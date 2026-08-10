# Phase 3 — Seller SLA policy & breach ledger (Stage E)

## Why this

The seller scorecard (built earlier this phase) computes quality metrics and a health tier, but its
thresholds are hard-coded and it is point-in-time: an operator cannot set a *policy*, and there is no
record of *when* a seller crossed a line or *for how long*. Stage E's service-level work turns the
scorecard into something enforceable: a configurable policy, evaluated into a breach ledger.

## Connected by construction

The SLA does not re-derive a single metric. `SlaService` asks `SellerScorecardService` for the
numbers — the same source the scorecard screen uses — so the policy and the scorecard can never
disagree about a seller's cancellation rate. It adds two things on top: configurable thresholds, and a
ledger.

- **Policy** — four thresholds in settings, with sensible defaults: cancellation, return and refund
  rates are ceilings; average rating is a floor, and (reusing the scorecard's own rule) only enforced
  once a seller has at least five reviews, so one angry customer cannot open a breach.
- **`breachesFor()`** — a pure function comparing a metrics array to the thresholds. Pure so the
  policy is testable without a database and any caller evaluating a hypothetical gets exactly what the
  ledger would record.
- **`evaluate()`** — pulls the scorecard, computes the breaches, and reconciles the ledger: it opens a
  row for each newly crossed line, and clears any open row the seller has recovered from. At most one
  open row exists per (seller, metric) — the row is the current state, its timestamps the history.

Each opened breach writes an audit line, so SLA events land in the same trail as the financial and
moderation actions.

## What shipped

`seller_sla_breaches` (one open row per seller-metric, cleared on recovery), `SlaService`, and an
admin screen at `admin/marketplace/sla`: configure the four thresholds, run an evaluation across
sellers, and read the breach ledger (open first, filterable). The open-breach count is surfaced as a
headline so an operator sees at a glance whether anyone is out of policy.

## Backward compatibility & data safety

One new table (guarded `up()`, working `down()`); no original migration touched, nothing dropped. The
thresholds default to reasonable values, so an install that configures nothing still evaluates
sensibly — and evaluation is on demand, changing no seller's data, only recording policy state.

## The honest scope line

`evaluateAll()` walks approved sellers in chunks — bounded, but a very large marketplace would move it
to a queued job; that is a scale change, not a correctness one, and is named in the code rather than
pretended away. The breach ledger records and surfaces policy state; automated *consequences*
(warnings, throttling, delisting) are a deliberate next step, not implied by this screen.

## Verification

- **7 feature tests** (`SlaTest`): `breachesFor()` across a crossed ceiling, an all-clear, and the
  rating floor gated by review count; the defaults; and the ledger reconcile through a stubbed
  scorecard — opening a breach idempotently (a second run adds no duplicate row) and clearing it once
  the seller recovers, plus the missing-table guard. Full suite **563 passed, 1 skipped**.
- **Runtime verified** against live MariaDB through the real HTTP stack: the SLA page renders, saving
  the four thresholds persisted them, an evaluation ran across the sellers (0 breaches for the current
  data, correctly), and the breach ledger rendered a seeded breach row with 0 exceptions. The seeded
  breach and the SLA settings were then removed, leaving the database clean.
