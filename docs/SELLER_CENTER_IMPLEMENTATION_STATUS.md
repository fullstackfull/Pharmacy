# Seller Center — Implementation Status

Required by PART 18 of the implementation brief: one place that says, per wave, what was built, what the
quality gates said about it, and what is still open. Updated at the end of each wave, not while it is in
flight.

- **Design source** — `design_handoff_seller_center/` (`README`, `01`–`14`, `assets/tokens.css`). The HTML
  prototype is a reference; none of its runtime was ported.
- **Parity source** — [`SELLER_WEB_APP_PARITY.md`](SELLER_WEB_APP_PARITY.md), 635 capabilities across 14
  domains, of which **195 are WEB MISSING** and form this programme's backlog.
- **Branch** — `claude/seller-center-app-update-979q0s` in both repositories.

## Where the work lives

The Seller Center is a **parallel surface** at `/seller`, not a re-skin of `/vendor`.

The legacy vendor panel carries 126 hard-coded colours outside its token block; re-theming it in place would
have put every existing seller screen at risk for a visual gain. Instead the new shell owns its own
stylesheet and its own routes, and the navigation registry links un-migrated destinations straight back into
`/vendor`. Nothing a seller can do today stopped working when wave 1 landed, and each wave removes one more
legacy link (PART 15).

## Wave ledger

| Wave | Scope | State | Commit |
|---|---|---|---|
| 1 | Foundation — tokens, shell, component library, table + filter system, status renderers, states, RTL | **Done** | `b969dd21` |
| 2 | Core seller operations — Home, Control Tower, Issue Center, Orders, Order detail, Products, Inventory, Movement ledger | **Done** | `e5125f33`, `53085acb` |
| 3 | Automation — rules, builder, history + undo, scheduled operations, opportunities | Not started | — |
| 4 | Fulfilment — shipments, exceptions, picking, packing, warehouse operations | Not started | — |
| 5 | Finance — overview, ledger, payout detail, statements, reconciliation, fee simulator | Not started | — |
| 6 | Trust — performance, account health, brand registry, claims, compliance | Not started | — |
| 7 | Enterprise — team, roles, approvals, audit log, security centre, cases, appeals | Not started | — |
| 8 | Platform — reports, exports, bulk operations, connected apps, API credentials, webhooks | Not started | — |

---

## Wave 1 — Foundation

**Built**

- `public/assets/seller-center/css/tokens.css` — the handoff's token file, dropped in unmodified so a design
  change arrives as a file replacement rather than a diff.
- `public/assets/seller-center/css/sc.css` — shell geometry, the 40 components of `04`, the one table system
  of `05` A, the one filter system of `05` B, the seven data states of `11`, the responsive rules of `10` A
  and the RTL mechanism of `10` B.
- `public/assets/seller-center/js/sc.js` — command palette (⌘K, `/`, ⌘1–9), drawers and modals with focus
  return, row selection with shift-range and the bulk bar, tooltips, SLA countdown, toasts. No framework;
  the prototype's `support.js` was not ported.
- `resources/views/layouts/seller/app.blade.php` + 6 partials, and **44 Blade components** under
  `resources/views/components/sc/`.
- Services: `Icons`, `Status`, `Navigation`, `Shell`, `TableFilters`, `Counts`, `Search` under
  `App\Services\SellerCenter`.
- `SellerCenterContext` middleware — resolves the web session into the **same `SellerPrincipal`** the API
  token produces, so `seller_can:` and the audit actor are one system across both clients (PART 5).
- `EnsureSellerPermission` now content-negotiates: JSON for the API, a real 403 screen for a browser.

**Gates** — a throwaway screen (`/seller/foundation`, debug-only) assembles a 10-column table, 4 saved views,
6 filter types, selection, bulk bar, density switch and all seven states from configuration alone, in both
directions, with no screen-specific CSS. 17 tests.

## Wave 2 — Core seller operations

**Built** — Seller Home, Control Tower, Issue Center + issue detail, Orders + order detail, Products,
Inventory overview, Movement ledger. Routes in `routes/seller/routes.php`.

**Decisions worth knowing**

- **One revenue definition.** `App\Services\SellerCenter\Revenue` holds it as a constant expression over
  delivered lines; the daily briefing and the home KPIs both read it. A second definition would have let the
  two screens disagree about the same number.
- **Issues carry their action.** `IssueAction` maps a detector's `action_key` and params to a real
  destination, carrying the affected ids into the target list as `?ids=`, so an issue's count and the list it
  opens always agree.
- **Sentences, not fragments.** `Copy::line()` / `choice()` / `duration()` / `sla()` translate whole
  sentences with `:placeholders`. `translate()` alone upper-cases every English result and takes no
  placeholders, which produced "1–10 Of 218" and word orders no Arabic translator could fix.
- **Detectors explain themselves.** Every producer writes the number and the cause, not a restated title.
- **`null` is not zero.** A missing comparison renders `—`; a zero renders no badge; an empty section is not
  rendered at all.

**Gates** — the exception loop works end to end: a Control Tower issue drills into a filtered list whose
count matches, the action resolves it, and the resolution shows in the entity timeline. Verified visually at
1440×950 in English LTR and Arabic RTL. 13 tests (32 across waves 1–2, 1800 in the suite, 0 failures).

**Copy** — 7,440 keys in `en`, `sy` and `sa`, seeded by `SellerCenterCopySeeder`. No production string is
hard-coded in a component.

---

## Standing rules for every remaining wave

Taken from PART 21 and `13-implementation-priority.md`; a wave is not done until all of them hold.

1. Design implemented from the handoff, not approximated.
2. Real data only — no fake sales, issues, balances, scores, stock, payouts, brand claims or automation
   results (PART 14). A number with no source renders `—`.
3. No existing capability lost. Every touched action is classified PRESERVED / MOVED / IMPROVED / REPLACED /
   DEPRECATED (PART 15).
4. The wave's WEB MISSING rows from the parity matrix are closed, and the matrix row is marked closed here.
5. Permissions enforced server-side, not by hiding the control.
6. Arabic RTL and English both complete, with key parity.
7. Responsive at all five breakpoints; every screen ships its loading, empty, error and permission states.
8. Tests pass; the audit trail records what the system did on the seller's behalf, with undo where the server
   reports it revertible.
9. No new table, filter, badge or drawer pattern invented locally — a new pattern is a change to `04`/`05`.
10. After each web wave, the matching Flutter feature is audited against it (PART 11): same terminology,
    statuses, permissions, validation, calculations, thresholds and audit behaviour.

## Open

- Waves 3–8, in the order above.
- Per-wave Flutter audits (PART 11) and the cross-client parity tests of PART 16 — a setting written from one
  client is visible in the other, a permission denied in one is denied in the other.
