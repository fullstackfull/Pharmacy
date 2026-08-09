# Phase 2.4 — Retention

Measured on the running store before anything was written, as with 2.1–2.3.

## What the store actually looks like

| | |
|---|---|
| Orders | **1** |
| Carts | 5 |
| Wishlist rows | **0** |
| Reviews | **0** |
| Active products | 16, across 4 categories |
| Abandoned-cart feature | **none** — the string "abandon" appears nowhere in `app/`, `Modules/`, `database/` or `routes/` |

This is a pre-launch store. It changes the honest answer for two of the four items below, and both
are stated rather than papered over.

---

## Abandoned-cart recovery — built

Completely absent, and the highest-value retention mechanism for a store about to open. Shipped end
to end: selection service, console command, mailable, bilingual RTL-aware template, signed recovery
link, conversion attribution, admin settings page.

**It ships switched off**, and that is a design decision rather than a default. This feature puts
mail in front of real people; nothing in an upgrade should start doing that on its own.

### The selection is where the difficulty is

Every mistake here lands in somebody's inbox and cannot be recalled, so the rules are conservative
and the tests are mostly assertions that *nothing* is selected:

1. Idle long enough — a cart touched five minutes ago is being used, not abandoned.
2. Not older than the window — past it the message is noise and the prices are stale.
3. Has a real recipient — a registered customer's account email, or (opt-in) a guest's checkout email.
4. **No order from that customer since the cart was last touched.** `orders` carries no
   `cart_group_id`, so a group cannot be matched to an order directly; it carries `customer_id` and
   `created_at`, which answers the question that matters. Emailing "you forgot something" to
   somebody who has just paid is the worst thing this feature can do.
5. Not already reminded at this stage — the unique key on `(cart_group_id, stage)` is what makes a
   repeated cron a no-op instead of a second email.
6. Still buyable — a cart of deactivated products is an invitation to a dead page.

### Guests are a separate decision, also off

A registered customer has an account relationship with the store. A guest who typed an email into a
checkout form to receive an order confirmation has not asked to be marketed to, and in several
jurisdictions that is the line between a transactional message and an unsolicited one. So guest
reminders are their own switch, and it starts off.

### Verified against the running store

Widening the window to cover all five real carts selected **1**, and each of the four exclusions was
traced to a specific rule: three guest carts had no address row at all (nobody to write to), and one
cart was newer than the idle threshold. No exclusion was unexplained.

The admin page was loaded as an authenticated admin: it renders, its link appears in the Business
Setup menu from *other* settings pages (so it is reachable, not dangling), an inverted window
(stop-after ≤ idle) is refused with the settings left untouched, and a valid update persists all
five values.

### Two bugs found by rendering the message for real

`Mail::fake()` never renders the view, so every send test would have passed with a template that
throws. Rendering it against the live configuration found:

* **`company_web_logo` is an array** (`{key, path, status}`), not a string. Concatenating it into a
  path is `Array to string conversion`, which takes the whole message down. This is a live bug in
  6Valley's own `customer-registration.blade.php`, which does exactly that.
* **This store registers Arabic under the code `sy`, not `ar`.** A hardcoded
  `in_array($lang, ['ar', ...])` check — which is what the first draft had — would have laid the
  entire Arabic message out left-to-right. The direction now comes from the store's own `language`
  setting, which records `direction` per language. Verified by making Arabic the default and
  watching the output become `<html lang="sy" dir="rtl">`.

---

## Reviews — three real defects, fixed

### Anyone could review anything

Neither endpoint verified the purchase. `product_id` and `order_id` arrived from the client and were
written straight to the row, so **any signed-in customer could post a review for any product,
attributed to any order id** — including an order that was never theirs. On a pharmacy catalogue
that is not cosmetic: ratings are what customers use to choose between medicines.

Both the web form and `POST /api/v1/products/reviews/submit` now go through one eligibility check:
the order must belong to the customer, and the product must be a line on it. It deliberately does
**not** require a particular delivery status — when a store opens reviews is a policy decision, and
inventing a status rule could silently stop legitimate customers reviewing.

### Two IDOR writes on the update paths

* **Web:** `$review` was looked up scoped to the signed-in customer, but the update was then issued
  against `$request['review_id']` *regardless of whether that lookup found anything*. Sending
  somebody else's review id rewrote their review and reassigned it to the sender.
* **API:** `Review::find($request['id'])` was unscoped outright — any authenticated customer could
  rewrite any review in the store, and a missing id fataled the request on the first property
  assignment.

### Ratings were unbounded

`'rating' => 'required'` accepts `"abc"` and `999`. One such row corrupts every average computed
from the table. Now `integer|min:1|max:5` on both paths.

Response shapes are unchanged on the API — only the failure cases differ, and they use the same
`{"message": ...}` shape the file already returns elsewhere.

---

## Wishlist — checked, sound, unchanged

Both `storeWishlist` and `deleteWishlist` scope every read and write by
`customer_id = auth('customer')->id()`. There is no cross-account path. **No change made** — this
is recorded as a result so the next person does not have to re-derive it.

---

## Recommendations — deliberately not built

Related products today are "same category, no ordering, limit 12". The obvious upgrades are
collaborative filtering ("customers who bought this also bought") and content-based ranking.

**Neither can work on this data, and building one would be theatre:**

* Collaborative filtering needs order history. There is **one order**. It would return nothing.
* Content-based ranking needs something to rank. The largest category holds **9 products**, so the
  existing "same category, limit 12" already returns the entire category. There is no selection left
  to make.

What would make it worth building: a catalogue in the hundreds and real order history — enough
co-purchase data that "also bought" produces a different answer than "same category". Until then the
existing block is already showing everything there is to show, and a recommender would add
machinery, queries and a maintenance surface in exchange for the same output.

Recorded here rather than marked done, because a feature that cannot function on the store's data is
not a completed feature.

---

## Not resolved here

The Passport OAuth keys were absent in this environment, which made **every `auth:api` route return
500 instead of 401**. Generated locally (`php artisan passport:keys`; `storage/*.key` is gitignored,
and nothing key-shaped is committed). If the production install shows the same symptom on API
routes, that is the cause — but it is an environment condition, not a code defect, and it is not
something this branch can fix for you.
