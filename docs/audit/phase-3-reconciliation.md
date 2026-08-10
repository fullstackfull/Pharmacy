# Phase 3 — Financial reconciliation (Stage E)

## Why this

Stage B built a financial core — a running-balance vendor ledger, immutable commission snapshots, and
settlements that roll up ledger entries. Those pieces hold invariants (a running balance equals the
sum of its entries; a settlement equals the entries it claimed), but nothing *checks* them. If a
future change ever maintained a balance wrongly, or recorded a commission charge without its snapshot,
it would go unnoticed until the numbers were paid out. This adds the monitor.

## What shipped

`ReconciliationService` — read-only checks over the Stage B core, each returning its scope, how many
things it examined, and a list of discrepancies (empty when everything reconciles):

1. **Ledger running-balance integrity** (per seller) — the last entry's `balance_after` must equal the
   independent sum of `credit − debit` across all the seller's entries. This is the core invariant of
   the running-balance ledger.
2. **Commission snapshot vs ledger** — the total of the `order_item_commissions` snapshots must equal
   the total of the ledger's `commission_charge` debits, because each order line writes both.
3. **Settlement integrity** (per settlement) — the entries a settlement claimed (via `settlement_id`)
   must sum to its stored `net_amount`, and their count must match `entry_count`.

An admin report at `admin/marketplace/reconciliation` renders the result — a green headline when
everything reconciles, and a per-check drill-down (reference, expected, actual, delta) for anything
that does not.

## Connected, not a disconnected module

This does not invent its own numbers — it audits the exact tables and invariants the Stage B services
maintain (`vendor_ledger_entries`, `order_item_commissions`, `vendor_settlements`). It is the
counterpart to that work: the code that would catch it the moment it drifts. A green report is the
expected, valuable result — an integrity monitor earns its keep by being green until the day it isn't.

## Backward compatibility & data safety

Read-only: the service performs no insert, update or delete, and the feature adds **no migration** and
no schema change. Every comparison is derived in PHP from portable aggregate queries, so it runs
identically on MariaDB and the SQLite test database, and a missing table degrades to "nothing to
reconcile" rather than a fatal.

## Verification

- **5 feature tests** (`ReconciliationTest`): a consistent core reconciles clean, and each check
  actually catches its drift — a corrupted `balance_after`, a commission charge with no snapshot, and
  a settlement whose entries do not sum to its net — plus the missing-table guard. Full suite
  **575 passed, 1 skipped**.
- **Runtime verified** against live MariaDB through the real HTTP stack: the report renders (0
  exceptions) and the checks execute against the real financial tables, reporting clean. Detection was
  then demonstrated on the real database engine: seeded a consistent seller and a seller with a
  deliberately corrupted running balance — the check examined both and flagged exactly the corrupted
  one (expected 40, actual 999). The seeded rows were removed.
