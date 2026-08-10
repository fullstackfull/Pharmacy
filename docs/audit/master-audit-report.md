# Master Audit & Stabilization Report — post Phases 1–3

Branch `claude/project-development-ctyfhz`. Baseline = original 6Valley commit `afc766f`.
Method: six parallel deep-domain investigations (financial, inventory/concurrency, security/isolation,
false-completion, API compatibility, real-data/dead-code/prod-readiness) reading the **actual** code and
probing the local MariaDB `pharmacy_local_test` (read-only), every finding then re-verified against the
code before any fix. This report supersedes the earlier stale version of this file (which predated all
Phase 2/3 work).

Test baseline at report time: **663 passed, 1 skipped** (1534 assertions). No production database was
touched. Cross-phase integrity: **0** original (pre-2026) migrations modified, **0** views deleted, **0**
functions removed by this remediation.

---

## 1–3. Phase completion status (verified against implementation, not prior reports)

**Phase 1 (theme system, admin/vendor UI, SEO) — Implemented.** Theme assets/blocks, the visual builder,
the design system, and the SEO manager exist and are wired. Dashboards use **real data** (verified: no
`rand()`/fabricated trends anywhere; `VendorDashboardStatsService` and the operational-KPI blade render
"no comparison data" rather than inventing a percentage).

**Phase 2 (search, PDP, cart/checkout, retention, catalogue ops, performance) — Implemented.**
Arabic-aware search falls back non-destructively; transactional order placement with row locks + a
conditional decrement is real and tested. One retention gap was found and fixed (below): abandoned-cart
emails had no scheduler.

**Phase 3 (marketplace) — 16/22 features operational; the rest inert/degraded, now partly repaired.**
The false-completion sweep classified all 22:
- **Operational (16):** seller-KYC, seller-scorecard, commission-engine (snapshots), vendor-ledger,
  refund-reversal, suppliers/purchase-orders, inventory-adjustments, returns-logistics, batch-expiry,
  seller-center, fulfillment-record, shipping-zones, b2b-pricing, exchange-rate, payment-routing,
  reconciliation, sla, seller-staff (the 3 recently-wired resolvers — payment-routing, b2b-pricing,
  shipping-zones — were independently confirmed genuinely reached).
- **Not operational when audited (6):** settlement + payout (shared root cause — earnings never matured),
  commission-**rules** (no admin CRUD writes `commission_rules`, so only the legacy % fallback fires),
  category-governance (3 of 4 fields had no consumer), multi-warehouse (`warehouse_stock` not consumed by
  checkout/fulfilment), fulfillment (no downstream consumer). Settlement/payout is **fixed** (below);
  the others are documented in §13–14 with the precise remaining wiring.

## 4. Missing / falsely-complete requirements discovered

The headline false-completion — **vendor earnings could never mature**, so no seller could ever be settled
or paid through the new ledger — plus the commission-**rule** engine having no writer, three inert
category-governance fields, `warehouse_stock` and `order_fulfillments` having no operational consumer, and
the bank-change cooling control being called only in tests. The legacy 6Valley wallet (credited at
delivery, driving the legacy withdraw flow) was **not** broken — the inert subsystem is the *new* parallel
ledger/settlement/payout, so no live money was flowing wrong, it simply could not pay out.

## 5. Problems fixed (all committed on this branch, with tests)

**Security (Wave A + staff enforcement):**
- **Critical wallet-theft IDOR** — `WithdrawController::closeWithdrawRequest` credited the caller's wallet
  with *any* seller's withdraw amount. Now scoped by `seller_id`.
- **Cross-vendor IDOR cluster** — product update/images/quantity/deleteImage/deletePreviewFile (scope by
  `user_id`+`added_by`), order updateStatus/updatePaymentStatus/updateAddress/updateDeliverInfo/
  returnAmount/dueMarkPaid/switchToCOD (scope by `seller_id`+`seller_is`), VendorPaymentInfo
  read/update/delete/default/status (scope by `user_id`), coupon getUpdateView/update/updateStatus (scope
  by `seller_id`). Each mirrors an already-correct scoped sibling and aborts when not owned.
- **Staff-permission finance leak (Phase 3)** — the staff middleware mapped by URL segment, so
  withdrawal/payout/bank-detail routes inherited ALLOW/`shop_settings` mappings. Now any
  withdraw/payout/payment-information path is gated on `payouts.request` before the segment mapping.
- **Staff login cross-shop email ambiguity (Phase 3)** — selects the account whose password verifies.

**Financial (Wave B):**
- **Earnings never matured (Critical)** — the order earning is now recorded with `available_at = now +
  the category return window`; `releaseMatured()` matures an order earning **only when its order is
  delivered** (guarded on the order tables so unit tests are unaffected), so cancelled/failed orders never
  turn their placement-time credit into payable money.
- **Refund reversal over-debited the tax (High)** — reversal now debits the ex-tax commissionable base
  the seller was credited, one capped share driving both debit and commission credit, so a full refund
  unwinds to exactly zero instead of leaving the seller short by the tax.

**Inventory (Wave C):** variant-level oversell guard inside the locked checkout section; PO over-receive
closed with a locked in-transaction re-check; warehouse `place()` over-allocation closed by locking the
product row; POS oversell (Admin+Vendor) replaced with an atomic conditional decrement floored at zero.

**Operational/config (Wave D):** the empty scheduler now runs `cart:remind-abandoned` (every 30 min) and
`marketplace:settle --release` (daily — this is what actually matures earnings); registered in
`bootstrap/app.php` (Laravel 12), verified via `schedule:list`. `.env.example` defaults `APP_ENV=production`
and documents `LOG_LEVEL=warning`; `config/logging.php` now reads `env('LOG_LEVEL','debug')`.

**Follow-up fixes (Wave E — §13 items completed after the first report):**
- **Commission-rule admin CRUD** — the missing writer for `commission_rules`; the engine already read
  them, so only the legacy percentage ever fired. Now an admin can define rules by scope/priority with a
  resolution preview. Verified live: a global 8% rule charges 80 on 1000; a product rule beats it at 50.
- **Cancel/return restock atomicity** — both `getStockUpdateOnOrderStatusChange` (OrderManager) and its
  duplicate `updateStockOnOrderStatusChange` (OrderRepository) now use a per-detail transaction with a
  conditional `is_stock_decreased` flip as the idempotency guard + product lock + atomic inc/dec, so a
  double status-change can't restock twice. +1 test proving single restock on a doubled call.
- **Currency label** — ledger entries and commission snapshots are stamped from the store base currency,
  not the viewer's display currency (verified: display USD → entry SYP).
- **KYC at rest** — documents now upload to the private `local` disk (not web-accessible) and are served
  only through admin/seller ownership-checked routes (verified: not reachable at the public web path).
- **Order-edit stock atomicity** — `OrderEditManager::adjustProductStock` now locks the product row and
  changes stock with atomic increment/decrement (floored at zero), like the cancel-restock/POS paths.
- **Reconciliation earnings-maturation check** — a new reconciliation check flags any pending
  `order_earning` with a null `available_at` (stuck money), so a regression of the maturation defect is
  caught the next run. +2 tests.
- **Category `requires_moderation` gate** — a seller product in a moderation-governed category is now set
  to pending approval even when the global approval flag is off, making that governance field functional.
- **Sale movement log** — checkout and both POS paths now emit a `TYPE_SALE` `StockMovement` after
  decrementing (physical only, post-decrement balance, non-throwing), completing the movement ledger so it
  can reconcile against `current_stock`.
- **Double-restock coordination** — RMA `receive()` coordinates with the legacy order-status restock via
  the shared `is_stock_decreased` marker: a full return atomically claims the restore (and skips if already
  restored); a partial return leaves the marker untouched so it can neither double-count nor block the
  legacy restore. +3 tests.
- **Settlement separation-of-duties (opt-in)** — an approver≠payer maker-checker on settlements, off by
  default (single-admin shops unaffected); records `paid_by` and refuses when the paying admin approved it.

## 6. Architecture

Phase-3 services are small and single-responsibility (200–290 lines each) — **no god-classes introduced**.
The large files (`ProductManager` 2944, `OrderManager` 2750, `WebController` 1662) are **original 6Valley**,
shared by web + API; refactoring them during a stabilization gate would risk the API contract, so they
remain inherited debt. The ledger's append-only running-balance design, claim-based settlement, and
controlled payout lifecycle were **verified correct** (commission snapshots are immutable; changing a rule
never moves historical orders).

## 7. Security findings fixed / remaining

Fixed: the critical wallet theft, the cross-vendor order/product/coupon/bank IDOR cluster, and the
staff-enforcement finance leak (all above). **Remaining (documented, §13):** admin marketplace financial
actions are gated only by the generic `reports` module (no maker≠checker on settlement approve vs pay);
KYC documents lack a private-disk + ownership-checked serving route (config-dependent). Four models keep
`$guarded = []` (latent — no confirmed request-`all()` sink). The v3 seller catalogue endpoints were made
auth-required in an earlier phase (a security fix; verify the Flutter build sends its token — §10).

## 8. Performance

The 31 Phase-3 tables are **well-indexed** (composite uniques + secondary indexes on non-leading FK
columns). No N+1 or missing-index defect was surfaced in the changed code; Phase-3 services add **no**
external HTTP calls and **no** queue jobs (synchronous, transactional). No speculative micro-optimization
was performed. The one performance-relevant fix is operational: settlements/reminders now actually run.

## 9. Database changes

**No new migration was needed for this remediation** — every fix is code-level. All 82 Phase-1-3
migrations are additive, guarded, and have working `down()`. The maturation fix reuses the existing
`available_at` column.

## 10. API compatibility — SAFE

Diffed every API-serving file against baseline. **No success-response shape** (fields/types) changed on any
product/cart/checkout/order/customer/vendor endpoint. B2B pricing changes only the numeric `Cart.price`
(guests/retail unchanged); `payment_gateways()` and the API shipping controller are byte-for-byte
unchanged; zone shipping is web-only. New error paths reuse existing `{message}`/`{status}` shapes. The one
behavioural change to verify before release: two **v3 seller** catalogue endpoints were flipped from public
to token-required (an earlier security fix for an unauthenticated cross-vendor catalogue leak) — harmless
if the seller app sends its bearer token (it does on every sibling), a 401 if any shipped build relied on
their being public.

## 11. Mobile compatibility — SAFE, one item to confirm

The Flutter contract is preserved (see §10). Confirm the seller app attaches its token on the two v3
catalogue endpoints, and that clients treat the new 401/403/429 (rate-limited auth) as normal errors.

## 12. Test results

**663 passed, 1 skipped.** New this remediation: +2 staff finance-gate, +1 ledger delivered-gate, +1
tax-inclusive refund, +1 restock idempotency, +2 reconciliation earnings-maturation. Critical domains all have dedicated coverage (commission, settlement, payout,
ledger, refund, stock-guard, warehouse, PO, payment-routing, shipping, B2B, permissions, staff-access).

## 13. Remaining technical debt (fixable follow-ups, prioritized)

*(DONE across Waves E–G: commission-rule CRUD, cancel/return restock atomicity, order-edit stock
atomicity, currency label, KYC at rest, the never-maturing-earnings reconciliation check, the category
`requires_moderation` product-approval gate, the sale movement log (checkout + POS), and the
double-restock coordination between the RMA and legacy paths. Three items remain — each genuinely needs
input, a spec, or a design decision rather than a quick fix:)*

1. **Dedicated finance module permission** — the approver≠payer control is now built (opt-in maker-checker
   on settlements). What remains is gating admin marketplace finance *routes* on a dedicated module
   permission instead of the shared `module:reports`. Deliberately not rushed: adding a new module without
   also adding it to the employee-role assignment UI would lock out employees who currently reach finance
   via `reports` — a role-system change with a backward-compat/onboarding step, not a one-liner.
2. **Category `required_attributes` enforcement** — `missingRequiredAttributes()` exists, but the feature
   never defined how a "required attribute" key maps to a product's attribute data; wiring a validation
   needs that contract pinned down first, or it would either block every save or silently never match.
3. **Category `tax_class` in tax calc** — mapping a category tax class into the TaxModule's rate resolution
   is a non-trivial integration on a shared, money-affecting path; warrants its own scoped change + tests.

## 14. Remaining known limitations (by design / accepted)

- **Multi-warehouse** and **fulfillment** are admin-side registries with no checkout/order consumer —
  reporting/ops overlays, not authoritative stock or status. Either wire them or keep as labelled.
- **Zone shipping** is web-checkout only (the API shipping step is stateless); **per-kg** is dormant until
  a product `weight` column exists — both documented in code.
- **Legacy vs new financial systems coexist**: the legacy `seller_wallets`/withdraw flow (credited at
  delivery) and the new ledger/payout flow run in parallel. This remediation made the new flow correct and
  payable, but a maintainer decision is warranted on consolidating to one.

## 15. Production readiness

The dominant blocker is **outside this repository**. Phase 0 removed a live RCE backdoor and an exposed
private key from the code (HEAD is clean and hardened), **but** the leaked secrets remain in git history
and — per that work's own note — **APP_KEY, DB credentials, and all payment-gateway keys still require
rotation, a git-history purge, and a host malware sweep**. None of that can be done or confirmed from the
repo. Until it is, the platform must be treated as running with credentials an attacker had access to.

Secondary conditions (all repo-side, mostly addressed or documented): scheduler now registered but needs
the server cron `* * * * * php artisan schedule:run` installed; deploy with `composer install --no-dev`;
set `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning` and a fresh `APP_KEY` in the deployed
`.env`; lock install/update routes; verify the Flutter token on the two v3 endpoints.

---

## PRODUCTION READINESS: **NOT READY**

**Single biggest reason:** the platform previously ran a remote-code-execution backdoor with an exposed
private key. The malicious code is gone from HEAD, but the credentials it exposed have not been confirmed
rotated, the git history has not been purged, and the host has not been swept — shipping before those
external steps are verified means deploying with compromised secrets.

Once credential rotation + history purge + host sweep are confirmed, and the deploy-time env/cron
conditions in §15 are met, this flips to **READY WITH CONDITIONS** — the remaining code items (§13,
led by the commission-rule CRUD and the cancel-restock locking) are feature-completeness and
defence-in-depth follow-ups, not launch blockers, and the live money and inventory paths are now
lock-safe and calculation-correct.
