<?php

namespace App\Http\Controllers\Seller;

use App\Models\VendorPayoutRequest;
use App\Services\ApprovalEngine;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Requests of this shop's that are waiting on somebody at the marketplace.
 *
 * Dual control on a large payout routes it through the maker-checker engine, and until now the only
 * sign of that from the seller's side was a request that sat at "pending" for longer than usual with
 * no explanation. A rule the seller cannot see is indistinguishable from a system that has stopped
 * working.
 *
 * Read-only, and deliberately so: the approver is by definition not the requester. The value here is
 * knowing that a decision is queued and with whom the decision sits — not a button.
 *
 * Approvals carry a subject rather than a seller, so this resolves the shop's payout requests first
 * and asks the engine about those. That is narrower than "every approval mentioning this shop", and
 * narrower is correct: an approval about a marketplace-wide settlement is not this seller's to read.
 */
class ApprovalController extends SellerCenterController
{
    /** How many of the shop's recent payout requests are matched against the approval queue. */
    private const PAYOUT_LOOKBACK = 200;

    public function __construct(private readonly ApprovalEngine $approvals)
    {
    }

    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $available = Schema::hasTable('approval_requests') && Schema::hasTable('vendor_payout_requests');

        $payouts = $available
            ? VendorPayoutRequest::where(['seller_id' => $sellerId, 'seller_is' => 'seller'])
                ->orderByDesc('id')->limit(self::PAYOUT_LOOKBACK)->get(['id', 'reference', 'amount', 'status'])
            : collect();

        $approvals = $this->approvals->forSubjects(VendorPayoutRequest::class, $payouts->pluck('id')->all());

        return view('seller-views.approvals.index', [
            'approvals' => $approvals,
            'payouts' => $payouts->keyBy('id'),
            'available' => $available,
            'state' => $this->listState($approvals->count(), false),
        ]);
    }
}
