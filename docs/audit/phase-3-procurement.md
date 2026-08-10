# Phase 3 — Procurement: suppliers & purchase orders (Stage C)

## Why this

The platform models the sell side in depth — sellers, products, orders, the financial core built
earlier this phase — but has **no supply side**. There is no supplier, no purchase order, no
receiving; `products.current_stock` is a single number that only ever goes *down* as things sell.
Stage C opens with the missing half: where inventory comes from, and how it gets back into stock.

## What the platform had

Measured: no supplier / purchase-order / procurement / warehouse / inventory table exists anywhere in
the schema. A product carries `current_stock`, `user_id` (its seller) and `code`; the only thing that
moves stock is the order path, which decrements it. Replenishment was invisible.

## What shipped

Three additive tables and one service:

- `suppliers` — a supplier owned by whoever procures from it: `seller_id` null is a platform/admin
  supplier, a value scopes it to one seller, so the same records serve in-house replenishment and a
  seller's own procurement without either seeing the other's.
- `purchase_orders` + `purchase_order_items` — a header with a status that walks
  draft → ordered → partially_received → received (or canceled), and lines tracking `qty_received`
  against `qty_ordered`.
- `PurchaseOrderService` — the state machine plus the one behaviour that makes this the supply side:
  **receiving a line increments `products.current_stock`** — the same stock the storefront sells and
  the order path decrements.

## Connected, not a disconnected module

Receiving is the connection. It runs **inside a transaction with the product row locked** before the
increment, so a receipt and a concurrent order (or a second receipt) serialise rather than losing an
update — the exact discipline the transactional order path already uses. The header status is
*derived* from the lines, so it can never disagree with them, and a line can never be received beyond
what was ordered. Every create / place / receive / cancel writes an audit-log line, so procurement
shows up in the same trail as the financial actions.

A line may point at a catalogue product (then its stock moves) or carry only a description (a
purchase for something not yet catalogued) — the quantity is tracked either way, and a description
line receives without touching any stock row.

## Backward compatibility & data safety

Three new tables, each guarded with a working `down()`; no original migration touched, nothing
dropped or renamed. Receiving only ever *adds* to stock, so it cannot interfere with the sell-side
decrement path — the two move the same number from opposite directions. Deleting a supplier that has
orders is refused (it is deactivated instead) so a financial record is never orphaned.

## The honest scope line

This ships the **admin / in-house** procurement flow end to end. The service is written seller-aware
(`seller_id` throughout), so a seller-facing procurement screen is a thin follow-up rather than a
rebuild — but it is not in this commit, and is named here rather than implied. The PO total mirrors
its line subtotal; per-order tax and shipping are a later refinement, not pretended to exist.

## Verification

- **9 feature tests** (`PurchaseOrderTest`): totals on create, the draft→placed→received transitions,
  the derived status (partial vs full), receiving incrementing product stock, the guards
  (over-receiving refused, empty order not placeable, received order not cancelable, a draft not
  receivable), receive-all, and a no-product line receiving without touching stock. Full suite
  **548 passed, 1 skipped**.
- **Runtime verified** against live MariaDB through the real HTTP stack: authenticated as admin,
  created a supplier, created a purchase order for a real product (id 3, stock 500), placed it and
  received all — the order reached `received` and the product's stock went **500 → 525**. All test
  rows were removed and the product's stock restored, leaving the database clean.
