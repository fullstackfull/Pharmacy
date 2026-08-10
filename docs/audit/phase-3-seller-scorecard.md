# Phase 3 — Seller performance scorecard (Stage A)

## Why this

The platform reports on seller *earnings* and *tax* — there are vendor-earning and vendor-tax reports
already — but nowhere on seller *quality*. There is no view of fulfilment, cancellations, returns,
refunds, rating or moderation strikes, and so no way to tell a healthy seller from one the store
should watch. Stage A asks for the Seller Center; a performance scorecard is the part of it that
turns the data the rest of the phase produces into a signal.

## Connected by construction

This item deliberately adds **no table**. It is a read model computed from what already exists, which
is the point: it demonstrates the phase's data is now integrated. Every number maps to a measured
column, not an invented one —

- **orders** by the platform's own `order_status` vocabulary (`delivered` / `canceled` / `returned`
  / `failed`), scoped to `seller_is = 'seller'` so in-house orders are not counted against a seller;
- **refund rate** from `order_details.refund_request`;
- **rating** from active `reviews` (`status = 1`) attributed to the seller through their product
  (`products.user_id`);
- **moderation strikes** from the `product_moderation_events` trail built earlier this stage
  (rejections and suspensions);
- **KYC standing** from `SellerVerificationService`, built alongside this.

`deriveTier()` turns those into a health tier — good / watch / at_risk — and is a **pure function**,
so the SLA, disputes and featured-placement work later in the phase can consume the same tier this
screen shows and get the same answer. That is what keeps it a shared signal rather than a vanity
page.

## An honest 'new'

A seller with no orders, no reviews and no strikes is `new`, never `good` or `at_risk`: judging a
seller who has not traded yet would be noise. And one bad review does not sink a store — the rating
only counts toward the tier once there are at least five, so a single angry customer cannot flip a
new seller to at-risk.

## What shipped

`SellerScorecardService` (compute-on-read, every query guarded so a partial install yields zero
rather than a 500). An **admin screen** at `admin/marketplace/seller-scorecard`: the seller list with
each seller's tier and metrics, computed for the current page only — bounded work per request. A
**seller screen** at `vendor/business-settings/seller-scorecard`: the same numbers for the
authenticated seller, as metric tiles with their health tier.

## The one honest limit

The admin list computes the scorecard for the sellers on the current page. That bounds the work per
request, but it means there is no server-side "show me only at-risk sellers" filter yet — a
cross-page filter would need the tier stored. A refreshed `seller_scorecards` snapshot table is the
scale answer (and the enabler of that filter); it is a performance optimisation, not a correctness
one, and is named here rather than pretended away.

## Backward compatibility & data safety

Read-only: the service performs no insert, update or delete, and the feature adds no migration —
nothing in the schema or existing behaviour changes. Two new read-only screens and their menu links,
nothing more.

## Verification

- **12 feature tests** (`SellerScorecardTest`): `deriveTier()` across new / good / watch / at_risk
  including the two guards (a fresh seller is `new`; three-too-few reviews cannot trigger at-risk on
  rating), and `scorecard()` computing each metric from a seeded dataset — the status vocabulary
  (with an in-house order correctly excluded), the refund rate, review attribution through products,
  and strikes counting only negative outcomes. Full suite **539 passed, 1 skipped**.
- **Runtime verified** against live MariaDB through the real HTTP stack: the admin scorecard renders
  for an authenticated admin and the seller scorecard for a real authenticated seller, both with the
  debugbar reporting 0 exceptions.
