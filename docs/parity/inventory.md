# Parity — inventory

[← back to the matrix](../SELLER_WEB_APP_PARITY.md) · 40 capabilities

**14** BOTH · **19** WEB MISSING · **4** APP MISSING · **1** DEVICE SPECIFIC · **2** BACKEND MISSING

## Structural facts the implementer must know

```
STRUCTURAL FACTS THE IMPLEMENTER MUST KNOW

1. Two different stock-write paths exist, and the web only has the unsafe one.
   - Ledger path (app only): POST /api/v3/seller/seller-center/inventory/products/{id}/adjust → App\Services\Marketplace\InventoryService::adjust() (/home/user/Pharmacy/app/Services/Marketplace/InventoryService.php:34-79). Row-locked transaction, refuses a negative balance, writes a StockMovement (signed change, balance_after, reason, note, actor) and an audit record.
   - Overwrite path (web + app legacy screens): vendor.products.update-quantity (/home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:788-820) and PUT /api/v3/seller/products/quantity-update (/home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1767-1788) both call productRepo->updateByParams(['current_stock' => …]) directly. No lock, no reason, NO movement row. Every stock edit made in the web panel is therefore invisible in the ledger the app now shows — the two surfaces will disagree until the web is moved onto InventoryService::adjust() (or at minimum calls InventoryService::record()).

2. The web vendor panel has no inventory module at all. There is no 'inventory' string in /home/user/Pharmacy/routes/vendor/routes.php, and grep for warehouse/batch/movement over /home/user/Pharmacy/resources/views/vendor-views/ returns zero hits. Full working reference implementations exist on the ADMIN side and can be adapted: /home/user/Pharmacy/routes/admin/routes.php:687-692 (inventory-adjustments), :722-728 (batches), :732-740 (warehouses), with controllers under /home/user/Pharmacy/app/Http/Controllers/Admin/Marketplace/.

3. Threshold inconsistency. The app's inventory overview uses a HARD-CODED constant (SellerInventoryController::LOW_STOCK_THRESHOLD = 5, /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:42,69-73), while every other low-stock surface (web stock-limit-list, web dashboard KPI, /products/stock-out-list, /products/stock-limit-status, stock report) uses the seller's own sellers.stock_limit falling back to the global business setting 'stock_limit'. The same shop can show a different "running low" count in the Inventory screen than in the low-stock list. Fix by feeding stock_limit into overview().

4. Broken/dead web controls on the restock page (fix while adding the missing filters):
   - The filter offcanvas posts `from`/`to`, the controller parses `restock_date` in 'm/d/Y - m/d/Y' (/home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:149-160). Date filtering never applies.
   - The page's JS binds $('input[name="restock_date"]') (/home/user/Pharmacy/resources/views/vendor-views/product/request-restock-list.blade.php:146) to an input that does not exist.
   - 'Clear_Filter' is <a href="#"> with no handler (_restock-list-filter-offcanvas.blade.php:48-50).
   - brand_id / category_id / sub_category_id are read by the controller and echoed into the export link but have no UI.

5. Security issues found while tracing this domain (report upward, not part of parity):
   - GET /api/v3/seller/products/restock-request-delete?id= has NO ownership check (/home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/ProductController.php:2135-2142) — any authenticated seller can delete another seller's restock rows. The web equivalent does verify ownership through the product (/home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:179-190).
   - vendor.products.get-variations looks the product up unscoped (/home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:852-861), leaking another seller's variation/SKU/price data into the stock modal.

6. Client-side business state: none inside lib/features/inventory or lib/features/restock — grep for SharedPreferences there returns nothing, and all inventory state (reasons list, movement types, warehouses_enabled, batches_enabled, thresholds) is server-supplied. The only client-stored flag in this area is the low-stock banner dismissal: the app writes AppConstants.showCookies to SharedPreferences with no expiry (permanent, per device — /home/user/sillercenter-syria-cosmatics/lib/features/product/domain/repositories/product_repository.dart:169-176), while the web uses a 30-day cookie. It suppresses a business alert forever on that device; consider a server-side or expiring preference for parity with the web.

7. Client-side gaps in the app worth fixing at the same time (the server already supports them): movement type filter has no UI, movement list never paginates past offset 1 (repo accepts `offset`, controller never passes it — inventory_controller.dart:78), the batch expiry window is fixed at 30 days, and the warehouses response's `breakdown`/`unallocated` per-product figures are fetched then discarded by WarehouseModel.

8. Domain overlap: rows 34-35 (stock report / export) belong to the reports domain, row 37 (bulk stock update) to the bulk_jobs domain, and rows 19-24 (low-stock list, banner) partly to the product domain. They are listed here because they are stock capabilities and the parity verdicts differ from those domains' other rows. Barcode scanning is POS-only (lib/features/pos/controllers/barcode_scan_controller.dart) — no inventory flow looks a product up by barcode on either surface, so nothing DEVICE SPECIFIC arises in this domain other than note 6.
```

## BOTH (14)

**See shop-wide out-of-stock and running-low product counts**  
`inventory.manage OR products.view (API); web dashboard is ALLOW for any staff`  
- App — Yes — inventory overview metric tiles (lib/features/inventory/widgets/inventory_view.dart, InventoryScreen from More menu)
- Web — Partial — dashboard KPI cards show low_stock / out_of_stock counts, but there is no inventory screen and no drill-down link
- Server — GET /api/v3/seller/seller-center/inventory/overview (SellerInventoryController@overview); web uses VendorDashboardStatsService::inventoryAlerts()
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/inventory/widgets/inventory_view.dart:124-137 (ReportMetricWidget products/out_of_stock/running_low) + /home/user/sillercenter-syria-cosmatics/lib/features/inventory/controllers/inventory_controller.dart:48-61 | WEB /home/user/Pharmacy/resources/views/vendor-views/partials/_operational-kpis.blade.php:54-79 + /home/user/Pharmacy/app/Http/Controllers/Vendor/DashboardController.php:144-152 | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:63-88

**Set a product's stock to an absolute value (main stock + per-variation quantities)**  
`inventory.manage OR products.manage (API); products.manage (web staff map)`  
- App — Yes — QuantityUpdateDialogWidget / RestockSheetWidget / LimitedStockQuantityUpdateDialogWidget, main stock is read-only and computed from the variation sum when variations exist
- Web — Yes — 'update quantity' modal loaded via vendor.products.get-variations, posted to vendor.products.update-quantity; main stock is readonly when the product has variations
- Server — PUT /api/v3/seller/products/quantity-update (ProductController@updateProductQuantity) and POST vendor.products.update-quantity
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/restock/domain/repositories/restock_repository.dart:70-84 + controller :279-304 + /home/user/sillercenter-syria-cosmatics/lib/features/restock/widgets/quantity_update_dialog_widget.dart:106-124 | WEB /home/user/Pharmacy/routes/vendor/routes.php:166,171 + /home/user/Pharmacy/resources/views/vendor-views/product/partials/_update-stock.blade.php:19-40 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:788-820 | API /home/user/Pharmacy/routes/rest_api/v3/seller/seller.php:186-188 (routes/rest_api/v3/seller.php:186-188) + /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1767-1788

**Edit stock per variation, with the variants customers actually asked for marked**  
`inventory.manage OR products.manage`  
- App — Yes — RestockSheetWidget renders one qty field per variation and appends ' *' to variants present in variant_keys
- Web — Yes — _edit-restock-combinations renders one qty input per variation and marks requested variants with a required asterisk
- Server — POST /api/v3/seller/products/restock-request-stock-update (variant_keys supplied by restock-request-list) / vendor.products.get-variations + update-quantity
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/restock/widgets/restock_bottom_sheet.dart:209-212 (variantKeys ' *') + /home/user/sillercenter-syria-cosmatics/lib/features/restock/controllers/restock_controller.dart:237-244,307-317 | WEB /home/user/Pharmacy/resources/views/vendor-views/product/partials/_edit-restock-combinations.blade.php:23-47 (asterisk at :28-30) | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/ProductController.php:2121-2125 (variant_keys), :2144-2166

**View the low-stock / stock-limit product list (products at or below the re-order level)**  
`products.view / products.manage`  
- App — Yes — StockOutProductScreen (also embedded on Home)
- Web — Yes — vendor.products.stock-limit-list
- Server — GET /api/v3/seller/products/stock-out-list (ProductController@stock_out_list) / vendor.products.stock-limit-list
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/product/screens/stock_out_product_screen.dart:10-29 + repo /home/user/sillercenter-syria-cosmatics/lib/features/product/domain/repositories/product_repository.dart:85-95 | WEB /home/user/Pharmacy/routes/vendor/routes.php:165 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:754-786 + /home/user/Pharmacy/resources/views/vendor-views/product/stock-limit-list.blade.php:1-60 | API /home/user/Pharmacy/routes/rest_api/v3/seller.php:157 + /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/ProductController.php:692-720

**Top up stock directly from a low-stock row (main + variation quantities)**  
`inventory.manage OR products.manage`  
- App — Yes — '+' button on the stock-out card opens LimitedStockQuantityUpdateDialogWidget
- Web — Yes — '+' icon on the quantity cell opens the update-quantity modal
- Server — PUT /api/v3/seller/products/quantity-update / POST vendor.products.update-quantity
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/product/widgets/stockout_product_card_widget.dart:318-322,404-448 | WEB /home/user/Pharmacy/resources/views/vendor-views/product/stock-limit-list.blade.php:87-96 | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1767-1788

**Be alerted when products fall to the re-order level (low-stock warning banner with a link to the list)**  
`products.view`  
- App — Yes — CookiesWidget banner driven by /products/stock-limit-status, polled on app state
- Web — Yes — .product-limited-stock-alert banner polling vendor.products.stock-limit-status every 10 minutes
- Server — GET /api/v3/seller/products/stock-limit-status / vendor.products.stock-limit-status
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/product/widgets/cookies_widget.dart:44-108 + gate in /home/user/sillercenter-syria-cosmatics/lib/main.dart:320-325 + repo /home/user/sillercenter-syria-cosmatics/lib/features/product/domain/repositories/product_repository.dart:158-167 | WEB /home/user/Pharmacy/resources/views/layouts/vendor/partials/_translated-message-container.blade.php:33 + /home/user/Pharmacy/public/assets/back-end/js/custom.js:1619-1700 | API /home/user/Pharmacy/routes/rest_api/v3/seller.php:164 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:959-975

**Set the shop's re-order level / low-stock threshold**  
`shop_settings.manage`  
- App — Yes — 'Re-order Level' field on the shop Other Setup screen
- Web — Yes — stock_limit input on shop other-setup
- Server — POST vendor.shop.* other-setup (ShopController@…, reads stock_limit) and the seller shop-update API; stored on sellers.stock_limit
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/shop/screens/other_setup_screen.dart:435 + /home/user/sillercenter-syria-cosmatics/lib/features/shop/domain/repositories/shop_repository.dart:67 ('stock_limit') + /home/user/sillercenter-syria-cosmatics/lib/features/shop/controllers/shop_controller.dart:190-210 | WEB /home/user/Pharmacy/resources/views/vendor-views/shop/other-setup.blade.php:81-95 + /home/user/Pharmacy/app/Http/Controllers/Vendor/ShopController.php:161 | consumed by /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:761 and /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/ProductController.php:695

**View the restock-request list (customers waiting for a product to come back), with a total count**  
`products.view / products.manage`  
- App — Yes — RestockListScreen with a count badge in the app bar and infinite pagination
- Web — Yes — vendor.products.request-restock-list with a count badge and pager
- Server — POST /api/v3/seller/products/restock-request-list / GET vendor.products.request-restock-list
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/restock/screens/restock_list_screen.dart:65-82 (count badge), :139-167 (PaginatedListViewWidget) + repo /home/user/sillercenter-syria-cosmatics/lib/features/restock/domain/repositories/restock_repository.dart:18-26 | WEB /home/user/Pharmacy/resources/views/vendor-views/product/request-restock-list.blade.php:18-20,107-116 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:139-176 | API /home/user/Pharmacy/routes/rest_api/v3/seller.php:167 + /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/ProductController.php:2088-2133

**Search the restock list by product name**  
`products.view`  
- App — Yes — SearchBarWidget bound to the 'search' body field
- Web — Yes — searchValue on the data-view
- Server — POST .../restock-request-list {search} / ?searchValue=
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/restock/widgets/search_bar_widget.dart:385-400 + controller setSearchText /home/user/sillercenter-syria-cosmatics/lib/features/restock/controllers/restock_controller.dart:191-203,135 | WEB /home/user/Pharmacy/resources/views/vendor-views/product/request-restock-list.blade.php:19-20 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:164 | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/ProductController.php:2112

**See how many customers requested each restocked product, and when the last request came in**  
`products.view`  
- App — Yes — 'total_request' count and 'last_request' timestamp on each row
- Web — Yes — 'number_of_request' and 'last_request_date' columns
- Server — restock_product_customers_count / updated_at on both list endpoints
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/restock/widgets/restock_list_item_widget.dart:309-316 (count), :323-333 (last request) | WEB /home/user/Pharmacy/resources/views/vendor-views/product/request-restock-list.blade.php:40-41,67-72 | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/ProductController.php:2121-2125 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:160-166

**Remove a restock request from the list**  
`products.manage`  
- App — Yes — 'x' on each row (immediate, no confirmation)
- Web — Yes — trash icon posting a DELETE form
- Server — GET /api/v3/seller/products/restock-request-delete?id={id} / DELETE vendor.products.delete-restock/{id}
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/restock/widgets/restock_list_item_widget.dart:269-283 + controller :100-112 + repo :41-48 | WEB /home/user/Pharmacy/resources/views/vendor-views/product/request-restock-list.blade.php:86-95 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:179-190 | API /home/user/Pharmacy/routes/rest_api/v3/seller.php:181 + /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/ProductController.php:2135-2142 (NO ownership check — see notes)

**Restock a requested product and have the waiting customers notified**  
`inventory.manage OR products.manage`  
- App — Yes — update-quantity from a restock row; the server notifies restock subscribers
- Web — Yes — same modal/route, same notification path
- Server — POST /api/v3/seller/products/restock-request-stock-update (updateRestockQuantity → updateRestockRequestListAndNotify) / POST vendor.products.update-quantity
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/restock/widgets/restock_list_item_widget.dart:342-404 + controller updateRestockProductQuantity :247-275 + repo :52-66 | WEB /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:812-816 (updateRestockRequestListAndNotify) | API /home/user/Pharmacy/routes/rest_api/v3/seller.php:188 + /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/ProductController.php:2144-2166

**Run a per-product stock report (current stock vs re-order level) with search, category filter and sort**  
`products.view / products.manage / inventory.manage (API); finance.view (web 'report' area in the staff map)`  
- App — Yes — StockReportScreen
- Web — Yes — vendor.report.stock-product-report
- Server — GET /api/v3/seller/seller-center/reports/stock / GET vendor.report.stock-product-report
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/reports/domain/repositories/report_repository.dart:22-30 + /home/user/sillercenter-syria-cosmatics/lib/features/reports/screens/stock_report_screen.dart:52,93 + URI /home/user/sillercenter-syria-cosmatics/lib/utill/app_constants.dart:175 | WEB /home/user/Pharmacy/routes/vendor/routes.php:449 + /home/user/Pharmacy/app/Http/Controllers/Vendor/ProductReportController.php:96-106 + /home/user/Pharmacy/resources/views/vendor-views/report/product-stock.blade.php | API /home/user/Pharmacy/routes/rest_api/v3/seller.php:611

**Export the stock report**  
`products.view / inventory.manage`  
- App — Yes — export action on the stock report screen
- Web — Yes — product-stock-export (Excel)
- Server — GET /api/v3/seller/seller-center/reports/stock/export / GET vendor.report.product-stock-export
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/reports/screens/stock_report_screen.dart:52 (AppConstants.stockReportExportUri) + /home/user/sillercenter-syria-cosmatics/lib/utill/app_constants.dart:179 | WEB /home/user/Pharmacy/routes/vendor/routes.php:450 + /home/user/Pharmacy/app/Http/Controllers/Vendor/ProductReportController.php:108-127 | API /home/user/Pharmacy/routes/rest_api/v3/seller.php:612

## WEB MISSING (19)

**See total units on hand across the catalogue and the number of movements recorded**  
`inventory.manage OR products.view` · wave 2  
- App — Yes — ReportRowWidget rows 'units_on_hand' and 'movements_recorded'
- Web — No — no view anywhere in vendor-views renders units-on-hand or a movement count
- Server — GET /api/v3/seller/seller-center/inventory/overview
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/inventory/widgets/inventory_view.dart:139-142 | WEB not found — grep 'units_on_hand|movements' over /home/user/Pharmacy/resources/views/vendor-views/ returns nothing | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:74-77

**View the shop-wide stock movement ledger (type, signed change, resulting balance, reason, note, who made it, when)**  
`inventory.manage OR products.view` · wave 2  
- App — Yes — 'stock_movements' section, newest first, one card per movement
- Web — No — nothing in the vendor panel reads stock_movements; the model/table exist but only the admin panel exposes them
- Server — GET /api/v3/seller/seller-center/inventory/movements (SellerInventoryController@movements)
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/inventory/widgets/inventory_view.dart:188-218 and :273-313 (_movement card) + repo /home/user/sillercenter-syria-cosmatics/lib/features/inventory/domain/repositories/inventory_repository.dart:15-21 | WEB not found — grep -rni 'movement' /home/user/Pharmacy/resources/views/vendor-views/ = 0 hits; admin-only route /home/user/Pharmacy/routes/admin/routes.php:687-692 (inventory-adjustments) | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:101-134

**View the stock movement history of ONE product (from the product's own page)**  
`inventory.manage OR products.view` · wave 2  
- App — Yes — InventoryView embedded as a tab on product details, narrowed by product_id
- Web — No — vendor product view shows only the current_stock number, no history tab
- Server — GET /api/v3/seller/seller-center/inventory/movements?product_id={id}
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/product_details/screens/product_details_screen.dart:166-172 (InventoryView tab) + /home/user/sillercenter-syria-cosmatics/lib/features/inventory/widgets/inventory_view.dart:74-88 | WEB /home/user/Pharmacy/resources/views/vendor-views/product/view.blade.php:436-438 shows current_Stock only | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:103-116 + productIdFilter :277-286

**Filter the movement log by movement type (adjustment / receipt / sale / return / transfer)**  
`inventory.manage OR products.view` · wave 2  
- App — Partial — repository, controller and the overview's movement_types list all support it, but no UI control is rendered (getMovements is always called with clearType: true)
- Web — No
- Server — GET /api/v3/seller/seller-center/inventory/movements?type={type}; type list served by overview
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/inventory/domain/repositories/inventory_repository.dart:20 ('type' query) + controller :63-87 (_type) + model movementTypes /home/user/sillercenter-syria-cosmatics/lib/features/inventory/domain/models/inventory_models.dart:177,202; NO chip/dropdown in inventory_view.dart (only _load() at :74-88 passing clearType: true) | WEB not found | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:112,288-293 and movement types :79-82

**Correct a product's stock with a required reason (add/remove direction, quantity, reason chip, optional note) written through the stock ledger**  
`inventory.manage (API); web write is mapped to products.manage only` · wave 2  
- App — Yes — StockAdjustSheet opened from the 'correct_stock' FAB on a product's inventory tab
- Web — No — the only web write is vendor.products.update-quantity, a raw overwrite of current_stock with no reason, no note and NO movement row
- Server — POST /api/v3/seller/seller-center/inventory/products/{id}/adjust (SellerInventoryController@adjust → InventoryService::adjust, row-locked, refuses negative, writes StockMovement)
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/inventory/widgets/stock_adjust_sheet.dart:414-425 (add/remove), :428-437 (qty), :455-459 (reason chips), :462-469 (note), :479-485 (apply) + repo :31-45 | WEB /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:788-820 — updateByParams(['current_stock'=>…]) with no InventoryService call, so no ledger entry | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:148-189 + /home/user/Pharmacy/app/Services/Marketplace/InventoryService.php:34-79

**Pick the adjustment reason from the server-defined list (count_correction, damage, loss, theft, found, expiry, other)**  
`inventory.manage` · wave 2  
- App — Yes — reasons come from the overview response and render as ChoiceChips; Apply stays disabled until one is chosen
- Web — No — no reason concept exists in the vendor stock form
- Server — reasons[] in GET .../inventory/overview; validated on adjust against StockMovement::REASONS
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/inventory/widgets/stock_adjust_sheet.dart:392,455-459,484 + controller reasons getter :46 | WEB not found — /home/user/Pharmacy/resources/views/vendor-views/product/partials/_update-stock.blade.php:18-40 has only a quantity field | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:78,160 + /home/user/Pharmacy/app/Models/StockMovement.php:19

**See the resulting balance before applying a correction, and get an actionable refusal when it would drive stock negative**  
`inventory.manage` · wave 2  
- App — Yes — 'will_become' preview next to current stock; the 422 message is shown inline in the sheet
- Web — No — web only checks current_stock >= 0 on the submitted absolute value and shows a generic toast
- Server — POST .../inventory/products/{id}/adjust returns 422 {errors:[{code:'stock',message:…}]} when the delta would go negative
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/inventory/widgets/stock_adjust_sheet.dart:361-366 (_resulting), :440-452 (preview), :471-476 (refusal) + controller _refusalFrom /home/user/sillercenter-syria-cosmatics/lib/features/inventory/controllers/inventory_controller.dart:119-158 | WEB /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:806,818 (ToastMagic::warning only) | API SellerInventoryController.php:179-183 + InventoryService.php:50-55

**See the seller's warehouses (name, code, address, default flag, status)**  
`inventory.manage OR products.view` · wave 2  
- App — Yes — warehouse cards, rendered only when warehouses_enabled
- Web — No — vendor panel has no warehouse surface at all (warehouse management is admin-only)
- Server — GET /api/v3/seller/seller-center/inventory/warehouses (SellerInventoryController@warehouses)
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/inventory/widgets/inventory_view.dart:146-177 + controller :89-100 + model WarehouseModel /home/user/sillercenter-syria-cosmatics/lib/features/inventory/domain/models/inventory_models.dart:274-292 | WEB not found — grep -rni 'warehouse' /home/user/Pharmacy/resources/views/vendor-views/ = 0 hits; admin-only /home/user/Pharmacy/routes/admin/routes.php:732-740 | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:202-217,313-331

**See how many units of a product sit in each warehouse and how many are unallocated**  
`inventory.manage OR products.view` · wave 2  
- App — No — the endpoint returns 'breakdown' and 'unallocated' for a product_id but WarehouseModel/the view drop both fields
- Web — No
- Server — GET .../inventory/warehouses?product_id={id} → breakdown + unallocated (WarehouseService::breakdown/unallocated)
- Evidence — APP endpoint called with product_id at /home/user/sillercenter-syria-cosmatics/lib/features/inventory/controllers/inventory_controller.dart:89-100, but only data['warehouses'] is parsed (:92-95); no model field for breakdown/unallocated in /home/user/sillercenter-syria-cosmatics/lib/features/inventory/domain/models/inventory_models.dart:274-292 | WEB not found | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:211-216 (server already returns it)

**See batches expiring soon (batch number, expiry date, quantity)**  
`inventory.manage OR products.view` · wave 2  
- App — Yes — 'expiring_soon' section, rendered only when batches_enabled
- Web — No — no batch/expiry surface in the vendor panel (admin-only)
- Server — GET /api/v3/seller/seller-center/inventory/batches?days=30 (SellerInventoryController@batches)
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/inventory/widgets/inventory_view.dart:179-186,243-271 + controller :102-112 | WEB not found — grep -rni 'batch' /home/user/Pharmacy/resources/views/vendor-views/ = 0 hits; admin-only /home/user/Pharmacy/routes/admin/routes.php:722-728 | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:230-261

**See already-expired batches still holding quantity**  
`inventory.manage OR products.view` · wave 2  
- App — Yes — 'expired_batches' section in error colour
- Web — No
- Server — GET .../inventory/batches (expired[] array)
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/inventory/widgets/inventory_view.dart:180-182 + controller :106-107 | WEB not found | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:251-259

**Hide the warehouse / batch modules for sellers the marketplace does not run them for**  
`inventory.manage OR products.view` · wave 2  
- App — Yes — warehouses_enabled / batches_enabled from the overview gate both sections (server-driven, not a local flag)
- Web — N/A — the web has no warehouse/batch module to gate
- Server — warehouses_enabled / batches_enabled computed per seller in .../inventory/overview
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/inventory/domain/models/inventory_models.dart:178-179,203-204 + gating at /home/user/sillercenter-syria-cosmatics/lib/features/inventory/widgets/inventory_view.dart:82-87,146,179 | WEB not found | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:85-86,300-310

**Open a dedicated Inventory screen from the main navigation**  
`none` · wave 2  
- App — Yes — More menu → 'inventory' opens InventoryScreen
- Web — No — no inventory entry in the vendor sidebar/menu; only the product-scoped stock-limit and restock lists exist
- Server — n/a (navigation)
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/menu/screens/more_screen.dart:99-101 + /home/user/sillercenter-syria-cosmatics/lib/features/inventory/screens/inventory_screen.dart:10-24 | WEB not found — grep -rn 'inventory' /home/user/Pharmacy/routes/vendor/routes.php = 0 hits; /home/user/Pharmacy/resources/views/vendor-views/marketplace/seller-center.blade.php has no inventory card

**Filter the restock list by category**  
`products.view` · wave 2  
- App — Yes — horizontal category chip row ('all' + each category)
- Web — No UI — the controller reads category_id / sub_category_id but the filter offcanvas offers no category control, so the filter is unreachable from the panel
- Server — POST .../restock-request-list {category_id} / ?category_id=&sub_category_id=
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/restock/screens/restock_list_screen.dart:96-120 (CategoryButtonWidget row) + controller setIndex :174-180, payload :133 | WEB controller supports it — /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:145-147 — but /home/user/Pharmacy/resources/views/vendor-views/product/partials/offcanvas/_restock-list-filter-offcanvas.blade.php:13-45 contains only date inputs | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/ProductController.php:2103

**Filter the restock list by brand (multi-select, each brand showing its request count)**  
`products.view` · wave 2  
- App — Yes — brand checkbox list in the filter sheet, fed by a dedicated brands endpoint that returns product_count per brand
- Web — No UI — controller accepts a single brand_id, but there is no brand control in the offcanvas and no per-brand count anywhere
- Server — GET /api/v3/seller/products/restock-request-brands-list + POST .../restock-request-list {brand_ids:[…]}
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/restock/widgets/product_filter_dialog_widget.dart:164-192 (CategoryFilterItem with count) + controller checkedToggleBrand :206-221, brand_ids payload :122-133 + repo :30-37 | WEB /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:144 reads brand_id but /home/user/Pharmacy/resources/views/vendor-views/product/partials/offcanvas/_restock-list-filter-offcanvas.blade.php has no brand field | API /home/user/Pharmacy/routes/rest_api/v3/seller.php:168 + /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/ProductController.php:2168-2199

**Filter the restock list by request date range**  
`products.view` · wave 2  
- App — Yes — SfDateRangePicker writing restock_start_date / restock_end_date
- Web — Broken — the offcanvas posts from/to but the controller only reads a 'restock_date' string ('m/d/Y - m/d/Y'); the field it parses is never rendered, so the date filter never applies
- Server — POST .../restock-request-list {restock_start_date, restock_end_date} / ?restock_date=
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/restock/widgets/restock_calender_widget.dart:341-364 + controller selectDate :183-188, payload :135-136 | WEB /home/user/Pharmacy/resources/views/vendor-views/product/partials/offcanvas/_restock-list-filter-offcanvas.blade.php:28-41 (inputs named from/to) vs /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:149-160 (parses $request['restock_date']); the JS at /home/user/Pharmacy/resources/views/vendor-views/product/request-restock-list.blade.php:146 binds input[name=restock_date] which does not exist | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/ProductController.php:2107-2108

**Clear all restock filters at once**  
`products.view` · wave 2  
- App — Yes — 'clear_filter' resets dates + brand checkboxes and reloads page 1
- Web — No — the 'Clear_Filter' control is an anchor with href="#" and no handler
- Server — n/a (re-issues the list call with no filters)
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/restock/widgets/product_filter_dialog_widget.dart:206-220 → controller resetChecked /home/user/sillercenter-syria-cosmatics/lib/features/restock/controllers/restock_controller.dart:223-235 | WEB /home/user/Pharmacy/resources/views/vendor-views/product/partials/offcanvas/_restock-list-filter-offcanvas.blade.php:48-50 (dead link)

**Apply a stock change to many products at once (set / increase / decrease, with a note), and review the job's outcome**  
`inventory.manage (stock job); products.view/manage/inventory.manage to read jobs` · wave 2  
- App — Yes — Bulk edit screen with stock modes set/increase/decrease and a note, plus job list and per-row failure receipt
- Web — No — the vendor panel's only bulk tool is products bulk-import (CSV product create/update); there is no bulk-jobs surface
- Server — POST /api/v3/seller/seller-center/bulk-jobs/stock (SellerBulkJobController@storeStockUpdate), GET /bulk-jobs, /bulk-jobs/{id}, /bulk-jobs/{id}/failures
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/bulk_jobs/screens/bulk_edit_screen.dart:214,232-233,263-266 + repo /home/user/sillercenter-syria-cosmatics/lib/features/bulk_jobs/domain/repositories/bulk_job_repository.dart:48-60 + URI /home/user/sillercenter-syria-cosmatics/lib/utill/app_constants.dart:228 | WEB not found — grep 'bulk' /home/user/Pharmacy/routes/vendor/routes.php returns only :172-173 bulk-import | API /home/user/Pharmacy/routes/rest_api/v3/seller.php:670-680

**Delegate stock-only access to a staff member (inventory.manage without product editing rights)**  
`inventory.manage` · wave 2  
- App — Yes — every inventory/stock API route is gated on inventory.manage, so an inventory-only key or staff member works in the app
- Web — No — the vendor staff middleware maps the whole /vendor/products area to products.view/products.manage and has no inventory mapping, so a staff member holding only inventory.manage is refused (403) everywhere stock is edited
- Server — seller_can:inventory.manage middleware on the v3 seller routes; SellerPermissionService catalog exposes the key and the web staff UI can grant it
- Evidence — API /home/user/Pharmacy/routes/rest_api/v3/seller.php:184-189, 656-664, 678 (seller_can:inventory.manage) + catalog /home/user/Pharmacy/app/Services/Marketplace/SellerPermissionService.php:35 | WEB /home/user/Pharmacy/app/Http/Middleware/SellerStaffAccessMiddleware.php:78-108 — match() has no 'inventory' arm; 'products' → products.view/manage, unmapped → DENY; the permission is still offered in /home/user/Pharmacy/resources/views/vendor-views/marketplace/staff.blade.php:38-46

## APP MISSING (4)

**Sort the low-stock list by quantity or by order volume**  
`products.view`  
- App — No — the list is fixed-order, no sort control
- Web — Yes — sortOrderQty select (default, quantity asc/desc, order volume asc/desc)
- Server — vendor.products.stock-limit-list?sortOrderQty=… ; the v3 stock-out-list endpoint accepts no sort parameter
- Evidence — APP no sort UI — /home/user/sillercenter-syria-cosmatics/lib/features/product/widgets/stock_out_product_widget.dart:19-105 and controller getStockOutProductList /home/user/sillercenter-syria-cosmatics/lib/features/product/controllers/product_controller.dart:281-315 send only offset+language | WEB /home/user/Pharmacy/resources/views/vendor-views/product/stock-limit-list.blade.php:26-45 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:756,771-784 | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/ProductController.php:692-708 (no sort support)

**Search the low-stock list by product name**  
`products.view`  
- App — No
- Web — Yes — searchValue on the data-view
- Server — vendor.products.stock-limit-list?searchValue=… ; not supported by /products/stock-out-list
- Evidence — APP no search field in /home/user/sillercenter-syria-cosmatics/lib/features/product/screens/stock_out_product_screen.dart:14-27 | WEB /home/user/Pharmacy/resources/views/vendor-views/product/stock-limit-list.blade.php:21-23 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:757,785 | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/ProductController.php:697-708

**Export the restock-request list to Excel**  
`products.view`  
- App — No
- Web — Yes — Export button carrying the current filters
- Server — GET vendor.products.export-restock (RestockProductListExport); no v3 seller API equivalent
- Evidence — APP no export in /home/user/sillercenter-syria-cosmatics/lib/features/restock/screens/restock_list_screen.dart:54-83 and no export URI in /home/user/sillercenter-syria-cosmatics/lib/utill/app_constants.dart:121-124 | WEB /home/user/Pharmacy/resources/views/vendor-views/product/request-restock-list.blade.php:23-26 + /home/user/Pharmacy/routes/vendor/routes.php:179 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:594-640

**Open the product (view / edit) straight from a restock row**  
`products.view`  
- App — No — the row's only actions are delete and update-quantity; the card is not tappable
- Web — Yes — the product name and an eye icon link to the product view
- Server — n/a (navigation)
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/restock/widgets/restock_list_item_widget.dart:236-419 (no navigation onTap) | WEB /home/user/Pharmacy/resources/views/vendor-views/product/request-restock-list.blade.php:50,75-78

## DEVICE SPECIFIC (1)

**Dismiss the low-stock warning banner**  
`none`  
- App — Yes — 'dont_show_again' writes a SharedPreferences key with NO expiry (permanent, per device)
- Web — Yes — two dismiss actions writing cookie 6valley_stock_limit_status (30 days for 'accepted', 20 minutes for 'reject')
- Server — none — dismissal is stored client-side on both surfaces
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/product/widgets/cookies_widget.dart:88-96 → /home/user/sillercenter-syria-cosmatics/lib/features/product/controllers/product_controller.dart:550-566 → /home/user/sillercenter-syria-cosmatics/lib/features/product/domain/repositories/product_repository.dart:169-176 (sharedPreferences key AppConstants.showCookies, /home/user/sillercenter-syria-cosmatics/lib/utill/app_constants.dart:291) | WEB /home/user/Pharmacy/public/assets/back-end/js/custom.js:1702-1712 | API none

## BACKEND MISSING (2)

**Page through the movement log beyond the first 25 rows**  
`inventory.manage OR products.view`  
- App — No — repository accepts an offset but the controller never passes one, so only page 1 (limit 25) is ever fetched
- Web — No
- Server — GET /api/v3/seller/seller-center/inventory/movements?limit=&offset= (paginated, limit capped at 100)
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/inventory/domain/repositories/inventory_repository.dart:15-21 (offset param, default 1) vs controller /home/user/sillercenter-syria-cosmatics/lib/features/inventory/controllers/inventory_controller.dart:78 (calls getMovements without offset) — no ListView pagination in inventory_view.dart:215-218 | WEB not found | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:118-121,295-298 (server paginates correctly — the gap is client-side only)

**Choose the expiry look-ahead window for the batch view**  
`inventory.manage OR products.view`  
- App — No — getBatches(days: 30) is hard-coded, no picker
- Web — No
- Server — GET .../inventory/batches?days=N (1-365, echoed back as within_days)
- Evidence — APP /home/user/sillercenter-syria-cosmatics/lib/features/inventory/controllers/inventory_controller.dart:102 (days = 30 default, never overridden by inventory_view.dart:87) | WEB not found | API /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:238,241 (server supports the parameter — the gap is client-side)

