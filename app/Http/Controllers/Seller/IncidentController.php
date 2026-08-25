<?php

namespace App\Http\Controllers\Seller;

use App\Models\SellerInsight;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The issues nobody answered in time.
 *
 * Not a second issue queue. The Issue Center is what is open now; this is the record of what was
 * left long enough that the platform promoted it — escalation only ever climbs, and one step at a
 * time, so a row here is a statement about elapsed silence rather than about severity.
 *
 * It matters because escalation is what eventually reaches the marketplace. A seller who can see
 * which of their issues has climbed, and how far, can stop the next step; one who cannot finds out
 * when somebody else acts on it.
 */
class IncidentController extends SellerCenterController
{
    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $available = Schema::hasTable('seller_insights');

        $incidents = $available
            ? SellerInsight::forSeller($sellerId)
                ->escalated()
                ->orderByDesc('escalation_level')
                ->orderByDesc('id')
                ->paginate($this->pageSize($request))
                ->withQueryString()
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25);

        return view('seller-views.incidents.index', [
            'incidents' => $incidents,
            'available' => $available,
            'state' => $this->listState($incidents->total(), false),
        ]);
    }

    private function pageSize(Request $request): int
    {
        $size = (int) $request->query('size', 25);

        return in_array($size, [25, 50, 100], true) ? $size : 25;
    }
}
