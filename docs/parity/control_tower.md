# Parity — control_tower

[← back to the matrix](../SELLER_WEB_APP_PARITY.md) · 40 capabilities

**7** BOTH · **22** WEB MISSING · **3** APP MISSING · **1** WEB ENHANCEMENT · **3** APP ADAPTATION · **2** DEVICE SPECIFIC · **2** BACKEND MISSING

## Structural facts the implementer must know

```
STRUCTURAL FINDING #1 — the web Control Tower is written but unreachable. `App\Http\Controllers\Seller\ControlTowerController` (/home/user/Pharmacy/app/Http/Controllers/Seller/ControlTowerController.php:22) and `App\Http\Controllers\Seller\HomeController` (:22) exist and are correct, but /home/user/Pharmacy/routes/seller/routes.php:35-44 registers only four routes (preferences.density, preferences.direction, search, help, foundation). `seller.control-tower`, `seller.home`, `seller.issues.index`, `seller.actions` are referenced by the navigation registry (/home/user/Pharmacy/app/Services/SellerCenter/Navigation.php:41-42) and the command palette (/home/user/Pharmacy/app/Services/SellerCenter/Search.php:65) but never defined; `Shell::route()` (/home/user/Pharmacy/app/Services/SellerCenter/Shell.php:76-79) returns null for missing routes, so those nav items silently vanish. Worse, the view the Control Tower controller returns — `resources/views/seller-views/control-tower.blade.php` — does not exist (the directory holds only foundation, help, home, permission-denied). So implementation work is: write the blade, register the routes. Do NOT assume the vendor panel is the only web surface; there are two (classic /vendor and the new /seller Seller Center), and the Seller Center is the one designed for this domain.

STRUCTURAL FINDING #2 — the vendor panel (/vendor) has ZERO awareness of the insight store. `grep -rln SellerInsight` over routes/, app/Http/Controllers/Vendor/ and resources/views/vendor-views/ returns nothing. Every Control Tower and Action Center capability above is therefore genuinely absent from the shipped web panel, not merely styled differently.

READY-MADE WEB BUILDING BLOCKS already exist and should be reused rather than rewritten: `App\Services\SellerIntelligence\ControlTowerService` (forSeller/health/summary), `DailyBriefingService::forSeller`, `App\Services\SellerCenter\Lists\IssueList` (/home/user/Pharmacy/app/Services/SellerCenter/Lists/IssueList.php:21-50 — defines the critical/high/needs_attention/monitoring/all/resolved views and the non-negotiable severity-then-deadline sort), and `App\Services\SellerCenter\Counts` (:45-47 — issues_open, issues_severity, actions_mine badges). The web list should also carry per-issue status writes; there is currently no web write path for `SellerInsight::SELLER_SETTABLE_STATUSES` (/home/user/Pharmacy/app/Models/SellerInsight.php:61).

SERVER-AUTHORITY RULES the web must honour (they are enforced today only in the app): (a) sections render in the order the server sends them and an empty section is never drawn as a heading — control_tower_screen.dart:77 + ControlTowerService.php:48; (b) a null day-over-day change renders '—', never 0% — control_tower_widgets.dart:184-186 + DailyBriefingService.php:22; (c) critical insights are never dismissible — SellerActionCenterController.php:68 + action_center_widgets.dart:76; (d) 'resolved' is not a seller-settable status — SellerControlTowerController.php:73-76.

PERMISSIONS ARE NOT ENFORCED ON THE WEB DASHBOARD. The v3 seller API gates every route in this domain (`seller_can:orders.view` for tower+briefing, `seller_can:orders.manage` for the status write, `seller_can:orders.view,products.view` for the action center, `seller_can:finance.view` for order-statistics and earning-statistics — routes/rest_api/v3/seller.php:111-112, 448, 454-456, 588). `grep -c seller_can routes/vendor/routes.php` returns 0: the vendor panel is gated only by `['seller','seller_staff_access']` (routes/vendor/routes.php:84). A staff member without finance.view therefore sees the full wallet block on /vendor/dashboard while the same person is refused the equivalent API call. `Seller\ControlTowerController::hiddenFor()` (:44-58) already contains the per-section permission map for the new panel — use it, and add `seller_can:` middleware when registering the routes.

SETTINGS / TOGGLES / CLIENT-SIDE BUSINESS STATE: I found NO SharedPreferences or local persistence of business state in lib/features/control_tower, lib/features/action_center, lib/features/dashboard or lib/features/home (grep for SharedPreferences/setBool/setString returns nothing in all four). The only client-held state is ephemeral in-memory UI state on SellerAnalyticsController — `_analyticsIndex` and `_revenueFilterTypeIndex` (lib/features/home/controllers/seller_analytics_controller.dart:18-22) — which is a legitimate device preference and is lost on restart. Note the asymmetry: the WEB persists the equivalent choice server-side in the session (`session()->put('vendor_statistics_type')`, app/Http/Controllers/Vendor/DashboardController.php:174, read back at resources/views/vendor-views/dashboard/index.blade.php:37-54). No business setting is stored client-side in this domain — nothing to flag.

DETECTION IS SCHEDULED, NOT ON-DEMAND: insights are recomputed hourly by `seller:refresh-insights` and escalated every four hours by `seller:escalate-issues` (/home/user/Pharmacy/bootstrap/app.php:160,170). There is no seller-facing 'recheck now' action on either client, and no endpoint for one. If the web page shows a 'checked at' timestamp (Seller\ControlTowerController.php:37 passes `checkedAt` => now()), it must not be presented as the detection time — it is the render time, which is a different fact.

CROSS-DOMAIN EDGES (audited elsewhere, listed so they are not double-counted): the home screen also mounts `SellerCenterCardWidget` and calls `getSellerCenterOverview()` / `getActivities()` (lib/features/home/screens/home_page_screen.dart:53,55,142) — those belong to the seller_center domain (/api/v3/seller/seller-center/overview, AppConstants.dart:163) and have a real web counterpart at /vendor/business-settings/seller-center (routes/vendor/routes.php:417-421). Product rejection reasons, stock-out lists and restock requests belong to the products/inventory domains; I listed them here only where the control-tower surface is what exposes them.
```

## BOTH (7)

**Business analytics — order counts by the 8 order statuses with a period filter (overall / today / this_week / this_month / this_year)**  
`finance.view (API, routes/rest_api/v3/seller.php:112); none on web (routes/vendor/routes.php has zero seller_can middleware)`  
- App — Yes — dropdown at lib/features/home/widgets/on_going_order_widget.dart:50-67; tiles at :101-140 (pending/processing/confirmed/out_for_delivery) and lib/features/home/widgets/completed_order_widget.dart:77-117 (delivered/cancelled/returned/failed); controller at lib/features/home/controllers/seller_analytics_controller.dart:51
- Web — Yes — statistics_type select at resources/views/vendor-views/dashboard/index.blade.php:35-57; all 8 status tiles at resources/views/vendor-views/partials/_dashboard-order-status.blade.php:2-73; AJAX reload via vendor.dashboard.order-status (app/Http/Controllers/Vendor/DashboardController.php:172)
- Server — App: GET /api/v3/seller/order-statistics?statistics_type= → SellerController::order_statistics (app/Http/Controllers/RestAPI/v3/seller/SellerController.php:706). Web: DashboardController::getOrderStatusArray (app/Http/Controllers/Vendor/DashboardController.php:294) — two separate implementations of the same counts
- Evidence — flutter: lib/features/home/widgets/on_going_order_widget.dart:50-67,101-140; lib/features/home/controllers/seller_analytics_controller.dart:51 | web: /home/user/Pharmacy/resources/views/vendor-views/dashboard/index.blade.php:35-57; /home/user/Pharmacy/resources/views/vendor-views/partials/_dashboard-order-status.blade.php:2-73; /home/user/Pharmacy/app/Http/Controllers/Vendor/DashboardController.php:294

**Tap an order-status figure to open the order list filtered to that status**  
`none`  
- App — Yes — OrderTypeButtonHeadWidget.onTap sets the order tab index and switches to the Orders tab (lib/features/home/widgets/order_type_button_head_widget.dart:87-90); same for completed statuses at lib/features/home/widgets/order_type_button_widget.dart:34-37
- Web — Yes — every status tile is an anchor to vendor.orders.list with the status: resources/views/vendor-views/partials/_dashboard-order-status.blade.php:2,11,20,29,39,48,57,66
- Server — n/a — client-side navigation on both sides
- Evidence — flutter: lib/features/home/widgets/order_type_button_head_widget.dart:87-90; lib/features/home/widgets/order_type_button_widget.dart:34-37 | web: /home/user/Pharmacy/resources/views/vendor-views/partials/_dashboard-order-status.blade.php:2,11,20,29,39,48,57,66

**Earning statistics chart — seller earnings against admin commission, filterable by this_year / this_month / this_week**  
`finance.view (API, routes/rest_api/v3/seller.php:111); none on web`  
- App — Yes — dropdown at lib/features/home/widgets/transaction_chart_widget.dart:106-121; Syncfusion spline chart at :156-177; empty state at :178; controller at lib/features/home/controllers/seller_analytics_controller.dart:61-95; repository at lib/features/home/domain/repositories/seller_analytics_repository.dart:12
- Web — Yes — radio group yearEarn / MonthEarn / WeekEarn at resources/views/vendor-views/dashboard/partials/earning-statistics.blade.php:12-31; ApexCharts line chart at :34; AJAX at app/Http/Controllers/Vendor/DashboardController.php:186
- Server — App: GET /api/v3/seller/get-earning-statitics?type= → SellerController::getEarningStatics (app/Http/Controllers/RestAPI/v3/seller/SellerController.php:685). Web: vendor.dashboard.earning-statistics → DashboardController::getEarningStatistics (:186). Both call DashboardService::getDateTypeData with the same yearEarn/MonthEarn/WeekEarn keys
- Evidence — flutter: lib/features/home/widgets/transaction_chart_widget.dart:106-121,156; lib/features/home/domain/repositories/seller_analytics_repository.dart:12 | web: /home/user/Pharmacy/resources/views/vendor-views/dashboard/partials/earning-statistics.blade.php:12-31,34; /home/user/Pharmacy/app/Http/Controllers/Vendor/DashboardController.php:186

**Top-selling products block on the dashboard**  
`products.view`  
- App — Yes — TopSellingProductScreen(isMain: true) at lib/features/home/screens/home_page_screen.dart:180, loaded at :59
- Web — Yes — resources/views/vendor-views/dashboard/index.blade.php:138 → resources/views/vendor-views/partials/_top-selling-products.blade.php, data from ProductRepository::getTopSellList (app/Http/Controllers/Vendor/DashboardController.php:62)
- Server — App: seller product endpoints; Web: DashboardController::index (:62)
- Evidence — flutter: lib/features/home/screens/home_page_screen.dart:59,180 | web: /home/user/Pharmacy/resources/views/vendor-views/dashboard/index.blade.php:138; /home/user/Pharmacy/app/Http/Controllers/Vendor/DashboardController.php:62

**Top-rated delivery men block on the dashboard (hidden when shipping is in-house)**  
`none`  
- App — Yes — TopDeliveryManViewWidget(isMain: true) at lib/features/home/screens/home_page_screen.dart:185-186, gated on configModel.shippingMethod != 'inhouse_shipping'; loaded at :62
- Web — Yes — resources/views/vendor-views/dashboard/index.blade.php:143 → _top-rated-delivery-man.blade.php, data at app/Http/Controllers/Vendor/DashboardController.php:78. Note: the web block is NOT gated on shipping method, so an in-house shop still sees it
- Server — Web: DashboardController::index (:78); App: delivery-man endpoints
- Evidence — flutter: lib/features/home/screens/home_page_screen.dart:62,185-186 | web: /home/user/Pharmacy/resources/views/vendor-views/dashboard/index.blade.php:141-145 (no shipping-method condition); /home/user/Pharmacy/app/Http/Controllers/Vendor/DashboardController.php:78

**Notification bell with unread count on the dashboard shell**  
`none`  
- App — Yes — bell with newNotificationItem badge at lib/features/home/screens/home_page_screen.dart:110-133, opening NotificationScreen at :112
- Web — Yes — notification dropdown with unseen count at resources/views/layouts/vendor/partials/v2/_header.blade.php:90-171 (badge at :130)
- Server — App: notification endpoints in the notification domain; Web: the header queries notifications inline (_header.blade.php:102-106)
- Evidence — flutter: lib/features/home/screens/home_page_screen.dart:110-133 | web: /home/user/Pharmacy/resources/views/layouts/vendor/partials/v2/_header.blade.php:90,130,171

**Seller setup guide — a completion checklist with a percentage, shown until the shop is fully set up**  
`shop_settings.manage on the API (routes/rest_api/v3/seller.php:97-99)`  
- App — Yes — CustomTutorialDialog at lib/features/dashboard/widgets/custom_tutorial_dialog.dart:12, completion percentage at :41-42, per-step rows at :101-113; opened by TutorialDialogController.show (lib/main.dart:272)
- Web — Yes — setup-guide button with completion percent in the v2 sidebar at resources/views/layouts/vendor/partials/v2/_side-bar.blade.php:490-511, plus resources/views/layouts/vendor/partials/v2/_setup-popover.blade.php
- Server — DIVERGENT SOURCES. App reads shops.setup_guide_app JSON (app/Models/Shop.php:74,94; seeded at app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:86-88; written by POST /api/v3/seller/update-setup-guide-app, routes/rest_api/v3/seller.php:98). Web reads checkSetupGuideRequirements() over web-config steps (app/Utils/panel-helpers.php:10-27)
- Evidence — flutter: lib/features/dashboard/widgets/custom_tutorial_dialog.dart:41-42,101-113; lib/main.dart:272 | web: /home/user/Pharmacy/resources/views/layouts/vendor/partials/v2/_side-bar.blade.php:490-511; /home/user/Pharmacy/app/Utils/panel-helpers.php:10-27; /home/user/Pharmacy/app/Models/Shop.php:74. Two independent progress stores for one concept — they can and will disagree.

## WEB MISSING (22)

**View the operational issue queue arranged in ordered sections (critical_now, needs_action_today, sla_risk, fulfillment_exceptions, returns_requiring_action, financial_exceptions, inventory_risk, catalog_and_pricing, recently_auto_resolved)**  
`orders.view (routes/rest_api/v3/seller.php:448)` · wave 2  
- App — Yes — lib/features/control_tower/screens/control_tower_screen.dart:37 (ControlTowerScreen), renders tower.sections at :77 via _section() at :94
- Web — No — no vendor route, controller or blade reads seller_insights. app/Http/Controllers/Seller/ControlTowerController.php:22 exists but its route 'seller.control-tower' is never registered (routes/seller/routes.php:35-44 registers only preferences/search/help/foundation) and resources/views/seller-views/control-tower.blade.php does not exist (dir holds foundation, help, home, permission-denied only)
- Server — GET /api/v3/seller/seller-center/control-tower — SellerControlTowerController::index (app/Http/Controllers/RestAPI/v3/seller/SellerControlTowerController.php:48) → ControlTowerService::forSeller (app/Services/SellerIntelligence/ControlTowerService.php:41, sections built at :47-72)
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/control_tower/screens/control_tower_screen.dart:77,94; /home/user/sillercenter-syria-cosmatics/lib/features/control_tower/domain/repositories/control_tower_repository.dart:12 | web: /home/user/Pharmacy/routes/seller/routes.php:35-44 (no control-tower route); /home/user/Pharmacy/app/Http/Controllers/Seller/ControlTowerController.php:35 (view('seller-views.control-tower') — file absent); /home/user/Pharmacy/routes/vendor/routes.php:104-113 (vendor dashboard group has no issue route)

**See per-section issue count and the affected-record count ('37 products require action' rather than '4 issues')**  
`orders.view` · wave 2  
- App — Yes — control_tower_screen.dart:100-108 prints section.affected when it exceeds section.count; model TowerSectionModel at control_tower_models.dart:96-121
- Web — No — no web surface renders sections at all; the vendor dashboard's only counters are order-status tiles (resources/views/vendor-views/partials/_dashboard-order-status.blade.php:7,16,25,34,44,53,62,71) and the 4 KPI cards
- Server — ControlTowerService::section() returns count + affected (app/Services/SellerIntelligence/ControlTowerService.php:178-207)
- Evidence — flutter: lib/features/control_tower/screens/control_tower_screen.dart:100-108; lib/features/control_tower/domain/models/control_tower_models.dart:96 | web: /home/user/Pharmacy/resources/views/vendor-views/dashboard/index.blade.php:9-146 (no issue/affected counter anywhere); /home/user/Pharmacy/app/Services/SellerIntelligence/ControlTowerService.php:180-182

**Change an issue's working status — acknowledge it, mark in progress, park as waiting, or reopen**  
`orders.manage (routes/rest_api/v3/seller.php:454-456)` · wave 2  
- App — Yes — PopupMenuButton with the four seller-settable statuses at lib/features/control_tower/widgets/control_tower_widgets.dart:99-111; wired through ControlTowerController.setStatus (controllers/control_tower_controller.dart:63) and repository.updateIssueStatus (domain/repositories/control_tower_repository.dart:18)
- Web — No — no vendor route accepts an issue status write; grep for SellerInsight across routes/vendor/routes.php and app/Http/Controllers/Vendor/ returns nothing
- Server — PUT /api/v3/seller/seller-center/control-tower/issues/{id}/status — SellerControlTowerController::updateStatus (app/Http/Controllers/RestAPI/v3/seller/SellerControlTowerController.php:82). Note it also silently assigns the issue to the acting principal when no assigned_staff_id is sent (:107-109) — the web must reproduce that ownership side effect
- Evidence — flutter: lib/features/control_tower/widgets/control_tower_widgets.dart:99-111; lib/features/control_tower/domain/repositories/control_tower_repository.dart:18-27 | web: /home/user/Pharmacy/routes/vendor/routes.php (0 matches for seller_can / SellerInsight — verified by grep); /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerControlTowerController.php:82,107-109; statuses defined at /home/user/Pharmacy/app/Models/SellerInsight.php:61

**Per-domain health board — 8 operational categories (orders, inventory, catalog, pricing, returns, shipping, finance, integrations) each showing healthy / watch / degraded / critical plus the open count**  
`orders.view` · wave 2  
- App — Yes — DomainHealthGridWidget at lib/features/control_tower/widgets/control_tower_widgets.dart:200-236, rendered under the 'system_health' heading at control_tower_screen.dart:81-84
- Web — No — the vendor dashboard has no per-domain health board. The nearest web analogue is the Seller Center hub's four cards (verification / performance / finance / SLA) at resources/views/vendor-views/marketplace/seller-center.blade.php:27,43,60,80, which read SellerCenterService, not the insight store
- Server — ControlTowerService::health (app/Services/SellerIntelligence/ControlTowerService.php:87-112); categories at app/Models/SellerInsight.php:74-78
- Evidence — flutter: lib/features/control_tower/widgets/control_tower_widgets.dart:200-236; lib/features/control_tower/screens/control_tower_screen.dart:81-84 | web: /home/user/Pharmacy/resources/views/vendor-views/marketplace/seller-center.blade.php:27-91 (different data, 4 cards not 8 domains); /home/user/Pharmacy/app/Services/SellerIntelligence/ControlTowerService.php:87

**See that an issue was escalated by the platform because nobody answered it (escalation_level badge)**  
`orders.view` · wave 2  
- App — Yes — 'escalated' tag rendered when wasEscalated at lib/features/control_tower/widgets/control_tower_widgets.dart:84-85; model getter at control_tower_models.dart:88
- Web — No — no web surface renders escalation_level; grep for 'escalat' across resources/views returns only refund/approval copy, nothing about issues
- Server — IssueEscalationService swept by `seller:escalate-issues` every four hours (app/Console/Commands/EscalateSellerIssues.php:18; schedule at bootstrap/app.php:170); field surfaced in the tower payload at app/Services/SellerIntelligence/ControlTowerService.php:198
- Evidence — flutter: lib/features/control_tower/widgets/control_tower_widgets.dart:84-85; lib/features/control_tower/domain/models/control_tower_models.dart:88 | web: grep -rn 'escalat' /home/user/Pharmacy/resources/views/vendor-views/ → no issue-related hit; /home/user/Pharmacy/bootstrap/app.php:170

**See an issue's deadline and whether it is overdue (due_at / is_overdue)**  
`orders.view` · wave 2  
- App — Yes — due/overdue tag at lib/features/control_tower/widgets/control_tower_widgets.dart:75-82
- Web — No — nothing in vendor-views renders a due date or overdue state for operational work; the dashboard's only time controls are the statistics period selects
- Server — due_at + is_overdue in the section payload (app/Services/SellerIntelligence/ControlTowerService.php:196-197); 'needs_action_today' section built from due_at within 24h (:52-56)
- Evidence — flutter: lib/features/control_tower/widgets/control_tower_widgets.dart:75-82 | web: /home/user/Pharmacy/resources/views/vendor-views/dashboard/index.blade.php:35-57 (period selects only, no deadline anywhere); /home/user/Pharmacy/app/Services/SellerIntelligence/ControlTowerService.php:196-197

**See the money at stake on an issue (impact amount, currency-formatted)**  
`orders.view` · wave 2  
- App — Yes — impact tag at lib/features/control_tower/widgets/control_tower_widgets.dart:73-74 via PriceConverter
- Web — No — no web view renders insight.impact; the vendor dashboard's money figures are wallet balances only (resources/views/vendor-views/partials/_dashboard-wallet-status.blade.php:10,29,43,58,71,84,97)
- Server — impact + impact_score in section payload (app/Services/SellerIntelligence/ControlTowerService.php:194-195)
- Evidence — flutter: lib/features/control_tower/widgets/control_tower_widgets.dart:73-74 | web: /home/user/Pharmacy/resources/views/vendor-views/partials/_dashboard-wallet-status.blade.php:10-97 (wallet only); /home/user/Pharmacy/app/Services/SellerIntelligence/ControlTowerService.php:194

**See the 'recently auto-resolved' section — issues the platform closed by itself in the last 7 days, so the self-healing claim is checkable**  
`orders.view` · wave 2  
- App — Yes — rendered like any other section (control_tower_screen.dart:77 iterates tower.sections, which includes recently_auto_resolved from the server)
- Web — No — no web surface reads resolved/auto_resolved insights. app/Services/SellerCenter/Lists/IssueList.php:27 defines a 'resolved' view, but no controller or route uses IssueList (no Seller\IssueController exists in app/Http/Controllers/Seller/)
- Server — ControlTowerService::recentlyResolved (app/Services/SellerIntelligence/ControlTowerService.php:149-161), exposed as sections.recently_auto_resolved (:71)
- Evidence — flutter: lib/features/control_tower/screens/control_tower_screen.dart:77; lib/features/control_tower/domain/models/control_tower_models.dart:180-185 (populated getter) | web: ls /home/user/Pharmacy/app/Http/Controllers/Seller/ → ControlTower, Foundation, Help, Home, Preferences, Search, SellerCenter (no IssueController); /home/user/Pharmacy/app/Services/SellerIntelligence/ControlTowerService.php:71,149

**Section overflow indicator — '+N more waiting' when a section holds more issues than the rows returned (server caps at 20)**  
`orders.view` · wave 2  
- App — Yes — control_tower_screen.dart:120-127; same pattern on the home card at action_center_card_widget.dart:86-89
- Web — No — no web paging or overflow surface for issues exists
- Server — SECTION_LIMIT = 20 (app/Services/SellerIntelligence/ControlTowerService.php:30), rows truncated at :183
- Evidence — flutter: lib/features/control_tower/screens/control_tower_screen.dart:120-127 | web: /home/user/Pharmacy/resources/views/vendor-views/ contains no issue list to paginate (verified by grep for SellerInsight over resources/views — only hit is seller-views/home.blade.php:86, itself unrouted); /home/user/Pharmacy/app/Services/SellerIntelligence/ControlTowerService.php:30

**Open the record an issue is about — deep-link from an issue into the order detail screen or the product editor (action_key open_order / open_product)**  
`orders.view / products.view` · wave 2  
- App — Yes — InsightActionHandler.open at lib/features/action_center/widgets/insight_action_handler.dart:25, open_order at :32, open_product at :39 (loads the product first at :51 before opening AddProductTabView); Control Tower reuses the same handler via control_tower_screen.dart:116
- Web — No — there is no issue row on the web to click through from. Vendor order/product detail pages exist independently (routes/vendor/routes.php) but nothing links an issue to them
- Server — action_key + action_params emitted per issue (app/Services/SellerIntelligence/ControlTowerService.php:199-200; app/Http/Controllers/RestAPI/v3/seller/SellerActionCenterController.php:65-66)
- Evidence — flutter: lib/features/action_center/widgets/insight_action_handler.dart:25,32,39,51; lib/features/control_tower/screens/control_tower_screen.dart:116 | web: /home/user/Pharmacy/app/Services/SellerIntelligence/ControlTowerService.php:199-200 (payload exists, no consumer); no blade in resources/views/vendor-views/ references action_key

**Read the moderator's rejection reasons for a rejected listing (structured reason_codes plus free-text note) before choosing to fix it**  
`products.view` · wave 2  
- App — Yes — _showRejection dialog at lib/features/action_center/widgets/insight_action_handler.dart:71-116; lists translated reason codes at :86-90, the note at :91-96, and says 'no reason recorded' rather than rendering blank at :99-102
- Web — Partial — vendor product detail shows only the flat denied_note string, and only when request_status == 2: resources/views/vendor-views/product/view.blade.php:17-25. No reason_codes, no 'no reason recorded' state, and nothing on the dashboard
- Server — ListingQualityProducer reads product_moderation_events.reason_codes + note (app/Services/SellerIntelligence/Producers/ListingQualityProducer.php:145-170) and puts them in action_params (:87,98)
- Evidence — flutter: lib/features/action_center/widgets/insight_action_handler.dart:71-116 | web: /home/user/Pharmacy/resources/views/vendor-views/product/view.blade.php:22 (denied_note only, no reason_codes); /home/user/Pharmacy/app/Services/SellerIntelligence/Producers/ListingQualityProducer.php:145-170

**Daily briefing — today's order count and revenue with yesterday beside them and a day-over-day percentage that renders '—' when there is no comparable prior day**  
`orders.view` · wave 2  
- App — Yes — DailyBriefingWidget at lib/features/control_tower/widgets/control_tower_widgets.dart:135-197; metrics at :146-153, the null-safe change line at :181-196
- Web — No — the vendor dashboard's comparison card is month-to-date vs previous equal period, not today vs yesterday: resources/views/vendor-views/partials/_operational-kpis.blade.php:20-52. Different window, different service (VendorDashboardStatsService::salesWithComparison, app/Http/Controllers/Vendor/DashboardController.php:145-149)
- Server — GET /api/v3/seller/seller-center/control-tower/briefing — SellerControlTowerController::briefing (app/Http/Controllers/RestAPI/v3/seller/SellerControlTowerController.php:66) → DailyBriefingService::forSeller (app/Services/SellerIntelligence/DailyBriefingService.php:36, dayFigures at :69, change at :50)
- Evidence — flutter: lib/features/control_tower/widgets/control_tower_widgets.dart:146-153,181-196; lib/features/control_tower/domain/repositories/control_tower_repository.dart:15 | web: /home/user/Pharmacy/resources/views/vendor-views/partials/_operational-kpis.blade.php:20,39 (month window); /home/user/Pharmacy/app/Http/Controllers/Vendor/DashboardController.php:145-149

**Briefing queue counters — awaiting shipment, SLA at risk, returns to answer, low stock, withdrawable balance in one block**  
`orders.view` · wave 2  
- App — Yes — ReportCardWidget rows at lib/features/control_tower/widgets/control_tower_widgets.dart:162-177 (awaiting_shipment, sla_at_risk, returns_to_answer, low_stock_products, withdrawable_balance)
- Web — Partial — low stock and out of stock appear as KPI cards (resources/views/vendor-views/partials/_operational-kpis.blade.php:58,73) and withdrawable balance appears in the wallet card (resources/views/vendor-views/partials/_dashboard-wallet-status.blade.php:10). Awaiting-shipment, SLA-at-risk and returns-to-answer counts do not exist anywhere in the vendor panel
- Server — DailyBriefingService::waiting (app/Services/SellerIntelligence/DailyBriefingService.php:100-135); SLA window is the same 6h the severity engine uses (:123)
- Evidence — flutter: lib/features/control_tower/widgets/control_tower_widgets.dart:162-177 | web: /home/user/Pharmacy/resources/views/vendor-views/partials/_operational-kpis.blade.php:58,73 (stock only); /home/user/Pharmacy/resources/views/vendor-views/partials/_dashboard-wallet-status.blade.php:10; no vendor blade matches 'awaiting_shipment' or 'sla' (verified by grep over resources/views/vendor-views)

**Briefing standing-issue summary — how many issues are critical right now and how many fall due today**  
`orders.view` · wave 2  
- App — Partial — parsed into DailyBriefingModel.criticalIssues / dueToday (lib/features/control_tower/domain/models/control_tower_models.dart:230-231, 260-261) but DailyBriefingWidget never renders them; the seller reads the same facts off the tower's sections instead
- Web — No — the only web consumer of ControlTowerService::summary is the unrouted Seller Center home (app/Http/Controllers/Seller/HomeController.php:48 → resources/views/seller-views/home.blade.php:83,93), which has no registered route
- Server — ControlTowerService::summary (app/Services/SellerIntelligence/ControlTowerService.php:119-134), embedded in the briefing at DailyBriefingService.php:52
- Evidence — flutter: lib/features/control_tower/domain/models/control_tower_models.dart:230-231,260-261 (parsed, not rendered in widgets/control_tower_widgets.dart:141-178) | web: /home/user/Pharmacy/app/Http/Controllers/Seller/HomeController.php:48; /home/user/Pharmacy/resources/views/seller-views/home.blade.php:83,93; /home/user/Pharmacy/routes/seller/routes.php:35-44 (no seller.home route)

**Briefing cancelled / returned counts for today**  
`orders.view` · wave 2  
- App — Partial — parsed at lib/features/control_tower/domain/models/control_tower_models.dart:225-226 but not rendered by DailyBriefingWidget
- Web — Partial — canceled and returned appear as all-time-or-period order-status tiles, not as a today figure: resources/views/vendor-views/partials/_dashboard-order-status.blade.php:47-64
- Server — DailyBriefingService::dayFigures (app/Services/SellerIntelligence/DailyBriefingService.php:86-87)
- Evidence — flutter: lib/features/control_tower/domain/models/control_tower_models.dart:225-226 | web: /home/user/Pharmacy/resources/views/vendor-views/partials/_dashboard-order-status.blade.php:47-64 (period tiles, not a daily briefing); /home/user/Pharmacy/app/Services/SellerIntelligence/DailyBriefingService.php:86-87

**Action Center — one flat list of everything open, worst first, read from the single insight store**  
`orders.view,products.view (routes/rest_api/v3/seller.php:588)` · wave 2  
- App — Yes — ActionCenterScreen at lib/features/action_center/screens/action_center_screen.dart:22; list at :81-95; controller at controllers/action_center_controller.dart:33
- Web — No — no vendor route, controller or view reads seller_insights (verified by grep for SellerInsight over routes/, app/Http/Controllers/Vendor/ and resources/views/vendor-views/: zero hits)
- Server — GET /api/v3/seller/seller-center/action-center — SellerActionCenterController::index (app/Http/Controllers/RestAPI/v3/seller/SellerActionCenterController.php:41); route at routes/rest_api/v3/seller.php:587-595
- Evidence — flutter: lib/features/action_center/screens/action_center_screen.dart:22,81-95; lib/features/action_center/domain/repositories/action_center_repository.dart:12 | web: grep -rln 'SellerInsight' over /home/user/Pharmacy/routes /home/user/Pharmacy/app/Http/Controllers/Vendor /home/user/Pharmacy/resources/views/vendor-views → no hits; /home/user/Pharmacy/routes/rest_api/v3/seller.php:587-595

**Filter the Action Center by severity (all / critical / high / medium / low) with the open count shown on each chip**  
`orders.view,products.view` · wave 2  
- App — Yes — InsightSeverityFilterWidget at lib/features/action_center/widgets/action_center_widgets.dart:119-170; wired at action_center_screen.dart:46-52; severity passed as a query param at domain/repositories/action_center_repository.dart:16
- Web — No — no severity filter exists; the vendor dashboard's only filter is the statistics period select (resources/views/vendor-views/dashboard/index.blade.php:35-57)
- Server — severity query param validated against SEVERITY_ORDER (app/Http/Controllers/RestAPI/v3/seller/SellerActionCenterController.php:106-111); counts returned at :53
- Evidence — flutter: lib/features/action_center/widgets/action_center_widgets.dart:119-170; lib/features/action_center/domain/repositories/action_center_repository.dart:16 | web: /home/user/Pharmacy/resources/views/vendor-views/dashboard/index.blade.php:35-57 (period only); /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerActionCenterController.php:106-111

**Dismiss an insight, with critical insights explicitly non-dismissible**  
`orders.view,products.view` · wave 2  
- App — Yes — dismiss button rendered only when insight.dismissible (lib/features/action_center/widgets/action_center_widgets.dart:76-81); screen passes null for non-dismissible rows at action_center_screen.dart:92; POST at domain/repositories/action_center_repository.dart:25-27
- Web — No — no dismiss endpoint or control in the vendor panel
- Server — POST /api/v3/seller/seller-center/action-center/{id}/dismiss — SellerActionCenterController::dismiss (app/Http/Controllers/RestAPI/v3/seller/SellerActionCenterController.php:85); dismissible computed server-side at :68 (severity !== critical)
- Evidence — flutter: lib/features/action_center/widgets/action_center_widgets.dart:76-81; lib/features/action_center/domain/repositories/action_center_repository.dart:25-27 | web: /home/user/Pharmacy/routes/vendor/routes.php has no dismiss route (grep 'dismiss' → no hit); /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerActionCenterController.php:68,85

**Insight metric rendered in the words of its own type — hours left / hours late for ORDER_SLA, score /100 for LISTING_QUALITY, remaining stock for INVENTORY_RISK**  
`orders.view,products.view` · wave 2  
- App — Yes — type-aware switch at lib/features/action_center/widgets/action_center_widgets.dart:98-112, returns null rather than a placeholder for types with no figure
- Web — No — no web view renders insight.metric at all
- Server — metric emitted per insight (app/Http/Controllers/RestAPI/v3/seller/SellerActionCenterController.php:63); produced by app/Services/SellerIntelligence/Producers/ (12 producers, e.g. OrderSlaProducer.php, ListingQualityProducer.php, InventoryRiskProducer.php)
- Evidence — flutter: lib/features/action_center/widgets/action_center_widgets.dart:98-112 | web: no blade in /home/user/Pharmacy/resources/views/vendor-views/ references 'metric' for insights; /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerActionCenterController.php:63

**Home surface for what needs attention — a card that appears only when something is waiting, previews the top 3 insights, shows an urgent (critical+high) badge and links to the full list**  
`orders.view,products.view` · wave 2  
- App — Yes — ActionCenterCardWidget at lib/features/action_center/widgets/action_center_card_widget.dart:22; renders nothing when empty at :31; urgent badge at :51-58; 'view all' at :64-67; mounted on home at lib/features/home/screens/home_page_screen.dart:148 (above the order lists on purpose)
- Web — No — the vendor dashboard has no attention card. resources/views/vendor-views/dashboard/index.blade.php:9 renders _operational-kpis, which is 4 static KPI numbers with no issue rows and no links
- Server — Same action-center endpoint; urgentCount derived client-side from counts (lib/features/action_center/domain/models/seller_insight_model.dart:110)
- Evidence — flutter: lib/features/action_center/widgets/action_center_card_widget.dart:22,31,51-58,64-67; lib/features/home/screens/home_page_screen.dart:148 | web: /home/user/Pharmacy/resources/views/vendor-views/dashboard/index.blade.php:9; /home/user/Pharmacy/resources/views/vendor-views/partials/_operational-kpis.blade.php:15-81 (no rows, and despite the file's own comment at :7-8 the low-stock/out-of-stock cards are not anchors)

**Out-of-stock / limited-stock products surfaced on the dashboard as an openable list of the actual products**  
`products.view` · wave 2  
- App — Yes — StockOutProductView(isHome: true) at lib/features/home/screens/home_page_screen.dart:170, fed by ProductController.getStockOutProductList (:57) and getStockLimitStatus (:67)
- Web — Partial — counts only, and not clickable: resources/views/vendor-views/partials/_operational-kpis.blade.php:58-66 (low stock) and :70-79 (out of stock), computed by VendorDashboardStatsService::inventoryAlerts (app/Http/Controllers/Vendor/DashboardController.php:144). No product rows, no link to the filtered list despite the partial's own comment at :7-8
- Server — App: product stock-limit endpoints in the products domain. Web: VendorDashboardStatsService::inventoryAlerts
- Evidence — flutter: lib/features/home/screens/home_page_screen.dart:57,67,170 | web: /home/user/Pharmacy/resources/views/vendor-views/partials/_operational-kpis.blade.php:55-80 (numbers, no anchors, no rows); /home/user/Pharmacy/app/Http/Controllers/Vendor/DashboardController.php:144

**Most popular products block on the dashboard**  
`products.view` · wave 2  
- App — Yes — MostPopularProductScreen(isMain: true) at lib/features/home/screens/home_page_screen.dart:182, loaded at :70
- Web — No — the vendor dashboard renders top-rated and top-selling only (resources/views/vendor-views/dashboard/index.blade.php:133,138); no 'most popular' partial exists in resources/views/vendor-views/partials/
- Server — App: most-popular product endpoint in the products domain (ProductController.getMostPopularProductList). Web: none
- Evidence — flutter: lib/features/home/screens/home_page_screen.dart:70,182 | web: /home/user/Pharmacy/resources/views/vendor-views/dashboard/index.blade.php:133,138; ls /home/user/Pharmacy/resources/views/vendor-views/partials/ → only _top-rated-products, _top-selling-products, _top-rated-delivery-man

## APP MISSING (3)

**Top-rated products block on the dashboard**  
`reviews.view / products.view`  
- App — No — home renders top-selling and most-popular only; ProductReviewController.getReviewList is fetched at lib/features/home/screens/home_page_screen.dart:72 but nothing on the home tree renders it
- Web — Yes — resources/views/vendor-views/dashboard/index.blade.php:133 → _top-rated-products.blade.php, data from ProductRepository::getTopRatedList (app/Http/Controllers/Vendor/DashboardController.php:70)
- Server — Web: DashboardController::index (:70). No app-side equivalent on home
- Evidence — flutter: lib/features/home/screens/home_page_screen.dart:72 (review list loaded, never rendered), :180-182 (only top-selling and most-popular) | web: /home/user/Pharmacy/resources/views/vendor-views/dashboard/index.blade.php:133; /home/user/Pharmacy/app/Http/Controllers/Vendor/DashboardController.php:70

**Auction wallet statistics on the dashboard (only when the Auction add-on is published)**  
`none on web`  
- App — No — no auction wallet block in lib/features/home; the app has no auction wallet widget on home
- Web — Yes — resources/views/vendor-views/dashboard/index.blade.php:82-98, populated by AuctionVendorWalletStatsService when the add-on is published (app/Http/Controllers/Vendor/DashboardController.php:116-118)
- Server — Modules/Auction AuctionVendorWalletStatsService::getStats (app/Http/Controllers/Vendor/DashboardController.php:117)
- Evidence — flutter: lib/features/home/screens/home_page_screen.dart:137-190 (no auction block) | web: /home/user/Pharmacy/resources/views/vendor-views/dashboard/index.blade.php:82-98; /home/user/Pharmacy/app/Http/Controllers/Vendor/DashboardController.php:116-118

**Real-time activity poll on the dashboard — count of unchecked new orders and restock-request alerts with a link into the restock list**  
`none on web`  
- App — Partial — the home app bar polls notifications and shows an unread badge (lib/features/home/screens/home_page_screen.dart:110-133, loaded at :66) but there is no restock-request activity block on home; restock lives in its own screen (lib/features/restock)
- Web — Yes — vendor.dashboard.real-time-activities wired at resources/views/layouts/vendor/partials/_translated-message-container.blade.php:46, served by DashboardController::getRealTimeActivities (app/Http/Controllers/Vendor/DashboardController.php:372-410) returning new_order_count, restockProductCount and a restock card with a route into vendor.products.request-restock-list
- Server — GET vendor/dashboard/real-time-activities (routes/vendor/routes.php:112). No v3 seller-API equivalent — the app has no endpoint for this composite
- Evidence — flutter: lib/features/home/screens/home_page_screen.dart:66,110-133 (notification badge only) | web: /home/user/Pharmacy/app/Http/Controllers/Vendor/DashboardController.php:372-410; /home/user/Pharmacy/routes/vendor/routes.php:112; /home/user/Pharmacy/resources/views/layouts/vendor/partials/_translated-message-container.blade.php:46

## WEB ENHANCEMENT (1)

**Share-of-total percentage on each order-status figure (ring / arrow, with a down-trend colour when negative)**  
`none`  
- App — Yes — percentage computed client-side over the 4 ongoing statuses at lib/features/home/widgets/on_going_order_widget.dart:88-93 and over the 4 completed ones at lib/features/home/widgets/completed_order_widget.dart:33-34; rendered as a ring at order_type_button_widget.dart:56-60 and as an up/down badge at order_type_button_head_widget.dart:75-80,168-214
- Web — No — the web tiles show the raw count only (resources/views/vendor-views/partials/_dashboard-order-status.blade.php:7)
- Server — none — derived on the client from the same order-statistics payload
- Evidence — flutter: lib/features/home/widgets/on_going_order_widget.dart:88-93; lib/features/home/widgets/order_type_button_head_widget.dart:75-80 | web: /home/user/Pharmacy/resources/views/vendor-views/partials/_dashboard-order-status.blade.php:7 (count only). Presentation-only; the underlying counts are identical, so this is decoration to adapt rather than a missing business capability.

## APP ADAPTATION (3)

**Wallet summary on the dashboard — withdrawable balance, pending withdraw, total commission, already withdrawn, delivery charge earned, total tax, collected cash — plus raising a withdraw request from the dashboard itself**  
`finance.view / payouts.request on the API; ungated on the web dashboard`  
- App — No — home has no wallet block; the app puts this on a separate Wallet screen (lib/features/wallet/screens/wallet_screen.dart), reached from the menu, not from lib/features/home
- Web — Yes — resources/views/vendor-views/dashboard/index.blade.php:66-80 → _dashboard-wallet-status.blade.php:10,29,43,58,71,84,97; withdraw-request modal at index.blade.php:100-126, posted to vendor.dashboard.withdraw-request (app/Http/Controllers/Vendor/DashboardController.php:209)
- Server — Web: DashboardController::getWithdrawRequest (:209) with cooling-period and KYC gates at :217-224. App: payout/wallet endpoints in the wallet domain
- Evidence — flutter: lib/features/home/screens/home_page_screen.dart:137-190 (no wallet widget in the home tree) | web: /home/user/Pharmacy/resources/views/vendor-views/dashboard/index.blade.php:66-126; /home/user/Pharmacy/app/Http/Controllers/Vendor/DashboardController.php:209,217-224. Same capability, different placement — the app owns it in the wallet feature, so this is placement rather than a gap.

**Pull-to-refresh the whole control tower / action center / home dashboard**  
`none`  
- App — Yes — RefreshIndicator at lib/features/control_tower/screens/control_tower_screen.dart:46, lib/features/action_center/screens/action_center_screen.dart:55, lib/features/home/screens/home_page_screen.dart:90-95
- Web — n/a — the web reloads the page; the dashboard refreshes order-status and earning blocks over AJAX (app/Http/Controllers/Vendor/DashboardController.php:172,186)
- Server — same GET endpoints re-issued
- Evidence — flutter: lib/features/control_tower/screens/control_tower_screen.dart:46; lib/features/home/screens/home_page_screen.dart:90-95 | web: /home/user/Pharmacy/app/Http/Controllers/Vendor/DashboardController.php:172,186 (AJAX partial reload). Gesture-level difference only.

**Bottom navigation shell with Home / Orders / POS / Products / More, with the POS tab appearing only when POS is enabled both platform-wide and for this seller**  
`none`  
- App — Yes — nav items built at lib/features/dashboard/screens/dashboard_screen.dart:92-98; posEnabled computed from configModel.posActive && userInfoModel.posActive at :85-86; falls back to Home if POS is revoked while open at :87-91
- Web — n/a — the vendor panel uses a sidebar/rail (resources/views/layouts/vendor/partials/v2/_side-bar.blade.php, _rail.blade.php) with its own visibility rules
- Server — config + seller flags read from the splash/profile endpoints
- Evidence — flutter: lib/features/dashboard/screens/dashboard_screen.dart:85-98 | web: /home/user/Pharmacy/resources/views/layouts/vendor/partials/v2/_side-bar.blade.php:490 (sidebar shell, no bottom tabs). Navigation chrome, not a business capability.

## DEVICE SPECIFIC (2)

**Barcode scan floating action button on the POS tab of the dashboard**  
`pos.manage (POS domain)`  
- App — Yes — FloatingActionButton calling BarcodeScanController.scanProductBarCode at lib/features/dashboard/screens/dashboard_screen.dart:115-121
- Web — No — no camera scan in the vendor panel; POS search is keyboard-driven (resources/views/vendor-views/pos/index.blade.php)
- Server — POS product lookup endpoints (POS domain)
- Evidence — flutter: lib/features/dashboard/screens/dashboard_screen.dart:115-121 | web: /home/user/Pharmacy/resources/views/vendor-views/pos/index.blade.php (search input, no scanner). The camera scan is device-specific; the underlying 'look a product up by its barcode' capability belongs to the POS domain and must exist on the web there.

**Exit-the-app confirmation from the dashboard root**  
`none`  
- App — Yes — PopScope + confirmation dialog at lib/features/dashboard/screens/dashboard_screen.dart:100-109,223-233
- Web — n/a — browsers own tab closing
- Server — none

## BACKEND MISSING (2)

**Assign an issue to a named staff member (explicit assignee rather than the implicit 'whoever touched it')**  
`orders.manage`  
- App — No — repository posts only {'status': status} (lib/features/control_tower/domain/repositories/control_tower_repository.dart:21-22); assigned_staff_id is never sent
- Web — No — no web issue surface exists at all
- Server — Yes — updateStatus validates assigned_staff_id and verifies the staff member belongs to this shop (app/Http/Controllers/RestAPI/v3/seller/SellerControlTowerController.php:86,111-117,132-136)
- Evidence — flutter: lib/features/control_tower/domain/repositories/control_tower_repository.dart:21-22 (payload is status only) | web: /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerControlTowerController.php:86,111-117 — server supports it, neither client exposes it. Categorised BACKEND MISSING in the inverse sense: server support exists with no UI on either side; treat as a build-both item.

**Filter insights by type (repeatable ?type= parameter)**  
`orders.view,products.view`  
- App — No — ActionCenterRepository sends only limit and severity (lib/features/action_center/domain/repositories/action_center_repository.dart:14-17)
- Web — No — no web insight surface
- Server — Yes — SellerActionCenterController::types (app/Http/Controllers/RestAPI/v3/seller/SellerActionCenterController.php:97-104)
- Evidence — flutter: lib/features/action_center/domain/repositories/action_center_repository.dart:14-17 (no type param) | web: /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerActionCenterController.php:97-104 — server-side filter with no client on either side; build it into the web list.

