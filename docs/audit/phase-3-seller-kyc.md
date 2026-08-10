# Phase 3 — Seller KYC / onboarding verification (Stage A)

## Why this

Stage A asks for seller onboarding and KYC. The platform verifies a seller with a single coarse
account `status` (approved / pending / denied) — one boolean decision, with no record of *what* was
checked. There is nowhere to hold an identity document, a business licence or a tax certificate, no
per-document review state, no reviewer, no expiry, and no way to make "verified" a precondition for
anything. This adds that missing structure and then wires it to the one place it must bite:
withdrawing money.

## What the platform had

Measured. `sellers` carries name, phone, bank fields, a commission percentage and `status`. The only
verification-shaped tables in the schema are OTP/phone (`phone_or_email_verifications`) and the
withdraw-method rows — nothing about identity or business documents. The payout flow built in
Stage B (`PayoutService::requestPayout`) gated withdrawals on the ledger balance alone.

## What shipped

`seller_verification_documents` — one row per submitted document, each with its own lifecycle
(pending → approved / rejected, and an approved document can carry an expiry). The seller's overall
KYC standing is **derived** from these rows against a configurable required set; the account
`status` column is never touched, so this adds a dimension rather than migrating the existing one.

`SellerVerificationService` — the single resolver, built around two rules:

1. **Derived, not stored.** `overallStatus()` computes verified / pending / rejected / unverified /
   not_required from the documents. A required type is satisfied by any approved, unexpired document
   of that type; an approved-but-expired licence stops counting.
2. **Off by default.** `isPayoutEligible()` is always true until an admin turns on
   `require_kyc_for_payout`. Until then the withdrawal path is byte-for-byte the behaviour it has
   today — which is what keeps this backward compatible with every current install and the Flutter
   apps.

An **admin review screen** at `admin/marketplace/seller-verification`: the document queue with
approve / reject (reason required), the derived per-seller standing, and the two settings that arm
the gate (the payout requirement and the required-document list). A **seller screen** at
`vendor/business-settings/seller-verification`: submit a document (pdf/jpg/png via the platform's
hardened file uploader, the extension mapped onto a server-side whitelist), and see each document's
state and any rejection reason. Every approve / reject / submit writes an audit-log line.

## Connected, not a disconnected module

The proof is the payout gate. `PayoutService::requestPayout` now asks
`SellerVerificationService::isPayoutEligible()` before it reserves anything — a guard added inside
the existing method, no function added or removed. Runtime-verified end to end: with the gate armed
and a seller holding only an approved identity (business licence still outstanding), a withdrawal
request comes back `kyc_verification_required`; approve the licence and the same request passes the
KYC gate and falls through to the normal balance check. With the gate off — the default — an
unverified seller withdraws exactly as before.

Only real sellers are subject to the gate; admin / in-house withdrawals (`seller_is = admin`) are
not KYC-verified entities and pass straight through.

## Backward compatibility & data safety

Additive migration only (new table, guarded `up()`, working `down()`); no original migration touched,
nothing dropped or renamed. The two settings default to off / the standard document set, so an
install that configures nothing behaves as it does today. The uploader reuses the platform's
`ImageManager::file_upload`, and the seller-supplied extension is never trusted — it is mapped onto a
`{pdf,jpg,jpeg,png}` whitelist before storage.

## Verification

- **17 feature tests** (`SellerVerificationTest`): the required-set resolution (default, configured,
  empty-means-none), the derived status transitions, expiry no longer satisfying a requirement, a
  newer approval superseding an older rejection, and the gate — off by default, blocking the
  unverified only once armed, allowing the verified. Full suite **527 passed, 1 skipped**.
- **Runtime verified** against live MariaDB through the real HTTP stack: the admin page renders,
  arming the gate persisted both settings, and approving a document set it approved with
  `reviewed_by` = the admin and the chosen expiry. The seller page renders for a real authenticated
  seller with the full submission form (debugbar: 0 exceptions). The payout wiring was exercised
  through the real `PayoutService` (blocked-then-passed). All seeded rows, the armed settings, and
  the temporary admin/seller passwords were then reverted, leaving the database clean.

## What this is not

It is not seller permissions/teams and not the performance scorecard — other Stage A items. It is the
onboarding/KYC spine: structured documents, a derived standing, and one real consequence
(payout eligibility) wired through.
