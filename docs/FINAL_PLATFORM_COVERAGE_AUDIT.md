# Final Platform Coverage Audit

> Domain by domain: what is complete, and what is explicitly not.

A domain is complete when every capability in it is owned by a surface that can actually manage it.

The per-surface lines below count how many capabilities that surface could hold and does not — useful
for seeing where a surface is thin. But a capability is only **incomplete** when the surface that owns
it cannot reach it: an admin-owned policy with no admin screen, a seller-owned action the seller cannot
perform from either client, or anything still unowned. A missing analytics dimension on a well-managed
capability is a gap worth knowing about; it is not the same failure, and collapsing the two would make
this document say that almost everything is broken.

## ANALYTICS

Backend: 47 capabilities
Admin: 34 of 47 covered
Seller Web: 10 of 47 covered
Flutter App: 9 of 28 covered
Analytics: 40 of 47 covered
Monitor: 3 of 47 covered
Dev Portal: 17 of 45 covered
Audit: Complete

Incomplete — the owner cannot reach it (10):

- **Seller-domain analytics events (payout requested, KYC submitted) are recorded as internal traffic and can never reach a report** — assigned to Developer, no surface yet. Ruled: belongs to Developer as a defect in BotDetector. Both events are only ever raised while a seller is authenticated, and BotDetector.php:126 flags any logged-in seller as internal, so every row is written with is_internal=1 and excluded by every rollup and every report — the events exist and are structurally unreportable.
- **Inventory as a measured quantity — stock-out frequency, how long stock sat at zero, sell-through** — assigned to Admin, no surface yet. Ruled: belongs to Analytics. Stock can be listed, adjusted, transferred and written off on every surface, and nothing counts any of it — product_metrics carries views and cart adds and has no stock column, so the cost of a stock-out is unanswerable on a platform whose whole job is stock.
- **Reporting how much traffic went unmeasured because of Do Not Track or missing consent** — assigned to Admin, no surface yet. Ruled: belongs on the Analytics data-quality screen it was written for. PrivacyGate::reason() exists specifically to supply that number and has no caller anywhere, so a shop that turns consent on loses reported traffic with nothing explaining the drop.
- **Seller report builder, saved report definitions and an exports centre** — assigned to Seller, no surface yet. OVERRULED in part — the seller sweep recorded reports and exports as having no backend. Verified: GET seller-center/reports/{orders,products,stock} and three export endpoints do exist on the v3 API and are used by the Flutter app. What is missing is the web surface and the saved-definition/queued-export half: seller.reports.index, seller.reports.builder and seller.exports.index have no route, so on a browser every export is a synchronous download off one specific list.
- **Folding the tail of a high-cardinality dimension into an __other__ row instead of dropping it** — assigned to Developer, no surface yet. Ruled: belongs to Developer as a correctness gap. config/analytics.php:70 promises the tail beyond 500 keys is folded into __other__ 'and the fold is reported rather than hidden'; the analytics rollup applies a limit and writes no such row, so the tail is silently dropped and every breakdown's 'other' figure understates it. Monitoring's BucketWriter does implement the fold, which shows the intended shape.
- **Pipeline health counters — events written, and events dropped because a request overflowed the buffer** — assigned to Admin, no surface yet. Ruled: belongs on the Analytics data-quality screen. EventRecorder records both counters explicitly so that screen can show them, and collectionHealth reads only rollup_ran and write_failed — so a request loop quietly shortening the numbers is recorded and shown to nobody.
- **Per-day performance of each campaign short link** — assigned to Admin, no surface yet. Ruled: belongs on the Admin Campaigns screen, which instead reads lifetime counters off analytics_campaigns. The rollup writes a campaign_link dimension every day and no section asks for it, so the day-by-day series is reachable only by guessing the export URL.
- **The extra facts attached to each event — payment method, coupon code, shipping cost, guest flag, failure reason** — assigned to Admin, no surface yet. Ruled: belongs to Analytics reporting. Every order writes them into analytics_events.properties and exactly one reader exists in the codebase (ExperimentReach pulling properties->experiment), so shipping cost, coupon and payment method per order are captured on every order and reportable on none.
- **Daily history of request volume, visitors, errors and API load (telemetry_daily)** — assigned to Developer, no surface yet. Ruled: belongs to Developer to either surface or stop writing. Three scheduled runs a day maintain the table and the command's own header admits no screen reads it since Analytics moved to analytics_daily — it survives the raw-row prune as retention, so a quarter of the telemetry scheduler budget produces output nobody can look at.
- **Analytics and telemetry policy — consent, Do Not Track, IP masking, bot and staff exclusion, what a session and a bounce are, and how long customer data is kept** — assigned to Admin, no surface yet. Ruled: belongs in Admin Settings and is the clearest case in the whole audit: the Analytics settings page opens with 'Read-only for now, and honest about it' and prints config() values with no form. Every privacy decision about live customer traffic — and two independent retention policies, in config/analytics.php and config/telemetry.php — can only be changed by editing .env and redeploying, so honouring a consent or erasure request is a deployment.

## AUTOMATION

Backend: 24 capabilities
Admin: 20 of 24 covered
Seller Web: 14 of 24 covered
Flutter App: 14 of 17 covered
Analytics: 6 of 24 covered
Monitor: 7 of 24 covered
Dev Portal: 13 of 19 covered
Audit: Complete

Incomplete — the owner cannot reach it (6):

- **Commerce campaigns, segments and experiments are absent from the admin sidebar** — assigned to Admin, no surface yet. Ruled: belongs to Admin navigation. Three complete, audited features exist and an operator finds them only by opening a fourth feature and noticing its tab strip — a discovery problem, not a build problem.
- **How many variants a storefront experiment may run, and how many rules a segment or campaign may carry** — assigned to Admin, no surface yet. Ruled: belongs in Admin Settings for Commerce. How many variants a marketplace may test at once is a traffic-constrained product decision, and variants beyond the fourth are silently sliced off rather than refused.
- **Seller issue policy — the weighted severity model, the escalation ladder, and how often the platform may interrupt a seller's phone** — assigned to Admin, no surface yet. Ruled: belongs in Admin Settings as a policy page, with the notification half exposed to the seller. Thirteen severity constants decide what every seller sees first, an escalation ladder decides the marketplace's enforcement posture toward slow sellers, and a 12-hour window plus a critical/high floor decides what reaches their phone — none of it is settable by anyone, so the only way to stop the noise is to turn notifications off entirely.
- **Scheduled operations — timed price changes, timed activations, campaign starts** — assigned to Seller, no surface yet. Ruled: belongs in the Seller Center and has no backend at all — no route, controller, table or command; two navigation destinations (seller.pricing.scheduled, seller.automation.scheduled) name a server that was never built and are filtered out of the rail.
- **Whether a seller's automation rules and bulk jobs are actually succeeding** — assigned to Admin, no surface yet. Ruled: belongs to Monitoring. The sweep is recorded only as a scheduled-task row, so a run that exits 0 while every rule inside it fails is filed as a success; every run and every action already records an outcome and nothing aggregates them into a success rate or a trend.
- **Commerce Experience master switch — storefront collections, campaigns, segments and experiments on or off** — assigned to Admin, no surface yet. Ruled: belongs in Admin Settings. Four admin screens display the flag's state and none writes it, so the documented rollback path for the whole personalisation engine is one env line and a deploy.

## BRANDS

Backend: 11 capabilities
Admin: Complete
Seller Web: 3 of 11 covered
Flutter App: 8 of 11 covered
Analytics: 1 of 11 covered
Monitor: 0 of 11 covered
Dev Portal: 10 of 11 covered
Audit: Complete

Incomplete — the owner cannot reach it (1):

- **Create, rename or delete a brand in the catalogue** — assigned to Admin, no surface yet. Ruled: belongs on the unified audit trail alongside the brand registry. The registry audits every claim decision; the CRUD that actually creates and deletes the brand records those claims point at audits nothing.

## CATALOG

Backend: 31 capabilities
Admin: 26 of 31 covered
Seller Web: 13 of 31 covered
Flutter App: 14 of 27 covered
Analytics: 14 of 31 covered
Monitor: 4 of 31 covered
Dev Portal: 19 of 30 covered
Audit: Complete

Incomplete — the owner cannot reach it (4):

- **Approve or deny a seller's listing from the classic product screen** — assigned to Admin, no surface yet. Ruled: belongs to the audited moderation path that already exists. ProductModerationService records every decision with reason codes and history; ProductController::approveStatus/deny writes nothing — and the sidebar sends operators to the unaudited one, so whether a listing decision is recorded depends on which screen the operator happened to open.
- **The listing quality bar (a score under 70 is raised for improvement)** — assigned to Admin, no surface yet. Ruled: belongs in Admin Settings. A catalogue quality bar is exactly what a marketplace raises as its listings mature, and here only a deploy can move it.
- **Merchandising limits per collection — 12 pins, 100 exclusions, 20 boosts, boost weight up to 1000, fallback chains 5 deep** — assigned to Admin, no surface yet. Ruled: belongs in Admin Settings for Commerce. A merchandiser curating a large seasonal collection hits 12 pins or 20 boosts and the save is refused with no route other than a deploy.
- **Keeping the storefront product search index in step with the catalogue, and rebuilding it when it drifts** — assigned to Admin, no surface yet. Ruled: belongs on an Admin catalogue page as an index-health readout with a rebuild action. The observer swallows every exception so a product save can never fail, which means a broken index write is invisible, and the weekly reconcile command has no admin surface either — so an import that bypassed the observer leaves storefront search quietly incomplete with no way to notice or repair it from the panel.

## COMPLIANCE

Backend: 22 capabilities
Admin: 20 of 22 covered
Seller Web: 11 of 22 covered
Flutter App: 13 of 19 covered
Analytics: 2 of 22 covered
Monitor: 4 of 22 covered
Dev Portal: 13 of 21 covered
Audit: Complete

Incomplete — the owner cannot reach it (6):

- **Batch expiry warning horizon — stock expiring within 30 days is shown as expiring soon** — assigned to Admin, no surface yet. Ruled: belongs in Admin Settings; on a pharmacy and cosmetics catalogue the expiry warning window is a regulated operational decision. The admin screen hardcodes 30 days with no field while BatchService::expiringSoon takes a caller-supplied value the API can vary, so the two surfaces can disagree about what is expiring.
- **How much notice a seller gets before a verification document expires (45 days)** — assigned to Admin, no surface yet. Ruled: belongs on the seller-verification settings page that already configures which documents are required and whether KYC gates payouts. Today it is an inline literal inside a badge-count query.
- **Disputes and appeals — a channel for a seller to contest a rejection, a suspension, a brand revocation or a chargeback** — assigned to Admin, no surface yet. Ruled: belongs to both panels and exists in neither. Searched app/, Modules/, routes/ and database/migrations for dispute|appeal|case: no controller, no table, no route — only prose in two service files and three dead nav entries (seller.cases.index, seller.incidents.index, seller.appeals.index). The panel can suspend a shop, deny a listing and revoke a brand claim, and the seller's only channel is a support ticket that carries no link to the decision it contests, so nobody can see how many decisions are being challenged.
- **The seller's own account health and SLA standing** — assigned to Seller, no surface yet. Ruled: belongs in the Seller Center. The platform evaluates every approved seller against SLA policy daily and writes audited breaches, and no client renders account health — the seller sees a scorecard number and never the standing, the breach, or the deadline they are being judged against.
- **Compliance as a measured quantity — unauthorised brand listings, verification standing, policy breaches over time** — assigned to Seller, no surface yet. Ruled: belongs on the Seller Center compliance page, which does not exist. Counts.php already computes a compliance_action badge for that missing page, so the platform renders a number on a menu pointing at nothing, and no breach, verification or brand-claim figure is trended anywhere.
- **Seller health tiers — the good / watch / at-risk bands on the admin scorecard** — assigned to Admin, no surface yet. Ruled: belongs with the SLA thresholds that are already admin-configurable. The bands label every seller on the scorecard, are invisible to the operator, and silently disagree with the SLA page sitting beside them — an admin can set a 0.10 return ceiling while the watch band fires at 0.05 regardless.

## FINANCE

Backend: 57 capabilities
Admin: 47 of 57 covered
Seller Web: 29 of 57 covered
Flutter App: 37 of 49 covered
Analytics: 14 of 57 covered
Monitor: 8 of 57 covered
Dev Portal: 39 of 52 covered
Audit: Complete

Incomplete — the owner cannot reach it (12):

- **Dual-control (maker-checker) gate on large seller payouts — above a set amount a payout needs two approvers** — assigned to Admin, no surface yet. Ruled: belongs on Admin → Marketplace → Settlements, beside the maker-checker toggle that already has a screen. Verified by repo-wide grep — payout_dual_control_threshold appears at exactly two read sites and no writer — so it defaults to 0, dual control is off on every install, and arming it is a hand-written database row; the required approver count of 2 is a default argument as well.
- **24-hour payout freeze after a seller changes their bank details** — assigned to Admin, no surface yet. Ruled: belongs in Admin Settings next to the payout queue. It is the platform's anti-account-takeover hold and the length is exactly what a risk team retunes after an incident, yet PayoutService.php:37 is a class constant with no setting key.
- **Changing the shop's bank / payout account from the Flutter app or the v3 API** — assigned to Seller, no surface yet. Ruled: a defect belonging to Developer on the v3 path. The web path calls PayoutService::recordBankChange, which writes the before/after audit row and arms the 24-hour cooling window; SellerController.php:352 writes the same columns directly and does neither, so a payout redirect performed from the phone is both unrecorded and undelayed.
- **Mark a payout failed, or retry one a bank bounced** — assigned to Admin, no surface yet. Ruled: belongs on the Admin payout queue. VendorPayoutRequest::STATUS_FAILED exists and payouts.blade.php:8 colours the badge, but a grep of every STATUS_FAILED write shows only bulk jobs, automation actions and webhook deliveries setting it — nothing ever marks a payout failed, so a bounced transfer stays 'paid' and the seller is never made whole.
- **Payment terms and scheduled cadences — payout frequency, minimum payout, holding period, settlement release time, SLA evaluation time and abandoned-cart send times** — assigned to Admin, no surface yet. Ruled: belongs in Admin Settings. Settlement release is hard-scheduled at 02:00 (bootstrap/app.php:147), seller judgement at 03:00 (:155) and cart reminders at :140/:151, and there is no screen for a payout frequency, a minimum amount or a hold period — so changing the marketplace's payment-terms promise to its sellers is a deploy.
- **How far back a seller's finance reconciliation looks, and how many example rows it shows** — assigned to Admin, no surface yet. Ruled: belongs in Admin Settings. The 30-day default silently bounds how far back a seller can chase a missing payment, which is wrong for any marketplace settling monthly.
- **How late money may be before it is called a finance-integrity problem (6-hour grace on delivered orders)** — assigned to Admin, no surface yet. Ruled: belongs in the Admin SLA/threshold settings. It is the platform's own definition of 'money is late', it is not shown to the admin at all, and it does not agree with the separately configurable stuck_order_hours in config/monitoring.php.
- **Diagnose a payment gateway that is switched on but cannot take a payment** — assigned to Admin, no surface yet. Ruled: belongs on Admin → Third-party → Payment methods as a check button or a banner. Credentials live in addon_settings as separate live_values/test_values blobs and the controllers read only the blob matching the row's mode, so a shop can show a green, fully-filled gateway that refuses every payment; payment:check names the blank field and no screen ever runs it.
- **Why a payment failed — gateway latency, failure reason, and whether the callback ever arrived** — assigned to Admin, no surface yet. Ruled: belongs to Monitoring. No gateway callback leaves a receipt anywhere (PaymentsPanel.php:2056), so a callback that never arrived and one that arrived and failed are the same absent row, and a payment outage is visible only as orders that stopped appearing.
- **Alerting on payout and settlement failure — duplicate settlements, paid orders with no settlement row, commission mismatches** — assigned to Admin, no surface yet. Ruled: belongs to Monitoring. PaymentsPanel really does detect these money-losing conditions, but computes them live on page load and publishes no series, so MetricResolver cannot see them and no rule can be written — a seller who is silently never paid is found only if an admin happens to open the section.
- **Currency model — whether the marketplace runs single-currency or multi-currency with exchange rates** — assigned to Admin, no surface yet. Ruled: belongs on the existing Admin Currency screen, which already reads and displays it. 35 branch sites including every conversion in app/Utils/currency.php depend on it and the only writer is the installer, so the audited bulk exchange-rate editor can be maintaining rates the platform will never apply.
- **Payment success and abandonment rate** — assigned to Developer, no surface yet. Ruled: belongs to Developer to emit. payment_started is in the catalogue, mapped in the recorder and charted by the funnel's gateway breakdown, and verified here: the only three callers of paymentAttempted pass 'succeeded' or 'failed' and never 'started', so a shopper who left the gateway before it answered is invisible and the platform has no payment success rate at all.

## INTEGRATIONS

Backend: 46 capabilities
Admin: 39 of 46 covered
Seller Web: 4 of 46 covered
Flutter App: 16 of 31 covered
Analytics: 3 of 44 covered
Monitor: 20 of 46 covered
Dev Portal: 33 of 42 covered
Audit: Complete

Incomplete — the owner cannot reach it (11):

- **Twelve inbound payment-gateway callbacks (bKash, Flutterwave, LiqPay, MercadoPago, Paymera, PayMob, Paystack, PayTabs, Razorpay, SenangPay and others)** — assigned to Developer, no surface yet. Ruled: belongs in the Developer Portal's partner surface. They are real external webhooks that move money, but they sit under /payment/* rather than api/, so EndpointClassifier marks them panel routes and the explorer, the OpenAPI export and the quality score all skip them.
- **Inbound courier status webhook — POST /api/delivery-syria/orders/update-status** — assigned to Developer, no surface yet. Ruled: belongs in the Developer Portal with a written contract. It is the only genuinely external partner endpoint on the whole API — an outside courier POSTs order status changes into it under a shared secret — and it carries no ApiDoc, so the portal's Partner APIs section shows one endpoint described by a mechanically inferred summary.
- **Seller webhook delivery failure visibility** — assigned to Admin, no surface yet. Ruled: belongs to Monitoring. The marketplace dispatches signed webhooks to sellers' own systems with a retry ledger and a five-minute retry sweep, and app/Services/Monitoring contains no reference to any of it — no panel, no check, no series, no rule. The only count lives in the admin operations overview, so a seller whose endpoint has rejected every delivery for a week produces nothing an operator would see.
- **Documented intent for the API — 438 of 537 endpoints carry no declared contract** — assigned to Developer, no surface yet. Ruled: belongs to Developer as an #[ApiDoc] pass. The manifest describes all 537 endpoints mechanically and the miss count against the route table is zero, but only 99 carry a declared contract and 86 of those are the v3 Seller Center alone (86/86). Outside it: v2 is 0/95, v1 is 11/185, the rest of v3 is 2/170 — so the entire shopper app API, the entire delivery app API, 20 unauthenticated customer auth endpoints, 29 AI endpoints that spend money per call and the tax endpoints are all undescribed.
- **API deprecation lifecycle and the change/breaking-change log** — assigned to Admin, no surface yet. Ruled: belongs in the Developer Portal and is fully built and never run. Four surfaces are wired to render deprecations (portal screen, OpenAPI flag, Postman annotation, Monitoring panel) and zero endpoints declare one; the snapshot service, diff engine and severity classification exist, api_snapshots holds no rows, and verified here — api:snapshot is absent from a scheduler that runs 20 other commands. Three live API versions and no retirement machinery in use.
- **Documentation for outbound seller webhooks — the event catalogue, the signature, the retry policy and the auto-disable behaviour** — assigned to Developer, no surface yet. Ruled: belongs in the Developer Portal's webhooks section, which is the worst of the placeholders: the capability probe returns true so the entry renders enabled and opens onto an empty card, while a complete signed-delivery system with six events, SSRF-guarded dialling and a retry sweep sits beside it. ApiDoc carries emits and dependsOn into every manifest entry and no view renders either — and the only two endpoints that declare emits name events that do not exist in the real webhook vocabulary.
- **Portal sections that render a placeholder — models and enums, integrations, and portal settings** — assigned to Developer, no surface yet. Ruled: belongs to Developer to build or unlist. DeveloperPortalController::dataFor() has no branch for any of them and no blade exists. Portal settings is the costliest: console enable, console writes, console rate limit and response-shape recording are env-only, so an operator cannot turn the Try It console off without a deploy — and the integrations section duplicates a screen Monitoring already has, so the honest fix there is a link.
- **Creating, editing, repointing or deleting a seller's outbound webhook** — assigned to Seller, no surface yet. Ruled: belongs on the audit trail. Only the two paths that switch a webhook OFF are audited — the dispatcher's auto-disable and the admin kill switch — so repointing a live webhook at a new destination, which is how a shop's event data would be exfiltrated, writes nothing.
- **Outbound webhook retry policy — five attempts, doubling backoff, 8-second timeout** — assigned to Admin, no surface yet. Ruled: belongs in Admin Settings. Retry count and backoff decide how long a seller's ERP outage can last before order events are lost forever — five attempts over roughly 31 minutes — and no operator can extend that delivery guarantee without a deploy.
- **Which AI model writes seller content, and how creative it is allowed to be** — assigned to Admin, no surface yet. Ruled: belongs in the AI module's admin settings, which already choose the provider from the database. Because the model name and temperature are hardcoded in the provider class, an operator can switch vendors but cannot change model or cost per call.
- **AI provider credentials — the API key and organisation id the AI module runs on** — assigned to Admin, no surface yet. Ruled: belongs on the audit trail. Verified by grep: no module — AI, Blog or TaxModule — writes a single audit row, so replacing the credential the whole AI module spends money through is one unrecorded form post.

## INVENTORY

Backend: 24 capabilities
Admin: 19 of 24 covered
Seller Web: 13 of 24 covered
Flutter App: 19 of 23 covered
Analytics: 0 of 23 covered
Monitor: 3 of 24 covered
Dev Portal: 19 of 24 covered
Audit: Complete

Incomplete — the owner cannot reach it (4):

- **What counts as low stock — three surviving and mutually inconsistent definitions (7 days of cover, 1/3 days of cover, 14 days of cover)** — assigned to Admin, no surface yet. Ruled: belongs in Admin Settings beside the stock_limit reorder level that already exists. The API's fifth definition has since been folded into that setting, but the insight producer, the Seller Center inventory list and the opportunity cards still each carry their own numbers, so the same shop reports different low-stock counts in the daily briefing, the inventory screen and the opportunity cards.
- **When unsold stock is called dead capital (90 days, at least 3 units)** — assigned to Admin, no surface yet. Ruled: belongs in the Policy settings that already exist for automation. The seller-facing automation rule for the same idea lets the seller pick 7-365 days, so the platform's own judgement is stricter and unmovable while the seller's is configurable.
- **Quick stock edit from the classic product list — sets current_stock directly, with no reason, no movement row and no audit line** — assigned to Admin, no surface yet. Ruled: belongs to Developer to route through InventoryService, which already writes a reason code, a movement ledger row and an audit line to the same column. Two stock-writing paths that disagree about whether a change is traceable will drive current_stock and the movement ledger apart, and the trail cannot say why.
- **Suppliers — the vendors the marketplace itself buys from** — owned by Admin, with no admin surface. Create-only pattern; changing a supplier's bank or contact details is unrecorded.

## MONITORING

Backend: 93 capabilities
Admin: 86 of 93 covered
Seller Web: 4 of 93 covered
Flutter App: 4 of 12 covered
Analytics: 5 of 40 covered
Monitor: 57 of 91 covered
Dev Portal: 13 of 51 covered
Audit: Complete

Incomplete — the owner cannot reach it (17):

- **Monitoring and portal thresholds left as class constants beside the editable threshold map (duplicate-order window, payment capture grace, backup size-drop, incident correlation window, endpoint health verdicts)** — assigned to Admin, no surface yet. Ruled: belongs in the same Admin monitoring threshold map that already holds stuck_order_hours and backup_age_warning_hours — these five sit one file away from it, so the omission is inconsistency rather than design. The endpoint health verdicts additionally duplicate error_rate_warning and p95_critical_ms, so the Developer Portal and the monitor can disagree about the same endpoint.
- **Alert rules — seeding them, creating or editing one, setting a threshold, silencing one, and telling somebody when one fires** — assigned to Admin, no surface yet. Ruled: belongs in Admin → Monitoring, which is registered GET-only. Three compounding failures mean nothing ever pages anyone: the scheduled monitoring:evaluate carries no --seed and there is no seeder, so a fresh install evaluates zero rules forever; no route can write a rule, so a threshold change is a hand-written SQL INSERT; and every shipped rule is created with notify_email=false with no screen to enable it, so alerts land only in laravel.log. The evaluator, incident correlator, cooldown machine, metric resolver and email notifier are all built and unreachable.
- **Exception capture — grouped exceptions with stack traces, occurrence counts, affected users, and marking one resolved** — assigned to Developer, no surface yet. Ruled: belongs to Developer, and it is the single largest hole in the platform. monitoring_error_groups and monitoring_errors are created, read by eight panels and two services and pruned by the rollup, and verified here: the only reference to the table outside readers is the migration itself, because bootstrap/app.php:249 withExceptions() is empty. The Errors page is permanently blank, the health score's error signal permanently unmeasured, and Security's authorisation-failure card, both crash-free cards, the portal's endpoint error lookup and the deploy before/after comparison are all structurally zero — the only error visibility left in the product is the HTTP 5xx rate.
- **Defining the customer journeys the synthetic prober fetches** — assigned to Admin, no surface yet. Ruled: belongs in Admin → Monitoring → Settings, which SyntheticsPanel.php:470 itself says is read-only in this build. Adding a probe on your own checkout page is a shell command, and before that command existed it was a hand-written INSERT.
- **Acknowledging an incident, adding notes, recording probable cause, linking the deploy that caused it and saying who resolved it** — assigned to Admin, no surface yet. Ruled: belongs in Admin → Monitoring → Incidents. Six columns on monitoring_incidents have no writer anywhere, so there is no MTTA, no record of who took an incident and no cause attribution even though the deploy and error tables sit beside it — incident handling happens entirely outside the tool.
- **Writing a human note onto the monitoring timeline** — assigned to Admin, no surface yet. Ruled: belongs in Admin → Monitoring. The annotation renders on the admin timeline and can only be written from a shell, because the whole area is GET-only — the command says so at MonitoringAnnotate.php:20 — so an operator reading a chart cannot annotate what they are looking at.
- **Recording that a backup ran and that a restore was tested** — assigned to Admin, no surface yet. Ruled: belongs in Admin → Monitoring → Backups. Both facts can only be written by shell commands an operator must bolt into their own backup script, so BackupCheck grades the shop degraded permanently for anyone deploying through cPanel or the built-in updater, and the Backups page can only report the gap.
- **Recording a deployment, and comparing performance either side of it** — assigned to Admin, no surface yet. Ruled: belongs in Admin → Monitoring → Deployments. The recording command is the only writer, so the timeline is permanently empty on most installs, and before_metrics/after_metrics have no writer at all — which means the single most useful monitoring sentence, 'p95 doubled at 14:20 and the deploy was at 14:19', cannot be produced.
- **Changing a monitoring threshold, retention window, sampling rate or SLA target** — assigned to Admin, no surface yet. OVERRULED — the admin sweep filed this as internal infrastructure; it is not, because config/monitoring.php:154 and the migration both state the live values are edited in Monitoring → Settings, and SettingsPanel then admits it is read-only. MonitoringSettings::put() exists and is called from exactly two non-UI places, and the panels' own remedy strings tell the operator to run php artisan tinker to move a CPU threshold.
- **Machine-readable JSON feed of every monitoring section** — assigned to Developer, no surface yet. Ruled: belongs in the Developer Portal. Every section returns its full payload as JSON on the same URL with ?json=1 — a complete monitoring API behind admin session auth — and it appears in no portal screen, no OpenAPI export and no Postman collection.
- **Prometheus scrape endpoint and OTLP trace export** — assigned to Developer, no surface yet. Ruled: belongs to Developer to build or to delete the config. Verified: no route matching monitoring/metrics exists anywhere in routes/, and no OTLP exporter or job exists, yet config/monitoring.php documents both and two panels display the Prometheus endpoint as a live setting complete with a security warning about an exposure that cannot happen.
- **Blast radius — how many sellers a failure is affecting** — assigned to Admin, no surface yet. Ruled: belongs to Monitoring as a dimension on every signal. No monitoring table or panel carries a seller, vendor or shop_id — 'vendor' exists only as a request-channel label — so the console can say the queue is backed up or orders are stuck and never whether that is one seller or all of them, which on a marketplace is the first question asked and turns every triage into a manual SQL session.
- **Mobile app health ingest — self-reported sessions, crashes and ANRs from the phone apps** — assigned to Developer, no surface yet. Ruled: belongs to the Flutter app. POST api/v1/app-health exists, is rate-limited, is documented and writes the app.health.* series the Android and iOS panels read — and a grep of the entire seller app finds no caller, so both mobile sections report crash-free sessions as not_configured, which is the one thing about a phone app the server cannot infer.
- **Seeing which scheduled tasks are defined, and when each runs next** — assigned to Admin, no surface yet. Ruled: belongs on the Admin Scheduler page, which cannot serve it as built. Laravel registers the schedule through Artisan::starting(), so a web request resolves an empty Schedule; the page therefore cannot name the tasks that should run, withholds next-due times and the healthy/late/missed counts, and tells the operator to run php artisan schedule:list — i.e. SSH — instead.
- **Retrying, forgetting or flushing a failed queue job** — assigned to Admin, no surface yet. Ruled: belongs in Admin → Monitoring → Queues, which already reads failed_jobs and shows the ten most recent failures. Monitoring is registered GET-only, so a day of undelivered order confirmations, seller webhooks or bulk price changes can only be re-driven with php artisan queue:retry over SSH.
- **Request debugger — look up an X-Request-Id and see what happened** — assigned to Admin, no surface yet. Ruled: belongs in the Developer Portal or Monitoring, and the advice already points at it: the Errors section tells developers to keep the X-Request-Id because it is what makes a failure findable, Monitoring records request_id in its errors and logs panels, and there is no lookup-by-id screen anywhere.
- **Traces — where one slow request's time actually went, as a span waterfall** — owned by an integrator, with nothing in the Developer Portal. The by-kind split is read from the request's own counters rather than summed from nested spans, so the bar cannot claim more milliseconds than the request took.

## NOTIFICATIONS

Backend: 18 capabilities
Admin: 15 of 18 covered
Seller Web: 4 of 18 covered
Flutter App: 7 of 12 covered
Analytics: 1 of 18 covered
Monitor: 7 of 18 covered
Dev Portal: 10 of 13 covered
Audit: Complete

Incomplete — the owner cannot reach it (2):

- **Transactional notification delivery — every order, refund, wallet, OTP, verification, restock, referral and seller-onboarding email, SMS and push** — assigned to Admin, no surface yet. Ruled: belongs in Admin as a delivery log with a resend action, and it is the failure mode most invisible without opening the database. Twenty-three listeners send the platform's entire transactional traffic and not one records whether the message arrived: the fourteen SMS providers return the literal string 'error' and persist nothing, Mail:: bypasses the HTTP-client middleware entirely, and FCM push goes through a trait — so a shop whose SMS credentials expired sends no OTP, no customer can sign in, and every screen in the monitoring console stays green. Queued listeners at least land in failed_jobs; the eight that are not queued (chat, order status, cash collect, referral, delivery-man withdraw, refund) run inline and leave nothing at all. OVERRULED on one point: the jobs sweep recorded OrderEditDuePaymentListener as mis-bound to OrderEditEvent; it now type-hints OrderEditDuePaymentEvent and the due-payment notification fires correctly.
- **Email template mail tester** — assigned to Admin, no surface yet. Ruled: belongs in the Admin sidebar, one link away. The page renders and works; the sidebar points at the /{type}/{tab} view route instead, so the only way to test transactional mail is to type the URL.

## ORDERS

Backend: 26 capabilities
Admin: 22 of 26 covered
Seller Web: 19 of 26 covered
Flutter App: 21 of 25 covered
Analytics: 8 of 25 covered
Monitor: 6 of 26 covered
Dev Portal: 22 of 26 covered
Audit: Complete

Incomplete — the owner cannot reach it (4):

- **vendor/get-order-data — an authenticated seller endpoint returning order data that nothing calls** — assigned to Developer, no surface yet. Ruled: belongs to Developer to delete or document. It is either dead code or an undocumented integration point on order data, and the difference matters because it is reachable with a seller session.
- **What counts as a late order — three definitions that disagree with the configurable SLA deadline (72-hour stuck, quarter-of-window urgent, fixed 120/480-minute colour bands)** — assigned to Admin, no surface yet. Ruled: belongs in the Admin SLA settings that already own sla_processing_hours. The deadline is configurable and the three warning rules around it are not, so a marketplace running a two-hour SLA shows every order as closing from the moment it arrives and the daily briefing calls a different set of orders late than the order screen does.
- **Which order states remain editable, and which remain cancellable** — assigned to Admin, no surface yet. Ruled: belongs in Admin → Order settings, which already exists. Both rules are inline status arrays repeated across at least three files, so a marketplace that wants cancellation to stop at 'processing' has to be given a code change.
- **Minimum number of items required before a customer may check out** — assigned to Admin, no surface yet. Ruled: belongs on the Admin order-settings screen that already carries the minimum order amount. It is seeded at install, read in exactly one place, shipped to all three mobile apps in /api/v1/config, and written by nothing — so the apps enforce a checkout rule the operator cannot see or change.

## PLATFORM

Backend: 122 capabilities
Admin: 93 of 122 covered
Seller Web: 20 of 122 covered
Flutter App: 24 of 66 covered
Analytics: 17 of 119 covered
Monitor: 19 of 122 covered
Dev Portal: 80 of 108 covered
Audit: Complete

Incomplete — the owner cannot reach it (9):

- **Seller Center navigation registry — 41 of its 51 designed destinations resolve to no route and are silently dropped from the rail** — assigned to Seller, no surface yet. Ruled: belongs to the Seller Center web panel. The registry is the design of record and the route table is one fifth of it, so a seller sees a menu that silently omits every capability the phone app already has; the drop is invisible from inside the product because a missing route removes the item rather than erroring.
- **Five pages call route() on names that do not exist, so they throw RouteNotFoundException instead of rendering** — assigned to Developer, no surface yet. Ruled: belongs to Developer as a defect, not a missing surface. Each is a hard 500 on a page a customer or operator will reach — the Paystack one breaks a live payment method — and 19 further missing names elsewhere are correctly guarded by Route::has and degrade quietly, which shows the pattern was understood and these five were missed.
- **Installer and software updater — the first-run wizard and the file-based update flow** — assigned to Developer, no surface yet. Ruled: belongs to Developer. Two whole route files are mapped by methods that are commented out, so a fresh install or an in-place update cannot be driven through any UI; either restore the mapping or delete the route files, controllers and views together.
- **Unlinked admin developer pages — the Kohl design-system gallery and two component galleries mounted on the production admin prefix** — assigned to Developer, no surface yet. Ruled: belongs to Developer, off the production admin prefix. Three live URLs any panel user can open, two of them nameless, kept for component development rather than operation.
- **Silent truncation caps — 500 open issues, 500 SLA deadlines, 200 audit rows, 200 sellers in the admin rollup, 200 automation rules per sweep** — assigned to Admin, no surface yet. Ruled: belongs to Developer to report, and to Admin to raise. Unlike the request guards above these change the answer without saying so: past 200 active rules some sellers' automation simply never runs, past 200 sellers the admin's cross-seller issue rollup is partial, and past 200 rows a seller cannot page further back through their own audit trail — and no screen anywhere prints 'showing 200 of N'.
- **Paid advertising and sponsored placement — ad slots, budgets, billing** — assigned to Admin, no surface yet. Ruled: belongs in Admin and has no backend at all. Searched app/, Modules/, routes/ and database/migrations for advertis*/sponsored/ad_campaign: only BannerService and unrelated substring hits. The marketplace can place its own banners and run merchandising overlays but cannot sell placement to a seller, price it, cap it or bill for it — a revenue line with nothing behind it, and the Seller Center advertises a destination for it that does not resolve.
- **Feature flags and gradual rollout** — assigned to Admin, no surface yet. Ruled: belongs in Admin. No flag table, no config, no per-seller or per-percentage switch anywhere; the only lever is publishing or unpublishing an entire addon module, so every change to the marketplace is all-or-nothing for everyone at once.
- **Duplicate addon manager mounted at /admin/addon** — assigned to Admin, no surface yet. Ruled: belongs to Developer to delete — the gated twin under system-setup is canonical. Verified in the route file: the same controller and the same five actions including upload and delete, linked from no view and no menu, and unlike the twin it sits outside the themes_and_addons module gate, so an admin denied that permission can still publish and delete platform modules through it.
- **Deployments — which build started running when, with migrations run and errors before and after** — owned by an integrator, with nothing in the Developer Portal. Empty until the deploy script calls the command — deploy.sh does not — and its error comparison reads monitoring_errors, which has no writer, so the before/after error counts are structurally zero.

## PRICING

Backend: 15 capabilities
Admin: 14 of 15 covered
Seller Web: 12 of 15 covered
Flutter App: 13 of 15 covered
Analytics: 4 of 15 covered
Monitor: 0 of 15 covered
Dev Portal: 13 of 15 covered
Audit: Complete

Incomplete — the owner cannot reach it (2):

- **Mass product updates written through the query builder bypass the price observer** — assigned to Developer, no surface yet. Ruled: belongs to Developer as a latent hole. ProductRepository::updateByParams issues a builder update, which fires no model events, so any future price write through it would skip the price-change history, the audit row and the seller-visible price history in one step; today's callers touch stock and variations only.
- **What counts as a suspicious price swing (more than half the previous price within 48 hours)** — assigned to Admin, no surface yet. Ruled: belongs in the Policy settings. One ratio is applied to the whole catalogue, and what is extreme for a pharmacy line is normal for clearance.

## RETURNS

Backend: 7 capabilities
Admin: 6 of 7 covered
Seller Web: 6 of 7 covered
Flutter App: Complete
Analytics: 2 of 7 covered
Monitor: 0 of 7 covered
Dev Portal: 6 of 7 covered
Audit: Complete

Incomplete — the owner cannot reach it (3):

- **Returns and refunds as measured quantities — return rate by reason, time to receive, restock rate, refund volume, value and time to settle** — assigned to Admin, no surface yet. Ruled: belongs to Analytics. No event is raised when a refund request is created or approved, and the RMA state machine writes nothing at all, so the platform has two half-measurements that cannot be joined: a rate derived from order_status on the scorecard, and an event named refund_requested that actually fires on an order status change.
- **Approve or reject a customer refund** — assigned to Admin, no surface yet. Ruled: belongs on the unified audit trail. Approval debits the seller's earnings, reverses the marketplace's commission and moves customer money, and writes only to its own refund_status history — so the audit centre cannot answer who approved any refund ever processed.
- **The returns response promise — 48 hours to answer a return request, 72 hours to process it** — assigned to Admin, no surface yet. Ruled: belongs in the Admin SLA settings. A returns-response SLA is a customer promise the marketplace makes, and it exists only as two private constants with no admin or seller field.

## SECURITY

Backend: 47 capabilities
Admin: 36 of 47 covered
Seller Web: 6 of 47 covered
Flutter App: 20 of 31 covered
Analytics: 3 of 45 covered
Monitor: 11 of 47 covered
Dev Portal: 30 of 42 covered
Audit: Complete

Incomplete — the owner cannot reach it (13):

- **Authentication events — sign-in success, sign-in failure and lockout for admins, sellers and seller staff** — assigned to Admin, no surface yet. Ruled: belongs on the audit trail, and the monitoring panel already prints the fix. A rejected password leaves no trace anywhere in the application: no auth.* action exists in app/ or Modules/, and the Admin and Vendor auth controllers contain zero AuditLogger calls, so a credential-stuffing run against the seller panel is indistinguishable from silence and monitoring can only count 401 responses, which measures refusal by any cause.
- **The before/after values and actor context on every audited change** — assigned to Admin, no surface yet. Ruled: belongs on the Admin audit page and the seller's own trail — rows nobody can read are as much a gap as rows never written. AuditLogger captures before, after, ip_address and user_agent on every row; the admin page renders the word 'changed' and nothing else, and the Flutter model drops the diff entirely while its tile shows three of the eight returned fields. The bank-details change PayoutService records specifically so a fraud review can see what the account was redirected from and to cannot be read on any screen in this system.
- **Who may read the audit trail** — assigned to Admin, no surface yet. Ruled: belongs behind its own permission. The only screen that reads the trail sits inside the marketplace route group, so an admin role without that unrelated module flag cannot see a single audit row while theme, commerce, developer-console and approval events keep writing to it.
- **The seller's web view of their own audit trail** — assigned to Seller, no surface yet. Ruled: belongs in the Seller Center, where the IA already reserves seller.audit.index — verified absent from the route table, so the route-existence filter silently drops the menu item. A seller on a browser cannot see what happened in their own shop; only the phone app can, and it drops the before/after values.
- **Admin employee accounts and admin custom roles — who operates the platform and which modules they may touch** — assigned to Admin, no surface yet. Ruled: belongs on the audit trail. Verified zero AuditLogger references in EmployeeController and CustomRoleController: the platform audits every change to a seller's permission model with before/after and none to its own, including granting an employee the 'marketplace' module that unlocks the audit page itself.
- **Business settings — the several hundred DB-driven switches the whole platform boots from** — assigned to Admin, no surface yet. Ruled: belongs on the audit trail, and it matters more here than in most codebases because CLAUDE.md is explicit that behaviour on this platform is DB-driven rather than code-driven. Only 11 of 139 admin controllers call AuditLogger and none of them is under Admin/Settings, so changing the commission percentage, the OTP lockout window, the storage backend, maintenance mode or the forced minimum app version leaves no record of who did it or what it was before — most behavioural change on this platform is unaudited by construction.
- **reCAPTCHA on customer login, registration and both forgot-password flows, and the bot score that refuses a shopper** — assigned to Admin, no surface yet. Ruled: belongs in Admin Settings. Verified by grep: the recaptcha key has read sites in RecaptchaService and the monitoring integrations panel and no writer anywhere in app/Http/Controllers/Admin or resources/views, so the platform's only bot defence on its authentication forms is seeded off at install and can be enabled — or its secret rotated — only by editing the database; the 0.5 score floor beside it is a class constant, and 0.5 is precisely the number an operator lowers when real customers start being blocked.
- **Which channel a customer password reset is sent through — email or SMS OTP** — assigned to Admin, no surface yet. Ruled: belongs in Admin Settings, where the vendor and delivery-man equivalents already have screens. Only the customer one has none, so switching customer account recovery to SMS is a hand-edited row.
- **Minimum password length — 6 characters on some surfaces and 8 on others** — assigned to Admin, no surface yet. Ruled: belongs in Admin Settings as one rule. Password policy is the most commonly retuned security control and here it is scattered across a dozen validators that do not agree with each other (app/Rules contains only DisallowedExtension.php), so a seller can register under an 8-character rule and then reset their password to 6.
- **Brute-force tolerance — 20 attempts a minute on auth endpoints, 3000 a minute globally** — assigned to Admin, no surface yet. Ruled: belongs in Admin Settings. Tightening the login throttle under attack is a posture change an operator makes in minutes, and here it is six route files and a deploy; routes/rest_api/v1/api.php openly comments that the global 3000/min limiter is 'effectively none'.
- **Seller staff reaching the shop's own analytics page** — assigned to Seller Staff, no surface yet. Ruled: a defect belonging to Developer in one line. Verified in the current file: the segment 'analytics' is still absent from the permission map in SellerStaffAccessMiddleware, so deny-by-default 403s every staff member on /vendor/analytics while the same person's API token reaches seller-center/analytics under finance.view — the two clients disagree about what a staff member may see.
- **The authentication requirement the portal reports for the v2 seller API** — assigned to Developer, no surface yet. Ruled: a defect belonging to Developer, and the single most dangerous claim the portal makes. Verified: routes/rest_api/v2/api.php:27 declares only api_lang, the controllers authenticate in-line through Helpers::get_seller_by_token(), and AuthResolver reads middleware only — so the portal tells every reader that balance-withdraw, shop-update and product delete on 55 live endpoints need no credentials. That is the one direction an auth resolver must never be wrong in.
- **The permission scope an endpoint requires, and which endpoints a seller-issued API key may call** — assigned to Developer, no surface yet. Ruled: belongs in the Developer Portal and resolves empty for all 537 endpoints. Verified in the current file: AuthResolver::permissions() matches only module: and can:, while the real gate on the seller API is seller_can: (53 route groups), and no controller anywhere passes ApiDoc(scopes:). On top of that, SellerApiAuthMiddleware refuses a key unless the route declares a scope — 232 of 248 seller endpoints accept a key and 16 refuse one — and that split is written down nowhere, so an integrator discovers it by getting a 403.

## SHIPPING

Backend: 17 capabilities
Admin: 14 of 17 covered
Seller Web: 9 of 17 covered
Flutter App: 9 of 16 covered
Analytics: 0 of 17 covered
Monitor: 4 of 17 covered
Dev Portal: 11 of 17 covered
Audit: Complete

Incomplete — the owner cannot reach it (3):

- **Registering a second courier — credentials, rates, labels and tracking per carrier** — assigned to Admin, no surface yet. Ruled: belongs in Admin as a carrier registry. Today carrier support is one hard-coded integration (Delivery Syria) plus flat and zone rates, with no carrier table in database/migrations, so onboarding a courier is a code change rather than a configuration.
- **Shipping and fulfilment as measured quantities — what shipping costs, which zone is expensive, dispatch time and lateness** — assigned to Admin, no surface yet. Ruled: belongs to Analytics, and it is the measurement gap with the sharpest consequence: FulfillmentService stamps packed and shipped timestamps on every fulfilment and nothing ever subtracts them, so a marketplace that enforces an SLA policy and suspends sellers for breaching it cannot measure lateness. The only shipping number recorded anywhere is shipping_cost inside an order_placed properties JSON blob that no rollup reads.
- **How long a shipment may go without courier movement before it is raised as an exception (72 hours)** — assigned to Admin, no surface yet. Ruled: belongs in Admin Settings, per carrier. Courier silence tolerance differs by carrier and country and there is no per-carrier or global setting anywhere.

