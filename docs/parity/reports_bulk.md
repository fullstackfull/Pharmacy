# Parity — reports_bulk

[← back to the matrix](../SELLER_WEB_APP_PARITY.md) · 47 capabilities

**19** BOTH · **16** WEB MISSING · **8** APP MISSING · **1** WEB ENHANCEMENT · **1** APP ADAPTATION · **2** DEVICE SPECIFIC

## Structural facts the implementer must know

```
STRUCTURE

Both sides of the reports half already share one service — App\Services\Reports\SellerReportService (order/product/stock queries, payment breakdown, chart series, stock threshold) and App\Services\Reports\ReportWindow (period + chart bucket). The mobile controller (app/Http/Controllers/RestAPI/v3/seller/SellerReportController.php) and the two vendor controllers (app/Http/Controllers/Vendor/OrderReportController.php, ProductReportController.php) are thin readers of it, and the Excel exporters (App\Exports\OrderReportExport / ProductReportExport / ProductStockReportExport) and the mPDF view (admin-views.transaction.total_orders_report_pdf) are literally the same classes. Anything added to a report should go into SellerReportService, not into a controller — otherwise the app and the panel drift, which is exactly what this service was created to stop.

THE BIG GAP: BULK IS 100% MOBILE-ONLY

The whole bulk_jobs feature has no web surface at all. The backend is complete and production-shaped: App\Services\Marketplace\Bulk\SellerBulkJobService (ownership scoping as a WHERE, 1000-row cap, dedupe, per-row receipt), BulkPriceOperation (5 modes, pricing-floor check, refuses instead of clamping), BulkStockOperation (routes every change through InventoryService::adjust, refuses variant products), App\Jobs\RunSellerBulkJob, and App\Console\Commands\RunStuckSellerBulkJobs as a stuck-queue net. The five endpoints under routes/rest_api/v3/seller.php:670-681 are the only consumers.

The Seller Center has already reserved the slot: app/Services/SellerCenter/Navigation.php:72 declares ['key'=>'bulk-jobs','label'=>'nav_bulk_operations','route'=>'seller.bulk-jobs.index','permission'=>'products.manage','badge'=>'bulk_running'], and app/Services/SellerCenter/Counts.php:159-169 already computes that badge. The item is invisible today only because Navigation::for (app/Services/SellerCenter/Navigation.php:220-222) drops items whose route does not exist. So the web work is: add seller.bulk-jobs.index/show routes + a Seller Center controller reading the same SellerBulkJobService, and the navigation and badge light up with no registry change. Do NOT build it under /vendor — resources/views/seller-views/ + routes/seller/routes.php is where this belongs, and the permission must be declared with seller_can: like every other route in that file.

Web screens needed, in priority order: (1) bulk job list with status chip + progress, (2) job receipt with the refused-rows table and the CSV download, (3) the product picker + price/stock operation form. The receipt matters more than the form — a partial result that cannot be inspected is the failure mode this whole feature exists to prevent.

PERMISSION DIVERGENCE — worth fixing while you are in here

The API gates reports precisely: orders report on orders.view/orders.manage/finance.view, products+stock reports on products.view/products.manage/inventory.manage (routes/rest_api/v3/seller.php:602,608). The vendor panel does not: app/Http/Middleware/SellerStaffAccessMiddleware.php:99 maps the whole 'report' URL segment to finance.view. Consequences today: a warehouse clerk with products.view/inventory.manage can read the stock report on their phone but gets a 403 on the web; a finance clerk with only finance.view can read the product and stock reports on the web but not in the app. Also, 'analytics' is unmapped in that middleware, so its default => DENY refuses every staff member from /vendor/analytics — and no navigation links to that page at all (grep for vendor.analytics.index matches only the page's own range links), so it is effectively URL-only for the owner.

CLIENT-SIDE BUSINESS STATE: NONE FOUND

Searched lib/features/reports and lib/features/bulk_jobs for SharedPreferences/prefs/setBool/setString — zero matches. Every filter (period, custom range, sort, category, selection) lives in an in-memory ChangeNotifier and is re-sent to the server on each call; nothing survives a restart, and nothing business-bearing is persisted on device. The low-stock threshold is server-resolved (SellerReportService::stockLimitFor: the seller's own stock_limit, else the platform default) and the client explicitly refuses to second-guess it — see the comment at lib/features/reports/domain/models/report_models.dart:359-362. The one selectable value the seller owns, stock_limit itself, is edited on the server in both clients (web: resources/views/vendor-views/shop/other-setup.blade.php:90; app: lib/features/shop/screens/other_setup_screen.dart:435). Nothing to flag.

APP-SIDE DEFECT WORTH A TICKET

lib/features/reports/domain/models/report_models.dart:339 computes lowStockCount from the products array, which is one page of 10 rows (limit hard-coded at lib/features/reports/domain/repositories/report_repository.dart:24). The 'running_low' tile on the stock report therefore reports at most 10 and is wrong for any shop with more low-stock products than that. Fix on the server (add a low-stock total to GET reports/stock) rather than in the client, so a future web stock report can show the same number.

REPORT PAGING IS THE APP'S WEAKEST POINT

All three report screens fetch offset: 1 / limit 10 and never page (report_controller.dart:60, :76, :94). The order report therefore lists 10 orders regardless of the period, with no indication that more exist beyond the count in the section header. The endpoints already accept limit/offset; this is client work only.

ADMIN SURFACE (not seller-facing, do not confuse)

Bulk jobs DO have a web view — for admins: routes/admin/routes.php:648 → app/Http/Controllers/Admin/Marketplace/SellerOperationsController.php:127, admin-views/marketplace/seller-operations/bulk-jobs. That is cross-seller oversight, not a seller's own receipt, and it must not be reused as the seller screen.

SCOPE OVERLAPS

Two rows here touch adjacent domains and should be de-duplicated against those audits: shop traffic analytics (owned by seller_center — lib/features/seller_center) and the vendor transaction/expense reports (owned by finance/statement — lib/features/statement). Both are included because the given search paths named vendor-views/analytics, and because the vendor sidebar files transactions under 'reports_&_analytics' (resources/views/layouts/vendor/partials/v2/_side-bar.blade.php:329-341).

NEITHER SIDE HAS

Cancelling a queued bulk job, or re-running the failed rows of a partial job as a new job. The model carries the statuses to support both (App\Models\SellerBulkJob::OPEN_STATUSES) and the failure list is exactly the input a re-run would need, but there is no endpoint and no UI in either client. Worth proposing rather than auditing — it is the obvious next step after the receipt exists on web.
```

## BOTH (19)

**View the order report for a period (counts: total, ongoing, canceled, delivered)**  
`seller_can:orders.view,orders.manage,finance.view (API) / finance.view for staff on web (app/Http/Middleware/SellerStaffAccessMiddleware.php:99)`  
- App — Yes — lib/features/reports/screens/order_report_screen.dart, OrderReportScreen metric tiles
- Web — Yes — resources/views/vendor-views/report/order-report.blade.php, vendor.report.order-report
- Server — GET /api/v3/seller/seller-center/reports/orders (SellerReportController::orders) and Vendor\OrderReportController::order_report — both read App\Services\Reports\SellerReportService::orderReport
- Evidence — flutter: lib/features/reports/screens/order_report_screen.dart:123-145 (total/delivered/ongoing/canceled tiles), lib/features/reports/controllers/report_controller.dart:50; web: resources/views/vendor-views/report/order-report.blade.php:54-85, app/Http/Controllers/Vendor/OrderReportController.php:34-52; backend: routes/rest_api/v3/seller.php:603, app/Http/Controllers/RestAPI/v3/seller/SellerReportController.php:48

**Choose the report period (today / this week / this month / this year)**  
`same as parent report`  
- App — Yes — ReportPeriodPickerWidget chips, driven by period.date_types echoed by the server
- Web — Yes — date_type <select> on both order and product report
- Server — date_type query parameter resolved by App\Services\Reports\ReportWindow::make; allowlist in ReportWindow::TYPES
- Evidence — flutter: lib/features/reports/widgets/report_widgets.dart:37-88, lib/features/reports/domain/repositories/report_repository.dart:35-42; web: resources/views/vendor-views/report/order-report.blade.php:19-25, resources/views/vendor-views/report/all-product.blade.php:27-33; backend: app/Services/Reports/ReportWindow.php:21-28

**Run a report over a custom date range (from/to)**  
`same as parent report`  
- App — Yes — native showDateRangePicker on the custom_date chip, order and product reports
- Web — Yes — from/to <input type=date> revealed when date_type=custom_date
- Server — from/to query parameters, ReportWindow::custom (capped at 1096 days)
- Evidence — flutter: lib/features/reports/screens/order_report_screen.dart:31-44 and lib/features/reports/screens/product_report_screen.dart:30-43; web: resources/views/vendor-views/report/order-report.blade.php:27-37, resources/views/vendor-views/report/all-product.blade.php:36-47; backend: app/Services/Reports/ReportWindow.php:38 (MAX_CUSTOM_DAYS), app/Http/Controllers/RestAPI/v3/seller/SellerReportController.php:332-339

**Order report: due vs settled vs total order amount for the period**  
`seller_can:orders.view,orders.manage,finance.view`  
- App — Yes — 'order_amount' card with settled / due / total rows
- Web — Yes — total_Order_Amount tile with due_Amount and settled sub-tiles
- Server — amounts.due / amounts.settled from SellerReportService::orderReport
- Evidence — flutter: lib/features/reports/screens/order_report_screen.dart:148-166, lib/features/reports/domain/models/report_models.dart:124 (turnover = due + settled); web: resources/views/vendor-views/report/order-report.blade.php:97-121, app/Http/Controllers/Vendor/OrderReportController.php:63-64

**Order report: payment-method breakdown (cash, digital, wallet, offline, returned, total)**  
`seller_can:orders.view,orders.manage,finance.view`  
- App — Yes — 'payments_received' card, hidden when nothing was received
- Web — Yes — payment_Statistics pie chart with the same five figures
- Server — payments block from SellerReportService::paymentBreakdown (folds in order-edit payments, subtracts returns)
- Evidence — flutter: lib/features/reports/screens/order_report_screen.dart:169-196, lib/features/reports/domain/models/report_models.dart:129-158; web: resources/views/vendor-views/report/order-report.blade.php:139-172 and 270-285, app/Http/Controllers/Vendor/OrderReportController.php:53-60; backend: app/Services/Reports/SellerReportService.php:200

**Order report: settled-amount-over-time chart**  
`seller_can:orders.view,orders.manage,finance.view`  
- App — Yes — ReportChartWidget horizontal bar series
- Web — Yes — ApexCharts 'order_Statistics' / total_settled_amount
- Server — chart.labels + chart.values, bucketed by ReportWindow::bucket (hour/weekday/day/month/year)
- Evidence — flutter: lib/features/reports/screens/order_report_screen.dart:198, lib/features/reports/widgets/report_widgets.dart:132-197; web: resources/views/vendor-views/report/order-report.blade.php:129-137; backend: app/Http/Controllers/RestAPI/v3/seller/SellerReportController.php:63-66, app/Services/Reports/ReportWindow.php:31-35

**Download the order report as an Excel spreadsheet**  
`seller_can:orders.view,orders.manage,finance.view`  
- App — Yes — 'download_excel' option in the app-bar export menu, carries the on-screen period
- Web — Yes — 'excel' button on the order report
- Server — GET /api/v3/seller/seller-center/reports/orders/export and vendor.report.order-report-excel — both render App\Exports\OrderReportExport
- Evidence — flutter: lib/features/reports/screens/order_report_screen.dart:71-78, lib/utill/app_constants.dart:176, lib/features/reports/domain/repositories/report_repository.dart:44-56; web: resources/views/vendor-views/report/order-report.blade.php:181-184, app/Http/Controllers/Vendor/OrderReportController.php:72-86; backend: routes/rest_api/v3/seller.php:604, app/Http/Controllers/RestAPI/v3/seller/SellerReportController.php:195

**Download the order report as a PDF summary**  
`seller_can:orders.view,orders.manage,finance.view`  
- App — Yes — 'download_pdf' option in the same export menu
- Web — Yes — 'Download_PDF' button on the order report
- Server — GET reports/orders/export-pdf (SellerReportController::exportOrdersPdf) and vendor.report.order-report-pdf — both render admin-views.transaction.total_orders_report_pdf via mPDF
- Evidence — flutter: lib/features/reports/screens/order_report_screen.dart:79-85, lib/utill/app_constants.dart:177; web: resources/views/vendor-views/report/order-report.blade.php:185-189, app/Http/Controllers/Vendor/OrderReportController.php:88-135; backend: routes/rest_api/v3/seller.php:605, app/Http/Controllers/RestAPI/v3/seller/SellerReportController.php:275-312

**View the product report for a period (counts by approval state: active / pending / rejected / total)**  
`seller_can:products.view,products.manage,inventory.manage (API) / finance.view for staff on web`  
- App — Yes — ProductReportScreen metric tiles
- Web — Yes — vendor.report.all-product count tiles
- Server — GET /api/v3/seller/seller-center/reports/products and Vendor\ProductReportController::all_product — both read SellerReportService::productReport
- Evidence — flutter: lib/features/reports/screens/product_report_screen.dart:107-129, lib/features/reports/domain/models/report_models.dart:255 (listedTotal); web: resources/views/vendor-views/report/all-product.blade.php:73-101, app/Http/Controllers/Vendor/ProductReportController.php:33-64; backend: routes/rest_api/v3/seller.php:609, app/Http/Controllers/RestAPI/v3/seller/SellerReportController.php:99

**Product report: total quantity sold and total discount given in the period**  
`seller_can:products.view,products.manage,inventory.manage`  
- App — Yes — 'sales' card rows quantity_sold and discount_given
- Web — Yes — Total_Product_Sale and total_Discount_Given tiles
- Server — totals.sold_quantity / totals.discount_given from SellerReportService::productReport
- Evidence — flutter: lib/features/reports/screens/product_report_screen.dart:136-141; web: resources/views/vendor-views/report/all-product.blade.php:112 and :122, app/Http/Controllers/Vendor/ProductReportController.php:56-57; backend: app/Services/Reports/SellerReportService.php:105-121

**Product report: products-listed-over-time chart**  
`seller_can:products.view,products.manage,inventory.manage`  
- App — Yes — ReportChartWidget titled products_listed_over_time
- Web — Yes — ApexCharts 'product_Statistics'
- Server — chart block of GET reports/products; same series builder as the order report
- Evidence — flutter: lib/features/reports/screens/product_report_screen.dart:145; web: resources/views/vendor-views/report/all-product.blade.php:139, app/Http/Controllers/Vendor/ProductReportController.php:58-59

**See the seller's best-selling products**  
`products.view`  
- App — Yes — 'best_selling_products' section on the product report (top 5 by value)
- Web — Yes, but on a different page — the vendor DASHBOARD's Top_Selling_Products card, not the product report
- Server — API: top_products from SellerReportService::topProducts (5, delivered, by SUM(qty*price)). Web: ProductRepository::getTopSellList from Vendor\DashboardController — a different query
- Evidence — flutter: lib/features/reports/screens/product_report_screen.dart:147-167, app/Services/Reports/SellerReportService.php:289-299; web: resources/views/vendor-views/dashboard/index.blade.php:138 → resources/views/vendor-views/partials/_top-selling-products.blade.php:1-30, app/Http/Controllers/Vendor/DashboardController.php:62-69. NOT present on resources/views/vendor-views/report/all-product.blade.php (grep for top_product/best_sell across vendor-views returns nothing).

**Product report: per-product row (name, unit price, amount sold, quantity sold, current stock)**  
`seller_can:products.view,products.manage,inventory.manage`  
- App — Yes — product cards showing price, current stock and quantity sold
- Web — Yes — table with product unit price, total amount sold, total quantity sold, average product value, current stock, average rating
- Server — products block of GET reports/products / SellerReportService::productQuery
- Evidence — flutter: lib/features/reports/screens/product_report_screen.dart:169-191, lib/features/reports/domain/models/report_models.dart:303-312; web: resources/views/vendor-views/report/all-product.blade.php:152-204, app/Http/Controllers/Vendor/ProductReportController.php:40-47

**Download the product report as an Excel spreadsheet**  
`seller_can:products.view,products.manage,inventory.manage`  
- App — Yes — single download icon in the app bar (no menu, only one format)
- Web — Yes — 'export' button on the product report
- Server — GET reports/products/export (SellerReportController::exportProducts) and vendor.report.all-product-excel — both render App\Exports\ProductReportExport
- Evidence — flutter: lib/features/reports/screens/product_report_screen.dart:68-76, lib/utill/app_constants.dart:178; web: resources/views/vendor-views/report/all-product.blade.php:146-149, app/Http/Controllers/Vendor/ProductReportController.php:67-81; backend: routes/rest_api/v3/seller.php:610, app/Http/Controllers/RestAPI/v3/seller/SellerReportController.php:248

**View the stock report — physical products ordered by how little is left**  
`seller_can:products.view,products.manage,inventory.manage (API) / finance.view for staff on web`  
- App — Yes — StockReportScreen (no date chips; a stock level is a fact about now)
- Web — Yes — vendor.report.stock-product-report
- Server — GET /api/v3/seller/seller-center/reports/stock and Vendor\ProductReportController::stock_product_report — both use SellerReportService::stockQuery
- Evidence — flutter: lib/features/reports/screens/stock_report_screen.dart:19-133, lib/features/reports/controllers/report_controller.dart:82; web: resources/views/vendor-views/report/product-stock.blade.php:52-98, app/Http/Controllers/Vendor/ProductReportController.php:83-106; backend: routes/rest_api/v3/seller.php:611, app/Http/Controllers/RestAPI/v3/seller/SellerReportController.php:154

**Stock report: sort stock low-to-high / high-to-low**  
`seller_can:products.view,products.manage,inventory.manage`  
- App — Yes — _SortToggleWidget pill toggle
- Web — Yes — sort <select> (ASC/DESC) in the filter form
- Server — `sort` query parameter, normalised to ASC/DESC in both controllers
- Evidence — flutter: lib/features/reports/screens/stock_report_screen.dart:97-104 and :136-169; web: resources/views/vendor-views/report/product-stock.blade.php:32-41, app/Http/Controllers/Vendor/ProductReportController.php:135-138; backend: app/Http/Controllers/RestAPI/v3/seller/SellerReportController.php:348-351

**Stock report: filter by category (with an 'all' reset)**  
`seller_can:products.view,products.manage,inventory.manage`  
- App — Yes — horizontal category chip row with a leading 'all' chip
- Web — Yes — all_category <select> populated from the same top-level category list
- Server — `category_id` query parameter; choices from SellerReportService::stockFilterCategories (Category where position=0)
- Evidence — flutter: lib/features/reports/screens/stock_report_screen.dart:106-115 and :171-215, lib/features/reports/controllers/report_controller.dart:82-98; web: resources/views/vendor-views/report/product-stock.blade.php:24-31, app/Http/Controllers/Vendor/ProductReportController.php:100,140-145; backend: app/Services/Reports/SellerReportService.php:185-188

**Download the stock report as an Excel spreadsheet**  
`seller_can:products.view,products.manage,inventory.manage`  
- App — Yes — download icon in the app bar, carrying the current sort and category
- Web — Yes — 'export' button carrying category_id, sort and search
- Server — GET reports/stock/export (SellerReportController::exportStock) and vendor.report.product-stock-export — both render App\Exports\ProductStockReportExport
- Evidence — flutter: lib/features/reports/screens/stock_report_screen.dart:35-58, lib/utill/app_constants.dart:179; web: resources/views/vendor-views/report/product-stock.blade.php:56-61, app/Http/Controllers/Vendor/ProductReportController.php:108-127; backend: routes/rest_api/v3/seller.php:612, app/Http/Controllers/RestAPI/v3/seller/SellerReportController.php:221

**Change one product's stock outside a bulk job**  
`seller_can:inventory.manage (API) / products.manage (web staff)`  
- App — Yes — inventory adjust screen (separate feature)
- Web — Yes — stock-limit list with an inline quantity form
- Server — POST /api/v3/seller/seller-center/inventory/products/{id}/adjust and POST /vendor/products/update-quantity
- Evidence — flutter: lib/features/inventory/domain/repositories/inventory_repository.dart:31-34, lib/utill/app_constants.dart:195; web: routes/vendor/routes.php:165-166, app/Http/Controllers/Vendor/Product/ProductController.php:754-813, resources/views/vendor-views/product/stock-limit-list.blade.php; backend: routes/rest_api/v3/seller.php:663-664. Belongs to the inventory domain — listed here only because it is the web's only stock-editing path and is therefore the fallback a WEB bulk-stock screen would replace.

## WEB MISSING (16)

**Product report: total sales VALUE (money) for the period**  
`seller_can:products.view,products.manage,inventory.manage` · wave 8  
- App — Yes — 'sales_value' row in the sales card (totals.sold_amount)
- Web — No — the blade prints only the sold QUANTITY (Total_Product_Sale) and the discount; totals.sold_amount is computed by the service but never passed to or rendered by the view
- Server — totals.sold_amount, already available: SellerReportService::productReport selects SUM(qty*price)
- Evidence — flutter: lib/features/reports/screens/product_report_screen.dart:138-139, lib/features/reports/domain/models/report_models.dart:242; web: app/Http/Controllers/Vendor/ProductReportController.php:56 passes only $report['totals']['sold_quantity'] — 'sold_amount' appears nowhere in resources/views/vendor-views/report/all-product.blade.php; backend: app/Services/Reports/SellerReportService.php:106,120

**Stock report: see the low-stock threshold currently in effect for this shop**  
`seller_can:products.view,products.manage,inventory.manage` · wave 8  
- App — Yes — printed as a value ('low_stock_threshold: N')
- Web — No — stockLimit is passed to the view but only used to colour the status badge; the number itself is never shown, so a seller cannot tell what 'soon_Stock_Out' is measured against
- Server — stock_limit in the API response; SellerReportService::stockLimitFor (seller's own setting, else platform default)
- Evidence — flutter: lib/features/reports/screens/stock_report_screen.dart:92-94, lib/features/reports/domain/models/report_models.dart:329; web: resources/views/vendor-views/report/product-stock.blade.php:87-93 uses $stockLimit only in comparisons — no output of the value; app/Http/Controllers/Vendor/ProductReportController.php:102; backend: app/Services/Reports/SellerReportService.php:175-183

**Stock report: a headline count of how many products are running low**  
`seller_can:products.view,products.manage,inventory.manage` · wave 8  
- App — Yes — 'running_low' metric tile (but see caveat: counted client-side over the loaded page only)
- Web — No — the web report shows per-row status badges but no aggregate low-stock count anywhere on the page
- Server — BACKEND GAP for a correct number: the API returns is_low_stock per row but no low-stock total; StockReportModel.lowStockCount counts only the 10 rows on the current page
- Evidence — flutter: lib/features/reports/screens/stock_report_screen.dart:84-88, lib/features/reports/domain/models/report_models.dart:339 (lowStockCount derived from `products`, i.e. the current page only); web: no low-stock count in resources/views/vendor-views/report/product-stock.blade.php (searched the whole file); backend: app/Http/Controllers/RestAPI/v3/seller/SellerReportController.php:163-181 returns total_size but no low-stock total

**Stock report: see each product's unit price alongside its stock**  
`seller_can:products.view,products.manage,inventory.manage` · wave 8  
- App — Yes — price row under every stock card
- Web — No — the stock table columns are SL, product name, last updated stock, current stock, status; no price column
- Server — unit_price is already in the API payload and in the underlying query
- Evidence — flutter: lib/features/reports/screens/stock_report_screen.dart:242, lib/features/reports/domain/models/report_models.dart:377; web: resources/views/vendor-views/report/product-stock.blade.php:63-98 (no price column); backend: app/Http/Controllers/RestAPI/v3/seller/SellerReportController.php:177

**See the history of bulk changes this shop has run (newest first, with status and progress)**  
`seller_can:products.view,products.manage,inventory.manage` · wave 8  
- App — Yes — BulkJobsScreen list with status chip, date and progress bar
- Web — No — there is no bulk-jobs screen in either web panel. The Seller Center navigation registry already reserves the slot (nav_bulk_operations → seller.bulk-jobs.index) but the route does not exist, so the item is silently dropped at render time
- Server — GET /api/v3/seller/seller-center/bulk-jobs (SellerBulkJobController::index) — exists and is seller-scoped
- Evidence — flutter: lib/features/bulk_jobs/screens/bulk_jobs_screen.dart:21-112, lib/features/bulk_jobs/controllers/bulk_job_controller.dart:153-166, lib/features/bulk_jobs/domain/repositories/bulk_job_repository.dart:13-20, lib/utill/app_constants.dart:226; web: grep for 'bulk' across resources/views/vendor-views/ matches only product/bulk-import.blade.php; routes/seller/routes.php has no bulk-jobs route while app/Services/SellerCenter/Navigation.php:72 names seller.bulk-jobs.index, which Navigation::for skips at app/Services/SellerCenter/Navigation.php:220-222; backend: routes/rest_api/v3/seller.php:670-675, app/Http/Controllers/RestAPI/v3/seller/SellerBulkJobController.php:42

**Open one bulk job's receipt: what was asked for, how far it got, how many succeeded and failed**  
`seller_can:products.view,products.manage,inventory.manage` · wave 8  
- App — Yes — BulkJobReceiptScreen with selected/changed/not-changed metric tiles, progress bar and job error text
- Web — No — no seller-facing receipt anywhere; only the ADMIN marketplace console can see bulk jobs
- Server — GET bulk-jobs/{id} (SellerBulkJobController::show) — returns counts, progress, input and failures
- Evidence — flutter: lib/features/bulk_jobs/screens/bulk_job_receipt_screen.dart:20-151 (metrics at :96-106), lib/features/bulk_jobs/controllers/bulk_job_controller.dart:168-180, lib/features/bulk_jobs/domain/repositories/bulk_job_repository.dart:23-30; web: not found — searched resources/views/vendor-views/, resources/views/seller-views/, routes/vendor/routes.php and routes/seller/routes.php; the only view is admin-only at app/Http/Controllers/Admin/Marketplace/SellerOperationsController.php:127 (routes/admin/routes.php:648); backend: routes/rest_api/v3/seller.php:673, app/Http/Controllers/RestAPI/v3/seller/SellerBulkJobController.php:68

**See exactly which products a bulk change refused, and why, in the seller's own language**  
`seller_can:products.view,products.manage,inventory.manage` · wave 8  
- App — Yes — a card per refused row with the product name and the translated reason
- Web — No
- Server — failures[] on GET bulk-jobs/{id}, each row carrying both the stable reason key and the translated message
- Evidence — flutter: lib/features/bulk_jobs/screens/bulk_job_receipt_screen.dart:115-144, lib/features/bulk_jobs/domain/models/bulk_job_models.dart:71-85; web: not found (no bulk UI at all); backend: app/Http/Controllers/RestAPI/v3/seller/SellerBulkJobController.php:214-222, reason keys produced at app/Services/Marketplace/Bulk/BulkPriceOperation.php:64,74,77 and app/Services/Marketplace/Bulk/BulkStockOperation.php:60,87

**Download the refused rows of a bulk job as a CSV to work through offline**  
`seller_can:products.view,products.manage,inventory.manage` · wave 8  
- App — Yes — 'download_csv' action on the receipt, saved as bulk-job-{id}-failures.csv
- Web — No
- Server — GET bulk-jobs/{id}/failures (SellerBulkJobController::downloadFailures) — streamed CSV
- Evidence — flutter: lib/features/bulk_jobs/screens/bulk_job_receipt_screen.dart:42-53 and :122-128, lib/features/bulk_jobs/controllers/bulk_job_controller.dart:184-200, lib/features/bulk_jobs/domain/repositories/bulk_job_repository.dart:63-73; web: not found; backend: routes/rest_api/v3/seller.php:674, app/Http/Controllers/RestAPI/v3/seller/SellerBulkJobController.php:130-148

**Start a new bulk change and pick the products it applies to (search, infinite-scroll paging, select-all-loaded, clear selection, selection survives searching and paging)**  
`seller_can:products.view (picker) / products.manage or inventory.manage to apply` · wave 8  
- App — Yes — BulkEditScreen: debounced search field, scroll-to-load-more, 'select loaded (n)' button, clear-selection action, selection kept as ids in a map
- Web — No — the nearest thing is per-product editing (stock-limit list posts one product_id at a time) or the spreadsheet bulk-import; there is no multi-select product picker
- Server — GET /api/v3/seller/products/{seller_id}/all-products (limit/offset/search) — the same endpoint the product list screen uses
- Evidence — flutter: lib/features/bulk_jobs/screens/bulk_edit_screen.dart:114-121 (search), :65-69 (load more), :128-133 (select loaded), :101-107 (clear), lib/features/bulk_jobs/controllers/bulk_job_controller.dart:21-82 and :84-120; web: routes/vendor/routes.php:165-166 (stock-limit-list + single-product update-quantity, app/Http/Controllers/Vendor/Product/ProductController.php:788-813) — no multi-select; backend: lib/features/product/domain/repositories/product_repository.dart:52 → routes/rest_api/v3/seller.php:695

**Change many product prices at once — set, increase %, decrease %, increase amount, decrease amount**  
`seller_can:products.manage` · wave 8  
- App — Yes — bottom sheet with the five mode chips and a decimal value field, naming the number of products before applying
- Web — No
- Server — POST /api/v3/seller/seller-center/bulk-jobs/price (SellerBulkJobController::storePriceUpdate → BulkPriceOperation). Refuses rather than clamps: a price that would land at ≤0, or a discount above the price, is refused with a reason; the seller's pricing floor is checked before the write
- Evidence — flutter: lib/features/bulk_jobs/screens/bulk_edit_screen.dart:213 (mode list), :263-280 (chips + value field), :227-237 (submit), lib/features/bulk_jobs/controllers/bulk_job_controller.dart:127-139, lib/features/bulk_jobs/domain/repositories/bulk_job_repository.dart:33-46, lib/utill/app_constants.dart:227; web: not found in routes/vendor/routes.php, routes/seller/routes.php or any vendor blade; backend: routes/rest_api/v3/seller.php:676, app/Http/Controllers/RestAPI/v3/seller/SellerBulkJobController.php:97, app/Services/Marketplace/Bulk/BulkPriceOperation.php:23-119

**Change many stock levels at once — set, increase, decrease (through the stock ledger, never a raw column write)**  
`seller_can:inventory.manage` · wave 8  
- App — Yes — same bottom sheet with the three stock modes and an integer-only field
- Web — No — web stock editing is one product at a time via products/update-quantity
- Server — POST bulk-jobs/stock (SellerBulkJobController::storeStockUpdate → BulkStockOperation → InventoryService::adjust). Cannot drive a balance negative, writes a movement line per change, refuses variant products (their stock is per variant), and clears the restock waiting list through the shared notifier
- Evidence — flutter: lib/features/bulk_jobs/screens/bulk_edit_screen.dart:214 and :180-188, lib/features/bulk_jobs/controllers/bulk_job_controller.dart:141-151, lib/features/bulk_jobs/domain/repositories/bulk_job_repository.dart:48-60, lib/utill/app_constants.dart:228; web: routes/vendor/routes.php:166 is single-product (app/Http/Controllers/Vendor/Product/ProductController.php:788); backend: routes/rest_api/v3/seller.php:677, app/Http/Controllers/RestAPI/v3/seller/SellerBulkJobController.php:113, app/Services/Marketplace/Bulk/BulkStockOperation.php:28-94

**Set a discount and discount type as part of a bulk price change**  
`seller_can:products.manage` · wave 8  
- App — Partial — the repository and service accept discount/discount_type and forward them, but the operation sheet exposes no input for either, so the app can never actually send them
- Web — No
- Server — Fully supported: 'discount' and 'discount_type' (percent|flat) validated by BulkPriceOperation::rules; omitting both leaves the product's discount untouched
- Evidence — flutter: declared at lib/features/bulk_jobs/domain/repositories/bulk_job_repository.dart:33-46 and lib/features/bulk_jobs/controllers/bulk_job_controller.dart:127-139, but never supplied — lib/features/bulk_jobs/screens/bulk_edit_screen.dart:232 calls submitPriceUpdate(mode:, value:) only; web: no bulk UI at all; backend: app/Services/Marketplace/Bulk/BulkPriceOperation.php:52-55 and :68-78

**Attach a note/reason to a bulk stock change so the ledger movement is explainable later**  
`seller_can:inventory.manage` · wave 8  
- App — Partial — the note is plumbed through the controller, service and repository but no field in the operation sheet ever sets it
- Web — No
- Server — Fully supported: 'note' (nullable, max 255) validated by BulkStockOperation::rules and written onto the inventory movement
- Evidence — flutter: lib/features/bulk_jobs/controllers/bulk_job_controller.dart:141-151 and lib/features/bulk_jobs/domain/repositories/bulk_job_repository.dart:48-60 accept `note`, but lib/features/bulk_jobs/screens/bulk_edit_screen.dart:233 calls submitStockUpdate(mode:, value:) only; web: no bulk UI; backend: app/Services/Marketplace/Bulk/BulkStockOperation.php:53 and :81-82

**Follow a running bulk job to completion and be told when it is still running rather than being shown a false 'done'**  
`seller_can:products.view,products.manage,inventory.manage` · wave 8  
- App — Yes — after submitting, the controller polls the job up to 10 times with growing backoff, then shows the receipt with a 'bulk_still_running' line if it has not finished
- Web — No
- Server — Job is queued (202) and run by App\Jobs\RunSellerBulkJob; is_finished/progress on every read; a stuck-queue safety net exists as the console command seller:run-stuck-bulk-jobs
- Evidence — flutter: lib/features/bulk_jobs/controllers/bulk_job_controller.dart:202-241 (_submit + _awaitCompletion), lib/features/bulk_jobs/screens/bulk_job_receipt_screen.dart:108-113, lib/features/bulk_jobs/widgets/bulk_job_widgets.dart:49-83 (progress bar with processed/total); web: not found; backend: app/Http/Controllers/RestAPI/v3/seller/SellerBulkJobController.php:158-161 (202) and :190-206, app/Jobs/RunSellerBulkJob.php:25, app/Console/Commands/RunStuckSellerBulkJobs.php:25

**Distinguish a PARTIAL bulk result (finished, some rows refused) from a full success and from an outright failure**  
`seller_can:products.view,products.manage,inventory.manage` · wave 8  
- App — Yes — 'partial' gets its own colour and chip rather than being folded into success or failure
- Web — No
- Server — Statuses queued/processing/completed/partial/failed decided by SellerBulkJobService; partial when some succeeded and some failed
- Evidence — flutter: lib/features/bulk_jobs/widgets/bulk_job_widgets.dart:17-42 (colorFor switch), lib/features/bulk_jobs/domain/models/bulk_job_models.dart:60 (isPartial); web: not found; backend: app/Services/Marketplace/Bulk/SellerBulkJobService.php:250-259

**See a 'bulk jobs still running' badge in the seller navigation**  
`seller_can:products.manage` · wave 8  
- App — No badge in the app's More menu — the entry is a plain row
- Web — No — the count is already computed server-side for the Seller Center rail, but the nav item is dropped because seller.bulk-jobs.index does not exist
- Server — App\Services\SellerCenter\Counts::bulkJobs counts queued+processing jobs; wired to the 'bulk_running' badge key in the navigation registry
- Evidence — flutter: lib/features/menu/screens/more_screen.dart:96-98 (no badge); web: app/Services/SellerCenter/Navigation.php:72 declares badge 'bulk_running' but the route is missing so Navigation::for:220-222 removes the item; backend: app/Services/SellerCenter/Counts.php:159-169

## APP MISSING (8)

**Search within the order report (by order id)**  
`seller_can:orders.view,orders.manage,finance.view`  
- App — No — the repository and service accept a `search` parameter but no screen ever passes one and there is no search field
- Web — Yes — x-k.data-view search box, search_by_order_id
- Server — `search` query parameter, honoured by SellerReportService::orderQuery for both API and web
- Evidence — flutter: lib/features/reports/domain/services/report_service_interface.dart:2 and lib/features/reports/domain/repositories/report_repository.dart:41 declare `search`, but lib/features/reports/controllers/report_controller.dart:50-64 never passes it and order_report_screen.dart has no search widget; web: resources/views/vendor-views/report/order-report.blade.php:177-179, app/Http/Controllers/Vendor/OrderReportController.php:38

**Page through the report result lists (orders / products / stock)**  
`same as parent report`  
- App — No — every report call sends offset: 1 with limit 10; there is no load-more or pager on any of the three report screens
- Web — Yes — Laravel paginator with 'showing x–y of n' on all three report pages
- Server — limit/offset supported on all three report endpoints (page cursor named `offset`, one-based)
- Evidence — flutter: lib/features/reports/controllers/report_controller.dart:60, :76, :94 (offset: 1) and lib/features/reports/domain/repositories/report_repository.dart:24,36 (limit 10); web: resources/views/vendor-views/report/order-report.blade.php:255-263, resources/views/vendor-views/report/all-product.blade.php:210-219, resources/views/vendor-views/report/product-stock.blade.php:104-113; backend: app/Http/Controllers/RestAPI/v3/seller/SellerReportController.php:360-371

**Jump from a report row to the underlying order / product record**  
`orders.view / products.view`  
- App — No — report rows are inert cards; nothing navigates to the order or product detail screen
- Web — Yes — order id links to vendor.orders.details, product name links to vendor.products.view on both the product and stock reports
- Server — none needed (navigation only)
- Evidence — flutter: lib/features/reports/screens/order_report_screen.dart:222-249 (_OrderRowWidget has no onTap), product_report_screen.dart:175-190, stock_report_screen.dart:217-251; web: resources/views/vendor-views/report/order-report.blade.php:213-215, resources/views/vendor-views/report/all-product.blade.php:170-174, resources/views/vendor-views/report/product-stock.blade.php:78-82

**Product report: average product value and average customer rating per product**  
`seller_can:products.view,products.manage,inventory.manage`  
- App — No — neither column is computed nor rendered; ReportProductModel carries no rating field
- Web — Yes — average_Product_Value and average_Ratings columns with review count
- Server — web-only: computed in the blade from orderDetails, and from $product->rating / $product->reviews eager loads; the API response has no rating field
- Evidence — flutter: lib/features/reports/domain/models/report_models.dart:282-312 (no rating/average fields), product_report_screen.dart:175-190; web: resources/views/vendor-views/report/all-product.blade.php:160,162 and :181-199; backend: app/Http/Controllers/RestAPI/v3/seller/SellerReportController.php:128-137 omits rating

**Stock report: search a product by name**  
`seller_can:products.view,products.manage,inventory.manage`  
- App — No — the repository sends `search` when given but the screen has no search field and the controller never sets one
- Web — Yes — x-k.data-view search box (search_Product_Name)
- Server — `search` query parameter honoured by SellerReportService::stockQuery for both callers
- Evidence — flutter: lib/features/reports/domain/repositories/report_repository.dart:27 accepts search, but lib/features/reports/controllers/report_controller.dart:82-98 never passes it and stock_report_screen.dart has no field; web: resources/views/vendor-views/report/product-stock.blade.php:52-54, app/Http/Controllers/Vendor/ProductReportController.php:101; backend: app/Services/Reports/SellerReportService.php:165

**Stock report: see when a product's stock was last updated**  
`seller_can:products.view,products.manage,inventory.manage`  
- App — No — StockProductModel carries no updated_at and no card shows a date
- Web — Yes — last_Updated_Stock column
- Server — web reads $data['updated_at'] off the model; the API response omits it
- Evidence — flutter: lib/features/reports/domain/models/report_models.dart:352-380 (no timestamp field); web: resources/views/vendor-views/report/product-stock.blade.php:68 and :84; backend: app/Http/Controllers/RestAPI/v3/seller/SellerReportController.php:173-180 does not emit updated_at

**Import or create many products from a spreadsheet (bulk import)**  
`products.manage`  
- App — No — no bulk-import screen, endpoint or constant anywhere in the app (grep for bulk_import/bulkImport across lib returns nothing)
- Web — Yes — vendor.products.bulk-import, with downloadable xlsx templates, and surfaced in the new Seller Center product page too
- Server — POST /vendor/products/bulk-import (Vendor\Product\ProductController::importBulkProduct via ProductService::getImportBulkProductData) — web-only, no API equivalent
- Evidence — flutter: not found — searched lib/ for bulk_import|bulkImport|bulk-import; web: routes/vendor/routes.php:172-173, app/Http/Controllers/Vendor/Product/ProductController.php:863-883, resources/views/vendor-views/product/bulk-import.blade.php:111-123, resources/views/seller-views/products/index.blade.php:32

**Order-wise and expense transaction reports with Excel and PDF export**  
`finance.view`  
- App — No — the nearest analog is the account statement (a ledger view with entry-type/status/date filters and its own export), not the order-wise or expense transaction report
- Web — Yes — vendor.transaction.order-list and expense-list, each with order-wise PDF, summary PDF and Excel export
- Server — Vendor\TransactionReportController via App\Services\Admin\Reports\TransactionReportService; the app uses GET /api/v3/seller/seller-center/statement (+ /export) instead
- Evidence — flutter: lib/features/statement/domain/repositories/statement_repository.dart:13-37, lib/utill/app_constants.dart:182-183 — no order-wise/expense transaction report exists; web: routes/vendor/routes.php:460-471, app/Http/Controllers/Vendor/TransactionReportController.php:30-38, resources/views/layouts/vendor/partials/v2/_side-bar.blade.php:330-333. Overlaps the finance/statement domain audit — flagged here because the vendor sidebar files it under 'reports_&_analytics'.

## WEB ENHANCEMENT (1)

**View shop traffic analytics (visitors, visits, product views, cart adds, orders, revenue, per-product view→cart funnel) over a range**  
`none declared on the vendor route; staff are denied by the deny-by-default map`  
- App — Yes — lib/features/seller_center/screens/analytics_screen.dart
- Web — Yes, but unreachable from the UI — resources/views/vendor-views/analytics/index.blade.php exists and vendor.analytics.index is routed, yet no navigation anywhere links to it, and seller STAFF are refused (the 'analytics' URL segment is unmapped → deny-by-default 403)
- Server — GET /api/v3/seller/seller-center/analytics (+ /activities) and Vendor\AnalyticsController::index — both via App\Services\Analytics\Reporting\AnalyticsReporting::forVendor
- Evidence — flutter: lib/features/seller_center/screens/analytics_screen.dart, lib/features/seller_center/domain/repositories/seller_center_repository.dart:107, lib/utill/app_constants.dart:168-169, lib/features/menu/screens/more_screen.dart:147-149; web: routes/vendor/routes.php:101-102, app/Http/Controllers/Vendor/AnalyticsController.php:53-74, resources/views/vendor-views/analytics/index.blade.php:11-64 — grep for 'vendor.analytics.index' across resources/ and app/ matches only the view's own range links; staff denial at app/Http/Middleware/SellerStaffAccessMiddleware.php:106-108 (default => DENY)

## APP ADAPTATION (1)

**Order report: list the orders in the period with their financial breakdown**  
`seller_can:orders.view,orders.manage,finance.view`  
- App — Partial — first 10 orders only, showing amount, product discount, coupon discount, tax, commission
- Web — Yes — full paginated table: order id (linked), total, product/coupon/referral discount, shipping charge, VAT, commission, deliveryman incentive, status
- Server — orders block of GET reports/orders (paginated) / OrderReportController::order_report
- Evidence — flutter: lib/features/reports/screens/order_report_screen.dart:201-211 and 222-249, lib/features/reports/controllers/report_controller.dart:60 (offset hard-coded to 1, limit 10 at report_repository.dart:36); web: resources/views/vendor-views/report/order-report.blade.php:191-247, app/Http/Controllers/Vendor/OrderReportController.php:41-43

## DEVICE SPECIFIC (2)

**Exported file lands in the device's Downloads folder and opens in the OS viewer**  
`none`  
- App — Yes — bytes written to /storage/emulated/0/Download (falling back to app documents), de-duplicated with (1)/(2) suffixes, then OpenFile.open
- Web — N/A — the browser's own download handles this
- Server — none (client-side file handling)
- Evidence — flutter: lib/features/reports/controllers/report_controller.dart:132-156, lib/features/reports/widgets/report_export_button_widget.dart:37-61 (snackbar success/failure then OpenFile.open at :60); web: browser default, no code

**Pull-to-refresh a report**  
`none`  
- App — Yes — RefreshIndicator on all three report screens and on both bulk screens
- Web — N/A — page reload
- Server — re-issues the same GET
- Evidence — flutter: lib/features/reports/screens/order_report_screen.dart:92-93, product_report_screen.dart:82-83, stock_report_screen.dart:64-65, lib/features/bulk_jobs/screens/bulk_jobs_screen.dart:60-61

