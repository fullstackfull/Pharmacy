# Phase 3 — Payment orchestration (Stage E)

## Why this

Measured: the platform has 36 payment gateways (in `addon_settings`, `settings_type = payment_config`),
each with an is_active flag — but the choice is all-or-nothing. There is no way to say "hide PayPal
over 5,000", "prefer the local gateway for domestic orders", or route by amount or country at all. That
routing layer is the gap.

## What shipped

`payment_routing_rules` — a rule targets one gateway with an action (`hide` or `prefer`) under optional
conditions (amount range, country). `PaymentRoutingService::resolve()` takes the gateways the platform
already offers plus the order context (amount, country) and returns the filtered, ordered list: hidden
gateways removed, preferred ones floated to the front by priority. An admin screen at
`admin/marketplace/payment-routing`: manage rules and a **resolution preview** that shows which
gateways an order of a given amount/country would be offered, in order.

## Non-breaking by contract

The rule that makes this safe to add to a live store: `resolve()` returns the gateways **unchanged when
no rule matches**, and a rule can only ever *hide* a gateway or *reorder* one that was already offered —
it never invents a gateway. So checkout behaves exactly as today until rules are configured. And where
one gateway is both hidden and preferred, **hide wins** (a hidden gateway is gone before ordering).

## The honest scope line

This ships the routing engine and its configuration, verified through the preview and the resolver
directly against the real gateway list. It does **not** wire the resolver into the live checkout's
gateway list in this commit — that touches the payment-selection path the storefront and apps run, the
deliberate kind of change this phase reserves for its own step. The resolver is the seam: a checkout
that passes its offered gateways through `resolve($gateways, $amount, $country)` adopts it with no
change here. It orchestrates *which* gateways and *in what order*; it does not itself move money — the
gateways still do that exactly as before.

## Backward compatibility & data safety

One new table (guarded `up()`, working `down()`); no original migration touched, the gateway config in
`addon_settings` untouched. Nothing here processes a payment or changes a gateway's own settings — it
only filters and orders a list on request.

## Verification

- **8 feature tests** (`PaymentRoutingTest`): the no-rules pass-through, hide removing a gateway, hide
  scoped by an amount range, a country condition, prefer floating a gateway to the front, prefer
  ordering by priority, **hide winning over prefer**, and a rule for an unoffered gateway doing
  nothing. Full suite **626 passed, 1 skipped**.
- **Runtime verified** against live MariaDB through the real HTTP stack: the page renders (0
  exceptions) with the real 36-gateway list feeding the picker; a "hide PayPal over 5,000" rule
  resolved a 6,000 order to `[stripe, razor_pay]` (PayPal gone) and a 100 order to
  `[paypal, stripe, razor_pay]` (PayPal still offered below the threshold). The test rule was removed.
