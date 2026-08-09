<?php

namespace App\Services\Marketplace;

use App\Models\OrderItemCommission;
use App\Models\VendorLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Unwinds the marketplace's side of a refund (Phase 3, Stage B).
 *
 * What the refund path did before this, in full:
 *
 * ```php
 * $this->vendorWalletRepo->updateWhere(
 *     params: ['seller_id' => $order['seller_id']],
 *     data: ['total_earning' => $sellerWallet['total_earning'] - $refund['amount']]
 * );
 * ```
 *
 * It subtracts the refund from a mutable total and says nothing about the commission. So **the
 * marketplace keeps its cut of a sale it gave back** — the seller loses the full amount while the
 * commission charged on it stays charged. On any marketplace that is a real defect, and on one with
 * high return rates it is a systematic overcharge of every seller.
 *
 * This reverses both sides, proportionally, as new ledger entries:
 *
 *   - a `refund` **debit** for the amount returned to the customer, and
 *   - a `commission_charge` **credit** giving back the commission on the refunded portion.
 *
 * Nothing is edited. The original earning and commission entries stay exactly as they were, because
 * the question a ledger exists to answer — what did this look like at the time — must survive a
 * refund. The snapshot's `reversed_amount` is the one field that moves, and only upward, so a
 * partial refund followed by another can never reverse more commission than was charged.
 */
class RefundReversalService
{
    public function __construct(private readonly VendorLedger $ledger)
    {
    }

    /**
     * Reverse a refund of `$refundedAmount` against one order line.
     *
     * Wrapped whole: refund accounting must never be the reason a refund fails to reach a customer.
     * The worst outcome of a failure here is a reversal that reconciliation surfaces, which is far
     * better than a customer who was not refunded.
     *
     * @return array{refund_debit: float, commission_credit: float}|null
     */
    public function reverseForOrderLine(int|string $orderDetailsId, float $refundedAmount, int|string|null $refundReference = null): ?array
    {
        if (!Schema::hasTable('order_item_commissions') || !Schema::hasTable('vendor_ledger_entries')) {
            return null;
        }

        $refundedAmount = round(max(0, $refundedAmount), 4);
        if ($refundedAmount <= 0) {
            return null;
        }

        try {
            return DB::transaction(function () use ($orderDetailsId, $refundedAmount, $refundReference) {
                $snapshot = OrderItemCommission::where('order_details_id', $orderDetailsId)->lockForUpdate()->first();

                if (!$snapshot || $snapshot->seller_is !== 'seller' || !$snapshot->seller_id) {
                    return null;
                }

                // Proportional to what is actually being returned. A full refund reverses all of
                // the commission; half the line reverses half.
                $base = (float) $snapshot->commissionable_amount;
                $refundedShare = $base > 0 ? min(1.0, $refundedAmount / $base) : 0.0;

                // Never reverse more commission than was charged, however many partial refunds
                // arrive. This is why reversed_amount is tracked rather than recomputed.
                $alreadyReversed = (float) $snapshot->reversed_amount;
                $commissionCredit = round(
                    min((float) $snapshot->commission_amount * $refundedShare, (float) $snapshot->commission_amount - $alreadyReversed),
                    4
                );
                $commissionCredit = max(0, $commissionCredit);

                // The seller gives back what the customer was refunded...
                $this->ledger->record(
                    sellerId: $snapshot->seller_id,
                    entryType: VendorLedgerEntry::TYPE_REFUND,
                    debit: $refundedAmount,
                    status: VendorLedgerEntry::STATUS_AVAILABLE,
                    referenceType: 'refund_order_details',
                    referenceId: $refundReference ?? $orderDetailsId,
                    description: 'Refund on order line #' . $orderDetailsId,
                    currency: $snapshot->currency,
                );

                // ...and the marketplace gives back its cut of it.
                if ($commissionCredit > 0) {
                    $this->ledger->record(
                        sellerId: $snapshot->seller_id,
                        entryType: VendorLedgerEntry::TYPE_RETURN_ADJUSTMENT,
                        credit: $commissionCredit,
                        status: VendorLedgerEntry::STATUS_AVAILABLE,
                        referenceType: 'refund_order_details',
                        referenceId: $refundReference ?? $orderDetailsId,
                        description: 'Commission reversed on refund of line #' . $orderDetailsId,
                        currency: $snapshot->currency,
                    );

                    $snapshot->forceFill([
                        'reversed_amount' => round($alreadyReversed + $commissionCredit, 4),
                    ])->save();
                }

                return ['refund_debit' => $refundedAmount, 'commission_credit' => $commissionCredit];
            }, attempts: 3);
        } catch (\Throwable $exception) {
            Log::warning('Refund reversal failed for order line ' . $orderDetailsId . ': ' . $exception->getMessage());

            return null;
        }
    }
}
