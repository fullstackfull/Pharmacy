# Transactional order placement

This is the item I previously declined to ship, with the reasoning written down. You asked me to
decide. I re-examined it, concluded my earlier assessment was too conservative, and closed it.

## Why I changed my mind

Two of the assumptions behind the earlier decision turned out to be wrong when I looked properly:

**"It restructures the most important path in the application."** It does not. `generateOrder()`
was already written in two halves: a loop that writes order rows and *collects* notification and
mail payloads, followed by a block that fires them. Every database write is in the first half; every
side effect the database cannot undo — emails, push notifications, clearing the cart, the session —
is in the second. The transaction boundary therefore follows a seam that already existed. The diff
is an opening brace, a closing brace and a `use` clause.

**"This environment cannot exercise a real checkout."** It cannot exercise a *gateway* checkout —
there are no credentials. It can exercise cash-on-delivery, which needs no gateway, against the real
MariaDB. And the acceptance criterion I proposed myself — *two simultaneous orders for the last
unit* — is precisely what two parallel processes and a shared start barrier can produce here. I had
conflated "cannot test the payment provider" with "cannot test the fix".

## What shipped

**1. One transaction around the order writes.** A failure part-way through used to leave some stock
decremented and some order rows written, with nothing to undo either.

**2. Deterministic row locks.** Every product in the order is locked up front, ordered by id, before
any of it is written. Two carts holding the same two products in opposite orders would otherwise
each hold the row the other needs. The `attempts: 3` on the transaction is the belt to that brace.

This also fixes a bug the earlier work missed: the **variant** stock update is a read-modify-write —
it decodes `variation`, subtracts from one variant and writes the whole JSON back. That is the exact
lost-update fixed for `current_stock` in v2.4, still live on the variant path. The row lock closes
it.

**3. A conditional decrement.** `where('current_stock', '>=', $qty)` means the database itself
refuses to take stock that is not there, and an affected-row count of zero *is* the refusal.
Atomicity alone stopped the arithmetic being wrong; it never stopped stock going negative.

**4. A second race, found by running the test.** With the fix removed to prove the "before" state,
the losing order did not oversell — it died on

    SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '100002' for key 'PRIMARY'

`orders.id` is not auto-increment; `generateNewOrderID()` picks it by reading the current maximum.
Two checkouts that start together read the same number and the second insert fails — **a hard 500 at
the last step of checkout**. Locking does not help, because the row being counted may not exist yet
and an absent row cannot be locked. A bounded retry does: each attempt re-reads the id, so the loser
of the race simply takes the next number.

## The judgement call: what happens after the money is taken

Refusing an order is right when nothing has been charged. After a gateway has captured payment it is
**wrong** — the customer would be left paid with nothing to show for it, which is strictly worse than
an oversold line a human can reconcile against a real payment record.

So the policy differs by path, deliberately:

| Path | On insufficient stock |
|---|---|
| Cash on delivery, offline payment, wallet | **Reject.** Nothing charged, cart untouched, customer told. |
| After a successful gateway payment | **Allow.** The order is created; stock may go negative, and that is visible. |

The wallet path deserves a specific note: the debit used to happen *after* `generateOrder()`. A
refused order would have taken the customer's balance and given them nothing. The guard there is
placed **before** the wallet is touched.

## Verified against the running store, on MariaDB

**The acceptance criterion — two simultaneous orders for the last unit.** Two customers, two
parallel processes, a shared wall-clock start barrier, run five consecutive times:

    race 1: orders_placed=1  final_stock=0  order_rows=1  PASS
    race 2: orders_placed=1  final_stock=0  order_rows=1  PASS
    race 3: orders_placed=1  final_stock=0  order_rows=1  PASS
    race 4: orders_placed=1  final_stock=0  order_rows=1  PASS
    race 5: orders_placed=1  final_stock=0  order_rows=1  PASS

Exactly one order each time. Stock reaches 0 and never −1.

**The order-id race.** Two concurrent orders for *different* products — no shared product lock, so
nothing serialises them except the retry:

    before:  A -> 500 Duplicate entry '100002' for key 'PRIMARY'   B -> order 100002
    after:   A -> order 100003                                     B -> order 100002

**Rollback of a partly-written order.** A two-line order where the first line is in stock and the
second is not:

    orders returned : []
    stock before    : {in-stock: 10, nearly-gone: 2}
    stock after     : {in-stock: 10, nearly-gone: 2}     <- the successful line was rolled back too
    order rows      : unchanged
    cart            : still there, so the customer can adjust and retry

**The happy path.** A real cash-on-delivery order placed through `generateOrder()` end to end:
order created, stock 1 → 0, no errors. Wrapping the path in a transaction did not break it.

**Regression suite:** 389 tests, 907 assertions, all passing. `audit:ui` 0 errors.

## What this still does not cover

The concurrency evidence above comes from a purpose-built harness, not from two humans pressing
"pay". A staging run with a real gateway checkout before and after is still worth doing before this
reaches production — not because the behaviour is in doubt, but because the payment provider is the
one participant this environment has never exercised.
