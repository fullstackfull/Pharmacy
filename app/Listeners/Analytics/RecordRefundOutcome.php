<?php

namespace App\Listeners\Analytics;

use App\Events\RefundEvent;
use App\Services\Analytics\Analytics;
use Throwable;

/**
 * The one place a refund decision is measured, whoever made it.
 *
 * `refund_requested` existed in the event catalogue and fired on an order status change — an order
 * marked `returned` or `failed` produced it, and an actual refund request produced nothing. So the
 * platform had an event named after a thing it did not count and a thing it did count under
 * somebody else's name, which is worse than a gap: a gap is visible.
 *
 * `RefundEvent` is the seam every decision path already passes through — the customer's request,
 * the seller panel, the admin panel, the v1 customer API and the v3 seller API all fire it — so
 * this listener is the only site that needed writing. A per-controller call would have been five
 * sites and the sixth would have been forgotten.
 *
 * The outcome is a property rather than three event names, because "how many refunds were decided"
 * and "how many were approved" are both real questions, and splitting the name turns the first into
 * a sum somebody has to remember to do.
 */
class RecordRefundOutcome
{
    /** The statuses that are a decision rather than a request. */
    private const SETTLED = ['approved', 'rejected', 'refunded'];

    public function __construct(private readonly Analytics $analytics)
    {
    }

    public function handle(RefundEvent $event): void
    {
        try {
            $refund = (array) $event->refund;
            $id = (int) ($refund['id'] ?? 0);

            if ($id === 0) {
                return;
            }

            if (in_array($event->status, self::SETTLED, true)) {
                $this->analytics->refundSettled(
                    refundRequestId: $id,
                    outcome: $event->status,
                    amount: isset($refund['amount']) ? (float) $refund['amount'] : null,
                    sellerId: $this->sellerId($event),
                );
            }
        } catch (Throwable) {
            // A refund the funnel did not see is still a refund. Measurement never blocks money.
        }
    }

    /**
     * Which shop the refund is against.
     *
     * Read from the order line rather than the order: a multi-vendor order has one order row and
     * several sellers, and attributing the refund to the order's own seller_id would credit the
     * wrong shop on every mixed basket.
     */
    private function sellerId(RefundEvent $event): ?int
    {
        $line = (array) ($event->orderDetails ?? []);

        return isset($line['seller_id']) ? (int) $line['seller_id'] : null;
    }
}
