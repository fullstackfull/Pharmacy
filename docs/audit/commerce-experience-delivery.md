# Commerce Experience & Merchandising Engine — delivery report (§91)

Branch `claude/flutter-app-review-9o7car` · backend suite **1495 passed / 2 skipped**,
Flutter suite **85 passed** after the verification sweep and fixes.

## Architecture

One engine, no forks. Every Phase 3 capability enters the serve pipeline at an existing seam:

```
theme_sections (published, never rewritten)
   │
   ├─ WEB   StorefrontThemeRenderer::sectionsFor → cache(structure) → runnable():
   │          visibility+schedule+audience(+segments) → locale collapse → experiment patch
   │          → campaign splice → shell/partials
   └─ API   ThemeDelivery::payload → cache key = fingerprint(platform, device, audience,
              segments-set, locale, engine, components) + campaign-stamp + experiment-stamp
              → build(): visibility → capability withhold → present(): normalize → locale
              collapse → experiment patch → actions/sources → campaign splice → checksum
```

Product resolution stays single-seam: web through `SectionDataResolver::productsFrom`
(new `collection` kind), app through a `source` hint at
`/api/v1/products/theme-collection` — same product-list dialect as its siblings, so
**installed apps needed zero changes for the whole phase**.

## Compatibility

- No existing API field renamed, retyped, or removed. New fields (`experiment`, earlier
  `variant`/`view_all`/`component_version`) are additive and optional.
- Locale, segments, campaign and experiment stamps joined the CACHE KEY, never the contract.
- The version endpoint's checksum gains a suffix only while a campaign is live — the mechanism
  installed apps already use to decide "did anything change".
- `commerce.enabled=false` (env `COMMERCE_EXPERIENCE_ENABLED`) short-circuits every resolver:
  behavior is App Builder V2, byte-identical, pinned by tests.

## Database (all additive; up/re-up/down verified on sqlite)

| Migration | Adds |
|---|---|
| 2026_09_03 …commerce_collections | `product_collections`, `product_metrics` |
| 2026_09_04 …merchandising | nullable `merchandising` column on product_collections |
| 2026_09_05 …experience_campaigns | `experience_campaigns` (overrides as validated JSON — deliberate deviation from the plan's child table: read whole-set on every request, no join) |
| 2026_09_06 …customer_segments | `customer_segments` |
| 2026_09_07 …experience_experiments | `experience_experiments` |

No existing table touched. Rollback = drop new tables, or just the kill switch.

## Backend modules (`app/Services/Commerce/`)

CollectionRuleRegistry · CollectionResolver · MerchandisingRules · CampaignRules ·
CampaignResolver · SegmentRules · SegmentResolver · ExperimentRules · ExperimentResolver ·
ExperienceHealth — each independently bypassable; none reachable when the switch is off.
Commands: `commerce:metrics-refresh` (hourly, after analytics rollup),
`commerce:campaigns-tick` (5-min lifecycle tidy + cache flush; the WINDOW protects shoppers
even with no cron).

## Admin

- **Commerce Experience** (sidebar): Collections (rule builder, merchandising, live preview via
  the storefront's own resolver), Campaigns (lifecycle, slot overrides, UPCOMING/LIVE badges),
  Segments, Experiments — all module-gated + ThemePermissionService::EDIT on writes, demo-mode
  guarded, audit-logged.
- **App Builder**: pages/sections/media/templates + **Experience Health** (findings by severity,
  server readiness, decision trace, time-travel evaluation §61, segment preview §62).
- Builder: collection source option + picker; segment keys in the Visibility tab's audience row.

## Mobile

Zero required changes (by design). Optional additive work already shipped earlier on this
branch: composed-page screen, page actions, language-switch refetch.

## Dynamic collections / merchandising / campaigns / segments / experiments

Delivered per §19–48; key invariants pinned by tests:

- rules/fields/operators allowlisted; refusals named; stored-but-unknown rule ⇒ empty, never broader
- metrics precomputed from order_details / analytics_daily / reviews / wishlists — resolution
  measured **3 queries / ~2 ms over 1 000 products**
- pins never resurrect unsellable products; boosts re-rank only; fallback chains: cycle-checked
  at save, one hop at run time
- campaigns never write base pages; equal-priority slot contests refused with names; ended
  window ⇒ base page back with nothing to restore
- guests carry no segments; membership computed, never stored; no customer data in shared cache
- experiment buckets = crc32(key+subject) — stable with nothing persisted; every failure mode
  = control; stopped never restarts

## Analytics

Existing pipeline only (§50): campaign sections report as `campaign-{id}` through the same
section_viewed impressions; experiment sections stamp `experiment: key:variant` onto the same
event as a property. No new endpoints, no second pipeline.

## Failure safety (§82–86 drills, all as tests)

- corrupt campaign ⇒ base page on both paths, never a 500 (`CampaignExperienceTest`)
- broken/deleted/disabled collection ⇒ empty ⇒ section hides; publish gate names the reference
- segment resolution sabotage (table dropped mid-flight) ⇒ base experience
- corrupt experiment ⇒ control
- kill switch ⇒ App Builder V2 exactly
- old-client shape: `ThemeDeliveryTest` legacy-client tests green throughout

## Performance

Collection resolution 3 queries / ~2 ms @1k products (measured). Cache-entry cardinality
bounded by defined-segment sets × variant space × campaign set — never by customers (§65).
Serve-path additions per request: campaign row read (indexed, ≤2×), experiment list (60 s
cache), segment metrics (600 s per-customer cache).

## Tests

Backend: +8 new feature suites this phase (DynamicCollections 21, CampaignExperience 11,
SegmentExperience 9, ExperimentExperience 9, ExperienceHealth 7, plus localisation, custom
pages, readiness earlier) — total suite 1474 passed. Flutter: 82 passed.

## Remaining limitations (deliberate)

- App-side impressions for campaign overlay sections are not recorded (their id is null in the
  payload; web measures them). Extension point: AnalyticsReporter keying by uuid.
- Per-variant conversion reporting reads `analytics_events.properties` — data records from day
  one; a rollup dimension was deliberately not added to the working pipeline.
- Campaign overrides compose a curated subset of section types (six), not the full registry.
- Promotional slots are positional (hero/top/middle/bottom), matching the ordered-list page
  architecture rather than the spec's named-slot examples (§35 "use only where compatible").

## Rollback

`COMMERCE_EXPERIENCE_ENABLED=false` → every resolver answers empty/control/no-overlay and
App Builder V2 serves exactly as before — no migration reversal, no data loss. Tables can be
dropped independently afterwards; nothing existing references them.

## Verification sweep — results

**59 agents** reviewed both repos across 20 dimensions (collections, merchandising, campaigns,
segments, experiments, delivery contract, web renderer, localisation, admin screens, custom
pages, Flutter, migrations, performance, fail-safety, security, analytics, RBAC/audit, tests,
cohesion/docs), with adversarial verifiers on the substantive claims. Every confirmed finding
was fixed and regression-tested in commits `c1501cbb` (backend) and `75d08a0` (app); the fix
list is those commits' messages. Highlights: inert channel targeting on both render paths,
home-payload served for unknown page slugs, session-only auth on the stateless sections API,
experiment-patch/locale-collapse ordering, javascript: URLs surviving link coercion, segment
tokens stripped on engine-off saves, live-campaign edits invisible to caches and sync, pin
collisions dropping pins, fallbacks bypassing exclusions, category rules reading one of three
levels, and a set of cache-invalidation and permission (publish-capability) gaps. Refuted
claims (kept as records, unfixed by design): `dropIfExists` down-migrations, the version/payload
checksum domain split (pre-existing, one extra conditional GET per resume), 10-minute segment
metric freshness (documented TTL).

Final state after fixes: backend **1495 passed / 2 skipped**, Flutter **85 passed**, analyzer
clean. A permanent `AdminPanelWiringTest` now holds the panel together: every route the sidebar
and every Commerce/App Builder/Theme screen references must exist, every such route must land on
a real controller method, the sidebar must carry all four areas (App Builder, Commerce
Experience, Theme Management, Analytics), each area nav all of its screens, every product-source
hint the payload can emit must resolve to a registered API route, and the measurement pipeline
must have both of its ends (beacon collect + theme sync endpoints).
