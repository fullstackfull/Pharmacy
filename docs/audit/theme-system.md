# Theme System (Phase 1.1 → 1.2)

Status: **data foundation built and tested.** Admin management + storefront consumption + the visual
builder are the next slices.

## Why this shape
The current storefront picks a theme from `env('WEB_THEME')` and resolves Blade views by path — a
single hardcoded theme, no drafts, no versioning, design decisions scattered in blades. This
foundation adds a professional, additive layer **without touching** that mechanism, so nothing
existing breaks; the new system only takes over where a published version exists.

## Schema (additive, normalized)
| Table | Purpose |
|---|---|
| `themes` | registry; `is_active` marks the one live theme, `is_system` marks built-ins |
| `theme_versions` | `draft` → `published` → `archived` lifecycle; `settings` JSON = global config |
| `theme_sections` | section-based pages (home/header/footer…): page, type, order, visibility, settings |
| `theme_blocks` | reusable child elements inside a section (slides, columns, links) |

FKs cascade on delete. Structural data (sections/blocks) is normalized into its own tables; JSON is
used only for the schema-less **settings** payloads (branding, colors, typography, layout, and each
section/block's own options) — the balanced approach the brief asked for.

## Lifecycle (non-destructive)
- **Publish** = a status swap inside a transaction: the theme's current `published` version becomes
  `archived`, the target becomes `published`. Rollback = publish a prior version. No row is deleted.
- **Duplicate** = `ThemeManager::createDraftFrom()` copies a version + its sections + blocks into a
  fresh `draft` (revision / "edit a copy" workflow).
- **Settings resolution** = `ThemeManager::resolveSettings()` deep-merges baseline defaults with a
  version's overrides, so a draft only stores what it changes.

## Tested
`tests/Feature/ThemeManagerTest.php` (5): defaults, deep-merge override, publish archives previous,
active-theme published-version resolution, duplicate copies sections+blocks.

## Next (in order)
1. **Theme admin CRUD + activation** — list/create/duplicate/publish/activate, permissions
   `theme.view|edit|publish` mapped onto the admin RBAC.
2. **Storefront consumption** — a resolver that, when the active theme has a published version,
   renders sections from config; otherwise falls back to the current blades (the shim). *Needs live
   QA — storefront rendering can't be browser-verified in this session.*
3. **Visual Theme Builder (Phase 1.2)** — left structure panel, center live preview
   (desktop/tablet/mobile), right settings; drag-and-drop reorder; draft→preview→publish; autosave;
   revision history. Built on this foundation.
