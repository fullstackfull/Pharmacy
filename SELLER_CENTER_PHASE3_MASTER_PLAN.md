# Seller Center — Phase 3 Master Plan

**Autonomous operations, control, automation and brand protection.**

Phase 1 built the seller's tools. Phase 2 made them honest — real figures, real receipts, real
history. Phase 3 changes who does the looking: today a seller finds problems by opening screens and
checking. The platform should find them first.

This document is the architectural source of truth for Phase 3. It is written after reading the
existing implementation, not before — every EXISTING claim below names the file it is about.

---

## 0. What is already here

The audit found substantially more standing infrastructure than a greenfield reading of the Phase 3
brief would assume. That changes the shape of the work: most of Phase 3 is **wiring domains into
layers that already exist**, not building those layers.

| Capability | Where it already lives |
|---|---|
| Insight store + producer contract + hourly refresh | `app/Services/SellerIntelligence/**`, `seller_insights` |
| Action Center (seller-facing list, severity, dismissal) | `SellerActionCenterController`, app `features/action_center` |
| SLA policy + deadline + breach ledger | `SlaService`, `seller_sla_breaches`, admin SLA policy page |
| Seller scorecard + tiers | `SellerScorecardService` |
| Maker–checker approvals | `ApprovalEngine`, `approval_requests`, `approval_decisions` |
| Unified audit trail | `AuditLogger`, `audit_logs` |
| Platform alert rules with thresholds/cooldown/recovery | `monitoring_alert_rules`, `monitoring_alert_states` |
| Platform incidents with probable cause + evidence | `monitoring_incidents` |
| Order self-contradiction checks | `app/Services/Monitoring/Panels/OrderIntegrityPanel.php` |
| Financial reconciliation (5 checks) | `ReconciliationService` |
| Per-line commission snapshot + rules | `CommissionEngine`, `commission_rules`, `order_item_commissions` |
| Vendor ledger with idempotency + settlement linkage | `VendorLedger`, `vendor_ledger_entries` |
| Stock ledger with locked adjustments | `InventoryService`, `stock_movements` |
| Returns logistics (RMA lifecycle + restock) | `ReturnLogisticsService`, `return_shipments` |
| Bulk jobs with per-row failure receipts | `SellerBulkJobService`, `seller_bulk_jobs` |
| Seller RBAC (shop vs person, per-route gates) | `SellerPrincipal`, `EnsureSellerPermission`, `seller_roles`/`seller_staff` |
| Domain events + listeners | `app/Events/**` (23 pairs) — all transactional mail/push; none operational |
| Maker–checker engine | `ApprovalEngine` — adopted by exactly one caller (`PayoutService`) |
| Seller KYC with document expiry enforced | `SellerVerificationService`, `seller_verification_documents` |
| Product moderation with structured reasons | `ProductModerationService`, `product_moderation_events` |
| Category governance (return window, required attributes, moderation) | `CategoryGovernanceService`, `category_governance` |
| Support tickets | `support_tickets` — customer↔admin only, no seller side |
| Per-seller funnel telemetry | `analytics_daily` with a `vendor` dimension, `analytics_events.vendor_id` |
| Carrier integration + inbound webhook | `app/Services/DeliverySyria/**`, `DeliverySyriaWebhookController`, `delivery_syria_parcels` |
| Warehouse + batch services | `WarehouseService`, `BatchService`, `warehouses`, `warehouse_stock`, `product_batches` |

206 seller API routes, 1,408 backend tests, 95 app screens as of this plan.

Two things the audit makes plain, and they shape everything below:

**The two mature detection layers do not know about each other.** `seller_insights` (business,
seller-facing, hourly) and `monitoring_alert_rules` (infrastructure, operator-facing, every minute)
were built independently. The monitoring one has the harder-won semantics — no-data is not zero,
`for_seconds` requires *every* sample in the window to breach, the recovery threshold sits strictly
inside the firing threshold so an alert cannot flap, and cooldown suppresses the *notification*
without resetting the *state*. Phase 3 reuses those semantics for business rules rather than
rediscovering them.

**Neither layer can reach a seller.** The notification stack is real and complete — FCM HTTP v1,
mail, in-app, per-device and topic sends — and nothing in `SellerIntelligence/**` or `SlaService`
ever calls it. An SLA breach today writes a row and tells nobody. That is the single largest gap
between what the platform knows and what a seller finds out.

---

## 1. Scope restrictions, honoured

The brief forbids AI/LLM, B2B/wholesale/RFQ, and multi-country. Recorded as decisions so nobody
re-opens them later:

- **No AI, anywhere in Phase 3.** Everything below is thresholds, moving averages, ratios and
  deterministic rules. Where this plan says "detect" it means a documented arithmetic comparison
  against real rows.
- **Phase 2's planned assistant (H) is cancelled**, not deferred. The new brief forbids it.
- **B2B stays where it is.** `B2BPricingService` exists and is wired into `CartManager`; it is left
  untouched and unextended. No new B2B surface.
- **Single market.** No country dimension is added to any new table. `DeliverySyria` remains the one
  carrier integration and the shipping layer is built to accept a second, not a second country.

---

## 2. The layer Phase 3 adds

```
domain events + scheduled sweeps
        │
        ▼
   Detectors            one per problem, each answering a single question
        │
        ▼
   Issue store          extends seller_insights — one row per (seller, type, entity)
        │
        ▼
   Severity engine      documented arithmetic, not a score anyone invented
        │
        ▼
   Automation engine    safe / controlled / confirm / never
        │
        ▼
   Control Tower + notifications + escalation
        │
        ▼
   audit_logs + operational timeline
```

The rule that keeps this from becoming another pile of alerts: **detection logic lives in one
detector class per problem and nowhere else.** No controller detects anything. A detector is a pure
question over real rows; the engine owns everything that is the same for every detector — dedup,
severity, lifecycle, escalation, audit.

---

## 3. Classification of the 84 brief items

`EXISTING` — already built and adequate. `EXTEND` — built, needs more. `REFACTOR` — built in the
wrong place. `NEW` — nothing exists. `N/A` — excluded by the scope restrictions or by this
marketplace's actual business model.

### 3A — Core control layer (P0)

| # | Item | Class | Note |
|---|---|---|---|
| 1 | Problem detection engine | EXTEND | `SellerInsightEngine` + 3 producers exist; the producer contract becomes the detector contract and gains ~25 more |
| 2 | Universal issue model | EXTEND | `seller_insights` gains status lifecycle, `impact_score`, `due_at`, `affected_count`, `first/last_detected_at`, escalation, assignment, resolution |
| 3 | Issue Center | EXTEND | Action Center is the seller-facing half; add sections, filters, explanation, resolution history |
| 4 | Severity + business impact | NEW | Documented deterministic rules, not scores |
| 5 | Business anomaly engine | NEW | Moving averages over real rows; `analytics_daily` already carries a per-vendor funnel |
| 6 | Root-cause correlation | NEW | Deterministic co-occurrence only; language stays "possible contributor" |
| 15 | SLA engine | EXTEND | `SlaService` already holds the one processing clock; widen to return response, dispute response |
| 16 | Escalation engine | NEW | Time-based severity promotion on the issue row |
| 17 | Notification orchestration | NEW | The delivery stack is complete and unused by detection (defect 4). Aggregate by (seller, type); one message, a filtered view behind it |
| 13 | Control Tower | NEW | Distinct from Home; sectioned by what needs doing now |
| 14 | Management by exception | NEW | A UX rule the Control Tower enforces, not a feature |
| 18 | Daily operations briefing | NEW | Deterministic day-over-day from real counts |
| 76 | Event architecture | EXTEND | 23 event/listener pairs fire today and every one is a transactional mail or push. No operational event exists, so detection has nothing to subscribe to yet |
| 60 | Audit centre | EXTEND | `audit_logs` is complete; the seller has no view of it |
| 62 | Operational timeline | EXTEND | Order timeline exists (Phase 2 B.2); widen to shop-wide |

### 3B — Automation (P0/P1)

| # | Item | Class | Note |
|---|---|---|---|
| 7 | Rules engine | NEW | Structured, validated rows — never executable code |
| 8 | Admin-managed rules | EXTEND | `monitoring_alert_rules` + `AlertEvaluator` already solve no-data, flap and cooldown correctly; business rules reuse those semantics rather than rediscover them |
| 9 | Automation engine | NEW | Detect → validate → execute → verify → audit → resolve/escalate |
| 10 | Automation safety levels | NEW | Four tiers, declared per action, enforced by the engine |
| 11 | Self-healing | NEW | Retry classes for webhook, carrier, job, stale lock |
| 12 | Retry + circuit breaker | NEW | One backoff trait exists (`QueuedMailDelivery`); no breaker, no dead-letter, and no `failed_jobs` table at all (defect 1) |
| 64 | Scheduled operations | EXTEND | `theme:publish-due` proves the pattern; generalise to price/status/promotion |
| 77 | Idempotency | EXISTING | `VendorLedger` unique key, `seller_bulk_jobs`, `authorizeForRefund`, RMA restock claim. Extend to new writers |
| 78 | Observability | EXISTING | `monitoring_*` is a full subsystem; new engines emit into it |

### 3C — Brand and compliance (P0 for brand, P1 for the rest)

| # | Item | Class | Note |
|---|---|---|---|
| 36–47 | Brand registry, verification, relationships, protection, expiry, audit | NEW | `brands` is catalogue-only: name, slug, image, status. No owner, no seller relationship, no `brand_*` table of any kind. `brands.name` has no DB unique key either — the guard is one form rule. The single largest genuinely-new block in Phase 3 |
| 48 | Brand dashboard | NEW | Depends on 36–47 |
| 49 | Compliance 2.0 | EXTEND | `seller_verification_documents` already carries `expires_at` and enforces it; widen the document catalogue and add the expiry alerts |
| 50 | Compliance rule engine | NEW | Category → required documents |
| 51 | Policy engine | NEW | Versioned policy with effective dates |
| 52 | Performance early warning | EXTEND | `SellerScorecardService` computes the rates; warn on approach, not only breach |
| 53 | Performance scorecard | EXISTING | Formulas already documented in the service |

### 3D — Operational execution (P1/P2)

| # | Item | Class | Note |
|---|---|---|---|
| 21 | Advanced fulfilment | EXTEND | Behind a flag; the marketplace is seller-fulfilled today |
| 22 | WMS lite | EXTEND | `warehouses` + `warehouse_stock` exist and are empty; zone/aisle/rack/bin behind a flag |
| 23 | Picking waves | NEW | Flagged off until a seller actually operates a warehouse |
| 24 | Packing station | NEW | Same flag |
| 25 | Shipping hub | EXTEND | One carrier integration exists; the hub is the seam a second would plug into |
| 26 | Shipping routing rules | NEW | Deterministic, logged |
| 27 | Shipping exception detection | NEW | Real signals exist: `delivery_syria_parcels.status_updated_at`, `courier_status` |
| 61 | Incident management | EXTEND | `monitoring_incidents` + `IncidentManager` already correlate signals inside a 30-minute window into one incident; seller issues link to one instead of multiplying |
| 73 | Mobile operations | EXTEND | The app is the primary surface and already carries most flows |
| 74 | Barcode workflows | EXTEND | `features/barcode` in the app generates barcodes; scanning one to act on a product does not exist |

### 3E — Financial control (P0)

| # | Item | Class | Note |
|---|---|---|---|
| 28 | Financial reconciliation | EXTEND | `ReconciliationService` runs 5 checks; results become seller-visible finance issues |
| 29 | Balance view | EXISTING | Phase 2 D built the traceable statement |
| 30 | Fee/commission engine | REFACTOR | `commission_rules` + `CommissionEngine` are complete; the legacy `orders.admin_commission` path still runs beside them and can disagree (defect 2) |
| 31 | Fee simulator | NEW | Reads the real rules; labelled an estimate |
| 32 | Pricing control | NEW | Floors, scheduled pricing, promotion restore |
| 33 | Price change audit | NEW | No history table, and `ProductService` is not an `AuditLogger` consumer (defect 3) |

### 3F — Growth (P2)

| # | Item | Class | Note |
|---|---|---|---|
| 19 | Opportunity engine | NEW | Separate store from issues; a missed opportunity is not a problem |
| 20 | Forecasting | NEW | Moving average over delivered units; always labelled an estimate |
| 34 | Campaign engine | EXTEND | Flash deals, deal of the day and featured deals are admin-only and add a seller's product directly — there is no nomination, acceptance, funding split or refusal. Sellers own only coupons and clearance |
| 35 | Advertising | N/A (flagged) | No advertising backend or billing exists. Per the brief, not shown until both are real |

### 3G — Enterprise control (P1)

| # | Item | Class | Note |
|---|---|---|---|
| 54 | Case management | EXTEND | `support_tickets` exists but is customer↔admin only; no seller side, no assignment, no SLA |
| 55 | Disputes and appeals | NEW | Depends on 54 |
| 56 | Workflow approvals | EXTEND | `ApprovalEngine` is finished; one caller adopts it. The work is adoption, not construction |
| 57 | Maker/checker | EXISTING | Maker≠checker and one-decision-per-actor both enforced by a DB unique key, so a race cannot beat them |
| 58 | Enterprise RBAC | EXTEND | 13 permission keys in 7 groups today; the brief lists ~25 |
| 59 | Security centre | NEW | Sessions, logins, key rotation |

### 3H — Scale and platform (P1/P2)

| # | Item | Class | Note |
|---|---|---|---|
| 63 | Bulk operations centre | EXISTING | Phase 2 A.3, with downloadable failures |
| 65–67 | Report builder, scheduled reports, export centre | EXTEND | Reports and exports exist; the builder and the schedule do not |
| 68–72 | API console, credentials, webhooks, delivery logs, integration health | NEW | A seller has one opaque `auth_token` — no name, scope, expiry, last-used, rotation or revocation. Passport is on `User`/`Customer` only; Sanctum is installed and unused. Outbound webhooks do not exist in any form |
| 75 | Performance and scale | EXTEND | Indexes are broadly good; two concrete gaps found (below) |
| 79 | Feature flags | EXTEND | Add-on state already gates modules; formalise for the flagged items above |
| 80 | Real data only | EXISTING | Enforced through Phase 2; every new surface follows it |
| 81 | Testing | EXTEND | 1,408 tests today |
| 82 | Migration safety | EXISTING | Every Phase 2 migration is additive and guarded |

### Excluded

| # | Item | Class | Why |
|---|---|---|---|
| — | AI / LLM / assistant | N/A | Forbidden by the brief; cancels Phase 2's planned H |
| — | B2B, wholesale, RFQ | N/A | Forbidden; existing `B2BPricingService` left untouched |
| — | Multi-country / multi-marketplace | N/A | Forbidden; single market |
| 35 | Advertising | N/A until real | No backend, no billing. A flag, not a screen |

---

## 4. Findings that are defects, not features

The audit turned up six things that are wrong today, independently of Phase 3. Four are P0.

1. **There is no `failed_jobs` migration.** `config/queue.php` names the table, and four separate
   consumers read it — `SystemHealthService`, `QueueCollector`, `OrderIntegrityPanel`,
   `DatabaseSettingController`. The table does not exist, so a queued job that exhausts its retries
   leaves no record anywhere and every health check that reads it is blind. **P0.** No self-healing
   or retry policy in 3B means anything until this is fixed.

2. **Two commission figures coexist per order.** `orders.admin_commission` is computed by the legacy
   `Helpers::sales_commission_before_order()` at six separate assignment sites, while
   `order_item_commissions` is written by `CommissionEngine` at one. They can disagree, and
   `PaymentsPanel` already contains a check that says so in as many words: "commission line(s) sum to
   a different figure from `orders.admin_commission`". The Phase 3 brief asks for exactly this in item
   30 — do not hard-code commission into order controllers. **P0**, and a prerequisite for the fee
   simulator, which must not quote a number the ledger will contradict.

3. **A price change leaves no trace.** `ProductService` writes `unit_price` at seven call sites and
   is not among `AuditLogger`'s consumers. There is no price history table. Item 33 is therefore not
   an enhancement — today nobody can answer who changed a price, from what, or why. **P0.**

4. **Nothing tells a seller about a breach.** `SlaService::evaluateAll()` opens a row in
   `seller_sla_breaches`; `SellerInsightEngine` writes a row in `seller_insights`. Neither touches
   `PushNotificationTrait` or `MailService`. The platform knows and the seller does not. **P0.**

5. **`order_status_histories.order_id` has no index.** `OrderIntegrityPanel` documents this and
   *disables its own stuck-order check* because of it — "a check that takes the shop down to find a
   missing audit row is worse than the missing audit row". The same index is required by the
   seller-facing stuck-order detector and by the order timeline shipped in Phase 2. **P1.**

6. **`stock_movements` has no `seller_id` index.** Phase 2 B.3 added a seller filter to
   `InventoryService::recent()`; that query has no index to ride. **P1.**

Two more worth recording without being defects: `seller:refresh-insights` runs hourly, so a
seller-facing deadline can be up to sixty minutes stale while `monitoring:evaluate` runs every
minute; and `ApprovalEngine` is a finished maker–checker engine with exactly one caller, so item 57
is mostly a question of adoption rather than construction.

## 5. Priority

P0 is marketplace operational correctness — the things whose absence lets the platform be wrong
about money, stock or a deadline. P1 is seller productivity. P2 is growth. P3 is polish, and none is
scheduled.

- **P0**: issue model, detection engine, severity, SLA widening, Control Tower, finance issue
  detection, brand registry and verification, escalation.
- **P1**: automation and self-healing, rules engine, compliance 2.0, RBAC widening, case management,
  security centre, API/webhook console, shipping exceptions.
- **P2**: opportunities, forecasting, campaign engine, WMS/picking/packing behind flags, report
  builder.
- **P3**: none scheduled. Nothing here is polish while P0 stands unfinished.

---

## 6. Execution order

Each block ships whole — backend, API, app, tests — before the next begins, because a detection
engine nobody can see is not worth having and a screen with nothing behind it is worse.

0. **3A.0** the four P0 defects above, first. A retry policy is meaningless without a
   `failed_jobs` table; a fee simulator is a lie while two commission figures disagree; a price
   control engine is unauditable while price changes leave no trace; and every detector built before
   the notification seam exists is a row nobody reads.
1. **3A.1** issue model + detector contract + severity engine + the indexes above.
2. **3A.2** the first detector set across orders, inventory, catalogue, pricing, returns, shipping,
   finance, integrations.
3. **3A.3** Control Tower + daily briefing + notification aggregation + escalation.
4. **3B** rules engine, automation engine, self-healing, circuit breakers, scheduled operations.
5. **3C** brand registry and verification, then compliance 2.0.
6. **3E** financial reconciliation surfaced, fee simulator, pricing control and price audit.
   *Taken before 3C in the event*: money that does not add up is marketplace operational
   correctness, which item 84 puts above brand protection, and 3C is the largest genuinely-new
   block.
7. **3G** case management, RBAC widening, security centre, audit centre.
8. **3H** API and webhook console, integration health, report builder.
9. **3D/3F** behind flags, last, and only what the marketplace actually operates.

---

## 7. Progress log

| Date | Block | What landed |
|---|---|---|
| 2026-08-24 | Admin | Seller operations: the marketplace's side of everything the Seller Center gained. Rules, keys, endpoints, staff and bulk jobs existed only from the seller's side, so an operator could not ask the question they always ask at three in the morning — how many shops is this happening to, and which. Five read-mostly pages, three interventions only the marketplace can make (stop a rule, kill a key, switch off an endpoint), each recorded. A missing table reads as "not installed" rather than zero, because an operator seeing "0 suspended rules" on a platform with no rules table would conclude automation is healthy. Vendor analytics now carries operational state beside the traffic: a shop whose traffic is falling and whose automation has been suspended for a week is one story, not two. 16 tests, three mutations verified. Also `docs/deployment.md`: what has to be running for the screens to mean what they claim. |
| 2026-08-24 | 3E (item 32) | A floor under a seller's own prices, checked before the write. The below-cost detector reports after the fact; by then the price has been live and orders may have been taken at it. Measured against what a customer would actually pay, because a floor reading only `unit_price` is cleared by any large enough discount — the exact case it exists to catch. A margin floor over a product with no recorded cost computes nothing rather than treating the cost as zero, which would clear everything while looking like enforcement; the endpoint returns how much of the catalogue has a cost, and the app puts that beside the switch. Enforced where the seller acts, reported everywhere else. 14 tests, six mutations verified. |
| 2026-08-24 | 3G/3H | The shop's security desk, and the keys it issues. Staff and roles reached the app, and the rules behind them moved into one service the vendor panel calls too — they were about to be written a second time, and two copies of "a staff member may only be given a role belonging to the same shop" disagree eventually. Switching somebody off now clears their token as well as their status. The audit trail answers "what happened in this shop" from a platform-wide table: actions by the owner or their staff, plus decisions the marketplace recorded about them; matching the shop id inside JSON needs the character after it, or shop 1 also finds shop 11. Keys exist to bound the blast radius: a key is deliberately not an owner, cannot be issued with more than its issuer holds, dies with the shop's standing, and cannot mint or revoke a key. Webhooks are raised from model observers rather than call sites, signed over their exact bytes, kept attempt by attempt, and switched off rather than retried for ever. 41 tests, eleven mutations verified. |
| 2026-08-24 | 3C | Brands, and who is entitled to sell under one. Owner, authorised reseller or distributor, with the evidence attached and the marketplace adjudicating. An unclaimed brand stays open to everybody, because the platform does not know who owns a name until somebody shows it; an authorisation with an end date stops entitling anybody the day it runs out; revocation is recorded as a different fact from rejection. Enforcement ships behind a flag and off, since switching it on over an empty registry would block every listing in the shop. 22 tests. |
| 2026-08-24 | 3B | Rules the seller writes, and the limits that make them safe unattended. Four triggers, three actions, and a registry that refuses an illegal pair at creation rather than at three in the morning. A run that would touch more than the seller allows touches nothing and stops the rule; three failures in a row stops it too, and only a person starts it again. Publication refuses what a moderator turned down, a markdown that cannot reach the floor refuses rather than shrinking itself, and restocking only puts back what automation took down. Every run is written down even when it matched nothing, and every change carries before and after so exactly one of them can be undone. 23 tests, six mutations verified. |
| 2026-08-24 | — | Audit complete; this plan written. |
| 2026-08-24 | 3E | The seller's own view of the marketplace's arithmetic. Reconciliation walks the chain one shop at a time — delivered lines, an earning recorded for each, a credit in the ledger for each — and names what fell out at every hand-off, with rows that open. `reconciles` is true only when nothing fell out, not when the totals happen to agree: a missing earning and an extra credit can cancel out and leave a seller unpaid for a line, and a check that stopped at the sums would call that fine. The fee simulator calls the commission engine itself rather than reimplementing the rates, so a seller prices against the number the platform will actually charge, and names tax, shipping and payment fees as excluded rather than estimating things that do not exist for an order nobody has placed. Price history from 3A.0's recorder is now readable by the seller, scoped through the products table rather than trusting the denormalised column. Found while verifying: `lines` is reserved in MariaDB, so the first reconciliation query was a syntax error against the real database and would have passed a SQLite-only test. 18 tests, five mutations verified. |
| 2026-08-24 | 3B | Rules the seller writes, and the limits that make them safe to run unattended. Four triggers and three actions, with the registry refusing an illegal pair at creation rather than at three in the morning. A run that would touch more than the seller allows touches nothing and stops the rule; three failures in a row stops it too; only a person starts it again. Publication refuses what a moderator turned down, a markdown that cannot reach the seller's floor refuses rather than quietly applying a smaller one, and restocking only puts back what automation itself took down. Every run is recorded even when it matched nothing, every change carries the value before and after, and undo restores only the columns the action declares it owns. Prices moved by a rule are attributed to that rule by name in 3A.0's price history. Found while building: a trigger selecting named columns left the approval flag null, so an approved listing read as unapproved — triggers now select whole rows, bounded by the rule's own cap. 23 tests, six mutations verified. |
| 2026-08-24 | 3A.2 | Eleven detectors across seven of the eight domains, and the three existing producers moved onto measured severity. Orders: stuck-in-status (read from status history, not `updated_at`, which any write bumps) and self-contradicting state pairs. Inventory: stock that has not moved, live listings with nothing to sell, hidden listings with stock to sell. Catalogue: duplicate barcodes within one shop, and listings missing what their own category requires — the same information a moderator would refuse them for, moved to where it is still cheap to act on. Pricing: extreme single moves and selling below cost, both only possible because 3A.0 started recording price changes. Returns: unanswered refund requests and goods that arrived and stopped. Shipping: couriers gone quiet, read from the webhook's own timestamp. Finance: delivered lines with no earning recorded. Findings true of many rows are one issue with a count. Integrations is deliberately empty — per-seller integrations do not exist until 3H, and a detector for them would have nothing real to read. Fixed while verifying: the engine's computed severity was being discarded by PHP's `+` operator keeping the left operand, and treating a detector's declared severity as a floor meant the engine could raise but never lower. 26 tests, five mutations verified. |
| 2026-08-24 | 3A.1 | The issue model and the severity engine. `seller_insights` grew into a first-class issue rather than being replaced — a second table would leave two lists of a seller's problems that could disagree — with a status lifecycle a seller actually works in, history that survives re-detection, a due date distinct from an expiry, and how it ended distinct from when. Severity is now measured against the seller's own business instead of declared: five capped components totalling 100, every one a share of their turnover or their catalogue rather than an absolute figure, so the brief's own example holds — the same stockout is `high` for a shop turning over 2,000 and `low` for one turning over 200,000. A missing signal scores zero rather than averaging away, and `confidence()` says how much of the picture was measured. A detector can declare a floor for findings that are not a matter of degree. The bands were wrong on the first pass and a test caught it: `high` sat above what revenue alone could reach, so a quarter of a shop's turnover at risk read as `medium`. 13 tests, four mutations verified. |
| 2026-08-24 | 3A.0 | The four P0 defects fixed and B2B removed. `failed_jobs` created — four consumers read a table nothing made, so an exhausted job left no trace. `orders.admin_commission` becomes the sum of the per-line snapshots, so the figure a seller is paid from stops disagreeing with the figure the rules charged; a missing snapshot leaves the legacy number alone rather than zeroing it. Price changes recorded by an observer, because the writers are the problem — seven call sites in one service, and the eighth will be added by someone who never heard of the history. A delivery log joins detection to the notification stack: one message per fact, announced once per window. Two missing indexes added. B2B removed entirely, tables verified empty and the drop still refuses a populated one. 13 tests, three mutations verified. |
