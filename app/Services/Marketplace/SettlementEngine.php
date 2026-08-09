<?php

namespace App\Services\Marketplace;

use App\Models\VendorLedgerEntry;
use App\Models\VendorSettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Draws a settlement period across a vendor's ledger (Phase 3, Stage B).
 *
 * It sums ledger entries. It does **not** re-derive anything from orders — the ledger already holds
 * every financial event with its own running balance, and re-deriving would create a second source
 * of truth that can disagree with the first. That is the whole reason the ledger was built before
 * this.
 *
 * ## The claim is what makes it safe
 *
 * Calculating a settlement *claims* its entries by stamping `settlement_id` on them. An entry can
 * belong to exactly one settlement, so the same earning can never be paid twice — and that is
 * enforced by the data rather than by remembering to check. Only unclaimed, `available` entries are
 * eligible: money still inside the return window is not money a seller can be paid.
 *
 * ## Approval freezes it
 *
 * After approval the totals stop moving. A correction is a new ledger entry that the *next*
 * settlement picks up, never an edit to an approved one, because a restated settlement cannot be
 * reconciled against the payout already made from it. `cancel()` therefore releases the claim and
 * refuses to touch anything approved or paid.
 */
class SettlementEngine
{
    /**
     * Build a settlement for one vendor from their unclaimed, available ledger entries.
     *
     * Returns null when there is nothing to settle, which is a normal outcome on a schedule and not
     * an error — a vendor with no sales this week simply has no settlement.
     */
    public function calculate(
        int|string $sellerId,
        string $sellerIs = 'seller',
        ?\DateTimeInterface $periodStart = null,
        ?\DateTimeInterface $periodEnd = null,
    ): ?VendorSettlement {
        if (!Schema::hasTable('vendor_settlements') || !Schema::hasTable('vendor_ledger_entries')) {
            return null;
        }

        return DB::transaction(function () use ($sellerId, $sellerIs, $periodStart, $periodEnd) {
            $query = VendorLedgerEntry::query()
                ->forSeller($sellerId, $sellerIs)
                ->whereNull('settlement_id')
                ->where('status', VendorLedgerEntry::STATUS_AVAILABLE)
                ->when($periodStart, fn ($q) => $q->where('created_at', '>=', $periodStart))
                ->when($periodEnd, fn ($q) => $q->where('created_at', '<=', $periodEnd));

            // Locked for the transaction so a second run cannot claim the same rows.
            $entries = (clone $query)->lockForUpdate()->orderBy('id')->get();

            if ($entries->isEmpty()) {
                return null;
            }

            $credits = round((float) $entries->sum('credit'), 4);
            $debits = round((float) $entries->sum('debit'), 4);
            $net = round($credits - $debits, 4);

            // What the ledger said before this period's earliest claimed entry. Taken from the
            // entry itself rather than re-summed, which is why balance_after is stored.
            $first = $entries->first();
            $opening = round(((float) $first->balance_after) - ((float) $first->credit) + ((float) $first->debit), 4);

            $settlement = VendorSettlement::create([
                'reference' => $this->nextReference(),
                'seller_id' => $sellerId,
                'seller_is' => $sellerIs,
                'period_start' => $periodStart ?? $entries->min('created_at'),
                'period_end' => $periodEnd ?? $entries->max('created_at'),
                'opening_balance' => $opening,
                'total_credits' => $credits,
                'total_debits' => $debits,
                'net_amount' => $net,
                'closing_balance' => round((float) $entries->last()->balance_after, 4),
                'entry_count' => $entries->count(),
                'currency' => $first->currency,
                'status' => VendorSettlement::STATUS_CALCULATED,
                'calculated_at' => now(),
            ]);

            // Claim them. Stamping the id is what stops a second settlement taking the same money.
            VendorLedgerEntry::whereIn('id', $entries->pluck('id'))
                ->update(['settlement_id' => $settlement->id, 'updated_at' => now()]);

            return $settlement->fresh();
        }, attempts: 3);
    }

    /**
     * Approve a settlement, freezing its totals.
     *
     * Refuses anything already locked, so approving twice cannot re-stamp who approved it.
     */
    public function approve(VendorSettlement $settlement, int|string|null $approvedBy = null): bool
    {
        if ($settlement->isLocked() || $settlement->status === VendorSettlement::STATUS_CANCELLED) {
            return false;
        }

        $settlement->forceFill([
            'status' => VendorSettlement::STATUS_APPROVED,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ])->save();

        app(\App\Services\AuditLogger::class)->record(
            action: 'settlement.approved',
            subject: $settlement,
            context: ['reference' => $settlement->reference, 'net_amount' => $settlement->net_amount, 'seller_id' => $settlement->seller_id],
        );

        return true;
    }

    /**
     * Mark an approved settlement paid, and move its entries to `paid` on the ledger.
     *
     * Only from approved: paying something nobody approved is exactly the control the maker-checker
     * split exists to enforce.
     */
    public function markPaid(VendorSettlement $settlement, ?string $payoutReference = null): bool
    {
        if ($settlement->status !== VendorSettlement::STATUS_APPROVED) {
            return false;
        }

        return DB::transaction(function () use ($settlement, $payoutReference) {
            $settlement->forceFill([
                'status' => VendorSettlement::STATUS_PAID,
                'paid_at' => now(),
                'payout_reference' => $payoutReference,
            ])->save();

            VendorLedgerEntry::where('settlement_id', $settlement->id)
                ->update(['status' => VendorLedgerEntry::STATUS_PAID, 'updated_at' => now()]);

            app(\App\Services\AuditLogger::class)->record(
                action: 'settlement.paid',
                subject: $settlement,
                context: ['reference' => $settlement->reference, 'net_amount' => $settlement->net_amount, 'payout_reference' => $payoutReference],
            );

            return true;
        });
    }

    /**
     * Cancel a settlement that has not been approved, releasing its claim so the entries return to
     * the pool for the next run. Approved and paid settlements are never unwound this way — a
     * correction is a new ledger entry.
     */
    public function cancel(VendorSettlement $settlement, ?string $note = null): bool
    {
        if ($settlement->isLocked()) {
            return false;
        }

        return DB::transaction(function () use ($settlement, $note) {
            VendorLedgerEntry::where('settlement_id', $settlement->id)
                ->update(['settlement_id' => null, 'updated_at' => now()]);

            $settlement->forceFill([
                'status' => VendorSettlement::STATUS_CANCELLED,
                'note' => $note,
            ])->save();

            return true;
        });
    }

    /**
     * Run a settlement for every vendor holding unclaimed available entries.
     *
     * @return array<int, VendorSettlement>
     */
    public function calculateForAll(?\DateTimeInterface $periodStart = null, ?\DateTimeInterface $periodEnd = null): array
    {
        if (!Schema::hasTable('vendor_ledger_entries')) {
            return [];
        }

        $sellers = VendorLedgerEntry::query()
            ->whereNull('settlement_id')
            ->where('status', VendorLedgerEntry::STATUS_AVAILABLE)
            ->select('seller_id', 'seller_is')
            ->distinct()
            ->get();

        $settlements = [];
        foreach ($sellers as $seller) {
            $settlement = $this->calculate($seller->seller_id, $seller->seller_is, $periodStart, $periodEnd);
            if ($settlement) {
                $settlements[] = $settlement;
            }
        }

        return $settlements;
    }

    /**
     * A reference that stays unique under concurrency. The random suffix is what makes two runs in
     * the same second safe; the date prefix is what makes it readable in correspondence.
     */
    private function nextReference(): string
    {
        do {
            $reference = 'STL-' . now()->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        } while (VendorSettlement::where('reference', $reference)->exists());

        return $reference;
    }
}
