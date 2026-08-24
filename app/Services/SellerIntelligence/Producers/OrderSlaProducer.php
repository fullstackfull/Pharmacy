<?php

namespace App\Services\SellerIntelligence\Producers;

use App\Models\Order;
use App\Services\SellerIntelligence\InsightDraft;
use App\Services\SellerIntelligence\InsightProducer;
use App\Services\Marketplace\SlaService;
use Illuminate\Support\Facades\Schema;

/**
 * Orders the seller still owes an action on, and how long they have left.
 *
 * The marketplace already measures SLA breaches after the fact — SlaService writes them to the
 * breach ledger and the scorecard reads them. That is accountability, not help: by the time a breach
 * exists the order is already late. This producer looks at orders still inside their window and
 * tells the seller which ones are about to fall out of it.
 *
 * The deadline is derived from the order's own age against the configured window, so it is the same
 * clock the marketplace judges them by — not a second, kinder one.
 */
class OrderSlaProducer implements InsightProducer
{
    public const TYPE = 'ORDER_SLA';

    /** Statuses that still need something from the seller before the order can ship. */
    private const AWAITING_SELLER = ['pending', 'confirmed', 'processing'];

    /** Inside this fraction of the window remaining, it is worth interrupting the seller. */
    private const URGENT_FRACTION = 0.25;

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

        $windowHours = $this->windowHours();
        if ($windowHours === null) {
            return [];
        }

        $orders = Order::query()
            ->where(['seller_is' => 'seller', 'seller_id' => $sellerId])
            ->whereIn('order_status', self::AWAITING_SELLER)
            ->orderBy('created_at')
            ->limit(200)
            ->get(['id', 'order_status', 'order_amount', 'created_at']);

        foreach ($orders as $order) {
            $deadline = $order->created_at?->copy()->addHours($windowHours);
            if ($deadline === null) {
                continue;
            }

            $hoursLeft = now()->diffInMinutes($deadline, false) / 60;
            $isLate = $hoursLeft <= 0;

            // Still comfortably inside the window: nothing to say yet.
            if (!$isLate && $hoursLeft > $windowHours * self::URGENT_FRACTION) {
                continue;
            }

            yield new InsightDraft(
                sellerId: $sellerId,
                type: self::TYPE,
                severity: $isLate ? 'critical' : 'high',
                title: $isLate ? 'insight_order_late' : 'insight_order_due_soon',
                body: "#{$order->id}",
                entityType: 'order',
                entityId: $order->id,
                metric: round($hoursLeft, 2),
                impact: (float) $order->order_amount,
                actionKey: 'open_order',
                actionParams: [
                    'order_id' => $order->id,
                    'deadline' => $deadline->toIso8601String(),
                    'hours_left' => round($hoursLeft, 2),
                ],
                // A late order stops being news once it is very late; the breach ledger owns it then.
                expiresAt: $deadline->copy()->addDays(7),
            );
        }
    }

    /**
     * The processing window the marketplace holds sellers to.
     *
     * Read from the SLA policy, so the countdown a seller sees and the deadline the marketplace
     * judges them by are the same number — changed in one place, on the SLA policy page.
     */
    private function windowHours(): ?int
    {
        $hours = $this->sla->thresholds()['processing_hours'] ?? null;

        return $hours !== null && $hours > 0 ? (int) $hours : null;
    }
}
