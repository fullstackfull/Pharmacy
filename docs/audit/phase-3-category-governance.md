# Phase 3 — Per-category governance (spec item 10, Stage A)

## Why this

The spec asks, under Stage A, that a category carry its own operational policy: a return window, a
required-attribute set, a tax class, and a moderation rule — because "cosmetics and electronics do
not share a return policy." Everything the store enforces today is one global number: a single
`refund_day_limit` for the entire catalogue, no per-category required fields, no per-category tax
class, no way to force a category through moderation. This is the item that makes categories
operational rather than merely a menu tree.

## What the platform had

Measured before building. `categories` carries name, slug, icon, parent and `position` — and nothing
operational. The refund window lives in `business_settings` as `refund_day_limit`, read in exactly
one place that matters for buyers: the API's refund-eligibility check
(`RestAPI/v1/OrderController@getRefundStatus`), which compared `refund_started_at` against that one
global number. There was no category-scoped policy of any kind.

## What shipped

`category_governance` — one row per category, **every field nullable**. A category with no row, or a
null field on one, inherits the global default. The consequence that matters: installing this and
configuring nothing changes no behaviour anywhere. Adoption is one category at a time.

Fields: `return_window_days` (null = inherit, **0 = no returns** — deliberately distinct), a JSON
`required_attributes` set, a free-text `tax_class` label the tax engine can key on when it exists,
`requires_moderation` (connects to the product moderation shipped alongside this), and a
`shipping_restricted` flag plus note for the shipping engine to act on later. Commission is
**deliberately not** duplicated here — the commission engine already resolves a category-scoped rule,
so governance points at that rather than storing a second rate that could drift from it.

`CategoryGovernanceService` — the one resolver. `returnWindowDays()` falls back to the global when
the category sets none; `isWithinReturnWindow()` centralises the refund-window arithmetic so the API
and any storefront path enforce the same rule instead of each re-deriving it;
`missingRequiredAttributes()` is the gate a moderator or a save-time check uses to say "this category
needs a strength and a volume before it can go live."

An admin screen at `admin/marketplace/category-governance`: one accordion row per top-level category,
each field blank-means-inherit, with the global default shown inline so the operator knows what a
blank will do. Saving writes an audit-log line.

## Connected, not a disconnected module

The return window is the proof. `RestAPI/v1/OrderController@getRefundStatus` no longer reads the
global limit directly — it derives the line's `category_id` from the stored `product_details` and
asks `CategoryGovernanceService::isWithinReturnWindow(...)`. For every ungoverned category that
resolves to the same global number as before, so the API contract and the Flutter apps see identical
behaviour; where a category tightens (or loosens) the window, the refund gate honours it. The change
is a condition swap inside the existing method — no function added or removed, no field renamed.

## Backward compatibility & data safety

Additive migration only (new table, guarded `up()`, working `down()`), no original migration touched,
nothing dropped or renamed. Null-inherit means zero behavioural change on an unconfigured install.
The `0 = no returns` case is tested explicitly so a caller can never misread it as "unset."

## Verification

- **13 feature tests** (`CategoryGovernanceTest`) — inherit vs override, the 0-vs-null distinction,
  the window actually gating a refund (four days is fine store-wide but past a two-day category
  window), forced moderation, missing-attribute reporting, and the tax class. Full suite **510
  passed, 1 skipped** — no regression.
- **Runtime verified** against the live local MariaDB through the real HTTP stack: authenticated as
  admin, the page renders (200); a POST save persisted every field exactly
  (`return_window_days=5`, `tax_class=zero_rated`, `required_attributes=["strength","volume"]`,
  `requires_moderation`, `shipping_restricted`, `restriction_note`, `updated_by=1`); the round-trip
  re-render showed the saved values; the test row was then removed to leave the database clean.

## What this is not

It is not the tax engine and not the shipping engine — it holds the `tax_class` label and the
`shipping_restricted` flag those engines will read, without pretending to be them. It stores policy;
enforcement beyond the refund window (which is wired) arrives with those stages.
