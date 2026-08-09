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

## The honest scope line

This is the roles/permissions/team **foundation**, not yet functional staff access: no one can sign in
as staff until the guard lands. Stated here and in the UI rather than implied by the presence of a
password field.
