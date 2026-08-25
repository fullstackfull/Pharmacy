# Parity — security_integrations

[← back to the matrix](../SELLER_WEB_APP_PARITY.md) · 37 capabilities

**8** BOTH · **26** WEB MISSING · **1** WEB ENHANCEMENT · **2** APP ADAPTATION

## Structural facts the implementer must know

```
SCALE OF THE GAP. The Flutter Security Centre (/home/user/sillercenter-syria-cosmatics/lib/features/security, 1,843 lines across 8 files, reached from lib/features/menu/screens/more_screen.dart:163-165) is four tabs: People, Roles, Keys & webhooks, Activity log. The web vendor panel covers roughly the Roles tab and half the People tab and nothing else. Everything under "integrations" (API keys, webhooks, deliveries) and the entire seller-facing audit trail have ZERO web presence — no route in routes/vendor/routes.php, no controller in app/Http/Controllers/Vendor/, no blade in resources/views/vendor-views/. Verified by: grep -rln 'SellerApiKey|SellerWebhook|SellerAuditTrailService' app/Http/Controllers/Vendor/ → no matches.

WEB PANEL SURFACE, EXACTLY. /home/user/Pharmacy/resources/views/vendor-views/marketplace/staff.blade.php (132 lines) is the whole domain on web: staff sign-in link card (:12-27), create-role form (:31-54), roles table with delete only (:56-80), add-staff form (:84-103), team table with remove only (:105-128). Reachable from resources/views/layouts/vendor/partials/v2/_side-bar.blade.php:419-421. Gated on staff.manage via app/Http/Middleware/SellerStaffAccessMiddleware.php:105.

DEAD BACKEND ON WEB. routes/vendor/routes.php:429 (PUT staff/{id}) and :433 (PUT staff/roles/{id}) are wired to SellerStaffController::updateStaff (:98-118) and ::updateRole (:55-66), but NO blade posts to either — grep 'staff.update|roles.update' over resources/views/vendor-views/** returns nothing. Adding edit forms is the cheapest parity win in this domain: the service layer (SellerTeamService) is already shared with the API.

BLOCKING BUG — every /security write from the app is fatal. app/Http/Controllers/RestAPI/v3/seller/SellerSecurityController.php references SellerPrincipal (:369, :380, :384) and SellerApiAuthMiddleware (:382) but imports neither (import block is :5-14). Unqualified, both resolve to App\Http\Controllers\RestAPI\v3\seller\* which does not exist (SellerPrincipal lives at app/Services/Marketplace/SellerPrincipal.php:21; the middleware at app/Http/Middleware/SellerApiAuthMiddleware.php). Every write route — storeRole, updateRole, destroyRole, storeStaff, updateStaff, signOutStaff, destroyStaff — calls $this->principal($request) and will throw Error: Class not found. SellerIntegrationController.php:6,14 imports both correctly, so only the security half is affected. Fix before any parity work is validated end to end.

SECURITY GAP — SSRF guard is bypassable. SellerIntegrationController::storeWebhook calls refuseDestination() twice (:206-208 and :210-212 — a duplicated block, harmless but sloppy), while updateWebhook (:242-276) never calls it at all. A seller can create an endpoint pointing at https://example.com, pass the check, then PUT it to an internal address. Any web implementation must route both create and update through refuseDestination()/SellerWebhookDispatcher::mayDial().

CLIENT-SIDE BUSINESS STATE: none. grep for SharedPreferences/prefs over lib/features/security returns nothing. Every piece of state — roles, staff, keys, webhooks, audit filter — is server-owned and re-read after each write (security_controller.dart:191-196 reloads rather than patching in place, deliberately). The only client-held values are the one-shot plaintext credential and signing secret (_freshKey / _freshSecret, security_controller.dart:60-71), held in memory only until dismissed, never persisted. Nothing to flag.

BACKEND AFFORDANCES NO SURFACE USES (build these into web while you are there, and consider adding to the app):
- API key expiry: 'expires_at' is validated and stored (SellerIntegrationController.php:92, :103; SellerApiKeyService::issue :46, :58) but the Flutter key sheet never sends it (security_widgets.dart:628-668 collects name + scopes only) and web has no key UI. Keys are effectively permanent-until-revoked on every surface today.
- Role status (active/inactive): accepted by both updateRole endpoints (SellerSecurityController.php:125; SellerStaffController.php:60) and enforced in SellerPermissionService::roleHas (:78-84), but neither UI can set it — the Flutter role sheet posts name + permissions only (security_controller.dart:96). staff.blade.php:65 only displays a badge when status !== active.
- Delivery filtering by status and pagination: SellerIntegrationController::deliveries supports ?status= and ?limit= with total_size/limit/offset (:409-418); the app sends neither (security_repository.dart:87-89) and renders an unpaginated list.
- Audit before/after diffs: SellerAuditTrailService::recent returns 'before' and 'after' for every entry (:105-106) — the bank-details change diff, the role permission diff — and AuditEntryModel (security_models.dart:99-130) drops both. Also drops ip_address/subject in the tile (widgets:446-468 renders action + actor + timestamp only). The web activity log should render the diff; that is the main reason a seller opens it.
- Audit ?limit= (SellerSecurityController.php:356, capped at SellerAuditTrailService::MAX_ROWS = 200) — app always takes the default 50 with no paging.

CAPABILITIES ABSENT EVERYWHERE (candidate BACKEND MISSING for a future phase, listed so nobody assumes parity means done): no webhook signing-secret rotation endpoint (secret is set once at creation, SellerIntegrationController.php:214, and there is no route to re-issue it — losing it means deleting and re-creating the endpoint); no manual replay/retry of a failed delivery (retries are automatic only, SellerWebhookDispatcher::MAX_ATTEMPTS = 5); no way to view a delivery's request payload (only the response excerpt is returned); no per-key or per-staff last-activity view beyond last_used_at/last_login_at.

PERMISSION MODEL, for the web build. /security/* is gated on staff.manage (routes/rest_api/v3/seller/seller.php:540) — deliberately including the reads, because the team list carries every employee's email and the audit trail carries before/after of bank-detail changes. /integrations/* is gated on shop_settings.manage (:514), reads included, because the key list enumerates the shop's other credentials. Additionally, every WRITE in both controllers refuses an API-key principal outright (SellerSecurityController::refuseIntegration :369-378 "api_keys_cannot_manage_people"; SellerIntegrationController::refuseIntegration :505-514 "api_keys_cannot_manage_api_keys") — a leaked key must not be able to mint another, plant a human account, or delete the webhook that would have raised the alarm. Any web equivalent inherits this for free (session-authenticated) but the panel must keep the staff.manage / shop_settings.manage split rather than gating the whole page on one permission.

SHARED SERVICE LAYER — reuse, do not re-implement. SellerTeamService (app/Services/Marketplace/SellerTeamService.php) is already called by both the vendor controller and the API controller and holds every rule worth honouring: permissions narrowed to what the writer holds (:245-254), deactivation clearing auth_token AND revoking the keys that person issued (:154-157), a password change ending existing sessions (:162-164), role deletion detaching holders (:84-85). A web Security Centre should call SellerAuditTrailService, SellerApiKeyService and SellerWebhookDispatcher the same way rather than re-deriving anything — the app and panel drifting on permission semantics is the exact failure this layer was built to prevent.

DEVICE SPECIFIC: nothing in this domain. No biometrics, camera, or native-only affordance — grep for 'biometric|local_auth|pinLock' over the Flutter app returns no matches. Clipboard copy (security_screen.dart:62) is the only platform API touched and has a direct web equivalent.
```

## BOTH (8)

**List the shop's staff members**  
`staff.manage`  
- App — Yes — People tab staff section: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:114-127
- Web — Yes — "Team" card table: /home/user/Pharmacy/resources/views/vendor-views/marketplace/staff.blade.php:105-128
- Server — GET /api/v3/seller/seller-center/security/staff → SellerSecurityController::staff (SellerSecurityController.php:172-187); web reads SellerPermissionService::staffFor (app/Http/Controllers/Vendor/Marketplace/SellerStaffController.php:37)
- Evidence — flutter: security_repository.dart:21, widgets/security_widgets.dart:173-225 (StaffTile) | web: staff.blade.php:111-124, SellerStaffController.php:31-40

**Add a staff member (name, email, password, role)**  
`staff.manage`  
- App — Yes — showStaffSheet with member == null: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:567-626
- Web — Yes — "Add staff member" form: /home/user/Pharmacy/resources/views/vendor-views/marketplace/staff.blade.php:84-103
- Server — POST /api/v3/seller/seller-center/security/staff → SellerSecurityController::storeStaff (:196-220); web POST vendor.business-settings.staff.store → SellerStaffController::storeStaff (:76-96). Both call SellerTeamService::createStaff (app/Services/Marketplace/SellerTeamService.php:100-127)
- Evidence — flutter: security_repository.dart:41-45 (POST $_security/staff), security_controller.dart:105-118 | web: staff.blade.php:87-101, routes/vendor/routes.php:428, SellerStaffController.php:76-96

**Remove a staff member from the shop**  
`staff.manage`  
- App — Yes — 'remove' with confirm dialog: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:121-125
- Web — Yes — 'remove' button with confirm: /home/user/Pharmacy/resources/views/vendor-views/marketplace/staff.blade.php:116-119
- Server — DELETE /api/v3/seller/seller-center/security/staff/{id} → SellerSecurityController::destroyStaff (:298-313); web DELETE vendor.business-settings.staff.destroy → SellerStaffController::destroyStaff (:120-126). Both call SellerTeamService::deleteStaff (:177-189)
- Evidence — flutter: security_repository.dart:51, security_widgets.dart:498-519 (confirmThen) | web: staff.blade.php:117-119, routes/vendor/routes.php:430

**List the shop's roles**  
`staff.manage`  
- App — Yes — Roles tab: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:148-164
- Web — Yes — 'Roles' card table: /home/user/Pharmacy/resources/views/vendor-views/marketplace/staff.blade.php:56-80
- Server — GET /api/v3/seller/seller-center/security/roles → SellerSecurityController::roles (:58-70); web reads SellerPermissionService::rolesFor (SellerStaffController.php:36)
- Evidence — flutter: security_repository.dart:18, widgets/security_widgets.dart:227-263 (RoleTile) | web: staff.blade.php:61-77

**Create a role and tick its permissions from the server's grouped catalogue**  
`staff.manage`  
- App — Yes — showRoleSheet: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:521-565
- Web — Yes — 'Create role' form with grouped checkboxes: /home/user/Pharmacy/resources/views/vendor-views/marketplace/staff.blade.php:31-54
- Server — POST /api/v3/seller/seller-center/security/roles → SellerSecurityController::storeRole (:81-99); web POST vendor.business-settings.staff.roles.store → SellerStaffController::storeRole (:42-53). Both call SellerTeamService::createRole (:35-56)
- Evidence — flutter: security_repository.dart:31-35, security_controller.dart:96-97 | web: staff.blade.php:34-52, routes/vendor/routes.php:432

**Delete a role (holders keep their account and lose the role's rights)**  
`staff.manage`  
- App — Yes — with an explicit warning dialog: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:159-163
- Web — Yes — delete button with generic confirm: /home/user/Pharmacy/resources/views/vendor-views/marketplace/staff.blade.php:68-71
- Server — DELETE .../security/roles/{id} → SellerSecurityController::destroyRole (:147-162); web → SellerStaffController::destroyRole (:68-74). Both call SellerTeamService::deleteRole, which detaches holders first (:79-95)
- Evidence — flutter: security_repository.dart:38 | web: staff.blade.php:69-71, routes/vendor/routes.php:434

**Build the role form from the server's permission catalogue rather than a hard-coded list**  
`staff.manage`  
- App — Yes — groups fetched and rendered as chip groups: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:536-557
- Web — Yes — $catalog looped into grouped checkboxes: /home/user/Pharmacy/resources/views/vendor-views/marketplace/staff.blade.php:39-50
- Server — GET .../security/permissions → SellerSecurityController::permissions (:45-48); web injects SellerPermissionService::catalog() (SellerStaffController.php:38, service at app/Services/Marketplace/SellerPermissionService.php:29-39)
- Evidence — flutter: security_repository.dart:15, security_controller.dart:205-215 | web: staff.blade.php:39-50, SellerStaffController.php:38

**A staff member signs in with their own credentials (rather than being handed the owner's login)**  
`none`  
- App — Yes — the ordinary login screen accepts staff credentials; no separate screen needed: /home/user/sillercenter-syria-cosmatics/lib/features/auth/domain/repositories/auth_repository.dart:28 posts to AppConstants.loginUri
- Web — Yes — dedicated staff login page: /home/user/Pharmacy/resources/views/vendor-views/auth/staff-login.blade.php:31 and routes/vendor/routes.php:76-82
- Server — POST /api/v3/seller/auth/login falls through to staff credentials (app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:28-52 staffToken, :74-84 fallthrough); web StaffLoginController
- Evidence — flutter: lib/utill/app_constants.dart:13 (loginUri), auth_repository.dart:28 | web: routes/vendor/routes.php:76-82, resources/views/vendor-views/auth/staff-login.blade.php:31

## WEB MISSING (26)

**See who currently holds a way into this shop (owner + every staff member) and whether each has a live session right now**  
`staff.manage` · wave 7  
- App — Yes — Security Centre "People" tab, access-holder list: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:107-113
- Web — No — staff.blade.php renders only a plain team table with no owner row and no session state
- Server — GET /api/v3/seller/seller-center/security/access → SellerSecurityController::access (app/Http/Controllers/RestAPI/v3/seller/SellerSecurityController.php:325-334); data from SellerAuditTrailService::accessHolders (app/Services/Marketplace/SellerAuditTrailService.php:128-157)
- Evidence — flutter: security_repository.dart:24 (GET $_security/access), security_controller.dart:227-230, widgets/security_widgets.dart:136-171 (AccessHolderTile, signed_in pill at :164-167) | web: /home/user/Pharmacy/resources/views/vendor-views/marketplace/staff.blade.php:105-128 is the only team UI and has no owner/session column; grep of resources/views/vendor-views/** for signed_in/access returns nothing

**See when a staff member last signed in**  
`staff.manage` · wave 7  
- App — Partial — last_login_at is fetched and modelled but never rendered: /home/user/sillercenter-syria-cosmatics/lib/features/security/domain/models/security_models.dart:63 and :74
- Web — No — team table shows name, email, role, status only
- Server — GET .../security/access and GET .../security/staff both return last_login_at (SellerSecurityController.php:184; SellerAuditTrailService.php:153)
- Evidence — flutter: security_models.dart:63 (SellerStaffModel.lastLoginAt), :74 (AccessHolderModel.lastLoginAt) — no widget in widgets/security_widgets.dart prints either | web: /home/user/Pharmacy/resources/views/vendor-views/marketplace/staff.blade.php:109-115 column list has no last-login cell

**Edit an existing staff member (rename, move them to another role / remove their role)**  
`staff.manage` · wave 7  
- App — Yes — showStaffSheet with member != null, PUT: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:567-626 (role dropdown :589-597)
- Web — No UI — the route and controller exist but no blade form ever posts to them
- Server — PUT /api/v3/seller/seller-center/security/staff/{id} → SellerSecurityController::updateStaff (:233-263); vendor equivalent PUT vendor/business-settings/staff/{id} → SellerStaffController::updateStaff (:98-118) is unreachable from any view
- Evidence — flutter: security_repository.dart:41-45 (put: id != null), security_controller.dart:105-118 | web: routes/vendor/routes.php:429 declares the PUT, SellerStaffController.php:98-118 implements it, but grep for 'staff.update' across resources/views/vendor-views/** returns no hits — staff.blade.php:116-119 offers only a delete button

**Switch a staff member off / back on (deactivating ends their live session and revokes the API keys they issued)**  
`staff.manage` · wave 8  
- App — Yes — SwitchListTile in the edit sheet: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:598-611
- Web — No — status is rendered as a read-only badge; nothing can change it
- Server — PUT .../security/staff/{id} with status → SellerSecurityController::updateStaff (:233-263) → SellerTeamService::updateStaff (app/Services/Marketplace/SellerTeamService.php:132-175, token cleared and keys revoked at :154-157)
- Evidence — flutter: security_widgets.dart:598-611 (subtitle 'switching_this_off_ends_their_session_now'), security_controller.dart:105-118 passes status | web: staff.blade.php:115 renders {{ translate($s->status) }} inside a badge with no form around it

**Reset a staff member's password (which also ends every session that password's token was in)**  
`staff.manage` · wave 7  
- App — Yes — 'new_password_optional' field in the edit sheet: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:581-587
- Web — No — password is only settable at creation time
- Server — PUT .../security/staff/{id} password rule at SellerSecurityController.php:249; SellerTeamService::updateStaff hashes it and nulls auth_token (app/Services/Marketplace/SellerTeamService.php:144-164)
- Evidence — flutter: security_widgets.dart:581-587, security_controller.dart:112 (password added to payload only when non-empty) | web: staff.blade.php:93-94 is the only password input and it lives in the create form; no edit form exists

**Sign a staff member out of every device without changing anything else about them (lost-phone response)**  
`staff.manage` · wave 7  
- App — Yes — 'sign_out_everywhere' shown only while they hold a live token: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:120 and widgets/security_widgets.dart:217-218
- Web — No — no route, no controller method, no UI
- Server — POST /api/v3/seller/seller-center/security/staff/{id}/sign-out → SellerSecurityController::signOutStaff (:274-289) → SellerTeamService::signOutStaff (app/Services/Marketplace/SellerTeamService.php:197-206)
- Evidence — flutter: security_repository.dart:48 (POST $_security/staff/{id}/sign-out), security_controller.dart:120 | web: grep 'sign-out|signOut' over routes/vendor/routes.php and app/Http/Controllers/Vendor/Marketplace/SellerStaffController.php returns nothing; the vendor controller has no equivalent of SellerTeamService::signOutStaff

**See which permissions a role actually grants, by name**  
`staff.manage` · wave 7  
- App — Yes — permission names listed on each role card, with 'can_sign_in_and_do_nothing' when empty: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:246-254
- Web — No — the roles table prints only a count ('N permissions'), never which ones
- Server — GET .../security/roles returns the permissions array (SellerSecurityController.php:65)
- Evidence — flutter: security_widgets.dart:246-254 (role.permissions.join(' · ')) | web: /home/user/Pharmacy/resources/views/vendor-views/marketplace/staff.blade.php:66 renders count($r->permissions ?? []) and nothing else

**See how many people hold a given role**  
`staff.manage` · wave 7  
- App — Yes — staff-count pill on each role card: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:240-243
- Web — No
- Server — GET .../security/roles computes staff_count (SellerSecurityController.php:67)
- Evidence — flutter: security_models.dart:25 (staffCount), security_widgets.dart:240-243 | web: staff.blade.php:61-77 has no such column and SellerStaffController.php:36 passes only the role collection

**Rewrite an existing role (rename it, change what it grants) — takes effect on every holder's next request**  
`staff.manage` · wave 7  
- App — Yes — showRoleSheet with role != null, PUT: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:521-565 and screens/security_screen.dart:158
- Web — No UI — route and controller exist but nothing posts to them
- Server — PUT /api/v3/seller/seller-center/security/roles/{id} → SellerSecurityController::updateRole (:110-135); vendor PUT vendor/business-settings/staff/roles/{id} → SellerStaffController::updateRole (:55-66) is unreachable
- Evidence — flutter: security_repository.dart:31-35 (put: id != null), security_controller.dart:96 | web: routes/vendor/routes.php:433 declares it, SellerStaffController.php:55-66 implements it, but grep 'roles.update' over resources/views/vendor-views/** returns no hits — staff.blade.php:68-71 offers only delete

**List the shop's API keys with prefix, scopes, last-used time, last-used IP, expiry, revoked state and whether the key is still usable**  
`shop_settings.manage` · wave 8  
- App — Yes — Keys & webhooks tab: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:185-201, widgets/security_widgets.dart:265-315 (ApiKeyTile; 'never used' at :296-306)
- Web — No — no page, no route, no controller
- Server — GET /api/v3/seller/seller-center/integrations/keys → SellerIntegrationController::keys (app/Http/Controllers/RestAPI/v3/seller/SellerIntegrationController.php:47-67)
- Evidence — flutter: security_repository.dart:54, security_controller.dart:232-239 | web: grep -rln 'SellerApiKey' over app/Http/Controllers/Vendor/ returns nothing; routes/vendor/routes.php has no 'keys' prefix; no blade under resources/views/vendor-views mentions api keys (only an unrelated Google Maps key at order/order-details.blade.php:2038)

**Issue an API key for a name/purpose, scoped to a chosen subset of permissions**  
`shop_settings.manage` · wave 8  
- App — Yes — showKeySheet: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:628-668
- Web — No
- Server — POST /api/v3/seller/seller-center/integrations/keys → SellerIntegrationController::storeKey (:80-114) → SellerApiKeyService::issue (app/Services/Marketplace/SellerApiKeyService.php:46)
- Evidence — flutter: security_repository.dart:57, security_controller.dart:120-140 | web: no vendor route or controller for key issuance (routes/vendor/routes.php contains no 'integrations' or 'keys' group)

**Offer only the scopes the person issuing the key actually holds (so a key can never be an escalation)**  
`shop_settings.manage` · wave 7  
- App — Yes — chips built from grantable_scopes returned by the server: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:648-660
- Web — No
- Server — grantable_scopes in GET .../integrations/keys (SellerIntegrationController.php:65, :487-497); narrowed again server-side in SellerApiKeyService::grantable (:134)
- Evidence — flutter: security_controller.dart:232-239 (_grantableScopes), security_widgets.dart:650-659 (comment: 'Only what the person issuing it actually holds') | web: nothing consumes grantable_scopes — grep over resources/views/vendor-views returns no hits

**Show a newly issued key exactly once, with a copy action and a clear 'it is not shown again' warning**  
`shop_settings.manage` · wave 7  
- App — Yes — FreshCredentialBanner: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:13-59, mounted at screens/security_screen.dart:55-64
- Web — No
- Server — POST .../integrations/keys returns the plaintext once and stores only a hash (SellerIntegrationController.php:106-113; SellerApiKeyService.php:46-64)
- Evidence — flutter: security_controller.dart:60-67 (_freshKey), :129-133 (captured from response), security_widgets.dart:39-55 | web: no page renders an issued key because none can be issued

**Revoke an API key immediately (effective on the very next request carrying it)**  
`shop_settings.manage` · wave 8  
- App — Yes — revoke button, only offered while the key is usable, with a warning dialog: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:192-201
- Web — No
- Server — DELETE /api/v3/seller/seller-center/integrations/keys/{id} → SellerIntegrationController::revokeKey (:125-142) → SellerApiKeyService::revoke (:113)
- Evidence — flutter: security_repository.dart:60, security_controller.dart:142 | web: no vendor route; grep 'revoke' over routes/vendor/routes.php and app/Http/Controllers/Vendor/ returns nothing for keys

**List webhook endpoints with their subscribed events and real health — last delivered, failing since / how many failures in a row, why the marketplace switched it off, and 'nothing has been sent to this yet'**  
`shop_settings.manage` · wave 8  
- App — Yes — WebhookTile with _health(): /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:317-400 (health text :377-392)
- Web — No
- Server — GET /api/v3/seller/seller-center/integrations/webhooks → SellerIntegrationController::webhooks (:171-180), presenter at :467-484
- Evidence — flutter: security_repository.dart:66, security_controller.dart:246-249, security_models.dart:172-214 | web: grep -rln 'SellerWebhook' over app/Http/Controllers/Vendor/ and resources/views/vendor-views/ returns nothing

**See the catalogue of events an endpoint can subscribe to**  
`shop_settings.manage` · wave 8  
- App — Yes — chips built from the server list: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:686-695
- Web — No
- Server — GET /api/v3/seller/seller-center/integrations/events → SellerIntegrationController::events (:155-158), list at app/Services/Marketplace/SellerWebhookDispatcher.php:38-45 (order.placed, order.status_changed, order.refund_requested, product.stock_low, product.hidden_by_rule, payout.status_changed)
- Evidence — flutter: security_repository.dart:63, security_controller.dart:241-244 | web: no consumer of SellerWebhookDispatcher::EVENTS outside the API controller (grep over app/Http/Controllers/Vendor/ returns nothing)

**Add a webhook endpoint (name, https URL, chosen events)**  
`shop_settings.manage` · wave 8  
- App — Yes — showWebhookSheet: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:670-708
- Web — No
- Server — POST /api/v3/seller/seller-center/integrations/webhooks → SellerIntegrationController::storeWebhook (:192-230); https-only rule at :461, SSRF destination check at :206-212 / :442-453
- Evidence — flutter: security_repository.dart:69-73, security_controller.dart:144-167 | web: routes/vendor/routes.php has no webhook routes; no blade under resources/views/vendor-views mentions webhooks

**Show a new endpoint's signing secret exactly once (the HMAC key the receiver verifies deliveries with)**  
`shop_settings.manage` · wave 8  
- App — Yes — same FreshCredentialBanner path, captured only on create: /home/user/sillercenter-syria-cosmatics/lib/features/security/controllers/security_controller.dart:160-162
- Web — No
- Server — POST .../integrations/webhooks returns 'secret' once (SellerIntegrationController.php:214, :225-229)
- Evidence — flutter: security_controller.dart:69-71 (_freshSecret), :160-162, security_screen.dart:55-64, security_widgets.dart:40 (copy_this_secret_now) | web: not present — no webhook UI at all

**Edit an endpoint (change URL/name/events) — which clears its failure run and any marketplace-applied switch-off**  
`shop_settings.manage` · wave 8  
- App — Yes — showWebhookSheet with webhook != null: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:212
- Web — No
- Server — PUT /api/v3/seller/seller-center/integrations/webhooks/{id} → SellerIntegrationController::updateWebhook (:242-276; failure run and disabled_reason reset at :262-270)
- Evidence — flutter: security_repository.dart:69-73 (put: id != null), security_controller.dart:144-167 | web: no route, no controller, no view

**Pause or resume an endpoint (and use 'resume' to deliberately clear a marketplace-applied disable)**  
`shop_settings.manage` · wave 8  
- App — Yes — toggle button flipping active/paused: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:214 and widgets/security_widgets.dart:364-367
- Web — No
- Server — PUT /api/v3/seller/seller-center/integrations/webhooks/{id}/status → SellerIntegrationController::setWebhookStatus (:288-319); only active/paused are settable (app/Models/SellerWebhook.php:21)
- Evidence — flutter: security_repository.dart:76-77, security_controller.dart:169-170 | web: not present

**Delete a webhook endpoint (its delivery record survives)**  
`shop_settings.manage` · wave 8  
- App — Yes — with a dialog saying the record of what was sent stays: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:215-219
- Web — No
- Server — DELETE /api/v3/seller/seller-center/integrations/webhooks/{id} → SellerIntegrationController::destroyWebhook (:330-347)
- Evidence — flutter: security_repository.dart:80, security_controller.dart:172 | web: not present

**Send a test delivery to an endpoint (a real, signed, queued delivery of a real event shape)**  
`shop_settings.manage` · wave 8  
- App — Yes — 'send_test' fires the endpoint's first subscribed event: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:213
- Web — No
- Server — POST /api/v3/seller/seller-center/integrations/webhooks/{id}/test → SellerIntegrationController::testWebhook (:360-394), queued via DeliverSellerWebhook
- Evidence — flutter: security_repository.dart:83-84, security_controller.dart:174-175 | web: not present

**Browse webhook delivery attempts — event, HTTP response code (or 'no answer'), attempt count, error, response-body excerpt and the next scheduled attempt**  
`shop_settings.manage` · wave 8  
- App — Yes — DeliveryTile: /home/user/sillercenter-syria-cosmatics/lib/features/security/widgets/security_widgets.dart:402-444
- Web — No
- Server — GET /api/v3/seller/seller-center/integrations/deliveries → SellerIntegrationController::deliveries (:407-433)
- Evidence — flutter: security_repository.dart:87-89, security_controller.dart:251-254, security_models.dart:216-255 | web: grep -rln 'SellerWebhookDelivery' over app/Http/Controllers/Vendor/ returns nothing

**Narrow the delivery log to one endpoint, then widen it back to all endpoints**  
`shop_settings.manage` · wave 8  
- App — Yes — per-endpoint 'deliveries' action plus a 'show all' header action: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:220 and :222-228
- Web — No
- Server — GET .../integrations/deliveries?webhook_id= (SellerIntegrationController.php:410)
- Evidence — flutter: security_controller.dart:178-181 (showDeliveriesFor), security_repository.dart:87-89 | web: not present

**Read the shop's activity log — what was done in this shop and by whom (including by people who have since left and keys that have since been revoked), plus decisions the marketplace recorded about the shop**  
`staff.manage` · wave 7  
- App — Yes — 'Activity log' tab: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:237-293, AuditTile at widgets/security_widgets.dart:446-468
- Web — No — the only audit-log page in the project is admin-only (routes/admin/routes.php:577)
- Server — GET /api/v3/seller/seller-center/security/audit → SellerSecurityController::audit (:348-359) → SellerAuditTrailService::recent (app/Services/Marketplace/SellerAuditTrailService.php:85-115)
- Evidence — flutter: security_repository.dart:27-28, security_controller.dart:222-225 | web: SellerAuditTrailService is referenced only by SellerSecurityController (grep -rn 'SellerAuditTrailService' over app/ routes/ Modules/ returns that file only); routes/vendor/routes.php has no audit/activity route

**Filter the activity log by area — everything / the team / the automation rules / the API keys**  
`staff.manage` · wave 8  
- App — Yes — ChoiceChip row mapping to action prefixes seller.staff, seller.automation, seller.api_key: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:244-270
- Web — No
- Server — GET .../security/audit?action={prefix} — prefix LIKE match at SellerAuditTrailService.php:91-93
- Evidence — flutter: security_screen.dart:244-249 (_filters map), security_controller.dart:90-94 (filterAudit) | web: not present

## WEB ENHANCEMENT (1)

**Share the staff sign-in link with the team**  
`staff.manage`  
- App — No — the app has no equivalent, and needs none: staff sign in through the same login screen
- Web — Yes — copyable link card: /home/user/Pharmacy/resources/views/vendor-views/marketplace/staff.blade.php:12-27
- Server — route('vendor.staff-auth.login') — routes/vendor/routes.php:78

## APP ADAPTATION (2)

**Copy a freshly issued credential to the clipboard**  
`none`  
- App — Yes — Clipboard.setData: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:62
- Web — No (would be a navigator.clipboard button in the web build)
- Server — none — client-side only
- Evidence — flutter: security_screen.dart:62 (import flutter/services.dart at :2), security_widgets.dart:52 | web: not applicable until key issuance exists in the panel

**Refresh the security data on demand**  
`none`  
- App — Yes — pull-to-refresh on all four tabs: /home/user/sillercenter-syria-cosmatics/lib/features/security/screens/security_screen.dart:99-102, :140-143, :177-180, :273-276
- Web — Partial — a normal page reload after each POST redirect (SellerStaffController returns back())
- Server — same read endpoints; controller reloads everything after every successful write (security_controller.dart:191-196)
- Evidence — flutter: security_screen.dart:99-102 (RefreshIndicator onRefresh: security.load) | web: /home/user/Pharmacy/app/Http/Controllers/Vendor/Marketplace/SellerStaffController.php:52, :65, :95 all return back(), so the page re-renders from the DB

