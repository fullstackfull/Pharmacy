# Phase 2.5 — Catalogue operations

## The sweep

The admin panel is the operations surface, so it was measured the same way the routes were: every
parameterless admin `GET` page (200 of them) was requested with a real authenticated admin session.

| Result | Count |
|---|---|
| 200 | 160 |
| **500** | **20** |
| 302 / 403 / 422 | 20 |

Twenty failures sounds worse than it is, and saying so is part of the finding: most are **AJAX
endpoints that require parameters**, which naturally fail when requested bare. That was not assumed
— each was re-requested with plausible parameters, and `order-statistics?type=yearEarn`,
`earning-statistics?type=yearEarn`, `products/get-variations?id=…`, `category/update?id=…`,
`pos/change-cart?cart_id=…`, `pos/change-customer?customer_id=…` and `pos/quick-view?product_id=…`
all returned 200. Those are artefacts of the sweep, not defects.

Four were real.

---

## The POS could not open a cart on a fresh login (fixed)

The worst of them, because the POS is how an over-the-counter sale is rung up.

Only `POSController@index` ever set `SessionKey::CURRENT_USER`. Every other POS endpoint assumed a
page load had already happened — which stops being true as soon as the panel's own AJAX fires after
a session expires, or an admin opens the POS by URL. Reproduced against the running panel on a
**fresh admin login**:

    GET /admin/pos/get-cart-ids   500   count(): Argument #1 ($value) must be of type
                                        Countable|array, Illuminate\Session\SessionManager given
    GET /admin/pos/quick-view     500   getCartData(): Argument #1 ($cartName) must be of type
                                        string, null given
    GET /admin/pos/new-cart-id    500   (same count() TypeError)

The cause is a PHP-level trap rather than a logic slip: **`session($key)` with a null key returns
the SessionManager itself**, not null. So `count(session(null))` is a TypeError, and elsewhere the
null flows straight into a typed parameter.

A third variant sat in the same file: `getCustomerDataFromSessionForPOS()` did
`explode('-', session(CURRENT_USER))[2]`. With the key unset, `Str::contains(null, …)` is false, so
it fell into the *saved-customer* branch and died on `Undefined array key 2`.

Fixed at the root with `CartService::ensureCartSession()`, which returns the current cart id and
creates a walk-in cart when there is none — which is precisely what a POS with no cart is. The
callers that fataled now use it, `getCartKeeper()` returns early instead of counting a
SessionManager, and the customer lookup falls back to Walk-In Customer.

**The same `CartService` backs the vendor POS**, and the same three handlers exist in
`Vendor/POS/`. Both panels were affected; both are fixed.

Verified on a fresh admin login after the change: `get-cart-ids` 200, `quick-view?product_id=10`
200, `new-cart-id` 302, `/admin/pos` 200.

---

## The reviews page's customer filter answered 500 on every keystroke (fixed)

    Call to undefined method App\Repositories\CustomerRepository::getCustomerList()

The repository method is `getCustomerNameList()`. `getCustomerList()` has never existed on it — the
working `Admin\Customer\CustomerController` calls the right name two files away. Note that the route
audit in v2.5 could not have caught this: the *controller* method exists; it is the **repository**
method behind it that does not.

Verified: the endpoint now returns the select2 payload, with and without a search term.

---

## The vendor VAT report 500'd instead of redirecting (fixed)

`vendorTax()` is a per-vendor details page whose view reads `$shop['name']` and `$shop['id']`
directly. A missing, stale or deleted `shop_id` left `$shop` null and the page fataled. It now
redirects to the vendor list. Verified: no `shop_id` → 302 to the list, `?shop_id=1` → 200, so the
working path is untouched.

There are **two** `VendorTaxReportController` classes with identical `vendorTax()` methods, under
`Api/v3/` and `Admin/Reports/`. The route uses the latter. Worth knowing before editing either.

---

## Inventory and promotions — measured, nothing to fix here

| | |
|---|---|
| Active products | 16 |
| Out of stock | 2 |
| **Negative stock** | **0** |
| Coupons | 0 |
| Flash deals | 0 |
| Refund requests | 0 |

Negative stock is zero today, which is the outcome the atomic decrement shipped in v2.4 protects.
It does not prove the remaining race is closed — that still needs the transactional order placement
recorded in `phase-2-payment-security.md`.

One data problem is visible and is the owner's to decide on rather than mine to "fix":
**product 16 carries a unit price of 555,555,555**, which reads as a data-entry slip nobody caught.
Nothing in the platform flags an implausible price. Left alone deliberately — silently rewriting a
live price is not a change code should make on its own.
