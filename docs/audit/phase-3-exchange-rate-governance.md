# Phase 3 — Exchange-rate governance (Stage E, multi-currency)

## Why this — and why NOT a rebuild

Measured first, and the finding shaped the work: the platform **already does multi-currency**. The
`currencies` table carries an `exchange_rate`, there is a `currency_model` setting (single/multi), and
`usd_to_currency` / `currency_to_usd` helpers convert prices throughout. Re-implementing any of that
would be the "rebuild from scratch" the phase explicitly forbids.

What the platform lacks is **governance of the rates themselves**: `exchange_rate` is a single mutable
number with no record of who changed it, when, or from what, and no way to move several at once. That
is the genuine, additive gap — so this extends the existing currencies rather than replacing them.

## What shipped

`exchange_rate_logs` — an append-only record of every rate change (currency, old → new, source, who).
`ExchangeRateService`: `updateRate()` writes the new rate onto the existing `currencies.exchange_rate`
and logs the before/after (a no-op change records nothing); `bulkUpdate()` applies many at once and
reports how many actually moved; `history()` reads the log. An admin screen at
`admin/marketplace/exchange-rates`: edit every currency's rate in one form and read the audited change
history.

## Connected, not a disconnected module

It writes through the **existing** `currencies` table — the same rate the platform's conversion
helpers already read — so a change here takes effect in every converted price immediately (the
controller flushes the config cache so the new number is live). It records to the change log and to the
unified audit log built earlier in the phase. It does not add a second rate store or a parallel
converter; conversion stays exactly where it is.

## Backward compatibility & data safety

One new table (guarded `up()`, working `down()`); no original migration touched, the `currencies`
table's shape unchanged. Conversion behaviour is identical — only the provenance of a rate change is
now recorded. A non-positive rate is refused, and an unchanged rate is a no-op, so the log never fills
with noise or an impossible value.

## Verification

- **6 feature tests** (`ExchangeRateTest`): a change writing the rate and logging old→new, a no-op
  recording nothing, the non-positive guard, the missing-currency guard, bulk update counting only the
  rates that moved, and history newest-first. Full suite **618 passed, 1 skipped**.
- **Runtime verified** against live MariaDB through the real HTTP stack: the page renders (0
  exceptions); a bulk update moved a real currency (BDT) 84 → 85.5 and recorded a log line
  (`source: bulk`, by the admin); the original rate was then restored to 84 and the test log removed,
  leaving the currencies table exactly as it was.

## The honest note on "multi-currency"

The spec item is "multi-markets / currencies / taxes." Currency conversion and tax calculation are
already the platform's (the `TaxModule` and the currency helpers); this contributes the missing
governance layer for currency. Per-market currency selection and psychological rounding rules are
further additive refinements, named here rather than implied.
