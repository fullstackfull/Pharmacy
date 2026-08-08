# Fix: product create/edit save fails after removing a variant/attribute/option

**Area:** Phase 1 · Product workflow (P0 bug)
**Status:** root-caused + fixed (backward-compatible). Needs live regression verification (see checklist).

## Symptom (as reported)
> While adding or editing a product, if you add data and later remove/delete something
> before saving, the save sometimes fails.

The merchant sees a generic failure with no clear reason — a **silent failure**.

## Root cause
The product form builds variant data from two parallel structures:

- `choice_attributes[]` / `colors[]` — the select2 pickers, **validated** in
  `ProductAddRequest`/`ProductUpdateRequest`.
- `choice_no[]` + `choice_options_<id>[]` and `extensions_type[]` + `extensions_options_<type>[]`
  — hidden inputs injected per attribute by `product-add-update.js`, **consumed by the service**.

The validator inspects `choice_attributes`, but the service iterates `choice_no` /
`extensions_type`. When an attribute, option, or extension is removed on the form, the two
structures desync: a `choice_no` entry survives while its `choice_options_<id>` array becomes
`null`. The service then executed:

```php
implode('|', $request['choice_options_' . $no]);   // implode('|', null)
```

In PHP 8, `implode()` on `null` throws `TypeError` → **HTTP 500**, after validation has
already passed. Because the request is posted via AJAX, the merchant only sees a generic error.

### Confirmed crash sites (all the same pattern)
| File | Method | Line (pre-fix) |
|---|---|---|
| `app/Services/ProductService.php` | `getChoiceOptions()` | 330 |
| `app/Services/ProductService.php` | `getOptions()` | 346 |
| `app/Services/ProductService.php` | `getDigitalVariationOptions()` | 981 |
| `app/Http/Requests/ProductAddRequest.php` | `after()` (digital) | 209 |
| `app/Http/Requests/ProductUpdateRequest.php` | `after()` (digital) | 261 |

A related null-dereference was also fixed in `getVariations()`:
`$this->color->where('code', $item)->first()->name` crashed with **Error: read property "name"
on null** when a selected color no longer exists in the catalog.

Both `getAddProductData()` and `getUpdateProductData()` call `getOptions()`/`getChoiceOptions()`,
so the crash is on the real **save** path, not only the AJAX SKU preview.

## The fix
Null-safe, backward-compatible normalization — for well-formed input the output is identical:

- Coerce the paired option arrays to `[]` when missing (`is_array($x ?? null) ? $x : []`),
  so `implode()` never receives `null`.
- **Skip** attributes/options that are empty after removal, instead of persisting an empty
  variation attribute or (in `getOptions`) collapsing the entire cartesian product to zero
  variations. This makes "removed on the form" mean "removed on save".
- Guard the color lookup with a fallback to the raw code.

No schema change, no API change, no behavioral change for valid submissions.

## Manual regression checklist (run in a real environment)
Cannot be automated here — this checkout has no `vendor/`, no DB dump, so the app can't boot.
Verify each in **Admin → Products → Add** and **Edit**, in both **English (LTR)** and **Arabic (RTL)**:

- [ ] Physical product: add attribute → add options → **remove the attribute** → Save ⇒ saves, no 500.
- [ ] Physical: add 2 attributes → remove one attribute's options only → Save ⇒ saves with the remaining variation set.
- [ ] Physical: add color → add color image → remove the color → Save ⇒ saves.
- [ ] Physical: generate variations → clear one variation price/SKU field → Save ⇒ clear validation message (not a 500).
- [ ] Digital: add extension type → add options → remove the extension type → Save ⇒ saves.
- [ ] Edit an existing product with variations, remove one variation, Save ⇒ persists correctly; existing carts recalculated.
- [ ] Confirm no regression: normal create/edit with attributes + colors still stores identical `variation` / `choice_options` JSON.
