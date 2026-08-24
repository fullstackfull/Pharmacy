# Commerce Experience & Merchandising Engine — pre-implementation audit (§89)

Baseline date: 2026-08-24 · branch `claude/flutter-app-review-9o7car`

## A. Current architecture (as discovered, not assumed)

**Publish flow (traced):**

```
Admin edits (ThemeBuilderController → ThemeBuilderService)
  → theme_sections / theme_blocks rows on a DRAFT theme_versions row
  → publish (ThemeManagementController → ThemeManager, gated by PublishValidator)
  → version stamped monotonic revision + checksum (ThemeDelivery::stampVersion, same transaction)
  → caches flushed (StorefrontThemeRenderer::flush + ThemeDelivery::flush) + sync beacon
  → WEB:  theme-sections/home.blade.php shell → StorefrontThemeRenderer::sectionsFor(page)
          (5-min cache of normalized structure; per-request pass = visibility + schedule +
           audience + locale collapse in runnable())
  → APP:  GET /api/v1/theme/version (tiny) + /theme/home?page= (ETag/304)
          ThemeSectionController → ThemeDelivery::payload(page, ViewerContext)
          (cache keyed by fingerprint: revision|platform|device|audience|locale|engine|components)
  → APP section data: payload carries a `source` hint (ThemeSourceMap) naming an EXISTING
          catalogue endpoint (/products/best-selling, /products/by-ids, …); the app fetches that.
```

**The one seam for product resolution:** web rails resolve products through
`SectionDataResolver::productsFrom(ContentSource)`; the app is *told which existing
endpoint to call* via `ThemeSourceMap`. Any new product source therefore needs
(1) a `productsFrom` branch and (2) one catalogue endpoint speaking the existing
product-list dialect, plus a source-map hint. Nothing else moves.

**Registries already in place:** SectionRegistry (types/variants/schemas, settings
normalisation incl. `_tablet/_mobile` and `_{locale}` overrides), ComponentCapabilityRegistry
(schema/engine versions, per-client capability handshake), ContentSource
(featured / best_selling / new_arrival / top_rated / category / brand / manual, MAX_LIMIT 24).

**Scheduling/targeting already in place:** per-section `starts_at/ends_at`,
`platforms`, `audience` (guest/customer), `channels` — applied post-cache per request on
web, pre-cache-keyed on the API. Version-level `publish_at` + `theme:publish-due`.

**Analytics already in place:** `analytics_events` + rollups (dimensions include
`product`, `category`, `brand`, `banner`, `theme_section`, `path`), client events
allow-listed (`product_list_viewed`, `banner_clicked`, `section_viewed`),
`AnalyticsReporting::breakdown()/collectionHealth()`, `SectionReach` (30-day visitors per
section). Scheduler heartbeat + `BuilderReadiness`.

**RBAC/audit/config:** ThemePermissionService (module `themes_and_addons` + fine
capabilities offered in role forms), AuditLogger (theme.* actions), business_settings +
config/*.php for configuration, ToastMagic flash pattern, `nwidart` modules for add-ons.

## B. Production-critical paths (must not break)

| Path | Files |
|---|---|
| Home page (web) | theme-sections/home.blade.php + types/*, StorefrontThemeRenderer, SectionDataResolver, SectionReadiness |
| Home payload (app) | ThemeSectionController, ThemeDelivery, ViewerContext, ThemeSourceMap, ComponentCapabilityRegistry |
| Version sync / 304 | ThemeDelivery::revision/payload checksum+fingerprint |
| Draft→publish | ThemeBuilderService, ThemeManager, PublishValidator, ThemeVersion stamping |
| Version history / restore | ThemeManagementController, ThemeVersion |
| Catalogue endpoints the app already calls | ProductController list endpoints, `by-ids` |
| Builder UI | ThemeBuilderController + builder.blade.php |
| Custom pages | ExperiencePageService, ExperiencePageController, /p/{slug}, app ExperiencePageScreen |

## C. Baseline (recorded before any Phase 3 code)

* Backend: `php artisan test` → **1415 passed, 2 skipped (5232 assertions)** @ commit `4e4793a4`.
* Flutter: `flutter test` → **82 passed**; `flutter analyze` clean on all touched dirs
  (280 pre-existing infos/warnings in legacy app code, none in dynamic_theme) @ `9797e7c`.
* Pre-existing failures: none.
* Live site incident history this branch: `$__data` Blade collision (fixed; regression-tested
  by StorefrontRendersTest).

## D. Existing reusable features (extend, do not duplicate)

| Phase 3 ask | Already exists → reuse |
|---|---|
| Collection source kinds | ContentSource + productsFrom + catalogue endpoints |
| Sales/views/rating metrics | orderDetails counts, reviews avg, analytics product rollups |
| Scheduled aggregates | scheduler + rollup command pattern (AnalyticsRollup/TelemetryRollup) |
| Campaign scheduling primitives | section starts_at/ends_at + version publish_at + theme:publish-due |
| Flash/featured/clearance campaigns | FlashDeal/FeatureDeal/DealOfTheDay models + section types |
| Guest/customer targeting | ViewerContext.audience + SectionVisibility |
| Stable visitor identity | analytics VisitorContext |
| Publish gate | PublishValidator (BLOCKING/WARNING, cached) — extend with new checks |
| Health surface | BuilderReadiness + App Builder pages screen |
| Impressions/clicks | analytics-beacon.js + AnalyticsReporter (app) + EventRecorder |
| RBAC/audit | ThemePermissionService::all() + role forms; AuditLogger |
| Preview w/o cache poison | previewPayload + ThemePreviewToken + storefront preview branch |

## E. Risks and mitigations (per module)

* **Dynamic Collections** — risk: heavy queries on Home. Mitigation: resolution behind the
  existing 5-min caches; sales/views read from a `product_metrics` summary table refreshed by
  scheduler, never aggregated per request; rule engine is allowlisted fields+operators compiled
  to a bounded Eloquent query. Rollback: a section whose source is a missing/broken collection
  falls back to its configured fallback kind (default `featured`) — the section renders, never 500s.
* **Merchandising** — risk: pinned/boosted products going ineligible. Mitigation: pins filtered
  through `Product::active()` at resolve time; broken pin = skipped, backfilled by the
  collection. Rollback: merchandising config ignored when unreadable.
* **Campaigns** — risk: overlay corrupting the served page. Mitigation: overlays stored in their
  own tables and applied at delivery time inside try/catch; any throw → base payload unchanged.
  Base version rows are never written by campaign activation/end. Conflict check at activation
  (same slot+overlapping window+same priority = refuse). Rollback: deactivate row / kill switch.
* **Segments** — risk: cache explosion + PII in shared cache. Mitigation: segment set resolved
  per request from a cached per-customer metrics row (orders_count, last_order_at); only the
  matched-set hash joins the delivery fingerprint (bounded by defined segments); no customer data
  in any shared cache value. Failure → guest/base experience (fail open).
* **Experiments** — risk: unstable assignment. Mitigation: bucket = crc32(experiment_id +
  stable visitor/customer id) % 100 — deterministic, no storage; broken experiment → control.
* **All modules** — master kill switch `config('commerce.enabled')` read at the single
  enhancement seam; off = byte-identical V2 behavior.

## F. Database plan (all additive; no existing table touched)

```
product_collections        id, name, slug, status, rules(json), sort_by(string),
                           merchandising(json: pins/excluded/boosts/min_items/replace/fallback), timestamps
product_metrics            product_id (unique), sales_30d, views_30d, carted_30d, rating,
                           wishlist_count, computed_at
experience_campaigns       id, name, status(draft/scheduled/active/paused/ended/cancelled),
                           page(slug), priority(int), starts_at, ends_at,
                           overrides(json: validated [{slot, section}] rows — a child table was
                           planned and deliberately not shipped: the set is small, validated,
                           and read whole on every request), timestamps
customer_segments          id, name, key, status, rules(json), timestamps
experience_experiments     id, name, key, status, page, section_uuid, variants(json — weights
                           live inside the rows; no schedule window), timestamps
```

Each migration: guarded `Schema::hasTable`, up/re-up/down verified on sqlite; no locks on
existing tables; rollback = drop the new table. `product_metrics` rebuilt by command —
losing it costs nothing but freshness.

## G. API compatibility plan

* No existing field renamed, removed, or retyped. Sections gain nothing old clients read.
* Collections reach the app as a `source` hint pointing at a NEW endpoint
  `/api/v1/products/theme-collection/{id}` that answers in the SAME product-list shape as the
  endpoints the app already parses → **zero mobile changes required**; old builds render
  collections the day they are composed.
* Campaign overlays and personalization change WHICH sections are in the payload — a shape the
  client already handles (it renders the list it is given; unknown types withheld server-side).
* Experiment/campaign ids ride analytics context fields already tolerated by EventRecorder.

## H. Runtime fallback plan

Single seam in both render paths:

```
base   = published V2 payload/sections           (existing code, untouched)
try  { enhanced = ExperienceComposer::apply(base, context) }   // campaigns, personalization, experiments
catch{ report(); enhanced = base }                              // §9/§82 — Home always loads
```

Collections fail inside SectionDataResolver::safely() exactly as every source does today:
empty result → SectionReadiness hides the section; configured fallback source used first.
Analytics/health never sit on the render path (§51).

## I. Implementation sequence

1. **3.1** product_metrics table + refresh command (scheduler) → CollectionRuleRegistry
   (fields/operators allowlist) → ProductCollection model + CollectionResolver →
   `ContentSource` kind `collection` + productsFrom branch + web endpoint + source-map hint →
   admin CRUD (Commerce Experience → Collections) + builder source option → tests + regression.
2. **3.2** merchandising inside CollectionResolver (pins/exclusions/boosts/min items/replacement/
   fallback with cycle check at save) → tests + regression.
3. **3.3** campaigns tables + CampaignResolver (slot/priority/conflicts) + ExperienceComposer
   seam in ThemeDelivery::build + StorefrontThemeRenderer::runnable → admin screens →
   scheduler transition (reuse theme:publish-due pattern) → failure tests §82–83.
4. **3.4** customer_metrics cache + SegmentResolver + section `segments` targeting (builder
   Visibility tab) + fingerprint hash → tests.
5. **3.5** experiments + deterministic assignment + variant override in composer → tests.
6. **3.6–3.8** analytics dimensions (campaign/experiment on existing events), Experience Health
   screen (extends BuilderReadiness + PublishValidator findings + decision trace), time-travel
   & segment preview (extends existing preview), post-publish monitoring row. Final failure
   drills §82–86, performance before/after, delivery report.

After every step: full backend suite + Flutter suite + the §80 checklist; no step starts
while the previous one is red.
