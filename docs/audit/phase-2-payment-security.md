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
