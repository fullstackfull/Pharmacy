# Parity — orders (order list, order details, order edit, POS)

[← back to the matrix](../SELLER_WEB_APP_PARITY.md) · 58 capabilities

**40** BOTH · **2** WEB MISSING · **7** APP MISSING · **3** WEB ENHANCEMENT · **3** APP ADAPTATION · **2** DEVICE SPECIFIC · **1** BACKEND MISSING

## Structural facts the implementer must know

```
STRUCTURAL FACTS THE IMPLEMENTER MUST KNOW

1. Two different write shapes for the same order-detail changes. The app funnels order status, payment status, delivery type, delivery man, incentive, expected delivery date and third-party courier through ONE call: POST /api/v3/seller/orders/order-detail-info-update (routes/rest_api/v3/seller.php:212 -> app/Http/Controllers/RestAPI/v3/seller/OrderController.php:598), built from lib/features/order_details/domain/models/order_setup_model.dart:41-53 and submitted by the single "Order setup" bottom sheet (lib/features/order_details/screens/order_details_screen.dart:629; lib/features/order_details/widgets/order_setup_bottom_sheet.dart:222-260). The vendor panel uses granular routes instead (routes/vendor/routes.php:193-199). The older granular API routes still exist (order-detail-status, assign-delivery-man, delivery-charge-date-update, assign-third-party-delivery, update-payment-status, seller.php:205-210) but the current app no longer calls them — do not treat them as the app's contract.

2. BUSINESS STATE STORED CLIENT-SIDE (flagged as requested):
   - Held POS carts. lib/features/pos/domain/repository/cart_repository.dart:112-129 writes the whole list of per-customer held carts to SharedPreferences key 'cart_list' (lib/utill/app_constants.dart:282), written from lib/features/pos/controllers/cart_controller.dart:588-590 and read at 592-599. A held sale is invisible to the web panel, to a second device, and is lost if app data is cleared. The panel keeps the same thing in the HTTP session (app/Services/CartService.php:149-180, SessionKey::CART_NAME), so the two surfaces already cannot see each other's held orders. Neither is a durable per-seller server record — there is no held-cart endpoint in routes/rest_api/v3/seller.php at all.
   - Offline POS sales outbox. lib/features/pos/controllers/offline_sales_controller.dart:22 storage key 'offline_pos_orders' in SharedPreferences holds complete unsent sales (money taken, stock moved) until connectivity returns. It correctly refuses to replay without a session (line 91-101) but has no server-side idempotency (see the BACKEND MISSING row).
   - Legitimate device preference, not business state: the thermal printer MAC address, cart_repository.dart:187-191, key 'bluetooth_mac_address'.

3. Permissions. Everything in this domain is gated by the seller-staff permission middleware: reads need orders.view or orders.manage, writes need orders.manage, and the entire POS group is orders.manage (routes/rest_api/v3/seller.php:195,204,218,317). The web equivalent is derived from the URL segment in app/Http/Middleware/SellerStaffAccessMiddleware.php:88-89 ('orders' -> orders.view/orders.manage, 'pos' -> orders.manage). Any new endpoint must be placed inside the matching middleware group.

4. Earnings/timeline/SLA are already shared logic, not duplicated. Both surfaces read SellerOrderBreakdownService and SellerOrderTimelineService — the app over GET /orders/{id}/breakdown (RestAPI/v3/seller/OrderController.php:164), the panel by calling the services directly (app/Http/Controllers/Vendor/Order/OrderController.php:517-518). Keep it that way; do not re-derive margins in a blade or a widget.

5. The order-edit settlement flow is asymmetric and is the biggest real gap. After an order edit the marketplace produces either a due amount or a return amount. The app can only do the COD switch; the panel can additionally mark the due as paid (routes/vendor/routes.php:202) and record the return with a method and note (routes/vendor/routes.php:200). Both of those write money-movement records and have NO API route in the seller.php orders group (lines 193-224), so adding them to the app means new endpoints, not just new screens.

6. POS parity gap worth fixing first: 'fulfillment'. The backend already implements instant vs delivery on both place-order paths (RestAPI/v3/seller/POSController.php:284-289; Vendor/POS/POSOrderController.php:107-117), including the walk-in-customer guard and the COD/unpaid storage. The app simply never sends the field (lib/features/pos/domain/models/place_order_body.dart:84-104), so a seller taking a delivery sale on the phone silently creates a completed counter sale. This is a one-field client change.

7. Where I could not find something. Order list export: searched lib/features/order, order_details, order_edit, pos and lib/utill/app_constants.dart — no export URI exists for orders in the app (only seller-center report exports at app_constants.dart:176-179, a different domain). POS keyboard shortcuts: searched lib/features/pos for Shortcuts/RawKeyboard/Actions — none. Web camera barcode scan: searched resources/views/vendor-views/pos/** — none.
```

## BOTH (40)

**Browse the seller's own orders as a paginated list**  
`orders.view or orders.manage`  
- App — Yes — lib/features/order/screens/order_screen.dart:70 (My Orders screen); loaded by lib/features/order/controllers/order_controller.dart:71 getOrderList
- Web — Yes — resources/views/vendor-views/order/list.blade.php:51 (order list data-view), rendered by Vendor/Order/OrderController@index
- Server — POST /api/v3/seller/orders/list (routes/rest_api/v3/seller.php:196 -> app/Http/Controllers/RestAPI/v3/seller/OrderController.php:51) | web GET vendor/orders/list/{status} (routes/vendor/routes.php:188 -> app/Http/Controllers/Vendor/Order/OrderController.php:110)
- Evidence — flutter lib/features/order/domain/repositories/order_repository.dart:19-40 posts to AppConstants.orderListUri (lib/utill/app_constants.dart:25); web resources/views/vendor-views/order/list.blade.php:51 + routes/vendor/routes.php:188

**Switch the order list between status tabs (all/pending/confirmed/packaging/out for delivery/delivered/returned/failed/canceled)**  
`orders.view or orders.manage`  
- App — Yes — lib/features/order/screens/order_screen.dart:128-144 OrderTypeButton tabs; lib/features/order/controllers/order_controller.dart:100-136 setIndex maps each tab to a status
- Web — Yes — resources/views/vendor-views/order/list.blade.php:33-46 status tiles linking to vendor.orders.list with the status segment
- Server — Same list endpoints; status carried as order_current_status (API) / {status} segment (web)

**Filter orders by date type + custom range, order source (POS vs website), edit-settlement (has due / has return), multi-select order status, payment status and customer**  
`orders.view or orders.manage`  
- App — Yes — lib/features/order_details/widgets/order_list_filter_bottomsheet_widget.dart:124-540; params built in lib/features/order_details/domain/models/order_list_filter_model.dart:64-98
- Web — Yes — resources/views/vendor-views/order/partials/_filter-offcanvas.blade.php:22-250 (date_type, from/to, order_types[], order_amount_settlement[], order_current_status[], payment_status[], customer_id)
- Server — POST /api/v3/seller/orders/list reads date_type/start_date/end_date/order_types/order_amount_settlement/order_current_status/payment_status/customer_id (app/Http/Controllers/RestAPI/v3/seller/OrderController.php:56-85) | web same filter keys in Vendor/Order/OrderController@index

**Open one order and see its full detail (items, quantities, prices, discounts, tax, shipping, totals)**  
`orders.view or orders.manage`  
- App — Yes — lib/features/order_details/screens/order_details_screen.dart:100-320 with billing summary block from line 236
- Web — Yes — resources/views/vendor-views/order/order-details.blade.php:174-460 item table and totals
- Server — GET /api/v3/seller/orders/{id} (routes/rest_api/v3/seller.php:201 -> RestAPI/v3/seller/OrderController.php:191 details) | web GET vendor/orders/details/{id} (routes/vendor/routes.php:192 -> Vendor/Order/OrderController.php:437 getView)
- Evidence — flutter lib/features/order_details/domain/repositories/order_details_repository.dart:47-55; web resources/views/vendor-views/order/order-details.blade.php:174-460

**Read the customer's order note and the order verification code**  
`orders.view or orders.manage`  
- App — Yes — note at lib/features/order_details/screens/order_details_screen.dart:430-455; verification code at lib/features/order_details/widgets/order_payment_info_widget.dart:41-46
- Web — Yes — note at resources/views/vendor-views/order/order-details.blade.php:57-80 (with 'Read more' modal); verification code at line 160-163
- Server — Both read order_note / verification_code off the order detail payload (RestAPI/v3/seller/OrderController.php:191; Vendor/Order/OrderController.php:437)

**View the delivery verification images uploaded against the order**  
`orders.view or orders.manage`  
- App — Yes — lib/features/order_details/screens/order_details_screen.dart:570-600 'completed_service_picture' gallery with full-screen image dialog
- Web — Yes — resources/views/vendor-views/order/order-details.blade.php:110-111 trigger and modal at 1330-1356
- Server — verification_images returned on the order detail payload by both sides

**See the 'bring change for the customer' reminder on a COD order**  
`orders.view or orders.manage`  
- App — Yes — lib/features/order_details/widgets/change_amount_widget.dart:26-30, mounted at order_details_screen.dart:559
- Web — Yes — resources/views/vendor-views/order/order-details.blade.php:95-99
- Server — bring_cash_amount / bring_change_amount_currency on the order record; no dedicated endpoint

**See what the order is worth to the seller: items total, commission with the rule that charged it, and the ledger lines with 'available on' dates**  
`orders.view or orders.manage`  
- App — Yes — lib/features/order_details/widgets/order_earning_widget.dart:24-120, mounted at order_details_screen.dart:219
- Web — Yes — resources/views/vendor-views/order/partials/_seller-earning.blade.php:9-78, included at order-details.blade.php:607
- Server — GET /api/v3/seller/orders/{id}/breakdown (routes/rest_api/v3/seller.php:200 -> RestAPI/v3/seller/OrderController.php:164) | web calls the same SellerOrderBreakdownService directly (app/Http/Controllers/Vendor/Order/OrderController.php:517)
- Evidence — flutter lib/features/order_details/domain/repositories/order_details_repository.dart:57-65; web resources/views/vendor-views/order/partials/_seller-earning.blade.php:9 + app/Http/Controllers/Vendor/Order/OrderController.php:517

**See the order timeline (every recorded event, who did it, when)**  
`orders.view or orders.manage`  
- App — Yes — lib/features/order_details/widgets/order_timeline_widget.dart:17-50, mounted at order_details_screen.dart:222
- Web — Yes — resources/views/vendor-views/order/partials/_seller-earning.blade.php:80-107
- Server — GET /api/v3/seller/orders/{id}/breakdown returns 'timeline' | web app/Http/Controllers/Vendor/Order/OrderController.php:518 SellerOrderTimelineService

**See the SLA processing countdown / how late the order is**  
`orders.view or orders.manage`  
- App — Yes — lib/features/order_details/widgets/order_sla_countdown_widget.dart:18-64, mounted at order_details_screen.dart:162
- Web — Yes — resources/views/vendor-views/order/partials/_seller-earning.blade.php:83-95
- Server — 'sla' block of GET /api/v3/seller/orders/{id}/breakdown (RestAPI/v3/seller/OrderController.php:164) | web SellerOrderTimelineService (Vendor/Order/OrderController.php:518)

**Change the order status, with the inhouse-shipping restriction (only pending/confirmed/packaging when shipping is inhouse)**  
`orders.manage`  
- App — Yes — status dropdown in lib/features/order_details/widgets/order_setup_bottom_sheet.dart:136-153; allowed values built in lib/features/order_details/domain/repositories/order_details_repository.dart:19-42
- Web — Yes — resources/views/vendor-views/order/order-details.blade.php:876-897, extra statuses gated on shipping_responsibility == 'sellerwise_shipping' at line 885
- Server — POST /api/v3/seller/orders/order-detail-info-update (routes/rest_api/v3/seller.php:212 -> RestAPI/v3/seller/OrderController.php:598 updateOrderDetails); also PUT orders/order-detail-status/{id} (seller.php:205) | web POST vendor/orders/status (routes/vendor/routes.php:199 -> Vendor/Order/OrderController.php:565 updateStatus)

**Change the order payment status (paid / unpaid)**  
`orders.manage`  
- App — Yes — payment status dropdown in lib/features/order_details/widgets/order_setup_bottom_sheet.dart:156-175, submitted through setUpOrder
- Web — Yes — switcher at resources/views/vendor-views/order/order-details.blade.php:903-915, posted to the url at line 1996
- Server — POST /api/v3/seller/orders/order-detail-info-update (RestAPI/v3/seller/OrderController.php:598); dedicated POST orders/update-payment-status also exists (seller.php:210) | web POST vendor/orders/payment-status (routes/vendor/routes.php:194 -> Vendor/Order/OrderController.php:797)
- Evidence — flutter lib/features/order_details/widgets/order_setup_bottom_sheet.dart:156-175 + domain/models/order_setup_model.dart:41; web resources/views/vendor-views/order/order-details.blade.php:903-915

**Choose the delivery type for an order: own delivery man vs third-party service**  
`orders.manage`  
- App — Yes — lib/features/order/widgets/delivery_man_assign_widget.dart:66-92 delivery type dropdown; mapped to 'by_self_delivery_man'/'third_party_delivery' at order_setup_bottom_sheet.dart:307
- Web — Yes — resources/views/vendor-views/order/order-details.blade.php:944-965 choose_delivery_type select
- Server — POST /api/v3/seller/orders/order-detail-info-update ('delivery_type' in lib/features/order_details/domain/models/order_setup_model.dart:52) | web via vendor.orders.add-delivery-man (routes/vendor/routes.php:196) and vendor.orders.update-deliver-info (routes/vendor/routes.php:195)

**Assign a delivery man to an order**  
`orders.manage`  
- App — Yes — lib/features/order/widgets/delivery_man_assign_widget.dart:103-135 delivery man picker (blocked once delivered, line 130)
- Web — Yes — resources/views/vendor-views/order/order-details.blade.php:968-1010 addDeliveryMan select (disabled when delivered, line 975)
- Server — POST /api/v3/seller/orders/order-detail-info-update, plus PUT orders/assign-delivery-man (routes/rest_api/v3/seller.php:206 -> RestAPI/v3/seller/OrderController.php:283) | web GET vendor/orders/add-delivery-man/{order_id}/{d_man_id} (routes/vendor/routes.php:196 -> Vendor/Order/OrderController.php:857)

**Set the delivery man incentive/charge and the expected delivery date**  
`orders.manage`  
- App — Yes — incentive field lib/features/order/widgets/delivery_man_assign_widget.dart:143-152, expected delivery date picker at 157-185
- Web — Yes — incentive input resources/views/vendor-views/order/order-details.blade.php:1016-1034, expected delivery date input at 1038-1045
- Server — POST /api/v3/seller/orders/order-detail-info-update ('deliveryman_charge','expected_delivery_date' in order_setup_model.dart:48-49); PUT orders/delivery-charge-date-update also exists (seller.php:208) | web POST vendor/orders/amount-date-update (routes/vendor/routes.php:197 -> Vendor/Order/OrderController.php:886)

**Assign a third-party courier by service name + tracking id, and view it back on the order**  
`orders.manage`  
- App — Yes — inputs at lib/features/order/widgets/delivery_man_assign_widget.dart:203-235; read-back card lib/features/order_details/widgets/third_party_delivery_info_widget.dart:22-60
- Web — Yes — form at resources/views/vendor-views/order/order-details.blade.php:1915-1925; read-back card at 1048-1060
- Server — POST /api/v3/seller/orders/order-detail-info-update (order_setup_model.dart:50-51); POST orders/assign-third-party-delivery also exists (routes/rest_api/v3/seller.php:209 -> RestAPI/v3/seller/OrderController.php:483) | web POST vendor/orders/update-deliver-info (routes/vendor/routes.php:195 -> Vendor/Order/OrderController.php:835)

**Edit the shipping and billing address on an order (name, phone, city, zip, address)**  
`orders.manage`  
- App — Yes — lib/features/order/screens/edit_address_screen.dart:414-560; entry points lib/features/order_details/widgets/shipping_and_biilling_widget.dart:300-310 (shipping) and 412-422 (billing)
- Web — Yes — shipping modal resources/views/vendor-views/order/order-details.blade.php:1365-1470, billing modal at 1489-1545; triggers at 1080-1082 and 1175
- Server — POST /api/v3/seller/orders/address-update (routes/rest_api/v3/seller.php:211 -> RestAPI/v3/seller/OrderController.php:552) | web POST vendor/orders/address-update (routes/vendor/routes.php:193 -> Vendor/Order/OrderController.php:749)
- Evidence — flutter lib/features/order/domain/repositories/order_repository.dart:47-68 (address_type shipping|billing); web resources/views/vendor-views/order/order-details.blade.php:1376-1379 (address_type hidden field)

**Pick the delivery location on a map when editing an address (place search, autocomplete, reverse geocode to lat/lng)**  
`orders.manage`  
- App — Yes — lib/features/order/screens/select_location_screen.dart:1-322 and lib/features/order/widgets/location_search_dialog_widget.dart
- Web — Partial — map picker only when map_api_status is on: resources/views/vendor-views/order/order-details.blade.php:1456-1470 (#pac-input + hidden latitude/longitude at 1455-1462)
- Server — GET /api/v1/mapapi/geocode-api, /api/v1/mapapi/place-api-autocomplete, /api/v1/mapapi/place-api-details (lib/utill/app_constants.dart:245-247, called from lib/features/order/domain/repositories/location_repository.dart:16-42) | web uses the Google Maps JS key gated by getWebConfig('map_api_status')

**Show the customer's delivery location on a map from the order**  
`orders.view or orders.manage`  
- App — Yes — lib/features/order_details/widgets/shipping_and_biilling_widget.dart:581-601 opens lib/features/order_details/widgets/show_on_map_dialog_widget.dart (OpenStreetMap tiles, line 119-163)
- Web — Yes — 'On Map' button resources/views/vendor-views/order/order-details.blade.php:1154-1157 opening #locationModal
- Server — none — renders lat/lng already on the order address payload

**Contact the customer by phone or email from the order**  
`orders.view or orders.manage`  
- App — Yes — lib/features/order_details/widgets/customer_contact_widget.dart:107 (call) and 119 (email), launched at 137-156 via url_launcher
- Web — Partial — tel:/mailto: links on the order list resources/views/vendor-views/order/list.blade.php:140,143; on the order details page phone and email are plain text (order-details.blade.php:1266-1270)
- Server — none — contact data already on the order payload

**Download / print the order invoice**  
`orders.view or orders.manage`  
- App — Yes — lib/features/order_details/widgets/order_top_section_widget.dart:145-163 triggers getOrderInvoice, saved as PDF at lib/features/order_details/controllers/order_details_controller.dart:283-310
- Web — Yes — 'Print Invoice' resources/views/vendor-views/order/order-details.blade.php:131-132; list row action at list.blade.php:195-196
- Server — GET /api/v3/seller/orders/{id}/invoice (routes/rest_api/v3/seller.php:198 -> RestAPI/v3/seller/OrderController.php:135, SellerInvoiceService) | web GET vendor/orders/generate-invoice/{id} (routes/vendor/routes.php:191 -> Vendor/Order/OrderController.php:418)
- Evidence — flutter lib/features/order_details/domain/repositories/order_details_repository.dart:151-160 (AppConstants.generateInvoice + '/invoice', app_constants.dart:154-155); web routes/vendor/routes.php:191

**Upload the digital product file a customer bought (ready-after-sell delivery)**  
`orders.manage`  
- App — Yes — file picker + upload at lib/features/order_details/widgets/order_product_list_item_widget.dart:245-345; upload call at lib/features/order_details/controllers/order_details_controller.dart:117-132
- Web — Yes — per-item upload modal resources/views/vendor-views/order/order-details.blade.php:265-362 (trigger at 231-237)
- Server — PUT /api/v3/seller/orders/order-wise-product-upload (routes/rest_api/v3/seller.php:207 -> RestAPI/v3/seller/OrderController.php:350) | web POST vendor/orders/digital-file-upload-after-sell (routes/vendor/routes.php:198 -> Vendor/Order/OrderController.php:905)
- Evidence — flutter lib/features/order_details/domain/repositories/order_details_repository.dart:67-105 (AppConstants.digitalProductUploadAfterSell, app_constants.dart:61); web resources/views/vendor-views/order/order-details.blade.php:270

**Switch an edited order to cash-on-delivery so the customer pays the new due amount**  
`orders.manage`  
- App — Yes — AmountDueCard with 'switch_to_cod' at lib/features/order_details/screens/order_details_screen.dart:165-195; call at lib/features/order_edit/controllers/order_edit_controller.dart:250-266
- Web — Yes — 'Switch to COD' button resources/views/vendor-views/order/order-details.blade.php:852-862 opening the modal in partials/modal/order-edit-due-amount-switch-to-cod.blade.php:1
- Server — POST /api/v3/seller/orders/assign-order-in-cod (routes/rest_api/v3/seller.php:221 -> RestAPI/v3/seller/OrderEditController.php:123) | web POST vendor/orders/customer-due-amount (routes/vendor/routes.php:201 -> Vendor/Order/OrderController.php:532)
- Evidence — flutter lib/features/order_edit/domain/repositories/order_edit_repository.dart:81-88 (AppConstants.switchToCod, app_constants.dart:159); web resources/views/vendor-views/order/partials/modal/order-edit-due-amount-switch-to-cod.blade.php:1

**Edit an existing order's products: add, remove, change quantity and variant, revalidate the amount and submit the edit**  
`orders.manage`  
- App — Yes — lib/features/order_edit/screens/edit_product_screen.dart:113-245; cart ops in lib/features/order_edit/controllers/order_edit_controller.dart:121-200 and 268-297 (validation)
- Web — Yes — offcanvas resources/views/vendor-views/order/partials/offcanvas/_edit-products-offcanvas.blade.php and _edit-order-products-list.blade.php; entry buttons order-details.blade.php:118-128
- Server — POST /api/v3/seller/orders/edit-order-validation and /edit-order-submit (routes/rest_api/v3/seller.php:219-220 -> RestAPI/v3/seller/OrderEditController.php:71,89) | web vendor.orders.edit-order-product-add / -remove / -list-update / -generate (routes/vendor/routes.php:207-211 -> Vendor/Order/OrderEditController.php:236,260,283,306)
- Evidence — flutter lib/features/order_edit/domain/repositories/order_edit_repository.dart:28-46; web routes/vendor/routes.php:205-211

**Search the seller's catalogue for a product to add while editing an order**  
`orders.manage / products.view`  
- App — Yes — lib/features/order_edit/widgets/edit_product_search_suggestion.dart driven by lib/features/order_edit/controllers/order_edit_controller.dart:148-180
- Web — Yes — resources/views/vendor-views/order/partials/_search-product.blade.php, served by vendor.orders.search-for-edit-order-product
- Server — GET /api/v3/seller/products/{seller_id}/edit-order-all-products (routes/rest_api/v3/seller.php:697 -> ProductController@editOrderVendorAllProducts) | web GET vendor/orders/search-for-edit-order-product (routes/vendor/routes.php:205 -> Vendor/Order/OrderEditController.php:122)
- Evidence — flutter lib/features/order_edit/domain/repositories/order_edit_repository.dart:15-24; web routes/vendor/routes.php:205

**Gate order editing on the marketplace's 'vendor can edit order' config and on order state (pending/confirmed, not digital-only, offline payment verified)**  
`orders.manage`  
- App — Yes — lib/features/order_details/widgets/order_top_section_widget.dart:109-140 (canVendorEditOrder + status + onlyDigitalProduct + isPaymentVerified checks with distinct warnings)
- Web — Yes — resources/views/vendor-views/order/order-details.blade.php:118-128 renders two variants of the Edit Products button under the same conditions
- Server — config flag surfaced by /api/v1/config (canVendorEditOrder) and getWebConfig on the web side

**See the order edit history / edit log (who edited, when) and the 'bill updated after edits' notice**  
`orders.view or orders.manage`  
- App — Yes — lib/features/order_details/screens/order_details_screen.dart:489-556 edit history list; notice at 253-277
- Web — Yes — 'Edit Log' resources/views/vendor-views/order/order-details.blade.php:583-598; notice at 570-573
- Server — order_edit_history returned on the order detail payload by both sides

**POS: browse the seller's sellable catalogue and add items to a sale**  
`orders.manage`  
- App — Yes — lib/features/pos/screens/pos_product_screen.dart:42-90 and lib/features/pos/widgets/pos_product_list_widget.dart
- Web — Yes — resources/views/vendor-views/pos/index.blade.php:77-92 items grid with physical/digital tabs at 61-75
- Server — GET /api/v3/seller/pos/product-list (routes/rest_api/v3/seller.php:322 -> RestAPI/v3/seller/POSController.php:216) | web GET vendor/pos (routes/vendor/routes.php:117 -> Vendor/POS/POSController.php:82)
- Evidence — flutter lib/utill/app_constants.dart:77 posProductList; web routes/vendor/routes.php:117 + resources/views/vendor-views/pos/index.blade.php:77

**POS: look a product up by name, SKU/code or barcode**  
`orders.manage`  
- App — Yes — search field lib/features/pos/screens/pos_product_screen.dart:56-75; barcode lookup lib/features/pos/controllers/barcode_scan_controller.dart:66-88
- Web — Yes — search box resources/views/vendor-views/pos/index.blade.php:21-27; the POS search matches code, barcode and name (app/Repositories/ProductRepository.php:260-267)
- Server — GET /api/v3/seller/pos/products?code= (routes/rest_api/v3/seller.php:321 -> POSController.php:197 get_product_by_barcode) and /pos/product-list?search= | web GET vendor/pos/search-product (routes/vendor/routes.php:122 -> Vendor/POS/POSController.php:495)
- Evidence — flutter lib/features/pos/domain/repository/cart_repository.dart:59-67 (AppConstants.getProductFromProductCode, app_constants.dart:69); web app/Repositories/ProductRepository.php:260-267 (code/barcode/name)

**POS: filter the catalogue by category**  
`orders.manage`  
- App — Yes — lib/features/pos/widgets/category_filter_botto_sheet_widget.dart:45-120 (multi-select + reset)
- Web — Yes — resources/views/vendor-views/pos/partials/offcanvas/_filter-offcanvas.blade.php:15-19 includes the category filter section
- Server — GET /api/v3/seller/pos/get-categories (routes/rest_api/v3/seller.php:319) and category ids on /pos/product-list | web filter params on vendor.pos.index (Vendor/POS/POSController.php:93 getPosSearchFilterArray)

**POS: pick a variation / digital variant before adding to cart**  
`orders.manage`  
- App — Yes — lib/features/pos/widgets/product_variation_selection_dialog_widget.dart, opened from barcode_scan_controller.dart:73-79 and the product card
- Web — Yes — quick view modal resources/views/vendor-views/pos/partials/_quick-view.blade.php:1-383; price resolved by vendor.pos.get-variant-price
- Server — variant price on POST vendor/pos/get-variant-price (routes/vendor/routes.php:125 -> Vendor/POS/CartController.php:63); app resolves variants client-side from the product payload

**POS: apply a coupon code to the sale**  
`orders.manage`  
- App — Yes — lib/features/pos/widgets/coupon_apply_widget.dart:30-50; call at lib/features/pos/controllers/coupon_discount_controller.dart:38-70
- Web — Yes — resources/views/vendor-views/pos/partials/modals/_add-coupon-discount.blade.php, trigger _cart.blade.php:112
- Server — POST /api/v3/seller/coupon/check-coupon (routes/rest_api/v3/seller.php:266) | web POST vendor/pos/coupon-discount (routes/vendor/routes.php:120 -> Vendor/POS/POSController.php:257)
- Evidence — flutter lib/features/pos/domain/repository/cart_repository.dart:21-34 (AppConstants.getCouponDiscount, app_constants.dart:67); web routes/vendor/routes.php:120

**POS: apply an extra discount as a flat amount or a percentage**  
`orders.manage`  
- App — Yes — lib/features/pos/widgets/extra_discount_and_coupon_dialog_widget.dart:32-95 (type dropdown + amount, with over-discount guard at coupon_discount_controller.dart:74-96)
- Web — Yes — resources/views/vendor-views/pos/partials/modals/_add-discount.blade.php:12-29 (type amount|percent + discount)
- Server — App folds it into the place-order body (extra_discount/extra_discount_type, lib/features/pos/domain/models/place_order_body.dart:92-93) | web POST vendor/pos/update-discount (routes/vendor/routes.php:119 -> Vendor/POS/POSController.php:192)

**POS: compute VAT/tax on the current cart before checkout**  
`orders.manage`  
- App — Yes — lib/features/pos/controllers/cart_controller.dart:838-857 getTaxAmount
- Web — Yes — computed server-side into the cart summary (resources/views/vendor-views/pos/partials/_cart.blade.php:147-150)
- Server — POST /api/v3/seller/pos/get-tax-amount (routes/rest_api/v3/seller.php:329 -> RestAPI/v3/seller/POSCartController.php:46) | web inside Vendor/POS/CartController and POSService summary
- Evidence — flutter lib/features/pos/domain/repository/cart_repository.dart:143-152 (AppConstants.getTaxAmount, app_constants.dart:145); web resources/views/vendor-views/pos/partials/_cart.blade.php:147-150

**POS: attach a customer to the sale by searching existing customers, or sell to a walk-in**  
`orders.manage`  
- App — Yes — lib/features/pos/screens/customer_search_screen.dart:42-121; controller lib/features/pos/controllers/customer_controller.dart:30-86
- Web — Yes — customer dropdown with inline search resources/views/vendor-views/pos/index.blade.php:127-155 (Walk-In-Customer option at 143)
- Server — GET /api/v3/seller/pos/customers?name= (routes/rest_api/v3/seller.php:320 -> RestAPI/v3/seller/POSController.php:172) | web ANY vendor/pos/change-customer (routes/vendor/routes.php:118 -> Vendor/POS/POSController.php:174)
- Evidence — flutter lib/features/pos/domain/repository/cart_repository.dart:69-87 (AppConstants.customerSearchUri, app_constants.dart:70); web routes/vendor/routes.php:118

**POS: create a new customer during checkout (name, email, phone, country, city, zip, address)**  
`orders.manage`  
- App — Yes — lib/features/pos/screens/add_new_customer_screen.dart:52-240
- Web — Yes — resources/views/vendor-views/pos/partials/offcanvas/_add-new-customer-offcanvas.blade.php, trigger index.blade.php:138-140
- Server — POST /api/v3/seller/pos/customer-store (routes/rest_api/v3/seller.php:318 -> RestAPI/v3/seller/POSController.php:124) | web via Vendor/CustomerController (routes/vendor/routes.php:215 customer group)
- Evidence — flutter lib/features/pos/domain/repository/cart_repository.dart:89-99 (AppConstants.addNewCustomer, app_constants.dart:97); web resources/views/vendor-views/pos/index.blade.php:138-140

**POS: choose the payment method — cash, card, or the customer's wallet balance**  
`orders.manage`  
- App — Yes — lib/features/pos/screens/pos_screen.dart:57 (_paymentVia cash/card) with wallet appended at :82 when the add-on is on and a real customer is selected (:333)
- Web — Yes — resources/views/vendor-views/pos/partials/_cart.blade.php:186-201 (cash/card/wallet radios, wallet gated the same way)
- Server — payment_method on POST /api/v3/seller/pos/place-order (place_order_body.dart:95) | web 'type' on POST vendor/pos/order-place (routes/vendor/routes.php:137 -> Vendor/POS/POSOrderController.php:104)

**POS: enter the amount paid and see the change due, with insufficient-balance / under-payment guards**  
`orders.manage`  
- App — Yes — paid amount field lib/features/pos/screens/pos_screen.dart:407-420, change amount at 460, guards at 571-579
- Web — Yes — paid amount and change amount per payment type at resources/views/vendor-views/pos/partials/_cart.blade.php:207-255; messages at index.blade.php:231,243
- Server — paid_amount on both place-order endpoints

**POS: place the sale and produce an order**  
`orders.manage`  
- App — Yes — lib/features/pos/screens/pos_screen.dart:564-585; lib/features/pos/controllers/cart_controller.dart:389-441
- Web — Yes — 'Place Order' resources/views/vendor-views/pos/partials/_cart.blade.php:275-277
- Server — POST /api/v3/seller/pos/place-order (routes/rest_api/v3/seller.php:324 -> RestAPI/v3/seller/POSController.php:269) | web POST vendor/pos/order-place (routes/vendor/routes.php:137 -> Vendor/POS/POSOrderController.php:104)
- Evidence — flutter lib/features/pos/domain/repository/cart_repository.dart:36-47 (AppConstants.placeOrderUri, app_constants.dart:68); web routes/vendor/routes.php:137

**POS: view the receipt/invoice for a completed sale**  
`orders.manage`  
- App — Yes — lib/features/pos/screens/invoice_screen.dart:48-360, data from lib/features/pos/controllers/cart_controller.dart:628-648
- Web — Yes — resources/views/vendor-views/pos/order/invoice.blade.php and pos/order/order-details.blade.php
- Server — GET /api/v3/seller/pos/get-invoice?id= (routes/rest_api/v3/seller.php:325 -> RestAPI/v3/seller/POSController.php:425) | web POST vendor/pos/order-details/{id} (routes/vendor/routes.php:136 -> Vendor/POS/POSOrderController.php:76)
- Evidence — flutter lib/features/pos/domain/repository/cart_repository.dart:133-141 (AppConstants.invoice, app_constants.dart:71); web routes/vendor/routes.php:136

## WEB MISSING (2)

**Download the digital product file that was delivered on an order (both 'ready product' and 'ready after sell' types)**  
`orders.view or orders.manage` · wave 2  
- App — Yes — download button lib/features/order_details/widgets/order_product_list_item_widget.dart:213-240; picks digitalFileAfterSellFullUrl or digitalFileReadyFullUrl at 368-382
- Web — Partial — only the after-sell file, and only from inside the upload modal (resources/views/vendor-views/order/order-details.blade.php:326-328 doc_download_btn). No download for a 'ready_product' digital item anywhere on the page.
- Server — none needed — both sides fetch the stored file URL directly; flutter side lib/features/order_details/controllers/order_details_controller.dart:150-245 productDownload

**POS: take a sale while the device has no connectivity, queue it, and replay it automatically when the network returns (with a pending-count banner and manual retry)**  
`orders.manage` · wave 2  
- App — Yes — lib/features/pos/controllers/offline_sales_controller.dart:41-152 (connectivity watch, enqueue, syncPending, max 10 attempts); banner lib/features/pos/widgets/offline_sales_banner.dart:50-72; entry point cart_controller.dart:389-397,446-479
- Web — No — resources/views/vendor-views/pos/** has no offline queue; a failed submit to vendor.pos.order-place is simply lost
- Server — Replays the ordinary POST /api/v3/seller/pos/place-order (RestAPI/v3/seller/POSController.php:269) via placeOrderRaw
- Evidence — flutter lib/features/pos/controllers/offline_sales_controller.dart:41-152 and lib/features/pos/domain/repository/cart_repository.dart:49-57 placeOrderRaw; web grep for offline/queue/outbox across resources/views/vendor-views/pos and app/Http/Controllers/Vendor/POS returns nothing

## APP MISSING (7)

**Search the order list by order ID**  
`orders.view or orders.manage`  
- App — No — lib/features/order/screens/order_screen.dart:70-98 has only a filter action, no search field; lib/features/order/domain/repositories/order_repository.dart:19-40 never sends search_value
- Web — Yes — resources/views/vendor-views/order/list.blade.php:53 searchPlaceholder 'search_by_Order_ID'
- Server — Supported but unused by the app: POST /api/v3/seller/orders/list reads requestString('search_value') at app/Http/Controllers/RestAPI/v3/seller/OrderController.php:92

**See per-status order counts (current order summary) on the order list**  
`orders.view or orders.manage`  
- App — No — lib/features/order/screens/order_screen.dart:128-144 tabs carry a label only; OrderTypeButton (order_screen.dart:231-266) renders no count
- Web — Yes — resources/views/vendor-views/order/list.blade.php:31-46 stat tiles bound to $allOrdersInfo counts
- Server — Web only: counts assembled in app/Http/Controllers/Vendor/Order/OrderController.php:110 index. The list API returns total_size for the current filter only (RestAPI/v3/seller/OrderController.php:116).

**Filter the order list by delivery man**  
`orders.view or orders.manage`  
- App — No — lib/features/order_details/domain/models/order_list_filter_model.dart:3-14 has no delivery man field
- Web — Partial — carried through as a hidden field when arriving from the delivery man order history: resources/views/vendor-views/order/partials/_filter-offcanvas.blade.php:19-21
- Server — Supported: 'delivery_man_id' filter at app/Http/Controllers/RestAPI/v3/seller/OrderController.php:65 and in Vendor/Order/OrderController@index

**Mark the due amount from an order edit as paid**  
`orders.manage`  
- App — No — the due card only offers 'switch to COD' (lib/features/order_details/screens/order_details_screen.dart:165-195); no mark-as-paid action exists in lib/features/order_details or order_edit
- Web — Yes — resources/views/vendor-views/order/partials/modal/order-edit-due-amount-mark-as-paid.blade.php:1-27
- Server — Web only: POST vendor/orders/customer-due-amount-mark-as-paid (routes/vendor/routes.php:202 -> Vendor/Order/OrderController.php:266). No equivalent route in the orders group of routes/rest_api/v3/seller.php:193-224.
- Evidence — flutter lib/features/order_edit/domain/repositories/order_edit_repository.dart (only editOrderSubmit:28, editOrderValidation:39, switchToCod:81); web resources/views/vendor-views/order/partials/modal/order-edit-due-amount-mark-as-paid.blade.php:1 + routes/vendor/routes.php:202

**Record that the excess amount from an order edit was returned to the customer (payment method + note, or to wallet)**  
`orders.manage`  
- App — No — the 'need to return' card is display only, explicitly built with showButton: false at lib/features/order_details/screens/order_details_screen.dart:194-203
- Web — Yes — resources/views/vendor-views/order/partials/modal/order-edit-return-amount-modal.blade.php:1-53 (method select + payment note)
- Server — Web only: POST vendor/orders/customer-return-amount (routes/vendor/routes.php:200 -> Vendor/Order/OrderController.php:207 orderReturnAmountToCustomer). No equivalent in routes/rest_api/v3/seller.php.

**POS: filter the catalogue by brand and change the sort order**  
`orders.manage`  
- App — No — only category ids are sent (lib/features/pos/screens/pos_product_screen.dart:44-46, posSelectedCategoryIds)
- Web — Yes — resources/views/vendor-views/pos/partials/offcanvas/_filter-offcanvas.blade.php:16 requests filterSection ['sorting','brand','category']
- Server — Supported server-side (brand_id / category_id / sorting in app/Repositories/ProductRepository.php:281-286); the app just never sends brand or sort

**POS: choose the sale's fulfilment — instant counter sale vs home delivery that enters the normal order lifecycle (and is stored as COD when paid in cash)**  
`orders.manage`  
- App — No — lib/features/pos/domain/models/place_order_body.dart:84-104 toJson has no 'fulfillment' key, so every app sale is created as an instant sale
- Web — Yes — resources/views/vendor-views/pos/partials/_cart.blade.php:165-180 instant/delivery radios plus the COD explanation note
- Server — Fully supported on both endpoints: app/Http/Controllers/RestAPI/v3/seller/POSController.php:284-289 and app/Http/Controllers/Vendor/POS/POSOrderController.php:107-117

## WEB ENHANCEMENT (3)

**Export the filtered order list to Excel**  
`orders.view or orders.manage`  
- App — No — no export call anywhere under lib/features/order, order_details, order_edit, pos (grep for 'export' returns nothing in these paths)
- Web — Yes — resources/views/vendor-views/order/list.blade.php:57-67 export link
- Server — GET vendor/orders/export-excel/{status} (routes/vendor/routes.php:190 -> app/Http/Controllers/Vendor/Order/OrderController.php:310 exportList). No seller-app equivalent under routes/rest_api/v3/seller.php orders group.
- Evidence — web resources/views/vendor-views/order/list.blade.php:57 + routes/vendor/routes.php:190; flutter not found — searched lib/features/order*, lib/features/pos and lib/utill/app_constants.dart (no order export URI)

**Jump to the previous / next order from the order details page**  
`orders.view or orders.manage`  
- App — No — lib/features/order_details/widgets/order_top_section_widget.dart:90-103 only offers back navigation
- Web — Yes — resources/views/vendor-views/order/order-details.blade.php:21-27 previous/next links built from $previousOrder/$nextOrder
- Server — Web only: linked orders resolved in app/Http/Controllers/Vendor/Order/OrderController.php:437 getView

**POS: keyboard shortcuts for the till (order, submit, clear cart, add customer, print, search…)**  
`orders.manage`  
- App — No — no keyboard shortcut handling in lib/features/pos/screens/pos_screen.dart or lib/features/pos/widgets/**
- Web — Yes — resources/views/vendor-views/pos/partials/modals/_short-cut-keys.blade.php:11-24
- Server — none

## APP ADAPTATION (3)

**POS: change item quantity, remove a line, and clear the whole cart**  
`orders.manage`  
- App — Yes — lib/features/pos/controllers/cart_controller.dart:225-271 setQuantity, 273-296 removeFromCart, 307-322 removeAllCartList; clear button pos_screen.dart:497-515
- Web — Yes — qty input and remove at resources/views/vendor-views/pos/partials/_cart.blade.php:58,74; 'Clear Cart' at 268
- Server — App keeps the cart client-side and posts the whole cart on checkout | web POST vendor/pos/quantity-update, cart-remove, cart-empty (routes/vendor/routes.php:126,130,131 -> Vendor/POS/CartController.php:79,246,299)

**POS: hold the current sale, list held sales, search them by customer name, and resume one**  
`orders.manage`  
- App — Yes — hold at lib/features/pos/screens/pos_screen.dart:524-548; list lib/features/pos/screens/hold_order_page.dart; search lib/features/pos/widgets/hold_order_search_bar_widget.dart:64; resume lib/features/pos/widgets/hold_order_item_widget.dart:126-131
- Web — Yes — 'View All Hold Orders' resources/views/vendor-views/pos/index.blade.php:107-110; list resources/views/vendor-views/pos/partials/_view-hold-orders.blade.php; customer search handled in POSOrderController@getAllHoldOrdersView
- Server — App: none — held carts live only on the device (see note) | web ANY vendor/pos/view-hold-orders (routes/vendor/routes.php:139 -> Vendor/POS/POSOrderController.php:309), change-cart / new-cart-id (routes/vendor/routes.php:132,133)
- Evidence — flutter lib/features/pos/domain/repository/cart_repository.dart:112-129 persists held carts to SharedPreferences key 'cart_list' (lib/utill/app_constants.dart:282), written from lib/features/pos/controllers/cart_controller.dart:588-590; web app/Http/Controllers/Vendor/POS/POSOrderController.php:309-325 reads them from the HTTP session

**POS: start a fresh cart for another customer / cancel the current cart**  
`orders.manage`  
- App — Partial — 'Clear' resets the current cart (lib/features/pos/screens/pos_screen.dart:497-515) and holding a cart implicitly starts a new one (cart_controller.dart:496-541)
- Web — Yes — new cart, clear cart ids and cancel order: resources/views/vendor-views/pos/index.blade.php:191-192,198 plus partials/modals/_clear-cart-modal.blade.php
- Server — Web only: GET vendor/pos/new-cart-id, GET vendor/pos/clear-cart-ids, ANY vendor/pos/cancel-order (routes/vendor/routes.php:133,128,138 -> Vendor/POS/CartController.php:329,275 and POSOrderController.php:291). The app has no server cart to reset.
- Evidence — flutter lib/features/pos/screens/pos_screen.dart:497-515 and controllers/cart_controller.dart:496-541; web routes/vendor/routes.php:128,133,138

## DEVICE SPECIFIC (2)

**POS: scan a barcode with the device camera to add an item**  
`orders.manage`  
- App — Yes — lib/features/pos/controllers/barcode_scan_controller.dart:35-63 (BarcodeScanner.scan with flash/autofocus options)
- Web — No — no camera capture in resources/views/vendor-views/pos/**; the panel relies on typing/USB-scanner input into the search box (index.blade.php:21)
- Server — Same lookup endpoint on both sides: GET /api/v3/seller/pos/products?code=
- Evidence — flutter lib/features/pos/controllers/barcode_scan_controller.dart:35-63; web resources/views/vendor-views/pos/index.blade.php:21 — the business capability (barcode lookup) is present on both, only the camera capture is app-only

**POS: print the receipt on a Bluetooth thermal printer (pair, connect, remember the printer)**  
`orders.manage`  
- App — Yes — lib/features/pos/screens/invoice_print_screen.dart:51-110 (pairing, connect, writeBytes) and 141-210 (device list)
- Web — No thermal path — resources/views/vendor-views/pos/partials/modals/_print-invoice.blade.php:6-19 hands off to the browser print dialog
- Server — none — printing is entirely client side; the printer MAC is a device preference (lib/features/pos/domain/repository/cart_repository.dart:187-191, key 'bluetooth_mac_address')

## BACKEND MISSING (1)

**Server-side duplicate protection for a replayed POS sale (idempotency key on place-order)**  
`orders.manage`  
- App — Partial — the outbox retries up to 10 times per queued sale with no client-generated request id (lib/features/pos/controllers/offline_sales_controller.dart:113-146)
- Web — n/a — the panel never replays
- Server — Missing: neither app/Http/Controllers/RestAPI/v3/seller/POSController.php:269-425 place_order nor app/Http/Controllers/Vendor/POS/POSOrderController.php:104 accepts or checks an idempotency key, so a retry after a response that was lost in transit creates a second order

