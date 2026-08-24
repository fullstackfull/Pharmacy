<?php

namespace App\Services\SellerIntelligence\Producers;

use App\Models\SellerInsight;
use App\Services\Marketplace\SlaService;
use App\Services\SellerIntelligence\InsightDraft;
use App\Services\SellerIntelligence\InsightProducer;
use App\Services\SellerIntelligence\Severity\ImpactSignals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders that have not moved in a long time.
 *
 * Distinct from the SLA countdown, which measures an order against a deadline. This measures it
 * against itself: an order sitting in `processing` for four days has not breached anything if the
 * marketplace only holds sellers to a confirmation window, and is still a shop with a problem in it.
 * The two catch different failures — a seller who confirms everything instantly and then ships
 * nothing passes the SLA check and fails this one.
 *
 * "Has not moved" is read from `order_status_histories`, not inferred from `updated_at`, which any
 * unrelated write bumps. The check needed an index on that table to be affordable and did not have
 * one — `OrderIntegrityPanel` disabled its own version of it for exactly that reason — so the index
 * came first.
 */
class OrderStuckProducer implements InsightProducer
{
    public const TYPE = 'ORDER_STUCK';

    /** An order untouched for longer than this is worth raising, whatever its status. */
    private const STALE_AFTER_HOURS = 72;

    /** Beyond this it is a different conversation, and one banner has already been shown. */
    private const STOP_AFTER_DAYS = 45;

    /** How many to look at in one sweep. A shop with ten thousand stuck orders has one problem, not ten thousand. */
    private const LIMIT = 100;

    public function __construct(private readonly SlaService $sla)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function produce(int|string $sellerId): iterable
    {
        if (!Schema::hasTable('orders')) {
            return [];
        }

        $staleBefore = now()->subHours(self::STALE_AFTER_HOURS);

        $orders = DB::table('orders')
            ->where(['seller_is' => 'seller', 'seller_id' => $sellerId])
            ->whereIn('order_status', SlaService::AWAITING_SELLER_STATUSES)
            ->where('created_at', '>=', now()->subDays(self::STOP_AFTER_DAYS))
            ->orderBy('created_at')
            ->limit(self::LIMIT)
            ->get(['id', 'order_status', 'order_amount', 'created_at']);

        if ($orders->isEmpty()) {
            return [];
        }

        $lastMoved = $this->lastStatusChange($orders->pluck('id')->all());

        foreach ($orders as $order) {
            // No history row means nothing has moved it since it was placed, which is the answer the
            // question is asking — not a reason to skip it.
            $movedAt = $lastMoved[$order->id] ?? $order->created_at;

            if ($movedAt > $staleBefore) {
                continue;
            }

            $stillHours = round(now()->diffInMinutes($movedAt) / 60, 1);

            yield new InsightDraft(
                sellerId: $sellerId,
                type: self::TYPE,
                severity: SellerInsight::SEVERITY_HIGH,
                title: 'insight_order_stuck',
                body: "#{$order->id}",
                entityType: 'order',
                entityId: $order->id,
                metric: $stillHours,
                impact: (float) $order->order_amount,
                actionKey: 'open_order',
                actionParams: ['order_id' => $order->id, 'status' => $order->order_status, 'hours_in_status' => $stillHours],
                category: SellerInsight::CATEGORY_ORDERS,
                signals: new ImpactSignals(
                    revenueAtRisk: (float) $order->order_amount,
                    affectedCount: 1,
                    // Already past the point of being worth raising, so the urgency component is at
                    // its maximum rather than counting down to something.
                    hoursUntilDue: -($stillHours - self::STALE_AFTER_HOURS),
                    openForHours: $stillHours,
                ),
                metadata: ['status' => $order->order_status, 'hours_in_status' => $stillHours],
            );
        }
    }

    /**
     * When each of these orders last actually changed status.
     *
     * One grouped query rather than one per order — a hundred orders is a hundred round trips
     * otherwise, on a sweep that runs for every seller.
     *
     * @param  array<int, int>  $orderIds
     * @return array<int, \Illuminate\Support\Carbon>
     */
    private function lastStatusChange(array $orderIds): array
    {
        if (!Schema::hasTable('order_status_histories') || $orderIds === []) {
            return [];
        }

        return DB::table('order_status_histories')
            ->whereIn('order_id', $orderIds)
            ->selectRaw('order_id, MAX(created_at) as moved_at')
            ->groupBy('order_id')
            ->pluck('moved_at', 'order_id')
            ->map(fn ($moment) => \Illuminate\Support\Carbon::parse($moment))
            ->all();
    }
}
