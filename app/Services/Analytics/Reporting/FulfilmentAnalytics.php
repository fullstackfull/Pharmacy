<?php

namespace App\Services\Analytics\Reporting;

use App\Models\OrderFulfillment;
use App\Models\ReturnShipment;
use App\Services\Platform\Policy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shipping, returns and refunds as measured quantities.
 *
 * This is the gap with the sharpest consequence in the platform. `FulfillmentService` stamps
 * `picked_at`, `packed_at` and `shipped_at` on every fulfilment, the RMA state machine stamps
 * `received_at` on every return, and nothing anywhere ever subtracted two of them — so a
 * marketplace that enforces an SLA policy, opens breaches against it and suspends sellers for
 * breaching it could not measure how long anything actually took. The one shipping figure recorded
 * was `shipping_cost` inside an `order_placed` properties blob that no rollup reads.
 *
 * Two decisions shape everything below.
 *
 * **Measured from the records, not from the event stream.** The timestamps already exist on every
 * row going back to the first order. Deriving these from analytics events would have produced an
 * empty report today and a partial one for a year, and a report that is empty for a reason nobody
 * can see is worse than no report. The events raised alongside this work are for the funnel; the
 * durations are read from the source of truth.
 *
 * **A median, not a mean, and a p90 beside it.** One order that sat over a public holiday moves a
 * mean by hours and a median not at all. The p90 is what an operator acts on — "one in ten takes
 * longer than this" is a sentence about a real customer, where an average is a sentence about
 * nobody.
 *
 * Anything not measured reads null and renders as an em dash. A zero here would say "instant",
 * which is a claim, where null says "we did not measure this", which is the truth.
 */
class FulfilmentAnalytics
{
    /**
     * How many duration rows one figure is computed from.
     *
     * Percentiles are computed in PHP rather than in SQL — MySQL and SQLite disagree about window
     * functions and this platform ships on both — so the scan is bounded. Ten thousand orders is a
     * stable median for any period an operator reads.
     */
    private const SCAN = 10000;

    public function __construct(private readonly Policy $policy)
    {
    }

    /**
     * From opening a fulfilment to handing it to a carrier.
     *
     * @return array{measured: int, open: int, late: int, threshold_hours: int, median_hours: ?float, p90_hours: ?float, stages: array<string, ?float>}
     */
    public function dispatch(Window $window): array
    {
        $threshold = $this->silenceHours();

        if (!Schema::hasTable('order_fulfillments')) {
            return $this->emptyDispatch($threshold);
        }

        $rows = OrderFulfillment::query()
            ->whereBetween('created_at', [$window->from, $window->to->copy()->endOfDay()])
            ->limit(self::SCAN)
            ->get(['created_at', 'picked_at', 'packed_at', 'shipped_at', 'status']);

        $shipped = $rows->whereNotNull('shipped_at');
        $dispatchHours = $shipped
            ->map(fn (OrderFulfillment $row) => $this->hoursBetween($row->created_at, $row->shipped_at))
            ->filter(fn (?float $hours) => $hours !== null)
            ->values();

        return [
            'measured' => $dispatchHours->count(),
            // Still open is not the same as slow, and counting it as either would be wrong: it has
            // no dispatch time yet, because it has not been dispatched.
            'open' => $rows->filter(fn (OrderFulfillment $row) => $row->isOpen())->count(),
            'late' => $this->countLate($rows, $threshold),
            'threshold_hours' => $threshold,
            'median_hours' => $this->percentile($dispatchHours, 0.5),
            'p90_hours' => $this->percentile($dispatchHours, 0.9),
            'stages' => [
                'to_pick' => $this->stageMedian($rows, 'created_at', 'picked_at'),
                'to_pack' => $this->stageMedian($rows, 'picked_at', 'packed_at'),
                'to_ship' => $this->stageMedian($rows, 'packed_at', 'shipped_at'),
            ],
        ];
    }

    /**
     * From placing an order to it reaching the customer.
     *
     * Delivery is read from the status history rather than from a column, because no column records
     * when an order was delivered — only that it currently is. An order delivered, returned, and
     * delivered again on a second attempt has two rows there and one status here, and the first is
     * the honest answer to "how long did it take".
     *
     * @return array{measured: int, median_hours: ?float, p90_hours: ?float}
     */
    public function delivery(Window $window): array
    {
        if (!Schema::hasTable('order_status_histories') || !Schema::hasTable('orders')) {
            return ['measured' => 0, 'median_hours' => null, 'p90_hours' => null];
        }

        $rows = DB::table('order_status_histories')
            ->join('orders', 'orders.id', '=', 'order_status_histories.order_id')
            ->where('order_status_histories.status', 'delivered')
            ->whereBetween('order_status_histories.created_at', [$window->from, $window->to->copy()->endOfDay()])
            ->limit(self::SCAN)
            ->get(['orders.created_at as placed_at', 'order_status_histories.created_at as delivered_at']);

        $hours = $rows
            ->map(fn ($row) => $this->hoursBetween($this->moment($row->placed_at), $this->moment($row->delivered_at)))
            ->filter(fn (?float $value) => $value !== null)
            ->values();

        return [
            'measured' => $hours->count(),
            'median_hours' => $this->percentile($hours, 0.5),
            'p90_hours' => $this->percentile($hours, 0.9),
        ];
    }

    /**
     * What shipping cost, and where it was expensive.
     *
     * Grouped by the delivery type the order actually used rather than by configured zone: a zone
     * with no orders in it is a setting, not a measurement, and listing it beside real rows would
     * imply it was counted and found to be zero.
     *
     * @return array{orders: int, total: float, average: ?float, free: int, by_type: array<int, array{label: string, orders: int, total: float}>}
     */
    public function shipping(Window $window): array
    {
        if (!Schema::hasTable('orders')) {
            return ['orders' => 0, 'total' => 0.0, 'average' => null, 'free' => 0, 'by_type' => []];
        }

        $scoped = fn () => DB::table('orders')->whereBetween('created_at', [$window->from, $window->to->copy()->endOfDay()]);

        $orders = (int) $scoped()->count();
        $total = (float) $scoped()->sum('shipping_cost');

        $byType = $scoped()
            ->selectRaw('COALESCE(delivery_type, ?) as label, COUNT(*) as orders, SUM(shipping_cost) as total', ['unspecified'])
            ->groupBy('label')
            ->orderByDesc('total')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'orders' => (int) $row->orders,
                'total' => (float) $row->total,
            ])
            ->all();

        return [
            'orders' => $orders,
            'total' => $total,
            // Null rather than zero on an empty period: dividing by nothing is not an average of 0.
            'average' => $orders > 0 ? round($total / $orders, 2) : null,
            'free' => (int) $scoped()->where('is_shipping_free', 1)->count(),
            'by_type' => $byType,
        ];
    }

    /**
     * What came back, why, how long it took to arrive and how much of it went back on the shelf.
     *
     * @return array{opened: int, received: int, restocked: int, restock_rate: ?float, median_receive_hours: ?float, by_reason: array<int, array{reason: string, count: int}>}
     */
    public function returns(Window $window): array
    {
        if (!Schema::hasTable('return_shipments')) {
            return $this->emptyReturns();
        }

        $rows = ReturnShipment::query()
            ->whereBetween('created_at', [$window->from, $window->to->copy()->endOfDay()])
            ->limit(self::SCAN)
            ->get(['created_at', 'received_at', 'status', 'reason']);

        $received = $rows->whereIn('status', [ReturnShipment::STATUS_RECEIVED, ReturnShipment::STATUS_RESTOCKED]);
        $restocked = $rows->where('status', ReturnShipment::STATUS_RESTOCKED);

        $receiveHours = $received
            ->map(fn (ReturnShipment $row) => $this->hoursBetween($row->created_at, $row->received_at))
            ->filter(fn (?float $hours) => $hours !== null)
            ->values();

        return [
            'opened' => $rows->count(),
            'received' => $received->count(),
            'restocked' => $restocked->count(),
            // Of what arrived, not of what was opened: a return still in the post has not failed to
            // be restocked, and counting it as such would make the rate fall every busy week.
            'restock_rate' => $received->count() > 0 ? round($restocked->count() / $received->count(), 4) : null,
            'median_receive_hours' => $this->percentile($receiveHours, 0.5),
            'by_reason' => $this->byReason($rows),
        ];
    }

    /**
     * How much money went back, and how long the customer waited for it.
     *
     * Settlement time is measured to the row's last change rather than to a settled-at column,
     * because there is none — the refund tables record a status and when the row was last touched.
     * That is an upper bound on the wait and it is labelled as one rather than presented as exact.
     *
     * @return array{requested: int, approved: int, rejected: int, value: float, median_settle_hours: ?float}
     */
    public function refunds(Window $window): array
    {
        if (!Schema::hasTable('refund_requests')) {
            return ['requested' => 0, 'approved' => 0, 'rejected' => 0, 'value' => 0.0, 'median_settle_hours' => null];
        }

        $rows = DB::table('refund_requests')
            ->whereBetween('created_at', [$window->from, $window->to->copy()->endOfDay()])
            ->limit(self::SCAN)
            ->get(['created_at', 'updated_at', 'status', 'amount']);

        $settled = $rows->filter(fn ($row) => in_array($row->status, ['approved', 'refunded', 'rejected'], true));

        $settleHours = $settled
            ->map(fn ($row) => $this->hoursBetween($this->moment($row->created_at), $this->moment($row->updated_at)))
            ->filter(fn (?float $hours) => $hours !== null)
            ->values();

        return [
            'requested' => $rows->count(),
            'approved' => $rows->whereIn('status', ['approved', 'refunded'])->count(),
            'rejected' => $rows->where('status', 'rejected')->count(),
            'value' => (float) $rows->whereIn('status', ['approved', 'refunded'])->sum('amount'),
            'median_settle_hours' => $this->percentile($settleHours, 0.5),
        ];
    }

    /**
     * A fulfilment the marketplace's own threshold says has been silent too long.
     *
     * The threshold is `shipping_silent_hours` from the policy registry — the same key the shipping
     * exception detector raises issues from and the same one the seller's fulfilment screen reads,
     * so the report, the issue and the screen cannot disagree about what late means.
     */
    private function countLate(Collection $rows, int $threshold): int
    {
        return $rows->filter(function (OrderFulfillment $row) use ($threshold) {
            $elapsed = $this->hoursBetween($row->created_at, $row->shipped_at ?? Carbon::now());

            return $elapsed !== null && $elapsed > $threshold;
        })->count();
    }

    private function stageMedian(Collection $rows, string $fromColumn, string $toColumn): ?float
    {
        $hours = $rows
            ->map(fn (OrderFulfillment $row) => $this->hoursBetween($row->{$fromColumn}, $row->{$toColumn}))
            ->filter(fn (?float $value) => $value !== null)
            ->values();

        return $this->percentile($hours, 0.5);
    }

    /** @return array<int, array{reason: string, count: int}> */
    private function byReason(Collection $rows): array
    {
        return $rows
            ->groupBy(fn (ReturnShipment $row) => $row->reason ?: 'unspecified')
            ->map(fn (Collection $group, string $reason) => ['reason' => $reason, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Hours between two moments, or null when either is missing or the pair is out of order.
     *
     * A negative duration is a clock or a backfill rather than a measurement, and averaging it in
     * would quietly pull every figure down.
     */
    private function hoursBetween(?Carbon $from, ?Carbon $to): ?float
    {
        if ($from === null || $to === null || $to->lessThan($from)) {
            return null;
        }

        return round($from->diffInMinutes($to) / 60, 2);
    }

    private function moment(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    /**
     * The nearest-rank percentile: the smallest observed value at or below which the given share of
     * measurements falls.
     *
     * Nearest rank rather than interpolation, deliberately. An interpolated p90 of four two-hour
     * dispatches and one four-hundred-hour one is a duration that no order ever took, and this
     * figure is read as "one in ten takes longer than this" — a sentence about a real order. It
     * must therefore be a real order's time.
     *
     * @param Collection<int, float> $values
     */
    private function percentile(Collection $values, float $at): ?float
    {
        if ($values->isEmpty()) {
            return null;
        }

        $sorted = $values->sort()->values();
        $rank = (int) ceil($at * $sorted->count());
        $index = min($sorted->count() - 1, max(0, $rank - 1));

        return round((float) $sorted[$index], 2);
    }

    private function silenceHours(): int
    {
        return max(1, $this->policy->int('shipping_silent_hours'));
    }

    /** @return array<string, mixed> */
    private function emptyDispatch(int $threshold): array
    {
        return [
            'measured' => 0,
            'open' => 0,
            'late' => 0,
            'threshold_hours' => $threshold,
            'median_hours' => null,
            'p90_hours' => null,
            'stages' => ['to_pick' => null, 'to_pack' => null, 'to_ship' => null],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyReturns(): array
    {
        return [
            'opened' => 0,
            'received' => 0,
            'restocked' => 0,
            'restock_rate' => null,
            'median_receive_hours' => null,
            'by_reason' => [],
        ];
    }
}
