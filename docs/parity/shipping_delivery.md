# Parity — shipping_delivery

[← back to the matrix](../SELLER_WEB_APP_PARITY.md) · 56 capabilities

**42** BOTH · **4** WEB MISSING · **9** APP MISSING · **1** APP ADAPTATION

## Structural facts the implementer must know

```
STRUCTURAL / IMPLEMENTATION NOTES

1. Entry points differ. Flutter reaches shipping config two ways: More → Shipping Method (lib/features/profile/widgets/theme_changer_widget.dart:55-64, which branches on the cached selectedShippingType) and Settings → Shipping Setting (lib/features/settings/screens/setting_screen.dart:35-39). Delivery-man work is behind More → Deliveryman → DeliveryManSetupScreen (lib/features/menu/screens/more_screen.dart:130-131, lib/features/delivery_man/screens/delivery_man_setup_screen.dart:22-25 = list / add / withdraws / emergency contacts). The web equivalents are four separate sidebar entries (resources/views/layouts/vendor/partials/v2/_side-bar.blade.php:393,460,464,468,472). lib/features/shipping/screens/shipping_main_screen.dart is dead code — nothing navigates to it.

2. FLUTTER BUG — the shipping-type dropdown shown on every shipping screen does not persist anything. DropDownForShippingTypeWidget → ShippingController.setShippingTypeIndex (lib/features/shipping/controllers/shipping_controller.dart:250-263) only pushes a different screen; it never calls setShippingMethodType. Only the Settings dialog (choose_shipping_dialog_widget.dart:115-129) actually hits the API. The two code paths also disagree on labels/order: the dropdown list is ['category_wise','order_wise','product_type'] (shipping_controller.dart:237, note 'product_type', not the server's 'product_wise'), while the dialog/splash list is ['order_wise','product_wise','category_wise'] (lib/features/splash/domain/repositories/splash_repository.dart:57-61). Any web-side work here should treat the server value set as order_wise | product_wise | category_wise (app/Http/Controllers/RestAPI/v3/seller/shippingController.php:25 defaults to 'order_wise').

3. CLIENT-SIDE BUSINESS STATE — the selected shipping type is mirrored into SharedPreferences under key 'shipping_type' (lib/features/splash/domain/repositories/splash_repository.dart:48-51, key at lib/utill/app_constants.dart:278). That write is currently unreachable: its only caller is SplashController.setShippingType (lib/features/splash/controllers/splash_controller.dart:167-170), which nothing invokes, and nothing ever reads the key back. So it is dead code rather than a live divergence — but it should be deleted, not wired up, because shipping type is server state (ShippingType table, keyed by seller_id). The shipping type list itself is also hard-coded on the device (splash_repository.dart:54-67) instead of fetched. No other business state in this domain is stored locally; everything else goes through the API.

4. PERMISSIONS ARE ASYMMETRIC. The API gates shipping config with seller_can:shop_settings.manage (routes/rest_api/v3/seller.php:275,288) and all delivery-man work with seller_can:orders.manage (routes/rest_api/v3/seller.php:337). The vendor web panel has no equivalent granular gate — the whole panel sits inside one ['seller','seller_staff_access'] group (routes/vendor/routes.php:84). Any new vendor-panel screen built for this domain will be reachable by every staff member unless a matching gate is added.

5. BACKEND ISSUE — GET /api/v3/seller/delivery-man/order-status-history/{id} is not scoped to the caller. app/Http/Controllers/RestAPI/v3/seller/DeliveryManController.php:233-240 queries OrderStatusHistory by order_id with no seller check, so any authenticated seller can read any order's status history. The vendor-web counterpart (app/Http/Controllers/Vendor/DeliveryMan/DeliveryManWalletController.php:98-102) has the same gap.

6. WEB DEFECT to fix alongside the parity work — app/Http/Controllers/Vendor/DeliveryMan/DeliveryManWalletController.php:72-78 assigns $deliveryMan then tests isset($delivery_man->wallet) (undefined variable), so $withdrawAbleBalance is always null and the 'withdrawable_balance' card on the delivery man overview always renders 0 (resources/views/vendor-views/delivery-man/wallet/index.blade.php:92). The correct call already exists on the earning page at line 123 of the same controller.

7. WEB DEAD UI — resources/views/vendor-views/delivery-man/wallet/index.blade.php:174-207 contains a 'cash_Withdraw' modal whose only button re-opens itself and whose form has no action. Real cash collection happens on the separate cash-collect page. Remove it rather than reuse it.

8. Pagination asymmetry (not a capability gap but affects any shared UX spec): the Flutter delivery man list and withdraw list always request page 1 with limit 10 and have no paginator (lib/features/delivery_man/screens/delivery_man_list_screen.dart:26, lib/features/delivery_man/screens/withdraw/withdraw_screen.dart:25, withdraw_list.dart:28-36), whereas both web tables paginate. The Flutter shipping-method list is unpaginated on both ends (the API returns the full set, app/Http/Controllers/RestAPI/v3/seller/ShippingMethodController.php:43-49).

9. Nothing in this domain is device-specific except image capture. The delivery man profile/identity images use the gallery picker only (lib/features/delivery_man/controllers/delivery_man_controller.dart:108-136, ImageSource.gallery), which the web already matches with file upload inputs — so there is no camera-only capability to adapt here.

10. Endpoints defined but never called by the app: GET /api/v3/seller/shipping-method/edit/{id} (routes/rest_api/v3/seller.php:292) — the app edits from its in-memory model. ShippingRepository.getShipping() (lib/features/shipping/domain/repositories/shipping_repository.dart:13-21) points at shopUri and is unused. Neither needs web work.
```

## BOTH (42)

**Choose the shop's shipping type (order-wise / product-wise / category-wise)**  
`shop_settings.manage (API); web only gated by 'seller'+'seller_staff_access'`  
- App — Yes — lib/features/settings/widgets/choose_shipping_dialog_widget.dart (Settings → Shipping Setting dialog, Update button)
- Web — Yes — resources/views/vendor-views/shipping-method/index.blade.php shipping-type <select>
- Server — API GET /api/v3/seller/shipping/selected-shipping-method (shippingController::selected_shipping_type); WEB POST vendor.business-settings.shipping-type.index (ShippingTypeController::addOrUpdate)
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/settings/widgets/choose_shipping_dialog_widget.dart:115-129 + lib/features/shipping/controllers/shipping_controller.dart:189-199 + lib/features/shipping/domain/repositories/shipping_repository.dart:68-75; web: /home/user/Pharmacy/resources/views/vendor-views/shipping-method/index.blade.php:61-69,312 + /home/user/Pharmacy/routes/vendor/routes.php:370 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Shipping/ShippingTypeController.php:43-61; api route /home/user/Pharmacy/routes/rest_api/v3/seller.php:278

**Read back the shop's currently-selected shipping type**  
`shop_settings.manage`  
- App — Yes — ShippingController.getSelectedShippingMethodType()
- Web — Yes — ShippingMethodController passes $shippingType into the blade and preselects the option
- Server — GET /api/v3/seller/shipping/get-shipping-method (shippingController::get_shipping_type)
- Evidence — flutter: lib/features/shipping/controllers/shipping_controller.dart:162-182 + lib/features/shipping/domain/repositories/shipping_repository.dart:57-65; web: /home/user/Pharmacy/app/Http/Controllers/Vendor/Shipping/ShippingMethodController.php:86 + resources/views/vendor-views/shipping-method/index.blade.php:63-68,313; api: /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/shippingController.php:18-31

**Add an order-wise shipping method (title, duration, cost)**  
`shop_settings.manage`  
- App — Yes — lib/features/settings/screens/order_wise_shipping_add_screen.dart (dialog, Save)
- Web — Yes — 'add_order_wise_shipping' form on the shipping-method index page
- Server — POST /api/v3/seller/shipping-method/add (ShippingMethodController::store); WEB POST vendor.business-settings.shipping-method.index (Vendor\Shipping\ShippingMethodController::add)
- Evidence — flutter: lib/features/settings/screens/order_wise_shipping_add_screen.dart:137-169 + lib/features/shipping/domain/repositories/shipping_repository.dart:111-120 (AppConstants.addShippingUri, lib/utill/app_constants.dart:43); web: resources/views/vendor-views/shipping-method/index.blade.php:93-130 + /home/user/Pharmacy/routes/vendor/routes.php:361 + app/Http/Controllers/Vendor/Shipping/ShippingMethodController.php:92

**Edit an existing order-wise shipping method**  
`shop_settings.manage`  
- App — Yes — edit pencil on the shipping card reopens OrderWiseShippingAddScreen prefilled
- Web — Yes — resources/views/vendor-views/shipping-method/update-view.blade.php
- Server — POST /api/v3/seller/shipping-method/update/{id} with _method=put (ShippingMethodController::update); WEB vendor.business-settings.shipping-method.update
- Evidence — flutter: lib/features/settings/widgets/order_wise_shipping_card_widget.dart:93-107 + lib/features/shipping/domain/repositories/shipping_repository.dart:35-44; web: /home/user/Pharmacy/routes/vendor/routes.php:362-363 + app/Http/Controllers/Vendor/Shipping/ShippingMethodController.php:119-143 + resources/views/vendor-views/shipping-method/update-view.blade.php

**Enable / disable a shipping method**  
`shop_settings.manage`  
- App — Yes — FlutterSwitch on each order-wise shipping card
- Web — Yes — switcher in the shipping method table with confirm modal
- Server — PUT /api/v3/seller/shipping-method/status (ShippingMethodController::status_update); WEB vendor.business-settings.shipping-method.update-status
- Evidence — flutter: lib/features/settings/widgets/order_wise_shipping_card_widget.dart:37-43 + lib/features/shipping/controllers/shipping_controller.dart:265-278; web: resources/views/vendor-views/shipping-method/index.blade.php:168-185 + /home/user/Pharmacy/routes/vendor/routes.php:364; api: app/Http/Controllers/RestAPI/v3/seller/ShippingMethodController.php:51-68

**Delete a shipping method**  
`shop_settings.manage`  
- App — Yes — delete icon with confirmation dialog on the shipping card
- Web — Yes — trash button (delete-data-without-form)
- Server — DELETE /api/v3/seller/shipping-method/delete/{id} (ShippingMethodController::delete); WEB POST vendor.business-settings.shipping-method.delete
- Evidence — flutter: lib/features/settings/widgets/order_wise_shipping_card_widget.dart:72-91 + lib/features/shipping/controllers/shipping_controller.dart:102-115; web: resources/views/vendor-views/shipping-method/index.blade.php:194-198 + /home/user/Pharmacy/routes/vendor/routes.php:365 + app/Http/Controllers/Vendor/Shipping/ShippingMethodController.php:150

**List the shop's order-wise shipping methods**  
`shop_settings.manage`  
- App — Yes — OrderWiseShippingScreen list (single unpaginated call)
- Web — Yes — paginated table on the shipping-method index page
- Server — GET /api/v3/seller/shipping-method/list (ShippingMethodController::list — returns the whole set, no paging)
- Evidence — flutter: lib/features/settings/screens/order_wise_shipping_list_screen.dart:61-82 + lib/features/shipping/controllers/shipping_controller.dart:36-46; web: resources/views/vendor-views/shipping-method/index.blade.php:142-215 + app/Http/Controllers/Vendor/Shipping/ShippingMethodController.php:73-86; api: app/Http/Controllers/RestAPI/v3/seller/ShippingMethodController.php:43-49

**Set the shipping cost for each product category (category-wise shipping)**  
`shop_settings.manage`  
- App — Yes — CategoryWiseShippingScreen with a cost field per category and a Save/Update button
- Web — Yes — 'category_wise_shipping_cost' table with cost[] inputs and Save
- Server — GET /api/v3/seller/shipping/all-category-cost + POST /api/v3/seller/shipping/set-category-cost (shippingController); WEB POST vendor.business-settings.category-wise-shipping-cost.index
- Evidence — flutter: lib/features/shipping/screens/category_wise_shipping_screen.dart:63-80 + lib/features/shipping/widgets/category_wise_shipping_card_widget.dart:70-78 + lib/features/shipping/domain/repositories/shipping_repository.dart:47-55,77-94; web: resources/views/vendor-views/shipping-method/index.blade.php:251-306 + /home/user/Pharmacy/routes/vendor/routes.php:374 + app/Http/Controllers/Vendor/Shipping/CategoryShippingCostController.php:42-58; api: app/Http/Controllers/RestAPI/v3/seller/shippingController.php:57-107

**Toggle 'multiply shipping cost with quantity' per category**  
`shop_settings.manage`  
- App — Yes — FlutterSwitch on each category shipping card
- Web — Yes — switcher in the multiply_with_QTY column
- Server — POST /api/v3/seller/shipping/set-category-cost (multiply_qty[]); WEB multiplyQTY[] on category-wise-shipping-cost.index
- Evidence — flutter: lib/features/shipping/widgets/category_wise_shipping_card_widget.dart:100-105 + lib/features/shipping/controllers/shipping_controller.dart:121-130; web: resources/views/vendor-views/shipping-method/index.blade.php:279-283 + app/Http/Controllers/Vendor/Shipping/CategoryShippingCostController.php:42-55

**Set a per-product shipping cost and its multiply-with-quantity flag (the data behind product-wise shipping)**  
`products.manage`  
- App — Yes — shipping cost field + 'shipping_cost_multiply' switch on the add/edit product pricing step
- Web — Yes — shipping_cost input + multiply_qty switcher in the product pricing partial
- Server — POST /api/v3/seller/products/add and PUT products/update/{id} (shipping_cost, multiply_qty); WEB vendor.products.* store/update
- Evidence — flutter: lib/features/addProduct/screens/add_product_next_screen.dart:490-503,508-531 + lib/features/addProduct/domain/repository/add_product_repository.dart:241-242; web: resources/views/vendor-views/product/add/_pricing-others.blade.php:141-180 and resources/views/vendor-views/product/update/_pricing-others.blade.php:140-180

**See the product-wise shipping explanatory note when that mode is selected**  
`shop_settings.manage`  
- App — Yes — ProductWiseShippingWidget shows the illustration + note
- Web — Yes — #product_wise_note block on the shipping-method index
- Server — none
- Evidence — flutter: lib/features/shipping/widgets/product_wise_shipping_widget.dart:33-47; web: resources/views/vendor-views/shipping-method/index.blade.php:71-78

**List the shop's delivery men with rating and delivered-order counts**  
`orders.manage`  
- App — Yes — DeliveryManListScreen / DeliveryManListViewWidget
- Web — Yes — resources/views/vendor-views/delivery-man/list.blade.php
- Server — GET /api/v3/seller/delivery-man/list (DeliveryManController::list); WEB vendor.delivery-man.list (Vendor\DeliveryMan\DeliveryManController::getListView)
- Evidence — flutter: lib/features/delivery_man/screens/delivery_man_list_screen.dart:26,57-62 + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:74-82; web: resources/views/vendor-views/delivery-man/list.blade.php:58-152 + /home/user/Pharmacy/routes/vendor/routes.php:278 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManController.php:86-101; api: routes/rest_api/v3/seller.php:339

**Search delivery men by name**  
`orders.manage`  
- App — Yes — debounced search field on DeliveryManListScreen
- Web — Yes — data-view search box ('search_by_name, contact_info')
- Server — GET /api/v3/seller/delivery-man/list?search= ; WEB vendor.delivery-man.list?search=
- Evidence — flutter: lib/features/delivery_man/screens/delivery_man_list_screen.dart:44-55 + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:74-82; web: resources/views/vendor-views/delivery-man/list.blade.php:17-19 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManController.php:88-98; api: app/Http/Controllers/RestAPI/v3/seller/DeliveryManController.php:38-43

**Add a delivery man (name, email, phone, address, identity type/number, profile image, identity images, password)**  
`orders.manage`  
- App — Yes — AddNewDeliveryManScreen two-tab wizard (delivery_man_info / account_info)
- Web — Yes — resources/views/vendor-views/delivery-man/index.blade.php
- Server — POST /api/v3/seller/delivery-man/store (DeliveryManController::store); WEB POST vendor.delivery-man.index (Vendor DeliveryManController::add)
- Evidence — flutter: lib/features/delivery_man/screens/add_new_delivery_man_screen.dart:196-250 + lib/features/delivery_man/widgets/delivery_man_info_widget.dart:118-300 + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:173-237; web: resources/views/vendor-views/delivery-man/index.blade.php:13-263 + /home/user/Pharmacy/routes/vendor/routes.php:277 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManController.php:67-79

**Update an existing delivery man's profile and documents**  
`orders.manage`  
- App — Yes — swipe 'edit' on the delivery man card opens the same wizard prefilled
- Web — Yes — resources/views/vendor-views/delivery-man/update-view.blade.php
- Server — POST /api/v3/seller/delivery-man/update/{id} with _method=put (DeliveryManController::update); WEB vendor.delivery-man.update
- Evidence — flutter: lib/features/delivery_man/widgets/delivery_man_card_widget.dart:46-54,71-79 + lib/features/delivery_man/screens/add_new_delivery_man_screen.dart:32-51,228-248; web: resources/views/vendor-views/delivery-man/update-view.blade.php:26-307 + /home/user/Pharmacy/routes/vendor/routes.php:280-281 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManController.php:121-140

**Reset / change a delivery man's login password**  
`orders.manage`  
- App — Yes — password + confirm password on the account_info tab
- Web — Yes — password + confirm password on the update form
- Server — delivery-man/store and delivery-man/update/{id} (password, confirm_password); WEB same controller actions
- Evidence — flutter: lib/features/delivery_man/widgets/delivery_man_info_widget.dart:367,396 + lib/features/delivery_man/screens/add_new_delivery_man_screen.dart:211-222; web: resources/views/vendor-views/delivery-man/update-view.blade.php:254-284 + app/Http/Controllers/RestAPI/v3/seller/DeliveryManController.php:101-105,147

**Activate / deactivate a delivery man account**  
`orders.manage`  
- App — Yes — switch in the app bar of the delivery man details screen
- Web — Yes — status switcher in the delivery man list row (with confirm modal)
- Server — POST /api/v3/seller/delivery-man/status-update (DeliveryManController::status); WEB POST vendor.delivery-man.update-status
- Evidence — flutter: lib/features/delivery_man/screens/delivery_man_details_screen.dart:63-70 + lib/features/delivery_man/controllers/delivery_man_controller.dart:285-296 + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:125-137; web: resources/views/vendor-views/delivery-man/list.blade.php:105-124 + /home/user/Pharmacy/routes/vendor/routes.php:282 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManController.php:148-156

**Delete a delivery man**  
`orders.manage`  
- App — Yes — swipe 'delete' action on the delivery man card
- Web — Yes — trash button on the delivery man list row
- Server — GET /api/v3/seller/delivery-man/delete/{id} (DeliveryManController::delete); WEB DELETE vendor.delivery-man.delete
- Evidence — flutter: lib/features/delivery_man/widgets/delivery_man_card_widget.dart:36-44,61-69 + lib/features/delivery_man/controllers/delivery_man_controller.dart:315-322; web: resources/views/vendor-views/delivery-man/list.blade.php:138-146 + /home/user/Pharmacy/routes/vendor/routes.php:283 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManController.php:163-176

**View a delivery man's profile overview (phone, email, address, bank details)**  
`orders.manage`  
- App — Yes — Overview tab of DeliveryManDetailsScreen
- Web — Yes — delivery man wallet 'overview' page (delivery_man_account + bank_info cards)
- Server — GET /api/v3/seller/delivery-man/details/{id} (DeliveryManController::details); WEB vendor.delivery-man.wallet.index
- Evidence — flutter: lib/features/delivery_man/screens/delivery_man_overview_screen.dart:74-111 + lib/features/delivery_man/controllers/delivery_man_controller.dart:242-250; web: resources/views/vendor-views/delivery-man/wallet/index.blade.php:108-171 + /home/user/Pharmacy/routes/vendor/routes.php:289 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManWalletController.php:70-79

**See a delivery man's wallet balances (cash in hand, current balance, pending withdraw, total withdrawn)**  
`orders.manage`  
- App — Yes — balance cards on the Overview tab plus the cash-in-hand hero card
- Web — Yes — four stat cards on the delivery man wallet overview
- Server — GET /api/v3/seller/delivery-man/details/{id}; WEB vendor.delivery-man.wallet.index
- Evidence — flutter: lib/features/delivery_man/screens/delivery_man_overview_screen.dart:42-56 + lib/features/delivery_man/widgets/delivery_man_withdraw_balance_widget.dart:64-74; web: resources/views/vendor-views/delivery-man/wallet/index.blade.php:38,52,65,78

**Collect cash-in-hand from a delivery man**  
`orders.manage`  
- App — Yes — 'collect_cash' bottom sheet with amount field and validation against cash in hand
- Web — Yes — cash-collect page with amount field ('receive')
- Server — POST /api/v3/seller/delivery-man/cash-receive (DeliveryManCashCollectController::cash_receive); WEB POST vendor.delivery-man.wallet.cash-collect (DeliveryManWalletController::collectCash)
- Evidence — flutter: lib/features/delivery_man/screens/withdraw/delivery_man_collect_cash_widget.dart:152-161 + lib/features/delivery_man/controllers/delivery_man_controller.dart:298-312 + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:139-151; web: resources/views/vendor-views/delivery-man/wallet/cash-collect.blade.php:17-49 + /home/user/Pharmacy/routes/vendor/routes.php:293-294 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManWalletController.php:153-186

**View the history of cash already collected from a delivery man**  
`orders.manage`  
- App — Yes — 'collected_cash' tab with paginated transaction cards
- Web — Yes — 'collected_cash' table under the cash-collect form
- Server — GET /api/v3/seller/delivery-man/collect-cash-list/{id} (DeliveryManCashCollectController::list); WEB vendor.delivery-man.wallet.cash-collect view
- Evidence — flutter: lib/features/delivery_man/screens/collect_cash_from_delivery_man_screen.dart:28-61 + lib/features/delivery_man/controllers/delivery_man_controller.dart:457-474 + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:239-247; web: resources/views/vendor-views/delivery-man/wallet/cash-collect.blade.php:54-95 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManWalletController.php:132-145

**View the orders a delivery man has handled**  
`orders.manage`  
- App — Yes — 'order_history' tab with paginated order cards, tap opens order details
- Web — Yes — delivery man wallet 'order_History_Log' page
- Server — GET /api/v3/seller/delivery-man/order-list/{id} (DeliveryManController::order_list); WEB vendor.delivery-man.wallet.order-history
- Evidence — flutter: lib/features/delivery_man/screens/delivery_man_order_history_screen.dart:27-53 + lib/features/delivery_man/widgets/delivery_man_order_history_widget.dart:48-49 + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:94-102; web: resources/views/vendor-views/delivery-man/wallet/order-history.blade.php:18-72 + /home/user/Pharmacy/routes/vendor/routes.php:290 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManWalletController.php:86-92

**View the status-change log of an order a delivery man handled**  
`orders.manage`  
- App — Yes — history icon on each order-history row opens OrderChangeLogWidget
- Web — Yes — history button in the order-history table opens the _order-status-history partial
- Server — GET /api/v3/seller/delivery-man/order-status-history/{orderId} (DeliveryManController::order_status_history); WEB vendor.delivery-man.wallet.order-status-history
- Evidence — flutter: lib/features/delivery_man/widgets/delivery_man_order_history_widget.dart:67-70 + lib/features/delivery_man/widgets/order_change_log_widget.dart:17,44-81 + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:163-171; web: resources/views/vendor-views/delivery-man/wallet/order-history.blade.php:62,95 + resources/views/vendor-views/delivery-man/wallet/_order-status-history.blade.php + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManWalletController.php:98-102

**View a delivery man's earning statement (total earned, withdrawable, per-order incentive)**  
`orders.manage`  
- App — Yes — 'earnings' tab plus a full-screen 'earning_statement' view
- Web — Yes — delivery man wallet 'earning' page with the three totals and an earning history table
- Server — GET /api/v3/seller/delivery-man/earning/{id} (DeliveryManController::earning); WEB vendor.delivery-man.wallet.earning
- Evidence — flutter: lib/features/delivery_man/widgets/delivery_man_earning_list_widget.dart:33-42 + lib/features/delivery_man/screens/delivery_man_earning_view_all_screen.dart:22-26 + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:104-112; web: resources/views/vendor-views/delivery-man/wallet/earning.blade.php:29-58 + /home/user/Pharmacy/routes/vendor/routes.php:292 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManWalletController.php:109-125

**View the customer reviews left for a delivery man**  
`orders.manage`  
- App — Yes — 'reviews' tab with reviewer, stars and comment
- Web — Yes — delivery man rating page with a reviews table
- Server — GET /api/v3/seller/delivery-man/reviews/{id} (DeliveryManController::reviews); WEB vendor.delivery-man.rating
- Evidence — flutter: lib/features/delivery_man/widgets/delivery_man_review_list_widget.dart:19-27 + lib/features/delivery_man/widgets/delivery_man_review_card_widget.dart:26-65 + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:38-46; web: resources/views/vendor-views/delivery-man/rating.blade.php:139-185 + /home/user/Pharmacy/routes/vendor/routes.php:284 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManController.php:183-225

**See the top-performing delivery men (leaderboard)**  
`products.view/products.manage/orders.view/orders.manage (API top-delivery-man)`  
- App — Yes — 'top_delivery_man' section on the home dashboard with a View-All screen
- Web — Yes — 'Top_Delivery_Man' dashboard card whose View All links to the list sorted by rating
- Server — GET /api/v3/seller/top-delivery-man (ProductController::top_delivery_man); WEB dashboard data + vendor.delivery-man.list?sort_by=rating
- Evidence — flutter: lib/features/home/screens/home_page_screen.dart:186 + lib/features/delivery_man/widgets/top_delivery_man_view_widget.dart:29-64 + lib/features/delivery_man/screens/top_delivery_man_screen.dart:13-22 + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:114-122; web: resources/views/vendor-views/dashboard/index.blade.php:143 + resources/views/vendor-views/partials/_top-rated-delivery-man.blade.php:1-53; api: routes/rest_api/v3/seller.php:146 + app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1909-1935

**List delivery man withdraw requests**  
`orders.manage`  
- App — Yes — DeliveryManWithdrawScreen + WithdrawListView
- Web — Yes — delivery-man withdraw index with the _table partial
- Server — GET /api/v3/seller/delivery-man/withdraw/list (DeliverymanWithdrawController::list); WEB vendor.delivery-man.withdraw.index
- Evidence — flutter: lib/features/delivery_man/screens/withdraw/withdraw_screen.dart:25,65 + lib/features/delivery_man/screens/withdraw/withdraw_list.dart:17-37 + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:28-36; web: resources/views/vendor-views/delivery-man/withdraw/index.blade.php:60-62 + resources/views/vendor-views/delivery-man/withdraw/_table.blade.php:1-68 + /home/user/Pharmacy/routes/vendor/routes.php:300 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManWithdrawController.php:53-68

**Filter withdraw requests by status (all / pending / approved / denied)**  
`orders.manage`  
- App — Yes — four chip buttons that re-query the list
- Web — Yes — status select that re-renders the table via getFiltered
- Server — GET delivery-man/withdraw/list?status= ; WEB POST vendor.delivery-man.withdraw.index (getFiltered)
- Evidence — flutter: lib/features/delivery_man/screens/withdraw/withdraw_screen.dart:49-59 + lib/features/delivery_man/controllers/delivery_man_controller.dart:437-451; web: resources/views/vendor-views/delivery-man/withdraw/index.blade.php:49-56,67 + /home/user/Pharmacy/routes/vendor/routes.php:301 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManWithdrawController.php:75-84; api: app/Http/Controllers/RestAPI/v3/seller/DeliverymanWithdrawController.php:16-46

**View a withdraw request's details (amount, status, request time, bank info, delivery man info, approval/denial note)**  
`orders.manage`  
- App — Yes — WithdrawDetailsScreen
- Web — Yes — right-hand withdraw-info sidebar (_details partial)
- Server — GET /api/v3/seller/delivery-man/withdraw/details/{id} (DeliverymanWithdrawController::details); WEB vendor.delivery-man.withdraw.details
- Evidence — flutter: lib/features/delivery_man/screens/withdraw/withdraw_card.dart:20-23 + lib/features/delivery_man/screens/withdraw/withdraw_details_screen.dart:27,44-118 + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:48-56; web: resources/views/vendor-views/delivery-man/withdraw/_table.blade.php:38-43 + resources/views/vendor-views/delivery-man/withdraw/_details.blade.php:9-80 + /home/user/Pharmacy/routes/vendor/routes.php:302

**Approve or deny a delivery man withdraw request with a note**  
`orders.manage`  
- App — Yes — Approve / Deny buttons on the withdraw details screen open a note dialog
- Web — Yes — Approve / Deny buttons reveal the approval-note / denial-note forms
- Server — PUT /api/v3/seller/delivery-man/withdraw/status-update (DeliverymanWithdrawController::status_update); WEB POST vendor.delivery-man.withdraw.update-status
- Evidence — flutter: lib/features/delivery_man/screens/withdraw/withdraw_details_screen.dart:120-164 + lib/features/delivery_man/screens/withdraw/withdraw_approve_deny_widget.dart:45-58 + lib/features/delivery_man/controllers/delivery_man_controller.dart:421-431 + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:58-72; web: resources/views/vendor-views/delivery-man/withdraw/_details.blade.php:88-131 + /home/user/Pharmacy/routes/vendor/routes.php:303 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManWithdrawController.php:115-145

**List emergency contacts for delivery staff**  
`orders.manage`  
- App — Yes — EmergencyContactScreen + EmergencyContactListViewWidget
- Web — Yes — 'contact_information_Table' on the emergency-contact index
- Server — GET /api/v3/seller/delivery-man/emergency-contact/list (EmergencyContactController::list); WEB vendor.delivery-man.emergency-contact.index
- Evidence — flutter: lib/features/emergency_contract/screens/emergency_contact_screen.dart:26,75-81 + lib/features/emergency_contract/domain/repositories/emergency_contact_repository.dart:74-82; web: resources/views/vendor-views/delivery-man/emergency-contact/index.blade.php:53-131 + /home/user/Pharmacy/routes/vendor/routes.php:310 + app/Http/Controllers/Vendor/DeliveryMan/EmergencyContactController.php:38-42; api: routes/rest_api/v3/seller.php:366

**Add an emergency contact (name, phone)**  
`orders.manage`  
- App — Yes — FAB opens AddEmergencyContactWidget
- Web — Yes — 'add_new_contact_information' form
- Server — POST /api/v3/seller/delivery-man/emergency-contact/store; WEB POST vendor.delivery-man.emergency-contact.index
- Evidence — flutter: lib/features/emergency_contract/screens/emergency_contact_screen.dart:84-92 + lib/features/emergency_contract/widgets/add_emergency_contact_widget.dart:61-121 + lib/features/emergency_contract/domain/repositories/emergency_contact_repository.dart:14-33; web: resources/views/vendor-views/delivery-man/emergency-contact/index.blade.php:13-52 + /home/user/Pharmacy/routes/vendor/routes.php:311 + app/Http/Controllers/Vendor/DeliveryMan/EmergencyContactController.php:48-53

**Edit an emergency contact**  
`orders.manage`  
- App — Yes — swipe 'edit' on the contact card reopens the dialog prefilled
- Web — Yes — edit button loads the _update-emergency-contact modal
- Server — PUT /api/v3/seller/delivery-man/emergency-contact/update; WEB vendor.delivery-man.emergency-contact.update
- Evidence — flutter: lib/features/emergency_contract/widgets/emergency_contact_card_widget.dart:45-53,72-80 + lib/features/emergency_contract/domain/repositories/emergency_contact_repository.dart:20-28; web: resources/views/vendor-views/delivery-man/emergency-contact/index.blade.php:100-104 + resources/views/vendor-views/delivery-man/emergency-contact/_update-emergency-contact.blade.php + /home/user/Pharmacy/routes/vendor/routes.php:312-313

**Enable / disable an emergency contact**  
`orders.manage`  
- App — Yes — FlutterSwitch on the contact card
- Web — Yes — switcher in the status column (PATCH)
- Server — PUT /api/v3/seller/delivery-man/emergency-contact/status-update; WEB PATCH vendor.delivery-man.emergency-contact.index
- Evidence — flutter: lib/features/emergency_contract/widgets/emergency_contact_card_widget.dart:145-147 + lib/features/emergency_contract/domain/repositories/emergency_contact_repository.dart:35-47; web: resources/views/vendor-views/delivery-man/emergency-contact/index.blade.php:80-96 + /home/user/Pharmacy/routes/vendor/routes.php:314 + app/Http/Controllers/Vendor/DeliveryMan/EmergencyContactController.php:73-87

**Delete an emergency contact**  
`orders.manage`  
- App — Yes — swipe 'delete' action on the contact card
- Web — Yes — trash button posting the delete form
- Server — DELETE /api/v3/seller/delivery-man/emergency-contact/delete; WEB DELETE vendor.delivery-man.emergency-contact.index
- Evidence — flutter: lib/features/emergency_contract/widgets/emergency_contact_card_widget.dart:36-43,62-69 + lib/features/emergency_contract/domain/repositories/emergency_contact_repository.dart:55-66; web: resources/views/vendor-views/delivery-man/emergency-contact/index.blade.php:105-115 + /home/user/Pharmacy/routes/vendor/routes.php:315 + app/Http/Controllers/Vendor/DeliveryMan/EmergencyContactController.php:93-102

**Choose how an order is delivered (own delivery man vs third-party service)**  
`orders.manage`  
- App — Yes — delivery_type dropdown in the order setup bottom sheet
- Web — Yes — 'choose_delivery_type' select on the order details page
- Server — POST /api/v3/seller/orders/order-detail-info-update (OrderController::updateOrderDetails); WEB vendor.orders.update-deliver-info
- Evidence — flutter: lib/features/order/widgets/delivery_man_assign_widget.dart:66-94 + lib/features/order_details/widgets/order_setup_bottom_sheet.dart:207,307 + lib/features/order_details/domain/models/order_setup_model.dart:38-49 + lib/utill/app_constants.dart:134; web: resources/views/vendor-views/order/order-details.blade.php:944-966,2004 + /home/user/Pharmacy/routes/vendor/routes.php:195; api route routes/rest_api/v3/seller.php:212

**Assign or change the delivery man on an order**  
`orders.manage`  
- App — Yes — deliveryman dropdown populated from the seller's delivery men, blocked once the order is delivered
- Web — Yes — 'addDeliveryMan' select on the order details page
- Server — GET /api/v3/seller/seller-delivery-man for the picker + POST orders/order-detail-info-update (also PUT orders/assign-delivery-man); WEB vendor.orders.add-delivery-man/{order_id}/{d_man_id}
- Evidence — flutter: lib/features/order/widgets/delivery_man_assign_widget.dart:106-136 + lib/features/delivery_man/controllers/delivery_man_controller.dart:154-174 + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:18-26 (AppConstants.getDeliveryManUri, app_constants.dart:47); web: resources/views/vendor-views/order/order-details.blade.php:968-1007,2005 + /home/user/Pharmacy/routes/vendor/routes.php:196; api: routes/rest_api/v3/seller.php:81,206 + app/Http/Controllers/RestAPI/v3/seller/SellerController.php:79-85

**Set the delivery man's incentive / extra charge on an order**  
`orders.manage`  
- App — Yes — 'additional_delivery_man_fee' amount field, disabled once delivered
- Web — Yes — 'delivery_man_incentive' input with its own Update button
- Server — orders/order-detail-info-update (deliveryman_charge) and PUT orders/delivery-charge-date-update; WEB vendor.orders.amount-date-update
- Evidence — flutter: lib/features/order/widgets/delivery_man_assign_widget.dart:143-154 + lib/features/order_details/domain/models/order_setup_model.dart:44; web: resources/views/vendor-views/order/order-details.blade.php:1013-1034,2007-2011 + /home/user/Pharmacy/routes/vendor/routes.php:197; api: routes/rest_api/v3/seller.php:208

**Set the expected delivery date on an order**  
`orders.manage`  
- App — Yes — read-only field opening a date picker
- Web — Yes — expected_delivery_date input on the order details page
- Server — orders/order-detail-info-update (expected_delivery_date) / orders/delivery-charge-date-update; WEB vendor.orders.amount-date-update
- Evidence — flutter: lib/features/order/widgets/delivery_man_assign_widget.dart:157-191 + lib/features/order_details/widgets/order_setup_bottom_sheet.dart:303-304; web: resources/views/vendor-views/order/order-details.blade.php:1036-1046

**Record third-party delivery service name and tracking id on an order**  
`orders.manage`  
- App — Yes — two text fields shown when delivery type is third-party
- Web — Yes — 'update_third_party_delivery_info' modal
- Server — orders/order-detail-info-update (delivery_service_name, third_party_delivery_tracking_id) and POST orders/assign-third-party-delivery; WEB vendor.orders.update-deliver-info
- Evidence — flutter: lib/features/order/widgets/delivery_man_assign_widget.dart:203-232 + lib/features/order_details/domain/models/order_setup_model.dart:46-47; web: resources/views/vendor-views/order/order-details.blade.php:1048-1057,1903-1930; api: routes/rest_api/v3/seller.php:209

**Set 'free delivery over amount' for the shop (seller-borne free shipping threshold)**  
`shop_settings.manage`  
- App — Yes — other_setup_screen free delivery status + over-amount fields
- Web — Yes — shop other-setup page free_delivery_status switch + amount
- Server — PUT /api/v3/seller/shop-update (free_delivery_status, free_delivery_over_amount); WEB vendor.shop.update-other-settings
- Evidence — flutter: lib/features/shop/screens/other_setup_screen.dart:35,42,45,59 + lib/features/shop/domain/repositories/shop_repository.dart:66; web: resources/views/vendor-views/shop/other-setup.blade.php:54-68 + /home/user/Pharmacy/routes/vendor/routes.php:338

## WEB MISSING (4)

**Set the delivery man's country dial code (country_code)**  
`orders.manage` · wave 4  
- App — Yes — CountryCodePicker next to the phone field; country_code is posted with the form
- Web — No — neither the add nor the update form renders a country_code input; $telephoneCodes is compacted into the view but never used, so DeliveryManService stores country_code as a bare '+' and leaves the dial code inside the phone string
- Server — POST /api/v3/seller/delivery-man/store, PUT delivery-man/update/{id} (country_code is a required field on the API update validator)
- Evidence — flutter: lib/features/delivery_man/widgets/delivery_man_info_widget.dart:155-170 + lib/features/delivery_man/controllers/delivery_man_controller.dart:345-354 + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:211; web: resources/views/vendor-views/delivery-man/index.blade.php:32-42 (tel input only, no country_code) and resources/views/vendor-views/delivery-man/update-view.blade.php:42-48, while app/Http/Controllers/Vendor/DeliveryMan/DeliveryManController.php:58 passes $telephoneCodes and app/Services/DeliveryManService.php:27-29 writes '+'.$request['country_code']; api validator app/Http/Controllers/RestAPI/v3/seller/DeliveryManController.php:95

**See whether a delivery man is currently online or offline**  
`orders.manage` · wave 4  
- App — Yes — green check 'online' / red 'offline' badge on every delivery man card
- Web — No — the list only exposes the is_active enable/disable toggle; is_online is never rendered anywhere in vendor-views
- Server — Served by the same GET /api/v3/seller/delivery-man/list payload (DeliveryMan model exposes is_online); web repo/view simply never reads it
- Evidence — flutter: lib/features/delivery_man/widgets/delivery_man_card_widget.dart:165-178 + lib/features/delivery_man/domain/model/top_delivery_man.dart (isOnline); web: grep 'is_online' over /home/user/Pharmacy/resources/views/vendor-views returns nothing — only /home/user/Pharmacy/app/Models/DeliveryMan.php:25,52,73 defines it; list view shows is_active only at resources/views/vendor-views/delivery-man/list.blade.php:112

**See a delivery man's withdrawable balance**  
`orders.manage` · wave 4  
- App — Yes — 'withdrawable_balance' row, fed by details.withdrawbale_balance
- Web — Partial/broken — the card exists but DeliveryManWalletController::getView tests the undefined variable $delivery_man instead of $deliveryMan, so $withdrawAbleBalance is always null and the card always renders 0
- Server — GET /api/v3/seller/delivery-man/details/{id} computes it via CommonTrait::delivery_man_withdrawable_balance; the vendor earning page computes it correctly, the overview page does not
- Evidence — flutter: lib/features/delivery_man/screens/delivery_man_overview_screen.dart:46-48 + app/Http/Controllers/RestAPI/v3/seller/DeliveryManController.php:156-166; web bug: /home/user/Pharmacy/app/Http/Controllers/Vendor/DeliveryMan/DeliveryManWalletController.php:72-78 ($deliveryMan assigned, isset($delivery_man->wallet) tested) rendered at resources/views/vendor-views/delivery-man/wallet/index.blade.php:92; correct computation exists at DeliveryManWalletController.php:123

**Search emergency contacts**  
`orders.manage` · wave 4  
- App — Yes — search field at the top of EmergencyContactScreen
- Web — No — the emergency-contact index has no search box and EmergencyContactController::index passes no searchValue to the repository
- Server — GET /api/v3/seller/delivery-man/emergency-contact/list?search= (already supported server-side)
- Evidence — flutter: lib/features/emergency_contract/screens/emergency_contact_screen.dart:44-70 + lib/features/emergency_contract/domain/repositories/emergency_contact_repository.dart:84-92; web: resources/views/vendor-views/delivery-man/emergency-contact/index.blade.php:53-57 (card actions hold only a count, no search form) + app/Http/Controllers/Vendor/DeliveryMan/EmergencyContactController.php:40 (getListWhere called with filters only)

## APP MISSING (9)

**Search categories while setting category-wise shipping cost**  
`shop_settings.manage`  
- App — No — CategoryWiseShippingScreen has no search field; the full category list is rendered
- Web — Yes — 'Search_by_category_name' GET form above the category cost table
- Server — WEB only (searchValue handled in Vendor ShippingMethodController::index); the API all-category-cost endpoint accepts no search param
- Evidence — flutter: lib/features/shipping/screens/category_wise_shipping_screen.dart:36-58 (no search widget) and lib/features/shipping/domain/repositories/shipping_repository.dart:47-55 (no query string); web: resources/views/vendor-views/shipping-method/index.blade.php:234-247 + app/Http/Controllers/Vendor/Shipping/ShippingMethodController.php:73

**Sort the delivery man list (recent / oldest / top rated)**  
`orders.manage`  
- App — No — no sort control; the list is always the API's default order
- Web — Yes — 'Sorting' dropdown with latest / oldest / rating
- Server — WEB vendor.delivery-man.list?sort_by= (DeliveryManRepository getListWhere filters['sort_by']); the API delivery-man/list has no sort_by param
- Evidence — flutter: lib/features/delivery_man/screens/delivery_man_list_screen.dart:38-63 (no sort UI) + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:77 (query string is limit/offset/search only); web: resources/views/vendor-views/delivery-man/list.blade.php:26-52 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManController.php:90-96

**Export the delivery man list to Excel**  
`orders.manage`  
- App — No — not found anywhere under lib/features/delivery_man
- Web — Yes — Export button → DeliveryManListExport
- Server — WEB vendor.delivery-man.export (DeliveryManController::exportList); no API equivalent
- Evidence — flutter: no match for 'export' under /home/user/sillercenter-syria-cosmatics/lib/features/delivery_man (grep of the whole feature dir); web: resources/views/vendor-views/delivery-man/list.blade.php:22-25 + /home/user/Pharmacy/routes/vendor/routes.php:279 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManController.php:227-254

**Search a delivery man's order history by order number**  
`orders.manage`  
- App — No — the order-history tab has no search field and the repository sends only limit/offset
- Web — Yes — 'search_by_order_no' box on the order history data-view
- Server — WEB vendor.delivery-man.wallet.order-history?search= ; the API order-list/{id} accepts no search param
- Evidence — flutter: lib/features/delivery_man/screens/delivery_man_order_history_screen.dart:22-53 (no search) + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:97; web: resources/views/vendor-views/delivery-man/wallet/order-history.blade.php:18-20 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManWalletController.php:88-90

**Filter delivery man reviews by date range and star rating, and search them by order id**  
`orders.manage`  
- App — No — the reviews tab is a plain paginated list, no filter or search controls
- Web — Yes — from/to date pickers, rating select, Filter/Reset, plus a search-by-order-id box
- Server — WEB vendor.delivery-man.rating?from_date=&to_date=&rating=&search= ; the API reviews/{id} accepts only limit/offset
- Evidence — flutter: lib/features/delivery_man/widgets/delivery_man_review_list_widget.dart:14-29 + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:41; web: resources/views/vendor-views/delivery-man/rating.blade.php:106-141 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManController.php:190-202

**See a delivery man's star-rating distribution (5→1 star breakdown)**  
`orders.manage`  
- App — No — only the numeric average is shown on the card
- Web — Yes — per-star bars with counts on the rating page
- Server — WEB computes $one..$five in DeliveryManController::getRatingView; the API reviews endpoint returns only average_rating
- Evidence — flutter: lib/features/delivery_man/widgets/delivery_man_card_widget.dart:138-144 (average only); web: resources/views/vendor-views/delivery-man/rating.blade.php:44-90 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManController.php:204-210; api: app/Http/Controllers/RestAPI/v3/seller/DeliveryManController.php:202-211

**Search withdraw requests by delivery man name**  
`orders.manage`  
- App — No — the withdraw screen has no search field; the request carries only limit/offset/status
- Web — Yes — 'search_by_name' GET form above the withdraw table
- Server — WEB vendor.delivery-man.withdraw.index?searchValue= ; the API withdraw/list has no search param
- Evidence — flutter: lib/features/delivery_man/screens/withdraw/withdraw_screen.dart:36-69 (no search) + lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:31; web: resources/views/vendor-views/delivery-man/withdraw/index.blade.php:26-38 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManWithdrawController.php:58

**Export delivery man withdraw requests to Excel**  
`orders.manage`  
- App — No — not found under lib/features/delivery_man
- Web — Yes — Export button → DeliveryManWithdrawRequestExport
- Server — WEB vendor.delivery-man.withdraw.export (DeliveryManWithdrawController::exportList); no API equivalent
- Evidence — flutter: no export action in lib/features/delivery_man/screens/withdraw/*.dart; web: resources/views/vendor-views/delivery-man/withdraw/index.blade.php:41-47 + /home/user/Pharmacy/routes/vendor/routes.php:304 + app/Http/Controllers/Vendor/DeliveryMan/DeliveryManWithdrawController.php:147-173

**Filter the order list by delivery man**  
`orders.view / orders.manage`  
- App — No — no delivery_man_id filter anywhere in lib/features/order
- Web — Partial — the orders list honours a delivery_man_id query param, but it is only reachable by clicking the order count on the delivery man list; the filter offcanvas has no delivery man picker, only a hidden passthrough
- Server — WEB vendor.orders.list?delivery_man_id= ; the API POST orders/list has no delivery_man_id filter
- Evidence — flutter: grep for delivery_man/deliveryMan across /home/user/sillercenter-syria-cosmatics/lib/features/order returns only the assign widget and the order model — no filter; web: resources/views/vendor-views/delivery-man/list.blade.php:92-95 (link) + resources/views/vendor-views/order/list.blade.php:58,69 + resources/views/vendor-views/order/partials/_filter-offcanvas.blade.php:17-18,66

## APP ADAPTATION (1)

**Quick-pick suggested amounts when collecting cash**  
`orders.manage`  
- App — Yes — hard-coded chips 500 / 1000 / 2000 / 5000 / 10000 above the amount field
- Web — No — plain numeric input only
- Server — none (client-side convenience over the same cash-receive endpoint)
- Evidence — flutter: lib/features/delivery_man/screens/withdraw/delivery_man_collect_cash_widget.dart:30,127,140; web: resources/views/vendor-views/delivery-man/wallet/cash-collect.blade.php:33-43 (single input, no presets)

