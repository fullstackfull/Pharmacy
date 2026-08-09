<?php

namespace App\Http\Controllers\Vendor\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\VendorPayoutRequest;
use App\Services\Marketplace\PayoutService;
use App\Services\Marketplace\VendorLedger;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The seller's own payout requests, over the ledger (Phase 3, Stage B).
 *
 * A seller sees what they can actually withdraw — the ledger's withdrawable balance, net of anything
 * already in flight — and requests against it. All the rules live in PayoutService; this controller
 * only reaches auth('seller') for identity and never trusts a seller-supplied id.
 */
class PayoutController extends BaseController
{
    public function __construct(
        private readonly PayoutService $payouts,
        private readonly VendorLedger $ledger,
    ) {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $sellerId = auth('seller')->id();

        return view('vendor-views.marketplace.payouts', [
            'balances' => $this->ledger->balances($sellerId),
            'withdrawable' => $this->ledger->withdrawable($sellerId),
            'inCoolingPeriod' => $this->payouts->isInCoolingPeriod($sellerId),
            'requests' => VendorPayoutRequest::where('seller_id', $sellerId)
                ->where('seller_is', 'seller')
                ->latest('id')
                ->paginate(15),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'nullable|string|max:40',
        ]);

        // The seller id comes from the session, never the request — a seller cannot request a payout
        // against another seller's balance.
        $result = $this->payouts->requestPayout(
            sellerId: auth('seller')->id(),
            amount: (float) $request->get('amount'),
            method: $request->get('method', 'bank_transfer'),
            methodDetails: $request->get('method_details', []),
        );

        if (!$result['ok']) {
            ToastMagic::error(translate($result['reason']));

            return back();
        }

        ToastMagic::success(translate('payout_requested') . ' — ' . $result['request']->reference);

        return back();
    }

    /** A seller may withdraw a request they made, as long as it has not been paid. */
    public function cancel(int $id): RedirectResponse
    {
        $payout = VendorPayoutRequest::where(['id' => $id, 'seller_id' => auth('seller')->id()])->first();

        if (!$payout || !$payout->isOpen()) {
            ToastMagic::error(translate('this_payout_can_no_longer_be_cancelled'));

            return back();
        }

        // Releasing puts the reserved amount back to available; a cancelled request is not a paid one.
        $this->payouts->releaseReservation($payout, VendorPayoutRequest::STATUS_REJECTED, note: 'cancelled by seller');
        ToastMagic::success(translate('payout_request_cancelled'));

        return back();
    }
}
