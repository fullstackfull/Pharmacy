# Parity — automation (seller rules engine — "if X happens to my catalogue, do Y", plus the audit trail of what the rules did)

[← back to the matrix](../SELLER_WEB_APP_PARITY.md) · 36 capabilities

**35** WEB MISSING · **1** APP ADAPTATION

## Structural facts the implementer must know

```
STRUCTURAL FINDING — the entire domain is WEB MISSING. There is no vendor-panel automation surface of any kind. Verified by: `grep -rn -i automation` over /home/user/Pharmacy/routes/vendor/routes.php, /home/user/Pharmacy/resources/views/vendor-views/**, /home/user/Pharmacy/app/Http/Controllers/Vendor/** → zero matches; `ls resources/views/vendor-views/marketplace/` → payouts, seller-center, seller-scorecard, seller-verification, staff only. The backend is complete and mature (11 endpoints, engine, registry, breaker, trail, undo, scheduler), so this is pure UI work, not backend work.

DANGLING NAV ROUTES (fix first, they are the intended entry points): /home/user/Pharmacy/app/Services/SellerCenter/Navigation.php:135-137 already declares three destinations — `seller.automation.index` (nav_automation_rules), `seller.automation.scheduled` (nav_scheduled_ops), `seller.automation.history` (nav_automation_history), all gated on products.manage — and /home/user/Pharmacy/app/Services/SellerCenter/Search.php:71 declares a command-palette entry `create_automation` → `seller.automation.index` with params ['action' => 'new']. None of those routes exist: /home/user/Pharmacy/routes/seller/routes.php:27-45 defines only preferences/density, preferences/direction, search, help, foundation. Navigation.php:269 wraps href resolution in `Route::has()`, so the items are silently dropped rather than throwing — the IA for this domain is already designed and merely unimplemented. Implementing three controllers/views under App\Http\Controllers\Seller with those exact route names lights up the rail, the mobile drawer, the bottom bar and the palette at once.

The Flutter IA is two tabs (rules / trail) and the nav expects three pages (rules / scheduled / history). Suggested mapping: `index` = rules list + create/edit form + preview sheet + run-now/pause/resume/delete; `history` = the activity trail with undo; `scheduled` = the piece NEITHER surface has today (next-run/cooldown view over AutomationEngine::runDue) — treat it as new design, not as parity.

SETTINGS/TOGGLES AUDIT — clean. `grep -rn "SharedPreferences|prefs" lib/features/automation/` returns zero hits. Every piece of business state in this domain (rule definition, trigger/action settings, status, max_actions_per_run, cooldown_minutes, run counters, suspension, trail, revert state) is server-owned and read back from the API; the only client-held values are transient TextEditingController form defaults (cap '50', cooldown '15' at automation_rule_form_screen.dart:28-29) which are overwritten by the rule's stored values on edit and re-sent on save. Nothing to flag.

DEVICE-SPECIFIC: nothing in this domain. No camera, biometrics, push permission, deep link or share. The pull-to-refresh row is the only mobile-idiom item and it is an adaptation, not a capability the web lacks.

BACKEND CAPABILITY NOT EXPOSED BY EITHER CLIENT (free wins when building the web pages):
- Pagination: index and activity both return total_size/limit/offset and accept `limit` (rules max 50, activity max 100) — SellerAutomationController.php:66,272. The Flutter controller ignores all three (automation_controller.dart:64-90), so the app silently shows only the first page. The web pages should paginate properly.
- Activity `status` filter (applied/skipped/failed) — SellerAutomationController.php:270. No client uses it; the Flutter repository does not even build it (automation_repository.dart:23-28).
- Creating a rule directly in the paused state — `status` is accepted on store/update (SellerAutomationRuleService.php:175,209) but the Flutter form never sends it (automation_rule_form_screen.dart:249-257), so every new rule starts live. A "save without switching on" control is worth adding on web.
- GET rules/{id} run history (20 most recent runs, with outcome capped/applied/failed/no_match and matched/applied/skipped/failed counts) — fully built and unreachable in the app.

BEHAVIOURS THE WEB UI MUST REPRODUCE OR IT WILL BE WRONG:
1. `suspended` is not a shade of `paused`. Paused = seller switched it off; suspended = the platform stopped it (AutomationEngine.php:422-440). Different colour family, per the Flutter treatment (automation_widgets.dart:14-26).
2. `suspended_by` splits the suspension in two: `platform` (breaker) is the seller's to clear via PUT status=active; `marketplace` is not, and the control must be absent, not disabled — SellerAutomationRuleService.php:124-126 returns 403, SellerAutomationRule.php:76-79. Flutter's exact treatment: hide the button, show a sentence saying why (automation_widgets.dart:110-114,150).
3. Editing a rule resets its failure counter and clears a breaker suspension, but never clears a marketplace hold (SellerAutomationRuleService.php:77,85,91-94).
4. An omitted field on edit keeps the stored value; it does not fall back to creation defaults (SellerAutomationRuleService.php:206-211) — the web form must send the whole rule or respect this.
5. "Run now" is refused (403) on a suspended rule — SellerAutomationController.php:241-245; the app hides the button in that state (automation_widgets.dart:142).
6. Preview and the real run share one code path (AutomationEngine.php:23-26,53-80) — the web preview must call the endpoint, never re-derive matches in Blade.
7. Undo is not a general write: only the columns the action declares (revertibleColumns) and only from the recorded `before` (AutomationEngine.php:201-211). Revertibility dies the moment anyone else touches the record — AutomationClaimObserver.php:29-48 stamps superseded_at on ANY product status write from any surface.

RELATED-BUT-ADJACENT (out of this domain's rows, worth knowing): automated price moves are attributed in ProductPriceChange with SOURCE_AUTOMATION and the rule's name as the reason (AutomationEngine.php:258-260), and an undo is re-attributed as SOURCE_SELLER_UI/'automation_undo' (AutomationEngine.php:207-211). The price-history reader for sellers exists only on the API (app/Http/Controllers/RestAPI/v3/seller/SellerFinanceControlController.php) — there is no vendor-panel price history either, so "which rule moved this price" is currently unanswerable on web. Track that under the finance/pricing domain.

The marketplace-side counterpart is complete on web and admin-only: routes/admin/routes.php:645,651-652 (seller-operations automation page + suspend-rule/release-rule) rendering resources/views/admin-views/marketplace/seller-operations/automation.blade.php. It is a useful reference implementation for tables/badges but prints raw trigger/action keys (automation.blade.php:57) — the seller-facing pages must use the trigger_*/action_*/setting_* translation keys instead; the app's full label set is in /home/user/sillercenter-syria-cosmatics/assets/language/en.json:1630-1671 (and ar.json at the same lines) and can be ported verbatim into the Laravel translation files.
```

## WEB MISSING (35)

**Reach an automation console at all (rules + activity, one destination)**  
`products.view or products.manage (seller_can, routes/rest_api/v3/seller.php:566)` · wave 3  
- App — Yes — More menu entry pushes AutomationScreen (tabs: Rules / What automation did)
- Web — No — no vendor route, view or controller exists; the Seller Center nav declares the item but the route it names is undefined so Navigation drops it
- Server — routes/rest_api/v3/seller.php:564-581 → App\Http\Controllers\RestAPI\v3\seller\SellerAutomationController
- Evidence — flutter: /home/user/sillercenter-syria-cosmatics/lib/features/menu/screens/more_screen.dart:154-156; /home/user/sillercenter-syria-cosmatics/lib/features/automation/screens/automation_screen.dart:43-79 | web: /home/user/Pharmacy/app/Services/SellerCenter/Navigation.php:135-137 declares seller.automation.index/.scheduled/.history, but /home/user/Pharmacy/routes/seller/routes.php:27-45 defines only preferences/search/help/foundation, and Navigation.php:269 hides any item whose route fails Route::has — so the entry silently vanishes. grep -i automation over routes/vendor/routes.php, resources/views/vendor-views/**, app/Http/Controllers/Vendor/** = 0 matches

**List every rule the shop has written, newest first, with its state (On / Paused / Stopped)**  
`products.view` · wave 3  
- App — Yes — AutomationScreen rules tab, AutomationRuleCardWidget status pill
- Web — No — not found in vendor-views or seller routes
- Server — GET /api/v3/seller/seller-center/automation/rules → SellerAutomationController::index
- Evidence — flutter: lib/features/automation/screens/automation_screen.dart:82-112; lib/features/automation/widgets/automation_widgets.dart:74-86 (status pill), lib/features/automation/controllers/automation_controller.dart:64-75; lib/features/automation/domain/repositories/automation_repository.dart:15 | backend: /home/user/Pharmacy/routes/rest_api/v3/seller.php:569, /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerAutomationController.php:62-74 | web: not found (grep -i 'automation' resources/views/vendor-views/ = 0 matches)

**Read a rule as a sentence — "when X → then Y" in plain language rather than raw keys**  
`products.view` · wave 3  
- App — Yes — trigger/action translated via trigger_*/action_* keys on the rule card
- Web — No — the only place trigger→action is rendered on the web is the ADMIN seller-operations page, and it prints raw keys
- Server — Same index/show payload (trigger, action fields)
- Evidence — flutter: lib/features/automation/widgets/automation_widgets.dart:89-95; labels at /home/user/sillercenter-syria-cosmatics/assets/language/en.json:1644-1656 | backend: SellerAutomationController.php:330-331 | web: /home/user/Pharmacy/resources/views/admin-views/marketplace/seller-operations/automation.blade.php:57 prints '{{ $rule->trigger }} → {{ $rule->action }}' unlabelled, and it is admin-only (routes/admin/routes.php:645); no vendor equivalent

**See what a rule has actually done: times run, changes made, last run date**  
`products.view` · wave 3  
- App — Yes — stat row on the rule card
- Web — No
- Server — index/show → run_count, applied_count, last_run_at
- Evidence — flutter: lib/features/automation/widgets/automation_widgets.dart:117-123; model fields lib/features/automation/domain/models/automation_models.dart:17-21,67-73 | backend: app/Http/Controllers/RestAPI/v3/seller/SellerAutomationController.php:336-341 | web: not found in resources/views/vendor-views/

**Be warned that a rule runs but has never changed anything (silently mis-written rule)**  
`products.view` · wave 3  
- App — Yes — hasNeverActed advisory banner
- Web — No
- Server — Derived client-side from run_count/applied_count returned by index
- Evidence — flutter: lib/features/automation/domain/models/automation_models.dart:57 (hasNeverActed), lib/features/automation/widgets/automation_widgets.dart:127-131; string en.json:1637 | backend: SellerAutomationController.php:337-338 | web: not found

**See why a rule was stopped (breaker reason: too many matches / three failures in a row / permission revoked / shop inactive)**  
`products.view` · wave 3  
- App — Yes — red suspension banner on the card, reason translated
- Web — No
- Server — suspension_reason set by AutomationEngine::suspend, returned by index/show
- Evidence — flutter: lib/features/automation/widgets/automation_widgets.dart:97-105; reason strings en.json:1657-1663 | backend: /home/user/Pharmacy/app/Services/SellerAutomation/AutomationEngine.php:422-440, 364 (too many matches), 417-419 (FAILURE_LIMIT=3, app/Models/SellerAutomationRule.php:31); presented at SellerAutomationController.php:342-343 | web: not found

**Distinguish a marketplace hold from a breaker trip, and be told the restart is not yours to make (restart control suppressed)**  
`products.view` · wave 3  
- App — Yes — isStoppedByMarketplace hides the status button and shows an explanatory banner
- Web — No
- Server — suspended_by = platform|marketplace; setStatus refuses a marketplace hold
- Evidence — flutter: lib/features/automation/domain/models/automation_models.dart:52, lib/features/automation/widgets/automation_widgets.dart:110-114 and :150 (button hidden) | backend: app/Models/SellerAutomationRule.php:25-28,76-79; app/Services/SellerAutomation/SellerAutomationRuleService.php:124-126; SellerAutomationController.php:344-346 | web: seller side not found; the marketplace-side suspend/release lives in admin only (routes/admin/routes.php:651-652)

**Create a new rule**  
`products.manage` · wave 3  
- App — Yes — FAB → AutomationRuleFormScreen → POST
- Web — No
- Server — POST .../automation/rules → SellerAutomationController::store → SellerAutomationRuleService::create
- Evidence — flutter: lib/features/automation/screens/automation_screen.dart:45-49,159-163; lib/features/automation/screens/automation_rule_form_screen.dart:237-260; repository automation_repository.dart:36-38 | backend: routes/rest_api/v3/seller.php:575; SellerAutomationController.php:113-122; app/Services/SellerAutomation/SellerAutomationRuleService.php:32-65 | web: not found

**Edit an existing rule (and have its failure count reset / breaker suspension cleared by the rewrite)**  
`products.manage` · wave 3  
- App — Yes — tapping a card opens the same form prefilled; PUT
- Web — No
- Server — PUT .../automation/rules/{id} → update
- Evidence — flutter: lib/features/automation/screens/automation_screen.dart:103; lib/features/automation/screens/automation_rule_form_screen.dart:39-48 (prefill), 249-257; automation_repository.dart:39 | backend: routes/rest_api/v3/seller.php:576; SellerAutomationController.php:133-148; SellerAutomationRuleService.php:70-106 (consecutive_failures reset :91, marketplace hold preserved :77,85) | web: not found

**Delete a rule, with an explicit warning that its history of what it already did is kept**  
`products.manage` · wave 3  
- App — Yes — confirm dialog then DELETE
- Web — No
- Server — DELETE .../automation/rules/{id} → destroy (runs and actions are retained)
- Evidence — flutter: lib/features/automation/screens/automation_rule_form_screen.dart:153-160,262-289; string 'delete_rule_keeps_its_history' | backend: routes/rest_api/v3/seller.php:578; SellerAutomationController.php:188-199; SellerAutomationRuleService.php:150-162 | web: not found

**Pause a running rule**  
`products.manage` · wave 3  
- App — Yes — Pause button → PUT status=paused
- Web — No
- Server — PUT .../automation/rules/{id}/status → setStatus
- Evidence — flutter: lib/features/automation/widgets/automation_widgets.dart:150-166; controller automation_controller.dart:152-164; repository automation_repository.dart:48-58 | backend: routes/rest_api/v3/seller.php:577; SellerAutomationController.php:160-177; SellerAutomationRuleService.php:118-148 | web: not found

**Resume a paused rule**  
`products.manage` · wave 3  
- App — Yes — Resume button → PUT status=active
- Web — No
- Server — Same setStatus endpoint
- Evidence — flutter: lib/features/automation/widgets/automation_widgets.dart:150-166 (label switches on rule.isActive) | backend: SellerAutomationController.php:160-177; app/Models/SellerAutomationRule.php:22 (SELLER_SETTABLE_STATUSES) | web: not found

**Restart a rule the breaker stopped — clearing the suspension in the same act, with the reason still on screen**  
`products.manage` · wave 3  
- App — Yes — 'Restart' label on a suspended rule, same status call; failure counter reset server-side
- Web — No
- Server — setStatus active → clears suspended_at/reason/suspended_by, resets consecutive_failures
- Evidence — flutter: lib/features/automation/widgets/automation_widgets.dart:158-161 (restart_rule) | backend: app/Services/SellerAutomation/SellerAutomationRuleService.php:128-137; SellerAutomationController.php:150-159 (doc) | web: not found

**Run a rule now, on demand, ignoring its cooldown**  
`products.manage` · wave 3  
- App — Yes — 'Run now' on the card (hidden while suspended); refetches rules + activity after
- Web — No
- Server — POST .../automation/rules/{id}/run → runNow → AutomationEngine::run
- Evidence — flutter: lib/features/automation/widgets/automation_widgets.dart:142-148; controller automation_controller.dart:180-198; repository automation_repository.dart:27 | backend: routes/rest_api/v3/seller.php:579; SellerAutomationController.php:233-254 (403 for suspended at :241-245); app/Services/SellerAutomation/AutomationEngine.php:88-130 | web: not found

**Preview what a rule would do right now without doing it (dry run)**  
`products.view` · wave 3  
- App — Yes — 'Preview' opens a modal sheet; preview state cleared on close so a stale answer never shows under another rule
- Web — No
- Server — GET .../automation/rules/{id}/preview → AutomationEngine::preview (shares the trigger + action planning code with the real run)
- Evidence — flutter: lib/features/automation/widgets/automation_widgets.dart:135-141; lib/features/automation/screens/automation_screen.dart:167-210; controller automation_controller.dart:107-127 | backend: routes/rest_api/v3/seller.php:571; SellerAutomationController.php:212-221; AutomationEngine.php:53-80 | web: not found

**In the preview, see the rows the rule would DECLINE to touch and the reason for each (already hidden, not approved, below your floor, no price…)**  
`products.view` · wave 3  
- App — Yes — per-subject rows with will_apply icon and translated reason
- Web — No
- Server — preview subjects[] carries will_apply + reason from each action's preview()
- Evidence — flutter: lib/features/automation/widgets/automation_widgets.dart:237-263; reason strings en.json:1664-1671; model automation_models.dart:179-198 | backend: AutomationEngine.php:66-77; reasons emitted at app/Services/SellerAutomation/Actions/SetDiscountAction.php:69-104, PublishListingAction.php:61-71, HideListingAction.php:57-59 | web: not found

**Be warned in the preview that the rule matches more than its own cap and would therefore refuse to run at all**  
`products.view` · wave 3  
- App — Yes — red 'this would stop instead of running' banner when capped
- Web — No
- Server — preview returns capped=true (matches counted cap+1); a real capped run does nothing and suspends the rule
- Evidence — flutter: lib/features/automation/widgets/automation_widgets.dart:219-236; model automation_models.dart:162,169-170 | backend: AutomationEngine.php:62-64 (capped), 125-127, 347-367 (capped run writes OUTCOME_CAPPED then suspends) | web: not found

**Build a rule from the server's own catalogue of triggers/actions/settings (new server trigger appears without an app release)**  
`products.view` · wave 3  
- App — Yes — form fields, dropdowns and required settings all come from GET /catalogue
- Web — No
- Server — GET .../automation/catalogue → AutomationRegistry::catalogue
- Evidence — flutter: lib/features/automation/screens/automation_rule_form_screen.dart:73-84,92-126,168-185; controller automation_controller.dart:55-62; models automation_models.dart:204-281 | backend: routes/rest_api/v3/seller.php:567; SellerAutomationController.php:46-49; app/Services/SellerAutomation/AutomationRegistry.php:78-99 | web: not found

**Choose the trigger: listing runs out of stock / is running low / was restocked after automation hid it / stock has not sold for N days**  
`products.manage` · wave 3  
- App — Yes — 'when' dropdown over catalogue.triggers
- Web — No
- Server — Triggers registered in app/Services/SellerAutomation/Triggers/*
- Evidence — flutter: lib/features/automation/screens/automation_rule_form_screen.dart:92-109; labels en.json:1643-1646 | backend: Triggers/OutOfStockTrigger.php:20, LowStockTrigger.php:20, RestockedTrigger.php:29, StaleStockTrigger.php:22 | web: not found

**Choose the action: take the listing off the storefront / put it back / mark it down**  
`products.manage` · wave 3  
- App — Yes — 'then' dropdown, restricted to the actions the chosen trigger legally accepts
- Web — No
- Server — Actions in app/Services/SellerAutomation/Actions/*; legality enforced by AutomationRegistry::accepts
- Evidence — flutter: lib/features/automation/screens/automation_rule_form_screen.dart:114-124 (options = trigger.actions), 99-108 (illegal action cleared on trigger change) | backend: Actions/HideListingAction.php:20, PublishListingAction.php:21, SetDiscountAction.php:24; AutomationRegistry.php:61-71; enforced again at SellerAutomationRuleService.php:189-191 | web: not found

**Set the trigger's own threshold settings (stock level, days without a sale)**  
`products.manage` · wave 3  
- App — Yes — numeric setting fields rendered from the trigger's declared settings
- Web — No
- Server — Validated per-trigger; keys not declared are dropped
- Evidence — flutter: lib/features/automation/screens/automation_rule_form_screen.dart:111,168-185; labels en.json:1652-1653 | backend: Triggers/LowStockTrigger.php:32-35 (threshold 1..1000 required), OutOfStockTrigger.php:32-35, RestockedTrigger.php:41-44, StaleStockTrigger.php:34-37 (days 7..365); SellerAutomationRuleService.php:236-255 | web: not found

**Set the markdown action's parameters: percent or flat, the discount value, and a hard price floor it must never cross**  
`no permission recorded` · wave 3  
- App — Yes — three setting fields; discount_type kept as free text, the rest numeric
- Web — No
- Server — SetDiscountAction rules; floor is required and the action refuses rather than clamps; the shop-wide PricingPolicyService floor is checked too
- Evidence — flutter: lib/features/automation/screens/automation_rule_form_screen.dart:126,168-185 (numeric: key != 'discount_type'); labels en.json:1654-1656 | backend: app/Services/SellerAutomation/Actions/SetDiscountAction.php:41-50 (discount_type in:percent,flat; discount_value gt:0; min_price_after_discount required gt:0), :83-96 (refuse below floor + PricingPolicyService) | web: not found

**Cap how many records one run may change (blast radius)**  
`products.manage` · wave 3  
- App — Yes — 'most changes in one run' field, default 50
- Web — No
- Server — max_actions_per_run 1..500; a run that would exceed it does NOTHING and trips the breaker
- Evidence — flutter: lib/features/automation/screens/automation_rule_form_screen.dart:28,136-137,255; limits copy at :129-134 | backend: SellerAutomationRuleService.php:21 (MAX_ACTIONS_PER_RUN=500), :176, :210; AutomationEngine.php:125-127,347-367 | web: not found

**Set how long a rule waits between runs (cooldown)**  
`products.manage` · wave 3  
- App — Yes — 'wait between runs (minutes)' field, default 15
- Web — No
- Server — cooldown_minutes 5..10080, honoured by the scheduled sweep via rule->isDue()
- Evidence — flutter: lib/features/automation/screens/automation_rule_form_screen.dart:29,139-140,256 | backend: SellerAutomationRuleService.php:177,211; AutomationEngine.php:153-155 (isDue gate), app/Models/SellerAutomationRule.php:53 | web: not found

**Get field-level validation errors back on the form (including dotted settings keys like action_settings.min_price_after_discount)**  
`products.manage` · wave 3  
- App — Yes — errors parsed by code and attached to the matching input
- Web — No
- Server — 403 with errors:[{code: field, message}]
- Evidence — flutter: lib/features/automation/controllers/automation_controller.dart:41-43,129-150,224-235; lib/features/automation/screens/automation_rule_form_screen.dart:89,98,122,137,140,181,202-204 | backend: SellerAutomationController.php:378-387; SellerAutomationRuleService.php:242-249 (prefixed keys) | web: not found

**Read the trail of everything automation did to the shop ("who changed this" when the answer is not a person)**  
`products.view` · wave 3  
- App — Yes — second tab, AutomationActivityTileWidget list
- Web — No — no seller-facing trail anywhere in vendor-views; the equivalent table exists only on the admin seller-operations page
- Server — GET .../automation/activity → SellerAutomationController::activity
- Evidence — flutter: lib/features/automation/screens/automation_screen.dart:114-139; controller automation_controller.dart:77-90; repository automation_repository.dart:24-28 | backend: routes/rest_api/v3/seller.php:568; SellerAutomationController.php:266-294 | web: not found (resources/views/admin-views/marketplace/seller-operations/automation.blade.php is admin-only, routes/admin/routes.php:645)

**See the before → after value of each automated change on a record**  
`products.view` · wave 3  
- App — Yes — rendered on applied rows (price_after_discount deliberately hidden from the pair)
- Web — No
- Server — before/after JSON columns on seller_automation_actions
- Evidence — flutter: lib/features/automation/widgets/automation_widgets.dart:321-326,350-357 | backend: SellerAutomationController.php:286-287; AutomationEngine.php:341-342 | web: not found

**See why a matched record was skipped or failed rather than changed**  
`products.view` · wave 3  
- App — Yes — translated reason under non-applied rows
- Web — No
- Server — status (applied/skipped/failed) + reason on each action row
- Evidence — flutter: lib/features/automation/widgets/automation_widgets.dart:283,310-316 | backend: app/Models/SellerAutomationAction.php:16-18; AutomationEngine.php:275-281,340 | web: not found

**Undo one automated change (restore the value the rule replaced)**  
`products.manage` · wave 3  
- App — Yes — 'Undo' on revertible rows; refetches the trail after
- Web — No
- Server — POST .../automation/activity/{id}/revert → AutomationEngine::revert; restores only the columns the action declares it owns
- Evidence — flutter: lib/features/automation/widgets/automation_widgets.dart:335-345; controller automation_controller.dart:200-216; repository automation_repository.dart:30 | backend: routes/rest_api/v3/seller.php:580; SellerAutomationController.php:306-323; AutomationEngine.php:172-225; revertibleColumns at HideListingAction.php:46-49, PublishListingAction.php:50-53, SetDiscountAction.php:52-55 | web: not found

**Know when a change can no longer be undone — already undone, or someone has touched the record since**  
`products.view` · wave 3  
- App — Yes — 'Undone' label, and the Undo control is absent when revertible=false
- Web — No
- Server — revertible computed from status/reverted_at/superseded_at; superseded_at set by AutomationClaimObserver on any status write
- Evidence — flutter: lib/features/automation/widgets/automation_widgets.dart:328-345; model automation_models.dart:125-126,154-155 | backend: app/Models/SellerAutomationAction.php:44-52; app/Observers/AutomationClaimObserver.php:29-48; SellerAutomationController.php:289 | web: not found

**Rules run unattended on a schedule (the whole point of the feature) — seller sets the cadence and reads last-run**  
`products.manage` · wave 3  
- App — Partial — sets cooldown and reads last_run_at/last_fired_at, but there is no 'next run' or sweep-health view
- Web — No — nothing seller-facing; nav even advertises a 'Scheduled ops' page (seller.automation.scheduled) that does not exist
- Server — php artisan seller:run-automation → AutomationEngine::runDue (sweep limit 200, cooldown-gated)
- Evidence — flutter: lib/features/automation/screens/automation_rule_form_screen.dart:139-140; lib/features/automation/widgets/automation_widgets.dart:120-122 | backend: /home/user/Pharmacy/app/Console/Commands/RunSellerAutomation.php:18-36; AutomationEngine.php:39,137-161 | web: /home/user/Pharmacy/app/Services/SellerCenter/Navigation.php:136 points at an undefined route; routes/seller/routes.php:27-45 has no such route

**Open a rule and read its recent run history (outcome, matched/applied/skipped/failed per run)**  
`products.view` · wave 3  
- App — Partial — repository + controller + AutomationRunModel exist and call GET rules/{id}, but no screen ever invokes loadRuns(); the runs list is unreachable in the UI
- Web — No
- Server — GET .../automation/rules/{id} → show, returns last 20 runs
- Evidence — flutter: lib/features/automation/controllers/automation_controller.dart:92-105 (loadRuns — sole reference; grep 'loadRuns' across lib/ returns only this definition), models automation_models.dart:81-112, repository automation_repository.dart:18 | backend: routes/rest_api/v3/seller.php:570; SellerAutomationController.php:84-101,351-364 | web: not found

**Filter the trail to one rule's activity**  
`products.view` · wave 3  
- App — Partial — repository accepts rule_id and builds the query string, but no screen passes it (no filter control on the activity tab)
- Web — No
- Server — GET .../automation/activity?rule_id= (also supports status=, and paginates)
- Evidence — flutter: lib/features/automation/domain/repositories/automation_repository.dart:23-28; controller automation_controller.dart:77-90 (ruleId param), called only as loadActivity() with no argument at automation_screen.dart:52,121,134 | backend: SellerAutomationController.php:268-272 | web: not found

**Empty-state guidance explaining what rules are and that the trail is empty because nothing has run**  
`none` · wave 3  
- App — Yes — two distinct empty states with explanatory body copy
- Web — No
- Server — none (client-side, driven by empty collections)
- Evidence — flutter: lib/features/automation/screens/automation_screen.dart:83-85,115-118,141-157; strings 'no_rules_yet','rules_explained','automation_has_done_nothing_yet','automation_trail_explained' in /home/user/sillercenter-syria-cosmatics/assets/language/en.json | backend: none | web: not found

**Staff-permission gating of the console (view vs. manage, and a rule may only use an action the writer is allowed to perform)**  
`products.view / products.manage` · wave 3  
- App — Partial — the app relies on the API's 403s; the More-menu entry is not permission-gated client-side
- Web — No surface to gate
- Server — seller_can middleware on every route; the action's own permission re-checked at write time and again at run time
- Evidence — flutter: lib/features/menu/screens/more_screen.dart:154-156 (no permission check around the tile); errors surfaced via ApiChecker at automation_controller.dart:143-144 | backend: routes/rest_api/v3/seller.php:566,574; SellerAutomationRuleService.php:196-198; AutomationEngine.php:111-113 (permission revoked → suspend); action permission at HideListingAction.php:31-34 | web: not found

## APP ADAPTATION (1)

**Refresh the console by pull-to-refresh on either tab**  
`products.view`  
- App — Yes — RefreshIndicator on both lists
- Web — No — a web panel would use a normal reload/filter submit rather than this gesture
- Server — Re-issues the same GET rules / GET activity
- Evidence — flutter: lib/features/automation/screens/automation_screen.dart:87-90,120-123 | backend: routes/rest_api/v3/seller.php:568-569 | web: not applicable; no equivalent page exists to reload

