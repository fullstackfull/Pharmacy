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
                              // deal_of_the_day, flash_deal
  TaxonomySectionRenderer(),  // category_grid, brand_slider, vendor_slider
  ContentSectionRenderer(),   // usp_strip, stats_bar, testimonials, faq,
                              // interest_tiles, price_tiles, app_download
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

`HomeHostScreen` wraps the dashboard's home tab: published theme renders when it exists and is
drawable; otherwise the pre-existing `HomePage`/`AsterThemeHomeScreen` renders exactly as before.
Nothing changes for any user until a merchant publishes.

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

* Backend: 21 pre-existing theme suites + 3 new ones — **188 theme tests / 1,091 assertions green**
  (full Feature suite: 1,054/1,056; the 2 failures are pre-existing `CrossTenantAuthorizationTest`
  errors present on a clean tree).
* Flutter: `flutter analyze` clean on all new/modified files; **20 engine tests green** (parser
  totality, sync decisions incl. rollback/304/offline, capability↔renderer contract).

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
* Sections typed outside the app's 24 renderable types render on web only (declared in
  `APP_EXCLUSIONS` with reasons; visible in `compatibility.withheld`).
* ~~Builder UI for scheduling/platform/audience~~ — built: the inspector's **Visibility** tab
  edits the schedule window and platform/audience targeting per section
  (`POST admin/theme/builder/section/delivery-rules` → `ThemeBuilderService::setDeliveryRules`,
  validated: unknown tokens dropped, an end-before-start window cleared rather than saved).
  Scheduled/targeted sections carry badges in the structure panel.
* Tablet-specific layouts inherit the mobile resolution in-app (the payload still carries
  `*_tablet` siblings for a future pass).
