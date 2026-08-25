<?php

namespace App\Services\SellerCenter\Lists;

use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Services\Platform\Policy;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * The warehouse work between "paid" and "on its way".
 *
 * Four screens read this one service, because picking, packing, shipments and exceptions are four
 * questions about the same rows rather than four datasets. Splitting them into separate services is
 * how the counts in a rail badge and the count in a toolbar start disagreeing.
 *
 * Exceptions are the reason this exists as more than a list. The fulfilment record has carried
 * `picked_at`, `packed_at` and `shipped_at` since it was built and nothing ever subtracted them, so
 * a marketplace that suspends sellers for missing an SLA could not show a seller which of their own
 * orders was late. Here, a fulfilment that has sat in one state longer than the marketplace's own
 * silence threshold is an exception — the same threshold the shipping detector raises issues from,
 * read from the same policy, so the screen and the issue cannot disagree about what "stuck" means.
 */
class FulfilmentList
{
    /** Which slice of the workflow a screen is asking for. */
    public const STAGES = [
        'all' => [OrderFulfillment::STATUS_PENDING, OrderFulfillment::STATUS_PICKING, OrderFulfillment::STATUS_PACKED, OrderFulfillment::STATUS_READY, OrderFulfillment::STATUS_SHIPPED],
        'picking' => [OrderFulfillment::STATUS_PENDING, OrderFulfillment::STATUS_PICKING],
        'packing' => [OrderFulfillment::STATUS_PACKED, OrderFulfillment::STATUS_READY],
        'shipped' => [OrderFulfillment::STATUS_SHIPPED],
    ];

    public function available(): bool
    {
        return Schema::hasTable('order_fulfillments');
    }

    /**
     * @param  array<int, string>|null  $statuses  null means every stage
     */
    public function paginate(int $sellerId, Request $request, ?array $statuses = null, bool $lateOnly = false): LengthAwarePaginator
    {
        if (!$this->available()) {
            return new LengthAwarePaginator([], 0, $this->pageSize($request));
        }

        $query = OrderFulfillment::where('seller_id', $sellerId)
            ->whereIn('status', $statuses ?? self::STAGES['all']);

        if ($lateOnly) {
            // "Nothing has happened to this for longer than the marketplace allows." Measured from
            // the last thing that DID happen, not from when the order was placed: a fulfilment
            // packed an hour ago is not late because the order is three days old.
            $query->where('updated_at', '<', $this->silenceCutoff());
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($where) use ($search) {
                $where->where('reference', 'like', $search . '%')
                    ->orWhere('tracking_number', 'like', $search . '%')
                    ->orWhere('order_id', $search);
            });
        }

        return $query->orderByDesc('id')->paginate($this->pageSize($request))->withQueryString();
    }

    /**
     * The counts every fulfilment screen leads with.
     *
     * @return array<string, int>
     */
    public function summary(int $sellerId): array
    {
        if (!$this->available()) {
            return ['to_pick' => 0, 'to_pack' => 0, 'ready' => 0, 'shipped_today' => 0, 'late' => 0];
        }

        $fulfilments = OrderFulfillment::where('seller_id', $sellerId);

        return [
            'to_pick' => (clone $fulfilments)->whereIn('status', self::STAGES['picking'])->count(),
            'to_pack' => (clone $fulfilments)->where('status', OrderFulfillment::STATUS_PACKED)->count(),
            'ready' => (clone $fulfilments)->where('status', OrderFulfillment::STATUS_READY)->count(),
            'shipped_today' => (clone $fulfilments)->where('status', OrderFulfillment::STATUS_SHIPPED)
                ->where('shipped_at', '>=', Carbon::today())->count(),
            'late' => (clone $fulfilments)
                ->whereIn('status', [OrderFulfillment::STATUS_PENDING, OrderFulfillment::STATUS_PICKING, OrderFulfillment::STATUS_PACKED, OrderFulfillment::STATUS_READY])
                ->where('updated_at', '<', $this->silenceCutoff())
                ->count(),
        ];
    }

    /**
     * How long this fulfilment has been sitting where it is, in hours.
     *
     * The number the whole exceptions screen exists for: the timestamps were always written and
     * nothing ever subtracted them.
     */
    public function hoursSinceLastMove(OrderFulfillment $fulfilment): float
    {
        $last = $fulfilment->shipped_at ?? $fulfilment->packed_at ?? $fulfilment->picked_at ?? $fulfilment->updated_at ?? $fulfilment->created_at;

        return $last === null ? 0.0 : round(Carbon::parse($last)->floatDiffInHours(Carbon::now()), 1);
    }

    /**
     * Time from opening the fulfilment to handing it to a carrier, in hours, or null while it is open.
     *
     * This is dispatch time, and it is the figure a seller is judged on. Reported per row rather
     * than only in aggregate, because an average nobody can attribute to an order is a number a
     * seller cannot act on.
     */
    public function dispatchHours(OrderFulfillment $fulfilment): ?float
    {
        if ($fulfilment->shipped_at === null || $fulfilment->created_at === null) {
            return null;
        }

        return round(Carbon::parse($fulfilment->created_at)->floatDiffInHours(Carbon::parse($fulfilment->shipped_at)), 1);
    }

    public function isLate(OrderFulfillment $fulfilment): bool
    {
        return $fulfilment->isOpen() && $this->hoursSinceLastMove($fulfilment) >= $this->silenceHours();
    }

    /** Order numbers for a page of fulfilments, in one query and scoped to the seller. */
    public function orderTotals(array $orderIds, int $sellerId): array
    {
        $ids = array_values(array_filter(array_unique($orderIds)));

        if ($ids === [] || !Schema::hasTable('orders')) {
            return [];
        }

        return Order::whereIn('id', $ids)
            ->where(['seller_id' => $sellerId, 'seller_is' => 'seller'])
            ->pluck('order_amount', 'id')
            ->all();
    }

    /**
     * The marketplace's own silence threshold, read from the platform policy.
     *
     * The same key ShippingExceptionProducer reads to raise an issue, so a fulfilment this screen
     * calls late and an issue in the Control Tower are the same fulfilment — a screen with its own
     * private idea of "stuck" is how a seller ends up told two different things about one order.
     */
    public function silenceHours(): int
    {
        return app(Policy::class)->int('shipping_silent_hours');
    }

    private function silenceCutoff(): Carbon
    {
        return Carbon::now()->subHours($this->silenceHours());
    }

    private function pageSize(Request $request): int
    {
        $size = (int) $request->query('size', 25);

        return in_array($size, [25, 50, 100], true) ? $size : 25;
    }
}
