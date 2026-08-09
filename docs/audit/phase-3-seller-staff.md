# Phase 3 — Seller staff & roles (Stage A, foundation)

## Why this — and why bounded

Measured: the platform has no seller-staff concept at all. A shop is operated by the single seller
account, with no way to grant a colleague scoped access. Completing the Seller Center needs staff with
roles.

This is the one Phase 3 item that does not decompose into the safe additive slice the other 18
followed: a staff sub-account **is** a new authentication surface — its own login, guard, and
per-route enforcement — a larger, riskier change than an additive table plus a resolver. So this
commit builds the **foundation** deliberately, and the login/guard/enforcement is named as the explicit
next step rather than rushed. This scope was chosen in agreement with the maintainer.

## What shipped

`seller_roles` (a named role owned by a seller, carrying a set of permission keys) and `seller_staff`
(a team member assigned a role, with credentials stored — hashed — ready for the deferred login).
`SellerPermissionService` owns:

- the **permission catalog** — the vendor capabilities a role can grant, grouped (products, orders,
  inventory, promotions, finance, reviews, settings), each a stable key like `orders.manage`;
- `sanitize()`, which strips any key not in the catalog, so a role can never store an unknown
  permission;
- the **resolver** — `roleHas()` and `staffCan()`, which answer "may this staff member do X?" through
  their assigned role, defaulting to *no* for an inactive member or one with no role.

A seller screen at `vendor/business-settings/staff`: define roles with permission checkboxes and manage
the team, every row scoped to `auth('seller')` so one shop can never touch another's staff. The UI
states plainly that staff sign-in is coming.

## Connected, and the deferred step named

`staffCan()` is the **seam**: it is built and tested now precisely so the future staff-login guard has
only to invoke it — the permission model and its enforcement logic already exist and are verified. What
is deferred is the authentication (a staff guard, a login screen) and the middleware that calls
`staffCan()` on each vendor route. That is the honest boundary of this commit.

## Backward compatibility & data safety

Two new tables (guarded `up()`, working `down()`); no original migration touched, the seller auth
untouched. Passwords are stored hashed. A role assigned to a staff member is detached (not orphaned) if
the role is deleted, and a staff member may only be assigned a role belonging to the same seller
(cross-seller assignment is refused with a 403).

## Verification

- **7 feature tests** (`SellerPermissionTest`): the catalog is non-empty with unique keys, `sanitize`
  strips unknown keys, a role grants only what it holds, an inactive role grants nothing, `staffCan`
  resolves through the role, an inactive or role-less staff member can do nothing, and lists are scoped
  to the seller. Full suite **633 passed, 1 skipped**.
- **Runtime verified** against live MariaDB through the real HTTP stack as an authenticated seller:
  created a role (`orders.view`, `orders.manage`) and a staff member assigned to it — the staff row was
  scoped to the seller, the password stored **hashed** (not plaintext), and the resolver returned
  `orders.manage` → true, `finance.view` → false. Test rows removed.

## The honest scope line (at foundation time)

This is the roles/permissions/team **foundation**, not yet functional staff access: no one can sign in
as staff until the guard lands. Stated here and in the UI rather than implied by the presence of a
password field.

## Update — the deferred login has landed

The guard, login and per-route enforcement named above as the next step are now built.

- **Login** (`StaffLoginController`, `vendor/staff-auth/login`): a staff member authenticates with their
  own hashed credentials and is then signed in **as their parent seller** on the existing `seller`
  guard, with `seller_staff_id` stamped on the session. That one choice is what keeps it small and safe
  — every vendor controller already scopes by `auth('seller')`, so a staff member operates their shop
  with **no controller change**, and the owner's own login path is untouched and never sets that key.
- **Enforcement** (`SellerStaffAccessMiddleware`, added after `seller` on the whole vendor group): a real
  owner (no `seller_staff_id`) passes straight through. For a staff session the required permission is
  derived from the vendor URL and checked via the already-tested `staffCan()`. The map is
  **deny-by-default** — navigation is allowed, a core domain needs its catalog permission, and anything
  unmapped is refused (403), so a gap fails closed. A stale/tampered staff session (missing, inactive, or
  not matching the signed-in seller) is dropped and bounced to the staff login.
- **Owner-facing**: the staff management page now shows the staff sign-in link to share (replacing the
  "coming soon" note); the `SellerStaff` model docblock now describes a sign-in account.

### Verification of the login

- **7 tests** (`SellerStaffAccessTest`) pin the security-critical URL→permission map: navigation is
  allowed, read needs `.view` and write needs `.manage`, the settings area splits `staff.manage` from
  `shop_settings.manage`, every mapped key is a real catalog key, and an unmapped area is **denied**.
  Full suite **652 passed, 1 skipped**.
- **Runtime verified** end-to-end through the real HTTP stack against live MariaDB: a staff member with
  only `orders.view` signed in (302), reached the dashboard (200, baseline) and order pages (200,
  granted), was **refused** the promotions and products pages (403, not granted), and after logout the
  dashboard redirected to login (session cleared). Test role and staff removed afterward.

### The honest boundary now

Enforcement is **deny-by-default over the mapped core domains** (catalogue, orders, promotions, reviews,
finance, settings, and navigation). A staff member can act only where their role grants it and where the
domain is mapped; niche vendor areas not in the map are refused rather than silently allowed. Widening
coverage to more areas is additive map entries, not a redesign.
