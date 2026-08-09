<?php

namespace App\Services\Marketplace;

use App\Models\VendorBankChangeLog;
use App\Models\VendorLedgerEntry;
use App\Models\VendorPayoutRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seller-initiated payouts over the ledger, with the security controls the phase asks for
 * (Phase 3, Stage B).
 *
 * The existing `withdraw_requests` flow runs over `seller_wallets` and validates nothing against a
 * real available balance. This runs over the ledger:
 *
 *   - A payout can only ask for what the ledger reports as **available**. Money still inside the
 *     return window (`pending`), or already reserved for another payout, is not requestable.
 *   - Requesting **reserves** the amount as a ledger debit with status `reserved`, so the same money
 *     cannot be requested twice, and a settlement cannot pay it out from under a live request.
 *   - Rejecting or failing a request **releases** the reservation — a credit that puts the money
 *     back — so nothing is lost when a payout does not go through.
 *   - Paying converts the reservation into a `paid` debit.
 *
 * ## The bank-change cooling period
 *
 * The control the specification names: changing a seller's bank details is logged and opens a
 * cooling window during which payouts are refused. It is the standard defence against an account
 * takeover that immediately redirects the money — the delay gives the real seller time to notice a
 * payout they did not make. `requestPayout()` refuses while the window is open.
 */
class PayoutService
{
    /** How long payouts are blocked after a bank-detail change. */
    public const COOLING_HOURS = 24;

    public function __construct(private readonly VendorLedger $ledger)
    {
    }

    /**
     * Create a payout request, reserving the amount against the seller's available balance.
     *
     * @return array{ok: bool, reason?: string, request?: VendorPayoutRequest}
     */
    public function requestPayout(int|string $sellerId, float $amount, string $method = 'bank_transfer', array $methodDetails = [], string $sellerIs = 'seller'): array
    {
        if (!Schema::hasTable('vendor_payout_requests')) {
            return ['ok' => false, 'reason' => 'payouts_unavailable'];
        }

        $amount = round($amount, 4);
        if ($amount <= 0) {
            return ['ok' => false, 'reason' => 'amount_must_be_positive'];
        }

        if ($this->isInCoolingPeriod($sellerId)) {
            return ['ok' => false, 'reason' => 'bank_details_recently_changed_payouts_are_temporarily_paused'];
        }

        return DB::transaction(function () use ($sellerId, $sellerIs, $amount, $method, $methodDetails) {
            // Withdrawable, not the raw available bucket: a payout already in flight is held as a
            // reserved debit, and withdrawable() nets those holds out. Read inside the transaction;
            // the reserve written below is what stops two concurrent requests both passing here.
            $withdrawable = $this->ledger->withdrawable($sellerId, $sellerIs);

            if ($amount > $withdrawable) {
                return ['ok' => false, 'reason' => 'amount_exceeds_available_balance'];
            }

            // Reserve it: a debit that moves the money out of "available" into "reserved". Recording
            // it through the ledger keeps the running balance correct and the audit trail intact.
            $reserve = $this->ledger->record(
                sellerId: $sellerId,
                entryType: VendorLedgerEntry::TYPE_PAYOUT,
                debit: $amount,
                status: VendorLedgerEntry::STATUS_RESERVED,
                referenceType: 'payout_reserve',
                referenceId: 'pending-' . uniqid(),
                description: 'Reserved for payout request',
                sellerIs: $sellerIs,
            );

            $request = VendorPayoutRequest::create([
                'reference' => $this->nextReference(),
                'seller_id' => $sellerId,
                'seller_is' => $sellerIs,
                'amount' => $amount,
                'currency' => $reserve?->currency,
                'status' => VendorPayoutRequest::STATUS_REQUESTED,
                'method' => $method,
                'method_details' => $methodDetails,
                'reserve_entry_id' => $reserve?->id,
            ]);

            return ['ok' => true, 'request' => $request];
        }, attempts: 3);
    }

    /**
     * Open a dual-control approval for a payout through the reusable engine, when the amount is large
     * enough to warrant it (spec item 83: maker-checker on large payouts).
     *
     * This is the payout workflow *using* the general engine rather than growing its own second copy
     * of approval logic. It is opt-in: with no threshold configured it opens nothing and the payout
     * follows the ordinary review path, so existing behaviour is unchanged. Above the threshold it
     * opens an ApprovalRequest requiring two approvers, and the admin actions it from the approvals
     * inbox — the maker who requested the payout cannot be one of them.
     */
    public function openApprovalIfLarge(VendorPayoutRequest $request, float $threshold, int $requiredApprovals = 2): ?\App\Models\ApprovalRequest
    {
        if ($threshold <= 0 || $request->amount < $threshold) {
            return null;
        }

        return app(\App\Services\ApprovalEngine::class)->open(
            workflow: 'payout',
            subject: $request,
            amount: $request->amount,
            requiredApprovals: $requiredApprovals,
            requestedBy: $request->seller_id,
            requestedByType: 'seller',
            payload: ['payout_reference' => $request->reference, 'seller_id' => $request->seller_id],
            note: 'Payout ' . $request->reference . ' exceeds the dual-control threshold',
            currency: $request->currency,
        );
    }

    /** Admin moves a request forward without paying: requested -> under_review -> approved. */
    public function review(VendorPayoutRequest $request, bool $approve, int|string|null $reviewer = null, ?string $note = null): bool
    {
        if (!in_array($request->status, [VendorPayoutRequest::STATUS_REQUESTED, VendorPayoutRequest::STATUS_UNDER_REVIEW], true)) {
            return false;
        }

        $request->forceFill([
            'status' => $approve ? VendorPayoutRequest::STATUS_APPROVED : VendorPayoutRequest::STATUS_UNDER_REVIEW,
            'reviewed_by' => $reviewer,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        app(\App\Services\AuditLogger::class)->record(
            action: $approve ? 'payout.approved' : 'payout.under_review',
            subject: $request,
            context: ['reference' => $request->reference, 'amount' => $request->amount, 'seller_id' => $request->seller_id],
        );

        return true;
    }

    /**
     * Mark an approved payout paid: the reservation becomes a real paid debit.
     *
     * Only from approved — paying what nobody approved is exactly the maker-checker control.
     */
    public function markPaid(VendorPayoutRequest $request, ?string $paymentReference = null): bool
    {
        if ($request->status !== VendorPayoutRequest::STATUS_APPROVED) {
            return false;
        }

        return DB::transaction(function () use ($request, $paymentReference) {
            // The reserved entry becomes the paid payout. Status is the only thing that changes; the
            // amount it reserved is the amount it pays.
            if ($request->reserve_entry_id) {
                VendorLedgerEntry::where('id', $request->reserve_entry_id)
                    ->update(['status' => VendorLedgerEntry::STATUS_PAID, 'updated_at' => now()]);
            }

            $request->forceFill([
                'status' => VendorPayoutRequest::STATUS_PAID,
                'paid_at' => now(),
                'payment_reference' => $paymentReference,
                'payout_entry_id' => $request->reserve_entry_id,
            ])->save();

            app(\App\Services\AuditLogger::class)->record(
                action: 'payout.paid',
                subject: $request,
                context: ['reference' => $request->reference, 'amount' => $request->amount, 'payment_reference' => $paymentReference],
            );

            return true;
        });
    }

    /**
     * Reject or fail a request, releasing the reservation back to available.
     *
     * The release is a credit rather than a deletion, so the ledger still shows that a payout was
     * requested and did not go through — the history is not rewritten.
     */
    public function releaseReservation(VendorPayoutRequest $request, string $toStatus = VendorPayoutRequest::STATUS_REJECTED, ?string $note = null): bool
    {
        if (!$request->isOpen()) {
            return false;
        }

        return DB::transaction(function () use ($request, $toStatus, $note) {
            if ($request->reserve_entry_id) {
                // Void the reservation so it no longer counts against "reserved"...
                VendorLedgerEntry::where('id', $request->reserve_entry_id)
                    ->update(['status' => VendorLedgerEntry::STATUS_PAID, 'updated_at' => now()]);

                // ...and put the money back as available.
                $this->ledger->record(
                    sellerId: $request->seller_id,
                    entryType: VendorLedgerEntry::TYPE_MANUAL_ADJUSTMENT,
                    credit: $request->amount,
                    status: VendorLedgerEntry::STATUS_AVAILABLE,
                    referenceType: 'payout_release',
                    referenceId: $request->id,
                    description: 'Released reservation for ' . $toStatus . ' payout ' . $request->reference,
                    sellerIs: $request->seller_is,
                );
            }

            $request->forceFill([
                'status' => $toStatus,
                'review_note' => $note ?? $request->review_note,
            ])->save();

            return true;
        });
    }

    /**
     * Log a bank-detail change and open the cooling window.
     *
     * Called from wherever seller bank details are updated. The before/after snapshot is what a
     * fraud review reads to see exactly what the account was redirected from and to.
     */
    public function recordBankChange(int|string $sellerId, ?array $previous, ?array $current, int|string|null $changedBy = null, ?string $changedByType = null, ?string $ip = null): ?VendorBankChangeLog
    {
        if (!Schema::hasTable('vendor_bank_change_logs')) {
            return null;
        }

        $log = VendorBankChangeLog::create([
            'seller_id' => $sellerId,
            'previous' => $previous,
            'current' => $current,
            'cooling_until' => now()->addHours(self::COOLING_HOURS),
            'changed_by' => $changedBy,
            'changed_by_type' => $changedByType,
            'ip_address' => $ip,
        ]);

        app(\App\Services\AuditLogger::class)->record(
            action: 'seller.bank_details_changed',
            subject: ['type' => 'seller', 'id' => $sellerId],
            before: $previous,
            after: $current,
            context: ['cooling_until' => $log->cooling_until?->toDateTimeString()],
        );

        return $log;
    }

    /** Is this seller inside a bank-change cooling window right now? */
    public function isInCoolingPeriod(int|string $sellerId): bool
    {
        if (!Schema::hasTable('vendor_bank_change_logs')) {
            return false;
        }

        return VendorBankChangeLog::where('seller_id', $sellerId)
            ->where('cooling_until', '>', now())
            ->exists();
    }

    private function nextReference(): string
    {
        do {
            $reference = 'PO-' . now()->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        } while (VendorPayoutRequest::where('reference', $reference)->exists());

        return $reference;
    }
}
