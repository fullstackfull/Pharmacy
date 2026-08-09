# Phase 3 — B2B / wholesale price lists (Stage E)

## Why this

Measured: the platform has no notion of a customer *segment*. Every buyer sees the same price, `users`
carries no group or type, and there is no wholesale, VIP or contract tier. B2B needs exactly that — a
group a buyer belongs to, carrying pricing that differs from retail.

## What shipped

Three additive tables — `customer_groups` (a segment with a default discount), a
`customer_group_customer` pivot (membership, without touching the `users` table), and
`customer_group_prices` (a per-group, per-product override: a fixed price or a discount). And
`B2BPricingService`, the resolver. An admin screen at `admin/marketplace/customer-groups`: manage
groups, their members and per-product prices, and a **price preview** that shows what a given
product/customer/base resolves to.

## Non-breaking by contract

The rule that makes this safe to add to a live store: `priceFor()` returns the **base price unchanged**
for a customer in no group, or a group with no rule for the product. So a retail buyer — and any
checkout that has not adopted this — sees exactly today's price. Group pricing only ever *lowers* the
effective price, and where a customer belongs to several groups the **best (lowest)** price wins.

The resolution ladder per group is explicit: a fixed `price` override, else a per-product
`discount_percent`, else the group's `default_discount_percent`, applied to the base.

## The honest scope line

This ships the pricing engine and its management, verified through the preview and the resolver. It
does **not** wire group pricing into live cart/checkout in this commit — that touches the price the
storefront and apps compute, the deliberate-not-incidental kind of change this phase reserves for its
own step. The resolver is the seam; a checkout that calls `priceFor(product, customer, basePrice)` and
uses the result adopts it without any change here. RFQ (request-for-quote) is a related B2B capability
named for later, not implied.

## A bug the live verification caught

Running the flow through the real HTTP stack surfaced a 500 the unit tests could not: submitting a
per-product price with only a discount (no fixed price) threw "Undefined array key `price`", because
Laravel's validated array omits an absent nullable field. Fixed by coalescing before the comparison —
exactly the kind of defect that only appears when the real request shape hits the controller, which is
why every feature here is exercised end to end, not just unit-tested.

## Backward compatibility & data safety

Three new tables (guarded `up()`, working `down()`); no original migration touched, the `users` table
untouched (membership is a pivot), and no product's own price is ever written. The base price is the
fallback everywhere.

## Verification

- **7 feature tests** (`B2BPricingTest`): the base-price fallback for a customer in no group, a group
  default discount, a per-product discount overriding it, a fixed price winning over any discount, the
  best-price-across-groups rule, an inactive group being ignored, and a zero base returned untouched.
  Full suite **612 passed, 1 skipped**.
- **Runtime verified** against live MariaDB through the real HTTP stack: created a wholesale group,
  added a real customer, set a 30%-off per-product price — the resolver returned **70** off a base of
  100 for the member (`source: discount`) and **100** (`source: base`) for a non-member, i.e. the
  non-breaking fallback. The 500 above was found and fixed here. Test rows removed.
