# Seller Center — Implementation Status

Required by PART 18 of the implementation brief: one place that says, per wave, what was built, what the
quality gates said about it, and what is still open. Updated at the end of each wave, not while it is in
flight.

- **Design source** — `design_handoff_seller_center/` (`README`, `01`–`14`, `assets/tokens.css`). The HTML
  prototype is a reference; none of its runtime was ported.
- **Parity source** — [`SELLER_WEB_APP_PARITY.md`](SELLER_WEB_APP_PARITY.md), 635 capabilities across 14
  domains, of which **195 are WEB MISSING** and form this programme's backlog.
- **Branch** — `claude/seller-center-app-update-979q0s` in both repositories.

## Where the work lives

**One panel, at `/vendor`.** The Seller Center's screens are *added to* the panel sellers already use —
they do not replace or remove anything that is there.

Every existing page is still at its own address, working as it works today: `/vendor/dashboard` is still the
classic dashboard, `/vendor/orders/list/all` is still the classic order list. The new screens take their own
`/vendor` addresses beside them (`/vendor/overview`, `/vendor/control-tower`, `/vendor/automation`, …), and
the two shells are bridged in both directions: the classic sidebar carries a **Seller Center** group built
from the same navigation registry the new rail reads, and the new navigation links straight back to the
classic pages. A screen that has not shipped is absent from both rather than dead in either.

The legacy panel is not re-themed. It carries 126 hard-coded colours outside its token block, and repainting
it in place would put every existing seller screen at risk for a visual gain. The new screens bring the
design; the old ones keep working exactly as they do now until a wave replaces one — and that replacement,
when it comes, is a decision taken per screen, not a side effect (PART 15).

## Wave ledger

| Wave | Scope | State | Commit |
|---|---|---|---|
| 1 | Foundation — tokens, shell, component library, table + filter system, status renderers, states, RTL | **Done** | `b969dd21` |
| 2 | Core seller operations — Home, Control Tower, Issue Center, Orders, Order detail, Products, Inventory, Movement ledger | **Done** | `e5125f33`, `53085acb` |
| 3 | Automation — rules, builder, history + undo, opportunities | **Done** (scheduled operations deferred, see below) | — |
| 4 | Fulfilment — returns, refunds, shipments, exceptions, picking, packing, warehouse, bulk jobs, action centre | **Done** | — |
| 5 | Finance — overview, transactions, payouts, statements, reconciliation, fees, pricing | **Done** | — |
| 6 | Trust — performance, account health, SLA, compliance, brand registry, brand protection, incidents, approvals | **Done** (cases and appeals are NOT BUILT, see below) | — |
| 7 | Enterprise — team, roles, security centre, integrations, API keys, webhooks, delivery health | **Done** | — |
| 8 | Platform — reports, exports (report builder, scheduled operations and advertising are NOT BUILT) | **Done** | — |

---

## Wave 1 — Foundation

**Built**

- `public/assets/seller-center/css/tokens.css` — the handoff's token file, dropped in unmodified so a design
  change arrives as a file replacement rather than a diff.
- `public/assets/seller-center/css/sc.css` — shell geometry, the 40 components of `04`, the one table system
  of `05` A, the one filter system of `05` B, the seven data states of `11`, the responsive rules of `10` A
  and the RTL mechanism of `10` B.
- `public/assets/seller-center/js/sc.js` — command palette (⌘K, `/`, ⌘1–9), drawers and modals with focus
  return, row selection with shift-range and the bulk bar, tooltips, SLA countdown, toasts. No framework;
  the prototype's `support.js` was not ported.
- `resources/views/layouts/seller/app.blade.php` + 6 partials, and **44 Blade components** under
  `resources/views/components/sc/`.
- Services: `Icons`, `Status`, `Navigation`, `Shell`, `TableFilters`, `Counts`, `Search` under
  `App\Services\SellerCenter`.
- `SellerCenterContext` middleware — resolves the web session into the **same `SellerPrincipal`** the API
  token produces, so `seller_can:` and the audit actor are one system across both clients (PART 5).
- `EnsureSellerPermission` now content-negotiates: JSON for the API, a real 403 screen for a browser.

**Gates** — a throwaway screen (`/seller/foundation`, debug-only) assembles a 10-column table, 4 saved views,
6 filter types, selection, bulk bar, density switch and all seven states from configuration alone, in both
directions, with no screen-specific CSS. 17 tests.

## Wave 2 — Core seller operations

**Built** — Seller Home, Control Tower, Issue Center + issue detail, Orders + order detail, Products,
Inventory overview, Movement ledger. Routes in `routes/seller/routes.php`.

**Decisions worth knowing**

- **One revenue definition.** `App\Services\SellerCenter\Revenue` holds it as a constant expression over
  delivered lines; the daily briefing and the home KPIs both read it. A second definition would have let the
  two screens disagree about the same number.
- **Issues carry their action.** `IssueAction` maps a detector's `action_key` and params to a real
  destination, carrying the affected ids into the target list as `?ids=`, so an issue's count and the list it
  opens always agree.
- **Sentences, not fragments.** `Copy::line()` / `choice()` / `duration()` / `sla()` translate whole
  sentences with `:placeholders`. `translate()` alone upper-cases every English result and takes no
  placeholders, which produced "1–10 Of 218" and word orders no Arabic translator could fix.
- **Detectors explain themselves.** Every producer writes the number and the cause, not a restated title.
- **`null` is not zero.** A missing comparison renders `—`; a zero renders no badge; an empty section is not
  rendered at all.

**Gates** — the exception loop works end to end: a Control Tower issue drills into a filtered list whose
count matches, the action resolves it, and the resolution shows in the entity timeline. Verified visually at
1440×950 in English LTR and Arabic RTL. 13 tests (32 across waves 1–2, 1800 in the suite, 0 failures).

**Copy** — 7,440 keys in `en`, `sy` and `sa`, seeded by `SellerCenterCopySeeder`. No production string is
hard-coded in a component.

## Wave 3 — Automation

**Built** — Automation rules list, rule builder, preview-matches, automation history with the run drawer and
Undo, Opportunities. Server work that the screens needed and did not have: rule scope, a field schema, and a
safety classification.

**Decisions worth knowing**

- **The form is derived from the validator.** `SettingField` reads each setting's Laravel rule and returns
  the control it needs — type, bounds, options. A trigger whose threshold gains an upper bound gets a bounded
  input the same day, in both clients, because there is only one statement of what is allowed.
- **A rule can be pointed at part of the catalogue.** `scope` (brands, categories, products) is new, nullable,
  and absent by default, so every rule that already exists keeps applying exactly as it did. Without it, "mark
  down anything that has not sold in ninety days" was a rule no sensible seller could switch on.
- **The safety class is the server's answer.** An action the signed-in person may not perform, or one whose
  changes cannot be put back, is marked *Cannot be automated* with the reason. There is deliberately no
  "needs confirmation" badge: nothing implements a confirmation step, and a badge promising one would lie.
- **`matched` is never called `applied`.** A capped run matched a great many things and changed none of
  them; the history says exactly that, in the row and in the drawer.
- **Undo restores only what the action owns.** A change somebody else has since overwritten is not offered as
  undoable — putting it back would overwrite their decision with one taken before it.
- **Opportunities are computed, never invented.** Three types, each from this shop's own data: fast sellers
  running out of cover, products viewed and not bought, listings under their category's median. A type with
  no data behind it does not render at all — a conversion rate with no views is not a low rate, it is no
  measurement (PART 14).

**Gates** — verified end to end in the browser: a rule created from the catalogue, previewed (1 match, no
change made), run (the listing hidden, the trail written), and undone (the listing restored, the trail
stamped). 22 tests for the wave, 54 across waves 1–3, 1822 in the suite, 0 failures.

**Flutter audit (PART 11)** — done, and it found drift. The app's rule form built its inputs from a bare
list of setting names, so it hard-coded `discount_type` as the one setting that is not a number and rendered
a free-text box for a value with exactly two legal answers. It now reads the same field description the web
reads, gained rule scope and the safety class, and refuses what the server would refuse. Both language files
still carry the same 1,836 keys. Commit `abee668` in `sillercenter-syria-cosmatics`.

**Not built, and why**

- **Scheduled operations (A4)** — there is no server for it. Scheduled price changes, timed activations and
  campaign starts do not exist as a domain, and a screen listing them would have nothing to list. Recorded as
  BACKEND MISSING; it needs its own wave, not a page.
- **The IF condition builder (A2)** — the rule model has no condition expressions, and a builder writing
  conditions nothing evaluates would be a form that silently does nothing. The practical half of it — limiting
  a rule to a brand, a category or a list of products — is what `scope` implements.
- **"Eligible for campaign" opportunity (A5)** — sellers cannot join a campaign from the panel, so the card
  would have no action behind it.

---

## Standing rules for every remaining wave

Taken from PART 21 and `13-implementation-priority.md`; a wave is not done until all of them hold.

1. Design implemented from the handoff, not approximated.
2. Real data only — no fake sales, issues, balances, scores, stock, payouts, brand claims or automation
   results (PART 14). A number with no source renders `—`.
3. No existing capability lost. Every touched action is classified PRESERVED / MOVED / IMPROVED / REPLACED /
   DEPRECATED (PART 15).
4. The wave's WEB MISSING rows from the parity matrix are closed, and the matrix row is marked closed here.
5. Permissions enforced server-side, not by hiding the control.
6. Arabic RTL and English both complete, with key parity.
7. Responsive at all five breakpoints; every screen ships its loading, empty, error and permission states.
8. Tests pass; the audit trail records what the system did on the seller's behalf, with undo where the server
   reports it revertible.
9. No new table, filter, badge or drawer pattern invented locally — a new pattern is a change to `04`/`05`.
10. After each web wave, the matching Flutter feature is audited against it (PART 11): same terminology,
    statuses, permissions, validation, calculations, thresholds and audit behaviour.

## Open

- Per-wave Flutter audits for waves 4–8 (PART 11), and the cross-client parity tests of PART 16 — a setting
  written from one client is visible in the other, a permission denied in one is denied in the other.

---

## Wave 4 — Fulfilment

**Screens** — `/vendor/returns` and its detail page, `/vendor/refunds`, `/vendor/shipments` with `/picking`,
`/packing` and `/exceptions`, `/vendor/warehouse`, `/vendor/bulk-jobs` and its receipt page, and
`/vendor/actions`.

Two list services carry the wave: `ReturnList` (views, summary buckets, the ledger lines a return produced)
and `FulfilmentList` (stages, the late test, dispatch time). Both were written against the services the v3
seller API already calls — `ReturnLogisticsService`, `FulfillmentService` — so the phone and the panel cannot
disagree about what a return is or when a fulfilment is late.

**Where the numbers come from.** Lateness is not a constant in a template: it reads
`app(Policy::class)->int('shipping_silent_hours')`, the same key `ShippingExceptionProducer` raises issues
from, so a marketplace that changes its threshold changes both at once. Dispatch time is null while a
fulfilment is still open rather than zero — an order that has not shipped has no dispatch time, and rendering
it as `0h` would read as instant.

**Refunds is read-only by design.** The decision belongs to the marketplace; showing the seller a button that
would be refused is worse than showing them the queue and where each request stands.

11 tests for the wave.

---

## Wave 5 — Finance

**Screens** — `/vendor/finance` and, under it, `transactions`, `statements`, `payouts`, `reconciliation` and
`fees`, plus `/vendor/pricing` and its history.

**The rule this wave exists to hold.** The buckets above the table are the WHOLE account and are not narrowed
by the filter under them. A seller reading last week still needs to know what they can withdraw today, and an
"available" figure that silently meant "available, of last week's entries" would be worse than no figure —
it is a number they would act on. Two tests hold it from both sides: the withdrawable figure ignores the
range, and the range totals follow it.

Everything reads `VendorLedger` and `SellerLedgerStatementService`, so the balance on the phone, the balance
on this screen and the balance the marketplace settles against are one number.

**Requesting a payout still happens on the classic page.** `/vendor/business-settings/payouts` has a working
form that reserves against the ledger atomically; a second form writing the same reservation is how a seller
ends up with two requests against one balance. The Seller Center links to it rather than duplicating it.

6 tests for the wave.

---

## Wave 6 — Trust

**Screens** — `/vendor/performance`, `/vendor/performance/health`, `/vendor/performance/sla`,
`/vendor/compliance`, `/vendor/brands`, `/vendor/brands/protection`, `/vendor/incidents` and
`/vendor/approvals`.

This wave renders a record the platform had already been keeping and no client had ever shown. SLA was
evaluated daily against every approved seller and wrote audited breaches; the seller saw a scorecard number
and never the standing, the breach, or the deadline they were being judged against.

**What each screen is careful about.**

- **Performance** prints the marketplace's ceiling beside each rate, so a number is a position rather than a
  statistic. A shop with no orders and no reviews is `new` — never good, never at risk: judging a seller who
  has not traded yet would be noise presented as a verdict. An unrated shop shows `—`, not zero stars.
- **Account health** is what the marketplace concludes and what it would take to change it — the record a
  suspension would have to rest on.
- **SLA** shows every line crossed and every line cleared, with the timestamps it opened and closed on. A
  cleared breach stays on the page: a record that only shows current problems cannot show improvement.
- **Compliance** reads verification, brand authorisation and open breaches together for the first time, and
  trends breaches by month over the last quarter. A count is a headline; a trend answers whether things are
  getting better.
- **Brand protection** counts what a revocation would cost in listings, from the seller's own catalogue, via
  `BrandRegistryService::brandExposure`. Not "you have an unclaimed brand" — "forty-one listings sit on it".
  Where enforcement is off, the screen says so and reframes the figure as what *would* be at risk.
- **Incidents** is not a second issue queue. The Issue Center is what is open now; this is what was left long
  enough that the platform promoted it. Escalation only climbs, and one step at a time, so a row here measures
  elapsed silence rather than severity.
- **Approvals** is read-only, deliberately: the approver is by definition not the requester. Its value is
  knowing a decision is queued and how far from released it is — `1 of 2`, not "in progress".

**Two pieces of shared logic rather than two controller queries.** `SellerInsight::scopeEscalated()` and
`ApprovalEngine::forSubjects()` exist so the v3 API gets the same answers when these reach the phone. The
approvals lookup resolves the shop's own payout requests first and asks the engine about those — narrower
than "every approval mentioning this shop", and narrower is correct: a marketplace-wide settlement approval
is not one seller's to read.

**Data honesty (PART 14).** Every one of these screens degrades to a stated reason rather than to an empty
table when its backing table is absent: "the brand registry is not running on this marketplace — nothing is
being withheld, there is no registry to read". A seller cannot tell a broken page from an empty one, and
saying which is the difference between a bug report and a fact.

10 tests for the wave, all three languages seeded (en / sy / sa).

**Navigation** — the rail resolved 10 of its 51 designed destinations when this work started and resolves 55
today. The 15 that remain are Wave 7 (team, roles, security, integrations), Wave 8 (reports, exports,
scheduled operations), and advertising, cases and appeals, which have no backend behind them and are recorded
as NOT BUILT rather than deferred.

---

## Wave 7 — Enterprise

**Screens** — `/vendor/team`, `/vendor/team/roles`, `/vendor/security`, `/vendor/integrations` with `api`,
`webhooks` and `health`.

**Team and roles read; they do not write.** The classic staff page is a working, audited set of forms, and a
second form writing the same role is how two people end up disagreeing about what `orders.manage` means. What
was missing was never the forms — it was a reading of them. The roles screen is a grid of role against
permission, because that is the only form in which "these two roles are the same role with different names"
is visible, and that is the finding an access review exists to produce. A role nobody holds is called out for
the same reason.

**Security answers who can act as this shop right now.** Read from the credentials themselves rather than
from a list of accounts: the owner holds a token, each staff member holds their own, and each live API key is
a third kind of door. Revoked and expired keys are left out on purpose — a key that cannot act is not an
answer to who can. The trail beneath is filtered by area because that is how it is actually read: somebody
asks who changed the automation rules, never "show me everything". Somebody who has since left still appears
in the history of what they did, which is exactly when a seller wants to look.

**Integrations existed only on the phone.** A seller wiring up an ERP was told to install the app, mint a key
on a handset and copy it across, and there was no screen anywhere that would show them why their endpoint had
stopped receiving orders. Integration work happens at a desk. `/vendor/integrations/api` issues, lists and
revokes keys — scopes narrowed to what the issuer holds, the key shown once and never again.
`/vendor/integrations/webhooks` adds, repoints, pauses, tests and removes endpoints, with the signing secret
shown once. `/vendor/integrations/health` is every delivery attempt, kept whether it worked or not: a seller
whose integration is quietly missing every third order needs to see the third one, and a failure counter on
the endpoint cannot show them which.

**One rule stayed in the controller rather than the service, because it is about who is asking:** a key
cannot manage keys. An integration that leaks should cost the seller what that integration could do; if a
leaked key could mint another or delete the webhook that would have reported it, the limit on its scopes
would be decorative.

**Everything else moved into `SellerIntegrationService`,** which the v3 API now calls too. Writing a second
client is exactly the moment the https-only rule, the destination check and what a repoint resets stop being
one controller's private business: two implementations of "which destinations may this platform be made to
dial" is one implementation and one hole.

**The extraction found a live defect.** `setWebhookStatus` carried `consecutive_failures` through from the
model, and that attribute is null on a model that has not been refreshed since its insert — so pausing an
endpoint created moments earlier wrote a null into a NOT NULL column. It was in the shipped API path.
Casting fixes it; the test that caught it asserts the pause, not the cast.

12 tests for the wave, all three languages seeded. The rail now resolves 62 of its designed destinations.

---

## Wave 8 — Platform

**Screens** — `/vendor/reports` with `orders`, `products` and `stock`, and `/vendor/exports`.

**One period, chosen once.** The figures are not new — `SellerReportService` has computed them for the
classic panel and the phone since Phase 4. What is new is that all three reports and every download sit
behind a single period. The classic panel scattered them across three menus with three independent date
pickers, which is how a seller ends up comparing March's orders against the year's products and drawing a
conclusion from it.

**Stock deliberately has no period.** A stock level is a fact about now; asking what it was in March would
need the movement ledger replayed backwards, and printing today's figure under a March heading would be
false. The screen says so rather than offering a picker that quietly does nothing.

**An export is not a lower bar than the list it exports.** A spreadsheet is the whole list in one file, so a
download gated more loosely than the screen it comes from is the permission model with a side door. Each
export route declares the same permission as its report, and the wave's test reads that out of the route
table rather than from a list written in the test — a route added next year without a permission fails it.

Nothing is queued and nothing is stored: a generated file left on the server is a copy of a shop's
commercial data sitting where nobody is watching. The exports stream and are gone. They are produced by the
same exporter classes the classic panel and the app use, so a spreadsheet downloaded from the panel and one
downloaded from the phone are the same spreadsheet rather than two renderings that agree today.

7 tests for the wave. The route-collision suite also grew: it now pins all 19 Seller Center screens from
waves 4–8 to their own controllers, because every one of them was added to a panel whose classic routes are
registered first — a path that stops resolving to its own controller has been shadowed, and the seller sees
the old screen with no sign anything is wrong.

**Not built, and why**

- **Report builder (`seller.reports.builder`)** — there is no table for a saved report definition and no
  service that would run one. A builder whose definitions nothing stores is a form that forgets. Recorded
  NOT BUILT on the same reasoning as the Wave 3 condition builder: the practical half of it — choose the
  report, the period and the search, then read it or download it — is what the reports screens are.
- **Scheduled operations (`seller.pricing.scheduled`, `seller.automation.scheduled`)** — confirmed again:
  timed price changes, timed activations and campaign starts do not exist as a domain, so the screens would
  have nothing to list. Unchanged from the Wave 3 ruling.
- **Advertising (`seller.advertising.index`)** — no ad slots, no budgets, no billing. A screen there would
  be a menu entry promising a product that does not exist.
- **Cases and appeals** — no backend; a channel for contesting a rejection or a suspension would be a new
  product, not a missing page.

The rail resolves 64 of its 51 designed destinations plus the sub-screens each wave added, and the 6 that
remain are exactly the four rulings above. Every one of them is a documented product gap rather than an
unrouted link, and `Route::has()` keeps them out of the menu until they exist.
