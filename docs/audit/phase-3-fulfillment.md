# Phase 3 — Order fulfilment: pick / pack / ship (Stage C)

## Why this

Measured: an order walks pending → confirmed → processing → out_for_delivery → delivered. The
warehouse work in the middle — pick the items, pack them, stage them for dispatch — has no
representation at all. "processing" is one opaque state: no pick list, no packed marker, no record of
which location fulfilled it. This adds that layer.

## What shipped

`order_fulfillments` — a fulfilment record per order (per seller for a split order) with its own
`pending → picking → packed → ready → shipped` lifecycle, optionally tied to the warehouse it is
picked from. `FulfillmentService` owns a **forward-only** state machine, and an admin screen at
`admin/marketplace/fulfillments`: open a fulfilment, advance it a step at a time (with carrier +
tracking captured at ship), and cancel.

## An overlay — it never touches order status

The design decision that keeps this safe: the fulfilment is an **overlay**. It writes only to its own
table and **never** writes `orders.order_status`, so it enriches the middle of the flow without
altering the state machine the storefront and the Flutter apps already depend on. Runtime proof:
driving a real order's fulfilment all the way to `shipped` left that order's `order_status` untouched
at `pending`.

The state machine is forward-only: `advance()` refuses to move backward or repeat a state, stamps
`packed_at` and `shipped_at` as those points are reached, and once shipped or canceled the fulfilment
is closed. Only one open fulfilment can exist per order+seller, so the workflow can't fork.

## Connected, not a disconnected module

It ties the two ends the phase already built: it references an `order` (the demand) and a `warehouse`
(the v3.13 location it is picked from), and when it reaches `shipped` it carries the carrier and
tracking the existing delivery flow can pick up. It is the warehouse-side step that was missing
between "confirmed" and "out for delivery", not a parallel order system.

## Backward compatibility & data safety

One new table (guarded `up()`, working `down()`); no original migration touched, nothing dropped. Order
status is never written, so the existing order lifecycle — and everything that keys on it — is
unchanged.

## The honest scope line

This models the fulfilment as one unit per order+seller with a status and timestamps; a line-level
pick list (tick each item as picked) is a natural refinement the record is shaped for but does not yet
include. Assignment is a single `assigned_to` field; a full picker roster belongs with the seller
staff/permissions work.

## Verification

- **6 feature tests** (`FulfillmentTest`): open creating a pending fulfilment, the one-open-per-order
  guard, moving forward through the flow with the packed/shipped timestamps stamped, the forward-only
  rule (skip-forward allowed, backward and repeat refused), a shipped fulfilment being closed, and
  cancel closing it so a fresh one may open. Full suite **597 passed, 1 skipped**.
- **Runtime verified** against live MariaDB through the real HTTP stack: opened a fulfilment for a real
  order and advanced it picking → packed → ready → shipped (carrier + tracking captured); the packed
  and shipped timestamps were set and — the property that matters — the order's `order_status` stayed
  `pending` throughout. The test row was removed.
