# Phase 3 — Seller Center hub (Stage A)

## Why this

Across the phase the seller-facing pieces were built one at a time — KYC verification, the performance
scorecard, ledger-based payouts, the SLA breach ledger — each on its own screen. A seller had no single
place to see where they stand. "The complete Seller Center" was the part that makes those pieces cohere
into one cockpit.

## What shipped

`SellerCenterService` and a seller page at `vendor/business-settings/seller-center`: one overview in
four cards — verification status, performance tier with headline metrics, finance (withdrawable
balance, pending, last payout), and service standing (open SLA breaches) — each linking through to its
detailed screen.

## Connected by construction — it owns no data

This is deliberately a composition, not a new store of truth. `SellerCenterService` **delegates** to
the services already built:

- verification status and the payout requirement → `SellerVerificationService`;
- the performance tier and metrics → `SellerScorecardService`;
- balances and withdrawable → `VendorLedger`;
- open breaches → the SLA ledger.

Because every figure comes from the same service its dedicated screen uses, the Center can never
disagree with them — it re-derives nothing. That is what keeps a hub from becoming a fifth, drifting
copy of the numbers.

## Backward compatibility & data safety

Read-only, and it adds **no table and no migration** — it is a page over existing services. Each
sub-service already guards its own missing tables, so the Center degrades to sensible defaults on an
install that has not adopted the newer features, rather than fataling.

## Verification

- **2 feature tests** (`SellerCenterTest`): the overview assembles all four sections and degrades to
  defaults when the newer tables are absent (nothing fatals), and a seeded SLA breach surfaces through
  the Center — proving the composition is live, not stubbed. Full suite **591 passed, 1 skipped**.
- **Runtime verified** against live MariaDB through the real HTTP stack: the page renders for a real
  authenticated seller with all four cards present and the debugbar reporting 0 exceptions.

## What this is not

It is a cockpit, not a new capability layer — the actions still live on the dedicated screens it links
to. It is the connective tissue of the Seller Center, deliberately thin, so it adds a place to stand
rather than a fifth source of numbers.
