# Phase 1 — engineering approach & architecture decisions

Brief record of the decisions taken while executing Phase 1 (theme system, theme builder,
admin/vendor UX, product workflow, SEO). Kept short by design; each subsystem gets its own
design note when it lands.

## Working constraints (must be respected by every change)
1. **Preserve existing functionality and data** — no destructive DB/API changes.
2. **Backward compatibility with the REST APIs and the Flutter apps** — never change an existing
   endpoint's contract destructively; add versioned/compat layers instead.
3. **Fix the root cause, not the symptom.**
4. **Clean architecture over patches**; if the ideal design needs structural change, add a
   compatibility/migration layer and migrate gradually.
5. **Regression tests around every affected area**, before and after.

## Environment reality (and how we compensate)
This checkout cannot boot: no `vendor/`* , no `node_modules`, no `.env`, and the core tables live
only in the proprietary `database.sql` dump (git-ignored, absent). Therefore:
- Backend logic is verified with `php -l` + isolated PHPUnit unit tests (no DB/app boot).
- Anything requiring a running app or a browser (visual builder, RTL/responsive QA, live
  regression) ships with an explicit **manual verification checklist** in its design note, to be
  run by the team in a real environment.
- DB work ships as **additive migrations** (new tables/columns only) so it is safe to run on top
  of the existing dump without touching current data.

\* dev dependencies are installed on-demand in the working session to run the unit tests.

## Prioritization (by dependency & risk, not by the order requested)
1. **Product create/edit workflow** (P0, in progress) — active bug, high trust, backend-verifiable.
2. **SEO system** — fully additive, backend, no runtime dependency, standalone value.
3. **Theme System data foundation** — additive backend keystone for the builder.
4. **Admin design system + information architecture**, then **Vendor panel**.
5. **Visual Theme Builder** and **UI/UX bug audit** — require live/browser validation.
6. **Regression pass** across everything shipped.

## Key architecture decisions

### Theme System (Phase 1.1) — additive, with a compatibility shim
- New relational tables (`themes`, `theme_versions`, `theme_sections`, `theme_blocks`,
  `theme_settings`, `theme_presets`) — **not** one giant JSON blob; JSON is used only for a
  section/block's own settings payload where a schema-less bag is genuinely appropriate.
- The storefront keeps resolving views through the **existing** env/`WEB_THEME` mechanism; the new
  system layers on top and only takes over rendering where a published theme version exists. This
  is the compatibility shim: no existing theme rendering path is removed until the new one is
  proven at parity.
- Draft → Preview → Published is modeled as versioned rows (`theme_versions.status`), so publish is
  an atomic pointer swap and rollback is selecting a prior version — no destructive writes.
- Permissions split: `theme.view` / `theme.edit` / `theme.publish` mapped onto the existing admin
  RBAC (`module:*`) so it integrates with the current role system rather than inventing a parallel one.

### SEO System (Phase 1.6) — additive tables + non-destructive routing
- Centralized `seo_meta` per entity (polymorphic) with per-language rows (AR/EN) rather than widening
  each entity table; templates table with placeholder resolution; `redirects` table for the 301/302
  manager. All additive.
- Redirects are applied by a middleware that runs **before** 404 handling and never shadows an
  existing valid route, so current URLs keep working.
- Structured data / hreflang / canonical are emitted from a service consumed by Blade partials —
  storefront markup is extended, not rewritten.

## Backward-compatibility rules of thumb applied here
- API controllers: only **additive** request handling and **defensive** null-safety (identical
  output for valid payloads). No field renames, no response-shape changes.
- Never delete a column/table in Phase 1; deprecate in place.
- Every new admin/vendor feature is gated by the existing auth guards and `module:*` permissions.
