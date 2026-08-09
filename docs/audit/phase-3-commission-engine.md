# Phase 3, Stage B — Commission engine

Measured before designing, as in every phase.

## What the platform did

`Helpers::seller_sales_commission()` in full:

```php
$seller = Seller::find($seller_id);
$commission = $seller['sales_commission_percentage'] ?? getWebConfig('sales_commission');
$commissionAmount = number_format(($order_total / 100) * $commission, 2);
```

One percentage. No category rate, no product override, no fixed fee, no campaign rate, no date
window. And the result was stored as a **single aggregate** on `orders.admin_commission`:
`order_details` has no commission column at all.

Three consequences, and the spec names all three as critical:

1. **No snapshot.** Change a seller's rate and there is no record of what old orders were charged
   under. The aggregate survives; *why* it was that number does not.
2. **No per-line figure.** An order split across sellers carries one number for the whole thing.
3. **Refunds cannot be netted.** Refund one line of five and its commission cannot be subtracted,
   because that line's commission was never a separate figure.

## What shipped

### Rules instead of a column

`commission_rules` supports global, category, vendor and product scopes; percentage, fixed, or
percentage-plus-fixed; optional min/max guard rails; and a date window so a campaign rate starts and
ends without anyone editing a record.

**Priority is explicit and deterministic** — two rules can never both win:

    product (400) > vendor (300) > category (200) > global (100)

Ties break by newest. The priority is *stored* rather than derived from the scope, which is what
lets a launch campaign deliberately outrank a product override — tested.

Two guard rails are policy, not features: a commission can never exceed the line it is charged on
(a misconfigured 500% rate would otherwise make a seller owe money for having made a sale), and an
in-house line carries none, because the marketplace does not pay itself.

### Backward compatibility is the default, not an option

With **no rules configured**, the engine falls back to exactly what the platform does today — the
seller's own percentage, else the global setting. Installing the migration and doing nothing changes
no figure anywhere. That is deliberate: the engine has to be adoptable one rule at a time.

### The snapshot

`order_item_commissions` is written once per order line, inside the order transaction, and never
recalculated. It carries the rule id, its scope, its label, the rate type, the percentage, the fixed
amount, the amount it was applied to, the commission, and the seller's net — everything needed to
re-check the arithmetic years later without reconstructing prices or rules.

The unique key on `order_details_id` makes it idempotent, so the order transaction retrying on a
deadlock or an id collision cannot double-charge commission. It is wrapped whole: marketplace
accounting must never be the reason an order fails.

## Verified against the running store

**Connected, not a disconnected module.** A real cash-on-delivery order, 2 units at 200, seller-owned
product in a category carrying a 12.5% + 2 rule:

    order_id 100002 | rule: category "cosmetics category rate" | percentage_plus_fixed 12.5% + 2
    commissionable 400.00  ->  commission 52.00  ->  seller net 348.00

(400 × 12.5%) + 2 = 52. The snapshot was written by order generation itself, not by a separate step.

**Immutability — the property the spec calls critical.** The live rule was then changed to 40% flat:

    before rule change:  commission 52.00  percentage 12.5  fixed 2.0  "cosmetics category rate"
    after  rule change:  commission 52.00  percentage 12.5  fixed 2.0  "cosmetics category rate"
    live rule now:       40%  "rate raised later"

The historical order did not move.

**18 engine tests**, covering each rate type, the full priority chain, scope isolation, explicit
priority override, the date window, active/inactive, both guard rails, the in-house case and both
legacy fallbacks. Suite: 407 tests, 942 assertions.

## What this deliberately does not do yet

This is the foundation the rest of Stage B stands on, not the whole of it. Still to build, in the
order the spec suggests and the dependencies require:

* **Vendor ledger.** `seller_wallets` is today five mutable decimals (`total_earning`, `withdrawn`,
  `commission_given`, `pending_withdraw`, …) updated with `->update()`, and `seller_wallet_histories`
  is a flat log with no running balance and no transaction type — not the ledger the spec describes.
  Every financial event needs its own transaction with a balance-after, and the pending / available /
  reserved split needs to be real rather than implied.
* **Settlement engine**, which reads the snapshots above rather than re-deriving anything.
* **Reversal on refund.** The `reversed_amount` column exists and is honoured by
  `OrderItemCommission::getNetCommissionAttribute()`, but nothing writes to it yet — the refund path
  has not been wired in. Stated here rather than left to be discovered.
