# Phase 3 — Batch & expiry tracking (Stage C)

## Why this

Measured: a product has one `current_stock` number and no notion of *when* any of it expires. For a
catalogue carrying perishable or dated goods that is a blind spot — nothing answers "what is expiring
this month?", and expired stock is written off, if at all, by editing the stock field with no reason.
This adds dated batches and makes expiry visible and actionable.

## What shipped

`product_batches` — a batch records that a quantity of a product carries a given expiry.
`BatchService` around it: `addBatch()`, `expiringSoon($days)`, `expired()`, and `writeOff()`. An admin
screen at `admin/marketplace/batches`: record a batch, see the expiring-soon and expired counts,
filter the list, and write off expired stock. Expired active batches are highlighted in the table, and
expiry is derived from the date, so a batch reads as expired even if no sweep has run.

## Deliberately a tracking overlay

Batches are decoupled from `current_stock` — that stays the single source of truth for sellable
quantity, so recording a batch changes no selling behaviour and the two can be adopted independently.
This is the honest, bounded choice: full per-batch stock allocation (FEFO consumption on every sale)
would rewrite the stock model and touch the order hot path, and is named here as a later step rather
than half-built.

## Connected, not a disconnected module

The one action that changes sellable quantity — writing off an expired batch — is **not** a second,
silent path that mutates `current_stock`. It is delegated to `InventoryService::adjust()` with reason
`expiry`, so the removal is a reasoned, row-locked, logged movement exactly like any other stock
change, and it shows up in the same movement history as receipts, adjustments and returns. Runtime
proof: writing off a 20-unit expired batch moved a real product 500 → 480 and left an `expiry`
movement (`balance_after` 480); the batch was marked depleted. If the write-off would drive stock
negative (the batch claims more than is on hand), the adjustment refuses it and the batch is left
intact to retry after a recount.

## Backward compatibility & data safety

One new table (guarded `up()`, working `down()`); no original migration touched, nothing dropped.
Recording a batch never changes stock; the only stock change is the write-off, which goes through the
guarded adjustment path and so can never make stock negative.

## Verification

- **7 feature tests** (`BatchExpiryTest`): adding a batch not touching stock, `expiringSoon` catching
  near-and-past but not far-future dates, `expired` catching only past dates, write-off reducing stock
  **and logging an `expiry` movement** and depleting the batch, the negative-floor refusal leaving the
  batch intact, the already-depleted guard, and the derived `isExpired`. Full suite **582 passed, 1
  skipped**.
- **Runtime verified** against live MariaDB through the real HTTP stack: the page renders (0
  exceptions), adding a batch left stock unchanged (500), and writing it off moved stock 500 → 480 with
  an `expiry` movement logged and the batch marked depleted. Test rows removed and stock restored.
