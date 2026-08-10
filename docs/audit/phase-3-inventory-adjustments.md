# Phase 3 — Inventory adjustments & movement log (Stage C)

## Why this

`products.current_stock` is a single number with no history. Measured: it is written from ~10 places
(orders, POS, the API, product edits) and, tellingly, an admin can change it straight on the product
form with **no reason and no trail** — so "why is this 480 and not 500?" cannot be answered, and a
miscount, a breakage and a theft all look identical to a sale. Stage C's inventory work starts by
giving stock changes a reason and a record.

## What shipped

`stock_movements` — an append-only log, each row carrying the signed change, the resulting balance, a
reason, an optional reference, and who made it. `InventoryService` around it:

- `adjust()` — the reasoned alternative to editing the stock field: it changes `current_stock`
  **inside a transaction with the product row locked**, refuses to drive stock negative, refuses a
  zero no-op, and writes a movement (type `adjustment`) plus an audit line. A magnitude-plus-direction
  form on the admin side becomes a signed delta at the service boundary.
- `record()` — the append-only primitive other paths call to log a change they have already applied.
  It is **non-throwing**: a missing history line must never roll back the stock change it describes.

An admin screen at `admin/marketplace/inventory-adjustments`: apply an adjustment (product, direction,
quantity, reason, note) and read the movement history, filterable by product and type.

## Connected, not a disconnected module

The log is not fed only by its own screen. The procurement service built alongside this now calls
`InventoryService::record()` on every receipt, so a purchase-order receipt appears in the same history
as a manual adjustment — with a reference back to the PO. Runtime-verified: receiving 8 units of a
product logged a `receipt` movement (`balance_after` 518, ref `purchase_order#2`) in the same table an
adjustment writes to. Two supply-side paths, one auditable stock history.

## The honest scope line

This captures the two write paths this phase owns and can touch safely — manual adjustments and PO
receipts. It deliberately does **not** retrofit the ~10 sale-side writers (the order path, POS, the
API, product edits) in one move; doing so touches the transactional order hot path and is a change
that deserves its own careful pass. So the log is complete for *reasoned supply-side* changes today,
not yet a total stock ledger — stated here rather than implied by the screen. The `sale`, `return`
and `transfer` movement types exist so those paths slot in without a schema change when they are
wired.

## Backward compatibility & data safety

One new table (guarded `up()`, working `down()`); no original migration touched, nothing dropped. The
receipt wiring is a single non-throwing call added inside the existing receive method — no function
added or removed, and it cannot fail a receipt. Adjustments refuse to make stock negative, so the log
can never describe an impossible state.

## Verification

- **8 feature tests** (`InventoryAdjustmentTest`): an increase and a decrease moving real stock and
  logging the signed change with its balance, the guards (negative-floor, zero, missing product), the
  movement inheriting the product's seller, and `record()` appending — and never throwing when the
  table is absent. The procurement suite still passes with the receipt wiring in place. Full suite
  **556 passed, 1 skipped**.
- **Runtime verified** against live MariaDB through the real HTTP stack: the adjustments page renders,
  a +10 `found` adjustment on a real product moved its stock 500 → 510 and logged a movement
  (balance_after 510, by the admin); a PO receipt then logged a `receipt` movement (→ 518) into the
  same history. All test rows were removed and the product's stock restored to 500.
