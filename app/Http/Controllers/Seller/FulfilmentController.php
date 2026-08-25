<?php

namespace App\Http\Controllers\Seller;

use App\Models\OrderFulfillment;
use App\Services\Marketplace\FulfillmentService;
use App\Services\SellerCenter\Lists\FulfilmentList;
use App\Services\SellerCenter\TableFilters;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The warehouse work between "paid" and "on its way".
 *
 * Four screens, one list service and one template, because picking, packing, shipments and
 * exceptions are four questions about the same rows. The alternative — four controllers with four
 * queries — is how a rail badge and a toolbar count start disagreeing about the same shop.
 *
 * The exceptions screen is the one with consequences. FulfillmentService has stamped picked, packed
 * and shipped timestamps on every fulfilment since it was built and nothing ever subtracted them,
 * so a marketplace that suspends sellers for breaching an SLA could not show a seller which of
 * their orders was late. It reads the marketplace's own silence threshold, the same one the
 * shipping detector raises issues from.
 */
class FulfilmentController extends SellerCenterController
{
    public function __construct(
        private readonly FulfilmentList $fulfilments,
        private readonly FulfillmentService $workflow,
    ) {
    }

    public function index(Request $request): View
    {
        return $this->stage($request, 'shipments', null);
    }

    public function picking(Request $request): View
    {
        return $this->stage($request, 'picking', FulfilmentList::STAGES['picking']);
    }

    public function packing(Request $request): View
    {
        return $this->stage($request, 'packing', FulfilmentList::STAGES['packing']);
    }

    public function exceptions(Request $request): View
    {
        return $this->stage($request, 'exceptions', null, lateOnly: true);
    }

    /**
     * Move a fulfilment one step along.
     *
     * The service refuses to move backward or to repeat a state, so this does not check: a second
     * implementation of the state machine is a second thing that can disagree with the first.
     */
    public function advance(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'to' => 'required|in:' . implode(',', OrderFulfillment::FLOW),
            'carrier' => 'nullable|string|max:120',
            'tracking_number' => 'nullable|string|max:120',
        ]);

        $result = $this->workflow->advance(
            fulfillment: $this->find($this->sellerId($request), $id),
            toStatus: $request->get('to'),
            extra: array_filter([
                'carrier' => $request->get('carrier'),
                'tracking_number' => $request->get('tracking_number'),
            ], static fn ($value) => $value !== null && $value !== ''),
            by: $this->principal($request)->actorId(),
        );

        $result['ok'] ?? false
            ? ToastMagic::success(translate('fulfilment_updated'))
            : ToastMagic::error(translate($result['reason'] ?? 'fulfilment_could_not_be_updated'));

        return back();
    }

    /**
     * @param  array<int, string>|null  $statuses
     */
    private function stage(Request $request, string $screen, ?array $statuses, bool $lateOnly = false): View
    {
        $sellerId = $this->sellerId($request);
        $filters = new TableFilters($request, [], route('seller.shipments.index'));
        $fulfilments = $this->fulfilments->paginate($sellerId, $request, $statuses, $lateOnly);

        return view('seller-views.fulfilment.index', [
            'screen' => $screen,
            'fulfilments' => $fulfilments,
            'summary' => $this->fulfilments->summary($sellerId),
            'totals' => $this->fulfilments->orderTotals(collect($fulfilments->items())->pluck('order_id')->all(), $sellerId),
            'list' => $this->fulfilments,
            'filters' => $filters,
            'available' => $this->fulfilments->available(),
            'state' => $this->listState($fulfilments->total(), $filters->isFiltered() || $lateOnly),
        ]);
    }

    private function find(int $sellerId, int $id): OrderFulfillment
    {
        abort_unless($this->fulfilments->available(), 404);

        return OrderFulfillment::where('seller_id', $sellerId)->findOrFail($id);
    }
}
