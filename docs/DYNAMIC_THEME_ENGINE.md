# Dynamic Theme Engine

Server-driven UI for the storefront home page: one published theme, composed in the Admin Theme
Builder, rendered by both the web storefront and the Flutter customer app — with layout, ordering,
content, scheduling and targeting changes reaching installed apps **without an app-store release**.

This document covers the whole pipeline: what existed before, what was added, the API contract,
the Flutter engine, compatibility rules, and how to extend each layer.

---

## 1. Architecture

```
                         ADMIN PANEL
                    /admin/theme (builder)
                             │
              ┌──────────────┼──────────────┐
        Design tokens   Page structure   Section settings
        (ThemeManager)  (ThemeSection)   (SectionRegistry schema)
              └──────────────┼──────────────┘
                             │  publish (atomic, stamped)
                             ▼
                  ThemeVersion  status=published
                  revision=N  checksum=sha256/32
                             │
            ┌────────────────┴───────────────────┐
            │                                    │
   StorefrontThemeRenderer                 ThemeDelivery
   (web: cached structure,           (app: per-client payload —
    per-request visibility)           visibility + capability +
            │                         breakpoint + actions)
            ▼                                    │
  resources/views/theme-sections/*        GET /api/v1/theme/home
  (blade renderers)                       GET /api/v1/theme/version
                                                 │
                                                 ▼
                                     Flutter DynamicThemeController
                                     → SectionRendererRegistry
                                     → per-type SectionRenderer widgets
```

**Shared schema, separate renderers.** Both clients consume the same published `ThemeVersion` →
`ThemeSection` → `ThemeBlock` structure and the same resolved settings. Only the rendering
implementation differs.

### What already existed (and was extended, not replaced)

| Layer | Where | Status |
|---|---|---|
| Theme / version / section / block models + migrations | `app/Models/Theme*`, `database/migrations/2026_08_08_*` | extended |
| Section catalogue + settings schemas (39 section types, 18 block types) | `app/Services/Theme/SectionRegistry` | untouched |
| Data resolution (products, categories, vendors, banners…) | `app/Services/Theme/SectionDataResolver` | untouched |
| Draft / publish / restore lifecycle | `app/Services/Theme/ThemeManager` | extended |
| Visual builder (drag-drop, pickers, media, preview) | `ThemeBuilderController` + `admin-views/theme/builder` | untouched |
| Web renderer | `resources/views/theme-sections/*` | extended (visibility) |
| Unversioned app endpoint | `GET /api/v1/theme/sections` | kept, refactored onto shared `ThemeSourceMap` |

### What was added

**Backend** (`app/Services/Theme/`):

| Class | Responsibility |
|---|---|
| `ViewerContext` | who is being served: platform, device, auth state, declared capabilities |
| `SectionVisibility` | the one visibility rule: hide switches + schedule + platform/audience targeting |
| `ComponentCapabilityRegistry` | which section types the app can draw, at which component/engine version |
| `ActionResolver` | storefront URL → typed action (`product`/`category`/`vendor`/`collection`/…) |
| `ThemeSourceMap` | section type + settings → the v1 endpoint that feeds it (shared with `/theme/sections`) |
| `ThemeDelivery` | assembles the negotiated per-client payload; revision/checksum identity; caching |

**Flutter** (`lib/features/dynamic_theme/`):

| Piece | Responsibility |
|---|---|
| `domain/models/theme_schema.dart` | total, never-throwing parser for the payload |
| `domain/repositories/` + `domain/services/` | fetch + Drift cache (the app's existing response store) |
| `controllers/dynamic_theme_controller.dart` | stale-while-revalidate sync loop |
| `engine/capability_registry.dart` | the capability list sent as `X-UI-Components` / `X-UI-Engine` |
| `engine/section_renderer_registry.dart` | type → renderer lookup; unknown types skipped + recorded |
| `engine/theme_action_resolver.dart` | typed action → `RouterHelper` navigation |
| `engine/theme_style.dart` | design tokens → Flutter, with the app theme as fallback |
| `renderers/*.dart` | 6 renderer families covering 24 section types |
| `screens/home_host_screen.dart` | published theme vs built-in home decision + resume re-sync |
| `screens/dynamic_home_view.dart` | ordered render + per-section error isolation + diagnostics |

---

## 2. Database

Two additive migrations. Both are guarded (`hasTable`/`hasColumn`), nullable-only, and reversible;
existing rows behave identically until a merchant sets a rule.

`2026_08_30_000001_add_delivery_rules_to_theme_sections`:

| Column | Type | Meaning |
|---|---|---|
| `uuid` | uuid, indexed | identity that survives version duplication (client state keys on it) |
| `starts_at`, `ends_at` | timestamp | server-side schedule window |
| `platforms` | json | subset of `web`,`app`,`desktop`,`tablet`,`mobile`; NULL = everywhere |
| `audience` | json | subset of `guest`,`customer`; NULL = everyone |

`2026_08_30_000002_add_revision_identity_to_theme_versions`:

| Column | Type | Meaning |
|---|---|---|
| `revision` | unsigned int | monotonic per theme, +1 per publish; already-published rows backfilled to 1 |
| `checksum` | string(64) | hash of the stored structure; unchanged content republished keeps it |

Copy semantics: `ThemeManager::createDraftFrom` carries `uuid` + delivery rules (same logical
section); `ThemeBuilderService::duplicateSection` carries the rules but mints a new `uuid` (new
section). Both are column-guarded so mid-migration databases and hand-built test schemas still work.

---

## 3. API contract

All under the existing `throttle:60,1` group. Public, like the storefront they mirror.

### `GET /api/v1/theme/version` — the cheap sync check

```json
{ "revision": 42, "checksum": "ab12…", "schema_version": 1, "engine_version": 1,
  "published_at": "2026-08-30T10:00:00+00:00" }
```

* `revision: 0` → nothing published; render the built-in home and stop asking this session.
* Clients refetch when `revision` **or** `checksum` differs from what they hold (a rollback
  publishes older content under a *higher* revision — comparing only "is newer" would miss it).

### `GET /api/v1/theme/home` — the negotiated page

Query: `page` (`home`|`header`|`footer`), `device` (`desktop`|`tablet`|`mobile`).

Request headers:

| Header | Meaning |
|---|---|
| `X-UI-Components` | comma list of section types this build renders |
| `X-UI-Engine` | renderer generation compiled into the build |
| `If-None-Match` | last held checksum → `304` with no body when current |

A client that declares nothing receives **every app-safe section** — builds predating capability
reporting keep working.

Response:

```json
{
  "page": "home",
  "revision": 42, "schema_version": 1, "engine_version": 1,
  "checksum": "…", "published_at": "…",
  "tokens": { "colors": {…}, "typography": {…}, "layout": {…}, "branding": {…} },
  "sections": [
    {
      "uuid": "…", "id": 7, "type": "product_slider",
      "settings": { "title": "…", "limit": 10, "columns": 2, "action": {…}, … },
      "blocks": [ { "id": 1, "type": "slide", "settings": {…} } ],
      "cards": [ { "image": "https://…", "title": "…", "action": { "type": "product", "slug": "…" } } ],
      "source": { "kind": "api", "endpoint": "/api/v1/products/featured", "params": { "limit": 10, "offset": 1 } }
    }
  ],
  "compatibility": { "delivered": 9, "withheld": { "custom_html": "arbitrary_markup_is_not_rendered_natively" } }
}
```

Shaping applied per client:

* **Visibility** — hide switches, schedule (server clock), platform/audience rules.
* **Capability** — only types this build can draw; the rest named in `compatibility.withheld`.
* **Breakpoint** — `*_mobile` / `*_tablet` overrides collapsed onto their base keys.
* **Images** — root-relative paths absolutized.
* **Actions** — every `link` gains a typed `action` sibling (see §5). The link itself is untouched.
* **`cards`** — banner-backed blocks merged live with their Promotion → Banners row, identical to
  the web (same `SectionDataResolver::blockCards`).
* **`source`** — where the section's *data* lives: `inline` / `api` (+endpoint+params) / `none`
  (+why). Overfetching is avoided by design: the theme payload never embeds product lists.

The ETag is computed over the **delivered** payload, so two clients with different capabilities
never share a validator.

### `GET /api/v1/theme/sections` — the original unversioned endpoint

Unchanged contract, now backed by the shared `ThemeSourceMap` so it cannot drift from
`/theme/home`. Prefer `/theme/home` for new integrations.

---

## 4. Sync & caching

**Server**: `ThemeDelivery` caches the revision answer and each per-client payload for 600s. The
cache key embeds `revision` + a capability fingerprint, so a publish strands all stale entries at
once; `ThemeManager::publish()` stamps `revision`/`checksum` **inside** the publish transaction and
flushes both the storefront cache and the delivery cache.

**Flutter** (`DynamicThemeController.sync()`):

```
cold start / resume / pull-to-refresh
  → render Drift-cached payload immediately (never a blank page)
  → GET /theme/version                    (one small request)
  → unchanged? stop.  changed?
  → GET /theme/home  (If-None-Match: held checksum)
  → 304? stop.  200? validate → render → cache
network failed at any step → keep what is on screen
server says "nothing published"/empty → drop to the built-in home
```

The cache is the app's existing Drift `cache_response` store, keyed
`/api/v1/theme/home::home` — no fifth persistence mechanism.

---

## 5. Actions & deep links

Merchants type storefront URLs; `ActionResolver` parses them **once, server-side** into a closed
vocabulary: `none · product · category · brand · vendor · campaign · search · cart · wishlist ·
collection · url`. Slugged pages map by path (`/product/{slug}`…), list pages map to named
collections (`/best-selling-products` → `collection:best_selling`), other hosts stay `url`.

The web keeps navigating by the original `link`. Flutter's `ThemeActionResolver` maps each type to
the app's own `RouterHelper` route; unknown future types fall back to the carried `url`; an action
with nowhere to go renders as not-tappable. **No executable code ever travels** — an action is a
type plus scalar parameters.

**A filtered catalogue is a subject, not a collection.** The banner form stores a category link as
the storefront URL that shows it — `/products?category_id=44&data_from=category` — so read
literally it is the whole catalogue, and a banner a merchant pointed at one category opened the
entire product list in the app while the web opened the category. `ActionResolver` reads
`?category_id=` / `?brand_id=` on `/products` as `category` / `brand`, and fills in what the link
did not spell: a slug link gains its id (the app's list screen opens on an id and has no slug
index), an id link gains the name the screen titles itself with. Both lookups are cached per
request and can fail without costing the action.

**Dashboard banners carry the same action.** `/api/v1/banners`, `/banners/home-promos` and
`/banners/category-sections` now return `action` beside `url`, so a banner placed in Banner Setup
and a banner placed by the theme builder are the same tap in the app — the app no longer works the
destination out from `resource_type` against whichever list it happened to have cached, which is
why a category or shop banner so often did nothing at all.

### Links arriving from outside the app

The same principle applies to a link the phone receives — a universal link, a pasted URL, a poster
QR code. The app does not parse the shop's URL structure; it asks:

```
GET /api/v1/deep-link/resolve?url=…
  → {resolved, target, parameter, subject, path, web_url, attribution, campaign, reason}
```

`target` is the same closed vocabulary (`home · product · brand · product_list · order_tracking ·
web`). `subject` carries what the app's screen actually opens with where a slug is not enough —
today the brand's `{id, name}`, because the app's brand screen opens by id and has no slug index.
A campaign short link (`/go/{code}`) is followed server-side to its destination, its click is
counted against the campaign (surface `app`), and its attribution rides back with the answer: this
is the only way a campaign tap that opens the app is counted at all.

Every request the app makes now carries `X-Platform` and `X-App-Version`. Three systems were
waiting on exactly those two headers: Monitoring's Android/iOS panels and its per-version traffic
and error tables (`requests.by_app_version`), the Analytics visitor context (which records the
session's app version and separates app traffic from server-to-server integrations), and the
Developer Portal's endpoint-health check, which cannot call a deprecation safe while it has no way
to tell which release is calling.

App side: both platforms have Flutter deep linking enabled, so the engine hands the link to
`RouterHelper`, and each published path (`/brand/:slug`, `/products`, `/track-order/:orderId`,
`/go/:code`) routes to `DeepLinkGatewayScreen`, which resolves and replaces itself with the answer.
A link opened cold always leaves the home screen underneath, so the first back press stays in the
app. `DeepLinkResolver.readLocally` reads the published paths offline when the shop cannot be
reached; a short link it cannot resolve lands on home rather than on a guess.
`android/app/src/main/AndroidManifest.xml`'s path list and `config/deeplinks.php` mirror each
other — `GET /api/v1/deep-link/config` publishes the shop's list so the app team reads it from the
shop rather than from a message.

### Banner clicks (Analytics)

Nothing counted whether a banner worked. On the web the server sees the page a banner led to and
nothing saying a banner led there; in the app the tap is not a navigation at all. So both clients
report it, and neither can double-count the other because neither is the server:

* **Web** — `data-analytics="banner_clicked"` on the banner anchors, read by the storefront beacon's
  existing delegated handler. A banner click is a navigation, so it flushes immediately rather than
  waiting for the 1.2s batch timer.
* **App** — `POST /api/v1/analytics/events`, public and rate limited for the same reason the beacon
  is: a banner is shown to guests, and an endpoint that counted only signed-in shoppers would report
  a biased subset while looking complete.

Both doors share one payload rule set (`ClientEventIngest`): only allow-listed names, nothing
money-related ever accepted from a client, ids coerced to digits, everything else dropped, and 204
to everything so a prober learns nothing. Theme-builder cards carry `banner_id` so a click on a
builder-placed banner counts against the same Banner Setup row the merchant edits; a card typed
straight into the builder has no banner row and reports nothing rather than a wrong attribution.

The daily rollup adds a `banner` dimension, and Analytics → Catalogue shows the table, labelled by
placement ("Main Banner", "Home Promo Banner") because the banner form has no title field and a
column of blank names would be useless.

### The mobile image

A picture that reads on a wide storefront is often unreadable once a phone draws it, so three
things carry an optional second image: **banners** (`banners.mobile_photo`, since v16.2),
**categories** (`categories.mobile_icon`) and **brands** (`brands.mobile_image`). The rules are
identical in all three, because a merchant who learns the field on one screen expects it on the
next:

* Empty means "use the web image" — the fallback lives in the model accessor
  (`mobile_photo_full_url` / `mobile_icon_full_url` / `mobile_image_full_url`), never in a client,
  so an app can read the mobile key unconditionally and never draw a blank tile.
* Uploading replaces, and deletes the file it replaced.
* It can be taken away again. An upload field can only replace, so removal is an explicit
  checkbox (`remove_mobile_image`) — without it, a mobile image once uploaded was permanent.
  `App\Traits\ManagesOptionalImage` holds the rule once for all three services.

Delivered to the apps through the model's `$appends` (so every catalogue endpoint carries it at
once) and, for theme sections, as `image_mobile` — including `dashboardBanners()`, which used to
drop it, so a `store_banner` section drew the wide web image on phones no matter what the merchant
uploaded.

### Crash-free rate (Monitoring)

A crash produces no request, so the app reports what only it knows. `AppHealthReporter` chains
`PlatformDispatcher.onError` (never `FlutterError.onError` — a render overflow is not a crash),
persists a capped counter, and the **next** launch posts `{platform, app_version, sessions,
crashes, anrs}` to `POST /api/v1/app-health`, clearing the counter only after the server's 204.
Counters only: no stack traces, no device or user ids leave the phone.

---

## 6. Compatibility model

Three numbers plus a list, negotiated per request — never `appVersion >= x`:

| Concept | Server | Client |
|---|---|---|
| Schema version | `ComponentCapabilityRegistry::SCHEMA_VERSION` | `ThemeCapabilities.schemaVersion` |
| Engine version | per-component `engine` requirement | `ThemeCapabilities.engineVersion` |
| Component versions | per-component `version` | implicit in the build |
| Capability list | intersection decides delivery | `ThemeCapabilities.components` |

Safety rules:

* A type absent from `ComponentCapabilityRegistry::APP_COMPONENTS` is **never** sent to the app,
  whatever the client claims (`custom_html` is excluded by design — arbitrary markup has no native
  renderer).
* A client that declares nothing gets everything app-safe (legacy builds).
* Unknown types that still arrive are skipped by the Flutter registry, recorded for diagnostics,
  and the rest of the page renders. A section that throws mid-build is isolated by a scoped
  `ErrorWidget` override and disappears alone.
* `compatibility.withheld` names every type the server held back and why — a thin page on an old
  build is explainable from the response.

**A new app release is required only when** a section needs a native primitive no shipped renderer
has. Everything else — new instances, reorder, enable/disable, text, banners, sources, picked
products/categories/vendors, colors, spacing, supported layout variants, scheduling, targeting,
tokens — is a Publish.

---

## 7. Rendering

### Web

`resources/views/theme-sections/{home,header,footer}.blade.php`, unchanged flow. Scheduling and
targeting are enforced in `StorefrontThemeRenderer`: the cached page *structure* stays shared,
visibility is evaluated per request (per viewer, per clock) after the cache read — a campaign
opens at 09:00:00, not "within a cache TTL of 09:00".

### Flutter

Registry/factory, no switch:

```dart
// engine/theme_engine.dart
static const List<SectionRenderer> renderers = [
  BannerSectionRenderer(),    // hero_banner, promotional_banner, split_banner,
                              // banner_mosaic, banner_strip, store_banner
  ProductSectionRenderer(),   // product_slider, featured_deal, clearance_sale,
                              // deal_of_the_day, flash_deal, category_showcase,
                              // brand_showcase, bundle
  TaxonomySectionRenderer(),  // category_grid, brand_slider, vendor_slider
  ContentSectionRenderer(),   // usp_strip, stats_bar, testimonials, faq,
                              // interest_tiles, price_tiles, app_download
  UtilitySectionRenderer(),   // product_tabs, stories, branches, before_after,
                              // shipping_cutoff, coupon_strip, custom_html
  AnnouncementSectionRenderer(), // announcement_bar
  SpacerSectionRenderer(),    // spacer
];
```

Every renderer: shares `ThemeSectionShell` (background / padding / heading from common settings),
reads tokens through `ThemeStyle` (merchant tokens with the app theme as fallback), clamps every
merchant-typed number, declines to render (`canRender`) rather than drawing an empty band, and
draws its own loading shimmer shaped like its real content.

**Pre-publish compatibility check** (spec §54–55): `ThemeCompatibilityReport` counts, per draft,
what the app will and will not show. The builder renders an "app will show X/Y sections" card
naming every withheld type with its reason; Theme Management shows an `app X/Y` badge per draft
and folds the warning into the publish confirmation. The report counts from the same
`ComponentCapabilityRegistry` the delivery pipeline filters with — a test pins them together.

`DynamicHomeSections` is embedded **inside** the app's own home screens
(`home_screens.dart`, `aster_theme_home_screen.dart`) — the header, search, drawer and bottom
navigation are app chrome and are never theme-controlled. When a drawable theme exists, the home
*content* comes from it and the screens' own banner/category/rail widgets are skipped (a
`useThemedHome` guard, plus a matching guard around their fetches, so nothing loads twice and
nothing renders twice). Otherwise the pre-existing home renders exactly as before. Nothing changes
for any user until a merchant publishes.

Heading affordances the shell owns, so every section behaves alike:

* **`view_all`** — the builder setting the app used to ignore. `ThemeViewAll` renders the link only
  where the storefront renders one (`product_slider · category_showcase · brand_showcase ·
  brand_slider · vendor_slider`), expresses the destination as a `ThemeAction` and routes it
  through `ThemeActionResolver`, so a heading link and the section's cards can never disagree. A
  showcase with no subject chosen shows no link rather than a dead one, and the chevron follows the
  reading direction.
* **Carousel dots** — `ThemeCarouselDots` on a multi-slide hero, and on a product carousel when the
  merchant ticked `pagination`. A slideshow with no indicator reads as a single image.

---

## 8. Adding a new section type

**Reachable from the app (full path):**

1. **Registry schema** — add the type + settings schema in `SectionRegistry::types()` (builder
   forms are schema-driven; no builder code changes).
2. **Web renderer** — add its `@case` block in `theme-sections/home.blade.php` and the type to
   `$__renderable`; resolve data through `SectionDataResolver` only.
3. **Data source** — if the app fetches its data, name the endpoint in `ThemeSourceMap::for()`
   (only endpoints that exist in `routes/rest_api/v1/api.php`).
4. **Capability** — add it to `ComponentCapabilityRegistry::APP_COMPONENTS` with
   `version`/`engine` (or to `APP_EXCLUSIONS` with a reason).
5. **Flutter renderer** — extend an existing renderer family's `types` list, or add a new
   `SectionRenderer` to `ThemeEngine.renderers`; add the type to
   `ThemeCapabilities.components`. A test asserts the renderer list and capability list agree.

**Web-only:** steps 1–2 plus an `APP_EXCLUSIONS` entry. Old apps are unaffected either way — the
server never sends what a client cannot hold.

Bump the **component version** when a section's settings change meaning; bump the **engine
version** when a renderer needs a primitive older builds lack.

---

## 9. Security

* All builder mutations sit behind the existing admin auth + `theme_view/edit/publish`
  capabilities (`ThemePermissionService`); publishing is a distinct permission.
* The payload is declarative data. No Dart, no JS, no eval; `custom_html` never reaches the app.
* Settings are normalized/coerced to their declared schema types on the way out
  (`SectionRegistry::normalizeSettings`), image uploads go through the hardened
  `ThemeAssetService`, links resolve into a closed action vocabulary.
* Draft preview stays session-scoped and admin-gated (never a shareable URL).
* Client capability headers can only *narrow* what is sent, never widen it.
* Malformed anything — settings, schedule strings, rule lists, payloads — degrades (ignore the
  field / skip the section / keep the cached page), never 500s a public endpoint and never blanks
  a home page.

## 10. Files changed / added

**Backend modified**: `app/Models/ThemeSection.php`, `app/Models/ThemeVersion.php`,
`app/Services/Theme/{StorefrontThemeRenderer,ThemeManager,ThemeBuilderService}.php`,
`app/Http/Controllers/RestAPI/v1/ThemeSectionController.php`,
`app/Http/Controllers/Admin/Settings/{ThemeBuilderController,ThemeManagementController}.php`,
`resources/views/admin-views/theme/{builder,index}.blade.php`,
`routes/rest_api/v1/api.php`, `routes/admin/routes.php`.

**Backend added**: `app/Services/Theme/{ViewerContext,SectionVisibility,ActionResolver,
ComponentCapabilityRegistry,ThemeDelivery,ThemeSourceMap,ThemeCompatibilityReport}.php`, the two
migrations above, `app/Services/Theme/ThemeSyncBeacon.php`,
`tests/Feature/{ThemeDeliveryTest,ThemeHomeApiTest,ThemeDeliveryRulesBuilderTest,ThemeSyncBeaconTest}.php`.

**Flutter modified**: `lib/di_container.dart`, `lib/main.dart`,
`lib/features/dashboard/screens/dashboard_screen.dart`, `lib/utill/app_constants.dart`
(dead `/api/v3/cursel` replaced by the theme endpoints), `lib/features/splash/*` (dead slider
fetch removed).

**Flutter added**: everything under `lib/features/dynamic_theme/`,
`test/{dynamic_theme_schema_test,dynamic_theme_controller_test}.dart`.

## 11. Upgrade considerations

* Migrations are additive and guarded; run them with the normal `php artisan migrate` flow (after
  the base DB import, per project rules). Code deployed before migrating degrades gracefully
  (publish works unstamped; sections insert without uuid).
* Future upstream merges: the touched original files are listed above; every change in them is
  additive (new constructor param with a default, new copy attributes behind a helper, new routes
  inside the existing group). The new services are all new files and cannot conflict.
* The unversioned `/theme/sections` endpoint keeps its exact contract for any existing consumer.

## 12. Verified state

* Backend: full Feature suite **1,104 tests / 3,659 assertions**, 2 errors — both the pre-existing
  `CrossTenantAuthorizationTest` auth-guard errors, reproduced on a clean tree. The
  categories/brands mobile-image migration was run live on sqlite (up, re-up, down, up).
* Flutter: `flutter analyze` reports no errors on any new/modified file (the remaining warnings are
  all pre-existing lints in untouched legacy files); **50 tests green** — parser totality, sync
  decisions (rollback/304/offline), capability↔renderer contract, the render matrix (every
  app-safe type × every builder style at 390pt RTL, asserting `takeException()` is null),
  deep-link resolution with its offline fallback, and the banner/category/brand image, action
  and click-attribution contracts.

## 13. Known limitations

* Web renderer is still one blade `@switch` (pre-existing); it renders all 39 types and was left
  as-is per "extend, don't rewrite". Registry-izing the blades is a possible later refactor.
* ~~No push-based invalidation~~ — built: publishing announces itself through `ThemeSyncBeacon`,
  a data-only, silent, background-priority FCM message to the `theme_updates_user_app` topic
  (subscribed at app startup, before login, next to the maintenance topic). The ping carries only
  `{type, revision}` — a woken client runs the same version-check-then-fetch it runs on resume, so
  a lost, delayed or duplicated beacon can never produce a wrong page, and the system keeps
  working with push disabled entirely. Sent after the publish transaction commits, and a send
  failure can never fail a publish.
* Sections typed outside the app's 33 renderable types render on web only (declared in
  `APP_EXCLUSIONS` with reasons; visible in `compatibility.withheld`): `recently_viewed`,
  `blog_posts`, `newsletter`, `footer_columns`, `trending_searches`, `vendor_showcase`.
* Theme lifecycle and builder mutations are recorded through the system-wide `AuditLogger`
  (spec §49): `theme.published / restored / activated / section_added / section_updated /
  section_deleted / sections_reordered / delivery_rules_updated`, each after its transaction
  commits, with before/after where a value changed — and never for a refused edit.
* ~~Builder UI for scheduling/platform/audience~~ — built: the inspector's **Visibility** tab
  edits the schedule window and platform/audience targeting per section
  (`POST admin/theme/builder/section/delivery-rules` → `ThemeBuilderService::setDeliveryRules`,
  validated: unknown tokens dropped, an end-before-start window cleared rather than saved).
  Scheduled/targeted sections carry badges in the structure panel.
* ~~Tablet layouts inherit mobile~~ — built: the app reports its real device class
  (600dp shortest-side, the same rule `ResponsiveHelper` applies) and the server resolves
  `*_tablet` overrides for it; per-device payloads are cached separately by the fingerprint.

## 14. The vendor_app channel — decision record

`Channel::VENDOR_APP` exists and is deliberately **targetable but not renderable**
(`Channel::ALL` includes it; `Channel::RENDERABLE` does not). This is a recorded
decision, not an omission:

* A channel is only real if a client can draw it. The seller app has no
  server-driven renderer today — no registry handshake, no section renderers, no
  theme sync loop. Publishing a "vendor app experience" would produce
  configuration nothing reads: a dead admin surface, which this codebase's rules
  (and the Phase 3 spec's §75) prohibit.
* Everything channel-shaped is already plumbed: pages carry a channel,
  sections carry channel restrictions, `ViewerContext` carries `X-UI-Channel`,
  and `Channel::forViewer()` maps an app that *names itself* `vendor_app` without
  touching the customer-app default.

**To make the vendor app renderable** (the full checklist — nothing else moves):

1. Vendor app: implement the sync loop (`/api/v1/theme/version` +
   `/api/v1/theme/home?page=…`) sending `X-UI-Channel: vendor_app` and its own
   `X-UI-Components` list, and a renderer registry for the section types it draws.
2. Backend: move `VENDOR_APP` from targetable-only into `Channel::RENDERABLE`.
   That single change makes the App Builder's channel switch, the pages screen,
   the publish gate and delivery all serve it — they all read `RENDERABLE`.
3. Seed its system pages (`ExperiencePageService::ensureSystemPages` runs on
   builder open; shared pages already belong to every channel).

Until step 1 exists, step 2 must not happen.
