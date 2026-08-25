# Parity — settings_profile

[← back to the matrix](../SELLER_WEB_APP_PARITY.md) · 46 capabilities

**32** BOTH · **5** WEB MISSING · **2** APP MISSING · **1** WEB ENHANCEMENT · **3** DEVICE SPECIFIC · **1** DEPRECATED · **2** BACKEND MISSING

## Structural facts the implementer must know

```
STRUCTURE

Flutter: this domain is three screens plus a menu. lib/features/shop/screens/shop_screen.dart is a 3-tab container (MyShopAppBar tabs at my_shop_appbar.dart:98-123) — tab 0 ShopDetailsWidget, tab 1 PaymentInfoScreen, tab 2 OtherSetupScreen — and the web mirrors it exactly with resources/views/vendor-views/shop/inline-menu.blade.php (Shop Settings / Payment Information / Other Setup). lib/features/settings/screens/setting_screen.dart is nearly empty (language + shipping type only); the real settings menu is lib/features/profile/widgets/theme_changer_widget.dart, rendered inside profile_view_screen.dart:145 (theme toggle, shipping method, settings, bank info, delete account). lib/features/menu/screens/more_screen.dart is the app's whole navigation tree.

Endpoint ground truth (lib/utill/app_constants.dart:14-17,19,63,111-112,136-143,248): /api/v1/config, /api/v3/seller/{seller-info, seller-update, shop-info, shop-update, account-delete, temporary-close, vacation-add, update-setup-guide-app, language-change} and /api/v3/seller/payment-information/{withdrawal-method-list, list, add, update, status, default, delete}. Everything the app does in this domain goes through those.

THE FOUR REAL WEB GAPS (implementation targets, ranked)

1. Shop secondary (bottom) banner and offer banner uploads. The backend already stores both — app/Services/ShopService.php:41-42,51-54,100-101,111-114 — and the API accepts them, but resources/views/vendor-views/shop/update-view.blade.php only has `image` and `banner` inputs. Worse, app/Http/Requests/Vendor/ShopRequest.php:41,47 validates `bottomBanner`/`offerBanner` in camelCase while ShopService reads `bottom_banner`/`offer_banner` — so even if someone added inputs with the validated names they would be silently dropped. Fix = add two file inputs named bottom_banner and offer_banner to update-view.blade.php and rename the two ShopRequest rules to snake_case.

2. Seller self-service account deletion. Endpoint exists and is app-only (routes/rest_api/v3/seller.php:91-92). No vendor route, no button. The customer storefront has one (routes/web/routes.php:305) that can be used as the pattern.

3. Language choice is not persisted per seller on the web. The app writes sellers.app_language on every switch (SellerController.php:757-764); SharedController::changeLanguage only does session()->put('local', ...) (app/Http/Controllers/SharedController.php:32). A seller who switches language on the web loses it on a new browser/session, and the two surfaces disagree.

4. Contextual setup-guideline help drawer (my_shop_appbar.dart:64-82). App-only. Its copy is hardcoded in lib/utill/app_constants.dart:336-350, so it is not a backend feature — porting it to the web means duplicating that copy, or better, moving it to business settings so both surfaces read one source.

SPLIT-BRAIN ONBOARDING (silent, high-impact)

The setup-guide checklist exists on BOTH surfaces but on two different columns of the same shops row: the app writes shops.setup_guide_app (SellerController::updateSetupGuideApp, SellerController.php:328-350; seeded at login LoginController.php:86-88) while the panel writes shops.setup_guide (app/Utils/panel-helpers.php:106-116). Completing "order setup" on the web leaves the app badge at the same percentage and vice versa. Same steps, same names (shop_setup, add_new_product, order_setup, withdraw_setup, payment_information) — they should be one column.

SECURITY / BACKEND PARITY

- Bank-change payout cooling window is web-only. Vendor\ProfileController::updateBankInfo (ProfileController.php:125-140) diffs old vs new bank details and calls PayoutService::recordBankChange to arm the anti-hijack hold. SellerController::seller_info_update (SellerController.php:352-390) writes the same four bank columns via /seller-update with no such call — so an attacker with a stolen mobile token can redirect payouts without tripping the delay. recordBankChange has exactly one caller in the whole app.
- /api/v3/seller/account-delete is still reachable by GET (routes/rest_api/v3/seller.php:92) alongside DELETE; the comment says it is kept for installed builds. Worth a removal date.
- Password change requires no current password on either surface (VendorPasswordRequest.php:27-31; SellerController.php:372). Consistent, but consistently weak.

CLIENT-SIDE BUSINESS STATE (flagged as requested)

- Language list is hardcoded to Arabic + English in lib/utill/app_constants.dart:297-300. The web reads the admin-configured list (v2/_header.blade.php:346-353). Enabling a third language in admin reaches the web instantly and the app never. This is business config living in the binary.
- Setup-guideline help copy hardcoded in app_constants.dart:336-350 (see gap 4).
- lib/features/settings/domain/repositories/business_repository.dart:33-40 fabricates a Response wrapping a static list of discount packages and never calls the network — dead mock wired into DI (di_container.dart:297,455; main.dart:179). Delete it.
- SharedPreferences writes across the app are only: token, userEmail, cartList, searchAddress, currency, shippingType, showCookies, offline POS queue, theme, languageCode, countryCode. Of these only `shippingType` (splash_repository.dart:50) is business state, and it is a read-through cache refreshed from /shipping/get-shipping-method (shipping_controller.dart:162-166) — acceptable. theme/languageCode/countryCode are device preferences. No business setting is authored client-side.
- ShopController.setShowAddProductWarning (shop_controller.dart:593) dismisses the empty-catalogue banner in memory only — it returns on every app start. Minor UX, not state that belongs on the server.

WEB-ONLY EXTRAS (not gaps, just asymmetry)

Payment-information search box (payment-information.blade.php:45); "Visit Website" storefront preview (shop/index.blade.php:173-174); the shop index surfaces Total Reviews where the app profile surfaces Total Earning instead.

WHERE I LOOKED AND FOUND NOTHING

Vendor dark mode: grep dark|theme-switch over resources/views/layouts/vendor/ → none. Vendor-panel links to policy pages: grep terms|privacy over layouts/vendor/partials/_footer.blade.php and v2/* → none (pages exist publicly at routes/web/routes.php:205). Vendor account-delete route: grep account-delete over routes/vendor/routes.php → none. bottom_banner/offer_banner in any blade: grep over resources/views/ → none.
```

## BOTH (32)

**View own seller profile (avatar, first/last name, phone, email)**  
`none (own session; seller_api_auth only)`  
- App — Yes — lib/features/profile/screens/profile_view_screen.dart (ProfileScreenView, header block lines 51-126); data from ProfileController.getSellerInfo()
- Web — Partial — resources/views/vendor-views/profile/index.blade.php renders ONLY bank info ("my_bank_info"); the identity fields are visible only inside the edit form resources/views/vendor-views/profile/update-view.blade.php:74-138
- Server — GET /api/v3/seller/seller-info → SellerController::getSellerInfo (routes/rest_api/v3/seller.php:78; app/Http/Controllers/RestAPI/v3/seller/SellerController.php:206) | web: Vendor\ProfileController::getListView (routes/vendor/routes.php:322)

**Edit own profile: first name, last name, phone with country dial code, avatar image**  
`seller_owner (API); web: authenticated seller only`  
- App — Yes — lib/features/profile/screens/profile_screen.dart (fields 198-257, avatar picker 164-183, submit 316-319)
- Web — Yes — resources/views/vendor-views/profile/update-view.blade.php:85,102,117-120,145 (f_name, l_name, phone, image)
- Server — PUT /api/v3/seller/seller-update → SellerController::seller_info_update (routes/rest_api/v3/seller.php:93; SellerController.php:352-390) | web POST vendor/profile/update/{id} → Vendor\ProfileController::update (routes/vendor/routes.php:324; ProfileController.php:75-83)
- Evidence — flutter lib/features/profile/domain/repositories/profile_repository.dart:31-49 (multipart _method=put, f_name/l_name/phone/image); web app/Services/VendorService.php:96-105 (getVendorDataForUpdate)

**Change own account password**  
`seller_owner (API)`  
- App — Yes — lib/features/profile/screens/profile_screen.dart:260-292 (password + confirm, strength view PassView), sent with the profile update
- Web — Yes — separate form resources/views/vendor-views/profile/update-view.blade.php:170-238 (password/confirm_password)
- Server — PUT /api/v3/seller/seller-update with `password` (SellerController.php:372,382-388 — rotates auth_token and returns it) | web PATCH vendor/profile/update/{id} → ProfileController::updatePassword (routes/vendor/routes.php:325; ProfileController.php:90-96)
- Evidence — flutter lib/features/profile/domain/repositories/profile_repository.dart:44-46; web app/Http/Requests/Vendor/VendorPasswordRequest.php:27-31

**View own email address (read-only on both sides)**  
`none`  
- App — Yes, read-only — lib/features/profile/screens/profile_screen.dart:222-226 (CustomTextFieldWidget idDate:true = disabled, hint = email)
- Web — Yes, read-only — resources/views/vendor-views/profile/update-view.blade.php:136-137 (`readonly`)
- Server — seller-info returns email; no update path on either side — VendorService::getVendorDataForUpdate does not persist email (app/Services/VendorService.php:96-105) and SellerController::seller_info_update does not either
- Evidence — flutter profile_screen.dart:222-226; web update-view.blade.php:134-138 (input name="email" but readonly, and not in VendorRequest rules app/Http/Requests/Vendor/VendorRequest.php:31-35)

**View / update bank account payout details (holder name, bank, branch, account number)**  
`seller_owner (API)`  
- App — Yes — lib/features/bank_info/screens/bank_info_screen.dart + bank_editing_screen.dart:126-192 (holder/bank/branch/account); entered from profile menu lib/features/profile/widgets/theme_changer_widget.dart:75-78
- Web — Yes — resources/views/vendor-views/profile/bank-info-update-view.blade.php:26-45 (bank_name, branch, holder_name, account_no)
- Server — PUT /api/v3/seller/seller-update (bank fields written in SellerController.php:366-369) | web POST vendor/profile/update-bank-info/{id} → ProfileController::updateBankInfo (routes/vendor/routes.php:327; ProfileController.php:118-141)
- Evidence — flutter lib/features/bank_info/domain/repositories/bank_info_repository.dart:18-19 (posts to AppConstants.sellerAndBankUpdate); web app/Http/Controllers/Vendor/ProfileController.php:122-130

**Log out of the panel/app**  
`none`  
- App — Yes — lib/features/menu/screens/more_screen.dart:217-219 → SignOutConfirmationDialogWidget → AuthController.clearSharedData()
- Web — Yes — resources/views/layouts/vendor/partials/_sign-out-modal.blade.php
- Server — client-side token/session clear; web vendor auth logout route
- Evidence — flutter lib/features/menu/widgets/sign_out_confirmation_dialog_widget.dart:87; web resources/views/layouts/vendor/partials/_sign-out-modal.blade.php

**Change the panel/app display language**  
`none`  
- App — Yes — lib/features/settings/screens/setting_screen.dart:29-33 → lib/features/language/screens/change_language_screen.dart (grid of languages)
- Web — Yes — language dropdown in resources/views/layouts/vendor/partials/v2/_header.blade.php:346-364
- Server — PUT /api/v3/seller/language-change → SellerController::language_change (routes/rest_api/v3/seller.php:77; SellerController.php:757-764) | web POST change-language → SharedController::changeLanguage (routes/admin/routes.php:113; app/Http/Controllers/SharedController.php:21-32)
- Evidence — flutter lib/features/auth/domain/repositories/auth_repository.dart:38-47 + lib/localization/controllers/localization_controller.dart:40,66-67; web resources/views/layouts/vendor/partials/v2/_header.blade.php:354-357

**Maintenance-mode lockout with admin's message and support contacts**  
`none`  
- App — Yes — lib/features/maintenance/maintenance_screen.dart:44-45 (checks maintenanceStatus + selectedMaintenanceSystem.vendorApp), messages 75-97, company phone/email 133,151
- Web — Yes — MaintenanceModeMiddleware redirects the vendor panel to route('maintenance-mode', ['maintenance_system' => 'vendor'])
- Server — config maintenance_mode_data (v1/config) | web app/Http/Middleware/MaintenanceModeMiddleware.php:26; applied at routes/vendor/routes.php:45 ('maintenance_mode' middleware group)

**Read platform policy pages (terms, about us, privacy, refund, return, cancellation)**  
`none`  
- App — Yes — lib/features/menu/screens/more_screen.dart:182-216 opens lib/features/more/screens/html_view_screen.dart with the BusinessPageModel from config
- Web — Partial — pages render at the public storefront route business-page/{slug} (routes/web/routes.php:205 → Web\PageController::getPageView), but the vendor panel never links them (grep terms|privacy over resources/views/layouts/vendor/partials/_footer.blade.php and v2/* → no match)
- Server — business pages come from /api/v1/config default_business_pages (app/Http/Controllers/RestAPI/v1/ConfigController.php) | web routes/web/routes.php:205

**View shop profile card (logo, shop name, created date, TIN + expiry, product/order/review counts)**  
`seller_api_auth (read); web authenticated seller`  
- App — Yes — lib/features/shop/widgets/shop_card_widget.dart:60-175 inside lib/features/shop/screens/shop_screen.dart (tab 0 → ShopDetailsWidget)
- Web — Yes — resources/views/vendor-views/shop/index.blade.php:160-220 (created_at, Total Products/Orders/Reviews, TIN + Exp)
- Server — GET /api/v3/seller/shop-info → SellerController::shop_info (routes/rest_api/v3/seller.php:80; SellerController.php:57-77) | web Vendor\ShopController::index (routes/vendor/routes.php:333)
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:29 + shop_card_widget.dart:114-175; web resources/views/vendor-views/shop/index.blade.php:185-220

**Edit shop name, contact number and address**  
`seller_can:shop_settings.manage (API)`  
- App — Yes — lib/features/shop/screens/shop_update_screen.dart:253 (name), :269 (contact + country code), :290 (address)
- Web — Yes — resources/views/vendor-views/shop/update-view.blade.php:50 (name), :59 (company_phone), :73 (address)
- Server — PUT /api/v3/seller/shop-update → SellerController::shop_info_update (routes/rest_api/v3/seller.php:98; SellerController.php:243,312-315) | web POST vendor/shop/update/{id} → Vendor\ShopController::update (routes/vendor/routes.php:335; ShopController.php:103-110)
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:75-79 (name/address/contact fields); web app/Http/Requests/Vendor/ShopRequest.php:26-29 + app/Services/ShopService.php:100-114

**Upload / replace shop logo**  
`seller_can:shop_settings.manage`  
- App — Yes — lib/features/shop/screens/shop_update_screen.dart:387,413 (_choose → gallery)
- Web — Yes — resources/views/vendor-views/shop/update-view.blade.php:102 (input file name="image")
- Server — shop-update multipart field `logo` (shop_repository.dart:42 → SellerController::shop_info_update) | web ShopService::getShopDataForUpdate app/Services/ShopService.php:100-114
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:41-43; web resources/views/vendor-views/shop/update-view.blade.php:94-131

**Upload / replace shop cover banner**  
`seller_can:shop_settings.manage`  
- App — Yes — lib/features/shop/screens/shop_update_screen.dart:498,525 (authProvider.pickImage → banner)
- Web — Yes — resources/views/vendor-views/shop/update-view.blade.php:152 (input file name="banner")
- Server — shop-update multipart field `banner` (shop_repository.dart:45-47) | web app/Services/ShopService.php:40,50
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:44-48; web resources/views/vendor-views/shop/update-view.blade.php:146-180

**Toggle store availability (temporarily close the shop to customers)**  
`seller_can:shop_settings.manage`  
- App — Yes — lib/features/shop/widgets/shop_details_widget.dart:176-205 (ShopSettingWidget switch + confirmation bottom sheet) → ShopController.shopTemporaryClose
- Web — Yes — resources/views/vendor-views/shop/index.blade.php:30-58 (switcher posting to vendor.shop.close-shop-temporary)
- Server — PUT /api/v3/seller/temporary-close → v3\seller\ShopController::temporary_close (routes/rest_api/v3/seller.php:131; app/Http/Controllers/RestAPI/v3/seller/ShopController.php:30-40) | web POST vendor/shop/close-shop-temporary → Vendor\ShopController::closeShopTemporary (routes/vendor/routes.php:337; ShopController.php:128-135)
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:92-98 + lib/features/shop/controllers/shop_controller.dart:222; web resources/views/vendor-views/shop/index.blade.php:36-58

**Vacation mode: on/off, duration type (24 hours / until I change / custom), start+end date, vacation note**  
`seller_can:shop_settings.manage`  
- App — Yes — lib/features/shop/screens/vacation_mode_setup_screen.dart:99-320 (status switch, 3 radio duration types, date pickers, 100-char note)
- Web — Yes — resources/views/vendor-views/shop/partials/_vacation-mode-offcanvas.blade.php:29 (status), :50-72 (until_change/one_day/custom), :88-99 (start/end), :118 (note); opened from shop/index.blade.php:78-82
- Server — PUT /api/v3/seller/vacation-add → v3\seller\ShopController::vacation_add (routes/rest_api/v3/seller.php:130; ShopController.php:16-28) | web POST vendor/shop/add-vacation → Vendor\ShopController::updateVacation (routes/vendor/routes.php:336; ShopController.php:116-122)
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:80-86 + vacation_mode_setup_screen.dart:301-320; web resources/views/vendor-views/shop/partials/_vacation-mode-offcanvas.blade.php:12-119

**Set minimum order amount for the shop (when admin delegates it to sellers)**  
`seller_can:shop_settings.manage`  
- App — Yes — lib/features/shop/screens/other_setup_screen.dart:113-125 (gated on configModel.minimumOrderAmountStatus && minimumOrderAmountStatusBySeller)
- Web — Yes — resources/views/vendor-views/shop/other-setup.blade.php:36-44 (name="minimum_order_amount")
- Server — shop-update field `minimum_order_amount` (SellerController.php shop_info_update) | web POST vendor/shop/update-other-settings → Vendor\ShopController::updateOtherSettings (routes/vendor/routes.php:338; ShopController.php:153-159)
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:75-78 + other_setup_screen.dart:428-431; web app/Http/Controllers/Vendor/ShopController.php:155-159

**Free delivery over amount: on/off toggle + threshold (when admin makes it the seller's responsibility)**  
`seller_can:shop_settings.manage`  
- App — Yes — lib/features/shop/screens/other_setup_screen.dart:128-160 (gated on freeDeliveryStatus && freeDeliveryResponsibility=='seller')
- Web — Yes — resources/views/vendor-views/shop/other-setup.blade.php:61-76 (free_delivery_status switcher + free_delivery_over_amount)
- Server — shop-update fields free_delivery_status / free_delivery_over_amount | web VendorService::getFreeDeliveryOverAmountData via ShopController::updateOtherSettings (ShopController.php:165-169)
- Evidence — flutter other_setup_screen.dart:419-430 + shop_repository.dart:76-77; web resources/views/vendor-views/shop/other-setup.blade.php:54-77

**Set re-order / low-stock alert level (stock_limit)**  
`seller_can:shop_settings.manage`  
- App — Yes — lib/features/shop/screens/other_setup_screen.dart:164-174 (_reOrderLevelController, required on save 421-422)
- Web — Yes — resources/views/vendor-views/shop/other-setup.blade.php:84-92 (name="stock_limit")
- Server — shop-update field `stock_limit` (SellerController.php:300) | web ShopController::updateOtherSettings → VendorService::getVendorStockLimit (ShopController.php:160-164)
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:78 ('stock_limit') + other_setup_screen.dart:435; web app/Http/Controllers/Vendor/ShopController.php:160-164

**Business TIN: taxpayer identification number + expiry date**  
`seller_can:shop_settings.manage`  
- App — Yes — lib/features/shop/screens/other_setup_screen.dart:203-249 (_tinNumberController, pickTinExpireDate)
- Web — Yes — resources/views/vendor-views/shop/other-setup.blade.php:115 (tax_identification_number), :125 (tin_expire_date datepicker)
- Server — shop-update fields tax_identification_number / tin_expire_date (SellerController.php:320-321) | web VendorService::getUpdateBusinessTIN via ShopController::updateOtherSettings (ShopController.php:170-174)
- Evidence — flutter other_setup_screen.dart:432-433 + shop_repository.dart:77; web resources/views/vendor-views/shop/other-setup.blade.php:113-128

**Upload TIN certificate document, then preview / download it**  
`seller_can:shop_settings.manage`  
- App — Yes — upload lib/features/shop/screens/other_setup_screen.dart:339-371 (pickTinCertificateFile), preview :312-329 (showPreview), download :296-308 (ShopController.previewDownload)
- Web — Yes — upload resources/views/vendor-views/shop/other-setup.blade.php:189, view resources/views/vendor-views/shop/other-setup.blade.php:206-222 ('Click_to_view_the_file')
- Server — shop-update multipart `tin_certificate` (shop_repository.dart:58-61 → SellerController.php:304-310) | web ShopController::updateOtherSettings → getUpdateBusinessTIN
- Evidence — flutter lib/features/shop/controllers/shop_controller.dart:534 (pickTinCertificateFile), :606 (previewDownload); web resources/views/vendor-views/shop/other-setup.blade.php:155-222

**View the withdrawal methods the admin has enabled for sellers**  
`seller_can:finance.view`  
- App — Yes — ShopController.getPaymentWithdrawalMethodList() called on shop screen open (lib/features/shop/screens/shop_screen.dart:44), rendered in the add-payment dropdown (add_payment_info_screen.dart:92,133)
- Web — Yes — the add-payment modal's select is populated from the enabled methods (resources/views/vendor-views/shop/payment-information.blade.php:240-246)
- Server — GET /api/v3/seller/payment-information/withdrawal-method-list → VendorPaymentInfoController::getWithdrawalMethods (routes/rest_api/v3/seller.php:394) | web Vendor\VendorPaymentInfoController::index (routes/vendor/routes.php:345)
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:106-112; web resources/views/vendor-views/shop/payment-information.blade.php:240-246

**Add payment/withdrawal information (method name, withdraw method, dynamic per-method fields incl. date and phone inputs, active status)**  
`seller_owner (API)`  
- App — Yes — lib/features/shop/screens/add_payment_info_screen.dart:119-296 (method name, method select, status switch, dynamic MethodFields with text/date/phone types)
- Web — Yes — resources/views/vendor-views/shop/payment-information.blade.php:214-288 (method_name, withdraw_method_id, status, method_info[...] dynamic fields)
- Server — POST /api/v3/seller/payment-information/add (routes/rest_api/v3/seller.php:398) | web POST vendor/shop/payment-information/add (routes/vendor/routes.php:346)
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:118-127 + add_payment_info_screen.dart:258-296; web resources/views/vendor-views/shop/payment-information.blade.php:214,264-278

**Edit an existing payment information entry**  
`seller_owner (API)`  
- App — Yes — lib/features/shop/screens/payment_info_screen.dart:352 ('edit') → AddPaymentInfoScreen(formUpdate:true)
- Web — Yes — resources/views/vendor-views/shop/payment-information.blade.php:155 ('Edit') + partials/_payment-information-modals.blade.php
- Server — POST /api/v3/seller/payment-information/update (routes/rest_api/v3/seller.php:399) | web POST vendor/shop/payment-information/update (routes/vendor/routes.php:347) + GET edit/{id} (routes/vendor/routes.php:348)
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:120-121; web resources/views/vendor-views/shop/payment-information.blade.php:297 (data-route to payment-information.update)

**Delete a payment information entry**  
`seller_owner (API)`  
- App — Yes — lib/features/shop/screens/payment_info_screen.dart:283,368 → ShopController.deletePaymentMethodStatus
- Web — Yes — resources/views/vendor-views/shop/payment-information.blade.php:158-163 (delete action, hidden when is_default)
- Server — GET /api/v3/seller/payment-information/delete?id= (routes/rest_api/v3/seller.php:402) | web GET vendor/shop/payment-information/delete (routes/vendor/routes.php:350)
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:158-166; web resources/views/vendor-views/shop/payment-information.blade.php:158-165

**Enable / disable a payment information entry**  
`seller_owner (API)`  
- App — Yes — lib/features/shop/screens/payment_info_screen.dart:292-315 (FlutterSwitch + confirmation sheet) → ShopController.updatePaymentMethodStatus
- Web — Yes — resources/views/vendor-views/shop/payment-information.blade.php:112-132 (switcher form posting to payment-information.update-status; disabled while is_default)
- Server — POST /api/v3/seller/payment-information/status (routes/rest_api/v3/seller.php:401) | web POST vendor/shop/payment-information/status (routes/vendor/routes.php:349)
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:141-152; web resources/views/vendor-views/shop/payment-information.blade.php:112-132

**Mark a payment information entry as the default payout destination**  
`seller_owner (API)`  
- App — Yes — lib/features/shop/screens/payment_info_screen.dart:335-340 ('mark_as_default') → ShopController.setDefaultPaymentMethod; default badge at :261
- Web — Yes — resources/views/vendor-views/shop/payment-information.blade.php:144-149 ('mark_as_default'); default badge at :87-88
- Server — POST /api/v3/seller/payment-information/default (routes/rest_api/v3/seller.php:400) | web POST vendor/shop/payment-information/default (routes/vendor/routes.php:348)
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:169-179; web resources/views/vendor-views/shop/payment-information.blade.php:144-149

**Onboarding setup-guide checklist with completion percentage (shop setup, add product, order setup, payment information, withdraw setup)**  
`seller_can:shop_settings.manage (API)`  
- App — Yes — floating tutorial badge in lib/main.dart:261-315 driven by shopModel.setupGuideApp; steps marked complete from other_setup_screen.dart:424-426, add_payment_info_screen.dart:306-318, wallet_controller.dart:131-133
- Web — Yes — sidebar 'Setup Guide' with % complete: resources/views/layouts/vendor/partials/v2/_side-bar.blade.php:490-511 (and _rail.blade.php:108); steps marked via updateSetupGuideCacheKey (app/Utils/panel-helpers.php:79-120)
- Server — POST /api/v3/seller/update-setup-guide-app → SellerController::updateSetupGuideApp writes shops.setup_guide_app (routes/rest_api/v3/seller.php:99; SellerController.php:328-350) | web writes shops.setup_guide (panel-helpers.php:106-116)
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:183-192 + lib/main.dart:261-262; web app/Utils/panel-helpers.php:65-72,106-116 — NOTE the two sides use different columns (setup_guide_app vs setup_guide), so progress does not sync

**Choose the shop's shipping method type (order-wise / product-wise / category-wise)**  
`web: vendor auth; API: seller shipping routes`  
- App — Yes — lib/features/settings/screens/setting_screen.dart:36-40 → lib/features/settings/widgets/choose_shipping_dialog_widget.dart:60-72 (grid select) → ShippingController.setShippingMethodType
- Web — Yes — resources/views/vendor-views/shipping-method/index.blade.php:61-69 (select name="shippingCategory" with order_wise/category_wise/product_wise)
- Server — GET /api/v3/seller/shipping/selected-shipping-method?shipping_type= and /shipping/get-shipping-method (lib/utill/app_constants.dart:54-55) | web POST vendor/business-settings/shipping-type/index → ShippingTypeController::addOrUpdate (routes/vendor/routes.php:369)
- Evidence — flutter lib/features/shipping/domain/repositories/shipping_repository.dart:58-76; web routes/vendor/routes.php:368-370 + resources/views/vendor-views/shipping-method/index.blade.php:61-69

**Order-wise shipping methods: create, edit, delete, enable/disable**  
`web: vendor auth`  
- App — Yes — list lib/features/settings/screens/order_wise_shipping_list_screen.dart, add/edit lib/features/settings/screens/order_wise_shipping_add_screen.dart:84-128, status toggle + delete lib/features/settings/widgets/order_wise_shipping_card_widget.dart:37,73-94
- Web — Yes — resources/views/vendor-views/shipping-method/index.blade.php:100-128 (add), :147-190 (list, status switcher, edit) + update-view.blade.php
- Server — /api/v3/seller/shipping-method/{add,update,delete,list,status} (lib/utill/app_constants.dart:43-46,79) | web routes/vendor/routes.php:359-366 (ShippingMethodController index/add/update/update-status/delete)
- Evidence — flutter lib/features/shipping/domain/repositories/shipping_repository.dart:36-46,97-131; web routes/vendor/routes.php:359-366

**Category-wise shipping cost setup**  
`web: vendor auth`  
- App — Yes — CategoryWiseShippingScreen reached from lib/features/profile/widgets/theme_changer_widget.dart:55-63
- Web — Yes — resources/views/vendor-views/shipping-method/index.blade.php:228-238 ('category_wise_shipping_cost' with its own search)
- Server — GET /api/v3/seller/shipping/all-category-cost + POST /shipping/set-category-cost (app_constants.dart:52-53) | web POST vendor/business-settings/category-wise-shipping-cost/index → CategoryShippingCostController::index (routes/vendor/routes.php:372-374)
- Evidence — flutter lib/features/shipping/domain/repositories/shipping_repository.dart:48-95; web routes/vendor/routes.php:372-374

**Prompt to add the shop's first product when the catalogue is empty**  
`none`  
- App — Yes — lib/features/shop/widgets/shop_details_widget.dart:48-83 (dismissible warning when totalProducts < 1, deep-links into Add Product)
- Web — Yes — resources/views/vendor-views/shop/index.blade.php:16-21 (always-on note linking to vendor.products.add)
- Server — shop-info total_products (SellerController.php:68) | web shop index view data
- Evidence — flutter lib/features/shop/widgets/shop_details_widget.dart:48-83 (dismissal is in-memory only, ShopController.setShowAddProductWarning shop_controller.dart:593); web resources/views/vendor-views/shop/index.blade.php:16-21

**Seller Center hub (verification / scorecard / payouts / service standing entry points)**  
`varies (seller_can:payouts.request, finance.view)`  
- App — Yes — lib/features/menu/screens/more_screen.dart:83-88 (seller_center → ScorecardScreen, verification → VerificationScreen)
- Web — Yes — resources/views/vendor-views/marketplace/seller-center.blade.php:18-91 (verification, performance, finance, service standing cards)
- Server — /api/v3/seller/... Seller Center group (routes/rest_api/v3/seller.php:405+) | web routes/vendor/routes.php:388-400 (payouts, seller-verification, seller-scorecard)

## WEB MISSING (5)

**Permanently delete own seller account**  
`seller_owner` · wave 7  
- App — Yes — lib/features/profile/widgets/theme_changer_widget.dart:82-83 → SignOutConfirmationDialogWidget(isDelete:true) (lib/features/menu/widgets/sign_out_confirmation_dialog_widget.dart:73-85) → ProfileController.deleteCustomerAccount
- Web — No — not found. Searched routes/vendor/routes.php (no delete/account-delete route), resources/views/vendor-views/profile/*, resources/views/layouts/vendor/partials/_sign-out-modal.blade.php. Only the CUSTOMER storefront has one (routes/web/routes.php:305)
- Server — DELETE|GET /api/v3/seller/account-delete → SellerController::account_delete (routes/rest_api/v3/seller.php:91-92; SellerController.php:610-628) — exists, just not wired to the panel
- Evidence — flutter lib/features/profile/domain/repositories/profile_repository.dart:52-60 + lib/features/profile/controllers/profile_controller.dart:114-121; web: grep 'account-delete' over routes/vendor/routes.php and resources/views/vendor-views/ → no match

**Persist the chosen language on the seller account (survives device/browser change)**  
`none` · wave 7  
- App — Yes — every language switch calls language-change, which writes sellers.app_language
- Web — No — SharedController::changeLanguage only does session()->put('local', ...); nothing writes sellers.app_language from the panel
- Server — SellerController::language_change writes app_language (SellerController.php:757-764); no web counterpart
- Evidence — flutter lib/localization/controllers/localization_controller.dart:40 → lib/features/auth/controllers/auth_controller.dart:118-127 → auth_repository.dart:38-47; web app/Http/Controllers/SharedController.php:32 (session only — grep 'app_language' over app/Http/Controllers/SharedController.php and Vendor/ returns nothing)

**Upload the shop's SECONDARY (bottom) banner**  
`seller_can:shop_settings.manage` · wave 7  
- App — Yes — lib/features/shop/screens/shop_update_screen.dart:563-598 ('store_secondary_banner', authProvider.pickImage(secondary: true))
- Web — No — resources/views/vendor-views/shop/update-view.blade.php has no bottom_banner input (only image + banner). app/Http/Requests/Vendor/ShopRequest.php:41 validates a field called `bottomBanner` (camelCase) that no form posts and that ShopService never reads (it reads `bottom_banner`), so the rule is dead
- Server — EXISTS — PUT /api/v3/seller/shop-update accepts `bottom_banner` (app/Http/Requests/API/v3/ShopInfoUpdateRequest.php bottom_banner rule; SellerController.php:318) and web ShopService already persists it (app/Services/ShopService.php:41,51-52,100,111-112). Only the blade input is missing
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:49-52 (MultipartFile('bottom_banner')) + shop_update_screen.dart:588-598; web grep bottom_banner over resources/views/vendor-views/ → 0 hits, while app/Services/ShopService.php:41,51-52 reads it

**Upload the shop's OFFER banner**  
`seller_can:shop_settings.manage` · wave 7  
- App — Yes — lib/features/shop/screens/shop_update_screen.dart:630-649 ('offer_banner', authProvider.pickImage(offer: true))
- Web — No — no offer_banner input anywhere in resources/views/vendor-views/. ShopRequest.php:47 validates `offerBanner` (camelCase), which nothing posts and ShopService never reads
- Server — EXISTS — shop-update accepts `offer_banner` (SellerController.php:319) and ShopService persists it (app/Services/ShopService.php:42,53-54,101,113-114)
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:53-57 (MultipartFile('offer_banner')) + shop_update_screen.dart:643-649; web grep offer_banner over resources/views/vendor-views/ → 0 hits vs app/Services/ShopService.php:42,53-54

**Contextual 'Business Setup Guideline' help drawer per shop tab (shop details / payment info / other setup)**  
`none` · wave 7  
- App — Yes — lib/features/shop/widgets/my_shop_appbar.dart:64-82 opens lib/features/shop/widgets/business_setup_guideline.dart (expansion tiles of title+description)
- Web — No — not found. Searched resources/views/vendor-views/shop/*.blade.php and layouts/vendor/partials/offcanvas for a guideline/help drawer; only static intro paragraphs exist (shop/index.blade.php:16-20)
- Server — None — the copy is hardcoded client-side in lib/utill/app_constants.dart:336-350 (inHouseShopGuidelineList / paymentInfoGuidelineList / otherSetupGuidelineList), so it cannot be edited from admin

## APP MISSING (2)

**Language menu reflects the languages the admin actually enabled**  
`none`  
- App — No — the list is hardcoded in lib/utill/app_constants.dart:297-300 (Arabic + English only); enabling a third language in admin does not reach the app without a release
- Web — Yes — the header loops the configured language list and filters on status==1
- Server — business_settings `language` (admin System Setup → Language); web reads it via $v2Languages
- Evidence — flutter lib/utill/app_constants.dart:297-300 (static List<LanguageModel> languages = [ar, en]) consumed by lib/features/language/controllers/language_controller.dart:22,38; web resources/views/layouts/vendor/partials/v2/_header.blade.php:346-353

**Open the shop's public storefront page to see it as a customer does**  
`none`  
- App — No — not found. lib/features/shop/widgets/my_shop_appbar.dart:81 uses Images.storeWebIcon but opens the setup-guideline sheet, not the storefront; no vendor-shop URL launch anywhere in lib/features/shop
- Web — Yes — resources/views/vendor-views/shop/index.blade.php:173-174 ('Visit_Website' → route('vendor-shop', ['slug' => $shop['slug']]))
- Server — web storefront route vendor-shop (routes/web/routes.php)
- Evidence — flutter: grep 'vendor-shop|launchUrl' over lib/features/shop → no match (only lib/features/update/screen/update_screen.dart:57 launches a URL); web resources/views/vendor-views/shop/index.blade.php:173-174

## WEB ENHANCEMENT (1)

**Search the payment information list**  
`seller_can:finance.view`  
- App — No — the list is offset-paginated only (limit=10&offset=n), no search field on payment_info_screen
- Web — Yes — resources/views/vendor-views/shop/payment-information.blade.php:45 (input type=search name="search")
- Server — web Vendor\VendorPaymentInfoController::index handles `search`; the API list endpoint (routes/rest_api/v3/seller.php:393) takes limit/offset only
- Evidence — flutter lib/features/shop/domain/repositories/shop_repository.dart:134 ('?limit=10&offset=$offset'); web resources/views/vendor-views/shop/payment-information.blade.php:45

## DEVICE SPECIFIC (3)

**Dark / light theme toggle**  
`none`  
- App — Yes — lib/features/profile/widgets/theme_changer_widget.dart:41-49 (FlutterSwitch → ThemeController.toggleTheme)
- Web — No — not found. Searched resources/views/layouts/vendor/partials/v2/_header.blade.php and layouts/vendor/partials/* for dark/theme-switch: no match
- Server — none — stored device-locally in SharedPreferences key 'theme'
- Evidence — flutter lib/theme/controllers/theme_controller.dart:16 (sharedPreferences.setBool(AppConstants.theme,...)), app_constants.dart:276; web: grep dark|theme-switch over resources/views/layouts/vendor/ → no result

**See the installed app/panel version**  
`none`  
- App — Yes — lib/features/profile/screens/profile_view_screen.dart:151-154 and lib/features/menu/screens/more_screen.dart:220 ('v - ${AppVersion.current}')
- Web — No panel-facing version display found in vendor-views; app/Utils/version.php getAppVersion() is used only by the developer portal and monitoring panels
- Server — n/a for the app binary version
- Evidence — flutter lib/helper/version_helper.dart via more_screen.dart:220; web app/Utils/version.php:12 (consumed by app/Services/DeveloperPortal/ApiManifest.php:203-205, not by vendor-views)

**Forced app-update gate (blocks the app until the seller updates from the store)**  
`none`  
- App — Yes — lib/features/update/screen/update_screen.dart:48-61 (opens configModel.sellerAppVersionControl.forAndroid/forIos link)
- Web — N/A — a browser panel has no store binary to update
- Server — GET /api/v1/config → seller_app_version_control block (AppConstants.configUri, app_constants.dart:14)

## DEPRECATED (1)

**Business/discount package browsing (BusinessController + BusinessRepository)**  
`none`  
- App — Dead code — lib/features/settings/domain/repositories/business_repository.dart:33-40 returns a HARDCODED two-item list ('Superb Discount', 'New Discount'); registered in DI (lib/di_container.dart:297,455; lib/main.dart:179) but no screen consumes it
- Web — No equivalent, and none needed
- Server — none — no endpoint is called; add/update/delete/get all throw UnimplementedError

## BACKEND MISSING (2)

**Anti-hijack payout cooling window armed when payout bank details change**  
`seller_owner`  
- App — No — the app's bank edit goes through seller-update, which never calls PayoutService::recordBankChange
- Web — Yes — Vendor\ProfileController::updateBankInfo compares previous vs new and arms the hold
- Server — app/Services/Marketplace/PayoutService.php:296 recordBankChange — only caller is app/Http/Controllers/Vendor/ProfileController.php:135
- Evidence — web app/Http/Controllers/Vendor/ProfileController.php:125-140 (arms hold); API app/Http/Controllers/RestAPI/v3/seller/SellerController.php:352-390 writes bank_name/branch/account_no/holder_name with no recordBankChange call — grep for recordBankChange across app/ returns only those two hits

**Remove an already-uploaded TIN certificate (as opposed to replacing it)**  
`seller_can:shop_settings.manage`  
- App — Partial — lib/features/shop/screens/other_setup_screen.dart:284-292 only clears the locally picked file (ShopController.removeTinCertificateFile, shop_controller.dart:574); the stored file cannot be cleared
- Web — Partial — resources/views/vendor-views/shop/other-setup.blade.php:163 only warns 'delete the old file and upload a new one' — a replace flow, not a delete
- Server — No endpoint deletes tin_certificate on either side (SellerController.php:304-310 only overwrites when a new file is posted)
- Evidence — flutter lib/features/shop/controllers/shop_controller.dart:574-577; web resources/views/vendor-views/shop/other-setup.blade.php:163 — neither path sends a delete; grep tin_certificate in app/Http/Controllers shows only overwrite

