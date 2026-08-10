# Phase 3 — Shipping zones (Stage C)

## Why this

Measured: the platform's shipping is flat or category-based — `shipping_methods.cost` is one number,
`category_shipping_costs` is per-category, and **nothing is aware of where the parcel is going or how
much it weighs**. A real logistics operation prices by destination and weight; there was no way to
express "coastal governorates cost 5, the interior 8, free over 100."

## What shipped

`shipping_zones` — a zone matches a set of countries (and optionally regions) and carries a rate rule:
a flat `base_cost`, a `per_kg_cost`, and an optional `free_over` value. `ShippingRateService` resolves
a destination to its zone and computes the cost. An admin screen at `admin/marketplace/shipping-zones`:
manage zones and a **rate preview** that shows what a given destination, weight and order value
resolves to.

Resolution has a clear precedence: a country-specific zone beats a catch-all (a zone with an empty
country list is the "rest of world"), the lowest `priority` breaks ties, and a region-scoped zone only
matches its listed regions.

## Non-breaking by contract

The design decision that keeps this safe to add to a live store: `rateFor()` returns **null when no
zone matches**. A caller that has adopted zone rating uses the resolved cost; where nothing matches, it
falls straight back to the platform's existing flat shipping. So installing this and configuring a few
zones changes nothing for destinations they don't cover, and nothing at all until the checkout is
wired to consult it.

## The honest scope line

This ships the **rate engine and its configuration**, verified through the admin preview and the
resolver directly. It does **not** wire zone rates into live checkout in this commit — that touches the
shipping-cost calculation the storefront and apps already run, and is the kind of hot-path change this
phase has been careful to do deliberately, not as a side effect. The resolver is the seam; adopting it
at checkout (zone rate when one matches, existing flat cost otherwise) is the named next step. One rate
rule per zone keeps it bounded; multiple named methods per zone (standard/express) and live carrier
APIs are later refinements — the latter also gated by the environment's outbound network policy.

## Backward compatibility & data safety

One new table (guarded `up()`, working `down()`); no original migration touched, nothing dropped. The
existing flat shipping is untouched and remains the fallback. Nothing here writes to an order.

## Verification

- **8 feature tests** (`ShippingZoneTest`): a country-specific zone beating a catch-all, the catch-all
  serving otherwise-unmatched countries, the **null-fallback** for a destination with no zone,
  priority breaking ties, a region-scoped zone matching only its regions, the base+per-kg rate, the
  free-over threshold, and an inactive zone being ignored. Full suite **605 passed, 1 skipped**.
- **Runtime verified** against live MariaDB through the real HTTP stack: the page renders (0
  exceptions), a created zone resolved as expected — Syria at 3 kg / value 40 → **11** (5 + 2×3), the
  same over the free-over threshold → **free**, and an unserved country (Jordan) → **null**, i.e. the
  flat-shipping fallback. The test zone was removed.
