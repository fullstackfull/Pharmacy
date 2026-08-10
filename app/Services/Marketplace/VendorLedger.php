<?php

namespace App\Services\Marketplace;

use App\Models\VendorLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Appends to a vendor's financial ledger, and reads balances back out of it (Phase 3, Stage B).
 *
 * The balance is never stored as a number somebody updates. It is the sum of the entries, and each
 * entry carries the balance as of itself so a statement can be printed without re-summing history.
 *
 * ## Why the lock
 *
 * `balance_after` is a read-then-write: read the previous balance, add this entry, write the result.
 * Two events landing together would otherwise both read the same previous balance and both write
 * their own — the identical lost-update that cost this project a stock decrement in Phase 2. The
 * previous entry's row is locked for the rest of the transaction, so the second event waits and
 * then reads the balance the first one just wrote.
 *
 * ## Why nothing is ever edited
 *
 * A correction is a new entry. An edited ledger cannot be reconciled against anything, and the one
 * question a ledger exists to answer — "what did this look like at the time?" — stops having an
 * answer the moment a row is rewritten.
 */
class VendorLedger
{
    /**
     * Append one entry and return it. Idempotent on (seller, type, reference): calling it twice for
     * the same order line credits once, which is what makes it safe inside a retried transaction or
     * a webhook delivered more than once.
     */
    public function record(
        int|string $sellerId,
        string $entryType,
        float $credit = 0,
        float $debit = 0,
        string $status = VendorLedgerEntry::STATUS_PENDING,
        ?string $referenceType = null,
        int|string|null $referenceId = null,
        ?string $description = null,
        ?string $currency = null,
        string $sellerIs = 'seller',
        ?\DateTimeInterface $availableAt = null,
    ): ?VendorLedgerEntry {
        if (!Schema::hasTable('vendor_ledger_entries') || !$sellerId) {
            return null;
        }

        $credit = round(max(0, $credit), 4);
        $debit = round(max(0, $debit), 4);

        return DB::transaction(function () use (
            $sellerId, $sellerIs, $entryType, $credit, $debit, $status,
            $referenceType, $referenceId, $description, $currency, $availableAt
        ) {
            // Idempotency first: if this exact event is already on the ledger, return it unchanged
            // rather than appending a second one.
            $existing = VendorLedgerEntry::query()
                ->forSeller($sellerId, $sellerIs)
                ->where('entry_type', $entryType)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->first();

            if ($existing) {
                return $existing;
            }

            // Lock the seller's last entry so a concurrent event cannot read the same balance.
            $previous = VendorLedgerEntry::query()
                ->forSeller($sellerId, $sellerIs)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $balanceAfter = round(((float) ($previous->balance_after ?? 0)) + $credit - $debit, 4);

            return VendorLedgerEntry::create([
                'seller_id' => $sellerId,
                'seller_is' => $sellerIs,
                'entry_type' => $entryType,
                'credit' => $credit,
                'debit' => $debit,
                'balance_after' => $balanceAfter,
                'status' => $status,
                'available_at' => $availableAt,
                'currency' => $currency ?? $this->currentCurrency(),
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);
        }, attempts: 3);
    }

    /**
     * The four buckets the phase specification asks to be kept apart, rather than one number.
     *
     * @return array{pending: float, available: float, reserved: float, paid: float, balance: float}
     */
    public function balances(int|string $sellerId, string $sellerIs = 'seller'): array
    {
        $empty = ['pending' => 0.0, 'available' => 0.0, 'reserved' => 0.0, 'paid' => 0.0, 'balance' => 0.0];

        if (!Schema::hasTable('vendor_ledger_entries')) {
            return $empty;
        }

        $rows = VendorLedgerEntry::query()
            ->forSeller($sellerId, $sellerIs)
            ->selectRaw('status, SUM(credit) as credits, SUM(debit) as debits')
            ->groupBy('status')
            ->get();

        $balances = $empty;
        foreach ($rows as $row) {
            $net = round((float) $row->credits - (float) $row->debits, 4);
            if (array_key_exists($row->status, $balances)) {
                $balances[$row->status] = $net;
            }
            $balances['balance'] = round($balances['balance'] + $net, 4);
        }

        return $balances;
    }

    /**
     * The amount a seller can actually request a payout for, right now.
     *
     * It is the running balance minus anything still `pending`. That one formula handles every case
     * the `available` bucket gets wrong on its own:
     *
     *   - a `reserved` payout hold is a debit, so it already lowers the balance;
     *   - a `paid` payout is a debit, so it already lowers the balance;
     *   - but the *earning* credit keeps `available` status forever, so the `available` bucket
     *     overstates once any of it has been reserved or paid.
     *
     * Balance − pending nets the holds and the payouts while excluding money still in the return
     * window. Worked through: earn 400, reserve 300 -> balance 100, pending 0 -> withdrawable 100;
     * then pay it -> balance 100, pending 0 -> still 100 (the reserved-to-paid move changes nothing).
     *
     * Without this, a second payout request would see the full earning again and double-spend it.
     */
    public function withdrawable(int|string $sellerId, string $sellerIs = 'seller'): float
    {
        $balances = $this->balances($sellerId, $sellerIs);

        return round($balances['balance'] - $balances['pending'], 4);
    }

    /**
     * Move entries from pending to available once their window has passed.
     *
     * Status is the only field an entry may ever change, and only along this one path: an earning
     * maturing out of the return window is not a correction, it is the event the entry was created
     * anticipating. The amounts are untouched.
     */
    public function releaseMatured(?\DateTimeInterface $asOf = null): int
    {
        if (!Schema::hasTable('vendor_ledger_entries')) {
            return 0;
        }

        $asOf = $asOf ?? now();

        $query = VendorLedgerEntry::query()
            ->where('status', VendorLedgerEntry::STATUS_PENDING)
            ->whereNotNull('available_at')
            ->where('available_at', '<=', $asOf);

        // Only mature an order earning once its order actually completed. Earnings are credited at
        // placement, so a cancelled, failed or returned order still carries its credit; without this
        // gate it would turn into payable money the moment its window passed. An order-linked earning
        // matures only when the order is `delivered`; anything not linked to an order (a manual
        // adjustment) matures on time as before. Guarded on the tables existing so unit tests that
        // exercise the ledger in isolation are unaffected.
        if (Schema::hasTable('order_details') && Schema::hasTable('orders')) {
            $query->where(function ($q) {
                $q->where('reference_type', '!=', 'order_details')
                    ->orWhereIn('reference_id', function ($sub) {
                        $sub->select('order_details.id')
                            ->from('order_details')
                            ->join('orders', 'orders.id', '=', 'order_details.order_id')
                            ->where('orders.order_status', 'delivered');
                    });
            });
        }

        return $query->update(['status' => VendorLedgerEntry::STATUS_AVAILABLE, 'updated_at' => now()]);
    }

    private function currentCurrency(): ?string
    {
        try {
            return session('currency_code');
        } catch (\Throwable) {
            return null;
        }
    }
}
