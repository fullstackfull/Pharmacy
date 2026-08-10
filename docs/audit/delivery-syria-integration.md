# Delivery Syria — optional shipping-company integration

## Why this

The platform had no concept of an external courier *account*. "Third-party delivery" was a free-text
label an admin typed onto an order (`orders.delivery_service_name` + `third_party_delivery_tracking_id`)
— no credentials, no rate sync, no automated dispatch, no status feedback. The request was to add
**Delivery Syria** as one selectable shipping company: the admin pastes a Secret, enables it, and from
then on the store can hand orders to the courier and receive status updates back — without disturbing
anything else.

## What shipped

An **additive, opt-in** integration, off until an admin enables it and pastes a token. Two directions.

**Configuration** (`admin/third-party/delivery-syria`, in the existing "Other Configuration" area).
A single `delivery_syria` row in `business_settings` holds the status toggle, the two secrets, and the
parcel defaults (base URL, platform header, hub / delivery-type / packaging ids, pickup details). Read
and written through `DeliverySyriaConfigService`, which follows the codebase's business-settings
convention **except** that the two secrets are **encrypted at rest** (the codebase otherwise stores
integration secrets in clear; the courier's own documentation requires the Secret never be exposed, so
this deviates deliberately). Secrets are never echoed back to the browser — the fields are blank-to-keep.

**Outbound — the store calling the courier** (`DeliverySyriaClient`):
- **Verify & sync** (`POST /api/secret/cheack`): confirms the Secret and caches the per-governorate
  per-kilo price list in `delivery_syria_governorate_rates` (keyed by the courier's `code`, so a re-sync
  updates in place). This is what "the integration connects" means to the admin.
- **Dispatch** (`POST /api/parcel/create-with-security`, from the order page): builds the parcel from
  truthful order data + the saved defaults, sends the order id as the unique `invoice_no`, and on success
  writes the courier `parcel_id`/`tracking_number` and the `wallet_status` — reusing the order's existing
  `delivery_type='third_party_delivery'` / `delivery_service_name='Delivery Syria'` /
  `third_party_delivery_tracking_id` fields. Idempotent: an order already shipped is refused locally.
  `wallet_status` is read explicitly — `pending_payment` is surfaced, never mistaken for a paid shipment.
- **List** (`GET /api/parcel/list-with-security`): read parcels by paid/unpaid visibility.
- `DeliverySyriaRateCalculator` gives a weight × base-cost estimate for the admin at dispatch; the
  courier returns the authoritative `calculated_delivery_charge`.

**Inbound — the courier calling the store** (`POST /api/delivery-syria/orders/update-status`):
authenticated by the `deliverysyria_auth` middleware (`X-Platform: deliverysyria` + a Bearer webhook
token, constant-time compared, fail-closed — the payment-IPN pattern, not Passport). It records the
courier's status in the parcel ledger and the order's status history, and reflects it into
`orders.order_status` **only** for the side-effect-free intermediate states.

## The status boundary (the load-bearing safety decision)

The platform's `order_status` is financially loaded: a transition to `delivered` matures vendor
earnings/commission, marks payment paid and pays the deliveryman; `canceled`/`returned`/`failed`
restock inventory. That orchestration lives in the order controllers, not in a `save()`. So the webhook
draws a hard line, encoded in `DeliverySyriaStatus::isFinancialTransition()`:

| Courier code | Platform `order_status` | Webhook behaviour |
|---|---|---|
| 0 Pending, 4 Confirmed, 5 Picked Up, 6 Shipped, 3 In Transit | pending / confirmed / processing / out_for_delivery | **recorded + reflected** into order_status (no side effects), via a query-builder update that fires no model events |
| 1 Delivered, 2 Canceled | delivered / canceled | **recorded only** — order_status is left untouched |

An already-terminal order (delivered/canceled/returned/failed) is never reflected onto, so a webhook can
never resurrect a closed order or move marketplace money. Finalizing an order stays with the platform's
own flow, by design — an external caller must not be able to mature earnings or restock stock.

## Non-breaking by contract

- **Additive schema only:** two new tables (`delivery_syria_governorate_rates`,
  `delivery_syria_parcels`), both guarded `up()` with a working `down()`. No existing table or column
  changed. The order's own delivery columns are reused, not extended.
- **New routes only:** one API route and three admin routes, all new. No existing route, controller or
  API contract changed — the Flutter/mobile API surface is untouched.
- **Off by default:** every path checks the enabled flag and the relevant token first; nothing runs, and
  the order page shows nothing new, until an admin enables it. Every outbound method fails soft (no
  throw, secret stripped from errors) so the courier being unreachable never breaks an admin action.

## The honest scope line

This ships the **connect → sync → dispatch → receive-status** loop, admin-configurable and fully tested.
It deliberately does **not** wire a live weight×governorate charge into the storefront/app checkout: the
catalog has no product `weight` column and the address forms never capture a governorate, so such an
overlay would be dormant (the same reason the existing per-kg shipping-zone overlay is documented as
dormant). The dispatch weight is entered by the admin, and the courier computes the binding charge. A
catalog-wide weight/governorate capture, and a customer-facing checkout overlay, are the named next
steps — not claimed here. Terminal-status auto-finalization (moving an order to delivered/paid from the
webhook) is intentionally out of scope: it is a financial-policy decision, not a wiring one.

## Security

- Both tokens are encrypted at rest (`Crypt`), never rendered to the browser, never written to the repo,
  and stripped from any surfaced error. The courier doc's example Secret is **not** present anywhere in
  the codebase.
- The webhook verifies platform + Bearer with `hash_equals`, fails closed, and does nothing when the
  integration is disabled or unconfigured.

## Verification

- **31 feature tests** across 5 files: the config service (encryption at rest, blank-keeps-secret,
  readiness gates), the client + calculator (Http-faked verify/sync upsert, parcel auth headers +
  wallet_status, disabled-makes-no-call, duplicate-invoice surfaced, estimate), the status map (the
  financial boundary pinned), the webhook (auth 401 matrix; intermediate reflected; delivered/canceled
  recorded-only; terminal not resurrected; 404; match by id/tracking/invoice), and dispatch (persist +
  reuse of order fields, idempotency, pending_payment recorded, disabled-blocks). **Full suite: 694
  passed, 1 skipped — no regressions** (was 663).
- **Runtime verified** against live MariaDB through the real objects: the two migrations apply; the
  Secret is stored encrypted in `business_settings` and decrypts back; the webhook middleware 401s a bad
  token and 200s a good one; a real order moved to `out_for_delivery` on status 6 with a parcel row and a
  history row written; status 1 (Delivered) did **not** move the order to delivered; an unknown order is
  404. The seeded rows were removed afterward.

## Admin setup steps

1. Open **3rd Party → Other Configuration → Delivery Syria**.
2. Paste the **Secret** from the Delivery Syria account, and the **webhook token** you will give the
   courier for inbound updates. Fill the pickup/hub defaults. Enable and save.
3. Press **Verify & Sync** — this confirms the Secret and pulls the governorate price list.
4. Give the courier the shown **inbound webhook URL** and header (`X-Platform: deliverysyria`) plus the
   webhook token.
5. On an order, use **Dispatch via Delivery Syria** (destination governorate + total weight) to create a
   parcel; the tracking id and wallet status are saved on the order.
