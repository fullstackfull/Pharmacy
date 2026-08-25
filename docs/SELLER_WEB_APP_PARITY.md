# Seller Center — Web / App Capability Parity

> Mandated by PART 2 of the Seller Center implementation brief. Produced before any redesign screen was
> altered, by reading both clients and the server rather than by comparing screenshots.

**Method.** Fourteen domains were audited against three sources at once: the Flutter seller app
(`sillercenter-syria-cosmatics`), the existing web seller panel (`resources/views/vendor-views/**`,
`routes/vendor/routes.php`) and the server that answers both (`routes/rest_api/v3/seller.php`,
`routes/rest_api/v{1,2}/api.php`). Every row below carries the file and line that proves it, so a reader
can disagree with a classification by opening the code, not by trusting the audit.

**Parity is behavioural, not visual.** A capability is at parity when the same business outcome is reachable
with the same rules, statuses, permissions and calculations. The two clients are allowed to look nothing
alike and to sequence the work differently; they are not allowed to disagree about what a status means, who
may perform an action, or what a number is.

## Coverage

| | |
|---|---|
| Domains audited | 14 |
| Capabilities recorded | 635 |
| WEB MISSING — all documented below, none outstanding | 195 |
| Undocumented WEB MISSING | **0** |

Every WEB MISSING capability appears in the register in [§3](#3-web-missing-register-the-mandatory-list),
is assigned to an implementation wave, and is repeated with its full evidence in the domain file listed in
[§5](#5-domains). The register and the domain files are rendered from the same records, so they cannot
drift apart.

---

## 1. How to read a row

| Category | Meaning | What it obliges |
|---|---|---|
| `BOTH` | Exists in the app and on the web, with the same business behaviour. | Keep them in step. A change to one is a change to both. |
| `WEB MISSING` | The app can do it; the web cannot. | **Must be built into the Web Seller Center.** Marketplace capability may not live only on a phone. |
| `APP MISSING` | The web can do it; the app cannot. | Note it. The app closes the gap where it makes sense on a phone; nothing is deleted from the web to force symmetry. |
| `WEB ENHANCEMENT` | Web-only and correct that way — bulk, breadth, long-form work a phone should not attempt. | Keep web-only. Do not shrink it into the app. |
| `APP ADAPTATION` | Same capability, deliberately different shape on a phone. | Legitimate. Verify the business rules match, not the layout. |
| `DEVICE SPECIFIC` | Belongs to the device — camera, scanner, push token, biometrics. | Not a web gap. The web offers the equivalent input where one exists (upload, typed code). |
| `DEPRECATED` | Present in code but no longer part of the product. | Do not carry forward. Removal is a separate, explicit decision. |
| `BACKEND MISSING` | Neither client can do it because the server does not offer it. | Server work first. A client-side workaround here would duplicate business logic, which PART 7 forbids. |

Each row also names the **permission** that governs it. Those keys are the ones the server already enforces
(`app/Http/Middleware/EnsureSellerPermission.php`), which is what makes one authorization system serve both
clients per PART 5.

---

## 2. Matrix

| Domain | BOTH | WEB<br>MISSING | APP<br>MISSING | WEB<br>ENHANCEMENT | APP<br>ADAPTATION | DEVICE<br>SPECIFIC | DEPRECATED | BACKEND<br>MISSING | Total |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| [orders](parity/orders.md) | 40 | 2 | 7 | 3 | 3 | 2 | 0 | 1 | 58 |
| [products](parity/products.md) | 33 | 9 | 8 | 0 | 4 | 1 | 1 | 0 | 56 |
| [finance](parity/finance.md) | 20 | 14 | 6 | 0 | 0 | 1 | 0 | 1 | 42 |
| [inventory](parity/inventory.md) | 14 | 19 | 4 | 0 | 0 | 1 | 0 | 2 | 40 |
| [returns_refunds](parity/returns_refunds.md) | 14 | 13 | 4 | 3 | 0 | 1 | 0 | 1 | 36 |
| [shipping_delivery](parity/shipping_delivery.md) | 42 | 4 | 9 | 0 | 1 | 0 | 0 | 0 | 56 |
| [automation](parity/automation.md) | 0 | 35 | 0 | 0 | 1 | 0 | 0 | 0 | 36 |
| [notifications_chat](parity/notifications_chat.md) | 32 | 7 | 1 | 1 | 0 | 6 | 0 | 1 | 48 |
| [control_tower](parity/control_tower.md) | 7 | 22 | 3 | 1 | 3 | 2 | 0 | 2 | 40 |
| [brands_compliance](parity/brands_compliance.md) | 17 | 19 | 8 | 0 | 1 | 1 | 0 | 0 | 46 |
| [security_integrations](parity/security_integrations.md) | 8 | 26 | 0 | 1 | 2 | 0 | 0 | 0 | 37 |
| [reports_bulk](parity/reports_bulk.md) | 19 | 16 | 8 | 1 | 1 | 2 | 0 | 0 | 47 |
| [settings_profile](parity/settings_profile.md) | 32 | 5 | 2 | 1 | 0 | 3 | 1 | 2 | 46 |
| [growth_reviews](parity/growth_reviews.md) | 35 | 4 | 5 | 0 | 0 | 2 | 0 | 1 | 47 |
| **All** | **313** | **195** | **65** | **11** | **16** | **22** | **2** | **11** | **635** |

---

## 3. WEB MISSING register (the mandatory list)

These 195 capabilities are the reason PART 2 exists: each is a real marketplace
capability that today is reachable only from a phone. They are grouped by the wave that closes them, from
`13-implementation-priority.md`. Wave assignment follows the domain, with two documented splits: the API-key
and webhook half of `security_integrations` belongs to wave 8 rather than 7, and the refund-to-balance row in
`returns_refunds` belongs to wave 5, because that is where the ledger it writes into is built.

| Wave | Capabilities | Closes |
|---|---:|---|
| 2 — Core seller operations | 59 | control_tower, inventory, notifications_chat, orders, products |
| 3 — Automation | 35 | automation |
| 4 — Fulfilment | 16 | returns_refunds, shipping_delivery |
| 5 — Finance | 15 | finance, returns_refunds |
| 6 — Trust | 23 | brands_compliance, growth_reviews |
| 7 — Enterprise | 16 | security_integrations, settings_profile |
| 8 — Platform | 31 | reports_bulk, security_integrations |

### Wave 2 — Core seller operations (59)

| # | Capability | Domain | Where it exists today | Server it calls | Permission |
|---:|---|---|---|---|---|
| 1 | Download the digital product file that was delivered on an order (both 'ready product' and 'ready after sell' types) | orders | Yes — download button lib/features/order_details/widgets/order_product_list_item_widget.dart:213-240; picks digitalFileAfterSellFullUrl or digitalFileReadyFullUrl at 368-382 | none needed — both sides fetch the stored file URL directly; flutter side lib/features/order_details/controllers/order_details_controller.dart:150-245 productDownload | orders.view or orders.manage |
| 2 | POS: take a sale while the device has no connectivity, queue it, and replay it automatically when the network returns (with a pending-count banner and manual retry) | orders | Yes — lib/features/pos/controllers/offline_sales_controller.dart:41-152 (connectivity watch, enqueue, syncPending, max 10 attempts); banner lib/features/pos/widgets/offline_sales_banner.dart:50-72; entry point cart_controller.dart:389-397,446-479 | Replays the ordinary POST /api/v3/seller/pos/place-order (RestAPI/v3/seller/POSController.php:269) via placeOrderRaw | orders.manage |
| 3 | Filter catalogue by publishing house (digital products) | products | Yes — product_filter_bottomsheet_widget.dart:394-397 `_PublisherFilterItemWidget` (class at :630), fed by GET /products/digital-publishing-house-list | GET all-products `publishing_house_ids` — RestAPI/v3/seller/ProductController.php:156, 306-328; list endpoint GET /api/v3/seller/products/digital-publishing-house-list (routes/rest_api/v3/seller.php:166) | products.view |
| 4 | Filter catalogue by author / creator (digital products) | products | Yes — product_filter_bottomsheet_widget.dart:399-402 `_AuthorFilterItemWidget` (class at :710), fed by GET /products/digital-author-list | GET all-products `author_ids` — RestAPI/v3/seller/ProductController.php:157, 338+; list endpoint /products/digital-author-list (routes/rest_api/v3/seller.php:165) | products.view |
| 5 | Filter the restock-request list by brand, by category, and by request date range | products | Yes — lib/features/restock/widgets/product_filter_dialog_widget.dart:98-124 date range, :164 brand list (fed by GET /products/restock-request-brands-list, restock_repository.dart:29-37), plus a category chip row in restock_list_screen.dart:110 | GET /products/restock-request-brands-list (routes/rest_api/v3/seller.php:168); restock-request-list accepts brand/category/date filters (RestAPI/v3/seller/ProductController::getRestockRequestList:2088). Web side is fully supported server-side — only the UI is absent. | products.view |
| 6 | Per-product stock movement ledger — what changed, by how much, why, and who did it | products | Yes — third tab of the product detail screen: lib/features/product_details/screens/product_details_screen.dart:168-172 mounts InventoryView; rendering at lib/features/inventory/widgets/inventory_view.dart:164-181 and :257-269 (movement type, reason, actor) | GET /api/v3/seller/seller-center/inventory/movements — routes/rest_api/v3/seller.php:658 → SellerInventoryController::movements | inventory.manage / products.view |
| 7 | Correct a product's stock with a mandatory reason code and an optional note (auditable adjustment, not a silent overwrite) | products | Yes — lib/features/inventory/widgets/stock_adjust_sheet.dart:89-170: add/remove segment, quantity, resulting balance preview, required reason chip, optional note; submit blocked until a reason is chosen (:170) | POST /api/v3/seller/seller-center/inventory/products/{id}/adjust — routes/rest_api/v3/seller.php:662 → SellerInventoryController::adjust | inventory.manage |
| 8 | Inventory overview and warehouse-wise / batch-and-expiry stock view | products | Yes — lib/features/inventory/screens/inventory_screen.dart:20 and inventory_view.dart:123-143 (warehouses, default warehouse) and :239 (batch expiry date) | GET /seller-center/inventory/overview, /warehouses, /batches — routes/rest_api/v3/seller.php:657,659,660 | inventory.manage / products.view |
| 9 | Bulk price change across many products (select products, choose a mode, apply as a background job) | products | Yes — lib/features/bulk_jobs/screens/bulk_edit_screen.dart:97-182 (multi-select + `bulk_change_price`), mode chips at :263-266, submit at :232 → bulk_job_repository.dart:33-46 | POST /api/v3/seller/seller-center/bulk-jobs/price — routes/rest_api/v3/seller.php:677 → SellerBulkJobController::storePriceUpdate | products.manage |
| 10 | Bulk stock change across many products, with an optional note | products | Yes — bulk_edit_screen.dart:182 (`bulk_change_stock`) → bulk_job_repository.dart:49-60 | POST /api/v3/seller/seller-center/bulk-jobs/stock — routes/rest_api/v3/seller.php:678 → SellerBulkJobController::storeStockUpdate | inventory.manage |
| 11 | Bulk-job history and per-job receipt, including downloading the refused rows as CSV | products | Yes — lib/features/bulk_jobs/screens/bulk_jobs_screen.dart:48-89 (history) and bulk_job_receipt_screen.dart:58-127 (result, partial-failure list, `download_csv` at :127) → bulk_job_repository.dart:13-30,63-73 | GET /seller-center/bulk-jobs, /{id}, /{id}/failures — routes/rest_api/v3/seller.php:673-675 | products.view / products.manage / inventory.manage |
| 12 | See total units on hand across the catalogue and the number of movements recorded | inventory | Yes — ReportRowWidget rows 'units_on_hand' and 'movements_recorded' | GET /api/v3/seller/seller-center/inventory/overview | inventory.manage OR products.view |
| 13 | View the shop-wide stock movement ledger (type, signed change, resulting balance, reason, note, who made it, when) | inventory | Yes — 'stock_movements' section, newest first, one card per movement | GET /api/v3/seller/seller-center/inventory/movements (SellerInventoryController@movements) | inventory.manage OR products.view |
| 14 | View the stock movement history of ONE product (from the product's own page) | inventory | Yes — InventoryView embedded as a tab on product details, narrowed by product_id | GET /api/v3/seller/seller-center/inventory/movements?product_id={id} | inventory.manage OR products.view |
| 15 | Filter the movement log by movement type (adjustment / receipt / sale / return / transfer) | inventory | Partial — repository, controller and the overview's movement_types list all support it, but no UI control is rendered (getMovements is always called with clearType: true) | GET /api/v3/seller/seller-center/inventory/movements?type={type}; type list served by overview | inventory.manage OR products.view |
| 16 | Correct a product's stock with a required reason (add/remove direction, quantity, reason chip, optional note) written through the stock ledger | inventory | Yes — StockAdjustSheet opened from the 'correct_stock' FAB on a product's inventory tab | POST /api/v3/seller/seller-center/inventory/products/{id}/adjust (SellerInventoryController@adjust → InventoryService::adjust, row-locked, refuses negative, writes StockMovement) | inventory.manage (API); web write is mapped to products.manage only |
| 17 | Pick the adjustment reason from the server-defined list (count_correction, damage, loss, theft, found, expiry, other) | inventory | Yes — reasons come from the overview response and render as ChoiceChips; Apply stays disabled until one is chosen | reasons[] in GET .../inventory/overview; validated on adjust against StockMovement::REASONS | inventory.manage |
| 18 | See the resulting balance before applying a correction, and get an actionable refusal when it would drive stock negative | inventory | Yes — 'will_become' preview next to current stock; the 422 message is shown inline in the sheet | POST .../inventory/products/{id}/adjust returns 422 {errors:[{code:'stock',message:…}]} when the delta would go negative | inventory.manage |
| 19 | See the seller's warehouses (name, code, address, default flag, status) | inventory | Yes — warehouse cards, rendered only when warehouses_enabled | GET /api/v3/seller/seller-center/inventory/warehouses (SellerInventoryController@warehouses) | inventory.manage OR products.view |
| 20 | See how many units of a product sit in each warehouse and how many are unallocated | inventory | No — the endpoint returns 'breakdown' and 'unallocated' for a product_id but WarehouseModel/the view drop both fields | GET .../inventory/warehouses?product_id={id} → breakdown + unallocated (WarehouseService::breakdown/unallocated) | inventory.manage OR products.view |
| 21 | See batches expiring soon (batch number, expiry date, quantity) | inventory | Yes — 'expiring_soon' section, rendered only when batches_enabled | GET /api/v3/seller/seller-center/inventory/batches?days=30 (SellerInventoryController@batches) | inventory.manage OR products.view |
| 22 | See already-expired batches still holding quantity | inventory | Yes — 'expired_batches' section in error colour | GET .../inventory/batches (expired[] array) | inventory.manage OR products.view |
| 23 | Hide the warehouse / batch modules for sellers the marketplace does not run them for | inventory | Yes — warehouses_enabled / batches_enabled from the overview gate both sections (server-driven, not a local flag) | warehouses_enabled / batches_enabled computed per seller in .../inventory/overview | inventory.manage OR products.view |
| 24 | Open a dedicated Inventory screen from the main navigation | inventory | Yes — More menu → 'inventory' opens InventoryScreen | n/a (navigation) | none |
| 25 | Filter the restock list by category | inventory | Yes — horizontal category chip row ('all' + each category) | POST .../restock-request-list {category_id} / ?category_id=&sub_category_id= | products.view |
| 26 | Filter the restock list by brand (multi-select, each brand showing its request count) | inventory | Yes — brand checkbox list in the filter sheet, fed by a dedicated brands endpoint that returns product_count per brand | GET /api/v3/seller/products/restock-request-brands-list + POST .../restock-request-list {brand_ids:[…]} | products.view |
| 27 | Filter the restock list by request date range | inventory | Yes — SfDateRangePicker writing restock_start_date / restock_end_date | POST .../restock-request-list {restock_start_date, restock_end_date} / ?restock_date= | products.view |
| 28 | Clear all restock filters at once | inventory | Yes — 'clear_filter' resets dates + brand checkboxes and reloads page 1 | n/a (re-issues the list call with no filters) | products.view |
| 29 | Apply a stock change to many products at once (set / increase / decrease, with a note), and review the job's outcome | inventory | Yes — Bulk edit screen with stock modes set/increase/decrease and a note, plus job list and per-row failure receipt | POST /api/v3/seller/seller-center/bulk-jobs/stock (SellerBulkJobController@storeStockUpdate), GET /bulk-jobs, /bulk-jobs/{id}, /bulk-jobs/{id}/failures | inventory.manage (stock job); products.view/manage/inventory.manage to read jobs |
| 30 | Delegate stock-only access to a staff member (inventory.manage without product editing rights) | inventory | Yes — every inventory/stock API route is gated on inventory.manage, so an inventory-only key or staff member works in the app | seller_can:inventory.manage middleware on the v3 seller routes; SellerPermissionService catalog exposes the key and the web staff UI can grant it | inventory.manage |
| 31 | Load the conversation list incrementally (pagination / infinite scroll) | notifications_chat | Yes — PaginatedListViewWidget with limit=30 and offset paging | App: GET /api/v3/seller/messages/list/{type}?limit&offset. Web: none (no paginator) | API: orders.view/orders.manage |
| 32 | Load older messages in a long conversation (message pagination) | notifications_chat | Yes — PaginatedListViewWidget with limit=30 pages back through history | App: get-message accepts limit+offset (validated required). Web: none | API: orders.view/orders.manage |
| 33 | See day separators inside the conversation thread | notifications_chat | Yes — a centered date chip is inserted whenever the day changes | none — presentation only | none |
| 34 | Auto-refresh the currently open conversation when the other party replies | notifications_chat | Yes — the FCM foreground listener refreshes the open thread and the inbox counts in place | App: push payload type 'chatting' carries customer_id / delivery_man_id; refresh re-hits get-message + list. Web: none (getNewNotification returns only a count and marks seen_notification=1) | Web: ALLOW for any staff |
| 35 | Page through notification history | notifications_chat | Yes — PaginatedListViewWidget, limit 20 per page | App: notification_index paginates. Web: none | API: orders.view/products.view/finance.view |
| 36 | Pick a country dial code for the emergency contact phone number | notifications_chat | Yes — CodePickerWidget prefixed to the phone field; the stored value is dialCode + number | the API stores whatever string it is given (no normalisation) — app/Http/Controllers/RestAPI/v3/seller/EmergencyContactController.php:38-43 | orders.manage |
| 37 | Search emergency contacts by name or phone | notifications_chat | Yes — search field at the top of the emergency contact screen, hitting the list endpoint with ?search= | Supported: EmergencyContactController@list applies a name/phone LIKE filter when 'search' is present | orders.manage |
| 38 | View the operational issue queue arranged in ordered sections (critical_now, needs_action_today, sla_risk, fulfillment_exceptions, returns_requiring_action, financial_exceptions, inventory_risk, catalog_and_pricing, recently_auto_resolved) | control_tower | Yes — lib/features/control_tower/screens/control_tower_screen.dart:37 (ControlTowerScreen), renders tower.sections at :77 via _section() at :94 | GET /api/v3/seller/seller-center/control-tower — SellerControlTowerController::index (app/Http/Controllers/RestAPI/v3/seller/SellerControlTowerController.php:48) → ControlTowerService::forSeller (app/Services/SellerIntelligence/ControlTowerService.php:41, sections built at :47-72) | orders.view (routes/rest_api/v3/seller.php:448) |
| 39 | See per-section issue count and the affected-record count ('37 products require action' rather than '4 issues') | control_tower | Yes — control_tower_screen.dart:100-108 prints section.affected when it exceeds section.count; model TowerSectionModel at control_tower_models.dart:96-121 | ControlTowerService::section() returns count + affected (app/Services/SellerIntelligence/ControlTowerService.php:178-207) | orders.view |
| 40 | Change an issue's working status — acknowledge it, mark in progress, park as waiting, or reopen | control_tower | Yes — PopupMenuButton with the four seller-settable statuses at lib/features/control_tower/widgets/control_tower_widgets.dart:99-111; wired through ControlTowerController.setStatus (controllers/control_tower_controller.dart:63) and repository.updateIssueStatus (domain/repositories/control_tower_repository.dart:18) | PUT /api/v3/seller/seller-center/control-tower/issues/{id}/status — SellerControlTowerController::updateStatus (app/Http/Controllers/RestAPI/v3/seller/SellerControlTowerController.php:82). Note it also silently assigns the issue to the acting principal when no assigned_staff_id is sent (:107-109) — the web must reproduce that ownership side effect | orders.manage (routes/rest_api/v3/seller.php:454-456) |
| 41 | Per-domain health board — 8 operational categories (orders, inventory, catalog, pricing, returns, shipping, finance, integrations) each showing healthy / watch / degraded / critical plus the open count | control_tower | Yes — DomainHealthGridWidget at lib/features/control_tower/widgets/control_tower_widgets.dart:200-236, rendered under the 'system_health' heading at control_tower_screen.dart:81-84 | ControlTowerService::health (app/Services/SellerIntelligence/ControlTowerService.php:87-112); categories at app/Models/SellerInsight.php:74-78 | orders.view |
| 42 | See that an issue was escalated by the platform because nobody answered it (escalation_level badge) | control_tower | Yes — 'escalated' tag rendered when wasEscalated at lib/features/control_tower/widgets/control_tower_widgets.dart:84-85; model getter at control_tower_models.dart:88 | IssueEscalationService swept by `seller:escalate-issues` every four hours (app/Console/Commands/EscalateSellerIssues.php:18; schedule at bootstrap/app.php:170); field surfaced in the tower payload at app/Services/SellerIntelligence/ControlTowerService.php:198 | orders.view |
| 43 | See an issue's deadline and whether it is overdue (due_at / is_overdue) | control_tower | Yes — due/overdue tag at lib/features/control_tower/widgets/control_tower_widgets.dart:75-82 | due_at + is_overdue in the section payload (app/Services/SellerIntelligence/ControlTowerService.php:196-197); 'needs_action_today' section built from due_at within 24h (:52-56) | orders.view |
| 44 | See the money at stake on an issue (impact amount, currency-formatted) | control_tower | Yes — impact tag at lib/features/control_tower/widgets/control_tower_widgets.dart:73-74 via PriceConverter | impact + impact_score in section payload (app/Services/SellerIntelligence/ControlTowerService.php:194-195) | orders.view |
| 45 | See the 'recently auto-resolved' section — issues the platform closed by itself in the last 7 days, so the self-healing claim is checkable | control_tower | Yes — rendered like any other section (control_tower_screen.dart:77 iterates tower.sections, which includes recently_auto_resolved from the server) | ControlTowerService::recentlyResolved (app/Services/SellerIntelligence/ControlTowerService.php:149-161), exposed as sections.recently_auto_resolved (:71) | orders.view |
| 46 | Section overflow indicator — '+N more waiting' when a section holds more issues than the rows returned (server caps at 20) | control_tower | Yes — control_tower_screen.dart:120-127; same pattern on the home card at action_center_card_widget.dart:86-89 | SECTION_LIMIT = 20 (app/Services/SellerIntelligence/ControlTowerService.php:30), rows truncated at :183 | orders.view |
| 47 | Open the record an issue is about — deep-link from an issue into the order detail screen or the product editor (action_key open_order / open_product) | control_tower | Yes — InsightActionHandler.open at lib/features/action_center/widgets/insight_action_handler.dart:25, open_order at :32, open_product at :39 (loads the product first at :51 before opening AddProductTabView); Control Tower reuses the same handler via control_tower_screen.dart:116 | action_key + action_params emitted per issue (app/Services/SellerIntelligence/ControlTowerService.php:199-200; app/Http/Controllers/RestAPI/v3/seller/SellerActionCenterController.php:65-66) | orders.view / products.view |
| 48 | Read the moderator's rejection reasons for a rejected listing (structured reason_codes plus free-text note) before choosing to fix it | control_tower | Yes — _showRejection dialog at lib/features/action_center/widgets/insight_action_handler.dart:71-116; lists translated reason codes at :86-90, the note at :91-96, and says 'no reason recorded' rather than rendering blank at :99-102 | ListingQualityProducer reads product_moderation_events.reason_codes + note (app/Services/SellerIntelligence/Producers/ListingQualityProducer.php:145-170) and puts them in action_params (:87,98) | products.view |
| 49 | Daily briefing — today's order count and revenue with yesterday beside them and a day-over-day percentage that renders '—' when there is no comparable prior day | control_tower | Yes — DailyBriefingWidget at lib/features/control_tower/widgets/control_tower_widgets.dart:135-197; metrics at :146-153, the null-safe change line at :181-196 | GET /api/v3/seller/seller-center/control-tower/briefing — SellerControlTowerController::briefing (app/Http/Controllers/RestAPI/v3/seller/SellerControlTowerController.php:66) → DailyBriefingService::forSeller (app/Services/SellerIntelligence/DailyBriefingService.php:36, dayFigures at :69, change at :50) | orders.view |
| 50 | Briefing queue counters — awaiting shipment, SLA at risk, returns to answer, low stock, withdrawable balance in one block | control_tower | Yes — ReportCardWidget rows at lib/features/control_tower/widgets/control_tower_widgets.dart:162-177 (awaiting_shipment, sla_at_risk, returns_to_answer, low_stock_products, withdrawable_balance) | DailyBriefingService::waiting (app/Services/SellerIntelligence/DailyBriefingService.php:100-135); SLA window is the same 6h the severity engine uses (:123) | orders.view |
| 51 | Briefing standing-issue summary — how many issues are critical right now and how many fall due today | control_tower | Partial — parsed into DailyBriefingModel.criticalIssues / dueToday (lib/features/control_tower/domain/models/control_tower_models.dart:230-231, 260-261) but DailyBriefingWidget never renders them; the seller reads the same facts off the tower's sections instead | ControlTowerService::summary (app/Services/SellerIntelligence/ControlTowerService.php:119-134), embedded in the briefing at DailyBriefingService.php:52 | orders.view |
| 52 | Briefing cancelled / returned counts for today | control_tower | Partial — parsed at lib/features/control_tower/domain/models/control_tower_models.dart:225-226 but not rendered by DailyBriefingWidget | DailyBriefingService::dayFigures (app/Services/SellerIntelligence/DailyBriefingService.php:86-87) | orders.view |
| 53 | Action Center — one flat list of everything open, worst first, read from the single insight store | control_tower | Yes — ActionCenterScreen at lib/features/action_center/screens/action_center_screen.dart:22; list at :81-95; controller at controllers/action_center_controller.dart:33 | GET /api/v3/seller/seller-center/action-center — SellerActionCenterController::index (app/Http/Controllers/RestAPI/v3/seller/SellerActionCenterController.php:41); route at routes/rest_api/v3/seller.php:587-595 | orders.view,products.view (routes/rest_api/v3/seller.php:588) |
| 54 | Filter the Action Center by severity (all / critical / high / medium / low) with the open count shown on each chip | control_tower | Yes — InsightSeverityFilterWidget at lib/features/action_center/widgets/action_center_widgets.dart:119-170; wired at action_center_screen.dart:46-52; severity passed as a query param at domain/repositories/action_center_repository.dart:16 | severity query param validated against SEVERITY_ORDER (app/Http/Controllers/RestAPI/v3/seller/SellerActionCenterController.php:106-111); counts returned at :53 | orders.view,products.view |
| 55 | Dismiss an insight, with critical insights explicitly non-dismissible | control_tower | Yes — dismiss button rendered only when insight.dismissible (lib/features/action_center/widgets/action_center_widgets.dart:76-81); screen passes null for non-dismissible rows at action_center_screen.dart:92; POST at domain/repositories/action_center_repository.dart:25-27 | POST /api/v3/seller/seller-center/action-center/{id}/dismiss — SellerActionCenterController::dismiss (app/Http/Controllers/RestAPI/v3/seller/SellerActionCenterController.php:85); dismissible computed server-side at :68 (severity !== critical) | orders.view,products.view |
| 56 | Insight metric rendered in the words of its own type — hours left / hours late for ORDER_SLA, score /100 for LISTING_QUALITY, remaining stock for INVENTORY_RISK | control_tower | Yes — type-aware switch at lib/features/action_center/widgets/action_center_widgets.dart:98-112, returns null rather than a placeholder for types with no figure | metric emitted per insight (app/Http/Controllers/RestAPI/v3/seller/SellerActionCenterController.php:63); produced by app/Services/SellerIntelligence/Producers/ (12 producers, e.g. OrderSlaProducer.php, ListingQualityProducer.php, InventoryRiskProducer.php) | orders.view,products.view |
| 57 | Home surface for what needs attention — a card that appears only when something is waiting, previews the top 3 insights, shows an urgent (critical+high) badge and links to the full list | control_tower | Yes — ActionCenterCardWidget at lib/features/action_center/widgets/action_center_card_widget.dart:22; renders nothing when empty at :31; urgent badge at :51-58; 'view all' at :64-67; mounted on home at lib/features/home/screens/home_page_screen.dart:148 (above the order lists on purpose) | Same action-center endpoint; urgentCount derived client-side from counts (lib/features/action_center/domain/models/seller_insight_model.dart:110) | orders.view,products.view |
| 58 | Out-of-stock / limited-stock products surfaced on the dashboard as an openable list of the actual products | control_tower | Yes — StockOutProductView(isHome: true) at lib/features/home/screens/home_page_screen.dart:170, fed by ProductController.getStockOutProductList (:57) and getStockLimitStatus (:67) | App: product stock-limit endpoints in the products domain. Web: VendorDashboardStatsService::inventoryAlerts | products.view |
| 59 | Most popular products block on the dashboard | control_tower | Yes — MostPopularProductScreen(isMain: true) at lib/features/home/screens/home_page_screen.dart:182, loaded at :70 | App: most-popular product endpoint in the products domain (ProductController.getMostPopularProductList). Web: none | products.view |

### Wave 3 — Automation (35)

| # | Capability | Domain | Where it exists today | Server it calls | Permission |
|---:|---|---|---|---|---|
| 1 | Reach an automation console at all (rules + activity, one destination) | automation | Yes — More menu entry pushes AutomationScreen (tabs: Rules / What automation did) | routes/rest_api/v3/seller.php:564-581 → App\Http\Controllers\RestAPI\v3\seller\SellerAutomationController | products.view or products.manage (seller_can, routes/rest_api/v3/seller.php:566) |
| 2 | List every rule the shop has written, newest first, with its state (On / Paused / Stopped) | automation | Yes — AutomationScreen rules tab, AutomationRuleCardWidget status pill | GET /api/v3/seller/seller-center/automation/rules → SellerAutomationController::index | products.view |
| 3 | Read a rule as a sentence — "when X → then Y" in plain language rather than raw keys | automation | Yes — trigger/action translated via trigger_*/action_* keys on the rule card | Same index/show payload (trigger, action fields) | products.view |
| 4 | See what a rule has actually done: times run, changes made, last run date | automation | Yes — stat row on the rule card | index/show → run_count, applied_count, last_run_at | products.view |
| 5 | Be warned that a rule runs but has never changed anything (silently mis-written rule) | automation | Yes — hasNeverActed advisory banner | Derived client-side from run_count/applied_count returned by index | products.view |
| 6 | See why a rule was stopped (breaker reason: too many matches / three failures in a row / permission revoked / shop inactive) | automation | Yes — red suspension banner on the card, reason translated | suspension_reason set by AutomationEngine::suspend, returned by index/show | products.view |
| 7 | Distinguish a marketplace hold from a breaker trip, and be told the restart is not yours to make (restart control suppressed) | automation | Yes — isStoppedByMarketplace hides the status button and shows an explanatory banner | suspended_by = platform\|marketplace; setStatus refuses a marketplace hold | products.view |
| 8 | Create a new rule | automation | Yes — FAB → AutomationRuleFormScreen → POST | POST .../automation/rules → SellerAutomationController::store → SellerAutomationRuleService::create | products.manage |
| 9 | Edit an existing rule (and have its failure count reset / breaker suspension cleared by the rewrite) | automation | Yes — tapping a card opens the same form prefilled; PUT | PUT .../automation/rules/{id} → update | products.manage |
| 10 | Delete a rule, with an explicit warning that its history of what it already did is kept | automation | Yes — confirm dialog then DELETE | DELETE .../automation/rules/{id} → destroy (runs and actions are retained) | products.manage |
| 11 | Pause a running rule | automation | Yes — Pause button → PUT status=paused | PUT .../automation/rules/{id}/status → setStatus | products.manage |
| 12 | Resume a paused rule | automation | Yes — Resume button → PUT status=active | Same setStatus endpoint | products.manage |
| 13 | Restart a rule the breaker stopped — clearing the suspension in the same act, with the reason still on screen | automation | Yes — 'Restart' label on a suspended rule, same status call; failure counter reset server-side | setStatus active → clears suspended_at/reason/suspended_by, resets consecutive_failures | products.manage |
| 14 | Run a rule now, on demand, ignoring its cooldown | automation | Yes — 'Run now' on the card (hidden while suspended); refetches rules + activity after | POST .../automation/rules/{id}/run → runNow → AutomationEngine::run | products.manage |
| 15 | Preview what a rule would do right now without doing it (dry run) | automation | Yes — 'Preview' opens a modal sheet; preview state cleared on close so a stale answer never shows under another rule | GET .../automation/rules/{id}/preview → AutomationEngine::preview (shares the trigger + action planning code with the real run) | products.view |
| 16 | In the preview, see the rows the rule would DECLINE to touch and the reason for each (already hidden, not approved, below your floor, no price…) | automation | Yes — per-subject rows with will_apply icon and translated reason | preview subjects[] carries will_apply + reason from each action's preview() | products.view |
| 17 | Be warned in the preview that the rule matches more than its own cap and would therefore refuse to run at all | automation | Yes — red 'this would stop instead of running' banner when capped | preview returns capped=true (matches counted cap+1); a real capped run does nothing and suspends the rule | products.view |
| 18 | Build a rule from the server's own catalogue of triggers/actions/settings (new server trigger appears without an app release) | automation | Yes — form fields, dropdowns and required settings all come from GET /catalogue | GET .../automation/catalogue → AutomationRegistry::catalogue | products.view |
| 19 | Choose the trigger: listing runs out of stock / is running low / was restocked after automation hid it / stock has not sold for N days | automation | Yes — 'when' dropdown over catalogue.triggers | Triggers registered in app/Services/SellerAutomation/Triggers/* | products.manage |
| 20 | Choose the action: take the listing off the storefront / put it back / mark it down | automation | Yes — 'then' dropdown, restricted to the actions the chosen trigger legally accepts | Actions in app/Services/SellerAutomation/Actions/*; legality enforced by AutomationRegistry::accepts | products.manage |
| 21 | Set the trigger's own threshold settings (stock level, days without a sale) | automation | Yes — numeric setting fields rendered from the trigger's declared settings | Validated per-trigger; keys not declared are dropped | products.manage |
| 22 | Set the markdown action's parameters: percent or flat, the discount value, and a hard price floor it must never cross | automation | Yes — three setting fields; discount_type kept as free text, the rest numeric | SetDiscountAction rules; floor is required and the action refuses rather than clamps; the shop-wide PricingPolicyService floor is checked too | — |
| 23 | Cap how many records one run may change (blast radius) | automation | Yes — 'most changes in one run' field, default 50 | max_actions_per_run 1..500; a run that would exceed it does NOTHING and trips the breaker | products.manage |
| 24 | Set how long a rule waits between runs (cooldown) | automation | Yes — 'wait between runs (minutes)' field, default 15 | cooldown_minutes 5..10080, honoured by the scheduled sweep via rule->isDue() | products.manage |
| 25 | Get field-level validation errors back on the form (including dotted settings keys like action_settings.min_price_after_discount) | automation | Yes — errors parsed by code and attached to the matching input | 403 with errors:[{code: field, message}] | products.manage |
| 26 | Read the trail of everything automation did to the shop ("who changed this" when the answer is not a person) | automation | Yes — second tab, AutomationActivityTileWidget list | GET .../automation/activity → SellerAutomationController::activity | products.view |
| 27 | See the before → after value of each automated change on a record | automation | Yes — rendered on applied rows (price_after_discount deliberately hidden from the pair) | before/after JSON columns on seller_automation_actions | products.view |
| 28 | See why a matched record was skipped or failed rather than changed | automation | Yes — translated reason under non-applied rows | status (applied/skipped/failed) + reason on each action row | products.view |
| 29 | Undo one automated change (restore the value the rule replaced) | automation | Yes — 'Undo' on revertible rows; refetches the trail after | POST .../automation/activity/{id}/revert → AutomationEngine::revert; restores only the columns the action declares it owns | products.manage |
| 30 | Know when a change can no longer be undone — already undone, or someone has touched the record since | automation | Yes — 'Undone' label, and the Undo control is absent when revertible=false | revertible computed from status/reverted_at/superseded_at; superseded_at set by AutomationClaimObserver on any status write | products.view |
| 31 | Rules run unattended on a schedule (the whole point of the feature) — seller sets the cadence and reads last-run | automation | Partial — sets cooldown and reads last_run_at/last_fired_at, but there is no 'next run' or sweep-health view | php artisan seller:run-automation → AutomationEngine::runDue (sweep limit 200, cooldown-gated) | products.manage |
| 32 | Open a rule and read its recent run history (outcome, matched/applied/skipped/failed per run) | automation | Partial — repository + controller + AutomationRunModel exist and call GET rules/{id}, but no screen ever invokes loadRuns(); the runs list is unreachable in the UI | GET .../automation/rules/{id} → show, returns last 20 runs | products.view |
| 33 | Filter the trail to one rule's activity | automation | Partial — repository accepts rule_id and builds the query string, but no screen passes it (no filter control on the activity tab) | GET .../automation/activity?rule_id= (also supports status=, and paginates) | products.view |
| 34 | Empty-state guidance explaining what rules are and that the trail is empty because nothing has run | automation | Yes — two distinct empty states with explanatory body copy | none (client-side, driven by empty collections) | none |
| 35 | Staff-permission gating of the console (view vs. manage, and a rule may only use an action the writer is allowed to perform) | automation | Partial — the app relies on the API's 403s; the More-menu entry is not permission-gated client-side | seller_can middleware on every route; the action's own permission re-checked at write time and again at run time | products.view / products.manage |

### Wave 4 — Fulfilment (16)

| # | Capability | Domain | Where it exists today | Server it calls | Permission |
|---:|---|---|---|---|---|
| 1 | See every refund request in one unfiltered list ("All", no status filter) | returns_refunds | Yes — 'All' tab at /home/user/sillercenter-syria-cosmatics/lib/features/refund/screens/refund_screen.dart:241 with refundTypeIndex==0 showing the full list (refund_screen.dart:69-70) | Present — the API returns everything when date_type/status are absent (RestAPI/v3/seller/RefundController.php:46-75); web repo filters on the route status (app/Repositories/RefundRequestRepository.php:80-82) | orders.manage |
| 2 | Filter refund requests by quick date preset (today / this week / this month / all time) | returns_refunds | Yes — app-bar popup menu at /home/user/sillercenter-syria-cosmatics/lib/features/refund/screens/refund_screen.dart:120-227 sending date_type=today\|this_week\|this_month\|all | API supports date_type presets (app/Http/Controllers/RestAPI/v3/seller/RefundController.php:59-71); the web controller only reads from_date/to_date (app/Http/Controllers/Vendor/RefundController.php:67-76) | orders.manage |
| 3 | See and contact the customer behind a refund request (name, photo, tap-to-call phone, email) from the refund itself | returns_refunds | Yes — CustomerInfoWidget /home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/customer_info_widget.dart:52-95 with tel: launch at :68 | customer relation already loaded on both paths (RestAPI/v3/seller/RefundController.php:46; app/Http/Controllers/Vendor/RefundController.php:79) | orders.manage |
| 4 | Approving a refund opens a return (RMA) so the physical goods are tracked back and can be restocked | returns_refunds | Yes — approving through the app triggers it server-side (/home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/approve_reject_widget.dart:121 → POST refund-status-update) | API only: app/Http/Controllers/RestAPI/v3/seller/RefundController.php:206-208 calling openReturnFor() at :225-257 → ReturnLogisticsService::authorizeForRefund (app/Services/Marketplace/ReturnLogisticsService.php:183-204) | orders.manage |
| 5 | Browse the return shipments (goods physically coming back) for this shop | returns_refunds | Yes — ReturnsScreen /home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/returns_screen.dart:29,85-96, reached from the More menu (/home/user/sillercenter-syria-cosmatics/lib/features/menu/screens/more_screen.dart:124-126) | GET /api/v3/seller/seller-center/returns (routes/rest_api/v3/seller.php:639-642 → app/Http/Controllers/RestAPI/v3/seller/SellerReturnController.php:49-70). Admin-only web equivalent exists at routes/admin/routes.php:706-713 → Admin/Marketplace/ReturnLogisticsController.php | orders.view or orders.manage to read (routes/rest_api/v3/seller.php:641-643) |
| 6 | Filter returns by state: authorized / in transit / received / restocked / rejected | returns_refunds | Yes — choice chips built from the server's status list /home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/returns_screen.dart:41-55, 105-122 | status query param validated against the five states (app/Http/Controllers/RestAPI/v3/seller/SellerReturnController.php:316-332); statuses list returned at :66 | orders.view / orders.manage |
| 7 | Open one return and see its reference, quantity, reason, linked order, tracking number, received date and internal note | returns_refunds | Yes — ReturnDetailsScreen /home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/return_details_screen.dart:152-182 | GET /api/v3/seller/seller-center/returns/{id} (routes/rest_api/v3/seller.php:642 → SellerReturnController.php:84-104, payload assembled at :94-101 and :298-313) | orders.view / orders.manage |
| 8 | Mark an authorized return as in transit and record the customer's tracking number | returns_refunds | Yes — dialog with tracking field /home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/return_details_screen.dart:104-134, button shown only while status == authorized (:218-228) | POST /api/v3/seller/seller-center/returns/{id}/in-transit (routes/rest_api/v3/seller.php:645 → SellerReturnController.php:116-137 → ReturnLogisticsService::markInTransit, app/Services/Marketplace/ReturnLogisticsService.php:61-74) | orders.manage (routes/rest_api/v3/seller.php:645) |
| 9 | Record which carrier is bringing a return back | returns_refunds | Partial — the API call accepts a carrier (/home/user/sillercenter-syria-cosmatics/lib/features/returns/domain/repositories/return_repository.dart:36-39) but the in-transit dialog collects only a tracking number, so carrier is never sent (return_details_screen.dart:112-118, 133) | Supported and persisted: carrier validated at app/Http/Controllers/RestAPI/v3/seller/SellerReturnController.php:124-127 and written at app/Services/Marketplace/ReturnLogisticsService.php:67-71 | orders.manage |
| 10 | Receive returned goods and decide at receipt whether they can be sold again (restock yes/no) | returns_refunds | Yes — receive dialog asks 'can these goods be sold again?' with No-do-not-restock / Yes-restock (/home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/return_details_screen.dart:44-67, button at :229-233) | POST .../returns/{id}/receive with restock flag (routes/rest_api/v3/seller.php:646 → SellerReturnController.php:151-175, :161-163 applies the flag) → ReturnLogisticsService::receive restocks under a row lock and writes a `return` stock movement (app/Services/Marketplace/ReturnLogisticsService.php:80-146) | orders.manage (routes/rest_api/v3/seller.php:646) |
| 11 | See whether a received return was actually restocked, and when it arrived | returns_refunds | Yes — received_at row and a green 'restocked: yes' row (/home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/return_details_screen.dart:170-179), plus distinct status chip colours for received vs restocked (widgets/return_widgets.dart:33-39) | status restocked\|received and received_at in the summary payload (app/Http/Controllers/RestAPI/v3/seller/SellerReturnController.php:302-311; service sets them at ReturnLogisticsService.php:133-136) | orders.view / orders.manage |
| 12 | Refuse a return with a required reason when what came back is not acceptable | returns_refunds | Yes — reject dialog with a 255-char reason, empty reason blocked client-side (/home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/return_details_screen.dart:69-102, button at :235-241) | POST .../returns/{id}/reject, reason required\|string\|max:255 (routes/rest_api/v3/seller.php:647 → SellerReturnController.php:187-202 → ReturnLogisticsService::reject, app/Services/Marketplace/ReturnLogisticsService.php:148-162) | orders.manage (routes/rest_api/v3/seller.php:647) |
| 13 | Set the delivery man's country dial code (country_code) | shipping_delivery | Yes — CountryCodePicker next to the phone field; country_code is posted with the form | POST /api/v3/seller/delivery-man/store, PUT delivery-man/update/{id} (country_code is a required field on the API update validator) | orders.manage |
| 14 | See whether a delivery man is currently online or offline | shipping_delivery | Yes — green check 'online' / red 'offline' badge on every delivery man card | Served by the same GET /api/v3/seller/delivery-man/list payload (DeliveryMan model exposes is_online); web repo/view simply never reads it | orders.manage |
| 15 | See a delivery man's withdrawable balance | shipping_delivery | Yes — 'withdrawable_balance' row, fed by details.withdrawbale_balance | GET /api/v3/seller/delivery-man/details/{id} computes it via CommonTrait::delivery_man_withdrawable_balance; the vendor earning page computes it correctly, the overview page does not | orders.manage |
| 16 | Search emergency contacts | shipping_delivery | Yes — search field at the top of EmergencyContactScreen | GET /api/v3/seller/delivery-man/emergency-contact/list?search= (already supported server-side) | orders.manage |

### Wave 5 — Finance (15)

| # | Capability | Domain | Where it exists today | Server it calls | Permission |
|---:|---|---|---|---|---|
| 1 | Filter withdraw request history by a custom from/to date range (and clear the filter) | finance | Yes — showDateRangePicker + TransactionController.applyDateRange, sending from/to to the transactions endpoint; a clear-filter icon resets it | GET /api/v3/seller/transactions?status=&from=&to= — already supports the range (whereBetween on created_at) | seller_can:finance.view |
| 2 | See the already-paid-out total and the total ledger balance alongside the other buckets | finance | Yes — 'already_paid_out' and 'total_balance' rows under the payout balance card | GET /api/v3/seller/seller-center/payouts returns balances.paid and balances.balance; the same VendorLedger::balances() is already passed into the web view | seller_can:finance.view |
| 3 | Be told a payout is blocked because KYC verification is not complete, before submitting | finance | Yes — payout_eligible drives a 'kyc_verification_required' notice and disables the request button | payout_eligible is returned by the API (SellerVerificationService::isPayoutEligible); the same service is enforced in PayoutService but not surfaced to the web view | seller_can:finance.view |
| 4 | Read the account statement line by line, each line carrying the running balance the ledger recorded (balance_after), the entry type, the credit/debit and the payout/settlement reference | finance | Yes — StatementScreen entry list | GET /api/v3/seller/seller-center/statement (SellerStatementController::index) — exists, mobile-only consumer | seller_can:finance.view |
| 5 | See the whole-account statement buckets (pending, available, reserved, paid, balance, withdrawable) that deliberately do not follow the filter | finance | Yes — top of StatementScreen | summary.buckets + withdrawable from SellerLedgerStatementService::summary via GET .../statement | seller_can:finance.view |
| 6 | Filter the statement by entry type using the server-supplied type list | finance | Yes — choice chips built from statement.entry_types plus an 'all' chip | entry_type query param + entry_types list on GET .../statement | seller_can:finance.view |
| 7 | Filter the statement by date range, with a clear action, and read the range totals (entries, credited, debited, net) | finance | Yes — date range picker + clear, and a range totals card under the filter | from/to query params (validated as plain calendar dates) and summary.range on GET .../statement | seller_can:finance.view |
| 8 | Download the statement as a CSV under the currently applied filters | finance | Yes — download icon in the app bar; the bytes are saved to Downloads/app documents and the path is reported | GET /api/v3/seller/seller-center/statement/export (SellerStatementController::export, capped at 5,000 rows) | seller_can:finance.view |
| 9 | Open the order that produced a statement line (drill from a ledger entry back to its source order) | finance | Yes — tapping an entry with an order_id pushes OrderDetailsScreen | order_id on each statement row from SellerLedgerStatementService::rows | seller_can:finance.view,orders.view |
| 10 | Reconciliation: does what I sold add up to what I was paid — delivered lines vs recorded earnings vs credited ledger entries, with named gaps and openable samples | finance | Yes — 'does_it_add_up' tab of FinanceControlScreen | GET /api/v3/seller/seller-center/finance/reconciliation (SellerFinanceControlController::reconciliation) | seller_can:finance.view |
| 11 | Fee simulator: what a sale at a considered price would cost — gross, discount, marketplace commission, seller receives, effective rate, applied rule and the named exclusions | finance | Yes — 'fee_simulator' tab with price / quantity / discount inputs and a Work-it-out action | GET /api/v3/seller/seller-center/finance/fee-simulator (SellerFinanceControlController::feeSimulator) | seller_can:finance.view |
| 12 | Price change history: every recorded move of a product's price/discount with previous → new, delta, source (own edit, panel, bulk job, rule, promotion), reason and actor | finance | Yes — 'price_history' tab, filterable by product via loadPriceChangesFor | GET /api/v3/seller/seller-center/finance/price-changes (SellerFinanceControlController::priceChanges) | seller_can:products.view,products.manage |
| 13 | SETTING — read the shop's own price floor policy: minimum margin over cost %, absolute minimum price, whether it is enforced, whether it actually binds, and how much of the catalogue has a recorded cost | finance | Yes — 'price_floor' tab, with explicit warnings for 'on but empty' and 'margin covers nothing' | GET /api/v3/seller/seller-center/finance/pricing-policy (SellerFinanceControlController::pricingPolicy) | seller_can:products.view,products.manage |
| 14 | TOGGLE — set the price floor: save min margin % / min price and switch enforcement on or off (the switch saves immediately and the policy is re-read from the server) | finance | Yes — SwitchListTile 'enforce_this_floor' plus a Save button, both calling savePricingPolicy | PUT /api/v3/seller/seller-center/finance/pricing-policy (SellerFinanceControlController::savePricingPolicy) | seller_can:products.manage |
| 15 | See what a refund did to the seller's own balance — the money debited and the commission credited back | returns_refunds | Yes — 'effect on your balance' ledger block on the return detail (/home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/return_details_screen.dart:184-205) with the commission-credit explainer at :200-203 | Returned with each return: app/Http/Controllers/RestAPI/v3/seller/SellerReturnController.php:102 → ledgerFor() at :247-267 (VendorLedgerEntry TYPE_REFUND + TYPE_COMMISSION_CHARGE) | orders.view / orders.manage |

### Wave 6 — Trust (23)

| # | Capability | Domain | Where it exists today | Server it calls | Permission |
|---:|---|---|---|---|---|
| 1 | See every brand my shop lists under, with the number of listings carrying each brand | brands_compliance | Yes — BrandClaimsScreen exposure list (lib/features/brand_claims/screens/brand_claims_screen.dart:60-116) | GET /api/v3/seller/seller-center/brand-claims/exposure → SellerBrandClaimController::exposure; data from BrandRegistryService::brandExposure() | products.view or products.manage |
| 2 | Know whether the marketplace is currently ENFORCING brand claims (refusing listings) or only reporting mismatches | brands_compliance | Yes — banner switches between brand_enforcement_is_on/off (brand_claims_screen.dart:74-82) | `enforcing` field on GET .../brand-claims/exposure; BrandRegistryService::isEnforcing() reads business_settings 'brand_claim_enforcement' | products.view or products.manage |
| 3 | See, per brand, whether my listings are allowed, blocked, or held by another seller | brands_compliance | Yes — colour-coded row + one of three messages (brand_claims_screen.dart:105-128) | `may_list` per row on GET .../brand-claims/exposure (BrandRegistryService::mayList) | products.view or products.manage |
| 4 | Brands that need action are surfaced first instead of being buried in an alphabetical list | brands_compliance | Yes — brandsNeedingAction prepended (brand_claims_screen.dart:60-63) | none (client-side ordering over the exposure payload) | products.view or products.manage |
| 5 | See my own claim on each brand: its status and the claimed relationship type | brands_compliance | Yes — 'your_claim: <status> · <type>' line (brand_claims_screen.dart:130-137) | GET /api/v3/seller/seller-center/brand-claims → SellerBrandClaimController::index (present(): status, claim_type, is_editable, is_pending, entitles) | products.view or products.manage |
| 6 | Read the marketplace reviewer's note on a claim (why it was rejected / what more is needed) | brands_compliance | Yes — review_note rendered under the claim line (brand_claims_screen.dart:138-141) | `review_note` in the claim payload (SellerBrandClaimController::present) | products.view or products.manage |
| 7 | See how many evidence documents are attached to a claim | brands_compliance | Yes — '<n> documents_attached' (brand_claims_screen.dart:142-150) | `documents[]` in the claim payload | products.view or products.manage |
| 8 | Start a brand claim, choosing the relationship: owner / authorized reseller / distributor | brands_compliance | Yes — claim sheet dropdown (brand_claims_screen.dart:218-234), saved via saveClaim | POST /api/v3/seller/seller-center/brand-claims → SellerBrandClaimController::store (claim_type in owner\|authorized_reseller\|distributor) | products.manage |
| 9 | Explain the brand relationship in a free-text statement attached to the claim | brands_compliance | Yes — 3-line 'explain_the_relationship' field (brand_claims_screen.dart:236-245) | `statement` (nullable, max 2000) on POST .../brand-claims | products.manage |
| 10 | Edit a claim that is still editable (draft or rejected) and re-save it | brands_compliance | Yes — button text flips to 'edit_claim' when claim.isEditable (brand_claims_screen.dart:155-163) | POST .../brand-claims re-drafts the existing row; BrandRegistryService::draft refuses to rewrite a claim already with the marketplace | products.manage |
| 11 | Attach a typed evidence document to a claim (trademark certificate, authorization letter, invoice) — PDF/JPG/PNG | brands_compliance | Yes — three add buttons + file picker upload (brand_claims_screen.dart:279-289,319-344) | POST /api/v3/seller/seller-center/brand-claims/{id}/documents → SellerBrandClaimController::attach (mimes pdf,jpg,jpeg,png, max 10 MB, private disk) | products.manage |
| 12 | Remove an attached evidence document from a claim | brands_compliance | Yes — per-document close icon (brand_claims_screen.dart:260-277) | DELETE /api/v3/seller/seller-center/brand-claims/{id}/documents/{documentId} → SellerBrandClaimController::detach | products.manage |
| 13 | Hand a completed claim to the marketplace for human review | brands_compliance | Yes — 'send_for_review' button (brand_claims_screen.dart:164-171) | POST /api/v3/seller/seller-center/brand-claims/{id}/submit → SellerBrandClaimController::submit | products.manage |
| 14 | Withdraw a claim that is still pending review, so it can be edited again | brands_compliance | Yes — 'take_it_back' button shown while claim.isPending (brand_claims_screen.dart:172-178) | POST /api/v3/seller/seller-center/brand-claims/{id}/withdraw → SellerBrandClaimController::withdraw | products.manage |
| 15 | Be told up front that a claim needs at least one document before it can be sent (rather than being refused at submit) | brands_compliance | Yes — hint text plus the submit button only rendering when documents exist (brand_claims_screen.dart:247-258,164-171) | BrandRegistryService::submit returns 'brand_claim_needs_evidence' when the claim has no documents | products.manage |
| 16 | Be blocked (with a clear reason) from saving a product under a brand the shop is not entitled to, while enforcement is on | brands_compliance | Yes — the seller API refuses the save and surfaces the brand_id error | ProductController::brandClaimGuard on the v3 seller product create/update only | products.manage |
| 17 | Act on a 'listings under a brand you are not entitled to' issue from the issue feed (tap through to the claim screen) | brands_compliance | Partial — the insight is listed in Action Center / Control Tower, but InsightActionHandler has no case for open_brand_claims, so the card is a dead end; the seller must reach Brands from the More menu | BrandComplianceProducer emits BRAND_COMPLIANCE insights with actionKey open_brand_claims; surfaced by GET /api/v3/seller/seller-center/control-tower and by the web ControlTowerService | orders.view (control tower) / products.view (catalog section) |
| 18 | See exactly which required documents are still outstanding (required minus already-approved) | brands_compliance | Yes — chips of outstanding types only (verification_screen.dart:83-106, computed in the model) | none — derived client-side from required_documents + documents[] | API: seller_owner. Web: seller panel guard |
| 19 | Clear the date filter in one action and return to the default range | brands_compliance | Yes — Reset control appears once a date is picked (vat_filter_bottomsheet.dart:75-89) | both default to the last 7 days when no dates are supplied | API: finance.view. Web: seller guard only |
| 20 | Copy a coupon code to the clipboard so it can be sent to a customer | growth_reviews | Yes — tap-to-copy on both the list card and the details dialog, with confirmation snackbar | none (client-side) | promotions.manage |
| 21 | See the raw order-outcome counts behind the scorecard rates (delivered / canceled / returned / failed) | growth_reviews | Yes — dedicated detail rows under the rate bars | Both served by SellerScorecardService::scorecard() which returns delivered/canceled/returned/failed | API: orders.view\|…\|finance.view; web: ALLOW |
| 22 | See the shop-wide view→cart conversion rate for the window | growth_reviews | Yes — a dedicated 'view_to_cart_rate' card computed from summary.cartAdds/summary.productViews | Derivable from the same summary payload on both sides; no extra endpoint needed | API: finance.view\|orders.view; web: staff DENY (unmapped) |
| 23 | Reach the analytics report from the panel navigation | growth_reviews | Yes — 'analytics' entry in the More menu under finance_and_reports | Route exists: GET vendor.analytics.index (routes/vendor/routes.php:101) | API: finance.view\|orders.view; web: none reachable |

### Wave 7 — Enterprise (16)

| # | Capability | Domain | Where it exists today | Server it calls | Permission |
|---:|---|---|---|---|---|
| 1 | See who currently holds a way into this shop (owner + every staff member) and whether each has a live session right now | security_integrations | Yes — Security Centre "People" tab, access-holder list: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:107-113 | GET /api/v3/seller/seller-center/security/access → SellerSecurityController::access (app/Http/Controllers/RestAPI/v3/seller/SellerSecurityController.php:325-334); data from SellerAuditTrailService::accessHolders (app/Services/Marketplace/SellerAuditTrailService.php:128-157) | staff.manage |
| 2 | See when a staff member last signed in | security_integrations | Partial — last_login_at is fetched and modelled but never rendered: /home/user/sillercenter-syria-cosmatics/lib/features/security/domain/models/security_models.dart:63 and :74 | GET .../security/access and GET .../security/staff both return last_login_at (SellerSecurityController.php:184; SellerAuditTrailService.php:153) | staff.manage |
| 3 | Edit an existing staff member (rename, move them to another role / remove their role) | security_integrations | Yes — showStaffSheet with member != null, PUT: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:567-626 (role dropdown :589-597) | PUT /api/v3/seller/seller-center/security/staff/{id} → SellerSecurityController::updateStaff (:233-263); vendor equivalent PUT vendor/business-settings/staff/{id} → SellerStaffController::updateStaff (:98-118) is unreachable from any view | staff.manage |
| 4 | Reset a staff member's password (which also ends every session that password's token was in) | security_integrations | Yes — 'new_password_optional' field in the edit sheet: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:581-587 | PUT .../security/staff/{id} password rule at SellerSecurityController.php:249; SellerTeamService::updateStaff hashes it and nulls auth_token (app/Services/Marketplace/SellerTeamService.php:144-164) | staff.manage |
| 5 | Sign a staff member out of every device without changing anything else about them (lost-phone response) | security_integrations | Yes — 'sign_out_everywhere' shown only while they hold a live token: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:120 and widgets/security_widgets.dart:217-218 | POST /api/v3/seller/seller-center/security/staff/{id}/sign-out → SellerSecurityController::signOutStaff (:274-289) → SellerTeamService::signOutStaff (app/Services/Marketplace/SellerTeamService.php:197-206) | staff.manage |
| 6 | See which permissions a role actually grants, by name | security_integrations | Yes — permission names listed on each role card, with 'can_sign_in_and_do_nothing' when empty: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:246-254 | GET .../security/roles returns the permissions array (SellerSecurityController.php:65) | staff.manage |
| 7 | See how many people hold a given role | security_integrations | Yes — staff-count pill on each role card: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:240-243 | GET .../security/roles computes staff_count (SellerSecurityController.php:67) | staff.manage |
| 8 | Rewrite an existing role (rename it, change what it grants) — takes effect on every holder's next request | security_integrations | Yes — showRoleSheet with role != null, PUT: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:521-565 and screens/security_screen.dart:158 | PUT /api/v3/seller/seller-center/security/roles/{id} → SellerSecurityController::updateRole (:110-135); vendor PUT vendor/business-settings/staff/roles/{id} → SellerStaffController::updateRole (:55-66) is unreachable | staff.manage |
| 9 | Offer only the scopes the person issuing the key actually holds (so a key can never be an escalation) | security_integrations | Yes — chips built from grantable_scopes returned by the server: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:648-660 | grantable_scopes in GET .../integrations/keys (SellerIntegrationController.php:65, :487-497); narrowed again server-side in SellerApiKeyService::grantable (:134) | shop_settings.manage |
| 10 | Show a newly issued key exactly once, with a copy action and a clear 'it is not shown again' warning | security_integrations | Yes — FreshCredentialBanner: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:13-59, mounted at screens/security_screen.dart:55-64 | POST .../integrations/keys returns the plaintext once and stores only a hash (SellerIntegrationController.php:106-113; SellerApiKeyService.php:46-64) | shop_settings.manage |
| 11 | Read the shop's activity log — what was done in this shop and by whom (including by people who have since left and keys that have since been revoked), plus decisions the marketplace recorded about the shop | security_integrations | Yes — 'Activity log' tab: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:237-293, AuditTile at widgets/security_widgets.dart:446-468 | GET /api/v3/seller/seller-center/security/audit → SellerSecurityController::audit (:348-359) → SellerAuditTrailService::recent (app/Services/Marketplace/SellerAuditTrailService.php:85-115) | staff.manage |
| 12 | Permanently delete own seller account | settings_profile | Yes — lib/features/profile/widgets/theme_changer_widget.dart:82-83 → SignOutConfirmationDialogWidget(isDelete:true) (lib/features/menu/widgets/sign_out_confirmation_dialog_widget.dart:73-85) → ProfileController.deleteCustomerAccount | DELETE\|GET /api/v3/seller/account-delete → SellerController::account_delete (routes/rest_api/v3/seller.php:91-92; SellerController.php:610-628) — exists, just not wired to the panel | seller_owner |
| 13 | Persist the chosen language on the seller account (survives device/browser change) | settings_profile | Yes — every language switch calls language-change, which writes sellers.app_language | SellerController::language_change writes app_language (SellerController.php:757-764); no web counterpart | none |
| 14 | Upload the shop's SECONDARY (bottom) banner | settings_profile | Yes — lib/features/shop/screens/shop_update_screen.dart:563-598 ('store_secondary_banner', authProvider.pickImage(secondary: true)) | EXISTS — PUT /api/v3/seller/shop-update accepts `bottom_banner` (app/Http/Requests/API/v3/ShopInfoUpdateRequest.php bottom_banner rule; SellerController.php:318) and web ShopService already persists it (app/Services/ShopService.php:41,51-52,100,111-112). Only the blade input is missing | seller_can:shop_settings.manage |
| 15 | Upload the shop's OFFER banner | settings_profile | Yes — lib/features/shop/screens/shop_update_screen.dart:630-649 ('offer_banner', authProvider.pickImage(offer: true)) | EXISTS — shop-update accepts `offer_banner` (SellerController.php:319) and ShopService persists it (app/Services/ShopService.php:42,53-54,101,113-114) | seller_can:shop_settings.manage |
| 16 | Contextual 'Business Setup Guideline' help drawer per shop tab (shop details / payment info / other setup) | settings_profile | Yes — lib/features/shop/widgets/my_shop_appbar.dart:64-82 opens lib/features/shop/widgets/business_setup_guideline.dart (expansion tiles of title+description) | None — the copy is hardcoded client-side in lib/utill/app_constants.dart:336-350 (inHouseShopGuidelineList / paymentInfoGuidelineList / otherSetupGuidelineList), so it cannot be edited from admin | none |

### Wave 8 — Platform (31)

| # | Capability | Domain | Where it exists today | Server it calls | Permission |
|---:|---|---|---|---|---|
| 1 | Switch a staff member off / back on (deactivating ends their live session and revokes the API keys they issued) | security_integrations | Yes — SwitchListTile in the edit sheet: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:598-611 | PUT .../security/staff/{id} with status → SellerSecurityController::updateStaff (:233-263) → SellerTeamService::updateStaff (app/Services/Marketplace/SellerTeamService.php:132-175, token cleared and keys revoked at :154-157) | staff.manage |
| 2 | List the shop's API keys with prefix, scopes, last-used time, last-used IP, expiry, revoked state and whether the key is still usable | security_integrations | Yes — Keys & webhooks tab: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:185-201, widgets/security_widgets.dart:265-315 (ApiKeyTile; 'never used' at :296-306) | GET /api/v3/seller/seller-center/integrations/keys → SellerIntegrationController::keys (app/Http/Controllers/RestAPI/v3/seller/SellerIntegrationController.php:47-67) | shop_settings.manage |
| 3 | Issue an API key for a name/purpose, scoped to a chosen subset of permissions | security_integrations | Yes — showKeySheet: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:628-668 | POST /api/v3/seller/seller-center/integrations/keys → SellerIntegrationController::storeKey (:80-114) → SellerApiKeyService::issue (app/Services/Marketplace/SellerApiKeyService.php:46) | shop_settings.manage |
| 4 | Revoke an API key immediately (effective on the very next request carrying it) | security_integrations | Yes — revoke button, only offered while the key is usable, with a warning dialog: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:192-201 | DELETE /api/v3/seller/seller-center/integrations/keys/{id} → SellerIntegrationController::revokeKey (:125-142) → SellerApiKeyService::revoke (:113) | shop_settings.manage |
| 5 | List webhook endpoints with their subscribed events and real health — last delivered, failing since / how many failures in a row, why the marketplace switched it off, and 'nothing has been sent to this yet' | security_integrations | Yes — WebhookTile with _health(): /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:317-400 (health text :377-392) | GET /api/v3/seller/seller-center/integrations/webhooks → SellerIntegrationController::webhooks (:171-180), presenter at :467-484 | shop_settings.manage |
| 6 | See the catalogue of events an endpoint can subscribe to | security_integrations | Yes — chips built from the server list: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:686-695 | GET /api/v3/seller/seller-center/integrations/events → SellerIntegrationController::events (:155-158), list at app/Services/Marketplace/SellerWebhookDispatcher.php:38-45 (order.placed, order.status_changed, order.refund_requested, product.stock_low, product.hidden_by_rule, payout.status_changed) | shop_settings.manage |
| 7 | Add a webhook endpoint (name, https URL, chosen events) | security_integrations | Yes — showWebhookSheet: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:670-708 | POST /api/v3/seller/seller-center/integrations/webhooks → SellerIntegrationController::storeWebhook (:192-230); https-only rule at :461, SSRF destination check at :206-212 / :442-453 | shop_settings.manage |
| 8 | Show a new endpoint's signing secret exactly once (the HMAC key the receiver verifies deliveries with) | security_integrations | Yes — same FreshCredentialBanner path, captured only on create: /home/user/sillercenter-syria-cosmatics/lib/features/security/controllers/security_controller.dart:160-162 | POST .../integrations/webhooks returns 'secret' once (SellerIntegrationController.php:214, :225-229) | shop_settings.manage |
| 9 | Edit an endpoint (change URL/name/events) — which clears its failure run and any marketplace-applied switch-off | security_integrations | Yes — showWebhookSheet with webhook != null: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:212 | PUT /api/v3/seller/seller-center/integrations/webhooks/{id} → SellerIntegrationController::updateWebhook (:242-276; failure run and disabled_reason reset at :262-270) | shop_settings.manage |
| 10 | Pause or resume an endpoint (and use 'resume' to deliberately clear a marketplace-applied disable) | security_integrations | Yes — toggle button flipping active/paused: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:214 and widgets/security_widgets.dart:364-367 | PUT /api/v3/seller/seller-center/integrations/webhooks/{id}/status → SellerIntegrationController::setWebhookStatus (:288-319); only active/paused are settable (app/Models/SellerWebhook.php:21) | shop_settings.manage |
| 11 | Delete a webhook endpoint (its delivery record survives) | security_integrations | Yes — with a dialog saying the record of what was sent stays: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:215-219 | DELETE /api/v3/seller/seller-center/integrations/webhooks/{id} → SellerIntegrationController::destroyWebhook (:330-347) | shop_settings.manage |
| 12 | Send a test delivery to an endpoint (a real, signed, queued delivery of a real event shape) | security_integrations | Yes — 'send_test' fires the endpoint's first subscribed event: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:213 | POST /api/v3/seller/seller-center/integrations/webhooks/{id}/test → SellerIntegrationController::testWebhook (:360-394), queued via DeliverSellerWebhook | shop_settings.manage |
| 13 | Browse webhook delivery attempts — event, HTTP response code (or 'no answer'), attempt count, error, response-body excerpt and the next scheduled attempt | security_integrations | Yes — DeliveryTile: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:402-444 | GET /api/v3/seller/seller-center/integrations/deliveries → SellerIntegrationController::deliveries (:407-433) | shop_settings.manage |
| 14 | Narrow the delivery log to one endpoint, then widen it back to all endpoints | security_integrations | Yes — per-endpoint 'deliveries' action plus a 'show all' header action: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:220 and :222-228 | GET .../integrations/deliveries?webhook_id= (SellerIntegrationController.php:410) | shop_settings.manage |
| 15 | Filter the activity log by area — everything / the team / the automation rules / the API keys | security_integrations | Yes — ChoiceChip row mapping to action prefixes seller.staff, seller.automation, seller.api_key: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:244-270 | GET .../security/audit?action={prefix} — prefix LIKE match at SellerAuditTrailService.php:91-93 | staff.manage |
| 16 | Product report: total sales VALUE (money) for the period | reports_bulk | Yes — 'sales_value' row in the sales card (totals.sold_amount) | totals.sold_amount, already available: SellerReportService::productReport selects SUM(qty*price) | seller_can:products.view,products.manage,inventory.manage |
| 17 | Stock report: see the low-stock threshold currently in effect for this shop | reports_bulk | Yes — printed as a value ('low_stock_threshold: N') | stock_limit in the API response; SellerReportService::stockLimitFor (seller's own setting, else platform default) | seller_can:products.view,products.manage,inventory.manage |
| 18 | Stock report: a headline count of how many products are running low | reports_bulk | Yes — 'running_low' metric tile (but see caveat: counted client-side over the loaded page only) | BACKEND GAP for a correct number: the API returns is_low_stock per row but no low-stock total; StockReportModel.lowStockCount counts only the 10 rows on the current page | seller_can:products.view,products.manage,inventory.manage |
| 19 | Stock report: see each product's unit price alongside its stock | reports_bulk | Yes — price row under every stock card | unit_price is already in the API payload and in the underlying query | seller_can:products.view,products.manage,inventory.manage |
| 20 | See the history of bulk changes this shop has run (newest first, with status and progress) | reports_bulk | Yes — BulkJobsScreen list with status chip, date and progress bar | GET /api/v3/seller/seller-center/bulk-jobs (SellerBulkJobController::index) — exists and is seller-scoped | seller_can:products.view,products.manage,inventory.manage |
| 21 | Open one bulk job's receipt: what was asked for, how far it got, how many succeeded and failed | reports_bulk | Yes — BulkJobReceiptScreen with selected/changed/not-changed metric tiles, progress bar and job error text | GET bulk-jobs/{id} (SellerBulkJobController::show) — returns counts, progress, input and failures | seller_can:products.view,products.manage,inventory.manage |
| 22 | See exactly which products a bulk change refused, and why, in the seller's own language | reports_bulk | Yes — a card per refused row with the product name and the translated reason | failures[] on GET bulk-jobs/{id}, each row carrying both the stable reason key and the translated message | seller_can:products.view,products.manage,inventory.manage |
| 23 | Download the refused rows of a bulk job as a CSV to work through offline | reports_bulk | Yes — 'download_csv' action on the receipt, saved as bulk-job-{id}-failures.csv | GET bulk-jobs/{id}/failures (SellerBulkJobController::downloadFailures) — streamed CSV | seller_can:products.view,products.manage,inventory.manage |
| 24 | Start a new bulk change and pick the products it applies to (search, infinite-scroll paging, select-all-loaded, clear selection, selection survives searching and paging) | reports_bulk | Yes — BulkEditScreen: debounced search field, scroll-to-load-more, 'select loaded (n)' button, clear-selection action, selection kept as ids in a map | GET /api/v3/seller/products/{seller_id}/all-products (limit/offset/search) — the same endpoint the product list screen uses | seller_can:products.view (picker) / products.manage or inventory.manage to apply |
| 25 | Change many product prices at once — set, increase %, decrease %, increase amount, decrease amount | reports_bulk | Yes — bottom sheet with the five mode chips and a decimal value field, naming the number of products before applying | POST /api/v3/seller/seller-center/bulk-jobs/price (SellerBulkJobController::storePriceUpdate → BulkPriceOperation). Refuses rather than clamps: a price that would land at ≤0, or a discount above the price, is refused with a reason; the seller's pricing floor is checked before the write | seller_can:products.manage |
| 26 | Change many stock levels at once — set, increase, decrease (through the stock ledger, never a raw column write) | reports_bulk | Yes — same bottom sheet with the three stock modes and an integer-only field | POST bulk-jobs/stock (SellerBulkJobController::storeStockUpdate → BulkStockOperation → InventoryService::adjust). Cannot drive a balance negative, writes a movement line per change, refuses variant products (their stock is per variant), and clears the restock waiting list through the shared notifier | seller_can:inventory.manage |
| 27 | Set a discount and discount type as part of a bulk price change | reports_bulk | Partial — the repository and service accept discount/discount_type and forward them, but the operation sheet exposes no input for either, so the app can never actually send them | Fully supported: 'discount' and 'discount_type' (percent\|flat) validated by BulkPriceOperation::rules; omitting both leaves the product's discount untouched | seller_can:products.manage |
| 28 | Attach a note/reason to a bulk stock change so the ledger movement is explainable later | reports_bulk | Partial — the note is plumbed through the controller, service and repository but no field in the operation sheet ever sets it | Fully supported: 'note' (nullable, max 255) validated by BulkStockOperation::rules and written onto the inventory movement | seller_can:inventory.manage |
| 29 | Follow a running bulk job to completion and be told when it is still running rather than being shown a false 'done' | reports_bulk | Yes — after submitting, the controller polls the job up to 10 times with growing backoff, then shows the receipt with a 'bulk_still_running' line if it has not finished | Job is queued (202) and run by App\Jobs\RunSellerBulkJob; is_finished/progress on every read; a stuck-queue safety net exists as the console command seller:run-stuck-bulk-jobs | seller_can:products.view,products.manage,inventory.manage |
| 30 | Distinguish a PARTIAL bulk result (finished, some rows refused) from a full success and from an outright failure | reports_bulk | Yes — 'partial' gets its own colour and chip rather than being folded into success or failure | Statuses queued/processing/completed/partial/failed decided by SellerBulkJobService; partial when some succeeded and some failed | seller_can:products.view,products.manage,inventory.manage |
| 31 | See a 'bulk jobs still running' badge in the seller navigation | reports_bulk | No badge in the app's More menu — the entry is a plain row | App\Services\SellerCenter\Counts::bulkJobs counts queued+processing jobs; wired to the 'bulk_running' badge key in the navigation registry | seller_can:products.manage |

---

## 4. The other registers

### BACKEND MISSING (11)

Server work, not client work. Building any of these into a client would mean inventing the rule in the client, which PART 7 forbids.

| Capability | Domain | Note |
|---|---|---|
| Server-side duplicate protection for a replayed POS sale (idempotency key on place-order) | orders | Missing: neither app/Http/Controllers/RestAPI/v3/seller/POSController.php:269-425 place_order nor app/Http/Controllers/Vendor/POS/POSOrderController.php:104 accepts or checks an idempotency key, so a retry after a response that was lost in transit creates a second order |
| Filter the statement by entry status (pending / available / paid …) | finance | status query param + statuses list on GET /api/v3/seller/seller-center/statement |
| Page through the movement log beyond the first 25 rows | inventory | GET /api/v3/seller/seller-center/inventory/movements?limit=&offset= (paginated, limit capped at 100) |
| Choose the expiry look-ahead window for the batch view | inventory | GET .../inventory/batches?days=N (1-365, echoed back as within_days) |
| See the refunded order's type (POS vs regular) and its payment status on the refund | returns_refunds | Missing — both API list and single-item constrain the order relation to select('id','payment_method') (app/Http/Controllers/RestAPI/v3/seller/RefundController.php:47-49 and :83-85), so order_type and payment_status are always null in the payload |
| Share a product into a chat conversation | notifications_chat | none — no route in routes/vendor/routes.php and no controller method |
| Assign an issue to a named staff member (explicit assignee rather than the implicit 'whoever touched it') | control_tower | Yes — updateStatus validates assigned_staff_id and verifies the staff member belongs to this shop (app/Http/Controllers/RestAPI/v3/seller/SellerControlTowerController.php:86,111-117,132-136) |
| Filter insights by type (repeatable ?type= parameter) | control_tower | Yes — SellerActionCenterController::types (app/Http/Controllers/RestAPI/v3/seller/SellerActionCenterController.php:97-104) |
| Anti-hijack payout cooling window armed when payout bank details change | settings_profile | app/Services/Marketplace/PayoutService.php:296 recordBankChange — only caller is app/Http/Controllers/Vendor/ProfileController.php:135 |
| Remove an already-uploaded TIN certificate (as opposed to replacing it) | settings_profile | No endpoint deletes tin_certificate on either side (SellerController.php:304-310 only overwrites when a new file is posted) |
| Filter coupons by date range / coupon type / discount type | growth_reviews | none — CouponController@getAddListView passes only ['added_by','vendorId'] to getListWhere; no filter params are honoured |

### DEPRECATED (2)

Code that still exists and product that no longer does. Not carried into the Seller Center; removal from the legacy panel stays a separate decision (PART 15).

| Capability | Domain | Note |
|---|---|---|
| Filter catalogue by price range and by created-at date range | products | No — not found. Searched resources/views/vendor-views/partials/_product-filters-sections.blade.php (only sorting/type/status/brand/category) and _filter-offcanvas.blade.php:14 |
| Business/discount package browsing (BusinessController + BusinessRepository) | settings_profile | No equivalent, and none needed |

### WEB ENHANCEMENT (11)

Correctly web-only. Listed so nobody "fixes" the asymmetry by cramming them into the app.

| Capability | Domain | Note |
|---|---|---|
| Export the filtered order list to Excel | orders | Yes — resources/views/vendor-views/order/list.blade.php:57-67 export link |
| Jump to the previous / next order from the order details page | orders | Yes — resources/views/vendor-views/order/order-details.blade.php:21-27 previous/next links built from $previousOrder/$nextOrder |
| POS: keyboard shortcuts for the till (order, submit, clear cart, add customer, print, search…) | orders | Yes — resources/views/vendor-views/pos/partials/modals/_short-cut-keys.blade.php:11-24 |
| See how many refund requests sit in each status without opening the list | returns_refunds | Yes — sidebar badges $v2RefundPending/$v2RefundApproved/$v2RefundRefunded/$v2RefundRejected (/home/user/Pharmacy/resources/views/layouts/vendor/partials/v2/_side-bar.blade.php:150-152, 162-179) |
| Download a refund evidence image to keep as proof | returns_refunds | Yes — Download Image link /home/user/Pharmacy/resources/views/vendor-views/refund/partials/_img-view-modal.blade.php:10-19 |
| Approve or reject a refund straight from the list without opening it | returns_refunds | Yes — per-row approve/reject icon buttons /home/user/Pharmacy/resources/views/vendor-views/refund/index.blade.php:110-134 wired to the per-row modals included at :160-165 |
| See a global unread-message badge outside the inbox | notifications_chat | Yes — header inbox icon with a total badge plus a customer/delivery-man split dropdown |
| Share-of-total percentage on each order-status figure (ring / arrow, with a down-trend colour when negative) | control_tower | No — the web tiles show the raw count only (resources/views/vendor-views/partials/_dashboard-order-status.blade.php:7) |
| Share the staff sign-in link with the team | security_integrations | Yes — copyable link card: /home/user/Pharmacy/resources/views/vendor-views/marketplace/staff.blade.php:12-27 |
| View shop traffic analytics (visitors, visits, product views, cart adds, orders, revenue, per-product view→cart funnel) over a range | reports_bulk | Yes, but unreachable from the UI — resources/views/vendor-views/analytics/index.blade.php exists and vendor.analytics.index is routed, yet no navigation anywhere links to it, and seller STAFF are refused (the 'analytics' URL segment is unmapped → deny-by-default 403) |
| Search the payment information list | settings_profile | Yes — resources/views/vendor-views/shop/payment-information.blade.php:45 (input type=search name="search") |

### APP ADAPTATION (16)

Same capability, phone-shaped. Check the rules, not the layout.

| Capability | Domain | Note |
|---|---|---|
| POS: change item quantity, remove a line, and clear the whole cart | orders | Yes — lib/features/pos/controllers/cart_controller.dart:225-271 setQuantity, 273-296 removeFromCart, 307-322 removeAllCartList; clear button pos_screen.dart:497-515 |
| POS: hold the current sale, list held sales, search them by customer name, and resume one | orders | Yes — hold at lib/features/pos/screens/pos_screen.dart:524-548; list lib/features/pos/screens/hold_order_page.dart; search lib/features/pos/widgets/hold_order_search_bar_widget.dart:64; resume lib/features/pos/widgets/hold_order_item_widget.dart:126-131 |
| POS: start a fresh cart for another customer / cancel the current cart | orders | Partial — 'Clear' resets the current cart (lib/features/pos/screens/pos_screen.dart:497-515) and holding a cart implicitly starts a new one (cart_controller.dart:496-541) |
| Filter catalogue by approval status (all / approved / denied / new request) | products | Yes — horizontal chips, lib/features/product/widgets/status_filter_widget.dart:23 `['all','approved','denied','new_product']` → request_status |
| Deliver the generated barcode sheet — download the file vs print it from the browser | products | Download / open the generated file — bar_code_generator_screen.dart:280-306 (launches the returned URL, `download` label at :306) |
| Preview a digital product's preview file (PDF / image / video / audio) and download variation files | products | Yes — in-app viewers: lib/features/product_details/widgets/{pdf_preview_flutter,image_preview,video_preview,audio_preview,download_preview_file}.dart dispatched by product_details_widget.dart:815-828; also from the list row (shop_product_card_widget.dart:190) |
| Dedicated Top Selling Products and Most Popular Products screens | products | Yes — lib/features/product/screens/top_selling_product_screen.dart and most_popular_product_screen.dart, wrapped by product_list_view_screen.dart:7-24; own endpoints /products/top-selling-product and /products/most-popular-product (product_repository.dart:96-118) |
| Quick-pick suggested amounts when collecting cash | shipping_delivery | Yes — hard-coded chips 500 / 1000 / 2000 / 5000 / 10000 above the amount field |
| Refresh the console by pull-to-refresh on either tab | automation | Yes — RefreshIndicator on both lists |
| Wallet summary on the dashboard — withdrawable balance, pending withdraw, total commission, already withdrawn, delivery charge earned, total tax, collected cash — plus raising a withdraw request from the dashboard itself | control_tower | No — home has no wallet block; the app puts this on a separate Wallet screen (lib/features/wallet/screens/wallet_screen.dart), reached from the menu, not from lib/features/home |
| Pull-to-refresh the whole control tower / action center / home dashboard | control_tower | Yes — RefreshIndicator at lib/features/control_tower/screens/control_tower_screen.dart:46, lib/features/action_center/screens/action_center_screen.dart:55, lib/features/home/screens/home_page_screen.dart:90-95 |
| Bottom navigation shell with Home / Orders / POS / Products / More, with the POS tab appearing only when POS is enabled both platform-wide and for this seller | control_tower | Yes — nav items built at lib/features/dashboard/screens/dashboard_screen.dart:92-98; posEnabled computed from configModel.posActive && userInfoModel.posActive at :85-86; falls back to Home if POS is revoked while open at :87-91 |
| Refresh verification standing without leaving the screen | brands_compliance | Yes — pull-to-refresh (verification_screen.dart:44-47) |
| Copy a freshly issued credential to the clipboard | security_integrations | Yes — Clipboard.setData: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:62 |
| Refresh the security data on demand | security_integrations | Yes — pull-to-refresh on all four tabs: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:99-102, :140-143, :177-180, :273-276 |
| Order report: list the orders in the period with their financial breakdown | reports_bulk | Partial — first 10 orders only, showing amount, product discount, coupon discount, tax, commission |

### DEVICE SPECIFIC (22)

The device is the capability. The web equivalent is the typed or uploaded form of the same input.

| Capability | Domain | Note |
|---|---|---|
| POS: scan a barcode with the device camera to add an item | orders | Yes — lib/features/pos/controllers/barcode_scan_controller.dart:35-63 (BarcodeScanner.scan with flash/autofocus options) |
| POS: print the receipt on a Bluetooth thermal printer (pair, connect, remember the printer) | orders | Yes — lib/features/pos/screens/invoice_print_screen.dart:51-110 (pairing, connect, writeBytes) and 141-210 (device list) |
| Look a product up by its barcode / product code | products | Yes, via camera scan — lib/features/pos/controllers/barcode_scan_controller.dart:36-62 (BarcodeScanner.scan, code128) then getProductFromScan → GET /api/v3/seller/pos/products |
| Hand a statement/transaction CSV to another app (share sheet) rather than saving it | finance | Yes — transactions use SharePlus; the statement writes to Downloads and reports the path |
| Dismiss the low-stock warning banner | inventory | Yes — 'dont_show_again' writes a SharedPreferences key with NO expiry (permanent, per device) |
| Open the refund request straight from a push notification about it | returns_refunds | Yes — deep link from FCM payload type 'refund' (/home/user/sillercenter-syria-cosmatics/lib/notification/my_notification.dart:57-58 and :177-178; cold start /home/user/sillercenter-syria-cosmatics/lib/features/splash/screens/splash_screen.dart:127-128) |
| Capture a photo with the camera and attach it to a message | notifications_chat | Yes — 'open camera' option in the attachment bottom sheet (ImageSource.camera) |
| Open a downloaded document in an external viewer app | notifications_chat | Yes — OpenFile.open after the download completes |
| Ask the OS/browser for notification permission | notifications_chat | Yes — Permission.notification requested at startup, and again before a bulk media download |
| Subscribe to broadcast push topics (all-sellers, maintenance mode) and unsubscribe on sign-out | notifications_chat | Yes — subscribeToTopic('six_valley_seller') and ('maintenance_mode_start_vendor') on login; unsubscribe on clearSharedData |
| Deep-link from a push notification straight to the relevant screen (chat inbox, order details, refund, wallet, product list, notification feed) | notifications_chat | Yes — payload.type routing in the local-notification tap handler, onMessageOpenedApp and the splash cold-start handler |
| Rich push presentation (big-text and big-picture notifications with sound) | notifications_chat | Yes — flutter_local_notifications BigText / BigPicture styles with a custom sound |
| Barcode scan floating action button on the POS tab of the dashboard | control_tower | Yes — FloatingActionButton calling BarcodeScanController.scanProductBarCode at lib/features/dashboard/screens/dashboard_screen.dart:115-121 |
| Exit-the-app confirmation from the dashboard root | control_tower | Yes — PopScope + confirmation dialog at lib/features/dashboard/screens/dashboard_screen.dart:100-109,223-233 |
| Photograph a KYC document with the device camera instead of picking a stored file | brands_compliance | Yes — camera button on the submit sheet (submit_document_sheet_widget.dart:136-142) |
| Exported file lands in the device's Downloads folder and opens in the OS viewer | reports_bulk | Yes — bytes written to /storage/emulated/0/Download (falling back to app documents), de-duplicated with (1)/(2) suffixes, then OpenFile.open |
| Pull-to-refresh a report | reports_bulk | Yes — RefreshIndicator on all three report screens and on both bulk screens |
| Dark / light theme toggle | settings_profile | Yes — lib/features/profile/widgets/theme_changer_widget.dart:41-49 (FlutterSwitch → ThemeController.toggleTheme) |
| See the installed app/panel version | settings_profile | Yes — lib/features/profile/screens/profile_view_screen.dart:151-154 and lib/features/menu/screens/more_screen.dart:220 ('v - ${AppVersion.current}') |
| Forced app-update gate (blocks the app until the seller updates from the store) | settings_profile | Yes — lib/features/update/screen/update_screen.dart:48-61 (opens configModel.sellerAppVersionControl.forAndroid/forIos link) |
| Pull-to-refresh the scorecard / analytics without leaving the screen | growth_reviews | Yes — RefreshIndicator on both screens; the scorecard refresh calls the lighter /scorecard endpoint after the first full overview load |
| Capture the product photo with the device camera for AI analysis | growth_reviews | Partial — AiController.pickImage hardcodes ImageSource.gallery, so there is no camera path in the AI flow (the KYC sheet does offer camera) |

### APP MISSING (65)

Recorded for PART 11: after each web wave, the corresponding app feature is audited against it. None of these
is a reason to remove anything from the web.

| Capability | Domain | Where it exists on the web |
|---|---|---|
| Search the order list by order ID | orders | Yes — resources/views/vendor-views/order/list.blade.php:53 searchPlaceholder 'search_by_Order_ID' |
| See per-status order counts (current order summary) on the order list | orders | Yes — resources/views/vendor-views/order/list.blade.php:31-46 stat tiles bound to $allOrdersInfo counts |
| Filter the order list by delivery man | orders | Partial — carried through as a hidden field when arriving from the delivery man order history: resources/views/vendor-views/order/partials/_filter-offcanvas.blade.php:19-21 |
| Mark the due amount from an order edit as paid | orders | Yes — resources/views/vendor-views/order/partials/modal/order-edit-due-amount-mark-as-paid.blade.php:1-27 |
| Record that the excess amount from an order edit was returned to the customer (payment method + note, or to wallet) | orders | Yes — resources/views/vendor-views/order/partials/modal/order-edit-return-amount-modal.blade.php:1-53 (method select + payment note) |
| POS: filter the catalogue by brand and change the sort order | orders | Yes — resources/views/vendor-views/pos/partials/offcanvas/_filter-offcanvas.blade.php:16 requests filterSection ['sorting','brand','category'] |
| POS: choose the sale's fulfilment — instant counter sale vs home delivery that enters the normal order lifecycle (and is stored as COD when paid in cash) | orders | Yes — resources/views/vendor-views/pos/partials/_cart.blade.php:165-180 instant/delivery radios plus the COD explanation note |
| Export the product list to Excel (respecting the active filters) | products | Yes — resources/views/vendor-views/product/list.blade.php:26-36 export button passing every filter |
| Product gallery — browse other listings and clone their setup into a new product | products | Yes — resources/views/vendor-views/product/product-gallery.blade.php:12,56,154 ("use this product info") |
| Bulk-import products from an Excel/XLSX spreadsheet (with template download) | products | Yes — resources/views/vendor-views/product/bulk-import.blade.php:23-143 (3-step wizard, template with/without existing data) |
| Export the restock-request list | products | Yes — resources/views/vendor-views/product/request-restock-list.blade.php:24-25 |
| Product-level sales performance on the detail page: total quantity sold, total order amount, star-rating breakdown | products | Yes — resources/views/vendor-views/product/view.blade.php:382 Total_Qty_Sold, :389 Total_Order_Amount, :233-303 5-to-1-star rating breakdown, :324 review count |
| See why a product was denied, including the structured moderation reason codes | products | Yes — free-text note at view.blade.php:21 plus structured reason codes and the needs_changes action from ProductModerationEvent: resources/views/vendor-views/product/list.blade.php:89-97 |
| Clearance-sale SEO / meta data setup for the offer page | products | Yes — resources/views/vendor-views/promotion/clearance-sale/partials/_clearance-sale-meta-setup.blade.php, opened from _clearance-sale-offer-setup.blade.php:49 ("Meta Data Setup") |
| Search within the clearance-sale product list | products | Yes — resources/views/vendor-views/promotion/clearance-sale/partials/_product-add-list.blade.php:99-102 `searchValue` box over the clearance list |
| Search withdraw requests by amount | finance | Yes — GET search box 'Search_By_Amount' on the Withdraw page |
| Open a withdraw request and read the payout method details that were submitted with it | finance | Yes — the preview offcanvas renders every withdrawal_method_fields key/value |
| Choose the payout method (bank transfer / manual) when requesting a payout | finance | Yes — a method select in the payout form |
| Page through payout request history | finance | Yes — paginate(15) with links under the table |
| Per-order transaction report: order amount, product/coupon/referral discounts, VAT, shipping, deliveryman incentive, admin vs vendor discount, admin commission and vendor net income, filtered by disburse/hold status, customer and date range, exportable to PDF and Excel | finance | Yes — Transaction report order list with filters and PDF/Excel exports |
| Expense transaction report: free delivery and coupon-discount expense per order, with PDF and Excel export | finance | Yes — Transaction report expense list with date filters and exports |
| Sort the low-stock list by quantity or by order volume | inventory | Yes — sortOrderQty select (default, quantity asc/desc, order volume asc/desc) |
| Search the low-stock list by product name | inventory | Yes — searchValue on the data-view |
| Export the restock-request list to Excel | inventory | Yes — Export button carrying the current filters |
| Open the product (view / edit) straight from a restock row | inventory | Yes — the product name and an eye icon link to the product view |
| Search refund requests by order id, refund id, or customer name/phone | returns_refunds | Yes — search box /home/user/Pharmacy/resources/views/vendor-views/refund/index.blade.php:14-16 ('search_by_order_id_or_refund_id') |
| Export the refund request list to Excel | returns_refunds | Yes — export button /home/user/Pharmacy/resources/views/vendor-views/refund/index.blade.php:26-29 |
| Cap the seller at two refund decisions (max 2 approvals / 2 rejections) with the remaining-attempts warning | returns_refunds | Yes — server refuses a third decision (app/Http/Controllers/Vendor/RefundController.php:130-132) and the UI hides the button plus shows the warning banner (details.blade.php:21-30, :36-69) |
| Record that the excess amount from an edited order was actually returned to the customer (wallet or manual, with a payment note) | returns_refunds | Partial — endpoint and modal exist but nothing opens the modal: it is included at /home/user/Pharmacy/resources/views/vendor-views/order/list.blade.php:231 with no data-target anywhere in vendor-views (the admin panel has the trigger, admin-views/order/list.blade.php:244) |
| Search categories while setting category-wise shipping cost | shipping_delivery | Yes — 'Search_by_category_name' GET form above the category cost table |
| Sort the delivery man list (recent / oldest / top rated) | shipping_delivery | Yes — 'Sorting' dropdown with latest / oldest / rating |
| Export the delivery man list to Excel | shipping_delivery | Yes — Export button → DeliveryManListExport |
| Search a delivery man's order history by order number | shipping_delivery | Yes — 'search_by_order_no' box on the order history data-view |
| Filter delivery man reviews by date range and star rating, and search them by order id | shipping_delivery | Yes — from/to date pickers, rating select, Filter/Reset, plus a search-by-order-id box |
| See a delivery man's star-rating distribution (5→1 star breakdown) | shipping_delivery | Yes — per-star bars with counts on the rating page |
| Search withdraw requests by delivery man name | shipping_delivery | Yes — 'search_by_name' GET form above the withdraw table |
| Export delivery man withdraw requests to Excel | shipping_delivery | Yes — Export button → DeliveryManWithdrawRequestExport |
| Filter the order list by delivery man | shipping_delivery | Partial — the orders list honours a delivery_man_id query param, but it is only reachable by clicking the order count on the delivery man list; the filter offcanvas has no delivery man picker, only a hidden passthrough |
| Page through a long emergency contact list | notifications_chat | Yes — paginated with getWebConfig('pagination_limit') and a links() footer |
| Top-rated products block on the dashboard | control_tower | Yes — resources/views/vendor-views/dashboard/index.blade.php:133 → _top-rated-products.blade.php, data from ProductRepository::getTopRatedList (app/Http/Controllers/Vendor/DashboardController.php:70) |
| Auction wallet statistics on the dashboard (only when the Auction add-on is published) | control_tower | Yes — resources/views/vendor-views/dashboard/index.blade.php:82-98, populated by AuctionVendorWalletStatsService when the add-on is published (app/Http/Controllers/Vendor/DashboardController.php:116-118) |
| Real-time activity poll on the dashboard — count of unchecked new orders and restock-request alerts with a link into the restock list | control_tower | Yes — vendor.dashboard.real-time-activities wired at resources/views/layouts/vendor/partials/_translated-message-container.blade.php:46, served by DashboardController::getRealTimeActivities (app/Http/Controllers/Vendor/DashboardController.php:372-410) returning new_order_count, restockProductCount and a restock card with a route into vendor.products.request-restock-list |
| Set an expiry date when submitting a KYC document | brands_compliance | Yes — date input on the submit form (seller-verification.blade.php:47-50) |
| Open / download a KYC document I previously submitted | brands_compliance | Yes — 'view_file' link per document (seller-verification.blade.php:79-84) |
| Attach a PDF (e.g. a scanned business licence) as the KYC document file | brands_compliance | Yes — file input accepting .pdf,.jpg,.jpeg,.png (seller-verification.blade.php:51-54) |
| Search VAT rows by order id or tax name | brands_compliance | Yes — search box on the VAT list (vendor-tax-report.blade.php:123-135) |
| Export the VAT report as Excel (.xlsx) | brands_compliance | Yes — Excel item in the Export dropdown (vendor-tax-report.blade.php:150-155) |
| Page through the whole VAT transaction history rather than the first page only | brands_compliance | Yes — LengthAwarePaginator, 10 per page, with links (vendor-tax-report.blade.php:259-263) |
| Jump from a VAT row to the underlying order | brands_compliance | Yes — order id links to vendor.orders.details (vendor-tax-report.blade.php:183-188) |
| See an expense report alongside the VAT report | brands_compliance | Yes — vendor transaction expense list with PDF/Excel exports |
| Search within the order report (by order id) | reports_bulk | Yes — x-k.data-view search box, search_by_order_id |
| Page through the report result lists (orders / products / stock) | reports_bulk | Yes — Laravel paginator with 'showing x–y of n' on all three report pages |
| Jump from a report row to the underlying order / product record | reports_bulk | Yes — order id links to vendor.orders.details, product name links to vendor.products.view on both the product and stock reports |
| Product report: average product value and average customer rating per product | reports_bulk | Yes — average_Product_Value and average_Ratings columns with review count |
| Stock report: search a product by name | reports_bulk | Yes — x-k.data-view search box (search_Product_Name) |
| Stock report: see when a product's stock was last updated | reports_bulk | Yes — last_Updated_Stock column |
| Import or create many products from a spreadsheet (bulk import) | reports_bulk | Yes — vendor.products.bulk-import, with downloadable xlsx templates, and surfaced in the new Seller Center product page too |
| Order-wise and expense transaction reports with Excel and PDF export | reports_bulk | Yes — vendor.transaction.order-list and expense-list, each with order-wise PDF, summary PDF and Excel export |
| Language menu reflects the languages the admin actually enabled | settings_profile | Yes — the header loops the configured language list and filters on status==1 |
| Open the shop's public storefront page to see it as a customer does | settings_profile | Yes — resources/views/vendor-views/shop/index.blade.php:173-174 ('Visit_Website' → route('vendor-shop', ['slug' => $shop['slug']])) |
| Search the coupon list by title / code / discount type | growth_reviews | Yes — x-k.data-view searchName=searchValue wired to CouponRepository::getListWhere($searchValue) |
| See how many times a coupon has actually been redeemed (order_count) and who bears the discount | growth_reviews | Yes — 'Total_Used' and 'discount_bearer' columns in the list |
| Export the coupon list to Excel | growth_reviews | Yes — 'export' button → CouponListExport xlsx |
| Page through the shop's full review history | growth_reviews | Yes — paginated with getWebConfig('pagination_limit') and a pager that preserves filters |
| Export the filtered review list to Excel | growth_reviews | Yes — export button carrying the active filters → CustomerReviewListExport |

---

## 5. Domains

The full record — every capability with the app, web and server evidence behind its classification — is one
file per domain, so each stays readable in a browser. The counts here are the same records the matrix above
is built from.

| Domain | Capabilities | WEB MISSING | Detail |
|---|---:|---:|---|
| orders (order list, order details, order edit, POS) | 58 | 2 | [parity/orders.md](parity/orders.md) |
| products | 56 | 9 | [parity/products.md](parity/products.md) |
| finance | 42 | 14 | [parity/finance.md](parity/finance.md) |
| inventory | 40 | 19 | [parity/inventory.md](parity/inventory.md) |
| returns_refunds | 36 | 13 | [parity/returns_refunds.md](parity/returns_refunds.md) |
| shipping_delivery | 56 | 4 | [parity/shipping_delivery.md](parity/shipping_delivery.md) |
| automation (seller rules engine — "if X happens to my catalogue, do Y", plus the audit trail of what the rules did) | 36 | 35 | [parity/automation.md](parity/automation.md) |
| notifications_chat | 48 | 7 | [parity/notifications_chat.md](parity/notifications_chat.md) |
| control_tower | 40 | 22 | [parity/control_tower.md](parity/control_tower.md) |
| brands_compliance | 46 | 19 | [parity/brands_compliance.md](parity/brands_compliance.md) |
| security_integrations | 37 | 26 | [parity/security_integrations.md](parity/security_integrations.md) |
| reports_bulk | 47 | 16 | [parity/reports_bulk.md](parity/reports_bulk.md) |
| settings_profile | 46 | 5 | [parity/settings_profile.md](parity/settings_profile.md) |
| growth_reviews | 47 | 4 | [parity/growth_reviews.md](parity/growth_reviews.md) |

---

## 6. What this document obliges

1. **No capability may remain phone-only.** The 195 WEB MISSING rows are the
   backlog for the Web Seller Center; a wave is not finished while one of its rows is open.
2. **Nothing here licenses a deletion.** A capability absent from the visual prototype but present in this
   matrix is preserved, moved or improved — never dropped for looking unfamiliar (PART 15).
3. **One rule, one place.** Where a row names a calculation, threshold or status, the server owns it and both
   clients read it. A client that recomputes it is a defect, not an optimisation (PART 7).
4. **The permission column is the contract.** UI hiding is presentation; the named permission is enforced
   server-side for the web session and the API token alike (PART 5).
5. **This file is regenerated, not hand-edited.** It is rendered from the audit records; correcting a
   classification means correcting the record and re-rendering, so the registers and the domain sections
   can never disagree.


---

## Flutter audit, waves 4–8 (PART 11)

Audited the app against each wave's rules — terminology, statuses, permissions, validation,
calculations, thresholds, audit behaviour — and found one real disagreement, plus one gap in what the
server was willing to tell the app.

### The disagreement: the app carried its own SLA thresholds

`scorecard_screen.dart` coloured the cancellation, return and refund rates against numbers written
into the screen — 5% amber, 15% red — while the marketplace's real limits live in SLA policy and are
settable from the admin. A market that lowered its cancellation ceiling to 8% therefore had **three
answers to one question**: the phone calling 10% comfortable, the web panel calling it a breach, and
the platform opening one against the seller.

Fixed by removing the client's copy entirely rather than by syncing it. The rates are now coloured
against `thresholds` from the server, and where the server has already reached a verdict — a metric
in `over_the_line` — that verdict wins, because the platform evaluates with the rounding and grace
the policy actually applies. A rate with no published ceiling is drawn plainly: judging it against a
guess is the bug, not the absence of a colour.

Two smaller consequences of the same fix:

- The progress bar is scaled to the ceiling rather than to 100%, so a full bar means "at the line"
  rather than "every order cancelled".
- `_DetailRowWidget` no longer asserts its translation away. Some of its titles are metric names the
  server chose, so a marketplace that adds an SLA line the app has no copy for shows its name
  instead of crashing.

### The gap: the API would not say what it was judging against

`GET seller-center/scorecard` returned the rates and the tier and nothing else, so even an app that
wanted to render the line had nothing to render. It now carries `thresholds`,
`processing_window_hours`, `over_the_line` and `open_breaches`, and a new
`GET seller-center/sla-breaches` serves the audited ledger — cleared breaches included, because a
record that shows only current problems cannot show improvement.

### Checked and already in agreement

| Wave | Rule | App |
|---|---|---|
| 4 | Lateness reads the marketplace's threshold | Server-driven; the app renders the insight's own metric |
| 4 | Dispatch time is null, never zero, while open | No client-side computation |
| 5 | The buckets are the whole account, not the filtered range | `StatementController` states the rule and holds it |
| 8 | Stock has no period | `stock_report_screen.dart` says so and offers no picker |
| 3 | Rule fields come from the server's schema | Fixed in the Wave 3 audit; still holds |

### Not carried to the phone, and why

Wave 6's incidents, approvals and brand-protection screens and Wave 7's team, access-review and
integrations screens stay web-first. Each is either a review task somebody does at a desk with the
whole list in front of them, or — for integrations — work nobody does on a handset. The APIs exist
for all of them, so this is a product judgement rather than a missing capability, and it is recorded
here rather than left as an unexplained absence.

## Cross-client parity, held by tests (PART 16)

`tests/Feature/CrossClientParityTest.php` asserts the properties rather than trusting them:

- **A capability both clients expose is gated the same on both.** Read out of both route tables, so
  a route added to one side without its gate fails the test rather than shipping. It found one: the
  web's action-centre dismiss declared no permission where the phone required
  `orders.view,products.view`. Same capability, two rules — now one.
- **Every Seller Center write declares a permission.** The staff gate's segment map would catch an
  omission, but that map is the coarse pre-filter and not the decision.
- **A threshold set once is read by both**, and a policy written through the operator's own save path
  is the number every consumer reads — asserted through `FulfilmentAnalytics`, which resolves it
  independently of the seller screens.
- **The API publishes what it judges against**, so a client can render a rate as a position rather
  than as a statistic.
