<?php

namespace App\Services\Marketplace;

use App\Models\ReturnShipment;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Returns logistics — the physical side of a return (Phase 3, Stage C).
 *
 * A return walks authorized → in_transit → received, and on receipt either restocks (goods sellable
 * again) or is rejected (damaged/unsellable). The one behaviour that closes the loop the platform was
 * missing: **receiving a restockable return puts the unit back into `products.current_stock`** — under
 * a row lock, and recorded as a `return` movement in the same inventory log a purchase receipt writes
 * to. The financial side (commission reversal) already lives in the refund path; this links to the
 * refund_request rather than duplicating it.
 */
class ReturnLogisticsService
{
    public function __construct(
        private readonly ?InventoryService $inventory = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * Authorise a return. Starts the RMA in `authorized` — the goods have not moved yet.
     *
     * @param array{order_id?: int, order_details_id?: int, refund_request_id?: int, product_id?: int, seller_id?: int, qty?: int, reason?: string, restock?: bool, note?: string} $data
     */
    public function authorize(array $data, int|string|null $by = null, string $byType = 'admin'): ReturnShipment
    {
        $rma = ReturnShipment::create([
            'reference' => $this->nextReference(),
            'order_id' => $data['order_id'] ?? null,
            'order_details_id' => $data['order_details_id'] ?? null,
            'refund_request_id' => $data['refund_request_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'seller_id' => $data['seller_id'] ?? null,
            'qty' => max(1, (int) ($data['qty'] ?? 1)),
            'reason' => $data['reason'] ?? null,
            'status' => ReturnShipment::STATUS_AUTHORIZED,
            'restock' => array_key_exists('restock', $data) ? (bool) $data['restock'] : true,
            'note' => $data['note'] ?? null,
            'created_by' => $by,
            'created_by_type' => $byType,
        ]);

        $this->audit?->record(
            action: 'returns.authorized',
            subject: ['type' => 'return_shipment', 'id' => $rma->id],
            after: ['reference' => $rma->reference, 'product_id' => $rma->product_id, 'qty' => $rma->qty],
        );

        return $rma;
    }

    public function markInTransit(ReturnShipment $rma, ?string $carrier = null, ?string $tracking = null): array
    {
        if ($rma->status !== ReturnShipment::STATUS_AUTHORIZED) {
            return ['ok' => false, 'reason' => 'only_an_authorized_return_can_be_marked_in_transit'];
        }

        $rma->update([
            'status' => ReturnShipment::STATUS_IN_TRANSIT,
            'carrier' => $carrier ?: $rma->carrier,
            'tracking_number' => $tracking ?: $rma->tracking_number,
        ]);

        return ['ok' => true, 'return' => $rma];
    }

    /**
     * Receive the goods. If the return is restockable and points at a product, restock it (locked)
     * and record a `return` movement; otherwise the return is simply marked received.
     */
    public function receive(ReturnShipment $rma, int|string|null $by = null, string $byType = 'admin'): array
    {
        if (!$rma->isOpen()) {
            return ['ok' => false, 'reason' => 'return_is_not_in_a_receivable_state'];
        }

        return DB::transaction(function () use ($rma, $by, $byType) {
            $restocked = false;
            $balanceAfter = null;

            if ($rma->restock && $rma->product_id && Schema::hasTable('products')) {
                // Coordinate with the legacy order-status restock (getStockUpdateOnOrderStatusChange) so a
                // line is never restocked twice — once here and once when the order is marked returned. If
                // this RMA covers the whole order line, atomically CLAIM the restore by flipping the line's
                // is_stock_decreased 1->0; a zero-row flip means the legacy path already restored it, so we
                // skip. A partial RMA never touches the marker, so it can neither be double-counted against
                // the legacy full-line restore nor block it from restoring the remainder (never under-restock).
                $mayRestock = true;
                if ($rma->order_details_id && Schema::hasTable('order_details')) {
                    $line = DB::table('order_details')->where('id', $rma->order_details_id)->first();
                    if ($line && (int) $line->qty <= (int) $rma->qty) {
                        $claimed = DB::table('order_details')
                            ->where('id', $rma->order_details_id)
                            ->where('is_stock_decreased', 1)
                            ->update(['is_stock_decreased' => 0]);
                        $mayRestock = $claimed > 0;
                    }
                }

                $locked = $mayRestock ? DB::table('products')->where('id', $rma->product_id)->lockForUpdate()->first() : null;
                if ($locked) {
                    $balanceAfter = (int) $locked->current_stock + (int) $rma->qty;
                    DB::table('products')->where('id', $rma->product_id)
                        ->update(['current_stock' => $balanceAfter, 'updated_at' => now()]);

                    ($this->inventory ?? app(InventoryService::class))->record(
                        productId: $rma->product_id,
                        type: StockMovement::TYPE_RETURN,
                        qtyChange: (int) $rma->qty,
                        balanceAfter: $balanceAfter,
                        reason: $rma->reason,
                        referenceType: 'return_shipment',
                        referenceId: $rma->id,
                        sellerId: $rma->seller_id,
                        createdBy: $by,
                        // Who actually received the goods. Stamping every receipt 'admin' made the
                        // stock log say the marketplace handled a return the seller handled.
                        createdByType: $byType,
                    );
                    $restocked = true;
                }
            }

            $rma->update([
                'status' => $restocked ? ReturnShipment::STATUS_RESTOCKED : ReturnShipment::STATUS_RECEIVED,
                'received_at' => now(),
            ]);

            $this->audit?->record(
                action: 'returns.received',
                subject: ['type' => 'return_shipment', 'id' => $rma->id],
                after: ['restocked' => $restocked, 'balance_after' => $balanceAfter],
            );

            return ['ok' => true, 'return' => $rma->fresh(), 'restocked' => $restocked, 'balance_after' => $balanceAfter];
        });
    }

    public function reject(ReturnShipment $rma, ?string $reason = null): array
    {
        if (!$rma->isOpen()) {
            return ['ok' => false, 'reason' => 'return_is_not_open'];
        }

        $rma->update([
            'status' => ReturnShipment::STATUS_REJECTED,
            'note' => $reason ?: $rma->note,
        ]);

        $this->audit?->record(
            action: 'returns.rejected',
            subject: ['type' => 'return_shipment', 'id' => $rma->id],
            after: ['reason' => $reason],
        );

        return ['ok' => true, 'return' => $rma];
    }

    /**
     * Open the return that an approved refund implies, once.
     *
     * A refund approved on a physical product means goods are coming back, and until now nothing
     * recorded that: the money moved and the units did not exist anywhere. Without an RMA the return
     * never restocks, so a seller who refunds a customer quietly loses the stock as well as the sale.
     *
     * Idempotent on the refund request, so approving twice — or an admin having opened one already —
     * does not create a second return for the same goods.
     */
    public function authorizeForRefund(
        int|string $refundRequestId,
        array $data,
        int|string|null $by = null,
        string $byType = 'seller',
    ): ?ReturnShipment {
        if (!Schema::hasTable('return_shipments')) {
            return null;
        }

        $existing = ReturnShipment::where('refund_request_id', $refundRequestId)->first();

        if ($existing) {
            return $existing;
        }

        return $this->authorize(
            data: $data + ['refund_request_id' => $refundRequestId],
            by: $by,
            byType: $byType,
        );
    }

    private function nextReference(): string
    {
        $prefix = 'RMA-' . date('Ym') . '-';
        $last = ReturnShipment::where('reference', 'like', $prefix . '%')->orderByDesc('id')->value('reference');

        $seq = 1;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $seq = ((int) $m[1]) + 1;
        }

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
