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

Incomplete — the owner cannot reach it (1):

- **Daily history of request volume, visitors, errors and API load (telemetry_daily)** — owned by an integrator, with nothing in the Developer Portal. Ruled: belongs to Developer to either surface or stop writing, and it is surfaced. Three scheduled runs a day maintained the table and the command's own header admitted no screen had read it since Analytics moved to analytics_daily — a quarter of the telemetry scheduler's budget producing output nobody could look at. Surfaced rather than stopped, because it turned out to be the only place this platform keeps request volume, visitors and errors beyond monitoring's own retention window: everything else on the Requests page is folded from monitoring_request_buckets, which is pruned, so 'were we slower in June' could not be asked of it at all. Ninety days, web and API side by side, newest first. The command's header now says which screen reads it, so the next person does not have to work it out from a grep.

## AUTOMATION

Backend: 24 capabilities
Admin: 21 of 24 covered
Seller Web: 14 of 24 covered
Flutter App: 14 of 17 covered
Analytics: 6 of 24 covered
Monitor: 8 of 24 covered
Dev Portal: 13 of 19 covered
Audit: Complete

Every capability in this domain is reachable by the surface that owns it.

## BRANDS

Backend: 11 capabilities
Admin: Complete
Seller Web: 3 of 11 covered
Flutter App: 8 of 11 covered
Analytics: 1 of 11 covered
Monitor: 0 of 11 covered
Dev Portal: 10 of 11 covered
Audit: Complete

Every capability in this domain is reachable by the surface that owns it.

## CATALOG

Backend: 31 capabilities
Admin: 28 of 31 covered
Seller Web: 13 of 31 covered
Flutter App: 14 of 27 covered
Analytics: 14 of 31 covered
Monitor: 4 of 31 covered
Dev Portal: 19 of 30 covered
Audit: Complete

Every capability in this domain is reachable by the surface that owns it.

## COMPLIANCE

Backend: 22 capabilities
Admin: 21 of 22 covered
Seller Web: 13 of 22 covered
Flutter App: 13 of 19 covered
Analytics: 2 of 22 covered
Monitor: 4 of 22 covered
Dev Portal: 13 of 21 covered
Audit: Complete

Every capability in this domain is reachable by the surface that owns it.

## FINANCE

Backend: 57 capabilities
Admin: 53 of 57 covered
Seller Web: 29 of 57 covered
Flutter App: 37 of 49 covered
Analytics: 14 of 57 covered
Monitor: 10 of 57 covered
Dev Portal: 40 of 52 covered
Audit: Complete

Incomplete — the owner cannot reach it (1):

- **Why a payment failed — gateway latency, failure reason, and whether the callback ever arrived** — owned by Admin, with no admin surface. Ruled: belongs to Monitoring. Four of the five declared gaps are closed and the fifth is stated rather than hidden. The sharpest was the callback receipt: nothing recorded a gateway callback at all, so a callback that never arrived and one that arrived and was rejected were the same absent row, and a payment outage showed up only as orders that quietly stopped appearing. Receipts now distinguish four cases — succeeded, failed, arrived-and-acted-on-by-nothing, and never-arrived, which is the one with no row — because each has a different fix. Two choke points did the work of fourteen edits: generate_link() for the attempt and payment_response() for the outcome, so no gateway controller was touched and the fifteenth is covered when somebody adds it. The middleware is global rather than bolted to the payment route group, because those routes only exist while the Gateways addon is unpublished and a group-scoped middleware would cover nothing on the installations that take the most money. finalized_at is written once, so a gateway that repeats a callback three times does not make one payment look like three. The ledger now records why a payment failed rather than only is_paid, and payment_requests.order_id gives the reconciliations the exact join they never had — attribute_id holds a unix timestamp, leaving a nullable varchar(30)-to-varchar(100) match as the only option. STILL OPEN and stated on the page: gateway_latency. Only one of the fourteen controllers goes out through Laravel's HTTP client; nine use raw curl_exec, three drive a vendor SDK and one makes no outbound call at all, so each needs instrumenting separately and wrapping an SDK is not the same job as wrapping curl.

## INTEGRATIONS

Backend: 46 capabilities
Admin: 39 of 46 covered
Seller Web: 7 of 46 covered
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

Incomplete — the owner cannot reach it (1):

- **Suppliers — the vendors the marketplace itself buys from** — owned by Admin, with no admin surface. Create-only pattern; changing a supplier's bank or contact details is unrecorded.

## MONITORING

Backend: 93 capabilities
Admin: 89 of 93 covered
Seller Web: 4 of 93 covered
Flutter App: 5 of 12 covered
Analytics: 5 of 40 covered
Monitor: 61 of 91 covered
Dev Portal: 16 of 51 covered
Audit: Complete

Incomplete — the owner cannot reach it (3):

- **Exception capture — grouped exceptions with stack traces, occurrence counts, affected users, and marking one resolved** — owned by an integrator, with nothing in the Developer Portal. Ruled: belongs to Developer, and it was the single largest hole in the platform — two tables with eight readers and no writer, because bootstrap/app.php's withExceptions() was an empty stub. Closed by ExceptionRecorder on the framework's own reporting chain, so HTTP requests, queued jobs and scheduled commands all feed it from one registration. Grouped by exception class + message with its variable parts normalised + topmost application frame, so one bug is one group however many customers hit it; a group marked resolved that fires again re-opens and its resolved_at is cleared, because a regression that stays silent is exactly what the table exists to prevent; affected_users counts a signed-in person once. Ordinary traffic — a login prompt, a validation failure, a 404, any deliberate 4xx — is not recorded as a fault. Messages, traces, URLs and IPs pass the Redactor before storage, and the request payload is stored as key names only. The whole recorder is inside a catch that gives up silently: an error the console did not see is a bad day, an error handler that throws is an outage. Held by tests/Feature/Monitoring/ExceptionCaptureTest.php, including a case that goes through the application's exception handler so deleting the bootstrap registration fails the suite.
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

Every capability in this domain is reachable by the surface that owns it.

## ORDERS

Backend: 26 capabilities
Admin: 24 of 26 covered
Seller Web: 19 of 26 covered
Flutter App: 21 of 25 covered
Analytics: 8 of 25 covered
Monitor: 6 of 26 covered
Dev Portal: 22 of 26 covered
Audit: Complete

Every capability in this domain is reachable by the surface that owns it.

## PLATFORM

Backend: 122 capabilities
Admin: 94 of 122 covered
Seller Web: 21 of 122 covered
Flutter App: 24 of 66 covered
Analytics: 17 of 119 covered
Monitor: 19 of 122 covered
Dev Portal: 83 of 108 covered
Audit: Complete

Incomplete — the owner cannot reach it (1):

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

- **Mass product updates written through the query builder bypass the price observer** — owned by an integrator, with nothing in the Developer Portal. Ruled: belongs to Developer as a latent hole, and it is closed rather than documented. A builder update fires no model events, which is exactly what a bulk stock or variation write wants and is also how a price written through this method would skip the price-change history, the audit row and the seller-visible price history in one step — silently, on a path nobody would think to check. The fast path stays the default and a write that touches a watched price column is routed through model instances instead, so today's callers are unchanged and the day somebody adds a price to this call the history follows it. Held by a test that fails if the guard is removed.

## RETURNS

Backend: 7 capabilities
Admin: Complete
Seller Web: 6 of 7 covered
Flutter App: Complete
Analytics: 2 of 7 covered
Monitor: 0 of 7 covered
Dev Portal: 6 of 7 covered
Audit: Complete

Incomplete — the owner cannot reach it (1):

- **Returns and refunds as measured quantities — return rate by reason, time to receive, restock rate, refund volume, value and time to settle** — assigned to Admin, no surface yet. Ruled: belongs to Analytics. No event is raised when a refund request is created or approved, and the RMA state machine writes nothing at all, so the platform has two half-measurements that cannot be joined: a rate derived from order_status on the scorecard, and an event named refund_requested that actually fires on an order status change.

## SECURITY

Backend: 47 capabilities
Admin: 41 of 47 covered
Seller Web: 9 of 47 covered
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

Incomplete — the owner cannot reach it (1):

- **Shipping and fulfilment as measured quantities — what shipping costs, which zone is expensive, dispatch time and lateness** — assigned to Admin, no surface yet. Ruled: belongs to Analytics, and it is the measurement gap with the sharpest consequence: FulfillmentService stamps packed and shipped timestamps on every fulfilment and nothing ever subtracts them, so a marketplace that enforces an SLA policy and suspends sellers for breaching it cannot measure lateness. The only shipping number recorded anywhere is shipping_cost inside an order_placed properties JSON blob that no rollup reads.

