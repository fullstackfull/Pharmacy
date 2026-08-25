# Parity — products

[← back to the matrix](../SELLER_WEB_APP_PARITY.md) · 56 capabilities

**33** BOTH · **9** WEB MISSING · **8** APP MISSING · **4** APP ADAPTATION · **1** DEVICE SPECIFIC · **1** DEPRECATED

## Structural facts the implementer must know

```
SCOPE / WHERE THINGS LIVE

- Flutter product domain is spread over seven feature folders, not the four named in the brief: lib/features/product (list, filters, stock-out, top-selling), lib/features/addProduct (the 3-tab create/edit wizard: general info → variations → SEO), lib/features/product_details (3 tabs: details / reviews / STOCK), lib/features/clearance_sale, lib/features/barcode, plus lib/features/inventory and lib/features/bulk_jobs, which are pure product-domain work (stock ledger, stock correction, bulk price/stock edits) even though they sit outside the four folders. lib/features/restock is also product domain (restock requests). Any parity plan that only covers the four named folders will miss the entire WEB MISSING block.

- Two different API surfaces back the app's product list: the catalogue read is GET /api/v3/seller/products/{seller_id}/all-products (routes/rest_api/v3/seller.php:696) — declared in a SEPARATE route group after the main products group — while every write is under routes/rest_api/v3/seller.php:153-190. The generic GET /products/list (line 155) exists but the Flutter app never calls it.

THE WEB MISSING BLOCK, RANKED

1. Inventory ledger + auditable stock correction (5 rows). This is the single biggest gap and it is not cosmetic: the web's only stock write, Vendor/Product/ProductController::updateQuantity (line 788), overwrites current_stock with no reason, no note and no movement row. The app writes through POST /seller-center/inventory/products/{id}/adjust with a REQUIRED reason (stock_adjust_sheet.dart:170 blocks submit until one is chosen). So the same seller acting on the web silently breaks the ledger the app shows. Implementing the web side means adding the reason/note path, not just a new screen.
2. Bulk jobs (3 rows). Backend is complete and permission-split (price → products.manage, stock → inventory.manage, routes/rest_api/v3/seller.php:677-678). The web has nothing; bulk-import is a different capability (create from spreadsheet, not re-price a selection).
3. Restock list filters (1 row) — and note this one is a live BUG, not just a gap: _restock-list-filter-offcanvas.blade.php names its date inputs `from`/`to` while getRequestRestockListView reads `restock_date` (Vendor/Product/ProductController.php:149), and request-restock-list.blade.php:146 calls changeInputTypeForDateRangePicker on an input named restock_date that does not exist in the DOM. brand_id / category_id / sub_category_id are already accepted by the controller (lines 142-144) and the "filter active" badge already checks for them (blade line 27) — the inputs were simply never built.
4. Author / publishing-house list filters (2 rows) — backend supports them on the API side (author_ids, publishing_house_ids) but ProductManager::getSortFilterWhereInArrays (app/Utils/ProductManager.php:2893-2912) builds only brand/shop/type/status, so the web needs both a UI and a repository change.

SETTINGS, TOGGLES AND CLIENT-SIDE STATE (the audit you asked for)

I grepped every SharedPreferences use across lib/features/{product,addProduct,product_details,clearance_sale,barcode,inventory,restock}. There is exactly ONE, and it is not business state:
- AppConstants.showCookies = 'cookies' (lib/utill/app_constants.dart:291), written/read/removed in lib/features/product/domain/repositories/product_repository.dart:167-180. It only remembers that the seller dismissed the low-stock alert banner (cookies_widget.dart:12). The web does the identical thing with a browser cookie 6valley_stock_limit_status (public/assets/back-end/js/custom.js:1692-1712, 20-minute max-age). Device preference on both sides — acceptable, and symmetrical. Worth noting only because the dismissal hides a business alert and does not sync between devices; if that ever matters, it belongs on the server, but neither client is worse than the other today.

Every genuine business toggle in this domain is server-held and server-written on both clients:
- Product active/inactive → PUT /products/status-update (app) / POST vendor.products.status-update (web).
- Clearance offer on/off → POST /clearance-sale/config-status-update / vendor.clearance-sale.status-update.
- Per-clearance-product on/off → /clearance-sale/product-status-update on both.
- Review visibility → /shop-product-reviews-status on both.
- Shipping-cost-multiply-with-quantity, SEO robots directives, discount type, offer active window → all submitted as part of the product / clearance config payload, never cached locally.
No business setting is stored client-side in this domain.

ONE DEAD CAPABILITY WORTH DECIDING ON

Price-range and created-at-range filtering is fully implemented server-side (min_price / max_price / start_date / end_date in getVendorAllProducts) and FilterModel still carries the fields (filter_model.dart:5-7), but the Flutter UI is commented out (product_filter_bottomsheet_widget.dart:258-303) and the web never had it. Either build both UIs or drop the query params — right now the API surface promises something no seller can reach.

PERMISSION MODEL (both surfaces agree, which is useful for the implementer)

API: products.view / products.manage / inventory.manage / promotions.manage, declared per route group (routes/rest_api/v3/seller.php:154, 171, 186, 228). Web staff access maps the same way in app/Http/Middleware/SellerStaffAccessMiddleware.php:85 ('products' → products.manage on write, products.view on read) and :93 ('clearance-sale' → promotions.manage). Note the API separates stock writes into inventory.manage (line 186) while the web has no equivalent split — vendor.products.update-quantity resolves to products.manage. If the web gains the inventory screens, it should adopt the inventory.manage split rather than reusing products.manage.

TWO SMALL ASYMMETRIES THAT ARE PROBABLY DELIBERATE

- Product status toggle is on the web LIST row but only on the app's DETAIL screen (the app's row speed-dial is edit/barcode/delete). Not a gap, but it makes bulk deactivation far slower on mobile.
- Approval status is chips in the app vs four separate sidebar routes on the web. Same capability, different navigation; no work needed unless you want them to match.
```

## BOTH (33)

**Browse own product catalogue, paginated**  
`products.view / products.manage`  
- App — Yes — lib/features/product/screens/product_list_screen.dart:20 (ProductListMenuScreen) rendering lib/features/product/widgets/product_widget.dart:120 LazyPaginatedListView
- Web — Yes — resources/views/vendor-views/product/list.blade.php:50 k-table + :180 pagination
- Server — GET /api/v3/seller/products/{seller_id}/all-products (routes/rest_api/v3/seller.php:696 → ProductController::getVendorAllProducts:143) / web: routes/vendor/routes.php:153 vendor.products.list → Vendor\Product\ProductController::index:100
- Evidence — flutter lib/features/product/domain/repositories/product_repository.dart:52-56; web resources/views/vendor-views/product/list.blade.php:67-178

**Search products by name**  
`products.view`  
- App — Yes — lib/features/product/screens/product_list_screen.dart:108-120 CustomSearchFieldWidget with 500ms debounce
- Web — Yes — resources/views/vendor-views/product/list.blade.php:22 x-k.data-view searchName="searchValue"
- Server — all-products `search` param (RestAPI/v3/seller/ProductController.php:228-233) / web index() searchValue (Vendor/Product/ProductController.php:116)

**Filter catalogue by sorting (recent / oldest / top selling / most popular)**  
`products.view`  
- App — Yes — lib/features/product/widgets/product_filter_bottomsheet_widget.dart:33-38 `_sortingOptions`, radio group at :99-113
- Web — Yes — resources/views/vendor-views/partials/_product-filters-sections.blade.php:1-45 `filter_sort_by` radios
- Server — all-products `filter_sort_by` (RestAPI/v3/seller/ProductController.php:267-292) / web ProductManager sort in getWebListWithScope

**Filter catalogue by product type (physical / digital)**  
`products.view`  
- App — Yes — product_filter_bottomsheet_widget.dart:133-189 physical/digital checkboxes
- Web — Yes — _product-filters-sections.blade.php:47-75 `filter_product_types[]`
- Server — `product_types` (RestAPI/v3/seller/ProductController.php:232-235) / ProductManager::getSortFilterWhereInArrays app/Utils/ProductManager.php:2904

**Filter catalogue by active / inactive status**  
`products.view`  
- App — Yes — product_filter_bottomsheet_widget.dart:202-254 active/inactive checkboxes
- Web — Yes — _product-filters-sections.blade.php:77-103 `product_status[]`
- Server — `product_status` json array (RestAPI/v3/seller/ProductController.php:149) / ProductManager.php:2908

**Filter catalogue by brand (with see-more paging of the brand list)**  
`products.view`  
- App — Yes — product_filter_bottomsheet_widget.dart:321-380, brand list from GET /api/v3/seller/brands
- Web — Yes — _product-filters-sections.blade.php:105-144 with load-more-brands AJAX
- Server — `brand_ids` (RestAPI/v3/seller/ProductController.php:236-238) / vendor.products.load-more-brands routes/vendor/routes.php:182

**Filter catalogue by category / sub-category / sub-sub-category**  
`products.view`  
- App — Yes — product_filter_bottomsheet_widget.dart via FilterModel.categoryIds / filterSubCategoryIds / filterSubSubCategoryIds (lib/features/product/domain/models/filter_model.dart:9-11)
- Web — Yes — _product-filters-sections.blade.php:146-205 nested category tree checkboxes
- Server — filter_category_ids / filter_sub_category_ids / filter_sub_sub_category_ids (RestAPI/v3/seller/ProductController.php:158-167) / Vendor/Product/ProductController.php:107-109
- Evidence — flutter lib/features/product/domain/repositories/product_repository.dart:39-41; web _product-filters-sections.blade.php:160-200

**Create a product with multi-language title & description**  
`products.manage`  
- App — Yes — lib/features/addProduct/screens/add_product_tab_view_screen.dart:15 3-tab wizard; title/description per language in add_product_screen.dart via titleControllerList/descriptionControllerList (add_product_repository.dart:219-220 posts jsonEncode(titleList)/(descriptionList))
- Web — Yes — resources/views/vendor-views/product/add-new.blade.php + resources/views/vendor-views/product/add/_title-description.blade.php (per-language tabs)
- Server — POST /api/v3/seller/products/add (routes/rest_api/v3/seller.php:175 → add_new:782) / POST vendor.products.add (routes/vendor/routes.php:155 → add:216)
- Evidence — flutter lib/features/addProduct/domain/repository/add_product_repository.dart:218-251,297-346; web resources/views/vendor-views/product/add/_title-description.blade.php:24-66

**Edit an existing product and delete a product**  
`products.manage`  
- App — Yes — edit via the row speed-dial lib/features/shop/widgets/shop_product_card_widget.dart:275-283 → AddProductTabView(product:…) loading GET /products/edit/{id}; delete at :294-318 with a confirmation dialog
- Web — Yes — resources/views/vendor-views/product/list.blade.php:162-176 edit link + delete form
- Server — GET /products/edit/{id} + PUT /products/update/{id} + DELETE /products/delete/{id} (routes/rest_api/v3/seller.php:158,177,178) / vendor routes/vendor/routes.php:167-168,164

**Product media: thumbnail, additional gallery images, colour-wise variation images, add & delete**  
`products.manage`  
- App — Yes — add_product_screen.dart:321-421 (thumbnail), :932-1046 (additional images), lib/features/addProduct/widgets/color_variation_image_widget.dart; upload via POST /products/upload-images (add_product_repository.dart:85-131), delete via add_product_image_controller.dart:336
- Web — Yes — resources/views/vendor-views/product/add/_product-thumbnail.blade.php, _additional-images.blade.php, _color-wise-images.blade.php; post-publish edits via resources/views/vendor-views/product/partials/offcanvas/_edit-images-offcanvas.blade.php:1,96
- Server — POST /products/upload-images + GET /products/delete-image + GET /products/get-product-images/{id} (routes/rest_api/v3/seller.php:172,179,163) / vendor.products.update-product-images + delete-image (routes/vendor/routes.php:169-170)
- Evidence — flutter lib/features/addProduct/controllers/add_product_image_controller.dart:58-122,209,336; web resources/views/vendor-views/product/partials/offcanvas/_edit-images-offcanvas.blade.php:1,73,96

**Attach a YouTube product video link**  
`products.manage`  
- App — Yes — add_product_screen.dart:1101-1113 (`product_video_link` section, `video_url` field)
- Web — Yes — resources/views/vendor-views/product/add/_product-video.blade.php; shown on view.blade.php:661-666
- Server — `video_url` in the add/update payload (add_product_repository.dart:238) served by add_new/updateProduct
- Evidence — flutter lib/features/addProduct/screens/add_product_screen.dart:1101-1113; web resources/views/vendor-views/product/add/_product-video.blade.php

**Product classification: category tree, brand, unit, SKU/product code with generator, search tags**  
`products.manage`  
- App — Yes — add_product_screen.dart:485-489 SelectCategoryWidget, :491-540 brand dropdown, :542-570 unit, :575-605 code + `generate_code` button (:588), tags on add_product_seo_screen.dart:284
- Web — Yes — resources/views/vendor-views/product/add/_general-setup.blade.php (category/brand/unit), code generator at :173 `action-onclick-generate-number`, tags at :214-222
- Server — category_id/sub_category_id/sub_sub_category_id, brand_id, unit, code, tags in the add/update payload (add_product_repository.dart:226-248, 270-277)

**Pricing & stock fields: unit price, discount + type, minimum order qty, current stock, shipping cost, multiply-shipping-with-quantity**  
`products.manage`  
- App — Yes — add_product_next_screen.dart:327 unit price, :440 discount amount, :464 current stock, :483 min order qty, :501 shipping cost, :505-534 multiply toggle
- Web — Yes — resources/views/vendor-views/product/add/_pricing-others.blade.php:47 unit_price, :68 minimum_order_qty, :87 current_stock, :106-110 discount + discount_type, :153 shipping_cost, :180 multiply_qty switch
- Server — unit_price/discount/discount_type/current_stock/minimum_order_qty/shipping_cost/multiply_qty (add_product_repository.dart:221-244)

**Assign VAT / tax rates and tax model to a product**  
`products.manage`  
- App — Yes — add_product_next_screen.dart:342 `select_tax_rate` multi-select, backed by lib/features/addProduct/controllers/add_product_tax_controller.dart and GET /api/v1/vat-tax/get-taxVat-list
- Web — Yes — resources/views/vendor-views/product/add/_pricing-others.blade.php:120-126 `tax_ids[]` multi-select; shown in the list at list.blade.php:115-123
- Server — tax_ids + tax_model in add/update payload (add_product_repository.dart:224-225); reference list /api/v1/vat-tax/get-taxVat-list (app_constants.dart:144)

**Variation setup: colours plus other attributes, with per-variant price, SKU and stock**  
`products.manage`  
- App — Yes — add_product_next_screen.dart:549-890 (`variations` section, colour block at :612, other attributes at :884), lib/features/addProduct/controllers/variation_controller.dart
- Web — Yes — resources/views/vendor-views/product/add/_product-variation-setup.blade.php + resources/views/vendor-views/product/partials/_sku-combinations.blade.php
- Server — colors/colors_active/attribute payload (add_product_repository.dart:234-237, 184-186) / vendor.products.sku-combination routes/vendor/routes.php:157

**Digital product setup: digital product type, file types & extensions, variation-wise file upload with price and SKU**  
`products.manage`  
- App — Yes — lib/features/addProduct/widgets/digital_product_widget.dart, add_product_next_screen.dart:927-1260 (`select_file_type`, `variation_wise_file_upload`), controllers/digital_product_controller.dart
- Web — Yes — resources/views/vendor-views/product/add/_digital-product-file.blade.php + _digital-ready-product-file.blade.php; combination builder routes/vendor/routes.php:158
- Server — POST /products/upload-digital-product + POST /products/delete-digital-product (routes/rest_api/v3/seller.php:173-174); digital_product_variant_key/sku/price fields (add_product_repository.dart:279-293) / vendor digital-variation-combination + digital-variation-file-delete (routes/vendor/routes.php:158-159)

**Digital preview file: upload and delete a preview for a digital product**  
`products.manage`  
- App — Yes — lib/features/addProduct/widgets/upload_preview_file_widget.dart; delete via add_product_repository.dart:460-468 (GET /products/delete-preview-file)
- Web — Yes — vendor.products.delete-preview-file routes/vendor/routes.php:177 → Vendor/Product/ProductController::deletePreviewFile:980
- Server — GET /api/v3/seller/products/delete-preview-file (routes/rest_api/v3/seller.php:180)
- Evidence — flutter lib/features/addProduct/domain/repository/add_product_repository.dart:460-468; web routes/vendor/routes.php:177 + Vendor/Product/ProductController.php:980

**Assign authors and publishing houses to a digital product**  
`products.manage`  
- App — Yes — add_product_screen.dart:653-790 author + publishing house typeaheads; lists via add_product_repository.dart:498-516
- Web — Yes — resources/views/vendor-views/product/add/_general-setup.blade.php:132-134 `publishing_house[]` (and the author equivalent), persisted by Vendor/Product/ProductController::updateProductAuthorAndPublishingHouse:377
- Server — GET /products/digital-author-list + /products/digital-publishing-house-list (routes/rest_api/v3/seller.php:165-166); `authors` / `publishing_house` in the add payload (add_product_repository.dart:249-250)
- Evidence — flutter add_product_screen.dart:653,785 + add_product_repository.dart:498-516; web resources/views/vendor-views/product/add/_general-setup.blade.php:132-134 + Vendor/Product/ProductController.php:377

**Product SEO: meta title, meta description, meta image, and the full robots directive set (index/noindex, nofollow, no-image-index, noarchive, nosnippet, max-snippet, max-video-preview, max-image-preview)**  
`products.manage`  
- App — Yes — add_product_seo_screen.dart:376-449 (meta title/description/image) and lib/features/addProduct/widgets/meta_seo_widget.dart:40-392 (all robots directives incl. metaNoImageIndex at :151)
- Web — Yes — resources/views/vendor-views/product/partials/_seo-section.blade.php:7,20,29,40,53,62,82,94,100,112,118,128 — same field names
- Server — meta_* fields in the add/update payload (add_product_repository.dart:254-268) → getProductSEOData (RestAPI/v3/seller/ProductController.php:1677)

**AI auto-fill of the product form (title, description, general setup, pricing, variation setup, SEO section), title suggestions, image analysis, and remaining-generations quota**  
`products.manage`  
- App — Yes — lib/features/ai/controllers/ai_controller.dart, invoked from add_product_screen.dart:456-478 (general setup), add_product_next_screen.dart:283-305 (pricing) and :556-574 (variations), add_product_seo_screen.dart:383-402 & :549-568; quota widget lib/features/ai/widgets/genertate_count_widget.dart:33
- Web — Yes — resources/views/vendor-views/product/add/_title-description.blade.php:24-27,59-61; add/_general-setup.blade.php:12-16; add/_pricing-others.blade.php:22-24; partials/ai-sidebar.blade.php:129 (analyze image) & :168-169 (title suggestions); quota at add-new.blade.php:23 and edit.blade.php:23
- Server — Modules/AI/routes/api.php:25-33 (v3 seller, POST, seller_can:products.manage) and Modules/AI/routes/vendor/routes.php:21-28 (web, GET). generate-limit-check exists only on the API (Modules/AI/routes/api.php:34) — the web reads $aiRemainingCount server-side instead.
- Evidence — flutter lib/features/ai/controllers/ai_controller.dart:114-369 + genertate_count_widget.dart:33; web Modules/AI/routes/vendor/routes.php:21-28 + resources/views/vendor-views/product/add-new.blade.php:23

**Turn a product active / inactive (publish toggle)**  
`products.manage`  
- App — Yes — from the details screen app bar switch, lib/features/product_details/screens/product_details_screen.dart:56-64 → productStatusOnOff (product_details_repository.dart:28-41). Not available on the list row (the row speed-dial is edit/barcode/delete only).
- Web — Yes — inline on every list row: resources/views/vendor-views/product/list.blade.php:127-146; also on the detail page view.blade.php:46-62 and on stock-limit-list.blade.php:101-120
- Server — PUT /api/v3/seller/products/status-update (routes/rest_api/v3/seller.php:176) / POST vendor.products.status-update (routes/vendor/routes.php:160 → updateStatus:692)

**Update a product's stock quantity, main stock and per-variation**  
`inventory.manage / products.manage`  
- App — Yes — lib/features/product/widgets/quantity_change_dialog_widget.dart:102-133 (AttributeViewWidget + total quantity + `update_quantity`) → add_product_repository.dart:412-427
- Web — Yes — resources/views/vendor-views/product/partials/_update-stock.blade.php driven from stock-limit-list.blade.php:88-95 and request-restock-list.blade.php:125
- Server — PUT /api/v3/seller/products/quantity-update (routes/rest_api/v3/seller.php:187) / POST vendor.products.update-quantity (routes/vendor/routes.php:166 → updateQuantity:788)
- Evidence — flutter quantity_change_dialog_widget.dart:102-133 + add_product_repository.dart:412-427; web resources/views/vendor-views/product/stock-limit-list.blade.php:88-95,175-183

**Limited / low-stock product list, with quick edit, delete, restock and barcode actions**  
`products.view / inventory.manage`  
- App — Yes — lib/features/product/screens/stock_out_product_screen.dart:10 → stock_out_product_widget.dart and stockout_product_card_widget.dart:91-124 (delete/edit swipe actions), :320 restock dialog; reached from the FAB at product_widget.dart:172-187
- Web — Yes — resources/views/vendor-views/product/stock-limit-list.blade.php:10-16, sort selector at :26-42, per-row update-quantity/barcode/edit/delete at :88-141
- Server — GET /api/v3/seller/products/stock-out-list (routes/rest_api/v3/seller.php:157) / GET vendor.products.stock-limit-list (routes/vendor/routes.php:165 → getStockLimitListView:754)
- Evidence — flutter lib/features/product/widgets/stockout_product_card_widget.dart:91-124,320; web resources/views/vendor-views/product/stock-limit-list.blade.php:26-141

**Low-stock alert banner that the seller can dismiss**  
`products.view`  
- App — Yes — lib/features/product/widgets/cookies_widget.dart:12, fed by GET /products/stock-limit-status; dismissal stored locally in SharedPreferences (product_repository.dart:167-180, key AppConstants.showCookies = 'cookies', app_constants.dart:291)
- Web — Yes — resources/views/layouts/vendor/partials/_translated-message-container.blade.php:33,43 + public/assets/back-end/js/custom.js:1621,1692-1712; dismissal stored in the browser cookie `6valley_stock_limit_status`
- Server — GET /api/v3/seller/products/stock-limit-status (routes/rest_api/v3/seller.php:164) / GET vendor.products.stock-limit-status (routes/vendor/routes.php:176)
- Evidence — flutter lib/features/product/domain/repositories/product_repository.dart:167-180 + cookies_widget.dart:12; web public/assets/back-end/js/custom.js:1692-1712

**Restock-request list — see which products customers asked to be restocked**  
`products.view`  
- App — Yes — lib/features/restock/screens/restock_list_screen.dart:56 (`request_restock_request`) → POST /products/restock-request-list (restock_repository.dart:18-26)
- Web — Yes — resources/views/vendor-views/product/request-restock-list.blade.php:11-45
- Server — POST /api/v3/seller/products/restock-request-list (routes/rest_api/v3/seller.php:167) / GET vendor.products.request-restock-list (routes/vendor/routes.php:178 → getRequestRestockListView:139)
- Evidence — flutter lib/features/restock/domain/repositories/restock_repository.dart:18-26 + restock_list_screen.dart:56; web resources/views/vendor-views/product/request-restock-list.blade.php:18-45

**Fulfil a restock request (set stock and notify the customers who asked) and delete a restock row**  
`inventory.manage / products.manage`  
- App — Yes — restock_bottom_sheet.dart:140-276 → restock_repository.dart:52-66 (POST /products/restock-request-stock-update); delete via restock_repository.dart:41-48
- Web — Yes — request-restock-list.blade.php:125 form → vendor.products.update-quantity, which calls updateRestockRequestListAndNotify (Vendor/Product/ProductController.php:817); delete at :87-93 → vendor.products.restock-delete
- Server — POST /products/restock-request-stock-update + GET /products/restock-request-delete (routes/rest_api/v3/seller.php:188,181) / routes/vendor/routes.php:166,180

**Generate a printable barcode sheet for a product with a chosen label quantity (1–270)**  
`products.view`  
- App — Yes — lib/features/barcode/screens/bar_code_generator_screen.dart:40 screen, quantity field at :203-231 with the 1–270 guard at :250-253, generate at :248 → barcode_repository.dart:14-22
- Web — Yes — resources/views/vendor-views/product/barcode.blade.php:60-63 quantity input with the same 270 cap, generate at :70, reset at :74, print at :78; A4 sheet in barcode-pdf.blade.php
- Server — GET /api/v3/seller/products/barcode/generate?id=&quantity= (routes/rest_api/v3/seller.php:160 → barcode_generate:1821) / GET vendor.products.barcode/{id} (routes/vendor/routes.php:163 → getBarcodeView:722)

**View a product's full detail sheet (general info, price info, variations, SEO/meta, video, denied note)**  
`products.view`  
- App — Yes — lib/features/product_details/screens/product_details_screen.dart:166 → product_details_widget.dart:115-404 (general :115, price :161, variation :217, SEO :340, video :394), denied note at :716-729
- Web — Yes — resources/views/vendor-views/product/view.blade.php:400 general, :449 price, :539-584 variations, :633 SEO, :661 video, :21 denied note
- Server — GET /api/v3/seller/products/details/{id} (routes/rest_api/v3/seller.php:156 → details:657) / GET vendor.products.view/{id} (routes/vendor/routes.php:162 → getView:496)
- Evidence — flutter lib/features/product_details/widgets/product_details_widget.dart:115-404,716-729; web resources/views/vendor-views/product/view.blade.php:400-666

**Product reviews: read the review list, reply to a review, and show/hide a review**  
`products.view`  
- App — Yes — reviews tab at product_details_screen.dart:167 → product_details_review_widget.dart:207; per-review status switch product_review_item_widget.dart:150-157 and reply at :186-195 (review_reply_widget.dart)
- Web — Yes — resources/views/vendor-views/product/view.blade.php:705-833 (review table, status switch at :804-807, reply at :833)
- Server — GET /products/review-list/{id} (routes/rest_api/v3/seller.php:159), GET /shop-product-reviews-status, POST /shop-product-reviews-reply (app_constants.dart:34,118) / vendor Review routes routes/vendor/routes.php (ReviewController)
- Evidence — flutter lib/features/product/widgets/product_review_item_widget.dart:150-195 + features/review/domain/repositories/product_review_repository.dart:78,101; web resources/views/vendor-views/product/view.blade.php:705-833

**Turn the shop's clearance-sale offer on or off**  
`promotions.manage`  
- App — Yes — lib/features/clearance_sale/screens/clearance_sale_screen.dart:83-100 FlutterSwitch → clearance_sale_repository.dart:100-111
- Web — Yes — resources/views/vendor-views/promotion/clearance-sale/partials/_clearance-sale-offer-setup.blade.php:17-27 switcher
- Server — POST /api/v3/seller/clearance-sale/config-status-update (routes/rest_api/v3/seller.php:236) / POST vendor.clearance-sale.status-update (routes/vendor/routes.php:247 → updateStatus:69)

**Configure the clearance offer: duration, discount type (flat vs product-wise), discount amount, and active time (always vs a daily window)**  
`promotions.manage`  
- App — Yes — lib/features/clearance_sale/widgets/clearance_offer_setup_widget.dart:35-48 dates, :80-126 discount type, :160-168 amount, :216-299 always/specific-time with open & close time, save at :343
- Web — Yes — _clearance-sale-offer-setup.blade.php:57-64 duration, :69-82 discount type, :89-94 amount, :104-131 offer_active_time + range, save at :142
- Server — GET /clearance-sale/config-data + POST /clearance-sale/config-data-update (routes/rest_api/v3/seller.php:237-238) / POST vendor.clearance-sale.update-config (routes/vendor/routes.php:248 → updateClearanceConfig:88)
- Evidence — flutter clearance_offer_setup_widget.dart:35-354 + clearance_sale_repository.dart:113-123; web _clearance-sale-offer-setup.blade.php:44-144

**Add products to the clearance sale (search the catalogue, multi-select, set a per-product discount at add time)**  
`promotions.manage`  
- App — Yes — lib/features/clearance_sale/screens/clearance_search_product_screen.dart:51-145 (search suggestions, add_products at :133, per-row discount field via clearance_product_distount_text_field_widget.dart); list comes from all-products with offer_type=clearance_sale (clearance_sale_repository.dart:78)
- Web — Yes — resources/views/vendor-views/promotion/clearance-sale/partials/_search-product.blade.php + _product-add-modal.blade.php + _select-product.blade.php
- Server — POST /api/v3/seller/clearance-sale/product-add (routes/rest_api/v3/seller.php:231) / POST vendor.clearance-sale.add-product (routes/vendor/routes.php:252 → addClearanceProduct:155) with search at routes/vendor/routes.php:250

**Manage clearance products individually: edit the discount, toggle the product on/off in the offer, remove it, and clear the whole list**  
`promotions.manage`  
- App — Yes — lib/features/clearance_sale/widgets/clearance_product_widget.dart:141 edit discount (clearance_product_update_widget.dart:237), :160-167 status switch, :143 delete; clear-all at clearance_sale_screen.dart:152-162
- Web — Yes — resources/views/vendor-views/promotion/clearance-sale/partials/_product-add-list.blade.php:255-269 status switch, discount modal via partials/_discount-update-modal.blade.php, clear-all at :109-114
- Server — POST /clearance-sale/product-discount-update, /product-status-update, /product-delete, /all-product-delete (routes/rest_api/v3/seller.php:232-235) / vendor routes/vendor/routes.php:253-256 + update-discount at :257
- Evidence — flutter clearance_product_widget.dart:141-167 + clearance_sale_repository.dart:36-70,138-146; web _product-add-list.blade.php:109-114,255-269 + routes/vendor/routes.php:253-257

## WEB MISSING (9)

**Filter catalogue by publishing house (digital products)**  
`products.view` · wave 2  
- App — Yes — product_filter_bottomsheet_widget.dart:394-397 `_PublisherFilterItemWidget` (class at :630), fed by GET /products/digital-publishing-house-list
- Web — No — the vendor filter offcanvas only declares ['sorting','product_type','product_status','brand','category'] (resources/views/vendor-views/product/partials/offcanvas/_filter-offcanvas.blade.php:14) and ProductManager::getSortFilterWhereInArrays (app/Utils/ProductManager.php:2893-2912) has no publishing-house branch. Searched all of resources/views/vendor-views/product and /partials — publishing_house appears only in the add/update product FORM, never as a list filter.
- Server — GET all-products `publishing_house_ids` — RestAPI/v3/seller/ProductController.php:156, 306-328; list endpoint GET /api/v3/seller/products/digital-publishing-house-list (routes/rest_api/v3/seller.php:166)
- Evidence — flutter lib/features/product/widgets/product_filter_bottomsheet_widget.dart:391-397,630; web _filter-offcanvas.blade.php:13-18 (section list omits it) + app/Utils/ProductManager.php:2893-2912

**Filter catalogue by author / creator (digital products)**  
`products.view` · wave 2  
- App — Yes — product_filter_bottomsheet_widget.dart:399-402 `_AuthorFilterItemWidget` (class at :710), fed by GET /products/digital-author-list
- Web — No — same omission as publishing house; _filter-offcanvas.blade.php:14 filter section list has no author entry, and app/Utils/ProductManager.php:2893-2912 does not build an author whereIn
- Server — GET all-products `author_ids` — RestAPI/v3/seller/ProductController.php:157, 338+; list endpoint /products/digital-author-list (routes/rest_api/v3/seller.php:165)

**Filter the restock-request list by brand, by category, and by request date range**  
`products.view` · wave 2  
- App — Yes — lib/features/restock/widgets/product_filter_dialog_widget.dart:98-124 date range, :164 brand list (fed by GET /products/restock-request-brands-list, restock_repository.dart:29-37), plus a category chip row in restock_list_screen.dart:110
- Web — No usable filter. The offcanvas exposes ONLY a date pair and names the inputs `from` / `to` (resources/views/vendor-views/product/partials/offcanvas/_restock-list-filter-offcanvas.blade.php:31,38), while the controller reads `restock_date` (Vendor/Product/ProductController.php:149) — so even the date filter never binds. There is no brand or category input anywhere in the view, although the controller accepts brand_id / category_id / sub_category_id (Vendor/Product/ProductController.php:142-144) and the blade's active-filter badge already checks for them (request-restock-list.blade.php:27).
- Server — GET /products/restock-request-brands-list (routes/rest_api/v3/seller.php:168); restock-request-list accepts brand/category/date filters (RestAPI/v3/seller/ProductController::getRestockRequestList:2088). Web side is fully supported server-side — only the UI is absent.

**Per-product stock movement ledger — what changed, by how much, why, and who did it**  
`inventory.manage / products.view` · wave 2  
- App — Yes — third tab of the product detail screen: lib/features/product_details/screens/product_details_screen.dart:168-172 mounts InventoryView; rendering at lib/features/inventory/widgets/inventory_view.dart:164-181 and :257-269 (movement type, reason, actor)
- Web — No — not found. There is no inventory route in routes/vendor/routes.php (grepped 'inventory' — zero hits) and no inventory view directory under resources/views/vendor-views (only analytics, marketplace, report, product, promotion…). view.blade.php shows only the current stock number (view.blade.php:436).
- Server — GET /api/v3/seller/seller-center/inventory/movements — routes/rest_api/v3/seller.php:658 → SellerInventoryController::movements
- Evidence — flutter product_details_screen.dart:168-172 + lib/features/inventory/widgets/inventory_view.dart:164-269 + inventory_repository.dart:15-21; web routes/vendor/routes.php (no 'inventory' route) + resources/views/vendor-views/product/view.blade.php:436

**Correct a product's stock with a mandatory reason code and an optional note (auditable adjustment, not a silent overwrite)**  
`inventory.manage` · wave 2  
- App — Yes — lib/features/inventory/widgets/stock_adjust_sheet.dart:89-170: add/remove segment, quantity, resulting balance preview, required reason chip, optional note; submit blocked until a reason is chosen (:170)
- Web — No — the only web stock write is vendor.products.update-quantity (routes/vendor/routes.php:166 → updateQuantity:788), which overwrites `current_stock` with no reason, no note and no ledger entry. Searched resources/views/vendor-views for 'adjust' — only order/invoice.blade.php matches.
- Server — POST /api/v3/seller/seller-center/inventory/products/{id}/adjust — routes/rest_api/v3/seller.php:662 → SellerInventoryController::adjust
- Evidence — flutter lib/features/inventory/widgets/stock_adjust_sheet.dart:37-170 + inventory_repository.dart:31-45; web app/Http/Controllers/Vendor/Product/ProductController.php:788-823 (no reason/ledger)

**Inventory overview and warehouse-wise / batch-and-expiry stock view**  
`inventory.manage / products.view` · wave 2  
- App — Yes — lib/features/inventory/screens/inventory_screen.dart:20 and inventory_view.dart:123-143 (warehouses, default warehouse) and :239 (batch expiry date)
- Web — No — not found. No inventory routes in routes/vendor/routes.php; the closest web pages are report/product-stock.blade.php (a flat stock report) and report/all-product.blade.php, neither of which shows warehouses or batches.
- Server — GET /seller-center/inventory/overview, /warehouses, /batches — routes/rest_api/v3/seller.php:657,659,660
- Evidence — flutter lib/features/inventory/widgets/inventory_view.dart:123-143,239 + inventory_repository.dart:12,24-28; web routes/vendor/routes.php:443-451 (report group only) + resources/views/vendor-views/report/product-stock.blade.php

**Bulk price change across many products (select products, choose a mode, apply as a background job)**  
`products.manage` · wave 2  
- App — Yes — lib/features/bulk_jobs/screens/bulk_edit_screen.dart:97-182 (multi-select + `bulk_change_price`), mode chips at :263-266, submit at :232 → bulk_job_repository.dart:33-46
- Web — No — not found. Grepped routes/vendor/routes.php and resources/views/vendor-views for 'bulk-jobs' / 'bulk price': zero hits. The only web bulk feature is bulk-import (creating products from a spreadsheet, routes/vendor/routes.php:172), which cannot re-price an existing selection.
- Server — POST /api/v3/seller/seller-center/bulk-jobs/price — routes/rest_api/v3/seller.php:677 → SellerBulkJobController::storePriceUpdate
- Evidence — flutter lib/features/bulk_jobs/domain/repositories/bulk_job_repository.dart:33-46 + bulk_edit_screen.dart:175,232; web routes/vendor/routes.php (no bulk-jobs route)

**Bulk stock change across many products, with an optional note**  
`inventory.manage` · wave 2  
- App — Yes — bulk_edit_screen.dart:182 (`bulk_change_stock`) → bulk_job_repository.dart:49-60
- Web — No — not found; same absence as bulk price (no bulk-jobs route or view in the vendor panel)
- Server — POST /api/v3/seller/seller-center/bulk-jobs/stock — routes/rest_api/v3/seller.php:678 → SellerBulkJobController::storeStockUpdate
- Evidence — flutter lib/features/bulk_jobs/domain/repositories/bulk_job_repository.dart:49-60 + bulk_edit_screen.dart:182; web routes/vendor/routes.php (grep 'bulk-jobs' → none)

**Bulk-job history and per-job receipt, including downloading the refused rows as CSV**  
`products.view / products.manage / inventory.manage` · wave 2  
- App — Yes — lib/features/bulk_jobs/screens/bulk_jobs_screen.dart:48-89 (history) and bulk_job_receipt_screen.dart:58-127 (result, partial-failure list, `download_csv` at :127) → bulk_job_repository.dart:13-30,63-73
- Web — No — not found; no bulk-jobs route or view in the vendor panel
- Server — GET /seller-center/bulk-jobs, /{id}, /{id}/failures — routes/rest_api/v3/seller.php:673-675
- Evidence — flutter lib/features/bulk_jobs/screens/bulk_job_receipt_screen.dart:58-127 + bulk_job_repository.dart:63-73; web routes/vendor/routes.php (grep 'bulk-jobs' → none)

## APP MISSING (8)

**Export the product list to Excel (respecting the active filters)**  
`products.view`  
- App — No — not found. lib/features/product/domain/repositories/product_repository.dart has no export call and AppConstants has no product-list export URI (lib/utill/app_constants.dart:31-42). Only seller-center report exports exist (app_constants.dart:178-179), which are a different screen.
- Web — Yes — resources/views/vendor-views/product/list.blade.php:26-36 export button passing every filter
- Server — GET vendor.products.export-excel/{type} — routes/vendor/routes.php:161 → Vendor/Product/ProductController::exportList:536. No v3 seller API equivalent.

**Product gallery — browse other listings and clone their setup into a new product**  
`products.manage`  
- App — No — not found; no gallery screen under lib/features/product* and no matching URI in lib/utill/app_constants.dart
- Web — Yes — resources/views/vendor-views/product/product-gallery.blade.php:12,56,154 ("use this product info")
- Server — GET vendor.products.product-gallery — routes/vendor/routes.php:175 → Vendor/Product/ProductController::getProductGalleryView:906. No seller API equivalent.

**Bulk-import products from an Excel/XLSX spreadsheet (with template download)**  
`products.manage`  
- App — No — not found; no import URI in lib/utill/app_constants.dart, no importer screen under lib/features/addProduct
- Web — Yes — resources/views/vendor-views/product/bulk-import.blade.php:23-143 (3-step wizard, template with/without existing data)
- Server — GET/POST vendor.products.bulk-import — routes/vendor/routes.php:172-173 → Vendor/Product/ProductController::importBulkProduct:868. No seller API equivalent.

**Export the restock-request list**  
`products.view`  
- App — No — not found; lib/features/restock/domain/repositories/restock_repository.dart has no export call and app_constants.dart has no restock export URI
- Web — Yes — resources/views/vendor-views/product/request-restock-list.blade.php:24-25
- Server — GET vendor.products.restock-export (routes/vendor/routes.php:179 → exportRestockList:594). No seller API equivalent.

**Product-level sales performance on the detail page: total quantity sold, total order amount, star-rating breakdown**  
`products.view`  
- App — No on the detail page — totalQtySold is parsed (lib/features/product/domain/models/product_model.dart:508) but only surfaced on the Top Selling / Most Popular cards (top_most_product_card_widget.dart:72). The product detail sheet shows no sold total, no order amount and no rating histogram (grepped product_details_widget.dart for 'total_qty_sold' / 'rating' — none).
- Web — Yes — resources/views/vendor-views/product/view.blade.php:382 Total_Qty_Sold, :389 Total_Order_Amount, :233-303 5-to-1-star rating breakdown, :324 review count
- Server — Both served by the same product detail read; the API `details` response already carries total_qty_sold (product_model.dart:508)
- Evidence — web resources/views/vendor-views/product/view.blade.php:382-389,233-324; flutter lib/features/product_details/widgets/product_details_widget.dart (no sold/rating block) vs product_model.dart:508

**See why a product was denied, including the structured moderation reason codes**  
`products.view`  
- App — Partial — free-text denied note only: product_details_widget.dart:716-729 (`denied_note`), model field product_model.dart:385. No reason_codes field is parsed anywhere in lib/features/product*.
- Web — Yes — free-text note at view.blade.php:21 plus structured reason codes and the needs_changes action from ProductModerationEvent: resources/views/vendor-views/product/list.blade.php:89-97
- Server — denied_note ships on both. reason_codes come from App\Models\ProductModerationEvent, read directly in the blade (list.blade.php:90) — grepped RestAPI/v3/seller/ProductController.php for 'moderation'/'reason_codes': zero hits, so the seller API never exposes them.
- Evidence — web resources/views/vendor-views/product/list.blade.php:89-97; flutter lib/features/product_details/widgets/product_details_widget.dart:716-729 + grep 'moderation' across lib/features/product* → none

**Clearance-sale SEO / meta data setup for the offer page**  
`promotions.manage`  
- App — No — not found. lib/features/clearance_sale has no meta/SEO widget and app_constants.dart:125-133 lists no clearance SEO endpoint; the config model (clearance_config_model.dart:1-60) carries no meta fields.
- Web — Yes — resources/views/vendor-views/promotion/clearance-sale/partials/_clearance-sale-meta-setup.blade.php, opened from _clearance-sale-offer-setup.blade.php:49 ("Meta Data Setup")
- Server — POST vendor.clearance-sale.update-seo-meta — routes/vendor/routes.php:249 → Vendor/Promotion/ClearanceSaleController::updateClearanceSeoConfig:104. No seller API equivalent (routes/rest_api/v3/seller.php:230-238 has no SEO route).
- Evidence — web resources/views/vendor-views/promotion/clearance-sale/partials/_clearance-sale-meta-setup.blade.php + routes/vendor/routes.php:249; flutter lib/utill/app_constants.dart:125-133 (no clearance SEO URI)

**Search within the clearance-sale product list**  
`promotions.manage`  
- App — No — clearance_sale_screen.dart has no search field (grepped for 'search' — only the import/navigation to ClearanceSearchProductScreen at :9,56, which searches the catalogue to ADD products, not the existing clearance list)
- Web — Yes — resources/views/vendor-views/promotion/clearance-sale/partials/_product-add-list.blade.php:99-102 `searchValue` box over the clearance list
- Server — GET /api/v3/seller/clearance-sale/product-list has no search param wired in the app (clearance_sale_repository.dart:27 sends only limit/offset) / web ClearanceSaleController::getView:44
- Evidence — web _product-add-list.blade.php:99-102; flutter lib/features/clearance_sale/screens/clearance_sale_screen.dart (grep 'search' → only :9,:56) + clearance_sale_repository.dart:27

## APP ADAPTATION (4)

**Filter catalogue by approval status (all / approved / denied / new request)**  
`products.view`  
- App — Yes — horizontal chips, lib/features/product/widgets/status_filter_widget.dart:23 `['all','approved','denied','new_product']` → request_status
- Web — Yes — but as four separate sidebar destinations, not an in-page filter: resources/views/layouts/vendor/partials/v2/_side-bar.blade.php:196,200,204,208 → /vendor/products/list/{all|approved|new-request|denied}
- Server — all-products `request_status` (RestAPI/v3/seller/ProductController.php:264-266) / web index() maps {type} to request_status (Vendor/Product/ProductController.php:110)
- Evidence — flutter lib/features/product/widgets/status_filter_widget.dart:23-38; web routes/vendor/routes.php:153 + resources/views/vendor-views/product/list.blade.php:3

**Deliver the generated barcode sheet — download the file vs print it from the browser**  
`products.view`  
- App — Download / open the generated file — bar_code_generator_screen.dart:280-306 (launches the returned URL, `download` label at :306)
- Web — Browser print of the A4 sheet — resources/views/vendor-views/product/barcode.blade.php:78 + barcode-pdf.blade.php:108 ("this page is for A4 size page printer")
- Server — Same endpoint on both sides (barcode/generate, routes/rest_api/v3/seller.php:160; vendor.products.barcode, routes/vendor/routes.php:163)

**Preview a digital product's preview file (PDF / image / video / audio) and download variation files**  
`products.view`  
- App — Yes — in-app viewers: lib/features/product_details/widgets/{pdf_preview_flutter,image_preview,video_preview,audio_preview,download_preview_file}.dart dispatched by product_details_widget.dart:815-828; also from the list row (shop_product_card_widget.dart:190)
- Web — Partial — variation files are downloaded via a link (view.blade.php:610) and video has a modal (partials/modal/_video-view-modal.blade.php); there is no in-page PDF/audio viewer
- Server — file_full_url / preview_file_full_url on the product detail payload (RestAPI/v3/seller/ProductController::details:657); web reads the same model in getView:496
- Evidence — flutter lib/features/product_details/widgets/product_details_widget.dart:815-828 + widgets/pdf_preview_flutter.dart; web resources/views/vendor-views/product/view.blade.php:610 + partials/modal/_video-view-modal.blade.php

**Dedicated Top Selling Products and Most Popular Products screens**  
`products.view`  
- App — Yes — lib/features/product/screens/top_selling_product_screen.dart and most_popular_product_screen.dart, wrapped by product_list_view_screen.dart:7-24; own endpoints /products/top-selling-product and /products/most-popular-product (product_repository.dart:96-118)
- Web — Partial — the same data appears as dashboard cards (resources/views/vendor-views/dashboard/index.blade.php:133,138 → _top-rated-products / _top-selling-products) and as product-list sort options (_product-filters-sections.blade.php:26,35), but there is no standalone paginated screen
- Server — GET /products/top-selling-product + /products/most-popular-product (routes/rest_api/v3/seller.php:161-162) / web Vendor/DashboardController.php:62 getTopSellList

## DEVICE SPECIFIC (1)

**Look a product up by its barcode / product code**  
`orders.manage`  
- App — Yes, via camera scan — lib/features/pos/controllers/barcode_scan_controller.dart:36-62 (BarcodeScanner.scan, code128) then getProductFromScan → GET /api/v3/seller/pos/products
- Web — Yes, by typing the code — the POS search matches code and barcode as well as name: app/Repositories/ProductRepository.php:260-265 (`search_from == 'pos'` branch), reached from Vendor/POS/POSController::getSearchedProductsView:495
- Server — GET /api/v3/seller/pos/products → POSController::get_product_by_barcode (routes/rest_api/v3/seller.php:322) / GET vendor.pos.search-product
- Evidence — flutter lib/features/pos/controllers/barcode_scan_controller.dart:36-62; web app/Repositories/ProductRepository.php:260-265 + app/Http/Controllers/Vendor/POS/POSController.php:495-511. The camera capture is device-only; the underlying lookup exists on both.

## DEPRECATED (1)

**Filter catalogue by price range and by created-at date range**  
`products.view`  
- App — No — the widgets exist but are commented out: lib/features/product/widgets/product_filter_bottomsheet_widget.dart:258-303 (price range + calendar block fully commented); FilterModel still carries minPrice/maxPrice/startDate/endDate (filter_model.dart:5-7)
- Web — No — not found. Searched resources/views/vendor-views/partials/_product-filters-sections.blade.php (only sorting/type/status/brand/category) and _filter-offcanvas.blade.php:14
- Server — Supported and live: min_price / max_price / start_date / end_date in all-products — RestAPI/v3/seller/ProductController.php (min/max at ~:301-309, dates at ~:309-314)

