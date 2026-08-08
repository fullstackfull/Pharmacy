# SEO Redirect Manager (Phase 1.6)

Status: **engine + admin CRUD built, wired, and tested.** Remaining: slug-change auto-suggest.

## What ships
| Piece | File | Verified |
|---|---|---|
| Table (additive) | `database/migrations/2026_08_08_000001_create_redirects_table.php` | lint |
| Model | `app/Models/Redirect.php` | lint |
| Matching logic (DB-free) | `app/Services/Seo/RedirectResolverService.php` | 11 unit tests |
| Storefront application | `app/Http/Middleware/ApplySeoRedirects.php` | 4 feature tests |
| Wiring | `bootstrap/app.php` (web group only) | app boots green |

## Safety posture
- **Storefront only.** Excludes `/api`, `/admin`, `/vendor`, `/install`, `/update`, `/payment` —
  so it can never change a panel page or a Flutter API 404 (backward-compat priority).
- **No-op until configured.** With no active rules (or before the table exists) it returns `$next`
  immediately; empty result is cached, so there is zero added DB load on current installs.
- **Fail-open.** Any error is `report()`ed and the request continues untouched.
- **Loop-safe (single hop).** Self-referential rules are skipped. Multi-hop loops (A→B, B→A) remain
  the admin's responsibility, as in every redirect manager; browsers cap redirect chains.

## Rules load / cache
`ApplySeoRedirects::loadRules()` caches active rules under `seo_active_redirects` for 300s. **The
upcoming admin CRUD must call `Cache::forget('seo_active_redirects')` on create/update/delete** so
changes apply immediately instead of within 5 minutes.

## Manual smoke test (run once in a real environment — cannot be automated without the DB dump)
1. Deploy with the migration run. Confirm the storefront serves normally (no rules yet).
2. Insert a rule: `from_path=/old-thing`, `to_path=/`, `status_code=301`, `is_active=1`.
   `Cache::forget('seo_active_redirects')`.
3. Visit `/old-thing` → expect a 301 to `/`. Confirm `hits`/`last_hit_at` increment.
4. Visit `/admin/...`, `/api/...`, and a normal storefront URL → expect **no** redirect.
5. Deactivate the rule → `/old-thing` serves normally again.

## Admin CRUD (shipped)
`Admin › SEO Settings › Redirects` (`admin/seo-settings/redirects`, `module:business_settings`).
`RedirectController` + `RedirectRepository` (auto-bound) + `RedirectStoreRequest`. Every write
invalidates `Redirect::ACTIVE_CACHE_KEY`, so storefront redirects apply immediately. Duplicate
`from_path` is rejected; the source path is normalized with the same resolver the middleware uses.

### Admin UI smoke test (run once in a real environment)
1. Open `Admin › SEO Settings › Redirects` → the tab renders, empty state shows.
2. Add `/old-url → /` (301, exact, active) → row appears; visiting `/old-url` 301s to `/`.
3. Toggle status off → `/old-url` serves normally; toggle on → redirects again (cache invalidated).
4. Edit the row (change target) and Save → new target applies. Delete → row gone, no redirect.
5. Try a duplicate `from_path` → rejected with a clear message.

## Next
- Auto-suggest a redirect when a product/category slug changes.
