# Phase 2.6 — Storefront performance

Measured on the running store before anything changed, and again after. The frontend is
intentionally legacy (Vue 2 / Bootstrap 4 / Laravel Mix 5), so nothing here rewrites the pipeline —
these are the wins available without touching it.

## Measured baseline (homepage)

| | |
|---|---|
| HTML | 370 KB |
| Assets | **4.18 MB across 59 files** (excluding images) |
| Stylesheets | 26, of which **21 render-blocking** |
| Scripts | 39, **0 with defer or async** |
| Requests (browser, all types) | 127 |

Largest single files:

    1,392 KB  firebase.min.js          <- 33% of all asset weight
      372 KB  theme.css
      358 KB  swiper-bundle.min.js
      251 KB  intl-tel-input/utils.js
      249 KB  uicons-regular-rounded.css
      249 KB  uicons-solid-rounded.css
      220 KB  style.css

## What shipped

### The 1.39 MB Firebase bundle no longer blocks the page

It was loaded **synchronously in every storefront page**, and alongside it three more copies of the
same SDK from `gstatic.com`:

```html
<script src=".../firebase.min.js"></script>                       <!-- 8.3.2, 1.39 MB, local -->
<script src="https://www.gstatic.com/firebasejs/8.3.2/firebase-app.js"></script>
<script src="https://www.gstatic.com/firebasejs/8.3.2/firebase-auth.js"></script>
<script src="https://www.gstatic.com/firebasejs/8.3.2/firebase-messaging.js"></script>
```

**The three CDN scripts are redundant.** Verified in a real browser, with `gstatic.com`
unreachable in this environment — zero requests to it — and yet `firebase.SDK_VERSION` reported
`8.3.2` with `firebase.auth` and `firebase.messaging` both present and one app initialised. The
local bundle already carries app, auth and messaging. Removed from all five files that loaded them
(both storefront themes, admin, vendor, and the vendor forgot-password page).

That also removes a dependency on Google being reachable, which is not a safe assumption for this
store's customers.

**The rest is now deferred.** The four remaining Firebase scripts carry `defer`, and the inline
blocks that call into them were moved inside a `DOMContentLoaded` listener — deferred scripts all
execute, in order, before that event, so the ordering is guaranteed rather than hoped for.

Measured effect, three runs each, median, cache cold:

| | before | after |
|---|---|---|
| **DOMContentLoaded** | **26,209 ms** | **13,765 ms** |
| requests | 127 | 125 |

DOMContentLoaded roughly halved. Push notifications still work: `firebase.apps.length === 1` after
the change, same as before.

### 500 KB of icon CSS no longer blocks the first paint

`uicons-regular-rounded.css` and `uicons-solid-rounded.css` are 249 KB each and define **7,867
classes between them**. Measured across the storefront's home, product-list, login and contact
pages, **two** are used: `fi-rr-phone-call` and `fi-sr-bars-filter`.

Subsetting was rejected as too fragile — a class added later in a view, or built by script, would
silently lose its icon. Instead both are loaded with the standard `media="print"` swap so they are
fetched at low priority and applied on load, with a `<noscript>` fallback that keeps them blocking
where the swap cannot run. Verified in the browser: the phone icon renders with
`font-family: uicons-regular-rounded` and its `::before` glyph set.

### `.env.example` shipped a real APP_KEY and APP_DEBUG=true

Found while tracing why Debugbar was rendering into the storefront. The committed example file
carried

    APP_KEY=base64:1vaU3dfc+sWjx8TuDXzginRsEa2dp2SBL+Ujs6QCb5c=
    APP_DEBUG=true

That key is identical in every copy of this platform, so anyone holding it can forge session
cookies and decrypt anything encrypted with it — and an install copied straight from the example
inherits it silently. `APP_KEY` is now empty with an instruction to run `key:generate`, and
`APP_DEBUG` defaults to `false`.

(Debugbar itself was rendering only because this local `.env` has `APP_DEBUG=true`, which is correct
for a development copy. It is not a production defect — but the example file that invites the same
setting in production is.)

## Honest note on the timing numbers

First Contentful Paint did not move: 13,440 ms before, 13,368 ms after. That is **not** evidence the
CSS change failed — it is evidence that FCP here is dominated by the PHP CLI development server,
which routes all 125 requests, static files included, through a 4-worker PHP process pool. The
byte-level and blocking-level improvements are real and deterministic; the wall-clock ones need
nginx and php-fpm to be visible. DOMContentLoaded moved because that one is gated by script
parse-and-execute rather than by request queueing.

## Measured, not yet done

* **intl-tel-input: 355 KB of JS** (`utils.js` 251 KB + `intlTelInput.js` 104 KB) loads on every
  storefront page, for a phone field that appears on a handful of them.
* **`swiper-bundle.min.js`, 358 KB**, loads everywhere; the homepage genuinely uses it, most pages
  do not.
* **`owl.carousel.min.css` and `home.css` are each linked twice** on the homepage — the layout
  includes them once and the page pushes them again.
* **`font-awesome.min.css` points its font files at `stackpath.bootstrapcdn.com`**, with only a
  local `.woff` fallback that does not exist — a guaranteed 404 per page load whenever that CDN is
  unreachable, which for this store's customers it may often be. Fixing it properly means vendoring
  the font files.
