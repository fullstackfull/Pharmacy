# Phase 2 — Plan

Phase 1 passed its gate (`phase-1-completion-report.md`). Phase 2 is the customer-facing half:
storefront experience, search and discovery, product page, cart and checkout, retention, catalogue
operations, and performance.

This plan is written **after** measuring the running store, not before, so the ordering reflects
what is actually broken rather than what sounds important.

---

## The finding that sets the order

The store's catalogue is bilingual: every product carries an Arabic name in `translations`. Search
is a `LIKE '%term%'` over the raw string. Run against the real catalogue:

| Customer types | Results |
|---|---|
| `حمايه` (as the merchant happened to type it) | 2 |
| **`حماية`** (the standard spelling, with taa marbuta) | **0** |
| `سيروم` | 2 |

A customer searching for sun protection with the ordinary spelling of the word finds **nothing**,
while the product sits in the catalogue. This is not a hypothetical: it is this store's data, today.
Arabic has several such pairs that users type interchangeably — ا/أ/إ/آ, ة/ه, ي/ى, and optional
diacritics — and a raw `LIKE` treats every one as a different letter.

Three further defects in the same query path:

1. **No index is usable.** A leading `%` wildcard forces a full table scan on every search.
2. **Whole rows are loaded to collect IDs** — `->get()->pluck('id')` in
   `ProductRepository::getListWhere()` hydrates every matching Product model just to read its
   primary key.
3. **No relevance ordering.** A term matching a product's name ranks the same as one matching a
   stray substring, so results arrive in arbitrary order.

Search is therefore Phase 2's first item: it is measurable, it is revenue-bearing, and it is
demonstrably broken for the store's primary language.

---

## Order of work

Each item ships only when it is implemented, integrated, tested, runtime-verified and
backward-compatible — the same gate Phase 1 was held to.

### 2.1 Search & discovery *(first)*
- Arabic normalisation on both sides of the comparison (alef forms, taa marbuta, alef maqsura,
  tatweel, diacritics), so `حماية` finds `حمايه` and vice versa.
- A normalised, indexable search column so the query stops scanning the table.
- Relevance ordering: exact match, then prefix, then contains; name over description.
- Remove the load-whole-rows-to-get-ids pattern.
- Zero-result handling that suggests something instead of a dead end.
- **Compatibility:** the existing search endpoints and their response shapes stay untouched, so the
  Flutter apps are unaffected.

### 2.2 Product detail page
Gallery, variant selection that cannot desync from stock, delivery/return information, and the
structured data already built in Phase 1.6 wired to the page.

### 2.3 Cart & checkout
The highest-value flow in the application and the one Phase 1 deliberately did not touch. Includes
the Stripe settlement bypass already documented in `phase-2-payment-security.md`, which is a real
vulnerability awaiting this phase.

### 2.4 Retention
Abandoned cart, wishlist, recommendations, reviews — in that order, because abandoned cart recovers
revenue already earned.

### 2.5 Catalogue operations
Inventory, orders, returns, promotions.

### 2.6 Performance
The storefront asset pipeline, measured in Phase 1 and deferred to here by scope: ~5 MB and 87–126
requests per page, 20 render-blocking stylesheets, a 1.4 MB Firebase bundle. Phase 1 shipped the
image-level fixes; the pipeline is the remaining gain.

---

## Constraints carried over from Phase 1

- **Never touch a production database.** All work is verified against the local copy.
- **Additive migrations only**, every one `hasTable`-guarded with a working `down()`.
- **No breaking change to any API contract** — the Flutter apps must keep working.
- **Bilingual AR/EN** for every string that reaches a user.
- Root causes, not symptoms; regression tests with each change; a browser check for anything visual.
