<?php

namespace App\Services\SellerIntelligence\Producers;

use App\Models\SellerInsight;
use App\Services\DeliverySyria\DeliverySyriaStatus;
use App\Services\SellerIntelligence\InsightDraft;
use App\Services\SellerIntelligence\InsightProducer;
use App\Services\SellerIntelligence\Severity\ImpactSignals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shipments that have stopped moving.
 *
 * A parcel whose courier status has not changed in days is the failure a seller cannot see: the
 * order screen says "out for delivery" and has said so all week. The signal is real —
 * `delivery_syria_parcels.status_updated_at` is written by the courier's own webhook — so silence in
 * that column is the courier not saying anything, which is precisely what is worth knowing.
 *
 * Deliberately not a per-parcel alarm. One issue with a count, because a courier having a bad week
 * is one problem however many parcels it touches, and because forty separate alerts about forty
 * parcels is how a seller learns to ignore all of them.
 */
class ShippingExceptionProducer implements InsightProducer
{
    public const TYPE = 'SHIPPING_EXCEPTION';

    /** No word from the courier in this long, on a parcel that has not arrived. */
    private const SILENT_HOURS = 72;

    /** Past this the parcel is a dispute, not a delay, and a banner is not the right place for it. */
    private const STOP_AFTER_DAYS = 30;

    private const LIMIT = 200;

    public function type(): string
    {
        return self::TYPE;
    }

    public function produce(int|string $sellerId): iterable
    {
        if (!Schema::hasTable('delivery_syria_parcels') || !Schema::hasTable('orders')) {
            return [];
        }

        $silent = DB::table('delivery_syria_parcels')
            ->join('orders', 'orders.id', '=', 'delivery_syria_parcels.order_id')
            ->where('orders.seller_is', 'seller')
            ->where('orders.seller_id', $sellerId)
            ->whereNotIn('orders.order_status', ['delivered', 'canceled', 'returned', 'failed'])
            ->where('delivery_syria_parcels.created_at', '>=', now()->subDays(self::STOP_AFTER_DAYS))
            ->where(function ($query) {
                // Either the courier has gone quiet, or it never said anything in the first place.
                $query->where('delivery_syria_parcels.status_updated_at', '<=', now()->subHours(self::SILENT_HOURS))
                    ->orWhere(function ($inner) {
                        $inner->whereNull('delivery_syria_parcels.status_updated_at')
                            ->where('delivery_syria_parcels.created_at', '<=', now()->subHours(self::SILENT_HOURS));
                    });
            })
            ->orderBy('delivery_syria_parcels.created_at')
            ->limit(self::LIMIT)
            ->get([
                'delivery_syria_parcels.id',
                'delivery_syria_parcels.order_id',
                'delivery_syria_parcels.tracking_number',
                'delivery_syria_parcels.courier_status',
                'delivery_syria_parcels.status_updated_at',
                'delivery_syria_parcels.created_at',
                'orders.order_amount',
            ]);

        if ($silent->isEmpty()) {
            return [];
        }

        $value = round((float) $silent->sum('order_amount'), 2);
        $oldest = $silent->first();
        $silentSince = $oldest->status_updated_at ?? $oldest->created_at;
        $silentHours = round(now()->diffInMinutes($silentSince) / 60, 1);

        yield new InsightDraft(
            sellerId: $sellerId,
            type: self::TYPE,
            severity: SellerInsight::SEVERITY_HIGH,
            title: 'insight_shipments_not_moving',
            body: null,
            entityType: 'shipment_group',
            entityId: 'not_moving',
            metric: $silent->count(),
            impact: $value,
            actionKey: 'open_orders',
            actionParams: ['order_ids' => $silent->pluck('order_id')->take(50)->all()],
            category: SellerInsight::CATEGORY_SHIPPING,
            affectedCount: $silent->count(),
            signals: new ImpactSignals(
                revenueAtRisk: $value,
                affectedCount: $silent->count(),
                openForHours: $silentHours,
            ),
            metadata: [
                'count' => $silent->count(),
                'longest_silence_hours' => $silentHours,
                'window_hours' => self::SILENT_HOURS,
                'last_known_status' => $oldest->courier_status,
            ],
        );
    }
}
