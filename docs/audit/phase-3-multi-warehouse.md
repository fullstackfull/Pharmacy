# Phase 3 — Multi-warehouse / stock locations (Stage C)

## Why this

Measured: the platform has no notion of *where* stock physically sits — a product carries one
`current_stock` number and nothing more. A multi-location operation cannot answer "how much of this is
in Damascus vs Aleppo?" or move stock between sites. This adds a location registry and per-location
allocation.

## The design that keeps it connected

The central decision, made to avoid a disconnected parallel inventory: this is a layer **over**
`current_stock`, not a replacement. `current_stock` stays the single source of truth for sellable
quantity; warehouse allocation *partitions that same quantity* across locations, leaving an
"unallocated" remainder (`current_stock − Σ allocated`). Every operation in `WarehouseService`
preserves the sellable total:

- `place()` allocates from the unallocated remainder into a warehouse — capped by that remainder, so
  you can never allocate stock that isn't there;
- `remove()` hands it back to unallocated;
- `transfer()` relocates between two warehouses under a stable-ordered double row-lock (so opposite
  concurrent transfers can't deadlock or lose an update).

Because none of these touch `current_stock`, none of them can affect the order path that decrements
sellable stock — the multi-location view is additive visibility, not a rewrite of how selling works.

## What shipped

`warehouses` (a location registry, with one default) and `warehouse_stock` (quantity per
warehouse×product). An admin screen at `admin/marketplace/warehouses`: manage locations, and look up a
product to see its distribution — quantity per warehouse plus the unallocated remainder — with inline
forms to place stock and to transfer between warehouses.

## Backward compatibility & data safety

Two new tables (guarded `up()`, working `down()`); no original migration touched, nothing dropped.
Allocation is bounded by the unallocated remainder and transfers by the source's holding, so the
per-location figures can never exceed the real stock or drive a location negative. `current_stock` is
never written by this feature.

## The honest scope line

This gives multi-location **visibility and movement**. It does not yet make selling *fulfil from a
specific warehouse* — the order path still decrements the aggregate `current_stock` — because doing so
touches the transactional order hot path and belongs with the fulfilment/picking work, named here
rather than half-wired. Allocation is admin-driven; wiring procurement receipts to land directly in a
chosen warehouse is a clean follow-up the `place()` primitive already supports.

## Verification

- **7 feature tests** (`WarehouseTest`): unallocated = current_stock − placed, placement capped by the
  remainder, remove returning to the pool, a transfer relocating **without changing sellable stock**,
  the guards (over-transfer, same-warehouse), and the distribution breakdown. Full suite **589 passed,
  1 skipped**.
- **Runtime verified** against live MariaDB through the real HTTP stack: created two warehouses,
  placed 100 units of a real product into one and transferred 40 to the other — the per-warehouse
  figures came out 60 / 40, allocated 100 / unallocated 400, and the product's `current_stock` stayed
  **500** through both operations. Test rows removed.
