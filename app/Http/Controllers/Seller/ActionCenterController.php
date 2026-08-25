<?php

namespace App\Http\Controllers\Seller;

use App\Models\SellerInsight;
use App\Services\SellerIntelligence\SellerInsightEngine;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Everything waiting for this seller right now, worst first.
 *
 * The navigation has named `seller.actions` since Wave 1 and the route did not exist, so the item
 * was silently dropped from the rail and the Action Center existed only on the phone. It is the
 * screen the Control Tower's counts point at, which made the omission worse than a missing page: a
 * badge that leads nowhere.
 *
 * Entries are produced from real records only — an empty screen means nothing needs attention,
 * never that the producers had nothing to say.
 */
class ActionCenterController extends SellerCenterController
{
    public function __construct(private readonly SellerInsightEngine $insights)
    {
    }

    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $severity = $this->severity($request);

        $insights = $this->insights->open(
            sellerId: $sellerId,
            severity: $severity,
            limit: 100,
        );

        return view('seller-views.actions.index', [
            'insights' => $insights,
            'counts' => $this->insights->counts($sellerId),
            'severity' => $severity,
            'severities' => array_keys(SellerInsight::SEVERITY_ORDER),
            'state' => $this->listState($insights->count(), $severity !== null),
        ]);
    }

    public function dismiss(Request $request, int $id): RedirectResponse
    {
        // Critical standing cannot be hidden: a seller may choose not to act on a suggestion, but
        // not to hide that their account is at risk. The engine enforces it; this reports it.
        $this->insights->dismiss($this->sellerId($request), $id)
            ? ToastMagic::success(translate('dismissed'))
            : ToastMagic::error(translate('this_cannot_be_dismissed'));

        return back();
    }

    private function severity(Request $request): ?string
    {
        $severity = (string) $request->query('severity', '');

        return isset(SellerInsight::SEVERITY_ORDER[$severity]) ? $severity : null;
    }
}
