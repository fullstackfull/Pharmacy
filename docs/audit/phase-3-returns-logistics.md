# Phase 3 — Returns logistics / RMA (Stage C)

## Why this

The platform had a refund flow and, from Stage B, a *financial* reversal (the commission reversal),
but nothing physical. Measured: when a refund was approved the money was handled and the goods were
simply gone from the books — no return authorisation, no shipment tracking, and, tellingly, **no
restock anywhere**. An approved return left the product's sellable stock one unit short, permanently.
This adds the physical half and closes that gap.

## What shipped

`return_shipments` — an RMA per return, walking `authorized → in_transit → received`, and on receipt
either `restocked` (goods sellable again) or `rejected` (damaged/unsellable: received so the return
closes, but not restocked). `ReturnLogisticsService` owns the state machine and the restock.

An admin screen at `admin/marketplace/returns`: authorise a return (against an order and/or a
product, with a restock flag), advance it through transit (carrier + tracking), and receive it —
restocking on receipt when the flag is set.

## Connected, not a disconnected module

Two connections make this real rather than a parallel record:

1. **It restocks through the inventory movement log.** Receiving a restockable return increments
   `products.current_stock` **under a row lock** and records a `return` movement — the movement type
   that was *reserved in the v3.8 inventory work precisely for this*. So a return shows up in the same
   stock history as a purchase receipt and a manual adjustment, referenced back to the RMA. Runtime
   proof: receiving a 4-unit return moved a real product 500 → 504 and logged a `return` movement
   (`balance_after` 504, ref `return_shipment`).
2. **It links to the refund, it doesn't duplicate it.** The RMA carries `refund_request_id`, so the
   physical and financial sides of one return can be seen together — while the commission reversal
   stays where it already lives, in the refund path. No double-counting.

## Backward compatibility & data safety

One new table (guarded `up()`, working `down()`); no original migration touched, nothing dropped.
Restock only ever *adds* to stock and only on an explicit receipt, so it cannot interfere with the
sell-side decrement. An unsellable return is a first-class case — received and closed without a
restock — so the log never claims stock that did not come back.

## The honest scope line

This is the admin-driven RMA flow. It starts an RMA at `authorized` (admin-initiated); a
customer-initiated `requested → authorized` step, and auto-creating an RMA from an approved refund,
are named hooks for later, not pretended to exist here. Multi-line returns are modelled one RMA per
line rather than a basket — simpler, and sufficient for the flow.

## Verification

- **7 feature tests** (`ReturnLogisticsTest`): the state machine (authorize → in_transit, the
  in-transit guard), receiving a restockable return putting stock back **and logging a `return`
  movement** with the right reference, an unsellable return received but not restocked, a
  no-product return received without restock, and the guards (no double-receive, reject closes).
  Full suite **570 passed, 1 skipped**.
- **Runtime verified** against live MariaDB through the real HTTP stack: authorised a return for a
  real product, marked it in transit (carrier + tracking), and received it — the RMA reached
  `restocked`, the product's stock went 500 → 504, and a `return` movement was logged against the
  RMA. All rows removed and the stock restored to 500.
