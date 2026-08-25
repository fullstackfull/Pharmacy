# Parity — finance

[← back to the matrix](../SELLER_WEB_APP_PARITY.md) · 42 capabilities

**20** BOTH · **14** WEB MISSING · **6** APP MISSING · **1** DEVICE SPECIFIC · **1** BACKEND MISSING

## Structural facts the implementer must know

```
STRUCTURAL FACTS THE IMPLEMENTER MUST KNOW

1. There are TWO parallel money systems in this codebase and they are not the same thing.
   - LEGACY WALLET/WITHDRAW: seller_wallets.total_earning / pending_withdraw / withdrawn + withdraw_requests. Surfaced by Flutter's wallet + transaction features and by the web Withdraw page (resources/views/vendor-views/withdraw/*, routes/vendor/routes.php:377-384 and the dashboard withdraw-request actions at :109-111). API: /api/v3/seller/transactions, balance-withdraw, balance-withdraw-update, close-withdraw-request.
   - LEDGER: vendor_ledger_entries + VendorLedger/PayoutService, surfaced by Flutter's payouts + statement features and by the web payouts page (routes/vendor/routes.php:390-396). API: /api/v3/seller/seller-center/payouts and /seller-center/statement.
   Both exist side by side on purpose (see the comment at routes/vendor/routes.php:387-389). Any web work must not merge them: the withdraw page's "Current Balance" is total_earning, the payouts page's "withdrawable" is the ledger ceiling, and they are different numbers.

2. The biggest web gap by far is the whole Flutter "seller-center finance" tier. Four app screens/tabs have zero web counterpart, and every one of them is already served by a stable v3 endpoint, so implementation is view + controller only, no new server work:
   - Statement (GET/EXPORT /api/v3/seller/seller-center/statement) — line-by-line ledger, entry-type chips, date range, range totals, bucket totals, CSV export, drill to order.
   - Reconciliation (GET .../finance/reconciliation).
   - Fee simulator (GET .../finance/fee-simulator).
   - Price change history (GET .../finance/price-changes).
   - Price floor policy read + save (GET/PUT .../finance/pricing-policy).
   Verified absent on the web: grepping -i for 'statement', 'reconcil', 'fee.simulator', 'pricing.policy', 'price.changes' across app/Http/Controllers/Vendor, resources/views/vendor-views and routes/vendor returns no seller-finance matches (the only 'statement' hits are delivery-man wallet blades and transaction PDF blades).

3. The price floor is the one genuine SETTING/TOGGLE in this domain, and it is correctly server-stored (seller_pricing_policies via PricingPolicyService), not client-side. It is already ENFORCED on web-originated writes — app/Services/Marketplace/Bulk/BulkPriceOperation.php:83 and app/Services/SellerAutomation/Actions/SetDiscountAction.php:90 both call PricingPolicyService::check — but a web seller has no way to see or change the policy that is refusing their prices. That asymmetry (enforced on web, configurable only on mobile) is the single highest-priority WEB MISSING item.

4. NO business state is stored client-side in this domain. The only SharedPreferences read across wallet/transaction/statement/bank_info/finance_control/seller_center is the auth token (lib/features/bank_info/domain/repositories/bank_info_repository.dart:35). BankInfoController._showWarning (lib/features/bank_info/controllers/bank_info_controller.dart:18-20,51-56) is in-memory-only dismissal of a hint banner and resets on every screen entry — a device preference at worst, not business state. TransactionController's month/year lists (lib/features/transaction/domain/repositories/transaction_repository.dart:25-74) are hardcoded client-side arrays, but they are dead code: transaction_screen.dart calls initMonthTypeList/initYearList at :31-32 and never renders them; the live filter is the server-side from/to range. Do not port those hardcoded 2020-2032 year lists to the web.

5. Permission model — the routes draw a deliberate line the web panel does not enforce as finely. Reading the books is seller_can:finance.view; moving money is seller_can:payouts.request (routes/rest_api/v3/seller.php:110-124, :620-627); changing where the money goes (payment information writes, bank details) is seller_owner (:93, :397-402). Price history and the price floor sit under products.view/products.manage, not finance (:495-505), because the floor governs the catalogue. Vendor web routes carry no equivalent per-capability middleware, so a web implementation of the missing screens should apply the same gating.

6. Two smaller web gaps that are cheap and worth folding into the payouts page while it is open: the 'paid' and 'total balance' buckets are already in the array passed to the view (app/Http/Controllers/Vendor/Marketplace/PayoutController.php:41) but are not rendered, and payout_eligible (KYC) is never fetched at all, so a KYC-blocked web seller gets an enabled form and a post-submit toast instead of an up-front reason the app shows.

7. App-side gaps worth queueing: the withdraw detail sheet has its payout-method-fields block commented out (lib/features/transaction/widgets/transaction_details_widget.dart:106-144) even though withdrawal_method_fields is in the payload; the payout request sheet never sends 'method' though the repository accepts it; payout history and the statement have no paging in the app despite limit/offset support; and the two web transaction reports (order commission breakdown, expense) have no mobile counterpart and no v3 endpoint — those two are the only rows here that would need new server work.
```

## BOTH (20)

**See the wallet headline balance (withdrawable / total earning) with a Withdraw call-to-action**  
`seller_can:finance.view`  
- App — Yes — lib/features/wallet/screens/wallet_screen.dart (WalletScreen) via WithdrawBalanceWidget
- Web — Yes — Withdraw page 'Current_Balance' card + Withdraw button, and the dashboard 'withdrawable_balance' card
- Server — GET /api/v3/seller/seller-info (wallet block) for app; server-side $vendorWallet->total_earning for web
- Evidence — flutter: lib/features/wallet/widgets/withdraw_balance_widget.dart:66-101 (balance_withdraw label, totalEarning, withdraw button); lib/features/wallet/screens/wallet_screen.dart:98 | web: resources/views/vendor-views/withdraw/index.blade.php:36-66 (Current_Balance + Withdraw offcanvas trigger); resources/views/vendor-views/partials/_dashboard-wallet-status.blade.php:6-16

**See the wallet breakdown: withdrawn, pending withdraw, commission given, delivery charge earned, collected cash, total collected tax**  
`seller_can:finance.view`  
- App — Yes — six WalletCardWidget tiles on the wallet screen
- Web — Yes — six dashboard wallet cards; the Withdraw page shows 3 of them (current/requested/withdrawn)
- Server — GET /api/v3/seller/seller-info for app; Vendor DashboardController dashboard data for web
- Evidence — flutter: lib/features/wallet/screens/wallet_screen.dart:108-154 (withdrawn, pending_withdrawn, commission_given, delivery_charge_earned, collected_cash, total_collected_tax) | web: resources/views/vendor-views/partials/_dashboard-wallet-status.blade.php:20-105; resources/views/vendor-views/withdraw/index.blade.php:66-92

**Earnings vs admin-commission chart filtered by this year / this month / this week**  
`seller_can:finance.view`  
- App — Yes — ChartWidget on the wallet screen driven by SellerAnalyticsController
- Web — Yes — dashboard 'earning_statistics' apex chart with yearEarn/MonthEarn/WeekEarn radio filter
- Server — GET /api/v3/seller/get-earning-statitics?type= (app) ; GET vendor/dashboard/earning-statistics (web)
- Evidence — flutter: lib/features/wallet/screens/wallet_screen.dart:37-41,81-83,166; lib/features/home/widgets/chart_widget.dart:31-34; lib/features/home/domain/repositories/seller_analytics_repository.dart:14 | web: resources/views/vendor-views/dashboard/partials/earning-statistics.blade.php:12-33; app/Http/Controllers/Vendor/DashboardController.php:186-202; routes/vendor/routes.php:107

**List the admin-defined withdrawal methods available to the shop**  
`seller_can:finance.view`  
- App — Yes — WalletController.getWithdrawMethods populates the 'Others' group of the withdraw dropdown
- Web — Yes — $withdrawalMethods 'Others' optgroup in the withdraw request form
- Server — GET /api/v3/seller/withdraw-method-list (SellerController::withdraw_method_list)
- Evidence — flutter: lib/features/wallet/controllers/wallet_controller.dart:74-95; lib/features/wallet/domain/repositories/wallet_repository.dart:14-21; lib/utill/app_constants.dart:113 | web: app/Http/Controllers/Vendor/WithdrawController.php:91; resources/views/vendor-views/withdraw/_withdraw-request-form.blade.php:39-51 | backend: routes/rest_api/v3/seller.php:116; app/Http/Controllers/RestAPI/v3/seller/SellerController.php:393-397

**List the seller's own saved payout methods ('My Methods') inside the withdraw form, with the default pre-selected**  
`seller_can:finance.view`  
- App — Yes — WalletController.getPaymentInfoList + setDefaultPaymentMethod feed the grouped dropdown
- Web — Yes — 'My Methods' optgroup with is_default preselected and its stored field values rendered read-only
- Server — GET /api/v3/seller/payment-information/list (app); VendorWithdrawMethodInfoRepository (web)
- Evidence — flutter: lib/features/wallet/controllers/wallet_controller.dart:196-237; lib/features/wallet/domain/repositories/wallet_repository.dart:93-101; lib/common/basewidgets/custom_edit_dialog_widget.dart:86-125,206-215 | web: app/Http/Controllers/Vendor/WithdrawController.php:71-97; resources/views/vendor-views/withdraw/_withdraw-request-form.blade.php:30-38,69-99

**Submit a legacy withdraw request (amount + the selected method's dynamic fields)**  
`seller_can:payouts.request`  
- App — Yes — CustomEditDialogWidget bottom sheet -> WalletController.updateBalance
- Web — Yes — withdraw offcanvas form posting to vendor.dashboard.withdraw-request
- Server — POST /api/v3/seller/balance-withdraw (SellerController::withdraw_request) ; POST vendor/dashboard/withdraw-request (DashboardController::getWithdrawRequest)
- Evidence — flutter: lib/common/basewidgets/custom_edit_dialog_widget.dart:289-350,398-414,422-478; lib/features/wallet/controllers/wallet_controller.dart:111-153; lib/features/wallet/domain/repositories/wallet_repository.dart:24-48 | web: resources/views/vendor-views/withdraw/index.blade.php:181-196; resources/views/vendor-views/withdraw/_withdraw-request-form.blade.php:100-108; app/Http/Controllers/Vendor/DashboardController.php:209-246 | backend: routes/rest_api/v3/seller.php:121; app/Http/Controllers/RestAPI/v3/seller/SellerController.php:399-446

**Edit a still-pending withdraw request (change amount and/or method details)**  
`seller_can:payouts.request`  
- App — Yes — 'edit_info_bt' in the transaction detail sheet reopens CustomEditDialogWidget with existingTransaction -> updateWithdrawRequest
- Web — Yes — 'Edit_Info' button on the request preview opens the edit modal posting to withdraw-request-update
- Server — POST /api/v3/seller/balance-withdraw-update (SellerController::withdraw_request_update) ; POST vendor/dashboard/withdraw-request-update
- Evidence — flutter: lib/features/transaction/widgets/transaction_details_widget.dart:180-203; lib/features/wallet/controllers/wallet_controller.dart:155-192; lib/features/wallet/domain/repositories/wallet_repository.dart:51-71 | web: resources/views/vendor-views/withdraw/_withdraw-request-preview.blade.php:94-98; resources/views/vendor-views/withdraw/_withdraw-request-edit.blade.php:13-30; app/Http/Controllers/Vendor/DashboardController.php:253-288 | backend: routes/rest_api/v3/seller.php:122

**Cancel / delete a pending withdraw request and get the amount returned to the balance**  
`seller_can:payouts.request`  
- App — Yes — 'cancel' on the transaction card and 'delete_request' in the detail sheet, both via WalletController.closeWithdrawRequest
- Web — Yes — trash action in the request table and Delete button in the preview offcanvas
- Server — DELETE /api/v3/seller/close-withdraw-request (SellerController::close_withdraw_request) ; GET vendor/business-settings/withdraw/close/{id} (WithdrawController::closeWithdrawRequest)
- Evidence — flutter: lib/features/transaction/widgets/transaction_widget.dart:120-139,161-184; lib/features/transaction/widgets/transaction_details_widget.dart:156-176; lib/features/wallet/domain/repositories/wallet_repository.dart:74-91 | web: resources/views/vendor-views/withdraw/_table.blade.php:43-56; resources/views/vendor-views/withdraw/_withdraw-request-preview.blade.php:82-92,112-118; app/Http/Controllers/Vendor/WithdrawController.php:175-201 | backend: routes/rest_api/v3/seller.php:123

**Browse withdraw request history filtered by status (all / pending / approved / denied)**  
`seller_can:finance.view`  
- App — Yes — TransactionScreen status chips mapped to all|pending|approve|deny
- Web — Yes — inline page-menu status tabs on the Withdraw page (plus an ajax getListByStatus endpoint)
- Server — GET /api/v3/seller/transactions?status= (SellerController::transaction) ; GET/POST vendor/business-settings/withdraw/index
- Evidence — flutter: lib/features/transaction/screens/transaction_screen.dart:131-141,159-197; lib/features/transaction/controllers/transaction_controller.dart:40-44,101-108; lib/features/transaction/domain/repositories/transaction_repository.dart:15-22 | web: resources/views/vendor-views/withdraw/index.blade.php:98-145; app/Http/Controllers/Vendor/WithdrawController.php:52-69,153-168 | backend: routes/rest_api/v3/seller.php:115; app/Http/Controllers/RestAPI/v3/seller/SellerController.php:512-535

**Export the withdraw request history to a file the seller can hand to an accountant**  
`seller_can:finance.view`  
- App — Yes — CSV built in-app and handed to the OS share sheet (ReportExportHelper.shareCsv)
- Web — Yes — Excel export of the withdraw request list (VendorWithdrawRequest export)
- Server — None for the app (client-side CSV from the already-fetched list); GET vendor/business-settings/withdraw/export for web
- Evidence — flutter: lib/features/transaction/screens/transaction_screen.dart:51-73,122-128; lib/helper/report_export_helper.dart:40-60 | web: resources/views/vendor-views/withdraw/index.blade.php:51-55; app/Http/Controllers/Vendor/WithdrawController.php:203-231; routes/vendor/routes.php:382

**Read the admin's approve / deny note on a decided withdraw request**  
`seller_can:finance.view`  
- App — Yes — _noteWidget renders approve_note / denied_note
- Web — Yes — Approve_Note / Denied_Note blocks in the preview, and a Transaction_Note column in the table
- Server — transaction_note on withdraw_requests, returned by GET /api/v3/seller/transactions
- Evidence — flutter: lib/features/transaction/widgets/transaction_details_widget.dart:146-149,221-249 | web: resources/views/vendor-views/withdraw/_withdraw-request-preview.blade.php:63-77; resources/views/vendor-views/withdraw/_table.blade.php:8,25-27

**See the ledger payout buckets — available, pending (in return window), reserved and the withdrawable ceiling**  
`seller_can:finance.view`  
- App — Yes — PayoutsScreen balance card
- Web — Yes — four k-stat tiles on the payouts page
- Server — GET /api/v3/seller/seller-center/payouts (SellerPayoutController::index) ; GET vendor/business-settings/payouts (Vendor\Marketplace\PayoutController::index)
- Evidence — flutter: lib/features/seller_center/screens/payouts_screen.dart:69-77,120-147; lib/features/seller_center/domain/models/payout_models.dart:32-56 | web: resources/views/vendor-views/marketplace/payouts.blade.php:22-28; app/Http/Controllers/Vendor/Marketplace/PayoutController.php:40-49 | backend: routes/rest_api/v3/seller.php:620-625; app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:43-70

**Request a ledger payout against the withdrawable balance (amount validated against the ceiling)**  
`seller_can:payouts.request`  
- App — Yes — RequestPayoutSheetWidget with a Max fill and a local ceiling check before posting
- Web — Yes — payouts page form with max attribute, disabled when withdrawable <= 0
- Server — POST /api/v3/seller/seller-center/payouts (SellerPayoutController::store) ; POST vendor/business-settings/payouts (PayoutController::store)
- Evidence — flutter: lib/features/seller_center/widgets/request_payout_sheet_widget.dart:76-92,123-153; lib/features/seller_center/domain/repositories/seller_center_repository.dart:89-100 | web: resources/views/vendor-views/marketplace/payouts.blade.php:41-72; app/Http/Controllers/Vendor/Marketplace/PayoutController.php:52-87 | backend: routes/rest_api/v3/seller.php:623; app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:83-119

**Choose the payout currency on a multi-currency store**  
`seller_can:payouts.request`  
- App — Yes — currency dropdown shown only when payout_currencies is non-empty
- Web — Yes — 'pay_in_currency' select shown only when more than one currency exists
- Server — payout_currency on POST .../seller-center/payouts; currency list from the same multi_currency check on both sides
- Evidence — flutter: lib/features/seller_center/widgets/request_payout_sheet_widget.dart:94-120; lib/features/seller_center/domain/models/payout_models.dart:46-48 | web: resources/views/vendor-views/marketplace/payouts.blade.php:40,56-65; app/Http/Controllers/Vendor/Marketplace/PayoutController.php:33-38 | backend: app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:53-57,88

**Cancel an open payout request and release the reservation**  
`seller_can:payouts.request`  
- App — Yes — cancel_request action with a confirmation dialog on open requests
- Web — Yes — Cancel button per open request row
- Server — POST /api/v3/seller/seller-center/payouts/{id}/cancel ; POST vendor/business-settings/payouts/{id}/cancel
- Evidence — flutter: lib/features/seller_center/screens/payouts_screen.dart:193-216; lib/features/seller_center/domain/repositories/seller_center_repository.dart:124-131; lib/features/seller_center/domain/models/payout_models.dart:91 | web: resources/views/vendor-views/marketplace/payouts.blade.php:106-117; app/Http/Controllers/Vendor/Marketplace/PayoutController.php:90-105 | backend: routes/rest_api/v3/seller.php:624; app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:132-143

**Be told payouts are paused because bank details changed recently (cooling period)**  
`seller_can:finance.view`  
- App — Yes — 'bank_details_recently_changed' notice and the request button disabled
- Web — Yes — alert banner and the whole form disabled
- Server — in_cooling_period from PayoutService::isInCoolingPeriod, returned by the API and passed into the web view
- Evidence — flutter: lib/features/seller_center/screens/payouts_screen.dart:54-56,87-93 | web: resources/views/vendor-views/marketplace/payouts.blade.php:30-35,47,51,59,68; app/Http/Controllers/Vendor/Marketplace/PayoutController.php:43 | backend: app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:63

**View the shop's bank details (holder name, bank, branch, account number)**  
`seller_owner (app write path) / vendor session (web)`  
- App — Yes — BankInfoScreen with a BankInfoWidget card
- Web — Yes — the profile page's bank block feeding the edit view
- Server — GET /api/v3/seller/seller-info (app) ; VendorRepository in Vendor\ProfileController (web)
- Evidence — flutter: lib/features/bank_info/screens/bank_info_screen.dart:22-27,111; lib/features/bank_info/controllers/bank_info_controller.dart:28-36; lib/features/bank_info/domain/repositories/bank_info_repository.dart:57-64 | web: resources/views/vendor-views/profile/bank-info-update-view.blade.php:20-49; app/Http/Controllers/Vendor/ProfileController.php:102-111; routes/vendor/routes.php:326

**Edit the shop's bank details, which arms the payout cooling window on a real change**  
`seller_owner`  
- App — Yes — BankEditingScreen with per-field validation, posting a multipart seller-update
- Web — Yes — bank info update form; ProfileController::updateBankInfo records the change via PayoutService::recordBankChange
- Server — PUT /api/v3/seller/seller-update (SellerController::seller_info_update) ; POST vendor/profile/update-bank-info/{id}
- Evidence — flutter: lib/features/bank_info/screens/bank_editing_screen.dart:40-88,126-194; lib/features/bank_info/domain/repositories/bank_info_repository.dart:18-31; lib/utill/app_constants.dart:16 | web: resources/views/vendor-views/profile/bank-info-update-view.blade.php:20-56; app/Http/Controllers/Vendor/ProfileController.php:118-147; routes/vendor/routes.php:327 | backend: routes/rest_api/v3/seller.php:93

**Manage saved payout methods (payment information): list, add, edit, delete, mark one as default, enable/disable each one**  
`seller_can:finance.view (read) / seller_owner (write)`  
- App — Yes — PaymentInfoScreen list with paginator, add/edit screens, default and delete menu actions and an active/inactive switch
- Web — Yes — payment-information page with the same six actions
- Server — GET/POST /api/v3/seller/payment-information/{list,withdrawal-method-list,add,update,default,status,delete} ; the mirrored vendor.shop.payment-information.* routes
- Evidence — flutter: lib/features/shop/screens/payment_info_screen.dart:28-32,88-121,254-283,296-320; lib/features/shop/domain/repositories/shop_repository.dart:108,121-122,134,144,161,173; lib/utill/app_constants.dart:136-142 | web: resources/views/vendor-views/shop/payment-information.blade.php:39-166,207-288; routes/vendor/routes.php:343-353 | backend: routes/rest_api/v3/seller.php:390-405

**Mark the 'withdraw setup' step of the seller onboarding guide complete after a first withdraw request**  
`seller_can:shop_settings.manage (app)`  
- App — Yes — ShopController.updateTutorialFlow / updateSetupGuideApp fired from WalletController.updateBalance
- Web — Yes — updateSetupGuideCacheKey('withdraw_setup', 'vendor') on the withdraw page
- Server — POST /api/v3/seller/update-setup-guide-app (app) ; helper cache write on the web
- Evidence — flutter: lib/features/wallet/controllers/wallet_controller.dart:131-134 | web: app/Http/Controllers/Vendor/WithdrawController.php:99 | backend: routes/rest_api/v3/seller.php:98

## WEB MISSING (14)

**Filter withdraw request history by a custom from/to date range (and clear the filter)**  
`seller_can:finance.view` · wave 5  
- App — Yes — showDateRangePicker + TransactionController.applyDateRange, sending from/to to the transactions endpoint; a clear-filter icon resets it
- Web — No — the Withdraw page offers only status tabs and an amount search; no date inputs anywhere in vendor-views/withdraw or WithdrawController
- Server — GET /api/v3/seller/transactions?status=&from=&to= — already supports the range (whereBetween on created_at)
- Evidence — flutter: lib/features/transaction/screens/transaction_screen.dart:35-49,103-121; lib/features/transaction/controllers/transaction_controller.dart:93-99; lib/features/transaction/domain/repositories/transaction_repository.dart:17 | web: not found — searched resources/views/vendor-views/withdraw/*.blade.php (index.blade.php:98-160 has only status tabs + Search_By_Amount) and app/Http/Controllers/Vendor/WithdrawController.php:52-69 (filters accept only status/search) | backend: app/Http/Controllers/RestAPI/v3/seller/SellerController.php:526-529

**See the already-paid-out total and the total ledger balance alongside the other buckets**  
`seller_can:finance.view` · wave 5  
- App — Yes — 'already_paid_out' and 'total_balance' rows under the payout balance card
- Web — No — the payouts page renders only pending/available/reserved/withdrawable, although VendorLedger::balances() already returns paid and balance
- Server — GET /api/v3/seller/seller-center/payouts returns balances.paid and balances.balance; the same VendorLedger::balances() is already passed into the web view
- Evidence — flutter: lib/features/seller_center/screens/payouts_screen.dart:73,76; lib/features/seller_center/domain/models/payout_models.dart:41-42 | web: resources/views/vendor-views/marketplace/payouts.blade.php:23-28 (no paid/balance tile) with the data available at app/Http/Controllers/Vendor/Marketplace/PayoutController.php:41

**Be told a payout is blocked because KYC verification is not complete, before submitting**  
`seller_can:finance.view` · wave 5  
- App — Yes — payout_eligible drives a 'kyc_verification_required' notice and disables the request button
- Web — No — the payouts controller never reads SellerVerificationService, so the form stays enabled and the seller only learns of the refusal from a toast after posting
- Server — payout_eligible is returned by the API (SellerVerificationService::isPayoutEligible); the same service is enforced in PayoutService but not surfaced to the web view
- Evidence — flutter: lib/features/seller_center/screens/payouts_screen.dart:54-56,80-86; lib/features/seller_center/domain/models/payout_models.dart:45 | web: not found — app/Http/Controllers/Vendor/Marketplace/PayoutController.php:29-50 passes no eligibility flag and resources/views/vendor-views/marketplace/payouts.blade.php:30-35 shows only the cooling-period alert | backend: app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:64

**Read the account statement line by line, each line carrying the running balance the ledger recorded (balance_after), the entry type, the credit/debit and the payout/settlement reference**  
`seller_can:finance.view` · wave 5  
- App — Yes — StatementScreen entry list
- Web — No — no statement view, controller or route anywhere in the vendor panel
- Server — GET /api/v3/seller/seller-center/statement (SellerStatementController::index) — exists, mobile-only consumer
- Evidence — flutter: lib/features/statement/screens/statement_screen.dart:213-286; lib/features/statement/domain/models/statement_models.dart:7-65; lib/features/statement/domain/repositories/statement_repository.dart:13-23 | web: not found — grepped -i 'statement' across app/Http/Controllers/Vendor, resources/views/vendor-views and routes/vendor: only delivery-man wallet and transaction PDF views match, no seller ledger statement | backend: routes/rest_api/v3/seller.php:630-635; app/Http/Controllers/RestAPI/v3/seller/SellerStatementController.php:39-60

**See the whole-account statement buckets (pending, available, reserved, paid, balance, withdrawable) that deliberately do not follow the filter**  
`seller_can:finance.view` · wave 5  
- App — Yes — top of StatementScreen
- Web — No — the payouts page shows four of these six and only there; there is no statement page
- Server — summary.buckets + withdrawable from SellerLedgerStatementService::summary via GET .../statement
- Evidence — flutter: lib/features/statement/screens/statement_screen.dart:99-116; lib/features/statement/domain/models/statement_models.dart:72-119 | web: not found — nearest is resources/views/vendor-views/marketplace/payouts.blade.php:23-28 (pending/available/reserved/withdrawable only) | backend: app/Http/Controllers/RestAPI/v3/seller/SellerStatementController.php:57

**Filter the statement by entry type using the server-supplied type list**  
`seller_can:finance.view` · wave 5  
- App — Yes — choice chips built from statement.entry_types plus an 'all' chip
- Web — No
- Server — entry_type query param + entry_types list on GET .../statement
- Evidence — flutter: lib/features/statement/screens/statement_screen.dart:158-177; lib/features/statement/controllers/statement_controller.dart:42-60; lib/features/statement/domain/repositories/statement_repository.dart:40-45 | web: not found (no statement surface at all — see the statement row above) | backend: app/Http/Controllers/RestAPI/v3/seller/SellerStatementController.php:55,116

**Filter the statement by date range, with a clear action, and read the range totals (entries, credited, debited, net)**  
`seller_can:finance.view` · wave 5  
- App — Yes — date range picker + clear, and a range totals card under the filter
- Web — No
- Server — from/to query params (validated as plain calendar dates) and summary.range on GET .../statement
- Evidence — flutter: lib/features/statement/screens/statement_screen.dart:53-65,124-133,178-196; lib/features/statement/controllers/statement_controller.dart:61-67,141-147 | web: not found (no statement surface) | backend: app/Http/Controllers/RestAPI/v3/seller/SellerStatementController.php:112-130

**Download the statement as a CSV under the currently applied filters**  
`seller_can:finance.view` · wave 5  
- App — Yes — download icon in the app bar; the bytes are saved to Downloads/app documents and the path is reported
- Web — No
- Server — GET /api/v3/seller/seller-center/statement/export (SellerStatementController::export, capped at 5,000 rows)
- Evidence — flutter: lib/features/statement/screens/statement_screen.dart:40-51,74-82; lib/features/statement/controllers/statement_controller.dart:92-139; lib/features/statement/domain/repositories/statement_repository.dart:26-38 | web: not found — no statement export route in routes/vendor/routes.php (the only vendor finance export is business-settings/withdraw/export at line 382) | backend: routes/rest_api/v3/seller.php:632; app/Http/Controllers/RestAPI/v3/seller/SellerStatementController.php:74-110

**Open the order that produced a statement line (drill from a ledger entry back to its source order)**  
`seller_can:finance.view,orders.view` · wave 5  
- App — Yes — tapping an entry with an order_id pushes OrderDetailsScreen
- Web — No
- Server — order_id on each statement row from SellerLedgerStatementService::rows
- Evidence — flutter: lib/features/statement/screens/statement_screen.dart:220-228; lib/features/statement/domain/models/statement_models.dart:18,50 | web: not found (no statement surface) | backend: app/Http/Controllers/RestAPI/v3/seller/SellerStatementController.php:58

**Reconciliation: does what I sold add up to what I was paid — delivered lines vs recorded earnings vs credited ledger entries, with named gaps and openable samples**  
`seller_can:finance.view` · wave 5  
- App — Yes — 'does_it_add_up' tab of FinanceControlScreen
- Web — No — SellerReconciliationService has no web consumer
- Server — GET /api/v3/seller/seller-center/finance/reconciliation (SellerFinanceControlController::reconciliation)
- Evidence — flutter: lib/features/finance_control/screens/finance_control_screen.dart:73,210-334; lib/features/finance_control/controllers/finance_control_controller.dart:94-104; lib/features/finance_control/domain/repositories/finance_control_repository.dart:12-15 | web: not found — grepped 'SellerReconciliationService' across app/Http/Controllers: only RestAPI/v3/seller/SellerFinanceControlController.php matches; nothing in app/Http/Controllers/Vendor, resources/views/vendor-views or routes/vendor | backend: routes/rest_api/v3/seller.php:490-493; app/Http/Controllers/RestAPI/v3/seller/SellerFinanceControlController.php:50-59

**Fee simulator: what a sale at a considered price would cost — gross, discount, marketplace commission, seller receives, effective rate, applied rule and the named exclusions**  
`seller_can:finance.view` · wave 5  
- App — Yes — 'fee_simulator' tab with price / quantity / discount inputs and a Work-it-out action
- Web — No — FeeSimulatorService has no web consumer
- Server — GET /api/v3/seller/seller-center/finance/fee-simulator (SellerFinanceControlController::feeSimulator)
- Evidence — flutter: lib/features/finance_control/screens/finance_control_screen.dart:74,336-403,498-505; lib/features/finance_control/controllers/finance_control_controller.dart:125-142; lib/features/finance_control/domain/models/finance_control_models.dart:116-163 | web: not found — grepped 'FeeSimulatorService' across app/Http/Controllers: only the RestAPI v3 seller controller matches | backend: routes/rest_api/v3/seller.php:492; app/Http/Controllers/RestAPI/v3/seller/SellerFinanceControlController.php:74-100

**Price change history: every recorded move of a product's price/discount with previous → new, delta, source (own edit, panel, bulk job, rule, promotion), reason and actor**  
`seller_can:products.view,products.manage` · wave 5  
- App — Yes — 'price_history' tab, filterable by product via loadPriceChangesFor
- Web — No — no ProductPriceChange read surface in the vendor panel
- Server — GET /api/v3/seller/seller-center/finance/price-changes (SellerFinanceControlController::priceChanges)
- Evidence — flutter: lib/features/finance_control/screens/finance_control_screen.dart:75,405-466; lib/features/finance_control/controllers/finance_control_controller.dart:106-123; lib/features/finance_control/domain/models/finance_control_models.dart:166-203 | web: not found — grepped 'ProductPriceChange' across app/Http/Controllers/Vendor, resources/views/vendor-views and routes/vendor: no matches | backend: routes/rest_api/v3/seller.php:495-501; app/Http/Controllers/RestAPI/v3/seller/SellerFinanceControlController.php:113-152

**SETTING — read the shop's own price floor policy: minimum margin over cost %, absolute minimum price, whether it is enforced, whether it actually binds, and how much of the catalogue has a recorded cost**  
`seller_can:products.view,products.manage` · wave 5  
- App — Yes — 'price_floor' tab, with explicit warnings for 'on but empty' and 'margin covers nothing'
- Web — No — PricingPolicyService is enforced by BulkPriceOperation and SetDiscountAction but has no vendor-panel read view
- Server — GET /api/v3/seller/seller-center/finance/pricing-policy (SellerFinanceControlController::pricingPolicy)
- Evidence — flutter: lib/features/finance_control/screens/finance_control_screen.dart:76,100-192; lib/features/finance_control/controllers/finance_control_controller.dart:56-64; lib/features/finance_control/domain/models/finance_control_models.dart:224-268 | web: not found — grepped 'PricingPolicyService|pricing_policy|min_margin_percent' across app/, resources/views/ and routes/: matches only in app/Models/SellerPricingPolicy.php, app/Services/Marketplace/PricingPolicyService.php, app/Services/SellerAutomation/Actions/SetDiscountAction.php:90, app/Services/Marketplace/Bulk/BulkPriceOperation.php:83 and the RestAPI v3 controller | backend: routes/rest_api/v3/seller.php:499-503; app/Http/Controllers/RestAPI/v3/seller/SellerFinanceControlController.php:167-186

**TOGGLE — set the price floor: save min margin % / min price and switch enforcement on or off (the switch saves immediately and the policy is re-read from the server)**  
`seller_can:products.manage` · wave 5  
- App — Yes — SwitchListTile 'enforce_this_floor' plus a Save button, both calling savePricingPolicy
- Web — No — no route, controller action or form writes SellerPricingPolicy from the vendor panel
- Server — PUT /api/v3/seller/seller-center/finance/pricing-policy (SellerFinanceControlController::savePricingPolicy)
- Evidence — flutter: lib/features/finance_control/screens/finance_control_screen.dart:128-141,142-158,175-189; lib/features/finance_control/controllers/finance_control_controller.dart:70-92; lib/features/finance_control/domain/repositories/finance_control_repository.dart:51-60 | web: not found — routes/vendor/routes.php has no pricing-policy route (finance routes there are only withdraw at 377-384, payouts at 390-396 and transaction reports at 460-469) | backend: routes/rest_api/v3/seller.php:505; app/Http/Controllers/RestAPI/v3/seller/SellerFinanceControlController.php:199-213

## APP MISSING (6)

**Search withdraw requests by amount**  
`seller_can:finance.view`  
- App — No — a TextEditingController is declared but never wired to any field or query
- Web — Yes — GET search box 'Search_By_Amount' on the Withdraw page
- Server — WithdrawController::index searchValue (web only); the v3 transactions endpoint has no search parameter
- Evidence — flutter: lib/features/transaction/screens/transaction_screen.dart:78 (searchController declared, no other reference in the file); lib/features/transaction/domain/repositories/transaction_repository.dart:15-22 (only status/from/to) | web: resources/views/vendor-views/withdraw/index.blade.php:146-160; app/Http/Controllers/Vendor/WithdrawController.php:65,209

**Open a withdraw request and read the payout method details that were submitted with it**  
`seller_can:finance.view`  
- App — Partial — the detail sheet shows amount, request time, status and note only; the method/bank field block is commented out
- Web — Yes — the preview offcanvas renders every withdrawal_method_fields key/value
- Server — withdrawal_method_fields is returned by GET /api/v3/seller/transactions (stored as JSON on withdraw_requests)
- Evidence — flutter: lib/features/transaction/widgets/transaction_details_widget.dart:35-104 (amount/time/status), :106-144 (method_name / bank_name / holder_name / account_no block commented out) | web: resources/views/vendor-views/withdraw/_withdraw-request-preview.blade.php:47-61 | backend: app/Http/Controllers/RestAPI/v3/seller/SellerController.php:512-535

**Choose the payout method (bank transfer / manual) when requesting a payout**  
`seller_can:payouts.request`  
- App — No — the repository accepts a method argument but the request sheet never sets it, so the API defaults to bank_transfer
- Web — Yes — a method select in the payout form
- Server — POST .../seller-center/payouts accepts 'method' on both surfaces
- Evidence — flutter: lib/features/seller_center/widgets/request_payout_sheet_widget.dart:143-148 (only amount + payoutCurrency passed); lib/features/seller_center/domain/repositories/seller_center_repository.dart:89-100 (method supported but unused) | web: resources/views/vendor-views/marketplace/payouts.blade.php:49-55 | backend: app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:87,99

**Page through payout request history**  
`seller_can:finance.view`  
- App — No — PayoutsScreen loads offset 1 only (limit 10) and has no paginator, so older requests are unreachable
- Web — Yes — paginate(15) with links under the table
- Server — GET .../seller-center/payouts accepts limit and offset
- Evidence — flutter: lib/features/seller_center/screens/payouts_screen.dart:27-30,101-110 (no onPaginate); lib/features/seller_center/domain/repositories/seller_center_repository.dart:77-87 | web: app/Http/Controllers/Vendor/Marketplace/PayoutController.php:45-48; resources/views/vendor-views/marketplace/payouts.blade.php:127-129 | backend: app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:46-51

**Per-order transaction report: order amount, product/coupon/referral discounts, VAT, shipping, deliveryman incentive, admin vs vendor discount, admin commission and vendor net income, filtered by disburse/hold status, customer and date range, exportable to PDF and Excel**  
`vendor session (routes/vendor)`  
- App — No — the reports feature only covers order / product / stock reports; there is no transaction (commission) report
- Web — Yes — Transaction report order list with filters and PDF/Excel exports
- Server — GET vendor/transaction/order-list plus the pdf/excel routes (TransactionReportController); no v3 seller API equivalent
- Evidence — flutter: not found — lib/features/reports/domain/repositories/report_repository.dart:15,20,24 hits only orderReportUri, productReportUri and stockReportUri; no lib/features/* screen consumes a transaction report endpoint | web: resources/views/vendor-views/transaction/order-list.blade.php:15-78,158-196; app/Http/Controllers/Vendor/TransactionReportController.php:30-80; routes/vendor/routes.php:460-465

**Expense transaction report: free delivery and coupon-discount expense per order, with PDF and Excel export**  
`vendor session (routes/vendor)`  
- App — No
- Web — Yes — Transaction report expense list with date filters and exports
- Server — GET vendor/transaction/expense-list plus pdf/excel routes; no v3 seller API equivalent
- Evidence — flutter: not found — searched lib/features/reports, lib/features/transaction and lib/utill/app_constants.dart for an expense/commission report endpoint; none exists | web: resources/views/vendor-views/transaction/expense-list.blade.php:17-45,60-122; routes/vendor/routes.php:466-469

## DEVICE SPECIFIC (1)

**Hand a statement/transaction CSV to another app (share sheet) rather than saving it**  
`none`  
- App — Yes — transactions use SharePlus; the statement writes to Downloads and reports the path
- Web — n/a — the browser downloads the file directly
- Server — none (client-side CSV) for transactions; GET .../statement/export for the statement
- Evidence — flutter: lib/helper/report_export_helper.dart:40-60 (SharePlus.instance.share); lib/features/statement/controllers/statement_controller.dart:115-139 (writes to /storage/emulated/0/Download) | web: resources/views/vendor-views/withdraw/index.blade.php:51-55 is the ordinary browser download equivalent — only the delivery mechanism differs, the export capability itself is listed separately above

## BACKEND MISSING (1)

**Filter the statement by entry status (pending / available / paid …)**  
`seller_can:finance.view`  
- App — Partial — StatementController holds a status filter and passes it to the repository, but no widget ever sets it
- Web — No
- Server — status query param + statuses list on GET /api/v3/seller/seller-center/statement
- Evidence — flutter: lib/features/statement/controllers/statement_controller.dart:30-31,56-60,75 (status plumbed) with lib/features/statement/screens/statement_screen.dart:158-198 exposing only entry-type chips and dates — no status control; statement.statuses (lib/features/statement/domain/models/statement_models.dart:125) is parsed and never rendered | web: not found (no statement surface) | backend: app/Http/Controllers/RestAPI/v3/seller/SellerStatementController.php:56,117 — server support exists, so this row is a UI gap on both surfaces rather than a server gap

