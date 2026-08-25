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
Admin: 36 of 47 covered
Seller Web: 10 of 47 covered
Flutter App: 9 of 28 covered
Analytics: 40 of 47 covered
Monitor: 3 of 47 covered
Dev Portal: 19 of 45 covered
Audit: Complete

Incomplete — the owner cannot reach it (4):

- **Inventory as a measured quantity — stock-out frequency, how long stock sat at zero, sell-through** — assigned to Admin, no surface yet. Ruled: belongs to Analytics. Stock can be listed, adjusted, transferred and written off on every surface, and nothing counts any of it — product_metrics carries views and cart adds and has no stock column, so the cost of a stock-out is unanswerable on a platform whose whole job is stock.
- **Seller report builder, saved report definitions and an exports centre** — assigned to Seller, no surface yet. OVERRULED in part — the seller sweep recorded reports and exports as having no backend. Verified: GET seller-center/reports/{orders,products,stock} and three export endpoints do exist on the v3 API and are used by the Flutter app. What is missing is the web surface and the saved-definition/queued-export half: seller.reports.index, seller.reports.builder and seller.exports.index have no route, so on a browser every export is a synchronous download off one specific list.
- **The extra facts attached to each event — payment method, coupon code, shipping cost, guest flag, failure reason** — assigned to Admin, no surface yet. Ruled: belongs to Analytics reporting. Every order writes them into analytics_events.properties and exactly one reader exists in the codebase (ExperimentReach pulling properties->experiment), so shipping cost, coupon and payment method per order are captured on every order and reportable on none.
- **Daily history of request volume, visitors, errors and API load (telemetry_daily)** — assigned to Developer, no surface yet. Ruled: belongs to Developer to either surface or stop writing. Three scheduled runs a day maintain the table and the command's own header admits no screen reads it since Analytics moved to analytics_daily — it survives the raw-row prune as retention, so a quarter of the telemetry scheduler budget produces output nobody can look at.

## AUTOMATION

Backend: 24 capabilities
Admin: 21 of 24 covered
Seller Web: 14 of 24 covered
Flutter App: 14 of 17 covered
Analytics: 6 of 24 covered
Monitor: 8 of 24 covered
Dev Portal: 13 of 19 covered
Audit: Complete

Incomplete — the owner cannot reach it (1):

- **Scheduled operations — timed price changes, timed activations, campaign starts** — assigned to Seller, no surface yet. Ruled: belongs in the Seller Center and has no backend at all — no route, controller, table or command; two navigation destinations (seller.pricing.scheduled, seller.automation.scheduled) name a server that was never built and are filtered out of the rail.

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
Admin: 27 of 31 covered
Seller Web: 13 of 31 covered
Flutter App: 14 of 27 covered
Analytics: 14 of 31 covered
Monitor: 4 of 31 covered
Dev Portal: 19 of 30 covered
Audit: Complete

Incomplete — the owner cannot reach it (2):

- **Approve or deny a seller's listing from the classic product screen** — assigned to Admin, no surface yet. Ruled: belongs to the audited moderation path that already exists. ProductModerationService records every decision with reason codes and history; ProductController::approveStatus/deny writes nothing — and the sidebar sends operators to the unaudited one, so whether a listing decision is recorded depends on which screen the operator happened to open.
- **Keeping the storefront product search index in step with the catalogue, and rebuilding it when it drifts** — assigned to Admin, no surface yet. Ruled: belongs on an Admin catalogue page as an index-health readout with a rebuild action. The observer swallows every exception so a product save can never fail, which means a broken index write is invisible, and the weekly reconcile command has no admin surface either — so an import that bypassed the observer leaves storefront search quietly incomplete with no way to notice or repair it from the panel.

## COMPLIANCE

Backend: 22 capabilities
Admin: 21 of 22 covered
Seller Web: 11 of 22 covered
Flutter App: 13 of 19 covered
Analytics: 2 of 22 covered
Monitor: 4 of 22 covered
Dev Portal: 13 of 21 covered
Audit: Complete

Incomplete — the owner cannot reach it (3):

- **Disputes and appeals — a channel for a seller to contest a rejection, a suspension, a brand revocation or a chargeback** — assigned to Admin, no surface yet. Ruled: belongs to both panels and exists in neither. Searched app/, Modules/, routes/ and database/migrations for dispute|appeal|case: no controller, no table, no route — only prose in two service files and three dead nav entries (seller.cases.index, seller.incidents.index, seller.appeals.index). The panel can suspend a shop, deny a listing and revoke a brand claim, and the seller's only channel is a support ticket that carries no link to the decision it contests, so nobody can see how many decisions are being challenged.
- **The seller's own account health and SLA standing** — assigned to Seller, no surface yet. Ruled: belongs in the Seller Center. The platform evaluates every approved seller against SLA policy daily and writes audited breaches, and no client renders account health — the seller sees a scorecard number and never the standing, the breach, or the deadline they are being judged against.
- **Compliance as a measured quantity — unauthorised brand listings, verification standing, policy breaches over time** — assigned to Seller, no surface yet. Ruled: belongs on the Seller Center compliance page, which does not exist. Counts.php already computes a compliance_action badge for that missing page, so the platform renders a number on a menu pointing at nothing, and no breach, verification or brand-claim figure is trended anywhere.

## FINANCE

Backend: 57 capabilities
Admin: 53 of 57 covered
Seller Web: 29 of 57 covered
Flutter App: 37 of 49 covered
Analytics: 14 of 57 covered
Monitor: 8 of 57 covered
Dev Portal: 40 of 52 covered
Audit: Complete

Incomplete — the owner cannot reach it (2):

- **Why a payment failed — gateway latency, failure reason, and whether the callback ever arrived** — assigned to Admin, no surface yet. Ruled: belongs to Monitoring. No gateway callback leaves a receipt anywhere (PaymentsPanel.php:2056), so a callback that never arrived and one that arrived and failed are the same absent row, and a payment outage is visible only as orders that stopped appearing.
- **Alerting on payout and settlement failure — duplicate settlements, paid orders with no settlement row, commission mismatches** — assigned to Admin, no surface yet. Ruled: belongs to Monitoring. PaymentsPanel really does detect these money-losing conditions, but computes them live on page load and publishes no series, so MetricResolver cannot see them and no rule can be written — a seller who is silently never paid is found only if an admin happens to open the section.

## INTEGRATIONS

Backend: 46 capabilities
Admin: 39 of 46 covered
Seller Web: 5 of 46 covered
Flutter App: 16 of 31 covered
Analytics: 3 of 44 covered
Monitor: 21 of 46 covered
Dev Portal: 36 of 42 covered
Audit: Complete

Every capability in this domain is reachable by the surface that owns it.

## INVENTORY

Backend: 24 capabilities
Admin: 21 of 24 covered
Seller Web: 13 of 24 covered
Flutter App: 19 of 23 covered
Analytics: 0 of 23 covered
Monitor: 3 of 24 covered
Dev Portal: 19 of 24 covered
Audit: Complete

Incomplete — the owner cannot reach it (2):

- **Quick stock edit from the classic product list — sets current_stock directly, with no reason, no movement row and no audit line** — assigned to Admin, no surface yet. Ruled: belongs to Developer to route through InventoryService, which already writes a reason code, a movement ledger row and an audit line to the same column. Two stock-writing paths that disagree about whether a change is traceable will drive current_stock and the movement ledger apart, and the trail cannot say why.
- **Suppliers — the vendors the marketplace itself buys from** — owned by Admin, with no admin surface. Create-only pattern; changing a supplier's bank or contact details is unrecorded.

## MONITORING

Backend: 93 capabilities
Admin: 87 of 93 covered
Seller Web: 4 of 93 covered
Flutter App: 4 of 12 covered
Analytics: 5 of 40 covered
Monitor: 57 of 91 covered
Dev Portal: 14 of 51 covered
Audit: Complete

Incomplete — the owner cannot reach it (9):

- **Exception capture — grouped exceptions with stack traces, occurrence counts, affected users, and marking one resolved** — assigned to Developer, no surface yet. Ruled: belongs to Developer, and it is the single largest hole in the platform. monitoring_error_groups and monitoring_errors are created, read by eight panels and two services and pruned by the rollup, and verified here: the only reference to the table outside readers is the migration itself, because bootstrap/app.php:249 withExceptions() is empty. The Errors page is permanently blank, the health score's error signal permanently unmeasured, and Security's authorisation-failure card, both crash-free cards, the portal's endpoint error lookup and the deploy before/after comparison are all structurally zero — the only error visibility left in the product is the HTTP 5xx rate.
- **Machine-readable JSON feed of every monitoring section** — assigned to Developer, no surface yet. Ruled: belongs in the Developer Portal. Every section returns its full payload as JSON on the same URL with ?json=1 — a complete monitoring API behind admin session auth — and it appears in no portal screen, no OpenAPI export and no Postman collection.
- **Prometheus scrape endpoint and OTLP trace export** — assigned to Developer, no surface yet. Ruled: belongs to Developer to build or to delete the config. Verified: no route matching monitoring/metrics exists anywhere in routes/, and no OTLP exporter or job exists, yet config/monitoring.php documents both and two panels display the Prometheus endpoint as a live setting complete with a security warning about an exposure that cannot happen.
- **Blast radius — how many sellers a failure is affecting** — assigned to Admin, no surface yet. Ruled: belongs to Monitoring as a dimension on every signal. No monitoring table or panel carries a seller, vendor or shop_id — 'vendor' exists only as a request-channel label — so the console can say the queue is backed up or orders are stuck and never whether that is one seller or all of them, which on a marketplace is the first question asked and turns every triage into a manual SQL session.
- **Mobile app health ingest — self-reported sessions, crashes and ANRs from the phone apps** — assigned to Developer, no surface yet. Ruled: belongs to the Flutter app. POST api/v1/app-health exists, is rate-limited, is documented and writes the app.health.* series the Android and iOS panels read — and a grep of the entire seller app finds no caller, so both mobile sections report crash-free sessions as not_configured, which is the one thing about a phone app the server cannot infer.
- **Seeing which scheduled tasks are defined, and when each runs next** — assigned to Admin, no surface yet. Ruled: belongs on the Admin Scheduler page, which cannot serve it as built. Laravel registers the schedule through Artisan::starting(), so a web request resolves an empty Schedule; the page therefore cannot name the tasks that should run, withholds next-due times and the healthy/late/missed counts, and tells the operator to run php artisan schedule:list — i.e. SSH — instead.
- **Retrying, forgetting or flushing a failed queue job** — assigned to Admin, no surface yet. Ruled: belongs in Admin → Monitoring → Queues, which already reads failed_jobs and shows the ten most recent failures. Monitoring is registered GET-only, so a day of undelivered order confirmations, seller webhooks or bulk price changes can only be re-driven with php artisan queue:retry over SSH.
- **Request debugger — look up an X-Request-Id and see what happened** — owned by Admin, with no admin surface. Ruled: belongs in the Developer Portal or Monitoring, and the advice already points at it: the Errors section tells developers to keep the X-Request-Id because it is what makes a failure findable, Monitoring records request_id in its errors and logs panels, and there is no lookup-by-id screen anywhere. RESOLVED. The lookup is exact on purpose — a debugger that widened to 'around that time' would return another request's stack trace with the confidence of an exact match — and an id with no rows says which of the two reasons applies rather than 'not found'.
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
Admin: 23 of 26 covered
Seller Web: 19 of 26 covered
Flutter App: 21 of 25 covered
Analytics: 8 of 25 covered
Monitor: 6 of 26 covered
Dev Portal: 22 of 26 covered
Audit: Complete

Incomplete — the owner cannot reach it (3):

- **vendor/get-order-data — an authenticated seller endpoint returning order data that nothing calls** — assigned to Developer, no surface yet. Ruled: belongs to Developer to delete or document. It is either dead code or an undocumented integration point on order data, and the difference matters because it is reachable with a seller session.
- **Which order states remain editable, and which remain cancellable** — assigned to Admin, no surface yet. Ruled: belongs in Admin → Order settings, which already exists. Both rules are inline status arrays repeated across at least three files, so a marketplace that wants cancellation to stop at 'processing' has to be given a code change.
- **Minimum number of items required before a customer may check out** — assigned to Admin, no surface yet. Ruled: belongs on the Admin order-settings screen that already carries the minimum order amount. It is seeded at install, read in exactly one place, shipped to all three mobile apps in /api/v1/config, and written by nothing — so the apps enforce a checkout rule the operator cannot see or change.

## PLATFORM

Backend: 122 capabilities
Admin: 93 of 122 covered
Seller Web: 20 of 122 covered
Flutter App: 24 of 66 covered
Analytics: 17 of 119 covered
Monitor: 19 of 122 covered
Dev Portal: 83 of 108 covered
Audit: Complete

Incomplete — the owner cannot reach it (4):

- **Seller Center navigation registry — 41 of its 51 designed destinations resolve to no route and are silently dropped from the rail** — assigned to Seller, no surface yet. Ruled: belongs to the Seller Center web panel. The registry is the design of record and the route table is one fifth of it, so a seller sees a menu that silently omits every capability the phone app already has; the drop is invisible from inside the product because a missing route removes the item rather than erroring.
- **Paid advertising and sponsored placement — ad slots, budgets, billing** — assigned to Admin, no surface yet. Ruled: belongs in Admin and has no backend at all. Searched app/, Modules/, routes/ and database/migrations for advertis*/sponsored/ad_campaign: only BannerService and unrelated substring hits. The marketplace can place its own banners and run merchandising overlays but cannot sell placement to a seller, price it, cap it or bill for it — a revenue line with nothing behind it, and the Seller Center advertises a destination for it that does not resolve.
- **Feature flags and gradual rollout** — assigned to Admin, no surface yet. Ruled: belongs in Admin. No flag table, no config, no per-seller or per-percentage switch anywhere; the only lever is publishing or unpublishing an entire addon module, so every change to the marketplace is all-or-nothing for everyone at once.
- **Deployments — which build started running when, with migrations run and errors before and after** — owned by an integrator, with nothing in the Developer Portal. Empty until the deploy script calls the command — deploy.sh does not — and its error comparison reads monitoring_errors, which has no writer, so the before/after error counts are structurally zero.

## PRICING

Backend: 15 capabilities
Admin: Complete
Seller Web: 12 of 15 covered
Flutter App: 13 of 15 covered
Analytics: 4 of 15 covered
Monitor: 0 of 15 covered
Dev Portal: 13 of 15 covered
Audit: Complete

Incomplete — the owner cannot reach it (1):

- **Mass product updates written through the query builder bypass the price observer** — assigned to Developer, no surface yet. Ruled: belongs to Developer as a latent hole. ProductRepository::updateByParams issues a builder update, which fires no model events, so any future price write through it would skip the price-change history, the audit row and the seller-visible price history in one step; today's callers touch stock and variations only.

## RETURNS

Backend: 7 capabilities
Admin: Complete
Seller Web: 6 of 7 covered
Flutter App: Complete
Analytics: 2 of 7 covered
Monitor: 0 of 7 covered
Dev Portal: 6 of 7 covered
Audit: Complete

Incomplete — the owner cannot reach it (2):

- **Returns and refunds as measured quantities — return rate by reason, time to receive, restock rate, refund volume, value and time to settle** — assigned to Admin, no surface yet. Ruled: belongs to Analytics. No event is raised when a refund request is created or approved, and the RMA state machine writes nothing at all, so the platform has two half-measurements that cannot be joined: a rate derived from order_status on the scorecard, and an event named refund_requested that actually fires on an order status change.
- **Approve or reject a customer refund** — assigned to Admin, no surface yet. Ruled: belongs on the unified audit trail. Approval debits the seller's earnings, reverses the marketplace's commission and moves customer money, and writes only to its own refund_status history — so the audit centre cannot answer who approved any refund ever processed.

## SECURITY

Backend: 47 capabilities
Admin: 41 of 47 covered
Seller Web: 8 of 47 covered
Flutter App: 20 of 31 covered
Analytics: 3 of 45 covered
Monitor: 11 of 47 covered
Dev Portal: 30 of 42 covered
Audit: Complete

Every capability in this domain is reachable by the surface that owns it.

## SHIPPING

Backend: 17 capabilities
Admin: 15 of 17 covered
Seller Web: 9 of 17 covered
Flutter App: 9 of 16 covered
Analytics: 0 of 17 covered
Monitor: 4 of 17 covered
Dev Portal: 11 of 17 covered
Audit: Complete

Incomplete — the owner cannot reach it (2):

- **Registering a second courier — credentials, rates, labels and tracking per carrier** — assigned to Admin, no surface yet. Ruled: belongs in Admin as a carrier registry. Today carrier support is one hard-coded integration (Delivery Syria) plus flat and zone rates, with no carrier table in database/migrations, so onboarding a courier is a code change rather than a configuration.
- **Shipping and fulfilment as measured quantities — what shipping costs, which zone is expensive, dispatch time and lateness** — assigned to Admin, no surface yet. Ruled: belongs to Analytics, and it is the measurement gap with the sharpest consequence: FulfillmentService stamps packed and shipped timestamps on every fulfilment and nothing ever subtracts them, so a marketplace that enforces an SLA policy and suspends sellers for breaching it cannot measure lateness. The only shipping number recorded anywhere is shipping_cost inside an order_placed properties JSON blob that no rollup reads.

