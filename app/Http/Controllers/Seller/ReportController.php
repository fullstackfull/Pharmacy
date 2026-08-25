<?php

namespace App\Http\Controllers\Seller;

use App\Services\Reports\ReportWindow;
use App\Services\Reports\SellerReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * What this shop did over a period, in the three shapes a seller actually asks for.
 *
 * The figures are not new — `SellerReportService` has computed them for the classic panel and the
 * phone since Phase 4. What is new is that all three live behind one period, chosen once. The
 * classic panel scattered them across three menus with three independent date pickers, which is how
 * a seller ends up comparing March's orders against the year's products and drawing a conclusion
 * from it.
 *
 * Every number here is a query against this shop's own rows. Nothing is projected, extrapolated or
 * annualised: a report that models is a forecast, and a forecast presented as a report is a lie
 * with a date on it.
 */
class ReportController extends SellerCenterController
{
    private const PAGE_SIZE = 25;

    public function __construct(private readonly SellerReportService $reports)
    {
    }

    /** All three headlines under one period, so they can be read against each other. */
    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $window = $this->window($request);

        return view('seller-views.reports.index', [
            'window' => $window,
            'orders' => $this->reports->orderReport($sellerId, $window),
            'products' => $this->reports->productReport($sellerId, $window),
            'periods' => ReportWindow::TYPES,
        ]);
    }

    public function orders(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $window = $this->window($request);
        $search = $this->search($request);

        $orders = $this->reports->orderQuery($sellerId, $window, $search)
            ->paginate(self::PAGE_SIZE)
            ->withQueryString();

        return view('seller-views.reports.orders', [
            'window' => $window,
            'periods' => ReportWindow::TYPES,
            'report' => $this->reports->orderReport($sellerId, $window, $search),
            'orders' => $orders,
            'search' => $search,
            'state' => $this->listState($orders->total(), $search !== null),
        ]);
    }

    public function products(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $window = $this->window($request);
        $search = $this->search($request);

        $products = $this->reports->productQuery($sellerId, $window, $search)
            ->paginate(self::PAGE_SIZE)
            ->withQueryString();

        return view('seller-views.reports.products', [
            'window' => $window,
            'periods' => ReportWindow::TYPES,
            'report' => $this->reports->productReport($sellerId, $window, $search),
            'products' => $products,
            'search' => $search,
            'state' => $this->listState($products->total(), $search !== null),
        ]);
    }

    /**
     * Stock is the one report with no period.
     *
     * A stock level is what it is now; asking what it was in March would need a movement ledger
     * replayed backwards, and reporting the current figure under a March heading would be false.
     */
    public function stock(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $search = $this->search($request);
        $categoryId = $this->categoryId($request);

        $products = $this->reports
            ->stockQuery($sellerId, $search, $this->sort($request), $categoryId)
            ->paginate(self::PAGE_SIZE)
            ->withQueryString();

        return view('seller-views.reports.stock', [
            'products' => $products,
            'categories' => $this->reports->stockFilterCategories(),
            'currentCategory' => $categoryId,
            'limit' => $this->reports->stockLimitFor($sellerId),
            'search' => $search,
            'sort' => $this->sort($request),
            'state' => $this->listState($products->total(), $search !== null || $categoryId !== null),
        ]);
    }

    /**
     * Every reader coerces to a string first: these are query parameters, so `?search[]=x` arrives
     * as an array and would be an uncatchable TypeError against a string-typed argument.
     */
    private function window(Request $request): ReportWindow
    {
        return ReportWindow::make(
            type: $this->stringOrNull($request, 'date_type'),
            from: $this->stringOrNull($request, 'from'),
            to: $this->stringOrNull($request, 'to'),
        );
    }

    private function search(Request $request): ?string
    {
        $search = $this->stringOrNull($request, 'q');

        return $search === null || trim($search) === '' ? null : trim($search);
    }

    private function sort(Request $request): string
    {
        return strtoupper((string) $this->stringOrNull($request, 'sort')) === 'DESC' ? 'DESC' : 'ASC';
    }

    private function categoryId(Request $request): ?string
    {
        $categoryId = $this->stringOrNull($request, 'category_id');

        return $categoryId === null || $categoryId === '' || $categoryId === 'all' ? null : $categoryId;
    }

    private function stringOrNull(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
