# Parity — brands_compliance

[← back to the matrix](../SELLER_WEB_APP_PARITY.md) · 46 capabilities

**17** BOTH · **19** WEB MISSING · **8** APP MISSING · **1** APP ADAPTATION · **1** DEVICE SPECIFIC

## Structural facts the implementer must know

```
SHAPE OF THE DOMAIN. Three separate features live here, with very different parity:
(1) BRAND CLAIMS — mobile-only. The seller API is complete (7 endpoints, routes/rest_api/v3/seller.php:464-482) and the admin review side exists (app/Http/Controllers/Admin/Marketplace/BrandRegistryController.php: index/document/approve/reject/revoke/updateEnforcement, routes/admin/routes.php:625-626), but there is NO seller web surface at all. Verified by: grep 'BrandClaim|BrandRegistryService' over app/Http/Controllers + resources/views returns only the API controller, RestAPI ProductController and the admin controller; routes/vendor/routes.php contains no brand-claims group; resources/views/vendor-views/marketplace/ contains only payouts, seller-center, seller-scorecard, seller-verification, staff. Every brand-claims row above is therefore WEB MISSING — this is the bulk of the implementation work.
(2) SELLER VERIFICATION (KYC) — near parity, with a small gap each way. Web is ahead on expiry-on-submit, viewing the stored file, and PDF upload; the app is ahead on the outstanding-documents view and camera capture.
(3) VAT REPORT — near parity. Web is ahead on search, Excel export, pagination and the order link; the app is ahead only on a one-tap filter reset. TIN (tax registration) lives in shop settings and is at parity.

ROUTES THE WEB IMPLEMENTATION SHOULD REUSE. The Seller Center navigation registry already reserves the destinations: app/Services/SellerCenter/Navigation.php:161-163 declares 'brands' (seller.brands.index), 'brands.authorization' (badge brands_expiring) and 'brands.protection'. Those routes do not exist in routes/seller/routes.php, so Navigation::for drops them silently (Navigation.php:221-224) and app/Services/SellerCenter/IssueAction.php:64-69 resolves open_brand_claims to a null href. Building the page under those exact route names makes the nav entries, the badge and the Control Tower action button light up with no other change. Badge counts are already computed: app/Services/SellerCenter/Counts.php:56 (brands_expiring, from BRAND_COMPLIANCE insights) and :58,193-202 (brands_pending, claims in submitted/under_review).

BACKEND CAPABILITY NEITHER CLIENT EXPOSES (kept out of the rows above because neither side has it, but the web build should include it):
- Claim-level expiry: POST .../brand-claims accepts expires_at (SellerBrandClaimController.php:87) and BrandClaim::entitles() enforces it (app/Models/BrandClaim.php:79-86), but the Flutter sheet never sends it (brand_claims_screen.dart:298-304 calls saveClaim without expiresAt, though brand_claim_controller.dart:90 supports it).
- Document-level `reference` and `expires_at`: accepted at SellerBrandClaimController.php:128-129 and returned at :289-290; the app sends neither (brand_claim_repository.dart:31-35) and never displays reference.
- Fourth document type 'other': BrandClaimDocument::TYPES includes it (app/Models/BrandClaimDocument.php:107,113) but the app offers only trademark_certificate / authorization_letter / invoice (brand_claims_screen.dart:280-283).
- Re-reading an uploaded evidence file: GET .../brand-claims/{id}/documents/{documentId} exists (routes/rest_api/v3/seller.php:471, SellerBrandClaimController.php:195-211) and has no client caller in either codebase — the web page must offer it, mirroring the KYC 'view_file' link.
- Claiming a brand the shop does not yet list under: exposure is derived from the seller's own catalogue (BrandRegistryService.php:116-142), so a brand with zero listings never appears and cannot be claimed from the app. POST .../brand-claims accepts any brand_id, and GET /api/v3/seller/brands (routes/rest_api/v3/seller.php:136-142) can back a brand picker. Worth adding on web.

ENFORCEMENT ASYMMETRY (real bug, not just a parity gap). The brand-claim gate runs only on the v3 seller product save (app/Http/Controllers/RestAPI/v3/seller/ProductController.php:2221-2237). The vendor web product form has no equivalent, so with brand_claim_enforcement on, the same product is refused in the app and accepted through /vendor. The fix belongs in the shared product save path, not in the new brand page.

PERMISSION DIVERGENCE. Brand claims: read needs products.view|products.manage, writes need products.manage (routes/rest_api/v3/seller.php:468,474) — mirror this on the web route. Verification: the API restricts the whole group to seller_owner (routes/rest_api/v3/seller.php:427) while the web route sits under the plain ['seller','seller_staff_access'] group (routes/vendor/routes.php:84,400-406) with no seller_can/owner check, so any staff member with panel access can read and submit the owner's identity documents on web but not in the app.

DATA-MODEL INCONSISTENCY. Counts::brandClaims (app/Services/SellerCenter/Counts.php:199-202) counts status 'more_information_required', which is not one of BrandClaim's statuses (app/Models/BrandClaim.php:18-24) — that branch can never match.

CLIENT-SIDE BUSINESS STATE: none found. grep for SharedPreferences across lib/features/brand_claims, lib/features/vat_management and lib/features/seller_center returns nothing; all brand, KYC and VAT state is read from and written to the server on every action (BrandClaimController reloads exposure AND claims after every mutation, brand_claim_controller.dart:152-157). The only local state is the picked-but-not-yet-uploaded file held on SellerCenterController (_pickedDocument, seller_center_controller.dart:32-33), deliberately cleared when the sheet reopens (submit_document_sheet_widget.dart:28-35), and the transient VAT date filter (vat_controller.dart:19-33). Nothing to flag.

MOBILE-ONLY DELIVERY MECHANICS (do not port literally): the app's VAT CSV goes through the OS share sheet and a temp file (lib/helper/report_export_helper.dart:42-81) — the web equivalent is the existing server-side export; and the TIN certificate download writes to /storage/emulated/0/Download with a storage permission request (shop_controller.dart:606-630).

MODULE GATING: the web VAT report is an addon surface — the sidebar entry only renders when the TaxModule addon is published (resources/views/layouts/vendor/partials/v2/_side-bar.blade.php:22-24,343-359, routes registered via Modules/TaxModule/routes/vendor.php:16-22 and Modules/TaxModule/Addon/vendor_tax_report_routes.php). The app's VAT screen calls Modules/TaxModule/routes/api/v3/api.php:20 with no addon check, so with the addon off the app hits a 404 while the web simply hides the menu.

OUT OF SCOPE BUT ADJACENT: the web also has an Auction VAT report (resources/views/layouts/vendor/partials/v2/_side-bar.blade.php:368-370, vendor.auction.reports.vat-report) with no app counterpart — that belongs to the auction domain, not this one. Product-level tax assignment (GET /api/v1/vat-tax/get-taxVat-list, lib/features/addProduct/controllers/add_product_tax_controller.dart:22) belongs to the product/catalog domain.
```

## BOTH (17)

**See my overall KYC / account-verification status**  
`API: seller_owner. Web: none beyond the seller/staff panel guard`  
- App — Yes — status pill on VerificationScreen (lib/features/seller_center/screens/verification_screen.dart:67-74)
- Web — Yes — status alert on the seller-verification page (resources/views/vendor-views/marketplace/seller-verification.blade.php:12-21)
- Server — GET /api/v3/seller/seller-center/verification (overall_status) and vendor.business-settings.seller-verification.index; both call SellerVerificationService::overallStatus
- Evidence — flutter /home/user/sillercenter-syria-cosmatics/lib/features/seller_center/screens/verification_screen.dart:67-74 + domain/models/verification_models.dart:19; web /home/user/Pharmacy/resources/views/vendor-views/marketplace/seller-verification.blade.php:12-21 + app/Http/Controllers/Vendor/Marketplace/SellerVerificationController.php:26-36; backend app/Http/Controllers/RestAPI/v3/seller/SellerVerificationController.php:37-47 + app/Services/Marketplace/SellerVerificationService.php:93-98

**Be warned that verification must be completed before payouts/withdrawals are possible**  
`API: seller_owner. Web: seller panel guard`  
- App — Yes — kyc_required_for_payout_note (verification_screen.dart:75-80)
- Web — Yes — 'verification_is_required_before_you_can_withdraw' (seller-verification.blade.php:18-20)
- Server — kyc_required_for_payout on both index endpoints; SellerVerificationService::isKycRequiredForPayout()
- Evidence — flutter verification_screen.dart:75-80 + verification_models.dart:20; web seller-verification.blade.php:18-20 + app/Http/Controllers/Vendor/Marketplace/SellerVerificationController.php:34

**See which document types this marketplace requires**  
`API: seller_owner. Web: seller panel guard`  
- App — Yes — required set drives the submit-sheet dropdown (submit_document_sheet_widget.dart:58-69)
- Web — Yes — datalist + 'required: …' hint (seller-verification.blade.php:34-41)
- Server — required_documents from SellerVerificationService::requiredDocumentTypes() (kyc_required_documents setting)
- Evidence — flutter lib/features/seller_center/widgets/submit_document_sheet_widget.dart:58-69,106-117; web resources/views/vendor-views/marketplace/seller-verification.blade.php:34-41; backend app/Services/Marketplace/SellerVerificationService.php:52-67

**See every KYC document I have submitted with its individual review status**  
`API: seller_owner. Web: seller panel guard`  
- App — Yes — document cards with status pills (verification_screen.dart:115-147)
- Web — Yes — documents table with status badge (seller-verification.blade.php:62-99)
- Server — documents[] from SellerVerificationService::documentsFor()
- Evidence — flutter verification_screen.dart:115-147; web seller-verification.blade.php:62-99; backend app/Services/Marketplace/SellerVerificationService.php:70-78

**Read the rejection reason on a refused KYC document so it can be resubmitted correctly**  
`API: seller_owner. Web: seller panel guard`  
- App — Yes — error-toned block, rejected only (verification_screen.dart:165-178)
- Web — Yes — red note under the badge (seller-verification.blade.php:89-91)
- Server — rejection_reason on the document payload / model
- Evidence — flutter verification_screen.dart:165-178 + verification_models.dart:43,63; web seller-verification.blade.php:89-91

**See a submitted KYC document's expiry date**  
`API: seller_owner. Web: seller panel guard`  
- App — Yes — shown for any document that has one (verification_screen.dart:156-161)
- Web — Partial — shown only when the document is approved (seller-verification.blade.php:92-94)
- Server — expires_at on the document payload
- Evidence — flutter verification_screen.dart:156-161; web resources/views/vendor-views/marketplace/seller-verification.blade.php:92-94 (guarded by `$doc->status === 'approved'`)

**Submit a KYC document (type + optional document number + file) for review**  
`API: seller_owner. Web: seller panel guard`  
- App — Yes — SubmitDocumentSheetWidget (submit_document_sheet_widget.dart:106-191)
- Web — Yes — submit form (seller-verification.blade.php:28-56)
- Server — POST /api/v3/seller/seller-center/verification/submit and POST vendor.business-settings.seller-verification.store; both call SellerVerificationService::submit + storeDocumentFile (private disk)
- Evidence — flutter submit_document_sheet_widget.dart:106-191 + lib/features/seller_center/controllers/seller_center_controller.dart:85-111 + domain/repositories/seller_center_repository.dart:43-72; web seller-verification.blade.php:28-56 + app/Http/Controllers/Vendor/Marketplace/SellerVerificationController.php:52-79; backend routes/rest_api/v3/seller.php:429 + app/Http/Controllers/RestAPI/v3/seller/SellerVerificationController.php:60-88

**See VAT report headline totals: total orders, total order amount, total VAT collected**  
`API: finance.view. Web: seller guard only`  
- App — Yes — two stat cards plus the VAT total (vat_report_screen.dart:142-193)
- Web — Yes — three summary cards (Modules/TaxModule vendor-tax-report blade:44-111)
- Server — GET /api/v3/seller/get-vat-tax-report-list → VendorTaxReportController::vendorWiseTaxes; web vendor.report.get-vat-report → TaxReportController::vendorTaxReportList
- Evidence — flutter /home/user/sillercenter-syria-cosmatics/lib/features/vat_management/screens/vat_report_screen.dart:142-193 + controllers/vat_controller.dart:40-55; web /home/user/Pharmacy/Modules/TaxModule/resources/views/6valley/vendor/vendor-tax-report.blade.php:44-111 + Modules/TaxModule/app/Http/Controllers/Vendor/Reports/TaxReportController.php:86-113; backend Modules/TaxModule/routes/api/v3/api.php:20 + Modules/TaxModule/app/Http/Controllers/Api/v3/VendorTaxReportController.php:102-150

**See VAT broken down by tax type and tax name, with the rate and the amount collected for each**  
`API: finance.view. Web: seller guard only`  
- App — Yes — horizontal TaxInfoCard list (vat_report_screen.dart:198-210, widgets/tax_info_card.dart:27-62)
- Web — Yes — type-wise list inside the VAT card (vendor-tax-report.blade.php:88-106)
- Server — type_wise_taxes_list on both the API and the web controller
- Evidence — flutter lib/features/vat_management/screens/vat_report_screen.dart:198-210 + widgets/tax_info_card.dart:27-62; web Modules/TaxModule/resources/views/6valley/vendor/vendor-tax-report.blade.php:88-106 + Modules/TaxModule/app/Http/Controllers/Vendor/Reports/TaxReportController.php:72-83; backend Modules/TaxModule/app/Http/Controllers/Api/v3/VendorTaxReportController.php:85-100

**Browse the taxed order transactions behind the VAT totals (order id, date, amount, VAT, tax model)**  
`API: finance.view. Web: seller guard only`  
- App — Yes — OrderListCardWidget list (vat_report_screen.dart:236-258, widgets/order_list_card_widget.dart:25-71)
- Web — Yes — 'All VAT' table (vendor-tax-report.blade.php:166-257)
- Server — order_transactions on both endpoints
- Evidence — flutter lib/features/vat_management/screens/vat_report_screen.dart:236-258 + widgets/order_list_card_widget.dart:25-71; web Modules/TaxModule/resources/views/6valley/vendor/vendor-tax-report.blade.php:166-257

**Open a per-order VAT breakdown showing every tax group, each tax line and the order total**  
`API: finance.view. Web: seller guard only`  
- App — Yes — TaxDetailBottomSheetWidget (order_list_card_widget.dart:75-105 → tax_detail_bottom_sheet_widget.dart:52-171)
- Web — Yes — per-row offcanvas detail panel (vendor-tax-report.blade.php:233-239 trigger, :269-343 panel)
- Server — vat_amount_formats.all_vat_groups (API) / orderTaxes grouped in the blade
- Evidence — flutter lib/features/vat_management/widgets/order_list_card_widget.dart:75-105 + widgets/tax_detail_bottom_sheet_widget.dart:52-171; web Modules/TaxModule/resources/views/6valley/vendor/vendor-tax-report.blade.php:233-239,269-343; backend Modules/TaxModule/app/Http/Controllers/Api/v3/VendorTaxReportController.php:106-126

**Filter the VAT report to a custom date range**  
`API: finance.view. Web: seller guard only`  
- App — Yes — filter sheet with start/end pickers and validation (vat_filter_bottomsheet.dart:98-152)
- Web — Yes — date-range picker + Filter button (vendor-tax-report.blade.php:13-40)
- Server — start_date/end_date on the API; `dates` (m/d/Y - m/d/Y) on the web route
- Evidence — flutter lib/features/vat_management/widgets/vat_filter_bottomsheet.dart:98-152 + controllers/vat_controller.dart:58-84 + domain/repositories/vat_repository.dart:12-18; web Modules/TaxModule/resources/views/6valley/vendor/vendor-tax-report.blade.php:13-40 + Modules/TaxModule/app/Http/Controllers/Vendor/Reports/TaxReportController.php:39-49; backend Modules/TaxModule/app/Http/Controllers/Api/v3/VendorTaxReportController.php:43-53

**Export the VAT report as CSV**  
`API: finance.view. Web: seller guard only`  
- App — Yes — export FAB builds a CSV and opens the share sheet (vat_report_screen.dart:49-90)
- Web — Yes — CSV item in the Export dropdown, generated server-side (vendor-tax-report.blade.php:156-160)
- Server — Web: vendor.report.get-vat-report-export?export_type=csv → TaxReportController::vendorTaxExport. App: none — the CSV is built on the device from the rows already loaded
- Evidence — flutter lib/features/vat_management/screens/vat_report_screen.dart:49-90 + lib/helper/report_export_helper.dart:42-81; web Modules/TaxModule/resources/views/6valley/vendor/vendor-tax-report.blade.php:156-160 + Modules/TaxModule/app/Http/Controllers/Vendor/Reports/TaxReportController.php:116-167 + Modules/TaxModule/routes/vendor.php:20

**Record the shop's taxpayer identification number (TIN)**  
`shop_settings.manage (API side); web: seller panel guard`  
- App — Yes — TIN field on Shop → Other setup (lib/features/shop/screens/other_setup_screen.dart:203-213)
- Web — Yes — tax_identification_number input (resources/views/vendor-views/shop/other-setup.blade.php:115-116)
- Server — App: POST /api/v3/seller/shop-update (multipart, _method=put). Web: vendor.shop.update-other-settings → ShopController::updateOtherSettings → VendorService::getUpdateBusinessTIN
- Evidence — flutter /home/user/sillercenter-syria-cosmatics/lib/features/shop/screens/other_setup_screen.dart:192-213 + domain/repositories/shop_repository.dart:64-67 + lib/utill/app_constants.dart:19; web /home/user/Pharmacy/resources/views/vendor-views/shop/other-setup.blade.php:115-116 + app/Http/Controllers/Vendor/ShopController.php:153,173-176 + app/Http/Requests/Vendor/VendorOtherSetupRequest.php:30-33

**Record the TIN expiry date**  
`shop_settings.manage (API side); web: seller panel guard`  
- App — Yes — date picker (other_setup_screen.dart:223-249)
- Web — Yes — tin_expire_date input (other-setup.blade.php:125-126)
- Server — tin_expire_date on shop-update / update-other-settings; web validates after_or_equal:today when it changes
- Evidence — flutter lib/features/shop/screens/other_setup_screen.dart:223-249 + controllers/shop_controller.dart:520 (pickTinExpireDate); web resources/views/vendor-views/shop/other-setup.blade.php:125-126 + app/Http/Requests/Vendor/VendorOtherSetupRequest.php:35-40

**Upload / replace the TIN certificate file**  
`shop_settings.manage (API side); web: seller panel guard`  
- App — Yes — dotted-border picker with size validation and a remove control (other_setup_screen.dart:253-300, shop_controller.dart:555-577)
- Web — Yes — tin_certificate file input (other-setup.blade.php:189)
- Server — tin_certificate multipart part on both; web validates mimes:pdf,doc,docx,jpg max 5 MB
- Evidence — flutter lib/features/shop/screens/other_setup_screen.dart:253-300 + controllers/shop_controller.dart:555-577 + domain/repositories/shop_repository.dart:56-61; web resources/views/vendor-views/shop/other-setup.blade.php:189 + app/Http/Requests/Vendor/VendorOtherSetupRequest.php:32

**Preview or download the TIN certificate already on file**  
`shop_settings.manage (API side); web: seller panel guard`  
- App — Yes — eye (preview) and download icons on the stored certificate (other_setup_screen.dart:293-325)
- Web — Yes — stored certificate rendered/linked on the setup page (other-setup.blade.php:138-219)
- Server — public shop document path (storage/app/public/shop/documents/…); app downloads it via ShopRepository::downloadTinCertificate
- Evidence — flutter lib/features/shop/screens/other_setup_screen.dart:293-325 + controllers/shop_controller.dart:606-630 (previewDownload) + domain/repositories/shop_repository.dart:197; web resources/views/vendor-views/shop/other-setup.blade.php:138-143,206-219

## WEB MISSING (19)

**See every brand my shop lists under, with the number of listings carrying each brand**  
`products.view or products.manage` · wave 6  
- App — Yes — BrandClaimsScreen exposure list (lib/features/brand_claims/screens/brand_claims_screen.dart:60-116)
- Web — No — no vendor route, controller or blade exists
- Server — GET /api/v3/seller/seller-center/brand-claims/exposure → SellerBrandClaimController::exposure; data from BrandRegistryService::brandExposure()
- Evidence — flutter /home/user/sillercenter-syria-cosmatics/lib/features/brand_claims/screens/brand_claims_screen.dart:60-116 + controllers/brand_claim_controller.dart:55-69 + domain/repositories/brand_claim_repository.dart:13; backend /home/user/Pharmacy/routes/rest_api/v3/seller.php:469 + app/Http/Controllers/RestAPI/v3/seller/SellerBrandClaimController.php:45-51 + app/Services/Marketplace/BrandRegistryService.php:116-142; web NOT FOUND — grep 'BrandClaim|BrandRegistryService' over app/Http/Controllers + resources/views hits only the API controller and Admin/Marketplace/BrandRegistryController.php; routes/vendor/routes.php has no brand-claims group (only 'load-more-brands' at :182); resources/views/vendor-views/marketplace/ holds only payouts, seller-center, seller-scorecard, seller-verification, staff

**Know whether the marketplace is currently ENFORCING brand claims (refusing listings) or only reporting mismatches**  
`products.view or products.manage` · wave 6  
- App — Yes — banner switches between brand_enforcement_is_on/off (brand_claims_screen.dart:74-82)
- Web — No — the seller side has no surface for the enforcement flag; only admin can see/toggle it
- Server — `enforcing` field on GET .../brand-claims/exposure; BrandRegistryService::isEnforcing() reads business_settings 'brand_claim_enforcement'
- Evidence — flutter brand_claims_screen.dart:74-82 + brand_claim_controller.dart:60 (_enforcing = data['enforcing']); backend app/Services/Marketplace/BrandRegistryService.php:39,78-87 + SellerBrandClaimController.php:48; web: admin-only toggle at app/Http/Controllers/Admin/Marketplace/BrandRegistryController.php:175 (updateEnforcement) — no vendor-facing equivalent anywhere in routes/vendor/routes.php

**See, per brand, whether my listings are allowed, blocked, or held by another seller**  
`products.view or products.manage` · wave 6  
- App — Yes — colour-coded row + one of three messages (brand_claims_screen.dart:105-128)
- Web — No
- Server — `may_list` per row on GET .../brand-claims/exposure (BrandRegistryService::mayList)
- Evidence — flutter brand_claims_screen.dart:95-128 + domain/models/brand_claim_models.dart:25 (needsAction => !mayList); backend app/Services/Marketplace/BrandRegistryService.php:56-75,140; web NOT FOUND (no vendor brand route/blade — see row 1)

**Brands that need action are surfaced first instead of being buried in an alphabetical list**  
`products.view or products.manage` · wave 6  
- App — Yes — brandsNeedingAction prepended (brand_claims_screen.dart:60-63)
- Web — No
- Server — none (client-side ordering over the exposure payload)
- Evidence — flutter brand_claims_screen.dart:60-63 + brand_claim_controller.dart:35-36; web NOT FOUND (no vendor brand screen at all)

**See my own claim on each brand: its status and the claimed relationship type**  
`products.view or products.manage` · wave 6  
- App — Yes — 'your_claim: <status> · <type>' line (brand_claims_screen.dart:130-137)
- Web — No
- Server — GET /api/v3/seller/seller-center/brand-claims → SellerBrandClaimController::index (present(): status, claim_type, is_editable, is_pending, entitles)
- Evidence — flutter brand_claims_screen.dart:130-137 + brand_claim_controller.dart:71-83 + models/brand_claim_models.dart:37-88; backend routes/rest_api/v3/seller.php:470 + SellerBrandClaimController.php:61-68,269-294; web NOT FOUND

**Read the marketplace reviewer's note on a claim (why it was rejected / what more is needed)**  
`products.view or products.manage` · wave 6  
- App — Yes — review_note rendered under the claim line (brand_claims_screen.dart:138-141)
- Web — No
- Server — `review_note` in the claim payload (SellerBrandClaimController::present)
- Evidence — flutter brand_claims_screen.dart:138-141 + models/brand_claim_models.dart:51,80; backend app/Http/Controllers/RestAPI/v3/seller/SellerBrandClaimController.php:283; web NOT FOUND

**See how many evidence documents are attached to a claim**  
`products.view or products.manage` · wave 6  
- App — Yes — '<n> documents_attached' (brand_claims_screen.dart:142-150)
- Web — No
- Server — `documents[]` in the claim payload
- Evidence — flutter brand_claims_screen.dart:142-150; backend SellerBrandClaimController.php:285-292; web NOT FOUND

**Start a brand claim, choosing the relationship: owner / authorized reseller / distributor**  
`products.manage` · wave 6  
- App — Yes — claim sheet dropdown (brand_claims_screen.dart:218-234), saved via saveClaim
- Web — No
- Server — POST /api/v3/seller/seller-center/brand-claims → SellerBrandClaimController::store (claim_type in owner|authorized_reseller|distributor)
- Evidence — flutter brand_claims_screen.dart:184-234,293-309 + brand_claim_controller.dart:85-116 + repositories/brand_claim_repository.dart:19-26; backend routes/rest_api/v3/seller.php:475 + SellerBrandClaimController.php:81-110 + app/Models/BrandClaim.php:32-36 + app/Services/Marketplace/BrandRegistryService.php:152-190; web NOT FOUND

**Explain the brand relationship in a free-text statement attached to the claim**  
`products.manage` · wave 6  
- App — Yes — 3-line 'explain_the_relationship' field (brand_claims_screen.dart:236-245)
- Web — No
- Server — `statement` (nullable, max 2000) on POST .../brand-claims
- Evidence — flutter brand_claims_screen.dart:236-245 + brand_claim_controller.dart:97; backend SellerBrandClaimController.php:86; web NOT FOUND

**Edit a claim that is still editable (draft or rejected) and re-save it**  
`products.manage` · wave 6  
- App — Yes — button text flips to 'edit_claim' when claim.isEditable (brand_claims_screen.dart:155-163)
- Web — No
- Server — POST .../brand-claims re-drafts the existing row; BrandRegistryService::draft refuses to rewrite a claim already with the marketplace
- Evidence — flutter brand_claims_screen.dart:155-163 + models/brand_claim_models.dart:44,77; backend app/Services/Marketplace/BrandRegistryService.php:152-166 + app/Models/BrandClaim.php:27,68-71; web NOT FOUND

**Attach a typed evidence document to a claim (trademark certificate, authorization letter, invoice) — PDF/JPG/PNG**  
`products.manage` · wave 6  
- App — Yes — three add buttons + file picker upload (brand_claims_screen.dart:279-289,319-344)
- Web — No
- Server — POST /api/v3/seller/seller-center/brand-claims/{id}/documents → SellerBrandClaimController::attach (mimes pdf,jpg,jpeg,png, max 10 MB, private disk)
- Evidence — flutter brand_claims_screen.dart:279-289,319-344 + brand_claim_controller.dart:118-136 + repositories/brand_claim_repository.dart:29-40; backend routes/rest_api/v3/seller.php:476 + SellerBrandClaimController.php:123-158 + app/Models/BrandClaimDocument.php:104-114 + app/Services/Marketplace/BrandRegistryService.php:44,240-243; web NOT FOUND

**Remove an attached evidence document from a claim**  
`products.manage` · wave 6  
- App — Yes — per-document close icon (brand_claims_screen.dart:260-277)
- Web — No
- Server — DELETE /api/v3/seller/seller-center/brand-claims/{id}/documents/{documentId} → SellerBrandClaimController::detach
- Evidence — flutter brand_claims_screen.dart:260-277 + brand_claim_controller.dart:138-139 + repositories/brand_claim_repository.dart:43-51; backend routes/rest_api/v3/seller.php:477 + SellerBrandClaimController.php:167-183; web NOT FOUND

**Hand a completed claim to the marketplace for human review**  
`products.manage` · wave 6  
- App — Yes — 'send_for_review' button (brand_claims_screen.dart:164-171)
- Web — No
- Server — POST /api/v3/seller/seller-center/brand-claims/{id}/submit → SellerBrandClaimController::submit
- Evidence — flutter brand_claims_screen.dart:164-171 + brand_claim_controller.dart:141 + repositories/brand_claim_repository.dart:54; backend routes/rest_api/v3/seller.php:478 + SellerBrandClaimController.php:222-240 + app/Services/Marketplace/BrandRegistryService.php:193-215; web NOT FOUND

**Withdraw a claim that is still pending review, so it can be edited again**  
`products.manage` · wave 6  
- App — Yes — 'take_it_back' button shown while claim.isPending (brand_claims_screen.dart:172-178)
- Web — No
- Server — POST /api/v3/seller/seller-center/brand-claims/{id}/withdraw → SellerBrandClaimController::withdraw
- Evidence — flutter brand_claims_screen.dart:172-178 + brand_claim_controller.dart:143 + repositories/brand_claim_repository.dart:57; backend routes/rest_api/v3/seller.php:479 + SellerBrandClaimController.php:249-267 + app/Services/Marketplace/BrandRegistryService.php:223-230; web NOT FOUND

**Be told up front that a claim needs at least one document before it can be sent (rather than being refused at submit)**  
`products.manage` · wave 6  
- App — Yes — hint text plus the submit button only rendering when documents exist (brand_claims_screen.dart:247-258,164-171)
- Web — No
- Server — BrandRegistryService::submit returns 'brand_claim_needs_evidence' when the claim has no documents
- Evidence — flutter brand_claims_screen.dart:247-258 (a_claim_needs_at_least_one_document) and :164 (guard `claim.documents.isNotEmpty`); backend app/Services/Marketplace/BrandRegistryService.php:198-201; web NOT FOUND

**Be blocked (with a clear reason) from saving a product under a brand the shop is not entitled to, while enforcement is on**  
`products.manage` · wave 6  
- App — Yes — the seller API refuses the save and surfaces the brand_id error
- Web — No — the vendor web product form never runs the brand-claim guard, so the same product saves through /vendor while it is refused through the app
- Server — ProductController::brandClaimGuard on the v3 seller product create/update only
- Evidence — backend app/Http/Controllers/RestAPI/v3/seller/ProductController.php:2221-2237 (brandClaimGuard → BrandRegistryService::mayList); web: grep 'BrandRegistry|mayList' over app/Http/Controllers/Vendor/Product/ returns nothing — the only other consumer is app/Services/SellerIntelligence/Producers/BrandComplianceProducer.php:34

**Act on a 'listings under a brand you are not entitled to' issue from the issue feed (tap through to the claim screen)**  
`orders.view (control tower) / products.view (catalog section)` · wave 6  
- App — Partial — the insight is listed in Action Center / Control Tower, but InsightActionHandler has no case for open_brand_claims, so the card is a dead end; the seller must reach Brands from the More menu
- Web — Partial — the /seller Control Tower renders the card but IssueAction resolves open_brand_claims to seller.brands.index, which does not exist, so href is null and no button renders; the classic /vendor panel has no issue feed at all
- Server — BrandComplianceProducer emits BRAND_COMPLIANCE insights with actionKey open_brand_claims; surfaced by GET /api/v3/seller/seller-center/control-tower and by the web ControlTowerService
- Evidence — backend app/Services/SellerIntelligence/Producers/BrandComplianceProducer.php:56-84 + app/Services/SellerIntelligence/ControlTowerService.php:62-68; web app/Services/SellerCenter/IssueAction.php:64-69 → app/Services/SellerCenter/Shell.php:76-79 returns null because routes/seller/routes.php declares no brands route, and resources/views/seller-views/control-tower.blade.php:96-101 only renders the button when href is set; flutter lib/features/action_center/widgets/insight_action_handler.dart:31-43 (only open_order and open_product handled)

**See exactly which required documents are still outstanding (required minus already-approved)**  
`API: seller_owner. Web: seller panel guard` · wave 6  
- App — Yes — chips of outstanding types only (verification_screen.dart:83-106, computed in the model)
- Web — No — the page lists the full required set, never the remaining subset
- Server — none — derived client-side from required_documents + documents[]
- Evidence — flutter verification_screen.dart:83-106 + domain/models/verification_models.dart:32-35 (outstandingDocuments); web seller-verification.blade.php:39-41 prints implode(', ', $requiredDocuments) unconditionally — no filtering against approved documents anywhere in app/Http/Controllers/Vendor/Marketplace/SellerVerificationController.php:26-36

**Clear the date filter in one action and return to the default range**  
`API: finance.view. Web: seller guard only` · wave 6  
- App — Yes — Reset control appears once a date is picked (vat_filter_bottomsheet.dart:75-89)
- Web — No — there is no reset control; the seller has to manually blank the date input and re-submit
- Server — both default to the last 7 days when no dates are supplied
- Evidence — flutter lib/features/vat_management/widgets/vat_filter_bottomsheet.dart:75-89 + controllers/vat_controller.dart:87-95 (resetReviewData); web Modules/TaxModule/resources/views/6valley/vendor/vendor-tax-report.blade.php:13-40 has only the input and a Filter submit, and Modules/TaxModule/app/Traits/AdminTaxReportManagement.php:49-51 supplies the 7-day default

## APP MISSING (8)

**Set an expiry date when submitting a KYC document**  
`API: seller_owner. Web: seller panel guard`  
- App — No — the repository and controller accept expiresAt but the submit sheet has no date control and never passes one
- Web — Yes — date input on the submit form (seller-verification.blade.php:47-50)
- Server — expires_at accepted by both submit endpoints (nullable|date)
- Evidence — flutter lib/features/seller_center/widgets/submit_document_sheet_widget.dart:183-186 calls submitVerificationDocument with only documentType and documentNumber, while controllers/seller_center_controller.dart:85-89 and domain/repositories/seller_center_repository.dart:50-53 support expiresAt; web resources/views/vendor-views/marketplace/seller-verification.blade.php:47-50; backend app/Http/Controllers/RestAPI/v3/seller/SellerVerificationController.php:65

**Open / download a KYC document I previously submitted**  
`API: seller_owner. Web: seller panel guard`  
- App — No — the model carries has_file but nothing renders a link and no repository method calls the document route
- Web — Yes — 'view_file' link per document (seller-verification.blade.php:79-84)
- Server — GET /api/v3/seller/seller-center/verification/document/{id} and vendor.business-settings.seller-verification.document — both stream from the private disk scoped by seller_id
- Evidence — flutter lib/features/seller_center/domain/models/verification_models.dart:45,65 (hasFile parsed, never used in verification_screen.dart:134-181) and no method for it in domain/repositories/seller_center_repository.dart; web resources/views/vendor-views/marketplace/seller-verification.blade.php:79-84 + app/Http/Controllers/Vendor/Marketplace/SellerVerificationController.php:42-50; backend routes/rest_api/v3/seller.php:430 + app/Http/Controllers/RestAPI/v3/seller/SellerVerificationController.php:104-113

**Attach a PDF (e.g. a scanned business licence) as the KYC document file**  
`API: seller_owner. Web: seller panel guard`  
- App — No — the sheet uses ImagePicker, which only yields images; a PDF cannot be selected
- Web — Yes — file input accepting .pdf,.jpg,.jpeg,.png (seller-verification.blade.php:51-54)
- Server — Both submit endpoints accept mimes:pdf,jpg,jpeg,png (max 5 MB)
- Evidence — flutter lib/features/seller_center/widgets/submit_document_sheet_widget.dart:44-52 (ImagePicker().pickImage) vs lib/features/brand_claims/screens/brand_claims_screen.dart:325-328 which does use FilePicker with pdf allowed; web seller-verification.blade.php:51-54; backend app/Http/Controllers/RestAPI/v3/seller/SellerVerificationController.php:66 + app/Services/Marketplace/SellerVerificationService.php:32

**Search VAT rows by order id or tax name**  
`API: finance.view. Web: seller guard only`  
- App — No — no search field anywhere in the VAT screens and the repository never sends `search`
- Web — Yes — search box on the VAT list (vendor-tax-report.blade.php:123-135)
- Server — `search` handled by both the web controller and the API controller
- Evidence — web Modules/TaxModule/resources/views/6valley/vendor/vendor-tax-report.blade.php:123-135 + Modules/TaxModule/app/Http/Controllers/Vendor/Reports/TaxReportController.php:59-65; backend Modules/TaxModule/app/Http/Controllers/Api/v3/VendorTaxReportController.php:74-78 accepts `search`; flutter lib/features/vat_management/domain/repositories/vat_repository.dart:12-18 builds the URL with limit/offset/start_date/end_date only

**Export the VAT report as Excel (.xlsx)**  
`Web: seller guard only`  
- App — No — only CSV-over-share is implemented
- Web — Yes — Excel item in the Export dropdown (vendor-tax-report.blade.php:150-155)
- Server — vendor.report.get-vat-report-export?export_type=excel → VendorTaxExport; no API equivalent
- Evidence — web Modules/TaxModule/resources/views/6valley/vendor/vendor-tax-report.blade.php:150-155 + Modules/TaxModule/app/Http/Controllers/Vendor/Reports/TaxReportController.php:162-166; flutter lib/features/vat_management/screens/vat_report_screen.dart:84-88 offers only export_csv

**Page through the whole VAT transaction history rather than the first page only**  
`API: finance.view. Web: seller guard only`  
- App — Broken — the paginator calls getVatReportList(offset) but the controller passes literal limit 10 / offset 1 to the service, so page 2 re-fetches page 1 and replaces the list; the CSV export therefore also only ever contains those 10 rows
- Web — Yes — LengthAwarePaginator, 10 per page, with links (vendor-tax-report.blade.php:259-263)
- Server — API accepts limit/offset and returns total_size/limit/offset
- Evidence — flutter lib/features/vat_management/controllers/vat_controller.dart:46 — `vatServiceInterface.getVatReport(10, 1, startDate, endDate)` ignores the `offset` argument declared at :40, and :48 assigns (never appends) _vatReportModel; called from screens/vat_report_screen.dart:241-247; web Modules/TaxModule/app/Http/Controllers/Vendor/Reports/TaxReportController.php:90-101 + resources/views/6valley/vendor/vendor-tax-report.blade.php:259-263; backend Modules/TaxModule/app/Http/Controllers/Api/v3/VendorTaxReportController.php:128-149

**Jump from a VAT row to the underlying order**  
`API: finance.view. Web: orders view via the order details route`  
- App — No — the row's arrow opens the tax detail sheet only; no navigation to order details
- Web — Yes — order id links to vendor.orders.details (vendor-tax-report.blade.php:183-188)
- Server — order_id present in both payloads
- Evidence — web Modules/TaxModule/resources/views/6valley/vendor/vendor-tax-report.blade.php:183-188; flutter lib/features/vat_management/widgets/order_list_card_widget.dart:75-105 (opens TaxDetailBottomSheetWidget) and widgets/tax_detail_bottom_sheet_widget.dart:52-55 renders the order id as plain text

**See an expense report alongside the VAT report**  
`Web: seller guard only`  
- App — No — ExpenseReportScreen is a placeholder ('------------Need Design----------') and its menu card is commented out, so it is unreachable dead code
- Web — Yes — vendor transaction expense list with PDF/Excel exports
- Server — vendor.transaction.expense-list → TransactionReportController::getExpenseTransactionList; no seller API equivalent
- Evidence — flutter lib/features/vat_management/screens/expense_report_screen.dart:15 and lib/features/vat_management/screens/vat_management_screen.dart:24-31 (the ManagementCardWidget for expense_report is commented out); web /home/user/Pharmacy/routes/vendor/routes.php:465-470 area — transaction group declares expense-list, pdf-order-wise-expense-transaction, expense-transaction-summary-pdf, expense-transaction-export-excel

## APP ADAPTATION (1)

**Refresh verification standing without leaving the screen**  
`API: seller_owner. Web: seller panel guard`  
- App — Yes — pull-to-refresh (verification_screen.dart:44-47)
- Web — Yes — page reload / redirect back after submit
- Server — GET verification index on both surfaces
- Evidence — flutter verification_screen.dart:44-47 (RefreshIndicator → getVerification); web app/Http/Controllers/Vendor/Marketplace/SellerVerificationController.php:78 (back() after store)

## DEVICE SPECIFIC (1)

**Photograph a KYC document with the device camera instead of picking a stored file**  
`API: seller_owner`  
- App — Yes — camera button on the submit sheet (submit_document_sheet_widget.dart:136-142)
- Web — No — a browser file input only; not required
- Server — same multipart submit endpoint
- Evidence — flutter lib/features/seller_center/widgets/submit_document_sheet_widget.dart:44-52,136-142 (ImageSource.camera); web resources/views/vendor-views/marketplace/seller-verification.blade.php:51-54 — file input, no capture path

