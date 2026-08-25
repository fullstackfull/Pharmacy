# Parity — growth_reviews

[← back to the matrix](../SELLER_WEB_APP_PARITY.md) · 47 capabilities

**35** BOTH · **4** WEB MISSING · **5** APP MISSING · **2** DEVICE SPECIFIC · **1** BACKEND MISSING

## Structural facts the implementer must know

```
SCOPE / FILE MAP
- Flutter: lib/features/coupon (10 files), lib/features/review (14), lib/features/seller_center (18), lib/features/ai (16). Endpoint ground truth is /home/user/sillercenter-syria-cosmatics/lib/utill/app_constants.dart:33-34, 67, 81, 104-118, 163-169, 232-240.
- Web: resources/views/vendor-views/{coupon,reviews}, vendor-views/marketplace/{seller-center,seller-scorecard,seller-verification,payouts}.blade.php, vendor-views/analytics/index.blade.php, vendor-views/product/partials/ai-sidebar.blade.php + add/update section partials.
- Backend: routes/rest_api/v3/seller.php:103-107 (reviews), :257-268 (coupon), :159 (per-product reviews), :411-441 (seller-center overview/scorecard/verification/analytics), :620-624 (payouts); Modules/AI/routes/api.php:23-34 (app) and Modules/AI/routes/vendor/routes.php:19-29 (web).

CLIENT-SIDE BUSINESS STATE: none found. The only SharedPreferences reference in this whole domain is an unused constructor field — CouponRepository takes `sharedPreferences` and never reads it (/home/user/sillercenter-syria-cosmatics/lib/features/coupon/domain/repositories/coupon_repository.dart:12,14). Every toggle in the domain (coupon status, review status, review-reply availability, AI availability, AI quota) is written to or read from the server. Two feature flags are read from the shared config endpoint and behave identically on both clients: `vendor_review_reply_status` (ConfigController.php:225 → config_model.dart:312) and the active-AI-provider check (`isAiFeatureActive` app-side / `getActiveAIProviderConfigCache()` web-side). Nothing to remediate here.

PERMISSION MODEL DIVERGENCE (affects every row above)
The mobile API declares fine-grained `seller_can:` gates per route; the web panel derives one permission from the URL's second segment in App\Http\Middleware\SellerStaffAccessMiddleware (routes/vendor/routes.php:84 wraps everything in ['seller','seller_staff_access']). Three concrete mismatches in this domain:
1. AI text auto-fills are GET on web (Modules/AI/routes/vendor/routes.php:21-26) so the middleware map (SellerStaffAccessMiddleware.php:85) resolves them to `products.view`, while the API requires `products.manage` (Modules/AI/routes/api.php:23). A staffer with read-only catalogue access can burn the shop's AI quota through the panel but not through the app.
2. `seller-center` is ALLOW for any active staff on web (SellerStaffAccessMiddleware.php:81) but gated on orders/products/finance permissions in the API (seller.php:416).
3. `/vendor/analytics` is not in the map at all, so SellerStaffAccessMiddleware.php:106 `default => DENY` 403s every staff member, while the API grants it on finance.view|orders.view (seller.php:435).

THE FOUR WEB GAPS, RANKED FOR IMPLEMENTATION
1. Analytics has no navigation entry (highest impact): the page and route are complete but unreachable — no link exists anywhere in resources/views/layouts/vendor. Fix is a sidebar item in the 'reports_&_analytics' group (_side-bar.blade.php:329) plus an 'analytics' entry in the middleware map, otherwise staff get a 403 the moment the link appears.
2. Shop-wide view→cart conversion rate on the analytics summary (data already in $data['summary']).
3. delivered/canceled/returned/failed counts on the scorecard (already in SellerScorecardService output, just not rendered).
4. Copy-coupon-code control in the coupon quick view / list.

THE FOUR APP GAPS
Coupon search (API already supports `?search=`, the repo just omits it), coupon order_count + coupon_bearer display (already parsed into the model), review-list pagination (endpoint already paginates; the app sends no limit/offset and silently caps at Laravel's default 15 — this is the most user-visible app defect in the domain), and Excel export for both coupons and reviews (no v3 seller endpoint exists for either, so this needs backend work first).

DEAD CODE — do not treat as a parity gap
resources/views/vendor-views/coupon/partials/_filter-offcanvas.blade.php is a complete filter UI that is (a) never @included — grep for `couponFilterOffcanvas` across resources/ and Modules/ matches only its own definition, (b) built with `name=""` on the date-type select and `value=""` on every checkbox, and (c) ignored by the controller, which passes only ['added_by','vendorId'] to getListWhere (CouponController.php:56-63). Coupon filtering does not exist on either client. Either wire it end to end or delete the file.

TWO BUGS FOUND WHILE READING (outside parity, worth fixing)
1. App: ai_repository.dart:105-114 `generatePricing` posts `'description' => langCode`, sending the language code where the backend expects the product description (AIProductController@pricingAndOthersAutoFill reads $request['description']). Pricing suggestions are being generated from the string "en".
2. Backend: ProductController@review_list (app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1937-1958) resolves the product by id alone with no `added_by`/`user_id` scoping, so any authenticated seller can read any product's reviews and reviewer names by changing the path id. The sibling deleteImage method a few lines below does scope correctly and reads as the intended pattern.

DELIBERATELY EXCLUDED
Delivery-man reviews (/api/v3/seller/delivery-man/reviews/{id}, app_constants.dart:94, lib/features/delivery_man/) belong to the delivery domain, not growth_reviews. Verification and payouts are included as two rows because they physically live in lib/features/seller_center, but they are finance/KYC capabilities and should be reconciled against that domain's audit rather than double-counted here.
```

## BOTH (35)

**List the shop's coupons (own + admin-issued seller-bearer coupons), paginated**  
`promotions.manage`  
- App — Yes — lib/features/coupon/screens/coupon_list_screen.dart (PaginatedListViewWidget, offset paging)
- Web — Yes — resources/views/vendor-views/coupon/index.blade.php table + pager
- Server — App: GET /api/v3/seller/coupon/list (routes/rest_api/v3/seller.php:260). Web: vendor.coupon.index → CouponController@index
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/coupon/screens/coupon_list_screen.dart:40,54-79 + domain/repositories/coupon_repository.dart:64-73 | web: /home/user/Pharmacy/resources/views/vendor-views/coupon/index.blade.php:141,268-273 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Coupon/CouponController.php:54-66

**Create a coupon (discount_on_purchase or free_delivery) with title, code, limit, discount type/value, min purchase, max discount, start/expire dates**  
`promotions.manage`  
- App — Yes — lib/features/coupon/screens/add_new_coupon_screen.dart (full form + client validation)
- Web — Yes — resources/views/vendor-views/coupon/index.blade.php inline add form
- Server — App: POST /api/v3/seller/coupon/store (seller.php:261). Web: POST vendor.coupon.add → CouponController@add
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/coupon/screens/add_new_coupon_screen.dart:77-263,287-338 | web: /home/user/Pharmacy/resources/views/vendor-views/coupon/index.blade.php:18-131 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Coupon/CouponController.php:74-82

**Target a coupon at one customer or at all customers, picking the customer via a searchable list**  
`promotions.manage`  
- App — Yes — CustomerSearchScreen(isCoupon:true) backed by GET /coupon/customers?name=
- Web — Yes — select2 customer dropdown preloaded by CustomerRepository::getListWhereNotIn([0])
- Server — App: GET /api/v3/seller/coupon/customers (seller.php:267). Web: server-rendered $customers in CouponController@getAddListView
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/coupon/screens/add_new_coupon_screen.dart:104-118 + controllers/coupon_controller.dart:161-180 + domain/repositories/coupon_repository.dart:32-41 | web: /home/user/Pharmacy/resources/views/vendor-views/coupon/index.blade.php:52-64 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Coupon/CouponController.php:64

**Auto-generate a random coupon code**  
`promotions.manage`  
- App — Yes — client-side 10-char random generator on the 'generate_code' tap
- Web — Yes — #generateCode link in the add form
- Server — none (client-side on both)
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/coupon/screens/add_new_coupon_screen.dart:138-149 | web: /home/user/Pharmacy/resources/views/vendor-views/coupon/index.blade.php:42-43

**Edit an existing coupon**  
`promotions.manage`  
- App — Yes — popup menu 'edit' → AddNewCouponScreen(coupons:) prefilled
- Web — Yes — vendor.coupon.update view (update-view.blade.php)
- Server — App: PUT /api/v3/seller/coupon/update/{id} (seller.php:262). Web: GET/POST vendor.coupon.update → CouponController@getUpdateView/@update
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/coupon/widgets/coupon_card_widget.dart:98-104 + domain/repositories/coupon_repository.dart:81-100 | web: /home/user/Pharmacy/resources/views/vendor-views/coupon/index.blade.php:231-233 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Coupon/CouponController.php:89-119

**Delete a coupon**  
`promotions.manage`  
- App — Yes — popup menu 'delete' with loader
- Web — Yes — trash action posting the DELETE form
- Server — App: DELETE /api/v3/seller/coupon/delete/{id} (seller.php:264). Web: DELETE vendor.coupon.delete → CouponController@delete
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/coupon/widgets/coupon_card_widget.dart:90-97 + domain/repositories/coupon_repository.dart:45-56 | web: /home/user/Pharmacy/resources/views/vendor-views/coupon/index.blade.php:236-243 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Coupon/CouponController.php:147-157

**Enable/disable a coupon (status toggle)**  
`promotions.manage`  
- App — Yes — FlutterSwitch inside the card popup menu
- Web — Yes — switcher with a confirm modal
- Server — App: PUT /api/v3/seller/coupon/status-update/{id} (seller.php:263). Web: GET vendor.coupon.update-status/{id}/{status} → CouponController@updateStatus
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/coupon/widgets/coupon_card_widget.dart:107-141 + controllers/coupon_controller.dart:100-109 | web: /home/user/Pharmacy/resources/views/vendor-views/coupon/index.blade.php:192-212 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Coupon/CouponController.php:128-140

**Block edit/delete/status changes on admin-owned coupons the seller merely bears**  
`promotions.manage`  
- App — Yes — adminCoupon guard disables the three actions and shows 'coupon_tooltip'
- Web — Yes — disabled switch + disabled edit/trash icons with an explanatory tooltip
- Server — App: CouponController@status_update/@delete ownership check. Web: CouponController@updateStatus scopes on added_by/coupon_bearer/seller_id
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/coupon/widgets/coupon_card_widget.dart:32-38,91-104,113-135 | web: /home/user/Pharmacy/resources/views/vendor-views/coupon/index.blade.php:161-166,213-253 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Coupon/CouponController.php:130-134

**View a single coupon's details (discount, min purchase, max discount, user limit, dates, audience)**  
`promotions.manage`  
- App — Yes — CouponDetailsDialogWidget opened by tapping the card
- Web — Yes — AJAX quick-view modal
- Server — App: data already in /coupon/list payload. Web: GET vendor.coupon.quick-view → CouponController@getQuickView
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/coupon/widgets/coupon_details_dialog_widget.dart:96-103 + screens/coupon_list_screen.dart:67-70 | web: /home/user/Pharmacy/resources/views/vendor-views/coupon/quick-view.blade.php:11-47 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Coupon/CouponController.php:165-171

**Validate/apply a coupon code against a POS cart**  
`promotions.manage (API) / orders.manage (web pos segment)`  
- App — Yes — AppConstants.getCouponDiscount used from the POS cart flow
- Web — Yes — POS coupon-discount route
- Server — App: POST /api/v3/seller/coupon/check-coupon (seller.php:266). Web: POST vendor.pos.coupon-discount → POSController@getCouponDiscount
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/utill/app_constants.dart:67 | web: /home/user/Pharmacy/routes/vendor/routes.php:120

**List all product reviews left on the shop's products**  
`reviews.view`  
- App — Yes — ProductReviewScreen list of ReviewWidget cards
- Web — Yes — vendor-views/reviews/index.blade.php table
- Server — App: GET /api/v3/seller/shop-product-reviews (seller.php:104) → SellerController@shop_product_reviews. Web: vendor.reviews.index → ReviewController@index
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/review/screens/product_review_screen.dart:58,99-114 + domain/repositories/product_review_repository.dart:12-21 | web: /home/user/Pharmacy/resources/views/vendor-views/reviews/index.blade.php:18-48 + /home/user/Pharmacy/app/Http/Controllers/Vendor/ReviewController.php:46-121

**Search reviews by product name / reviewer name**  
`reviews.view`  
- App — Yes — debounced search field sending ?search=
- Web — Yes — data-view searchValue, resolved to product ids + customer ids
- Server — Same endpoint both sides: shop-product-reviews?search= (SellerController@shop_product_reviews:92-106) / ReviewController@index:60-77
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/review/screens/product_review_screen.dart:76-95 + domain/repositories/product_review_repository.dart:65-73 | web: /home/user/Pharmacy/resources/views/vendor-views/reviews/index.blade.php:19-20 + /home/user/Pharmacy/app/Http/Controllers/Vendor/ReviewController.php:60-77

**Filter reviews by product, by customer, by status (active/inactive) and by date range, with a clear-filter reset**  
`reviews.view`  
- App — Yes — ReviewFilterBottomSheetWidget with product picker, customer picker, status dropdown, from/to date pickers, clear + apply
- Web — Yes — reviewFilterOffcanvas with the same four filters + Clear Filter
- Server — App: GET shop-product-reviews?product_id&customer_id&status&from&to (SellerController@shop_product_reviews:130-145). Web: ReviewController@index:49-56 builds the same filter array
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/review/widgets/review_filter_bottom_sheet_widget.dart:85-93,96-122,125-142,145-162,169-206 + domain/repositories/product_review_repository.dart:23-62 | web: /home/user/Pharmacy/resources/views/vendor-views/reviews/partials/_filter-offcanvas.blade.php:29-114,118-127 + /home/user/Pharmacy/app/Http/Controllers/Vendor/ReviewController.php:49-56

**Show or hide a review on the storefront (review status toggle)**  
`reviews.view`  
- App — Yes — app-bar switch on the review reply / full-view screens
- Web — Yes — switcher in the list and on the product view page, with a confirm modal
- Server — App: GET /api/v3/seller/shop-product-reviews-status?id&status (seller.php:105) → SellerController@shop_product_reviews_status. Web: GET vendor.reviews.update-status/{id}/{status} → ReviewController@updateStatus
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/review/screens/review_reply_widget.dart:43-55 and screens/review_full_view_screen.dart:19-28 + controllers/product_review_controller.dart:175-188 | web: /home/user/Pharmacy/resources/views/vendor-views/reviews/index.blade.php:122-139 + /home/user/Pharmacy/app/Http/Controllers/Vendor/ReviewController.php:128-135

**Open a review in full: rating, comment, order id, reviewer, product, timestamp**  
`reviews.view`  
- App — Yes — ReviewReplyScreen header block / ReviewFullViewScreen
- Web — Yes — #review-view-for-{id} modal
- Server — Data already in the list payload on both sides
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/review/screens/review_reply_widget.dart:111-141 and widgets/review_widget.dart:45-141 | web: /home/user/Pharmacy/resources/views/vendor-views/reviews/_review-modal.blade.php:10-67 + index.blade.php:143-147

**View the customer's review photo attachments full-size**  
`reviews.view`  
- App — Yes — horizontal thumbnail strip, tap opens ImageDialogWidget
- Web — Yes — thumbnails with lightbox in both the list cell and the modals
- Server — attachment_full_url returned by both (SellerController@shop_product_reviews:148-152)
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/review/screens/review_reply_widget.dart:144-172 | web: /home/user/Pharmacy/resources/views/vendor-views/reviews/index.blade.php:94-105 and _review-modal.blade.php:69-80

**Reply to a customer review, or update an existing reply — only when the admin has enabled vendor review replies**  
`reviews.view`  
- App — Yes — reply textarea + submit/update, gated on configModel.reviewReplyStatus
- Web — Yes — reply modal + submit/update, gated on getWebConfig('vendor_review_reply_status')
- Server — App: POST /api/v3/seller/shop-product-reviews-reply (seller.php:106) → SellerController@shopProductReviewReply (updateOrInsert semantics). Web: POST vendor.reviews.add-review-reply → ReviewController@addReviewReply. Same flag source: ConfigController.php:225
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/review/screens/review_reply_widget.dart:177-222 + controllers/product_review_controller.dart:250-269 + lib/features/splash/domain/models/config_model.dart:312 | web: /home/user/Pharmacy/resources/views/vendor-views/reviews/index.blade.php:16,148-154,191-247 + /home/user/Pharmacy/app/Http/Controllers/Vendor/ReviewController.php:200-212

**See one product's reviews with its average rating and star-by-star distribution**  
`products.view / reviews.view`  
- App — Yes — product details review section paginating products/review-list/{id} and rendering group-wise rating + average
- Web — Yes — product view page reviews tab with the star-distribution popover and a paginated review table
- Server — App: GET /api/v3/seller/products/review-list/{id} (seller.php:159) → ProductController@review_list. Web: vendor.products.view (server-rendered $reviews + getRatingCount())
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/review/domain/repositories/product_review_repository.dart:88-97 + lib/features/product_details/widgets/product_details_review_widget.dart:85-163,221 | web: /home/user/Pharmacy/resources/views/vendor-views/product/view.blade.php:236-330,720-810,1038

**See the seller-center overview: verification standing, performance tier, withdrawable balance and open SLA breaches in one glance**  
`API: any of orders.view/orders.manage/products.view/products.manage/finance.view; web: ALLOW for any active staff`  
- App — Yes — SellerCenterCardWidget on the home tab
- Web — Yes — vendor-views/marketplace/seller-center.blade.php four-card hub
- Server — App: GET /api/v3/seller/seller-center/overview (seller.php:417). Web: vendor.business-settings.seller-center.index → Marketplace\SellerCenterController@index
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/seller_center/widgets/seller_center_card_widget.dart:60-78 + domain/repositories/seller_center_repository.dart:14-22 | web: /home/user/Pharmacy/resources/views/vendor-views/marketplace/seller-center.blade.php:22-96 + /home/user/Pharmacy/routes/vendor/routes.php:417-420

**See the full performance scorecard — health tier, orders, fulfilment/cancellation/return/refund rates, rating, review count, moderation strikes, KYC state**  
`API: orders.view|orders.manage|products.view|products.manage|finance.view; web: ALLOW`  
- App — Yes — ScorecardScreen with rate bars and risk colouring
- Web — Yes — seller-scorecard.blade.php tile grid
- Server — App: GET /api/v3/seller/seller-center/scorecard (seller.php:418). Web: vendor.business-settings.seller-scorecard.index → Marketplace\SellerScorecardController@index (same SellerScorecardService)
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/seller_center/screens/scorecard_screen.dart:67-108 + domain/models/seller_center_models.dart:97-116 | web: /home/user/Pharmacy/resources/views/vendor-views/marketplace/seller-scorecard.blade.php:24-45 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Marketplace/SellerScorecardController.php:20-25

**See what is waiting right now: unchecked new orders and products awaiting restock**  
`API: finance.view|orders.view; web: dashboard → ALLOW`  
- App — Yes — activities strip on the seller-center home card, polled on every home load
- Web — Yes — dashboard real-time-activities poll (new_order_count + restockProductCount + a restock CTA)
- Server — App: GET /api/v3/seller/seller-center/analytics/activities (seller.php:438) → SellerAnalyticsController@activities. Web: GET vendor.dashboard.real-time-activities → DashboardController@getRealTimeActivities
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/seller_center/widgets/seller_center_card_widget.dart:82-84,136-145 + lib/features/home/screens/home_page_screen.dart:55 | web: /home/user/Pharmacy/app/Http/Controllers/Vendor/DashboardController.php:372,404-409 + /home/user/Pharmacy/routes/vendor/routes.php:112

**See storefront analytics for a chosen window — visitors, sessions, product views, cart adds, orders, revenue — with a range picker**  
`API: finance.view|orders.view; web: /vendor/analytics is UNMAPPED in SellerStaffAccessMiddleware → DENY for staff`  
- App — Yes — AnalyticsScreen with a horizontal range-chip picker and six metric cards
- Web — Yes — vendor-views/analytics/index.blade.php with range links and a six-metric row
- Server — App: GET /api/v3/seller/seller-center/analytics?range= (seller.php:437) → SellerAnalyticsController@index. Web: GET vendor.analytics.index?range= → Vendor\AnalyticsController@index (same AnalyticsReporting service)
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/seller_center/screens/analytics_screen.dart:59-68,84-103 + domain/repositories/seller_center_repository.dart:104-114 | web: /home/user/Pharmacy/resources/views/vendor-views/analytics/index.blade.php:13-22,36-48 + /home/user/Pharmacy/app/Http/Controllers/Vendor/AnalyticsController.php:53-74

**See which products get the most views and how many of those views turn into cart adds**  
`API: finance.view|orders.view; web: staff DENY (unmapped)`  
- App — Yes — 'most_viewed_products' list with events/visitors/views/cart adds
- Web — Yes — 'your_products' table with views / visitors / added_to_cart / view_to_cart
- Server — Same reporting service both sides (products array from AnalyticsReporting::forVendor)
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/seller_center/screens/analytics_screen.dart:118-130 + domain/models/analytics_models.dart:81-107 | web: /home/user/Pharmacy/resources/views/vendor-views/analytics/index.blade.php:50-77

**AI-generate a product title from a rough name (per language)**  
`API: products.manage; web: 'product' GET → products.view`  
- App — Yes — auto_fill title with the active language tab's langCode
- Web — Yes — 'Generate' button beside each language's name field
- Server — App: POST /api/v3/seller/product/title-auto-fill (Modules/AI/routes/api.php:25). Web: GET vendor.product.title-auto-fill (Modules/AI/routes/vendor/routes.php:21) → AIProductController@titleAutoFill
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/ai/domain/repositories/ai_repository.dart:16-33 + controllers/ai_controller.dart:102-119 | web: /home/user/Pharmacy/resources/views/vendor-views/product/add/_title-description.blade.php:25-27 + /home/user/Pharmacy/Modules/AI/routes/vendor/routes.php:21

**AI-generate a product description (per language)**  
`API: products.manage; web: products.view`  
- App — Yes — auto_fill description, HTML stripped to plain text before filling the field
- Web — Yes — 'Generate' button beside each language's description editor
- Server — App: POST product/description-auto-fill (api.php:26). Web: GET vendor.product.description-auto-fill (vendor/routes.php:22)
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/ai/domain/repositories/ai_repository.dart:35-50 + controllers/ai_controller.dart:122-142 | web: /home/user/Pharmacy/resources/views/vendor-views/product/add/_title-description.blade.php:60-61

**AI-fill the general setup section (category/brand/unit and friends) from title + description**  
`API: products.manage; web: products.view`  
- App — Yes — generateGeneralSetup feeding AddProductController
- Web — Yes — 'Generate' button on the General Setup card
- Server — App: POST product/general-setup-auto-fill (api.php:27). Web: GET vendor.product.general-setup-auto-fill (vendor/routes.php:23) → AIProductController@generalSetupAutoFill
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/ai/domain/repositories/ai_repository.dart:52-67 + controllers/ai_controller.dart:147-163 | web: /home/user/Pharmacy/resources/views/vendor-views/product/add/_general-setup.blade.php:14-16

**AI-fill pricing & other fields (unit price, discount, stock, min order qty, shipping cost, VAT, multiply flag)**  
`API: products.manage; web: products.view`  
- App — Yes — generatePricing writes straight into the pricing controllers and toggles discount type / multiply
- Web — Yes — 'Generate' button on the Pricing & Others card
- Server — App: POST product/price-others-auto-fill (api.php:28). Web: GET vendor.product.price-others-auto-fill (vendor/routes.php:24) → AIProductController@pricingAndOthersAutoFill
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/ai/domain/repositories/ai_repository.dart:105-122 + controllers/ai_controller.dart:166-223 | web: /home/user/Pharmacy/resources/views/vendor-views/product/add/_pricing-others.blade.php:12-16 + /home/user/Pharmacy/Modules/AI/app/Http/Controllers/API/V3/AIProductController.php:77-89

**AI-fill the variation setup (colours, choice attributes, generated variations)**  
`API: products.manage; web: products.view`  
- App — Yes — generateVariationSetup pushing into VariationController
- Web — Yes — 'Generate' button on the variation card (add and update)
- Server — App: POST product/variation-setup-auto-fill (api.php:30). Web: GET vendor.product.variation-setup-auto-fill (vendor/routes.php:26)
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/ai/domain/repositories/ai_repository.dart:124-138 + controllers/ai_controller.dart:226-250 | web: /home/user/Pharmacy/resources/views/vendor-views/product/add/_product-variation-setup.blade.php:14-16 and update/_product-variation-setup.blade.php:14-15

**AI-fill the SEO / meta section (meta title, meta description)**  
`API: products.manage; web: products.view`  
- App — Yes — generateMetaSeoSetup filling the SEO controllers and updating AddProductController
- Web — Yes — 'Generate' button on the SEO card
- Server — App: POST product/seo-section-auto-fill (api.php:29). Web: GET vendor.product.seo-section-auto-fill (vendor/routes.php:25)
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/ai/domain/repositories/ai_repository.dart:140-155 + controllers/ai_controller.dart:253-292 | web: /home/user/Pharmacy/resources/views/vendor-views/product/add/_seo-section.blade.php:14-15

**Get AI title suggestions from a list of keywords**  
`products.manage`  
- App — Yes — GenerateTitleBottomSheet collects keyword chips, joins them and posts them
- Web — Yes — 'give a title' pane in the AI sidebar with a keyword input and generate button
- Server — App: POST product/generate-title-suggestions (api.php:32). Web: POST vendor.product.generate-title-suggestions (vendor/routes.php:28) → AIProductController@generateProductTitleSuggestion
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/ai/widgets/generate_title_bottom_sheet.dart:113-120 + controllers/ai_controller.dart:295-331 + domain/repositories/ai_repository.dart:69-83 | web: /home/user/Pharmacy/resources/views/vendor-views/product/partials/ai-sidebar.blade.php:150-192

**Create product copy by uploading a photo and letting AI read it**  
`products.manage`  
- App — Yes — ImageAnalyzeBottomSheet picks from gallery, compresses to q10/800px, uploads and fills the name field
- Web — Yes — upload-image pane in the AI sidebar posting to analyze-image-auto-fill
- Server — App: POST product/analyze-image-auto-fill (api.php:31). Web: POST vendor.product.analyze-image-auto-fill (vendor/routes.php:27) → AIProductController@generateTitleFromImages
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/ai/domain/repositories/ai_repository.dart:85-103 + controllers/ai_controller.dart:339-366,383-401,415-439 | web: /home/user/Pharmacy/resources/views/vendor-views/product/partials/ai-sidebar.blade.php:71-131

**See how many AI generations are left, refreshed after every generation**  
`products.manage`  
- App — Yes — GeneratesLeftCount badge fed by generateLimitCheck(), re-polled at the end of every generate call
- Web — Yes — server-rendered $aiRemainingCount in the header, updated live from response.data.remaining_count
- Server — App: GET /api/v3/seller/product/generate-limit-check (api.php:33) → AIProductController@generateLimitCheck. Web: no dedicated route — the count is seeded by ProductController and refreshed from each auto-fill response
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/ai/widgets/genertate_count_widget.dart:15,32 + controllers/ai_controller.dart:369-380 (called at :114,138,159,219,246,284,327,361) | web: /home/user/Pharmacy/resources/views/vendor-views/product/add-new.blade.php:23 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Product/ProductController.php:212-213 + /home/user/Pharmacy/public/assets/backend/vendor/js/AI/products/product-title-autofill.js:35-37

**Hide the whole AI assistant when the marketplace has no active AI provider**  
`products.manage`  
- App — Yes — gated on configModel.isAiFeatureActive == 1
- Web — Yes — every generate button wrapped in @if(getActiveAIProviderConfigCache())
- Server — App: flag comes from the config endpoint. Web: getActiveAIProviderConfigCache() (AIProviderManager-backed)
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/ai/widgets/genertate_count_widget.dart:15 | web: /home/user/Pharmacy/resources/views/vendor-views/product/add/_pricing-others.blade.php:12 and add/_general-setup.blade.php:13

**Submit KYC identity/business documents and watch each one's review state (cross-domain: lives in lib/features/seller_center)**  
`seller_owner (API); web: business-settings → shop_settings.manage`  
- App — Yes — VerificationScreen + SubmitDocumentSheetWidget (type dropdown, document number, expiry, camera/gallery file)
- Web — Yes — vendor-views/marketplace/seller-verification.blade.php
- Server — App: GET/POST /api/v3/seller/seller-center/verification[/submit] (seller.php:423-431, seller_owner). Web: vendor.business-settings.seller-verification.* → Marketplace\SellerVerificationController
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/seller_center/screens/verification_screen.dart:31-160 + widgets/submit_document_sheet_widget.dart:96-190 + domain/repositories/seller_center_repository.dart:34-75 | web: /home/user/Pharmacy/routes/vendor/routes.php:399-405 + /home/user/Pharmacy/resources/views/vendor-views/marketplace/seller-verification.blade.php

**Request a payout against the withdrawable ledger balance, and cancel a pending request (cross-domain: lives in lib/features/seller_center)**  
`payouts.request`  
- App — Yes — PayoutsScreen balances + RequestPayoutSheetWidget (amount, MAX, payout currency) + cancel
- Web — Yes — vendor-views/marketplace/payouts.blade.php
- Server — App: GET/POST /api/v3/seller/seller-center/payouts and POST {id}/cancel (seller.php:620-624). Web: vendor.business-settings.payouts.index/store/cancel → Marketplace\PayoutController
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/seller_center/screens/payouts_screen.dart:70-103 + widgets/request_payout_sheet_widget.dart:63-146 + domain/repositories/seller_center_repository.dart:77-133 | web: /home/user/Pharmacy/routes/vendor/routes.php:390-396 + /home/user/Pharmacy/resources/views/vendor-views/marketplace/payouts.blade.php

## WEB MISSING (4)

**Copy a coupon code to the clipboard so it can be sent to a customer**  
`promotions.manage` · wave 6  
- App — Yes — tap-to-copy on both the list card and the details dialog, with confirmation snackbar
- Web — No — quick-view renders the code as plain text (#coupon_code) with no copy control; grep for clipboard/copy in vendor-views/coupon returns nothing
- Server — none (client-side)
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/coupon/widgets/coupon_card_widget.dart:236-257 and widgets/coupon_details_dialog_widget.dart:66-89 | web: not found — /home/user/Pharmacy/resources/views/vendor-views/coupon/quick-view.blade.php:12 renders the code as a bare span; also absent from index.blade.php:178

**See the raw order-outcome counts behind the scorecard rates (delivered / canceled / returned / failed)**  
`API: orders.view|…|finance.view; web: ALLOW` · wave 6  
- App — Yes — dedicated detail rows under the rate bars
- Web — No — the tile grid renders only the percentages, orders_total, rating, strikes and KYC; delivered/canceled/returned/failed are in the payload but never printed
- Server — Both served by SellerScorecardService::scorecard() which returns delivered/canceled/returned/failed
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/seller_center/screens/scorecard_screen.dart:99-102 | web: not found — /home/user/Pharmacy/resources/views/vendor-views/marketplace/seller-scorecard.blade.php:24-33 lists only the 8 tiles; the data exists at /home/user/Pharmacy/app/Services/Marketplace/SellerScorecardService.php:61-64

**See the shop-wide view→cart conversion rate for the window**  
`API: finance.view|orders.view; web: staff DENY (unmapped)` · wave 6  
- App — Yes — a dedicated 'view_to_cart_rate' card computed from summary.cartAdds/summary.productViews
- Web — No — the per-product column exists but there is no shop-level conversion figure anywhere on the analytics page
- Server — Derivable from the same summary payload on both sides; no extra endpoint needed
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/seller_center/screens/analytics_screen.dart:105-116 + domain/models/analytics_models.dart:78 | web: not found — /home/user/Pharmacy/resources/views/vendor-views/analytics/index.blade.php:36-48 has no rate row; only the per-product cell at :68-72

**Reach the analytics report from the panel navigation**  
`API: finance.view|orders.view; web: none reachable` · wave 6  
- App — Yes — 'analytics' entry in the More menu under finance_and_reports
- Web — No — vendor/analytics has a route and a view but no sidebar/menu link anywhere in resources/views/layouts/vendor, so it is URL-only; and the staff middleware refuses the 'analytics' segment by default
- Server — Route exists: GET vendor.analytics.index (routes/vendor/routes.php:101)
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/menu/screens/more_screen.dart:148-150 | web: not found — grep 'vendor.analytics.index' across /home/user/Pharmacy/resources/views/layouts and vendor-views matches only /home/user/Pharmacy/resources/views/vendor-views/analytics/index.blade.php:16 (the page's own range links); /home/user/Pharmacy/app/Http/Middleware/SellerStaffAccessMiddleware.php:106 default => DENY

## APP MISSING (5)

**Search the coupon list by title / code / discount type**  
`promotions.manage`  
- App — No — CouponListScreen has no search field and CouponRepository.getList only sends limit+offset even though the API supports `search`
- Web — Yes — x-k.data-view searchName=searchValue wired to CouponRepository::getListWhere($searchValue)
- Server — GET /api/v3/seller/coupon/list?search= is implemented (CouponController@list) — the app just never sends it
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/coupon/domain/repositories/coupon_repository.dart:64-73 (no search param) and screens/coupon_list_screen.dart:46-93 (no search widget) | web+api: /home/user/Pharmacy/resources/views/vendor-views/coupon/index.blade.php:141-143 and /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/CouponController.php:21-30

**See how many times a coupon has actually been redeemed (order_count) and who bears the discount**  
`promotions.manage`  
- App — Partial — Coupons.orderCount is parsed but neither the card nor the details dialog renders it, and couponBearer is used only for the admin guard
- Web — Yes — 'Total_Used' and 'discount_bearer' columns in the list
- Server — Both served: API list uses withCount('order') (CouponController@list:34); web reads $coupon['order_count'] / coupon_bearer
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/coupon/domain/models/coupon_model.dart:94,117 (parsed) vs widgets/coupon_card_widget.dart:181-259 and widgets/coupon_details_dialog_widget.dart:96-103 (never displayed) | web: /home/user/Pharmacy/resources/views/vendor-views/coupon/index.blade.php:187-190

**Export the coupon list to Excel**  
`promotions.manage`  
- App — No — no export action anywhere in lib/features/coupon and no export endpoint in AppConstants
- Web — Yes — 'export' button → CouponListExport xlsx
- Server — Web only: GET vendor.coupon.export → CouponController@exportList. No v3 seller API equivalent (grep of routes/rest_api/v3/seller.php coupon group shows list/store/update/status-update/delete/check-coupon/customers only)
- Evidence — flutter: not found — /home/user/sillercenter-syria-cosmatics/lib/utill/app_constants.dart:104-110 has no coupon export URI; lib/features/coupon/screens/coupon_list_screen.dart has no export action | web: /home/user/Pharmacy/resources/views/vendor-views/coupon/index.blade.php:146-149 + /home/user/Pharmacy/app/Http/Controllers/Vendor/Coupon/CouponController.php:173-185

**Page through the shop's full review history**  
`reviews.view`  
- App — No — the repository calls shop-product-reviews with no limit/offset, so the app silently shows only Laravel's default first page (15) and the controller has no paging state for _reviewList
- Web — Yes — paginated with getWebConfig('pagination_limit') and a pager that preserves filters
- Server — Endpoint already paginates: SellerController@shop_product_reviews:145 paginate($request['limit'],'page',$request['offset']) — the app just never sends the params
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/review/domain/repositories/product_review_repository.dart:12-21 (no query params) and controllers/product_review_controller.dart:84-102 (no offset tracking; contrast getProductWiseReviewList:218-243 which does paginate) | web: /home/user/Pharmacy/app/Http/Controllers/Vendor/ReviewController.php:88-91 + resources/views/vendor-views/reviews/index.blade.php:168-177

**Export the filtered review list to Excel**  
`reviews.view`  
- App — No — no export action and no export URI in AppConstants
- Web — Yes — export button carrying the active filters → CustomerReviewListExport
- Server — Web only: GET vendor.reviews.export → ReviewController@exportList. No v3 seller API equivalent (seller.php:103-107 exposes only list/status/reply)
- Evidence — flutter: not found — /home/user/sillercenter-syria-cosmatics/lib/utill/app_constants.dart:33-34,118 has no review export URI; lib/features/review/screens/product_review_screen.dart has no export control | web: /home/user/Pharmacy/resources/views/vendor-views/reviews/index.blade.php:23-26 + /home/user/Pharmacy/app/Http/Controllers/Vendor/ReviewController.php:141-198

## DEVICE SPECIFIC (2)

**Pull-to-refresh the scorecard / analytics without leaving the screen**  
`API: orders.view|…|finance.view`  
- App — Yes — RefreshIndicator on both screens; the scorecard refresh calls the lighter /scorecard endpoint after the first full overview load
- Web — N/A — page reload is the web equivalent
- Server — GET seller-center/scorecard and seller-center/analytics
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/seller_center/screens/scorecard_screen.dart:33-41 and screens/analytics_screen.dart:34-38 | web: no equivalent needed — /home/user/Pharmacy/resources/views/vendor-views/marketplace/seller-scorecard.blade.php is a plain server-rendered page

**Capture the product photo with the device camera for AI analysis**  
`products.manage`  
- App — Partial — AiController.pickImage hardcodes ImageSource.gallery, so there is no camera path in the AI flow (the KYC sheet does offer camera)
- Web — N/A — file upload input
- Server — Same analyze-image-auto-fill endpoint
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/ai/controllers/ai_controller.dart:339-350 (gallery only) vs lib/features/seller_center/widgets/submit_document_sheet_widget.dart:140-147 (camera/gallery pair) | web: /home/user/Pharmacy/resources/views/vendor-views/product/partials/ai-sidebar.blade.php:71-131

## BACKEND MISSING (1)

**Filter coupons by date range / coupon type / discount type**  
`promotions.manage`  
- App — No — no coupon filter UI at all
- Web — Dead UI — a full filter offcanvas blade exists but is never @included by index.blade.php, its select has name="", its checkboxes have value="", and the controller never reads from/to/coupon_type/discount_type
- Server — none — CouponController@getAddListView passes only ['added_by','vendorId'] to getListWhere; no filter params are honoured
- Evidence — web: /home/user/Pharmacy/resources/views/vendor-views/coupon/partials/_filter-offcanvas.blade.php:1-113 (grep for 'couponFilterOffcanvas' across resources+Modules matches only this file) and /home/user/Pharmacy/app/Http/Controllers/Vendor/Coupon/CouponController.php:56-63 | flutter: not found — lib/features/coupon/controllers/coupon_controller.dart has no filter state

