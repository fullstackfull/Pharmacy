# Seller Center V2 — Master Plan

**Status:** living document. This is the architectural source of truth for the Seller Operating
System phase. Phase 1 (the seller-center API, the mobile app's seller-center and reports features,
the marketplace suite) is the foundation; nothing here discards it.

**Audited against the running system**, not against route names: 186 `api/v3/seller` routes, 293
`vendor/*` web routes, 23 services under `app/Services/Marketplace/`, and the live schema. Every
"EXISTING" claim below was checked in code or in the database.

---

## 0. What Phase 1 actually left us

This matters more than any wish list, because it decides what V2 extends and what it must build.

### Services that already own real business logic

`app/Services/Marketplace/` — 23 services, all with tests:

| Service | Owns |
|---|---|
| `SettlementEngine` | order → settlement, commission maturation |
| `VendorLedger` | the five signed balance buckets (pending/available/reserved/paid/balance) |
| `CommissionEngine` | commission rules and rates |
| `PayoutService` | payout request, reserve, cancel, cooling period, dual control |
| `SlaService` | SLA thresholds, breach open/clear, idempotent evaluation |
| `SellerScorecardService` | seller tier and performance metrics |
| `SellerVerificationService` | KYC documents, required-document policy |
| `WarehouseService` | per-warehouse placement, transfer, unallocated stock |
| `InventoryService` | adjustments + movement ledger |
| `BatchService` | batches, expiry, write-off |
| `PurchaseOrderService` | PO lifecycle and stock-in |
| `ReturnLogisticsService` | RMA authorise → receive → restock/reject |
| `FulfillmentService` | fulfilment state machine (never touches order status) |
| `ProductModerationService` | approve/reject with reason |
| `ReconciliationService`, `RefundReversalService`, `ShippingRateService`, `B2BPricingService`, `ExchangeRateService`, `PaymentRoutingService`, `CategoryGovernanceService`, `SellerPermissionService`, `SellerCenterService` | as named |

Plus, outside Marketplace: `app/Services/Reports/` (`ReportWindow`, `SellerReportService`),
`app/Services/Analytics/` (event catalogue, `Analytics` one-door, `EventRecorder`, reporting
windows), `app/Services/DeveloperPortal/`, `app/Services/AuditLogger`, `app/Services/SellerInvoiceService`.

### Tables that already exist

`warehouses`, `warehouse_stock`, `product_stocks`, `stock_movements`, `product_batches`,
`purchase_orders`, `purchase_order_items`, `return_shipments`, `vendor_ledger_entries`,
`vendor_settlements`, `vendor_payout_requests`, `seller_verification_documents`,
`seller_sla_breaches`, `seller_roles`, `seller_staff`, `audit_logs`, `restock_products`,
`seller_wallets`, `seller_wallet_histories`, `vendor_withdraw_method_infos`,
`vendor_bank_change_logs`, `analytics_*`.

### The decisive finding

**A great deal of marketplace depth exists in services and tables and is reachable only from the
admin panel or not at all.** The seller — the person whose business it is — cannot see most of it.

That reframes V2. It is not mostly "build new capability". It is, in large part, **give the seller
their own operation back**, then add what is genuinely missing.

---

## 1. Capability matrix

Legend: **E** existing · **EI** existing, needs improvement · **P** partial · **M** missing ·
**F** future/optional. P0–P3 is build priority.

### Home & attention

| Capability | State | Evidence | Priority |
|---|---|---|---|
| Seller home | P | app home tab + seller-center card; web `vendor/dashboard` | P0 |
| Business overview KPIs | P | `order-statistics`, `get-earning-statitics`, seller-center overview — but no compare-to-previous, no AOV, no units | P0 |
| **Action Center** | **M** | nothing centralises what needs attention | **P0** |
| Notifications | P | `notifications` table + `notification` endpoint; no categories, priority, preferences, deep links | P1 |
| Announcements | M | — | P2 |
| **Global search / command palette** | **M** | — | P1 |

### Catalog

| Capability | State | Evidence | Priority |
|---|---|---|---|
| Products CRUD | E | 28 `products/*` routes | — |
| Variants | E | product variation handling in add/edit | — |
| Drafts / approval status | P | `request_status` 0/1/2 exists; no draft, submitted, suspended, archived states | P1 |
| Rejection reason codes | P | `ProductModerationService` records a reason; app never shows it | P0 |
| **Listing quality score** | **M** | — | P1 |
| **Bulk operations (async + validation report)** | **M** | no seller-facing bulk anything | **P0** |
| Import / export | P | web-only excel exports; no import | P1 |
| Category requirements | P | `CategoryGovernanceService` exists, admin-only | P2 |

### Inventory

| Capability | State | Evidence | Priority |
|---|---|---|---|
| Stock adjust + movement ledger | E (service) | `InventoryService`, `stock_movements` | — |
| **Seller API for inventory** | **M** | service is admin-only | **P0** |
| Warehouses | E (service) | `WarehouseService`, `warehouses`, `warehouse_stock` | — |
| **Seller API for warehouses** | **M** | admin-only | P1 |
| Reserved / available split | P | ledger concept exists; not exposed per SKU | P1 |
| Low / out of stock | E | `products/stock-limit-status`, `stock-out-list`, stock report | — |
| Batches & expiry | E (service) | `BatchService` | P2 for seller UI |
| Days of supply, sell-through, dead stock | M | needs real history — data exists in `stock_movements` | P2 |
| Restock recommendations | M | computable from movements; must not be fabricated | P2 |

### Orders & fulfilment

| Capability | State | Evidence | Priority |
|---|---|---|---|
| Order list + detail | E | 14 `orders/*` routes | — |
| Advanced filters / saved views | M | list takes limit/offset/status only | P1 |
| **Order timeline from real events** | **P** | `orderStatusHistory`, `orderEditHistory` exist; no unified timeline | P0 |
| Earnings/fees breakdown per order | P | fields exist on the order; not presented as a breakdown | P0 |
| Bulk order actions | M | — | P1 |
| Fulfilment state machine | E (service) | `FulfillmentService` | — |
| **Seller API for fulfilment** | **M** | admin-only | P1 |
| Labels / pickup / carrier | P | `DeliverySyria` integration exists | P2 |
| **SLA countdown for the seller** | **P** | `SlaService` computes breaches; seller never sees a countdown | P0 |

### Post-purchase

| Capability | State | Evidence | Priority |
|---|---|---|---|
| Returns (RMA) | E (service) | `ReturnLogisticsService`, `return_shipments` | — |
| **Seller API for returns** | **M** | admin-only | **P0** |
| Refunds | E | 4 `refund/*` routes | — |
| Claims / disputes | M | no case entity at all | P2 |

### Finance

| Capability | State | Evidence | Priority |
|---|---|---|---|
| Ledger with signed buckets | E | `VendorLedger` | — |
| Payout request / cancel | E | exposed in app (Phase 1) | — |
| **Transaction-level statement** | **M** | `vendor_ledger_entries` exists; never listed for the seller | **P0** |
| "Why did I receive this amount" | M | payout → entries link not exposed | P0 |
| Commissions / fees visibility | P | computed; not itemised to the seller | P0 |
| Statements (PDF/CSV) | M | — | P1 |
| Tax | P | TaxModule + `get-vat-tax-report-list` | P2 |

### Growth, analytics, performance

| Capability | State | Evidence | Priority |
|---|---|---|---|
| Coupons | E | 7 `coupon/*` routes | — |
| Flash deals / campaigns | P | admin-side deal engine; no seller surface | P2 |
| Advertising | M | no backend at all — **feature-flag only, do not simulate** | P3 |
| Sales/product analytics | E | Phase 1 analytics + reports | — |
| Funnel (impression→view→cart→purchase) | P | `analytics_events` carries views and cart adds; impressions not tracked | P2 |
| Account health page | P | `SellerScorecardService` + `seller_sla_breaches`; no explain-why surface | P0 |
| **Seller intelligence engine** | **M** | each page invents its own alerts | **P0** |

### Enterprise

| Capability | State | Evidence | Priority |
|---|---|---|---|
| Staff accounts | P | table + service + API identity; **still no seller-facing UI to create roles or staff**, and 0 roles exist in production | P1 |
| RBAC enforcement | **E (API)** | `SellerPrincipal` + `SellerApiAuthMiddleware` + `seller_can:` middleware. Staff hold their own token; permissions read per request, so a revocation lands on the next call. Web panel still uses the session middleware — unchanged, and a candidate to converge later | — |
| Audit log | E | `AuditLogger` + `audit_logs`, wired across Marketplace (Phase 1 fix) | — |
| **Seller API keys** | **M** | no table, no concept | P1 |
| **Webhooks** | **M** | no table, no concept | P1 |
| Compliance documents | P | `seller_verification_documents` covers KYC; no expiry alerts, no brand authorisation | P1 |
| Multi-country | M | one currency/country baked in | P3 |

---

## 2. Architecture decisions

### D1 — One intelligence engine, not per-page alerts

Every "you should look at this" in the product comes from **one** typed insight store, so Home, the
Action Center, notifications and (later) the assistant cannot disagree.

```
seller_insights
  id, seller_id, type, severity, title, body,
  entity_type, entity_id, metric, impact,
  action_key, action_params(json),
  created_at, expires_at, dismissed_at, resolved_at,
  fingerprint  -- unique per (seller, type, entity): re-running a producer updates, never duplicates
```

Producers are small classes with one job, run by a scheduled command and on relevant events. Each
declares its own type, severity rule and action. **A producer that cannot compute from real data
does not emit** — no placeholder insights.

Why not compute on read: the seller opens Home dozens of times a day, and these are aggregate
queries over orders, stock and ledger entries. Precomputing also gives dismissal and history, and
gives the Action Center a stable sort.

### D2 — RBAC moves to the token, not the session

`SellerStaffAccessMiddleware` gates on a session key. The API is stateless, so today a staff member
cannot use the seller app at all, and a staff member who obtains the owner's token gets **owner
rights** with none of the permission matrix applied.

V2: the authenticated principal carries `seller_id` **and** an optional `staff_id` + permission set,
resolved in `SellerApiAuthMiddleware` and enforced by a route middleware and a policy layer. Menu
hiding is never the control.

### D3 — Extend the existing services; expose them, don't re-implement

Inventory, warehouses, returns, fulfilment and purchase orders already have tested services. V2 adds
**thin seller-scoped controllers** over them, exactly as Phase 1 did for payouts and KYC. No parallel
logic.

### D4 — Bulk work is a job with a receipt

Every bulk operation creates a row the seller can watch and download errors from:

```
seller_bulk_jobs
  id, seller_id, type, status(queued|processing|completed|partial|failed),
  total, processed, succeeded, failed,
  input_path, report_path, error_summary(json),
  created_by_staff_id, created_at, started_at, finished_at
```

A bulk operation never reports success it did not achieve.

### D5 — Real data or an honest empty state

Non-negotiable. Where a metric needs telemetry we do not have (product impressions, ad metrics), we
either add the telemetry or ship the empty state and say why. No fabricated numbers, ever.

### D6 — Feature flags for architecture without an engine

Advertising and platform-fulfilment get their data model and interfaces, behind flags, and are not
shown to sellers until there is something real behind them.

---

## 3. Phase plan

Each phase ends green: tests pass, no existing web/app/API flow broken.

**A — Foundation (P0)**
1. `seller_insights` + producer interface + scheduler + first three producers (inventory risk, order SLA, listing quality).
2. Token-carried RBAC: staff principal, permission middleware, policies. Backfill: an owner has every permission.
3. `seller_bulk_jobs` + job runner + validation-report storage.
4. Audit coverage check for the new write paths.

**B — Daily operations (P0)**
Action Center API + app screen · order timeline + fee breakdown · SLA countdown · inventory and
warehouse seller APIs + app screens · product rejection reasons surfaced · bulk price/stock.

**C — Post-purchase (P0/P1)**
Returns seller API + app · refund visibility · dispute case entity.

**D — Finance (P0)**
Ledger statement API (transaction-level, filterable) · payout → entries link · per-order fee
breakdown · statement export.

**E — Intelligence (P1)**
Account health explain-why · more insight producers · funnel telemetry where missing.

**F — Growth (P2)**
Promotions beyond coupons · growth opportunity cards from real data.

**G — Enterprise (P1/P2)**
API keys · webhooks + delivery log · compliance expiry alerts · team management UI.

**H — Assistant (P3)**
Only after D and E: an assistant with nothing true to say is worse than none.

---

## 4. What we will not do

- Not copy any marketplace's UI, branding or layout. Capability references only.
- Not simulate advertising, platform fulfilment or AI answers.
- Not rebuild working modules. Phase 1 stands.
- Not add a second frontend. The app and the vendor panel both consume the same APIs.
- Not rename or drop production columns.

---

## 5. Progress log

| Date | Phase | What landed |
|---|---|---|
| 2026-08-24 | — | Audit complete; this plan written. |
| 2026-08-24 | A.2 | Token-carried RBAC. `SellerPrincipal` separates the shop from the person; `SellerApiAuthMiddleware` resolves either an owner or a staff token into one; `seller_can:` gates routes. Staff can now log into the API at all — before this the only way in was the owner's token. Payout read and payout request split into two permissions. 14 tests. |
| 2026-08-24 | A.1 | 24-hour processing policy, editable on the SLA policy page; the seller's countdown reads it. `seller_insights` + `SellerInsightEngine` + producer contract. Three producers: inventory risk (ranked by units actually sold, not by how low the number is), order SLA, listing quality (score from the record, rejection reason read from `product_moderation_events` — which the app had never shown). Hourly `seller:refresh-insights`. Action Center API with dismissal. 13 tests. |

### Decisions this phase settled

- **The ship-by policy is 24 hours.** `SlaService` measured rates only; nothing defined how long a
  seller has to get an order moving, so the countdown had nothing true to count against. The
  marketplace owner set it at 24 hours, and it is now a line on the SLA policy page alongside the
  rate ceilings — one number, changed in one place, read by both the seller's countdown and the
  deadline the marketplace judges them by. Two clocks would be worse than none.
