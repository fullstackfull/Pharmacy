<?php

namespace App\Http\Controllers\Seller;

use App\Models\ReturnShipment;
use App\Services\Marketplace\ReturnLogisticsService;
use App\Services\SellerCenter\Lists\ReturnList;
use App\Services\SellerCenter\TableFilters;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The goods coming back.
 *
 * The phone app has shown a seller their returns since Wave C; the browser has not, because the
 * navigation named a route that was never written and a missing route removes a menu item rather
 * than erroring — so the capability was invisible from inside the product.
 *
 * Every write goes through ReturnLogisticsService, which is the same service the v3 API calls. A
 * second implementation of "receive and restock" is how a return restocked from a phone and one
 * restocked from a browser end up writing different movement rows.
 */
class ReturnController extends SellerCenterController
{
    public function __construct(
        private readonly ReturnList $returns,
        private readonly ReturnLogisticsService $logistics,
    ) {
    }

    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $filters = new TableFilters($request, [], route('seller.returns.index'));
        $returns = $this->returns->paginate($sellerId, $request);

        return view('seller-views.returns.index', [
            'returns' => $returns,
            'summary' => $this->returns->summary($sellerId),
            'names' => $this->returns->productNames(collect($returns->items())->pluck('product_id')->all(), $sellerId),
            'filters' => $filters,
            'currentView' => $this->returns->view($request),
            'available' => $this->returns->available(),
            'state' => $this->listState($returns->total(), $filters->isFiltered() || $request->query('view')),
        ]);
    }

    public function show(Request $request, int $id): View
    {
        $sellerId = $this->sellerId($request);
        $rma = $this->find($sellerId, $id);

        return view('seller-views.returns.show', [
            'rma' => $rma,
            'name' => $this->returns->productNames([$rma->product_id], $sellerId)[$rma->product_id] ?? null,
            'ledger' => $this->returns->ledgerFor($rma, $sellerId),
        ]);
    }

    public function markInTransit(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'carrier' => 'nullable|string|max:120',
            'tracking_number' => 'nullable|string|max:120',
        ]);

        return $this->answer(
            $this->logistics->markInTransit(
                $this->find($this->sellerId($request), $id),
                $request->get('carrier'),
                $request->get('tracking_number'),
            ),
        );
    }

    public function receive(Request $request, int $id): RedirectResponse
    {
        $rma = $this->find($this->sellerId($request), $id);

        // The restock decision belongs at receipt, not at authorisation: nobody knows whether goods
        // are sellable until somebody has looked at them.
        $rma->update(['restock' => $request->boolean('restock')]);

        $principal = $this->principal($request);

        return $this->answer($this->logistics->receive(
            rma: $rma,
            by: $principal->actorId(),
            byType: $principal->actorType(),
        ));
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        // A reason is required: a refusal the customer cannot be told the grounds for is not a
        // decision anybody can act on.
        $request->validate(['reason' => 'required|string|max:255']);

        return $this->answer(
            $this->logistics->reject($this->find($this->sellerId($request), $id), $request->get('reason')),
        );
    }

    /** Scoped on the seller, so another shop's return is not found rather than actionable. */
    private function find(int $sellerId, int $id): ReturnShipment
    {
        abort_unless($this->returns->available(), 404);

        return ReturnShipment::where('seller_id', $sellerId)->findOrFail($id);
    }

    /**
     * A refusal here is a state problem — receiving a return that is already closed — so it is
     * reported as what was wrong rather than as a bare failure.
     */
    private function answer(array $result): RedirectResponse
    {
        $result['ok'] ?? false
            ? ToastMagic::success(translate('return_updated'))
            : ToastMagic::error(translate($result['reason'] ?? 'return_could_not_be_updated'));

        return back();
    }
}
