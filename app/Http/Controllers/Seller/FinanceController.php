<?php

namespace App\Http\Controllers\Seller;

use App\Models\VendorPayoutRequest;
use App\Services\Marketplace\FeeSimulatorService;
use App\Services\Marketplace\PayoutService;
use App\Services\Marketplace\SellerLedgerStatementService;
use App\Services\Marketplace\SellerReconciliationService;
use App\Services\Marketplace\VendorLedger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The seller's money, on the same numbers the phone app reads.
 *
 * Six destinations the navigation has named since Wave 1 and none of them resolved, so every finance
 * question a seller had ended at the classic wallet page: one balance, no explanation of how it got
 * there, and no way to see what the marketplace had taken.
 *
 * One controller for all six, because they are six views of one ledger. A controller each would mean
 * six places that could disagree about what "available" means — and the whole reason this area
 * exists is that a seller stopped trusting a single number nobody could account for.
 */
class FinanceController extends SellerCenterController
{
    public function __construct(
        private readonly VendorLedger $ledger,
        private readonly SellerLedgerStatementService $statements,
        private readonly SellerReconciliationService $reconciliation,
        private readonly PayoutService $payouts,
        private readonly FeeSimulatorService $fees,
    ) {
    }

    /** The balance, and what it is made of. */
    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);

        return view('seller-views.finance.index', [
            'summary' => $this->statements->summary($sellerId),
            'recent' => $this->statements->rows(
                $this->statements->statement($sellerId, [], perPage: 8, page: 1)->items(),
            ),
            'inCoolingPeriod' => $this->payouts->isInCoolingPeriod($sellerId),
        ]);
    }

    /**
     * Every movement, with a filter over the vocabulary the ledger itself uses.
     *
     * The buckets above the table are deliberately NOT filtered: a seller narrowing to last week
     * still needs to know what they can withdraw today, and an "available" figure that silently
     * meant "available, of last week's entries" would be worse than none at all.
     */
    public function transactions(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $filters = $this->ledgerFilters($request);
        $entries = $this->statements->statement($sellerId, $filters, perPage: $this->pageSize($request), page: (int) $request->query('page', 1));

        return view('seller-views.finance.transactions', [
            'entries' => $entries,
            'rows' => $this->statements->rows($entries->items()),
            'summary' => $this->statements->summary($sellerId, $filters),
            'entryTypes' => $this->statements->entryTypes(),
            'statuses' => $this->statements->statuses(),
            'filters' => $filters,
            'state' => $this->listState($entries->total(), $filters !== []),
        ]);
    }

    /** The statement, which is the same ledger read as a document rather than as a list. */
    public function statements(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $filters = $this->ledgerFilters($request);

        return view('seller-views.finance.statements', [
            'summary' => $this->statements->summary($sellerId, $filters),
            'rows' => $this->statements->rows(
                $this->statements->statement($sellerId, $filters, perPage: 200, page: 1)->items(),
            ),
            'filters' => $filters,
        ]);
    }

    public function payouts(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $available = Schema::hasTable('vendor_payout_requests');

        return view('seller-views.finance.payouts', [
            'requests' => $available
                ? VendorPayoutRequest::where(['seller_id' => $sellerId, 'seller_is' => 'seller'])
                    ->latest('id')->paginate($this->pageSize($request))->withQueryString()
                : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25),
            'withdrawable' => $this->ledger->withdrawable($sellerId),
            'inCoolingPeriod' => $this->payouts->isInCoolingPeriod($sellerId),
            'available' => $available,
        ]);
    }

    /**
     * Whether the shop's own books add up.
     *
     * "Reconciles" is deliberately not "the totals are close enough": a shop can have a matching
     * total and still be missing one line's earning while carrying an extra credit that cancels it
     * out, which is the exact shape of the error this screen exists to catch.
     */
    public function reconciliation(Request $request): View
    {
        return view('seller-views.finance.reconciliation', [
            'report' => $this->reconciliation->forSeller(
                sellerId: $this->sellerId($request),
                from: $this->date($request, 'from'),
                to: $this->date($request, 'to'),
            ),
        ]);
    }

    /**
     * What the marketplace takes, worked out on a line the seller chooses.
     *
     * The commission rules are the marketplace's, not the seller's, so this shows the rule that
     * applied and names what the figure excludes. A fee estimate that quietly omits tax and shipping
     * and does not say so is how a seller prices a product at a loss.
     */
    public function fees(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $asked = $request->hasAny(['unit_price', 'product_id']);

        return view('seller-views.finance.fees', [
            'asked' => $asked,
            'result' => $asked ? $this->fees->simulate($sellerId, [
                'product_id' => $request->query('product_id'),
                'unit_price' => $request->query('unit_price'),
                'quantity' => $request->query('quantity'),
                'discount' => $request->query('discount'),
            ]) : null,
            'excludes' => FeeSimulatorService::EXCLUDED,
            'input' => [
                'product_id' => $request->query('product_id'),
                'unit_price' => $request->query('unit_price'),
                'quantity' => $request->query('quantity', 1),
                'discount' => $request->query('discount'),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function ledgerFilters(Request $request): array
    {
        $filters = [];

        foreach (['entry_type' => $this->statements->entryTypes(), 'status' => $this->statements->statuses()] as $key => $allowed) {
            $value = (string) $request->query($key, '');
            if ($value !== '' && in_array($value, $allowed, true)) {
                $filters[$key] = $value;
            }
        }

        foreach (['from', 'to'] as $key) {
            $date = $this->date($request, $key);
            if ($date !== null) {
                $filters[$key] = $date;
            }
        }

        return $filters;
    }

    /** A date the ledger can bind, or null when the caller sent something that is not one. */
    private function date(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function pageSize(Request $request): int
    {
        $size = (int) $request->query('size', 25);

        return in_array($size, [25, 50, 100], true) ? $size : 25;
    }
}
