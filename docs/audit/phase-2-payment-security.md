# P0 — Stripe payment settlement bypass (Phase 2, area 46/47)

**Severity: critical (free goods / direct revenue loss).** Fixed in v2.0.

## The defect
`StripePaymentController::success()` took two **attacker-controlled query parameters**:

```
/payment/stripe/success?payment_session_id=<session>&payment_id=<payment>
```

It retrieved `payment_session_id` from Stripe, confirmed *that session* was paid — and then marked
whatever record `payment_id` pointed at as paid. **Nothing tied the verified session to the payment
being settled.**

So the gateway call looked like proper verification while verifying the wrong object.

## Exploit
1. Attacker makes one genuine small payment (say $1) and keeps the completed session id.
2. Attacker places an expensive order ($5,000); its payment record is created unpaid with id `X`.
3. Attacker opens `/payment/stripe/success?payment_session_id=<the $1 session>&payment_id=X`.
4. The $1 session verifies as `paid` + `complete` → the $5,000 payment is marked paid, the success
   hook fires, and the order proceeds to fulfilment.

The same session is reusable indefinitely: **one $1 payment could settle unlimited orders.**

Re-opening the success URL also re-ran the success hook, duplicating order/stock/wallet side
effects.

## The fix
`sessionBelongsToPayment()` now gates settlement on three independent checks:

| Check | Blocks |
|---|---|
| `metadata.payment_id` (set at session creation) matches the payment record | replaying a session against a different payment |
| `amount_total` equals the payment's expected amount, in minor units | settling an expensive order with a cheap session |
| `currency` matches the payment's currency | cross-currency mismatch |

Plus an **idempotency guard**: an already-paid payment returns success immediately without re-running
the success hook.

### Backward compatibility
Sessions created *before* this deploy carry no metadata. Those are still accepted **when the amount
matches**, so checkouts already in flight are not broken — while the amount check still blocks the
actual attack. No API contract, request/response shape or DB column changed; the Flutter apps are
unaffected.

## Tests
`tests/Unit/StripeSessionBindingTest.php` (8) — matching session accepted; cheap-session-settles-
expensive-payment rejected; session bound to another payment rejected; currency mismatch rejected;
legacy no-metadata session accepted on matching amount but rejected on wrong amount; exact cent
rounding; case-insensitive currency.

## Still to verify live (not possible in this session)
- A real Stripe sandbox checkout end-to-end (create → pay → success → order placed).
- Confirm the success hook fires exactly once when the success URL is reloaded.

## Related findings (audited, no defect)
The other 12 gateways do verify against the gateway before settling: PayPal captures and checks
`COMPLETED`; SslCommerz, Paytm, RazorPay, Paytabs, LiqPay, Paymob, Paystack, Flutterwave, bKash,
SenangPay and MercadoPago all make a verification call or validate a signature/checksum. **They were
not audited for the same session↔payment binding weakness** — that is the recommended next P0 sweep,
since the pattern (`payment_id` from the query string) is shared across the family.

---

## Update — 2026-08-09: the same flaw was in two more gateways

The Stripe fix documented above was the first instance of a pattern, not an isolated bug. Auditing
the other gateways for the same shape found it in both.

### RazorPay — worse than Stripe, and unauthenticated in effect

`RazorPayController@callback` **never contacted RazorPay at all**:

```php
$payment_data = $this->payment::where(['id' => $request->payment_request_id])->first();
if (count($input) && $payment_data && !empty($input['razorpay_payment_id'])) {
    $payment_data->is_paid = 1;     // no verification of anything
    $payment_data->save();
```

`POST payment/razor-pay/callback` carries only the `web` middleware, so any logged-in customer —
a CSRF token comes free with any page — could post their own pending order id with an arbitrary
`razorpay_payment_id` and be marked paid **without paying anything**. No amount check, no binding,
no `is_paid` guard, so it also worked repeatedly.

`RazorPayController@payment` had the same exposure with an extra twist: it captured
`$payment['amount'] - $payment['fee']` — whatever the fetched RazorPay payment was worth, not what
the order cost.

`createOrder` took `payment_amount` and `currency_code` **straight from the request body**, so a
caller could create a ₹1 RazorPay order against a ₹10,000 checkout and pay it for real.

**Fixed:** amount and currency now come from the stored record; the RazorPay order carries
`notes.payment_request_id`; and both settlement paths verify the RazorPay signature when present,
confirm the payment is captured/authorised, match amount and currency in minor units, and refuse an
already-paid record. Fails closed.

### bKash — real payment, wrong order

`BkashPaymentController@callback` did call the gateway and checked `statusCode == '0000'`, which
proves *a* payment happened — not that the right one happened for the right order. With `paymentID`
and `payment_id` both caller-supplied and no amount or `is_paid` check, a genuine small payment
could settle any other order, repeatedly.

**Fixed:** the executed payment's amount and currency must match the record, in minor units, and an
already-paid record is refused.

### Coverage

Nine regression tests in `tests/Feature/PaymentSettlementGuardTest.php` cover the attacks directly:
cheap-payment-settles-expensive-order, currency mismatch, replay against an already-paid record,
missing amount, near-miss amounts, and fail-closed when the gateway is unreachable.

**Still unverified by me:** no live payment was exercised — this environment has no gateway
credentials. The guards are unit-tested and the surrounding pages are runtime-verified, but a real
end-to-end payment on staging should confirm legitimate checkouts still complete before this
reaches production.

---

## Order-time inventory — 2026-08-09

Two defects on the path between "customer presses pay" and "order exists".

### Lost updates on stock (fixed)

The decrement wrote a computed absolute value:

```php
Product::where(['id' => $product['id']])->update([
    'current_stock' => $product['current_stock'] - $cartSingleItem['quantity']
]);
```

`current_stock` came from a model read earlier in the request, so two overlapping orders both read
the same number and both wrote their own result — losing one decrement outright. Demonstrated
against the running app:

    stock 10, two orders of 3 each  ->  final stock 7   (should be 4)

This is not a narrow race window. It fires whenever two orders overlap, and it inflates inventory
in the store's favour, so the catalogue keeps advertising stock that does not exist.

**Fixed** with `->decrement('current_stock', $qty)`, which issues
`SET current_stock = current_stock - N` and is resolved per statement by the database. Verified:
the same sequence now ends at 4.

### The remaining gap — NOT fixed, and it needs a decision

`generateOrder()` **is not wrapped in a transaction**, and `product_stock_check()` runs *before* it
rather than as part of it. So:

  * Between the check passing and the decrement landing, another order can consume the same stock.
    The atomic decrement stops the arithmetic from being wrong, but it does not stop stock going
    negative — nothing refuses the write.
  * If anything throws midway through order generation, some stock is already decremented and some
    order rows written, with no rollback.

Closing this properly means wrapping order generation in a transaction and taking row locks
(`lockForUpdate`) on the products being ordered, or making the decrement conditional
(`where('current_stock', '>=', $qty)`) and aborting the order when it affects no rows.

**I did not make that change**, and the reason is worth stating plainly rather than burying: it
restructures the single most important path in the application, and this environment cannot
exercise a real checkout end to end — there are no gateway credentials, and the flow needs
addresses, shipping methods and a payment provider. Shipping an unverified rewrite of order
placement is a worse risk than the race it fixes.

**Recommendation:** do it as its own change, on staging, with a real checkout exercised before and
after, and with a concurrency test (two simultaneous orders for the last unit) as the acceptance
criterion.

### Null variation crashed the checkout (fixed)

`count(json_decode($product->variation))` is a TypeError on PHP 8 when `variation` is null — the
same shape as the product-save crash fixed in Phase 1. Because `product_stock_check()` runs
immediately before order placement, such a product did not block checkout, it **500'd** it, leaving
the customer stuck at the final step.
