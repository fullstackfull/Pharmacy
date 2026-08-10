# Phase 3 — Checkout adoption of the three resolvers (hot-path wiring)

## Why this

Three Stage-E/C resolvers shipped earlier as complete, tested, admin-managed engines with a preview,
but were **not yet consulted by the live cart/checkout**: payment routing, B2B/wholesale pricing, and
destination shipping zones. This note records wiring each into its hot path — the step from "an admin
can configure it and preview it" to "an order is actually priced/routed/shipped by it" — under one
rule: **with no configuration, checkout behaves byte-for-byte as before, and the mobile API contract is
never broken.**

## What shipped

### 1. Payment routing → the web checkout gateway list

`PaymentRoutingService::applyToModels()` is added — the same hide/prefer rules `resolve()` applies to
gateway *codes*, applied to the gateway *models* the payment view iterates, preserving their shape
(`->key_name`, `count()`). `WebController::checkout_payment()` consults it once, from the order's
context (total + destination country), and uses the resolved list both for the digital-payment
availability gate and for the view. With no active rule the models come back in their original order.
The `payment_gateways()` helper itself is untouched, so the API's config endpoint is unaffected.

### 2. B2B group pricing → `Cart.price` at add-to-cart

`CartManager::customerGroupPrice()` is added and called in both add-to-cart paths (physical and
digital), right after the unit/variant price is determined. A guest, or a logged-in customer in no
active group, gets the base price back — so retail checkout is unchanged. The resolved wholesale price
lands on `Cart.price`, which both web and the mobile API read, so the price flows into the order
snapshot, taxes and commission consistently across surfaces. Group pricing only ever lowers the price,
and the best (lowest) of a customer's groups wins.

### 3. Shipping zones → the web checkout shipping cost (opt-in)

`ShippingRateService::checkoutCost()` is added — the platform's existing cost when no zone serves the
destination, the zone rate when one does, `0` for a met free-over threshold. A default-off admin toggle
(`zone_wise_shipping`, on the shipping-zones page) gates it. When on,
`Customer/SystemController::insertIntoCartShipping` overrides the chosen method's cost with the zone
rate for the customer's selected address, writing to the **same** `CartShipping` row — so order totals,
tax on shipping, coupons and free-delivery are computed downstream exactly as they were.

## The honest boundaries

- **Shipping zones is web-checkout only.** The mobile API's shipping step is stateless — the
  destination `address_id` only arrives at order-placement time — so there is no bound destination to
  resolve a zone against at that step. The API keeps method-based shipping. Enabling zone shipping
  affects web orders; this is stated in code and here rather than implied.
- **Per-kg is inert.** The catalogue has no per-product `weight` column, so weight is passed as 0 and
  only a zone's flat `base_cost` and `free_over` threshold take effect. The per-kg component is built
  and tested, dormant until product weights exist.
- **Zone shipping rides the existing method step.** It overrides the cost of the method the customer
  picks; it does not (yet) replace method selection. A store using it names a generic method and relies
  on the zone rate by destination.

## Backward compatibility & data safety

No migration in this work. Every wire has a no-config fallback that reproduces today's behavior exactly:
no routing rule → gateways unchanged; no customer group → base price; toggle off (default) or no
matching zone → the existing method cost. No original function was removed — each change adds a resolver
call alongside the existing computation.

## Verification

- **12 new tests** across the three (`PaymentRoutingTest` +4 for `applyToModels`, `CartB2BPricingTest`
  +5, `ShippingZoneTest` +3 for `checkoutCost`). Full suite **645 passed, 1 skipped**.
- **Runtime verified** against live MariaDB, each with seed → assert → cleanup:
  - Payment: seeded gateways + a rule — hidden over an amount threshold, shown under it, scoped by
    country; no-rule unchanged.
  - B2B: retail/guest → base 10000; 15% group → 8500; per-product fixed override → 8000; then base
    again after cleanup.
  - Shipping: toggle round-trips through the settings cache; a Syria zone charges its base, is free over
    its threshold, and Jordan falls back to the method cost; the wired seam returns the method cost
    unchanged when the toggle is off or no address is selected. DB left pristine.
