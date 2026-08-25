<?php

namespace App\Http\Controllers\Seller;

use App\Exports\OrderReportExport;
use App\Exports\ProductReportExport;
use App\Exports\ProductStockReportExport;
use App\Models\Seller;
use App\Services\Reports\ReportWindow;
use App\Services\Reports\SellerReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View as ViewFactory;
use Maatwebsite\Excel\Facades\Excel;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything this shop can take away with it, in one list.
 *
 * The exports already existed. What did not is a place that names them: they were four buttons on
 * three unrelated screens, each carrying whatever period that screen happened to be showing, so
 * "send me last month's figures" meant visiting three pages and setting the same dates three times.
 *
 * The period is chosen once here and carried into every download, and each export is produced by
 * the same exporter the classic panel and the phone use — so a spreadsheet a seller downloads from
 * the panel and one they download from the app are the same spreadsheet, not two renderings that
 * agree today.
 *
 * Nothing is queued and nothing is stored. A generated file kept on the server is a copy of a
 * shop's commercial data sitting somewhere nobody is watching; these stream and are gone.
 */
class ExportController extends SellerCenterController
{
    public function __construct(private readonly SellerReportService $reports)
    {
    }

    public function index(Request $request): View
    {
        return view('seller-views.exports.index', [
            'window' => $this->window($request),
            'periods' => ReportWindow::TYPES,
        ]);
    }

    public function orders(Request $request): BinaryFileResponse
    {
        $window = $this->window($request);
        $seller = $this->seller($request);

        return Excel::download(new OrderReportExport([
            'orders' => $this->reports->orderQuery($seller->id, $window)->get(),
            'search' => null,
            'vendor' => $seller,
            'from' => $window->from->toDateString(),
            'to' => $window->to->toDateString(),
            'dateType' => $window->type,
        ]), 'order-report.xlsx');
    }

    public function products(Request $request): BinaryFileResponse
    {
        $window = $this->window($request);
        $seller = $this->seller($request);

        return Excel::download(new ProductReportExport([
            'products' => $this->reports->productQuery($seller->id, $window)->get(),
            'search' => null,
            'seller' => $seller,
            'from' => $window->from->toDateString(),
            'to' => $window->to->toDateString(),
            'date_type' => $window->type,
        ]), 'product-report.xlsx');
    }

    /** Stock carries no period: a stock level is what it is now. */
    public function stock(Request $request): BinaryFileResponse
    {
        $seller = $this->seller($request);

        return Excel::download(new ProductStockReportExport([
            'products' => $this->reports->stockQuery($seller->id)->get(),
            'search' => null,
            'seller' => $seller,
            'category' => 'all',
            'sort' => 'ASC',
            'stock_limit' => $this->reports->stockLimitFor($seller),
        ]), 'product-stock-report.xlsx');
    }

    /**
     * The printed order summary.
     *
     * Two of its totals cannot be summed from a column — waived shipping was never charged, and a
     * delivery incentive is only owed on the seller's own deliveries — so the figures come from
     * `orderTotals()` rather than from the rows above them.
     */
    public function ordersPdf(Request $request): Response
    {
        $window = $this->window($request);
        $seller = $this->seller($request);
        $orders = $this->reports->orderQuery($seller->id, $window)->get();

        $data = array_merge($this->reports->orderTotals($orders), [
            'orders' => $orders,
            'search' => null,
            'seller' => trim("{$seller->f_name} {$seller->l_name}"),
            'type' => 'seller',
            'from' => $window->from->toDateString(),
            'to' => $window->to->toDateString(),
            'date_type' => $window->type,
            'company_name' => getWebConfig(name: 'company_name'),
            'company_email' => getWebConfig(name: 'company_email'),
            'company_phone' => getWebConfig(name: 'company_phone'),
            'company_web_logo' => getWebConfig(name: 'company_web_logo'),
        ]);

        $mpdf = new Mpdf([
            'default_font' => 'FreeSerif',
            'mode' => 'utf-8',
            'format' => [190, 250],
            'autoLangToFont' => true,
        ]);
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        $mpdf->WriteHTML(ViewFactory::make('admin-views.transaction.total_orders_report_pdf', ['data' => $data])->render());

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="order-report.pdf"',
        ]);
    }

    /** The shop itself, never `auth('seller')` — a staff member exports their employer's data. */
    private function seller(Request $request): Seller
    {
        return $this->principal($request)->seller;
    }

    private function window(Request $request): ReportWindow
    {
        $value = fn (string $key) => is_string($request->query($key)) ? $request->query($key) : null;

        return ReportWindow::make(type: $value('date_type'), from: $value('from'), to: $value('to'));
    }
}
