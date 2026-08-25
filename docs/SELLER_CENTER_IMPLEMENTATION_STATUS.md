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

**One panel, at `/vendor`.** The Seller Center's screens are *added to* the panel sellers already use —
they do not replace or remove anything that is there.

Every existing page is still at its own address, working as it works today: `/vendor/dashboard` is still the
classic dashboard, `/vendor/orders/list/all` is still the classic order list. The new screens take their own
`/vendor` addresses beside them (`/vendor/overview`, `/vendor/control-tower`, `/vendor/automation`, …), and
the two shells are bridged in both directions: the classic sidebar carries a **Seller Center** group built
from the same navigation registry the new rail reads, and the new navigation links straight back to the
classic pages. A screen that has not shipped is absent from both rather than dead in either.

The legacy panel is not re-themed. It carries 126 hard-coded colours outside its token block, and repainting
it in place would put every existing seller screen at risk for a visual gain. The new screens bring the
design; the old ones keep working exactly as they do now until a wave replaces one — and that replacement,
when it comes, is a decision taken per screen, not a side effect (PART 15).

## Wave ledger

| Wave | Scope | State | Commit |
|---|---|---|---|
| 1 | Foundation — tokens, shell, component library, table + filter system, status renderers, states, RTL | **Done** | `b969dd21` |
| 2 | Core seller operations — Home, Control Tower, Issue Center, Orders, Order detail, Products, Inventory, Movement ledger | **Done** | `e5125f33`, `53085acb` |
| 3 | Automation — rules, builder, history + undo, opportunities | **Done** (scheduled operations deferred, see below) | — |
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

## Wave 3 — Automation

**Built** — Automation rules list, rule builder, preview-matches, automation history with the run drawer and
Undo, Opportunities. Server work that the screens needed and did not have: rule scope, a field schema, and a
safety classification.

**Decisions worth knowing**

- **The form is derived from the validator.** `SettingField` reads each setting's Laravel rule and returns
  the control it needs — type, bounds, options. A trigger whose threshold gains an upper bound gets a bounded
  input the same day, in both clients, because there is only one statement of what is allowed.
- **A rule can be pointed at part of the catalogue.** `scope` (brands, categories, products) is new, nullable,
  and absent by default, so every rule that already exists keeps applying exactly as it did. Without it, "mark
  down anything that has not sold in ninety days" was a rule no sensible seller could switch on.
- **The safety class is the server's answer.** An action the signed-in person may not perform, or one whose
  changes cannot be put back, is marked *Cannot be automated* with the reason. There is deliberately no
  "needs confirmation" badge: nothing implements a confirmation step, and a badge promising one would lie.
- **`matched` is never called `applied`.** A capped run matched a great many things and changed none of
  them; the history says exactly that, in the row and in the drawer.
- **Undo restores only what the action owns.** A change somebody else has since overwritten is not offered as
  undoable — putting it back would overwrite their decision with one taken before it.
- **Opportunities are computed, never invented.** Three types, each from this shop's own data: fast sellers
  running out of cover, products viewed and not bought, listings under their category's median. A type with
  no data behind it does not render at all — a conversion rate with no views is not a low rate, it is no
  measurement (PART 14).

**Gates** — verified end to end in the browser: a rule created from the catalogue, previewed (1 match, no
change made), run (the listing hidden, the trail written), and undone (the listing restored, the trail
stamped). 22 tests for the wave, 54 across waves 1–3, 1822 in the suite, 0 failures.

**Not built, and why**

- **Scheduled operations (A4)** — there is no server for it. Scheduled price changes, timed activations and
  campaign starts do not exist as a domain, and a screen listing them would have nothing to list. Recorded as
  BACKEND MISSING; it needs its own wave, not a page.
- **The IF condition builder (A2)** — the rule model has no condition expressions, and a builder writing
  conditions nothing evaluates would be a form that silently does nothing. The practical half of it — limiting
  a rule to a brand, a category or a list of products — is what `scope` implements.
- **"Eligible for campaign" opportunity (A5)** — sellers cannot join a campaign from the panel, so the card
  would have no action behind it.

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
