# Paymera eGate — payment gateway integration

## Why this

The store needed a Syrian card gateway. 6Valley already ships ~13 built-in gateways (Stripe, SSLCommerz,
Paystack …); the request was to add **Paymera eGate** the same way — the admin pastes the Terminal ID +
Basic-Auth username + token, enables it, and it appears at checkout like any other gateway, on web and
in the Flutter app.

## How gateways work here (the mechanism I plugged into)

A gateway in this codebase is **not** an interface implementation — it is a convention: a controller
using the `Processor` trait, whose **string key** is registered in a handful of lists plus one
`addon_settings` row. The unified dispatcher `Payment::generate_link()` maps that key to a
`payment/{key}/pay` URL; both web checkout and the Flutter config endpoint read the **same**
`payment_gateways()` source. (The `Modules/Gateways` paid add-on is absent in this build — `.gitignore`d
— so the built-in path is active; creating that directory would disable every built-in gateway, so it
was left alone.)

## What shipped

`paymera` registered end-to-end, disabled until configured:

- **Controller** `app/Http/Controllers/Payment_Methods/PaymeraController.php` (mirrors Paystack): reads
  its credentials from `addon_settings` via `Processor::payment_config('paymera', …)`, and picks the
  test (`egate-t.paymera.cc`) or live (`egate.paymera.cc`) base URL by mode.
  - `index()` calls **POST /api/create-payment** (HTTP Basic Auth, server-side only) with the terminal
    id and an **integer** amount, remembers eGate's `paymentId`, and redirects the customer to the
    returned hosted card page.
  - `callback()` (the return URL and the server trigger) reads **GET /api/get-payment-status/{id}** —
    the authoritative result — and marks the `payment_requests` row paid **only on status `A`**, storing
    the bank `rrn` as the transaction id; `F`/`C` fail, `P` (pending) is left open. It fires the row's
    own `success_hook`/`failure_hook`, so the one controller serves order payment, wallet top-up and
    order-edit-due alike. Idempotent: an already-paid request short-circuits.
- **Wiring** (append-only, so nothing existing shifts): `Payment::generate_link()` route map;
  `GlobalConstant::DEFAULT_PAYMENT_GATEWAYS` and `Helpers::getDefaultPaymentGateways()` (the two lists
  that surface a gateway on the admin screen and at web+app checkout); `PaymentGatewayTrait`
  supported-currency map (`SYP`, `USD`); `PaymentMethodUpdateRequest` credential rules;
  `Constant::GATEWAYS_PAYMENT_METHODS`; and the `payment/paymera/{pay,callback}` routes.
- **Admin config**: one seeded `addon_settings` row (key_name `paymera`, `payment_config`, disabled,
  empty `terminal_id`/`username`/`token`). The existing Payment-Methods screen renders those keys as
  inputs automatically — no new blade — so the admin pastes the credentials and toggles it on, exactly
  like Stripe.

## The load-bearing detail

`PaymentMethodController::UpdatePaymentConfig` stores `live_values => $request->validated()`, and
`validated()` returns **only fields that have a validation rule**. So a credential with no rule is
silently dropped and never saved. The `paymera` branch in `PaymentMethodUpdateRequest` (terminal_id,
username, token, status — all `required`) is what makes the pasted credentials actually persist; it is
pinned by a test.

## Amount & currency

eGate infers the currency from the provisioned terminal, so create-payment carries **no currency
field** and the amount is an **integer with no decimals** (`(int) round(payment_amount)`). This is exact
for **SYP** (no minor unit — the intended currency for a Syrian terminal). Paymera's supported-currency
entry lists SYP (and USD for flexibility); the store's SYP currency must be active for the admin to
enable the gateway. If a terminal is ever provisioned in a minor-unit currency, the amount unit must be
aligned at provisioning — noted so it isn't mistaken for a defect.

## Non-breaking / Flutter-safe

- **Append-only** to every gateway list and the dispatcher map — no key renamed, reordered or removed —
  so the mobile config endpoint's shape is unchanged (a test pins the existing prefix order).
- **New controller + new routes only**; no existing gateway, route or API contract touched. `index()`
  returns a redirect and the callback returns via `payment_response()` (the `external_redirect_link?flag=…`
  contract the web and the app WebView both rely on).
- **Off by default**: the seeded row is disabled with empty credentials; `payment_gateways()` filters
  `is_active=1`, so it never shows at checkout until the admin enables it.
- Additive, idempotent seed migration (`firstOrCreate`, so a re-run never wipes entered credentials;
  `down()` removes only the paymera row).

## Verification

- **11 feature tests** (2 files): registration (both lists, append-only order, supported currency,
  admin-list row, and the credential-rules guard) and the payment flow (create-payment with Basic Auth +
  integer amount → hosted redirect; unconfigured makes no call; callback marks paid only on `A` with the
  rrn; `F`/`P` never pay; idempotent). **Full suite: 705 passed, 1 skipped — no regressions** (was 694).
- **Runtime verified** on live MariaDB: the seed migration creates the row; `paymera` is in both gateway
  lists and the admin config list; it is hidden at checkout while `is_active=0`; and after activating it
  with test credentials it appears in the checkout gateway query (the same source web + app read). The
  test activation was reverted, leaving the row seeded-and-disabled.

## Admin setup steps

1. Open **3rd Party Setup → Payment Methods → Paymera**.
2. Choose mode (test/live), paste the **Terminal ID**, **username** and **token** provided by Paymera /
   your bank, and enable it.
3. Ensure **SYP** is an active currency in the store.
4. Give Paymera your production public IP if going live (the production API is IP-restricted).

The eGate HTTP handshake happens with real traffic once real credentials are in place; the flow is
proven here against the documented v3.0 contract with faked transport.
