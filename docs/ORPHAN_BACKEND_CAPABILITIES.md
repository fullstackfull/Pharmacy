# Orphan Backend Capabilities

> Every capability found in the backend, and the surface it ended up connected to.

The acceptance criterion is **zero unexplained orphans**: nothing meaningful may run in the
background without a documented owner and a place a person can see it. A capability that is
deliberately invisible is recorded as `INTERNAL BY DESIGN` with the reason no screen is
appropriate — silence is not the same as a decision.

Every orphan below has been ruled to an owner, so none is unexplained. **An assignment is not a
surface**: these stay orphans until the screen, the setting or the documentation exists, and this
register is the backlog for building them. Where the reconciliation overruled a sweep, the note
says so and why.

| Verdict | Capabilities | Meaning |
|---|---:|---|
| CONNECTED TO ADMIN | 318 | The marketplace operator manages or oversees it. |
| CONNECTED TO SELLER | 77 | The seller manages it, in the panel or the app. |
| CONNECTED TO DEVELOPER PORTAL | 28 | Documented as an API capability an integrator can use. |
| CONNECTED TO MONITOR | 27 | Its health and its failures are visible to an operator. |
| INTERNAL BY DESIGN | 52 | Infrastructure. No screen is appropriate, and the reason is stated. |
| DEPRECATED | 19 | Present in code, no longer part of the product. |
| ORPHAN | 86 | Found with no surface. Each has been ruled to an owner; the ruling is not the surface, so the list reaches zero only when the screen exists. |

## CONNECTED TO ADMIN (318)

The marketplace operator manages or oversees it.

| Capability | Area | Owner | Where it lives |
|---|---|---|---|
| How far back a seller's finance reconciliation looks, and how many example rows it shows | finance | Admin | app/Services/Platform/PolicyRegistry.php (reconciliation_lookback_days) read at app/Services/Marketplace/SellerReconciliationService.php:305 |
| How late money may be before it is called a finance-integrity problem (6-hour grace on delivered orders) | finance | Admin | app/Services/Marketplace/OperationsPolicy.php (ops_finance_grace_hours, default 6), read at app/Services/SellerIntelligence/Producers/FinanceIntegrityProducer.php:51; LOOKBACK_DAYS = 90 and LIMIT = 200 remain sweep bounds |
| Low-stock threshold used by the seller API and the Flutter app | inventory | Admin | app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:42 (LOW_STOCK_THRESHOLD = 5) used at :69 and :73; mirrored in the Flutter app at /home/user/sillercenter-syria-cosmatics/lib/features/inventory/domain/models/inventory_models.dart:32 (lowStockThreshold ?? 5) |
| What counts as low stock — three surviving and mutually inconsistent definitions (7 days of cover, 1/3 days of cover, 14 days of cover) | inventory | Admin | app/Services/Platform/PolicyRegistry.php inventory group (stock_cover_critical_days, stock_cover_low_days, stock_cover_raise_days, stock_cover_opportunity_days, stock_velocity_days) read through app/Services/Marketplace/StockPolicy.php by InventoryRiskProducer.php:42, app/Services/SellerCenter/Lists/InventoryList.php and app/Services/SellerCenter/Automation/Opportunities.php |
| When unsold stock is called dead capital (90 days, at least 3 units) | inventory | Admin | app/Services/Platform/PolicyRegistry.php (stock_stale_days, stock_stale_minimum_units) read at app/Services/SellerIntelligence/Producers/StaleInventoryProducer.php:50-51 |
| Batch expiry warning horizon — stock expiring within 30 days is shown as expiring soon | compliance | Admin | app/Services/Marketplace/OperationsPolicy.php (ops_batch_expiry_days, default 30), read at app/Services/Marketplace/BatchService.php:61 |
| How much notice a seller gets before a verification document expires (45 days) | compliance | Admin | app/Services/Platform/PolicyRegistry.php (compliance_expiry_notice_days) read at app/Services/SellerCenter/Counts.php:181 |
| The listing quality bar (a score under 70 is raised for improvement) | catalog | Admin | app/Services/Platform/PolicyRegistry.php (catalog_quality_bar) read at app/Services/SellerIntelligence/Producers/ListingQualityProducer.php:61 |
| Merchandising limits per collection — 12 pins, 100 exclusions, 20 boosts, boost weight up to 1000, fallback chains 5 deep | catalog | Admin | app/Services/Platform/PolicyRegistry.php commerce group (commerce_max_pins, commerce_max_exclusions, commerce_max_boosts, commerce_max_boost_weight, commerce_max_chain, commerce_max_collection_rules) read at app/Services/Commerce/MerchandisingRules.php and CollectionRuleRegistry.php |
| How many variants a storefront experiment may run, and how many rules a segment or campaign may carry | automation | Admin | app/Services/Platform/PolicyRegistry.php (commerce_max_variants, commerce_max_segment_rules, commerce_max_campaign_overrides) read at app/Services/Commerce/ExperimentRules.php, SegmentRules.php and CampaignRules.php |
| What counts as a suspicious price swing (more than half the previous price within 48 hours) | pricing | Admin | app/Services/Platform/PolicyRegistry.php (catalog_price_swing_ratio, catalog_price_swing_hours) read at app/Services/SellerIntelligence/Producers/PricingRiskProducer.php:57-58 |
| What counts as a late order — three definitions that disagree with the configurable SLA deadline (72-hour stuck, quarter-of-window urgent, fixed 120/480-minute colour bands) | orders | Admin | app/Services/Marketplace/OperationsPolicy.php (ops_stuck_order_hours, ops_stuck_stop_after_days, ops_sla_urgent_fraction, ops_sla_closing_minutes, ops_sla_soon_minutes), read at app/Services/SellerCenter/Status.php:211, OrderStuckProducer.php:58,64 and OrderSlaProducer.php:72; deadline still from app/Services/Marketplace/SlaService.php:89 processingDeadline() |
| The returns response promise — 48 hours to answer a return request, 72 hours to process it | returns | Admin | app/Services/Marketplace/OperationsPolicy.php (ops_returns_response_hours, ops_returns_processing_hours), read at app/Services/SellerIntelligence/Producers/ReturnsRiskProducer.php:53,111 |
| How long a shipment may go without courier movement before it is raised as an exception (72 hours) | shipping | Admin | app/Services/Platform/PolicyRegistry.php (shipping_silent_hours, shipping_stop_after_days) read at app/Services/SellerIntelligence/Producers/ShippingExceptionProducer.php:46-48 |
| Seller health tiers — the good / watch / at-risk bands on the admin scorecard | compliance | Admin | app/Services/Platform/PolicyRegistry.php compliance group (health_watch_* and health_at_risk_* for cancellation, return, refund, rating and strikes) read at app/Services/Marketplace/SellerScorecardService.php:104-137 |
| Silent truncation caps — 500 open issues, 500 SLA deadlines, 200 audit rows, 200 sellers in the admin rollup, 200 automation rules per sweep | platform | Admin | app/Services/Platform/PolicyRegistry.php platform group (limit_automation_sweep, limit_audit_rows, limit_control_tower_rows, limit_admin_seller_rollup) read at AutomationEngine.php, SellerAuditTrailService.php, ControlTowerService.php and SellerOperationsOverview.php |
| Minimum password length — 6 characters on some surfaces and 8 on others | security | Admin | app/Services/Platform/PasswordPolicy.php over the password_minimum_length policy, used by every validator where a password is CHOSEN (registration, reset, staff and deliveryman creation, on web and API); sign-in validators are deliberately excluded so raising the minimum cannot lock out an existing account |
| Brute-force tolerance — 20 attempts a minute on auth endpoints, 3000 a minute globally | security | Admin | app/Providers/RouteServiceProvider.php:182 defines the `auth` and `global` limiters from the auth_attempts_per_minute and api_requests_per_minute policies; the six route files now use `throttle:auth` rather than a repeated literal |
| Outbound webhook retry policy — five attempts, doubling backoff, 8-second timeout | integrations | Admin | app/Services/Platform/PolicyRegistry.php (webhook_max_attempts, webhook_timeout_seconds, webhook_backoff_minutes) read at app/Services/Marketplace/SellerWebhookDispatcher.php |
| Approve, reject or suspend a seller account (single and bulk) | platform | Admin | app/Http/Controllers/Admin/Vendor/VendorController.php:210 updateStatus + :167 bulkUpdateStatus; routes/admin/routes.php:498 admin.vendors.updateStatus, :501 admin.vendors.bulk-status; nav resources/views/layouts/admin/partials/v2/_side-bar.blade.php:493; routes routes/admin/routes.php:947 admin.vendors.update-vendor-status; the file contains zero AuditLogger references |
| Commission rules — the rate the marketplace charges, by global, category, vendor or product scope | finance | Admin | app/Http/Controllers/Admin/Marketplace/CommissionRuleController.php; app/Services/Marketplace/CommissionEngine.php; routes/admin/routes.php:768-776 admin.marketplace.commission-rules.*; nav _side-bar.blade.php:659; app/Services/Marketplace/CommissionEngine.php:46 (const DEFAULT_PRIORITY = [product 400, vendor 300, category 200, global 100]); legacy fallback at app/Utils/Helpers.php:633 seller_sales_commission() reading seller.sales_commission_percentage then setting 'sales_commission'; app/Http/Controllers/Admin/Marketplace/CommissionRuleController.php (routes/admin/routes.php:768-776), evaluated by app/Services/Marketplace/CommissionEngine.php; app/Http/Controllers/Admin/Marketplace/CommissionRuleController.php:60 store (audited 'commission.rule_created' at :63), :73 update, :81 toggle, :90 destroy (all three unaudited); routes under routes/admin/routes.php:549 marketplace group |
| Per-seller commission override on the vendor record | finance | Admin | app/Http/Controllers/Admin/Vendor/VendorController.php:314 updateSalesCommission, :591 updateSetting; routes/admin/routes.php:505 admin.vendors.sales-commission-update, :508 admin.vendors.update-setting; no AuditLogger in the file |
| Seller payout queue — approve, mark paid or reject a requested payout | finance | Admin | app/Http/Controllers/Admin/Marketplace/PayoutController.php:60/:80/:109; app/Services/Marketplace/PayoutService.php:190/:236; routes/admin/routes.php:566-573; nav _side-bar.blade.php:653; app/Services/Marketplace/PayoutService.php:252 releaseReservation(), posting a TYPE_MANUAL_ADJUSTMENT ledger credit at :268 with no audit call; reached from app/Http/Controllers/Admin/Marketplace/PayoutController.php:109 reject(); route routes/admin/routes.php:571 |
| Legacy vendor withdraw requests — the classic money-out channel, still live beside ledger payouts | finance | Admin | app/Http/Controllers/Admin/Vendor/VendorController.php:757 withdrawStatus; routes/admin/routes.php:510-513 admin.vendors.withdraw_list / withdraw_status; nav _side-bar.blade.php:497; routes routes/admin/routes.php admin.vendors.withdraw_list group; the file contains zero AuditLogger references |
| Vendor settlements — calculate, approve, mark paid, cancel | finance | Admin | app/Http/Controllers/Admin/Marketplace/SettlementController.php; app/Services/Marketplace/SettlementEngine.php:120 approve/:147 markPaid/:178 cancel; routes/admin/routes.php:550-560; cron marketplace:settle --release at bootstrap/app.php:147; nav _side-bar.blade.php:648; app/Services/Marketplace/SettlementEngine.php:178 cancel(), nulling settlement_id on the entries at :185 with no audit call; reached from app/Http/Controllers/Admin/Marketplace/SettlementController.php:154 |
| Separation of duties on settlements — the approver may not be the payer | finance | Admin | app/Http/Controllers/Admin/Marketplace/SettlementController.php:61 toggleMakerChecker; routes/admin/routes.php:554; setting key settlement_maker_checker; business_settings `settlement_maker_checker` written at app/Http/Controllers/Admin/Marketplace/SettlementController.php:64 (route admin.marketplace.settlements.toggle-maker-checker, routes/admin/routes.php:554); enforced at SettlementController.php:135 and app/Http/Controllers/Admin/Marketplace/PayoutController.php:90; no audit call in the file |
| SLA policy — maximum cancellation, return and refund rates, minimum rating, processing deadline | compliance | Admin | app/Services/Marketplace/SlaService.php:43-53 (settings sla_max_cancellation_rate 0.10, sla_max_return_rate 0.10, sla_max_refund_rate 0.15, sla_min_rating 3.5, sla_processing_hours 24); admin page routes/admin/routes.php:696 -> resources/views/admin-views/marketplace/sla.blade.php:27-37; app/Http/Controllers/Admin/Marketplace/SlaController.php:56-75 writes business_settings `sla_max_cancellation_rate`, `sla_max_return_rate`, `sla_max_refund_rate`, `sla_min_rating`, `sla_processing_hours`; read at app/Services/Marketplace/SlaService.php:43-52; route admin.marketplace.sla.settings (routes/admin/routes.php:696); app/Http/Controllers/Admin/Marketplace/SlaController.php:56 updateSettings(), writing five BusinessSetting rows at :68-70; route routes/admin/routes.php:698 admin.marketplace.sla.settings; no audit call in the file; app/Services/Marketplace/SlaService.php:28 (const MIN_REVIEWS_FOR_R |
| Seller KYC — which documents are required, whether payouts are gated on them, and reviewing what a seller submits | compliance | Admin | app/Http/Controllers/Admin/Marketplace/SellerVerificationController.php:80 approve/:100 reject; app/Services/Marketplace/SellerVerificationService.php:231; routes/admin/routes.php:613-620 admin.marketplace.seller-verification.*; nav _side-bar.blade.php:710; routes/vendor/routes.php:403-409 — vendor/business-settings/seller-verification; routes/rest_api/v3/seller.php:422-433 — seller-center/verification (seller_owner); app/Services/Marketplace/SellerVerificationService.php; app/Services/Marketplace/SellerVerificationService.php:198 'seller.kyc_submitted', :231 'seller.kyc_approved', :248 'seller.kyc_rejected'; admin surface app/Http/Controllers/Admin/Marketplace/SellerVerificationController.php:80/:100; no audit call in the file |
| Shipping zones — destination-based rate rules that override the flat shipping cost | shipping | Admin | app/Http/Controllers/Admin/Marketplace/ShippingZoneController.php; app/Services/Marketplace/ShippingRateService.php; routes/admin/routes.php:755-762; nav _side-bar.blade.php:803; routes/admin/routes.php:755-762 — admin/marketplace/shipping-zones; app/Http/Controllers/Admin/Marketplace/ShippingZoneController.php (routes/admin/routes.php:755-763), resolved by app/Services/Marketplace/ShippingRateService.php; app/Http/Controllers/Admin/Marketplace/ShippingZoneController.php:64 toggle (audited 'shipping.zone_shipping_toggled' at :70), :76 store (audited 'shipping.zone_created' at :82), :88 update and :97 destroy (both unaudited); routes under routes/admin/routes.php:549 |
| Carrier configuration for Delivery Syria — base URL, hub, pickup point, secret and webhook tokens | shipping | Admin | app/Http/Controllers/Admin/ThirdParty/DeliverySyriaController.php:31 index/:54 update/:101 verifySync; routes/admin/routes.php:1270-1275 admin.third-party.delivery-syria; app/Http/Controllers/Admin/ThirdParty/DeliverySyriaController.php:54 update(), saving secret_token and webhook_token through app/Services/DeliverySyria/DeliverySyriaConfigService.php at :80-93; routes routes/admin/routes.php:1273; neither the controller nor app/Services/DeliverySyria/ contains any audit reference |
| Payment gateway credentials, live/test mode and on-off state | finance | Admin | app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php; routes/admin/routes.php:1191-1197 admin.third-party.payment-method.*; nav _side-bar.blade.php:982; check command PaymentGatewayCheck.php; addon_settings rows with settings_type='payment_config', written by app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php (routes/admin/routes.php:1191-1196 UpdatePaymentConfig / UpdateStatus); read through config_settings() at app/Utils/module-helper.php:570 and app/Traits/Processor.php:59; bootstrapped into config by app/Providers/PaymentConfigProvider.php; app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php:123 UpdatePaymentConfig() writing settings_type 'payment_config' at :159, and :176 UpdateStatus; route routes/admin/routes.php:1194 addon-payment-set; no audit reference in the file |
| Payment routing rules — hide or prefer a gateway by order amount or destination | finance | Admin | app/Http/Controllers/Admin/Marketplace/PaymentRoutingController.php; app/Services/Marketplace/PaymentRoutingService.php; routes/admin/routes.php:780-787; nav _side-bar.blade.php:671; app/Http/Controllers/Admin/Marketplace/PaymentRoutingController.php (routes/admin/routes.php:780-787); gateway codes enumerated from addon_settings at PaymentRoutingController.php:103-108; resolved by app/Services/Marketplace/PaymentRoutingService.php:25; app/Http/Controllers/Admin/Marketplace/PaymentRoutingController.php:50 store (audited 'payment.routing_rule_created' at :53), :59 update and :67 destroy (both unaudited) |
| Coupons, and the promotional discount surfaces beside them (flash deals, deal of the day, featured deals, clearance offers) | pricing | Admin | app/Http/Controllers/Admin/Promotion/CouponController.php; app/Services/CouponService.php; routes/admin/routes.php:882-895; nav _side-bar.blade.php:376; app/Http/Controllers/Admin/Promotion/CouponController.php and FlashDealController.php (both zero AuditLogger references, verified by grep -c); the whole app/Http/Controllers/Admin/Promotion/ directory has no audit call |
| Arm the KYC-required-for-payout gate and the required document list | compliance | Admin | app/Http/Controllers/Admin/Marketplace/SellerVerificationController.php:124 updateSettings; routes/admin/routes.php:619 admin.marketplace.seller-verification.settings; settings keys require_kyc_for_payout, kyc_required_documents |
| Brand registry: decide who may sell under a brand, on documentary evidence | brands | Admin | app/Http/Controllers/Admin/Marketplace/BrandRegistryController.php:120 approve/:142 reject/:152 revoke; app/Services/Marketplace/BrandRegistryService.php:306; routes/admin/routes.php:625-634; nav _side-bar.blade.php:716 |
| Brand enforcement switch: turn the brand registry from a report into a refusal | brands | Admin | app/Http/Controllers/Admin/Marketplace/BrandRegistryController.php:175 updateEnforcement; routes/admin/routes.php:632 admin.marketplace.brand-registry.enforcement |
| Seller SLA policy: set the thresholds a seller is held to | compliance | Admin | app/Http/Controllers/Admin/Marketplace/SlaController.php:56 updateSettings; app/Services/Marketplace/SlaService.php:38 thresholds(); routes/admin/routes.php:696-702; settings sla_max_cancellation_rate, sla_max_return_rate, sla_max_refund_rate, sla_min_rating, sla_processing_hours; nav _side-bar.blade.php:728 |
| Evaluate SLA breaches on demand | compliance | System | app/Http/Controllers/Admin/Marketplace/SlaController.php:77 evaluate; app/Services/Marketplace/SlaService.php:170 sla.breach_opened; routes/admin/routes.php:700; command marketplace:evaluate-sla at bootstrap/app.php:155 |
| Seller performance scorecard (quality metrics and health tier) | compliance | Admin | app/Http/Controllers/Admin/Marketplace/SellerScorecardController.php; app/Services/Marketplace/SellerScorecardService.php; routes/admin/routes.php:657-661 admin.marketplace.seller-scorecard.index; nav _side-bar.blade.php:722; routes/vendor/routes.php:412-416 — vendor/business-settings/seller-scorecard; routes/rest_api/v3/seller.php:418 — seller-center/scorecard |
| Payout methods available to sellers (withdrawal method registry) | finance | Admin | app/Http/Controllers/Admin/ThirdParty/WithdrawalMethodController.php; routes/admin/routes.php:1277-1287 admin.third-party.withdraw-method.*; nav via Payment Methods group _side-bar.blade.php:982; app/Http/Controllers/Admin/ThirdParty/WithdrawalMethodController.php (routes/admin/routes.php:1277-1287 add/update/status/default) |
| Financial reconciliation: integrity checks over ledger, commission snapshots and settlements | finance | Admin | app/Http/Controllers/Admin/Marketplace/ReconciliationController.php; app/Services/Marketplace/ReconciliationService.php:33 run(); routes/admin/routes.php:718 admin.marketplace.reconciliation; nav _side-bar.blade.php:665 |
| Per-seller ledger and running balance | finance | Admin | app/Http/Controllers/Admin/Marketplace/SettlementController.php ledger(); app/Services/Marketplace/VendorLedger.php; routes/admin/routes.php:560 admin.marketplace.ledger |
| Approvals inbox (reusable maker-checker engine) | finance | Admin | app/Http/Controllers/Admin/Marketplace/ApprovalController.php; app/Services/ApprovalEngine.php:41 open(); routes/admin/routes.php:583-589; nav _side-bar.blade.php:705; routes/admin/routes.php:583-589 — admin/marketplace/approvals; app/Services/ApprovalEngine.php; toggle at routes/admin/routes.php:554 (toggle-maker-checker); app/Services/ApprovalEngine.php:72 '{workflow}.approval_requested', :124 '{workflow}.approved' / '.approval_added', :156 '{workflow}.rejected'; admin inbox app/Http/Controllers/Admin/Marketplace/ApprovalController.php:51/:56 (routes/admin/routes.php:584-586) |
| Unified audit trail viewer | security | Admin | app/Http/Controllers/Admin/Marketplace/AuditLogController.php:23; app/Services/AuditLogger.php:31 record(); routes/admin/routes.php:577 admin.marketplace.audit-log; nav _side-bar.blade.php:812; Only the console send is recorded — app/Services/DeveloperPortal/ApiConsole.php:70 `developer.console.sent` through app/Services/AuditLogger.php:29; snapshot (DeveloperPortalController.php:136), refresh (:221), openapi (:102) and postman (:118) record nothing |
| Product moderation queue with per-product history | catalog | Admin | app/Http/Controllers/Admin/Marketplace/ProductModerationController.php:47 moderate/:75 bulk; app/Services/Marketplace/ProductModerationService.php:121; routes/admin/routes.php:594-601; nav _side-bar.blade.php:734 |
| Legacy vendor product approval (approve / deny) on the product list | catalog | Admin | app/Http/Controllers/Admin/Product/ProductController.php approveStatus/deny; routes/admin/routes.php:308 admin.products.deny, :309 admin.products.approve-status; nav _side-bar.blade.php:81-105 |
| Per-category governance: return window, tax class, required attributes, forced moderation | compliance | Admin | app/Http/Controllers/Admin/Marketplace/CategoryGovernanceController.php; app/Services/Marketplace/CategoryGovernanceService.php:24 returnWindowDays/:56 requiresModeration/:66 requiredAttributes/:91 taxClass; routes/admin/routes.php:605-609; nav _side-bar.blade.php:740; routes/admin/routes.php:605-610 — admin/marketplace/category-governance; app/Services/Marketplace/CategoryGovernanceService.php; category_governance table via app/Http/Controllers/Admin/Marketplace/CategoryGovernanceController.php; read at app/Services/Marketplace/CategoryGovernanceService.php:26 (refund override), :66 (required attributes), :91 (tax class); enforced on listing at app/Services/ProductService.php:632; routes under routes/admin/routes.php:604 |
| Global return / refund policy (refund day limit, wallet refunds) | returns | Admin | app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:486 updateRefundSetup; routes/admin/routes.php:1347 admin.business-settings.refund-setup; settings refund_day_limit, wallet_add_refund; setting keys refund_day_limit, wallet_add_refund; app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:488-489 writes `refund_day_limit` and `wallet_add_refund`; route admin.business-settings.refund-setup (routes/admin/routes.php:1347); category-level override read at app/Services/Marketplace/CategoryGovernanceService.php:26 |
| RMA / returns logistics queue (authorize, in-transit, receive, reject) | returns | Admin | app/Http/Controllers/Admin/Marketplace/ReturnLogisticsController.php; app/Services/Marketplace/ReturnLogisticsService.php; routes/admin/routes.php:706-713; nav _side-bar.blade.php:791; routes/rest_api/v3/seller.php:639-648 — GET/POST api/v3/seller/seller-center/returns[/{id}/in-transit\|receive\|reject] |
| Shipping methods and shipping responsibility (admin vs seller) | shipping | Admin | app/Http/Controllers/Admin/Shipping/ShippingMethodController.php; routes/admin/routes.php:1358-1367 admin.business-settings.shipping-method.*; :1370 shipping-type; :1374 category-shipping-cost |
| Dispatch an order to the carrier | shipping | Admin | app/Http/Controllers/Admin/Order/DeliverySyriaDispatchController.php; routes/admin/routes.php:332 admin.orders.delivery-syria.dispatch |
| Marketplace campaigns: scheduled storefront overlays with conflict checking | platform | Admin | app/Http/Controllers/Admin/Commerce/CampaignController.php; app/Services/Commerce/CampaignRules.php; routes/admin/routes.php:1445-1450 admin.commerce.campaigns.*; cron commerce:campaigns-tick bootstrap/app.php:238 |
| Tracked marketing campaigns (UTM links, short links, QR codes) | analytics | Admin | app/Http/Controllers/Admin/Telemetry/AnalyticsController.php storeCampaign/toggleCampaign/campaignQr; app/Services/Analytics/CampaignService.php; routes/admin/routes.php:217-221; section app/Services/Analytics/Reporting/AnalyticsNavigation.php:21 |
| Banner placement across storefront and app | platform | Admin | app/Http/Controllers/Admin/Promotion/BannerController.php; app/Services/BannerPlacementService.php; routes/admin/routes.php:432-442; nav _side-bar.blade.php:358 |
| Seller-facing issue register (Control Tower issues seen from the marketplace side) | monitoring | Admin | app/Http/Controllers/Admin/Marketplace/SellerOperationsController.php:64 issues; app/Services/Marketplace/SellerOperationsOverview.php; app/Services/SellerIntelligence/Producers/*; routes/admin/routes.php:644 admin.marketplace.seller-operations.issues; cron seller:escalate-issues bootstrap/app.php:170 |
| Seller automation oversight: stop a rule that is damaging a catalogue | automation | Admin | app/Http/Controllers/Admin/Marketplace/SellerOperationsController.php:80 automation + suspendRule/releaseRule; routes/admin/routes.php:649-650; nav _side-bar.blade.php:700 |
| Seller API key oversight: revoke a leaked key | security | Admin | app/Http/Controllers/Admin/Marketplace/SellerOperationsController.php revokeKey; app/Services/Marketplace/SellerApiKeyService.php; routes/admin/routes.php:651 admin.marketplace.seller-operations.revoke-key; app/Services/Marketplace/SellerApiKeyService.php:61 'seller.api_key_issued', :121 'seller.api_key_revoked'; seller API app/Http/Controllers/RestAPI/v3/seller/SellerIntegrationController.php:80/:125 (routes/rest_api/v3/seller.php:522-523); admin revoke app/Http/Controllers/Admin/Marketplace/SellerOperationsController.php:213 audited at :226 |
| Seller webhook oversight: disable an endpoint being hammered | integrations | Admin | app/Http/Controllers/Admin/Marketplace/SellerOperationsController.php disableWebhook; app/Services/Marketplace/SellerWebhookDispatcher.php; routes/admin/routes.php:652; cron seller:retry-webhooks bootstrap/app.php:182 |
| Seller staff and role oversight (who is acting for a shop) | security | Admin | app/Http/Controllers/Admin/Marketplace/SellerOperationsController.php team; app/Services/Marketplace/SellerTeamService.php; routes/admin/routes.php:647 admin.marketplace.seller-operations.team; seller_roles / seller_staff via app/Services/Marketplace/SellerPermissionService.php:30 catalog(), :50 sanitize(), :215 staffCan(); enforced by the seller_can middleware on every Seller Center route (routes/seller/routes.php:65-107) and every v3 API route; admin read-only view at routes/admin/routes.php:645 seller-operations/team; app/Services/Marketplace/SellerTeamService.php:48 role_created, :68 role_updated, :87 role_deleted, :119 staff_added, :166 staff_updated, :181 staff_removed, :201 staff_signed_out, :228 api_keys_revoked_with_staff; both doors go through it — web app/Http/Controllers/Vendor/Marketplace/SellerStaffController.php:42 (routes/vendor/routes.php:428) and API app/Http/Controller |
| Admin and vendor earnings reports | finance | Admin | app/Http/Controllers/Admin/ReportController.php; routes/admin/routes.php:810-818 admin.report.admin-earning / vendor-earning; nav _side-bar.blade.php:585 |
| In-house and vendor product sale reports | analytics | Admin | app/Http/Controllers/Admin/InhouseProductSaleController.php, VendorProductSaleReportController.php; routes/admin/routes.php:486-490, :831-834; nav _side-bar.blade.php:603/607 |
| Customer wallet: balances, manual fund adjustment and bonus rules | finance | Admin | app/Http/Controllers/Admin/Customer/CustomerWalletController.php; routes/admin/routes.php:464-476 admin.customer.wallet.*; nav _side-bar.blade.php:468/472 |
| Customer loyalty points | finance | Admin | app/Http/Controllers/Admin/Customer/CustomerLoyaltyController.php; routes/admin/routes.php:478-484 admin.customer.loyalty.*; nav _side-bar.blade.php:476 |
| Exchange-rate governance with change history | finance | Admin | app/Http/Controllers/Admin/Marketplace/ExchangeRateController.php; app/Services/Marketplace/ExchangeRateService.php; routes/admin/routes.php:791-797; nav _side-bar.blade.php:677; app/Http/Controllers/Admin/Marketplace/ExchangeRateController.php (routes/admin/routes.php:791-794) over the currencies table; conversion itself in app/Utils/currency.php; app/Services/Marketplace/ExchangeRateService.php:66 'currency.rate_changed' with before/after, plus its own exchange_rate_logs row at :55; admin surface app/Http/Controllers/Admin/Marketplace/ExchangeRateController.php:40 bulkUpdate (route routes/admin/routes.php:789) |
| Offline payment methods | finance | Admin | app/Http/Controllers/Admin/Payment/OfflinePaymentMethodController.php; routes/admin/routes.php:1199-1209 admin.third-party.offline-payment-method.*; app/Http/Controllers/Admin/Payment/OfflinePaymentMethodController.php (routes/admin/routes.php:1199-1208); master switch business_settings `offline_payment` written at BusinessSettingsController.php:548 |
| Invoice settings | finance | Admin | app/Http/Controllers/Admin/Settings/InvoiceSettingsController.php; routes/admin/routes.php:1393-1398 admin.business-settings.invoice-settings.* |
| VAT / tax rules and tax reports (TaxModule addon) | finance | Admin | Modules/TaxModule/Addon/tax_routes.php and tax_report_routes.php, loaded into the sidebar at resources/views/layouts/admin/partials/v2/_side-bar.blade.php:9 and rendered at :822/:914 |
| In-house product catalogue (create, edit, publish, feature, delete) | catalog | Admin | app/Http/Controllers/Admin/Product/ProductController.php; routes/admin/routes.php:279-317 admin.products.*; nav _side-bar.blade.php:54-77 |
| Bulk product import from spreadsheet | catalog | Admin | app/Http/Controllers/Admin/Product/ProductController.php importBulkProduct; routes/admin/routes.php:304-305 admin.products.bulk-import; nav _side-bar.blade.php:73; routes/vendor/routes.php:175-176 — GET/POST vendor/products/bulk-import |
| Categories, sub-categories and sub-sub-categories | catalog | Admin | app/Http/Controllers/Admin/Product/{CategoryController,SubCategoryController,SubSubCategoryController}.php; routes/admin/routes.php:395-429; nav _side-bar.blade.php:128-138 |
| Brand catalogue (the brand list itself, distinct from the brand registry) | brands | Admin | app/Http/Controllers/Admin/Product/BrandController.php; routes/admin/routes.php:380-392 admin.brand.*; nav _side-bar.blade.php:141 |
| Product attributes | catalog | Admin | app/Http/Controllers/Admin/Product/AttributeController.php; routes/admin/routes.php:368-377; nav _side-bar.blade.php:145 |
| Product gallery / media library | catalog | Admin | app/Http/Controllers/Admin/Product/ProductController.php getProductGalleryView; routes/admin/routes.php:310 admin.products.product-gallery; nav _side-bar.blade.php:149 |
| Limited stock list and restock requests | inventory | Admin | app/Http/Controllers/Admin/Product/ProductController.php getStockLimitListView / getRequestRestockListView; routes/admin/routes.php:296, :313-315; nav _side-bar.blade.php:65/69 |
| Inventory adjustments with reason codes and a movement log | inventory | Admin | app/Http/Controllers/Admin/Marketplace/InventoryAdjustmentController.php; app/Services/Marketplace/InventoryService.php; routes/admin/routes.php:687-692; nav _side-bar.blade.php:773 |
| Batch and expiry tracking with write-off | inventory | Admin | app/Http/Controllers/Admin/Marketplace/BatchController.php; app/Services/Marketplace/BatchService.php; routes/admin/routes.php:722-728; nav _side-bar.blade.php:779; app/Services/Marketplace/BatchService.php:42 'inventory.batch_added', :117 'inventory.batch_written_off'; admin surface app/Http/Controllers/Admin/Marketplace/BatchController.php:64/:87 |
| Multi-warehouse locations, stock placement and transfers | inventory | Admin | app/Http/Controllers/Admin/Marketplace/WarehouseController.php; app/Services/Marketplace/WarehouseService.php; routes/admin/routes.php:732-740; nav _side-bar.blade.php:785; app/Http/Controllers/Admin/Marketplace/WarehouseController.php:54 store (audited 'warehouse.created' at :72), :97 place and :111 transfer (audited in app/Services/Marketplace/WarehouseService.php:75 and :135), :78 update (unaudited) |
| Suppliers registry | inventory | Admin | app/Http/Controllers/Admin/Marketplace/SupplierController.php; routes/admin/routes.php:665-671; nav _side-bar.blade.php:762 |
| Purchase orders (create, place, receive, cancel) | inventory | Admin | app/Http/Controllers/Admin/Marketplace/PurchaseOrderController.php; app/Services/Marketplace/PurchaseOrderService.php; routes/admin/routes.php:673-683; nav _side-bar.blade.php:767; app/Services/Marketplace/PurchaseOrderService.php:54 po_created, :94 po_placed, :165 po_received, :201 po_canceled; admin surface app/Http/Controllers/Admin/Marketplace/PurchaseOrderController.php |
| Fulfilment workflow overlay (pick / pack / ship) | orders | Admin | app/Http/Controllers/Admin/Marketplace/FulfillmentController.php; app/Services/Marketplace/FulfillmentService.php; routes/admin/routes.php:744-751; nav _side-bar.blade.php:797; routes/admin/routes.php:744-751 — admin/marketplace/fulfillments; app/Services/Marketplace/FulfillmentService.php. No seller route in routes/vendor, routes/seller or routes/rest_api/v3 |
| Order list, detail and status changes (single and bulk) | orders | Admin | app/Http/Controllers/Admin/Order/OrderController.php; routes/admin/routes.php:330-354 admin.orders.*; nav _side-bar.blade.php:170-228 |
| Edit an order after placement (add, remove, reprice lines) | orders | Admin | app/Http/Controllers/Admin/Order/OrderEditController.php; app/Services/OrderEditService.php; routes/admin/routes.php:356-365; routes/vendor/routes.php:207-215 — POST vendor/orders/edit-order-*; routes/rest_api/v3/seller.php:217-223 — POST api/v3/seller/orders/edit-order-submit; gated by setting key vendor_can_edit_order |
| Order payment status, due amount and COD switching | orders | Admin | app/Http/Controllers/Admin/Order/OrderController.php updatePaymentStatus / orderDueAmountSwitchToCOD / orderDueAmountMarkAsPaid; routes/admin/routes.php:342, :352-354; routes/vendor/routes.php:197,204-205 — POST vendor/orders/payment-status, customer-due-amount, customer-due-amount-mark-as-paid; routes/rest_api/v3/seller.php:210 — POST update-payment-status |
| Order settings (statuses, delivery, order rules) | orders | Admin | app/Http/Controllers/Admin/Settings/OrderSettingsController.php; routes/admin/routes.php:1339-1344 admin.business-settings.order-settings.* |
| Point of sale (admin-side in-store selling) | orders | Admin | app/Http/Controllers/Admin/POS/{POSController,CartController,POSOrderController}.php; routes/admin/routes.php:241-269 admin.pos.*; nav _side-bar.blade.php:35 |
| Customer accounts: list, block/unblock, delete, manual creation | platform | Admin | app/Http/Controllers/Admin/Customer/CustomerController.php; routes/admin/routes.php:445-462 admin.customer.*; nav _side-bar.blade.php:460 |
| Customer reviews moderation and admin replies | catalog | Admin | app/Http/Controllers/Admin/Product/ReviewController.php; routes/admin/routes.php:869-879 admin.reviews.*; nav _side-bar.blade.php:464 |
| Newsletter subscribers | notifications | Admin | app/Http/Controllers/Admin/Customer/CustomerController.php getSubscriberListView; routes/admin/routes.php:456-457; nav _side-bar.blade.php:481 |
| Vendor directory, profile view and manual vendor creation | platform | Admin | app/Http/Controllers/Admin/Vendor/VendorController.php; routes/admin/routes.php:492-518 admin.vendors.*; nav _side-bar.blade.php:489/493 |
| Delivery men: accounts, earnings, cash collection, withdrawals, ratings | shipping | Admin | app/Http/Controllers/Admin/Deliveryman/{DeliveryManController,DeliveryManCashCollectController,DeliverymanWithdrawController,EmergencyContactController}.php; routes/admin/routes.php:1008-1050; nav _side-bar.blade.php:505-518 |
| Admin employees and custom roles / permissions | security | Admin | app/Http/Controllers/Admin/Employee/{EmployeeController,CustomRoleController}.php; module keys at app/Enums/GlobalConstant.php:1255-1268; routes/admin/routes.php:520-544; nav _side-bar.blade.php:526/530 |
| Support tickets | platform | Admin | app/Http/Controllers/Admin/HelpAndSupport/SupportTicketController.php; routes/admin/routes.php:979-986; nav _side-bar.blade.php:545 |
| Customer and vendor chat inbox | platform | Admin | app/Http/Controllers/Admin/ChattingController.php; routes/admin/routes.php:988-994 admin.messages.*; nav _side-bar.blade.php:541 |
| Contact form submissions | platform | Admin | app/Http/Controllers/Admin/HelpAndSupport/ContactController.php; routes/admin/routes.php:996-1006; nav _side-bar.blade.php:551 |
| Help topics / FAQ content | platform | Admin | app/Http/Controllers/Admin/HelpAndSupport/HelpTopicController.php; routes/admin/routes.php:1686-1696 admin.helpTopic.* |
| Flash deals, deal of the day and featured deals | pricing | Admin | app/Http/Controllers/Admin/Promotion/{FlashDealController,DealOfTheDayController,FeaturedDealController}.php; routes/admin/routes.php:897-926; nav _side-bar.blade.php:380-390 |
| Clearance sale with vendor offers and priority setup | pricing | Admin | app/Http/Controllers/Admin/Promotion/{ClearanceSaleController,ClearanceSaleVendorOfferController,ClearanceSalePrioritySetupController}.php; routes/admin/routes.php:928-957; nav _side-bar.blade.php:392 |
| Most demanded products merchandising | catalog | Admin | app/Http/Controllers/Admin/Promotion/MostDemandedController.php; routes/admin/routes.php:1052-1061 admin.most-demanded.* |
| Storefront priority setup (default sort and ranking rules) | catalog | Admin | app/Http/Controllers/Admin/Settings/PrioritySetupController.php; app/Services/PrioritySetupService.php; routes/admin/routes.php:1416-1422; nav _side-bar.blade.php:938 |
| Send a push notification to customers | notifications | Admin | app/Http/Controllers/Admin/Notification/NotificationController.php; app/Services/PushNotificationService.php; routes/admin/routes.php:967-977; nav _side-bar.blade.php:402 |
| Push notification message templates and Firebase setup | notifications | Admin | app/Http/Controllers/Admin/Notification/PushNotificationSettingsController.php; routes/admin/routes.php:960-965, :1211-1220; nav _side-bar.blade.php:406/986; business_settings `fcm_credentials`, `fcm_project_id`, `push_notification_key` at app/Http/Controllers/Admin/Notification/PushNotificationSettingsController.php:152-157; `firebase_otp_verification` at app/Http/Controllers/Admin/Settings/FirebaseOTPVerificationController.php:42; routes/admin/routes.php:1211-1220 |
| Site-wide announcement bar | notifications | Admin | app/Http/Controllers/Admin/Settings/BusinessSettingsController.php getAnnouncementView; routes/admin/routes.php:1679-1684 admin.business-settings.announcement; nav _side-bar.blade.php:410 |
| Blog (Blog module addon) | platform | Admin | Modules/Blog with its own RouteServiceProvider; nav resources/views/layouts/admin/partials/v2/_side-bar.blade.php:421-439 |
| Abandoned cart recovery settings and reminder emails | automation | Admin | app/Http/Controllers/Admin/Settings/AbandonedCartSettingsController.php; app/Console/Commands/SendAbandonedCartReminders.php; routes/admin/routes.php:1351-1356; cron bootstrap/app.php:140 and :151 |
| Theme management: versions, publish, schedule, restore, import/export | platform | Admin | app/Http/Controllers/Admin/Settings/ThemeManagementController.php; routes/admin/routes.php:1483-1501 admin.theme.*; cron theme:publish-due bootstrap/app.php:165; nav _side-bar.blade.php:1025 |
| Visual theme builder (sections, blocks, delivery rules, media) | platform | Admin | app/Http/Controllers/Admin/Settings/ThemeBuilderController.php; routes/admin/routes.php:1512-1549 admin.theme.builder.* |
| Global theme settings (branding, colours, typography, layout) | platform | Admin | app/Http/Controllers/Admin/Settings/ThemeSettingsController.php; routes/admin/routes.php:1504-1509 admin.theme.settings.* |
| App Builder: mobile-app pages, sections, media, templates and health | platform | Admin | app/Http/Controllers/Admin/Settings/AppBuilderController.php; routes/admin/routes.php:1465-1477 admin.app-builder.*; nav _side-bar.blade.php:1014 |
| Commerce collections (rule-driven product sets) | catalog | Admin | app/Http/Controllers/Admin/Commerce/CollectionController.php; routes/admin/routes.php:1438-1444; nav _side-bar.blade.php:1020 |
| Customer segments | analytics | Admin | app/Http/Controllers/Admin/Commerce/SegmentController.php; routes/admin/routes.php:1451-1456 |
| Storefront experiments (A/B tests) | analytics | Admin | app/Http/Controllers/Admin/Commerce/ExperimentController.php; routes/admin/routes.php:1457-1462; cron commerce:metrics-refresh bootstrap/app.php:234 |
| Business pages (privacy policy, about, terms) and social media links | platform | Admin | app/Http/Controllers/Admin/Settings/PagesController.php, SocialMediaSettingsController.php, FeaturesSectionController.php; routes/admin/routes.php:1620-1677; nav _side-bar.blade.php:942/946; business_pages via app/Http/Controllers/Admin/Settings/PagesController.php (routes/admin/routes.php:1620-1670); read through getWebConfig('refund-policy'), ('return-policy'), ('cancellation-policy'), ('shipping-policy') and served to apps at ConfigController.php:getBusinessPagesList |
| Seller-recruitment landing page content and registration reasons | platform | Admin | app/Http/Controllers/Admin/Settings/{VendorRegistrationSettingController,VendorRegistrationReasonController}.php; routes/admin/routes.php:1647-1669; nav _side-bar.blade.php:934 |
| SEO settings, robots.txt and webmaster tools | platform | Admin | app/Http/Controllers/Admin/Settings/SEOSettingsController.php; routes/admin/routes.php:1551-1558; nav _side-bar.blade.php:950 |
| SEO templates, translations, per-page meta and redirects | platform | Admin | app/Http/Controllers/Admin/Settings/{SeoTemplateController,SeoTranslationController,RobotsMetaContentController,RedirectController}.php; routes/admin/routes.php:1560-1595; app/Http/Controllers/Admin/Settings/SEOSettingsController.php, RobotsMetaContentController, RedirectController, SeoTemplateController, SiteMapController, SeoHealthController — routes/admin/routes.php:1551-1600 |
| SEO health audit | platform | Admin | app/Http/Controllers/Admin/Settings/SeoHealthController.php; app/Services/Seo/SeoAuditService.php; routes/admin/routes.php:1598 admin.seo-settings.health; linked at resources/views/admin-views/seo-settings/_inline-menu.blade.php:50 |
| Sitemap generation and upload | platform | Admin | app/Http/Controllers/Admin/Settings/SiteMapController.php; routes/admin/routes.php:1600-1607 |
| 404 / error log review | monitoring | Admin | app/Http/Controllers/Admin/Settings/ErrorLogsController.php; routes/admin/routes.php:1609-1616 admin.seo-settings.error-logs.* |
| Environment setup, HTTPS forcing, cache optimise, Passport install | platform | Developer | app/Http/Controllers/Admin/Settings/EnvironmentSettingsController.php; routes/admin/routes.php:1101-1108 admin.system-setup.environment-setup; nav _side-bar.blade.php:960 |
| Maintenance mode (store kill switch) | platform | Admin | app/Http/Controllers/Admin/Settings/BusinessSettingsController.php updateSystemMode; routes/admin/routes.php:1303 admin.business-settings.maintenance-mode; app/Http/Middleware/MaintenanceModeMiddleware.php |
| Business / web configuration (the 71-template settings surface) | platform | Admin | app/Http/Controllers/Admin/Settings/BusinessSettingsController.php; routes/admin/routes.php:1292-1400; index at :166 admin.settings.index via app/Services/Admin/SettingsIndexService.php; nav _side-bar.blade.php:888/895 |
| Seller-facing platform settings (registration open, seller POS, order editing, minimum order) | platform | Admin | app/Http/Controllers/Admin/Settings/VendorSettingsController.php:48-54; routes/admin/routes.php:1312-1317 admin.business-settings.vendor-settings.*; settings seller_registration, seller_pos, vendor_can_edit_order, minimum_order_amount_by_seller |
| Customer-facing settings and product settings | platform | Admin | app/Http/Controllers/Admin/Customer/CustomerController.php getCustomerSettingsView; BusinessSettingsController getProductSettingsView; routes/admin/routes.php:1319-1324, :1333-1337 |
| Delivery man settings and delivery-zone restrictions (countries, zip codes) | shipping | Admin | app/Http/Controllers/Admin/Settings/{DeliverymanSettingsController,DeliveryRestrictionController}.php; routes/admin/routes.php:1326-1332, :1380-1391; app/Http/Controllers/Admin/Settings/DeliveryRestrictionController.php:113 `delivery_country_restriction`, :132 `zip_code_field_status`, :147 `delivery_zip_code_area_restriction`; routes/admin/routes.php:1380-1391 |
| Languages and translation management (including auto-translate) | platform | Admin | app/Http/Controllers/Admin/Settings/LanguageController.php; app/Services/LanguageService.php; routes/admin/routes.php:1122-1137 admin.system-setup.language.* |
| Currencies and system currency switch | finance | Admin | app/Http/Controllers/Admin/Settings/CurrencyController.php; routes/admin/routes.php:1139-1149 admin.system-setup.currency.* |
| Login and OTP settings, and the secret admin login URL | security | Admin | app/Http/Controllers/Admin/SystemSetup/SystemLoginSetupController.php; routes/admin/routes.php:1156-1168; login throttle at routes/admin/routes.php:125; nav _side-bar.blade.php:964 |
| Email templates for every transactional message | notifications | Admin | app/Http/Controllers/Admin/EmailTemplatesController.php; routes/admin/routes.php:1170-1177 admin.system-setup.email-templates.*; nav _side-bar.blade.php:968 |
| Mail (SMTP / SendGrid) configuration and test send | integrations | Admin | app/Http/Controllers/Admin/ThirdParty/MailController.php; routes/admin/routes.php:1250-1257 admin.third-party.mail.*; business_settings `mail_config` and `mail_config_sendgrid`, written by app/Http/Controllers/Admin/ThirdParty/MailController.php (routes/admin/routes.php:1250-1254); read in 45 places including app/Services/MailService.php |
| SMS gateway configuration | integrations | Admin | app/Http/Controllers/Admin/ThirdParty/SMSModuleController.php; routes/admin/routes.php:1258-1263 admin.third-party.sms-module |
| Social login providers | integrations | Admin | app/Http/Controllers/Admin/ThirdParty/SocialLoginSettingsController.php; routes/admin/routes.php:1228-1234; nav _side-bar.blade.php:1000; business_settings `social_login` and `apple_login`, written at app/Http/Controllers/Admin/ThirdParty/SocialLoginSettingsController.php:46 and :56; routes/admin/routes.php:1228-1233 |
| Social media chat widgets | integrations | Admin | app/Http/Controllers/Admin/ThirdParty/SocialMediaChatController.php; routes/admin/routes.php:1244-1248 — POST update only, no GET index |
| Google Maps API key | integrations | Admin | app/Http/Controllers/Admin/ThirdParty/GoogleMapAPIController.php; routes/admin/routes.php:1265-1269 admin.third-party.map-api; app/Http/Controllers/Admin/ThirdParty/GoogleMapAPIController.php:36-38 writes `map_api_key`, `map_api_key_server`, `map_api_status`; routes/admin/routes.php:1266-1268 |
| Marketing / analytics third-party tags (GA, Pixel) | analytics | Admin | app/Http/Controllers/Admin/Settings/BusinessSettingsController.php getAnalyticsView; routes/admin/routes.php:1222-1226 admin.third-party.analytics-index; nav _side-bar.blade.php:990 |
| File storage backend (local vs S3) and credentials | platform | Developer | app/Http/Controllers/Admin/Settings/StorageConnectionSettingsController.php; routes/admin/routes.php:1236-1242; business_settings `storage_connection_type` and `storage_connection_s3_credential`, written at app/Http/Controllers/Admin/Settings/StorageConnectionSettingsController.php:87 and :144; applied at boot by AppServiceProvider::setStorageConnectionEnvironment (app/Providers/AppServiceProvider.php:177); every image URL routes through app/Utils/settings.php:146 storageLink() |
| File manager / media gallery | platform | Admin | app/Http/Controllers/Admin/Settings/FileManagerController.php; app/Services/FileManagerService.php; routes/admin/routes.php:1179-1186; nav _side-bar.blade.php:972 |
| Database maintenance (clean-up) | platform | Developer | app/Http/Controllers/Admin/Settings/DatabaseSettingController.php; routes/admin/routes.php:1150-1154 admin.system-setup.db-index / clean-db |
| Software update / version installer | platform | Developer | app/Http/Controllers/Admin/Settings/SoftwareUpdateController.php; routes/admin/routes.php:1116-1120 admin.system-setup.software-update |
| Addon modules: publish, unpublish, upload, delete | platform | Admin | app/Http/Controllers/Admin/Settings/AddonController.php; app/Services/AddonService.php; routes/admin/routes.php:1081-1089 admin.system-setup.addon.*; nav _side-bar.blade.php:1079 |
| Addon licence activation by purchase code | platform | Admin | app/Http/Controllers/Admin/Settings/AddonActivationController.php; routes/admin/routes.php:1091-1097; nav _side-bar.blade.php:1083 |
| App settings and deep links (mobile app configuration) | platform | Admin | app/Http/Controllers/Admin/Settings/BusinessSettingsController.php getAppSettingsView / getAppDeepLinkView; app/Services/DeepLink; routes/admin/routes.php:1109-1115; command DeepLinkPublish.php |
| Firebase OTP authentication configuration | security | Admin | app/Http/Controllers/Admin/Settings/FirebaseOTPVerificationController.php; routes/admin/routes.php:1216-1220 admin.third-party.firebase-configuration.authentication |
| Analytics area: visits, acquisition, funnels, catalogue, search, revenue | analytics | Admin | app/Http/Controllers/Admin/Telemetry/AnalyticsController.php; app/Services/Analytics/Reporting/AnalyticsNavigation.php:16-38 (17 sections); routes/admin/routes.php:210-223; cron analytics:rollup bootstrap/app.php:228; nav _side-bar.blade.php:576 |
| Analytics data export and live feed | analytics | Admin | app/Http/Controllers/Admin/Telemetry/AnalyticsController.php export/live; routes/admin/routes.php:215-216 admin.analytics.export, admin.analytics.live-feed |
| Admin dashboard: order status, earnings and real-time activity | analytics | Admin | app/Http/Controllers/Admin/DashboardController.php; app/Services/DashboardService.php; routes/admin/routes.php:154-162; nav _side-bar.blade.php:30; routes/vendor/routes.php:107-117 — vendor/dashboard/*; routes/rest_api/v3/seller.php:110-117 — get-earning-statitics, order-statistics, monthly-earning (seller_can:finance.view) |
| Admin global search | platform | Admin | app/Http/Controllers/Admin/V2AdvancedSearchController.php; routes/admin/routes.php:149-152 admin.v2.advanced-search; wired at resources/views/layouts/admin/partials/v2/_body.blade.php:85 |
| Auction management (Auction module addon) | platform | Admin | Modules/Auction/Addon/admin_routes.php and auction_report_routes.php, loaded into the sidebar at resources/views/layouts/admin/partials/v2/_side-bar.blade.php:7/11 and rendered at :275-345 |
| Admin profile and password | security | Admin | app/Http/Controllers/Admin/ProfileController.php; routes/admin/routes.php:271-277 admin.profile.* |
| Admin authentication and logout | security | Admin | app/Http/Controllers/Admin/Auth/LoginController.php; routes/admin/routes.php:125-128 (throttle:20,1) and :239 admin.logout |
| Upload the digital file a customer bought, after the sale | orders | Seller | routes/vendor/routes.php:201 — POST vendor/orders/digital-file-upload-after-sell; routes/rest_api/v3/seller.php:207 — PUT order-wise-product-upload; gated by setting key digital_product |
| Point of sale — take an in-store order, scan a barcode, apply a coupon, print an invoice | orders | Seller | routes/vendor/routes.php:118-144 — vendor/pos/*; routes/rest_api/v3/seller.php:315-333 — api/v3/seller/pos/* (seller_can:orders.manage); setting keys seller_pos and per-vendor pos_status |
| Product approval decision and the rejection reason the seller reads | catalog | Admin | routes/admin/routes.php:594-600 — admin/marketplace/product-moderation; seller side reads request_status/denied_note at resources/views/vendor-views/product/view.blade.php:17-22; setting key new_product_approval |
| See how much AI generation quota this shop has left | automation | Admin | Modules/AI/routes/api.php:33 — GET api/v3/seller/product/generate-limit-check; quota set by admin at Modules/AI/routes/admin/routes.php:60-61 (vendors-usage-limits) |
| Read customer reviews and reply to them | catalog | Seller | routes/vendor/routes.php:225-232 — vendor/reviews/*; routes/rest_api/v3/seller.php:103-107 — shop-product-reviews, -reply (seller_can:reviews.view); setting key vendor_review_reply_status |
| The commission percentage this shop pays | finance | Admin | routes/admin/routes.php:504,507 — POST admin/vendors/sales-commission-update/{id}, update_setting/{id}; app/Http/Controllers/Admin/Vendor/VendorController.php:314-324,593-603; setting key sales_commission |
| Warehouses — where the shop's stock physically sits, and moving it between locations | inventory | Admin | routes/rest_api/v3/seller.php:659 — GET seller-center/inventory/warehouses (read only); writes at routes/admin/routes.php:732-740 — admin/marketplace/warehouses (store, update, place, transfer) |
| Batches and expiry dates on stock, and writing off expired units | inventory | Admin | routes/rest_api/v3/seller.php:660 — GET seller-center/inventory/batches (read only); writes at routes/admin/routes.php:722-728 — admin/marketplace/batches store, write-off |
| Shipping methods and their rates for this shop | shipping | Seller | routes/vendor/routes.php:361-370 — vendor/business-settings/shipping-method/*; routes/rest_api/v3/seller.php:286-299 — api/v3/seller/shipping-method/* (seller_can:shop_settings.manage) |
| Legacy balance withdrawal request against the seller wallet | finance | Seller | routes/vendor/routes.php:380-388 — vendor/business-settings/withdraw/*, and dashboard withdraw-request at routes/vendor/routes.php:112-114; routes/rest_api/v3/seller.php:120-124 — balance-withdraw (seller_can:payouts.request) |
| Ledger payout — request the withdrawable balance, which reserves it, and cancel a pending request | finance | Seller | routes/vendor/routes.php:393-399 — vendor/business-settings/payouts; routes/rest_api/v3/seller.php:620-626 — seller-center/payouts (finance.view to read, payouts.request to move); app/Services/Marketplace/PayoutService.php |
| Settlements — the marketplace calculating what it owes each seller and releasing it | finance | Admin | routes/admin/routes.php:551-561 — admin/marketplace/settlements, ledger/{sellerId}; app/Services/Marketplace/SettlementEngine.php; command marketplace:settle --release (bootstrap/app.php:147) |
| Advertising and marketplace campaigns a seller could join | integrations | Admin | Admin-only campaigns at routes/admin/routes.php:1445-1449 and routes/admin/routes.php:220-221; the seller nav entry seller.advertising.index at app/Services/SellerCenter/Navigation.php:152 is unrouted |
| Other shop setup — minimum order amount, free delivery threshold, business TIN and its expiry | platform | Seller | routes/vendor/routes.php:341,343 — POST vendor/shop/update-other-settings, GET other-setup; app/Http/Controllers/RestAPI/v3/seller/SellerController.php:280-300; app/Http/Controllers/Vendor/ShopController.php:153-179 updateOtherSettings writing sellers.minimum_order_amount / stock_limit / free_delivery_over_amount via app/Services/VendorService.php:67 (route vendor.shop.update-other-settings, routes/vendor/routes.php:338); gated by admin flags `minimum_order_amount_by_seller` and `free_delivery_responsibility` |
| Whether sellers can register at all, and how a seller resets their password | platform | Admin | app/Http/Controllers/Admin/Settings/VendorSettingsController.php:49,53 — setting keys seller_registration, vendor_forgot_password_method; POST admin/business-settings/vendor-settings/update-vendor-settings (routes/admin/routes.php:1315) |
| Seller operations oversight for the marketplace — rules, keys, webhooks, team and bulk jobs across every shop, with three interventions | monitoring | Admin | routes/admin/routes.php:641-654 — admin/marketplace/seller-operations (+ suspend-rule, release-rule, revoke-key, disable-webhook); app/Services/Marketplace/SellerOperationsOverview.php |
| Catalogue of the 34 named things a shopper can do that the platform counts | analytics | System | app/Services/Analytics/AnalyticsEvent.php:16-114 (constants + CATEGORIES map, 34 names in 11 families); read back by AnalyticsEvent::names():184 |
| Knowing one visitor from another and stitching their requests into visits | analytics | System | app/Services/Analytics/VisitorContext.php:59 resolve, :80 visitorId (k_vid cookie or ClientIdentity for API), :127 sessionId; config/analytics.php:32 session_gap_minutes=30, :36 engaged_after_seconds=10 |
| Deciding which source, medium and campaign a visit is credited to | analytics | Admin | app/Services/Analytics/Support/AttributionEngine.php:80 (attribution_basis incl. 'campaign_link'); stored on analytics_sessions and rolled up as the source/medium/campaign/attribution_basis dimensions (AnalyticsRollup.php:34-46) |
| Keeping crawlers and the shop's own staff out of every reported figure, while still showing how much was excluded | analytics | Admin | app/Services/Analytics/Support/BotDetector.php:124-145 isInternal (any logged-in admin or seller, plus ANALYTICS_INTERNAL_IPS); AnalyticsRollup.php:130 rollupTraffic writes the excluded_traffic dimension; AnalyticsReporting.php:324 excludedTraffic |
| Honouring Do Not Track / Global Privacy Control and cookie consent before anything is recorded | compliance | Admin | app/Services/Analytics/Support/PrivacyGate.php:26-62; enforced in RecordAnalytics.php:38,50 and again in EventRecorder.php:71; settings ANALYTICS_RESPECT_DNT / ANALYTICS_REQUIRE_CONSENT (config/analytics.php:110-111) |
| Compressing a day of behaviour into the rollups every chart older than today reads | analytics | System | app/Console/Commands/AnalyticsRollup.php:26 (command analytics:rollup), rollupDay:79 writing 24 dimensions; scheduled bootstrap/app.php:228 hourlyAt(12) and :229 nightly --days=2 --prune; app/Console/Commands/AnalyticsRollup.php:26 `analytics:rollup`; scheduled bootstrap/app.php:228 hourly at :12 and bootstrap/app.php:229 `--days=2 --prune` daily 02:15 |
| Deleting raw events, visits and clicks once they are past their retention window | compliance | Admin | app/Console/Commands/AnalyticsRollup.php:498 prune() and :511 deleteInChunks; config/analytics.php:56-60 retention.event_days=90 / session_days=400 / daily_days=1100 / click_days=400 |
| Analytics grading itself, so a stopped pipeline reads as broken instead of as a quiet week | monitoring | Admin | app/Services/Analytics/EventRecorder.php:400 health(); AnalyticsReporting.php:49 collectionHealth (states not_installed / disabled / no_events / rollup_never_ran / rollup_stale / healthy); rendered by admin-views/analytics/sections/quality.blade.php:3 |
| Storefront beacon reporting the few things a page load cannot see (filter navigations, banner taps, sections scrolled into view) | analytics | System | public/assets/front-end/js/analytics-beacon.js:45 (allow-list); app/Http/Controllers/Web/AnalyticsCollectController.php:53; route POST analytics/collect at routes/web/routes.php:116-119, throttled per config('analytics.beacon.rate_limit_per_minute') |
| Building a campaign link (UTM builder), issuing a short link and a printable QR code | analytics | Admin | app/Services/Analytics/CampaignService.php:47 create / :311 qrSvg; admin routes POST admin/analytics/campaign and GET campaign/{id}/qr at routes/admin/routes.php:216-220; redirect route /go/{code} at routes/web/routes.php:129-135 |
| Counting campaign clicks separately for web and app, and crediting revenue from sessions rather than clicks | analytics | Admin | app/Services/Analytics/CampaignService.php:179 recordClick (+surface column) and :232 clicksBySurface; web click at Web/CampaignRedirectController.php:97; app click at RestAPI/v1/DeepLinkController.php:105; lifetime totals recomputed by AnalyticsRollup.php:381 refreshCampaignTotals |
| The Analytics area itself: 17 sections grouped by the question a merchant is asking | analytics | Admin | app/Http/Controllers/Admin/Telemetry/AnalyticsController.php:42 index / :217 dataFor; sections declared in app/Services/Analytics/Reporting/AnalyticsNavigation.php:16-38; routes/admin/routes.php:210-223 under middleware module:reports; menu at resources/views/layouts/admin/partials/v2/_side-bar.blade.php:576 |
| Five separate permissions over analytics: read, export, campaign links, individual journeys, collection settings | security | Admin | app/Services/Analytics/AnalyticsPermissionService.php:246-263 (analytics_view / analytics_export / analytics_campaigns / analytics_journeys / analytics_settings), granted in resources/views/admin-views/custom-role/create.blade.php:111 and edit.blade.php:108; enforced at AnalyticsController.php:58,86,129,160,188 |
| Exporting any analytics breakdown as a CSV a merchant opens in Excel | analytics | Admin | app/Http/Controllers/Admin/Telemetry/AnalyticsController.php:83 export (streamed, UTF-8 BOM, up to 5000 rows); route GET admin/analytics/export/{dimension} at routes/admin/routes.php:214 |
| Following one visitor's trail through their visits, in order | analytics | Admin | app/Services/Analytics/Reporting/AnalyticsReporting.php:516 journey(); admin section 'journeys' (AnalyticsController.php:309 journeyData), permission analytics_journeys |
| Live view of who is on the shop right now and what they are doing | analytics | Admin | app/Services/Analytics/Reporting/AnalyticsReporting.php:555 live() reading analytics_events directly; polled via GET admin/analytics/live-feed (routes/admin/routes.php:213, AnalyticsController.php:184) |
| Weekly retention cohorts — how many visitors come back week after week | analytics | Admin | app/Services/Analytics/Reporting/AnalyticsReporting.php:439 cohorts() over analytics_visitors.first_seen_at; admin section 'retention' (AnalyticsController.php:253) |
| The commerce funnel — visit, product, cart, checkout, payment, order — and where it leaks | orders | Admin | app/Services/Analytics/Reporting/AnalyticsReporting.php:372 funnel() counting distinct sessions per step; admin sections 'overview' and 'funnel' (AnalyticsController.php:226,275) with the gateway breakdown beside it |
| Read-only view of what analytics collects, what it excludes, what it keeps and for how long | platform | Admin | resources/views/admin-views/analytics/sections/settings.blade.php:3-51 rendering config('analytics.*'); section declared in AnalyticsNavigation.php:37 behind analytics_settings |
| How many shoppers actually scrolled to each composed section of the storefront | catalog | Admin | AnalyticsEvent::SECTION_VIEWED (AnalyticsEvent.php:70) rolled up as the theme_section dimension (AnalyticsRollup.php:279); read by app/Services/Theme/SectionReach.php:45 and shown in the theme builder (Admin/Settings/ThemeBuilderController.php:106) and on Analytics > Products & categories (AnalyticsController.php:263) |
| How many distinct shoppers saw each experiment variant | automation | Admin | app/Services/Commerce/ExperimentReach.php:29 visitors() reading analytics_events properties->experiment; consumed by Admin/Commerce/ExperimentController.php:42 |
| Per-product engagement summary (30-day views, cart adds, sales, rating, wishlists) that dynamic collections rank by | catalog | Admin | app/Console/Commands/CommerceMetricsRefresh.php:36 (commerce:metrics-refresh) reading analytics_daily at :128-140, writing product_metrics; scheduled bootstrap/app.php:234 after the analytics rollup; consumed by Admin/Commerce/CollectionController and Commerce/ExperienceHealth.php; app/Console/Commands/CommerceMetricsRefresh.php:29 `commerce:metrics-refresh`; scheduled bootstrap/app.php:234 hourly at :22 (deliberately after analytics:rollup) |
| Orders as a measured quantity (volume, status mix, conversion, revenue per order) | orders | Admin | instrumented once for all callers at app/Utils/OrderManager.php:1642 (order_placed, afterCommit) plus app/Repositories/OrderRepository.php:65,530 and CommerceInstrumentation.php:59 for status changes; rolled into analytics_daily totals (AnalyticsRollup.php:104) and the vendor dimension (:305) |
| Revenue as a measured quantity (trend, average order value, revenue by source and campaign) | finance | Admin | value carried on order_placed (Analytics.php:192) into analytics_daily.revenue; AnalyticsReporting.php:130 totals / :182 trend; admin section 'revenue' at AnalyticsController.php:279-284 |
| Catalogue demand: what products, categories, brands and shops are looked at, and what converts | catalog | Admin | Web/ProductDetailsController.php:55 productViewed, Web/ProductListController.php:516 categoryViewed and :523 brandViewed, Web/ShopViewController.php:87 shopViewed; rolled up per entity at AnalyticsRollup.php:266-316; admin section 'catalogue' AnalyticsController.php:258-264 |
| Search demand, including the terms customers type that return nothing | catalog | Admin | app/Services/Analytics/Analytics.php:86 searchPerformed (splits into search_performed / search_no_results), called only from Web/ProductListController.php:505; rolled up at AnalyticsRollup.php:324; admin section 'search' AnalyticsController.php:265-268 |
| Payouts and settlements as a measured quantity (payout volume, ageing, settlement value over time) | finance | Admin | Row-level only: app/Services/Marketplace/PayoutService.php, SettlementEngine.php and VendorLedger.php with admin screens at routes/admin/routes.php:550-573 and the earnings reports at :810-819; the one analytics event (payout_requested) is internal-flagged and unreportable. |
| Seller performance as a measured quantity (fulfilment, cancellation, return, refund, rating, strikes, health tier) | compliance | Admin | app/Services/Marketplace/SellerScorecardService.php:48 scorecard(); admin at routes/admin/routes.php:655-659 (Admin/Marketplace/SellerScorecardController), seller web at Vendor/Marketplace/SellerScorecardController and Seller/HomeController.php:52, Flutter at lib/utill/app_constants.dart:164 (seller-center/scorecard); enforced into breaches by SlaService.php:148 |
| Automation as a measured quantity (how often rules fire, what they changed, how often they fail) | automation | Seller | app/Services/SellerAutomation/AutomationEngine.php:93 run() writing seller_automation_runs with an outcome (:243, :293-295) and seller_automation_actions; listed by Marketplace/SellerOperationsOverview.php:143 automationActivity and counted at :42; admin at admin/marketplace/seller-operations/automation (routes/admin/routes.php:645), seller at routes/seller/routes.php:99 |
| Seller issues as a measured quantity (open issues, severity mix, which shops are worst) | compliance | Admin | seller_insights written by app/Services/SellerIntelligence/SellerInsightEngine.php (producers under Producers/); counted by Marketplace/SellerOperationsOverview.php:47 and ranked by :162 issuesBySeller / :193 attentionBySeller; admin at seller-operations/issues (routes/admin/routes.php:644) and inside Analytics > Vendors (AnalyticsController.php:273); seller at routes/seller/routes.php:60 and Flutter control tower (app_constants.dart:199-201) |
| Brand verification and the brand registry as a measured quantity (claims, approvals, time to decide) | brands | Admin | app/Services/Marketplace/BrandRegistryService.php with brand_claims/brand_claim_documents (database/migrations/2026_09_13_000001); admin queue at routes/admin/routes.php:624-633; seller badge count at app/Services/SellerCenter/Counts.php:58 brands_pending; findings produced by SellerIntelligence/Producers/BrandComplianceProducer.php:30 |
| Merchandising and advertising as a measured quantity (which banner and which placed section earns the click) | analytics | Admin | AnalyticsEvent::BANNER_CLICKED (:61) and SECTION_VIEWED (:70) from the web beacon (analytics-beacon.js:45) and the app ingest (RestAPI/v1/AnalyticsEventController.php); rolled up as the banner and theme_section dimensions (AnalyticsRollup.php:276-279); named for the merchant on Analytics > Products & categories (AnalyticsController.php:262 withBannerNames / :382 withSectionNames) |
| Accounts as a measured quantity (sign-ups, sign-ins, reviews left, wishlist and compare adds) | analytics | Admin | app/Services/Analytics/CommerceInstrumentation.php:44-55 listening on Order::updated, Wishlist::created, ProductCompare::created, Review::created, User::created and the framework Login event (customers only, :87) |
| The one place anything records who did what — resolves the actor from the admin guard, the seller guard, or the API token's principal, stamps IP and user agent, and never throws into the caller | security | System | app/Services/AuditLogger.php:32 record(); actor resolution at :101; registered as a singleton in app/Providers/AppServiceProvider.php:92; table created by database/migrations/2026_08_09_500001_create_audit_logs_table.php:34; model App/Models/AuditLog.php:13 |
| Admin audit center — the single screen where an operator reads the platform's activity trail, filtered by module, actor type and free-text search | security | Admin | app/Http/Controllers/Admin/Marketplace/AuditLogController.php:23; route routes/admin/routes.php:577 (admin.marketplace.audit-log); view resources/views/admin-views/marketplace/audit-log.blade.php:60; sidebar link resources/views/layouts/admin/partials/v2/_side-bar.blade.php:812 |
| Seller bank / payout account change made from the vendor web panel | finance | Seller | app/Http/Controllers/Vendor/ProfileController.php:118 updateBankInfo() calling app/Services/Marketplace/PayoutService.php:296 recordBankChange(), which audits 'seller.bank_details_changed' at PayoutService.php:312; route routes/vendor/routes.php:330 |
| Approve, reject or revoke a seller's claim to sell under a brand | brands | Admin | app/Services/Marketplace/BrandRegistryService.php:208 'seller.brand_claim_submitted', :306 approved, :324 rejected, :353 revoked; admin surface routes/admin/routes.php:626 admin.marketplace.brand-registry |
| Moderate a product listing with reason codes and history — approve, reject, request changes, suspend, single or bulk | catalog | Admin | app/Services/Marketplace/ProductModerationService.php:120 record('product.' . $action); routes routes/admin/routes.php:594-599 admin.marketplace.product-moderation.* |
| Product price change, from any writer — admin panel, vendor panel, three API versions, bulk importer or an automation rule | pricing | Seller | app/Observers/ProductPriceObserver.php:30 (registered at app/Providers/ObserverServiceProvider.php:40) calling app/Services/Marketplace/PriceChangeRecorder.php:150 'product.price_changed'; the observer fires because app/Repositories/ProductRepository.php:472 update() saves a model instance |
| Reasoned stock adjustment with a locked row, a movement ledger entry and an audit line | inventory | Seller | app/Services/Marketplace/InventoryService.php:59 adjust() → audit 'inventory.stock_adjusted' at :71; admin surface app/Http/Controllers/Admin/Marketplace/InventoryAdjustmentController.php:52 (route routes/admin/routes.php:690); seller API app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:154 (route routes/rest_api/v3/seller.php:662); bulk path app/Services/Marketplace/Bulk/BulkStockOperation.php:77 |
| Suppliers — the vendors the marketplace itself buys from | inventory | Admin | app/Http/Controllers/Admin/Marketplace/SupplierController.php:46 store (audited 'procurement.supplier_created' at :53), :59 update and :68 destroy (both unaudited) |
| Return authorisations — authorise, receive (restocking), reject | returns | Admin | app/Services/Marketplace/ReturnLogisticsService.php:54 'returns.authorized', :140 'returns.received', :161 'returns.rejected'; admin surface routes/admin/routes.php:707 admin.marketplace.returns.* |
| Order fulfilment lifecycle — open a fulfilment, advance it, cancel it | orders | Seller | app/Services/Marketplace/FulfillmentService.php:52 'fulfilment.opened', :92 'fulfilment.advanced', :118 'fulfilment.canceled'; admin surface app/Http/Controllers/Admin/Marketplace/FulfillmentController.php |
| Order status changes — the most frequent consequential action on the platform | orders | Seller | app/Utils/OrderManager.php (zero AuditLogger references) with its own trail via app/Services/OrderStatusHistoryService.php:22 into order_status_histories, read at routes/admin/routes.php:1024 admin.orders.ajax-order-status-history |
| Theme lifecycle — activate, publish, restore a version, add/edit/reorder/delete sections, scheduled publish outcomes | platform | Admin | app/Services/Theme/ThemeManager.php:102 activated, :156 published, :223 restored; app/Services/Theme/ThemeBuilderService.php:57/:79/:157/:266/:341 section events; app/Console/Commands/ThemePublishDue.php:85/:117 scheduled publish failed/cancelled; admin surface routes/admin/routes.php:1483 admin.theme.* |
| Experience pages and merchandising objects — collections, campaigns, segments, experiments | platform | Admin | app/Services/Theme/ExperiencePageService.php:191/:237/:267; app/Http/Controllers/Admin/Commerce/CollectionController.php:97/:161/:192, CampaignController.php:105/:221/:250, SegmentController.php:85/:136/:165, ExperimentController.php:99/:156/:184; routes routes/admin/routes.php:1437 admin.commerce.* |
| Seller notifications and issue escalation raised by the intelligence services | notifications | System | app/Services/SellerIntelligence/SellerNotifier.php:135 'seller.notified'; app/Services/SellerIntelligence/IssueEscalationService.php:156 'seller.issue_escalated' |
| Generated OpenAPI 3.1 specification, downloadable as JSON or YAML | integrations | Developer | app/Services/DeveloperPortal/Generators/OpenApiGenerator.php:64 generate(); GET admin/developer/download/openapi (routes/admin/routes.php:189) via app/Http/Controllers/Admin/Telemetry/DeveloperPortalController.php:102 |
| Console safety guard: which endpoints may be fired at all, which need a typed confirmation, and which are never sent | security | Admin | app/Services/DeveloperPortal/ConsoleGuard.php:66 verdict() / :46 NEVER_READ / :56 NEVER_WRITE; writes gated by DEVELOPER_CONSOLE_ALLOW_WRITES (config/developer_portal.php:47) |
| API surface snapshots and the generated changelog of added, removed and breaking changes | platform | Admin | app/Services/DeveloperPortal/ApiSnapshotService.php:34 capture() / :159 captureAndRecord() / :210 changelog(); tables api_snapshots and api_changes (database/migrations/2026_08_26_000001_create_developer_portal_tables.php:24 and :48); POST admin/developer/snapshot (routes/admin/routes.php:200) |
| Documentation quality score — what is undocumented, unclassified or missing a schema, grouped by reason | platform | Admin | app/Services/Telemetry/DeveloperPortalService.php:289 quality() / :383 qualityScore(); gaps enumerated by app/Services/DeveloperPortal/Generators/OpenApiGenerator.php:117 warnings(); rendered at resources/views/admin-views/telemetry/developer/quality.blade.php |
| Portal navigation: 25 sections grouped into Getting started, Reference, Conventions, Change management, Tools and Operations | platform | Admin | app/Services/DeveloperPortal/PortalNavigation.php:22 SECTIONS / :95 grouped(); capabilities probed at app/Services/Telemetry/DeveloperPortalService.php:46; rail rendered at resources/views/admin-views/telemetry/developer.blade.php:74 |
| Portal section: API Explorer — filter every endpoint by audience, version, group, method, visibility and auth | platform | Admin | app/Services/Telemetry/DeveloperPortalService.php:118 explorer(); filters at app/Http/Controllers/Admin/Telemetry/DeveloperPortalController.php:267; rendered at resources/views/admin-views/telemetry/developer/explorer.blade.php |
| Portal section: Authentication — the token types this API issues and what each one opens, with usage counts | security | Developer | app/Services/Telemetry/DeveloperPortalService.php:259 / :472 authenticationUsage(); rendered at resources/views/admin-views/telemetry/developer/authentication.blade.php |
| Portal section: Errors — the error envelope and what each HTTP status means on this API | platform | Developer | app/Services/Telemetry/DeveloperPortalService.php:267 (envelope) / :493 errorCatalogue(); rendered at resources/views/admin-views/telemetry/developer/errors.blade.php |
| Portal section: Rate limits — the throttles actually configured on the routes, tightest first | platform | Developer | app/Services/Telemetry/DeveloperPortalService.php:508 rateLimits(); read per route by app/Services/DeveloperPortal/Support/AuthResolver.php:214 rateLimit(); rendered at resources/views/admin-views/telemetry/developer/rate_limits.blade.php |
| Portal section: Pagination and File uploads — the conventions, plus the endpoints that use each | platform | Developer | app/Services/Telemetry/DeveloperPortalService.php:273 / :531 paginatedEndpoints() / :551 uploadEndpoints(); rendered at resources/views/admin-views/telemetry/developer/pagination.blade.php and uploads.blade.php |
| Portal section: Versions — endpoints per version, audiences per version, and 30-day traffic to decide what can be retired | platform | Admin | app/Services/Telemetry/DeveloperPortalService.php:340 versions() / :567 versionTraffic(); rendered at resources/views/admin-views/telemetry/developer/versions.blade.php |
| Portal section: Deprecations — what is going away, when, and what replaces it | platform | Admin | app/Services/Telemetry/DeveloperPortalService.php:364 deprecations(); rendered at resources/views/admin-views/telemetry/developer/deprecations.blade.php; declared only by ApiDoc(stability: DEPRECATED) at app/Services/DeveloperPortal/ApiDoc.php:53 |
| Portal section: Changelog — API changes generated from snapshot diffs rather than written by hand | platform | Admin | app/Services/Telemetry/DeveloperPortalService.php:326 changelog(); rendered at resources/views/admin-views/telemetry/developer/changelog.blade.php; rows come from api_changes (database/migrations/2026_08_26_000001_create_developer_portal_tables.php:48) |
| Who may read the API documentation | security | Admin | routes/admin/routes.php:182 — the whole portal sits behind ->middleware('module:system_settings') on the admin panel; sidebar entry at resources/views/layouts/admin/partials/v2/_side-bar.blade.php:879 |
| Visibility policy on generated artefacts (who an OpenAPI or Postman download may be handed to) | security | Admin | Visibility is computed per endpoint (app/Services/DeveloperPortal/Support/EndpointClassifier.php:107) and is an available filter (app/Http/Controllers/Admin/Telemetry/DeveloperPortalController.php:275), but neither generator applies it by default (OpenApiGenerator.php:66, PostmanGenerator.php) |
| Seller earnings are held pending until the return window closes before they can be settled or paid | finance | Admin | app/Utils/OrderManager.php:1107 ($availableAt = now()->addDays($returnWindowDays)) via app/Services/Marketplace/CategoryGovernanceService.php:26 (setting 'refund_day_limit' + per-category override); released by app/Services/Marketplace/VendorLedger.php:186 releaseMatured() |
| Global and per-seller reorder level that drives the low-stock and restock screens | inventory | Admin | setting 'stock_limit' written at app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:438 and resources/views/admin-views/business-settings/product-settings.blade.php:197; per-seller override at app/Services/Marketplace/InventoryService.php:138; seller edits it at app/Http/Controllers/Vendor/ShopController.php:161 |
| KYC verification can be made a hard requirement before a seller may withdraw money | compliance | Admin | app/Services/Marketplace/SellerVerificationService.php:154 (setting 'require_kyc_for_payout'), default document types at :28 (DEFAULT_REQUIRED_DOCUMENTS); admin form at resources/views/admin-views/marketplace/seller-verification.blade.php:23 and app/Http/Controllers/Admin/Marketplace/SellerVerificationController.php:133; gate applied at app/Services/Marketplace/PayoutService.php:63 |
| Brand claim enforcement: whether listings under an unclaimed brand are blocked or merely reported | brands | Admin | app/Services/Marketplace/BrandRegistryService.php:39 (const ENFORCEMENT_SETTING = 'brand_claim_enforcement'); consumed at app/Services/SellerIntelligence/Producers/BrandComplianceProducer.php:50 to pick severity critical vs high (:60) |
| Return window in days, globally and per category, deciding how long a customer may ask for a refund | returns | Admin | app/Services/Marketplace/CategoryGovernanceService.php:26 (setting 'refund_day_limit') with per-category override at :32; admin form resources/views/admin-views/business-settings/refund-setup.blade.php:47 and app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:488; category page app/Http/Controllers/Admin/Marketplace/CategoryGovernanceController.php:41 |
| Abandoned-cart reminders — on/off, guests included, idle window, stop-reminding age and minimum cart value | automation | Admin | app/Http/Controllers/Admin/Settings/AbandonedCartSettingsController.php:58-71 writes the five keys declared at app/Services/Retention/AbandonedCartService.php:47-51; read back at AbandonedCartService.php:232; driven by `cart:remind-abandoned` (bootstrap/app.php:140,:151); app/Services/Retention/AbandonedCartService.php:47-51 (settings abandoned_cart_reminder_status / _include_guests / _idle_hours / _max_age_hours / _minimum_cart_value), defaults at :53 (6h) and :54 (168h); admin page resources/views/admin-views/business-settings/abandoned-cart.blade.php and app/Http/Controllers/Admin/Settings/AbandonedCartSettingsController.php |
| Scheduled task run ledger — one row per task per run with duration, exit status and error | monitoring | System | app/Providers/MonitoringServiceProvider.php:71-118 (ScheduledTaskStarting/Finished/Failed/Skipped) → monitoring_scheduled_runs (database/migrations/2026_08_24_000002_create_monitoring_operations_tables.php:55); writes table monitoring_scheduled_runs on the `monitoring` connection (config/monitoring.php:26) |
| Commerce analytics instrumentation hung off model events (orders, wishlists, compares, reviews, sign-ups, logins) | analytics | System | app/Providers/AnalyticsServiceProvider.php:35 -> app/Services/Analytics/CommerceInstrumentation.php:37-55 (Order::updated, Wishlist::created, ProductCompare::created, Review::created, User::created, Event::listen(Login)) |
| Email customers who left items in their cart | automation | Admin | app/Console/Commands/SendAbandonedCartReminders.php:31 `cart:remind-abandoned`; scheduled bootstrap/app.php:140 (every 30 min, stage 1) and bootstrap/app.php:151 (daily 10:00, stage 2) |
| Mature seller earnings out of the return window and calculate vendor settlements | finance | Admin | app/Console/Commands/RunVendorSettlements.php:25 `marketplace:settle --release`; scheduled bootstrap/app.php:147 daily 02:00 |
| Evaluate every seller against SLA thresholds and reconcile the breach ledger | compliance | Admin | app/Console/Commands/EvaluateSellerSla.php:19 `marketplace:evaluate-sla`; scheduled bootstrap/app.php:155 daily 03:00 |
| Publish theme versions a merchant scheduled for a future date/time | platform | Admin | app/Console/Commands/ThemePublishDue.php:31 `theme:publish-due`; scheduled bootstrap/app.php:165 every 5 min |
| Raise the severity of seller issues nobody has answered | compliance | System | app/Console/Commands/EscalateSellerIssues.php:18 `seller:escalate-issues`; scheduled bootstrap/app.php:170 every 4 hours |
| Move storefront campaigns through their lifecycle and flush the delivery cache at the transition | automation | Admin | app/Console/Commands/CommerceCampaignsTick.php:20 `commerce:campaigns-tick`; scheduled bootstrap/app.php:238 every 5 min |
| Republish the Android/iOS deep-link association files from the stored app setup | integrations | Admin | app/Console/Commands/DeepLinkPublish.php:21 `deeplinks:publish`; not scheduled; the files are otherwise only rewritten when an admin presses save on the deep-link form |
| Fix file permissions on sitemap.xml and robots.txt after deployment | platform | Admin | app/Console/Commands/FilePermissionProcess.php:15 `file:permission`; invoked from app/Http/Controllers/Admin/Settings/SiteMapController.php:113, app/Http/Controllers/InstallController.php:66 and app/Traits/UpdateClass.php:796 |
| Record every product price change, whoever made it and from which surface | pricing | System | app/Observers/ProductPriceObserver.php:21 (watches unit_price, discount, discount_type); registered app/Providers/ObserverServiceProvider.php:38-43 |
| Tell customers the shop has gone into maintenance mode | notifications | Admin | app/Listeners/MaintenanceModeNotificationListener.php:18, handle(MaintenanceModeNotificationEvent) at line 33; queued |
| Operations console — one admin area with 33 sections covering the server, the store and both apps | monitoring | Admin | app/Http/Controllers/Admin/Telemetry/MonitoringController.php:42 (index); routes/admin/routes.php:227-237 route group admin.monitoring.* with middleware module:system_settings; every section on GET /admin/monitoring/{section} |
| Health score — one 0-100 number for 'is the shop alright', with the count of signals it could actually measure | monitoring | Admin | app/Services/Monitoring/HealthScoreService.php:45 (evaluate), :118 (12 weighted signals); rendered by OverviewPanel and the shell header |
| Pulse strip — the status/score/staleness header the console polls every few seconds | monitoring | Admin | app/Services/Monitoring/Panels/PanelRegistry.php:112 (pulse); route GET /admin/monitoring/pulse (routes/admin/routes.php:232) |
| Monitoring watching itself — whether the collector has stopped, which buffer is in use, and how much disk monitoring costs | monitoring | Admin | app/Services/Monitoring/Panels/PanelRegistry.php:136 (selfHealth), :171 (storageFootprint); config/monitoring.php:206 stale_after_seconds |
| Fine-grained monitoring permissions — six capabilities so not every admin sees logs, security and server internals | security | Admin | app/Services/Monitoring/MonitoringPermissionService.php:26-44 (VIEW/LOGS/SECURITY/INFRASTRUCTURE/ERRORS/SETTINGS), :131 (capabilityForTab), :58 (implied by module) |
| Section map — one declaration of which sections exist, their group, hint and required capability | monitoring | Admin | app/Services/Monitoring/MonitoringNavigation.php:22 (sections), :99 (visibleGroups), :116 ('built' flag from PanelRegistry::has) |
| Sampled distributed tracing — full span trees for slow and failed requests | monitoring | Developer | app/Services/Monitoring/Ingest/TraceRecorder.php:21, persisted from RequestRecorder.php:156; config/monitoring.php:92-99 sample_rate 0.02, always_trace_slower_than_ms 1500, always_trace_errors |
| Slow query capture — queries past the threshold, fingerprinted and attributed to a route | monitoring | Developer | app/Services/Monitoring/Ingest/SlowQueryRecorder.php:21 via DB::listen in RequestRecorder.php:44-49; config/monitoring.php:98 MONITORING_SLOW_QUERY_MS default 200 → monitoring_slow_queries |
| Outbound dependency call recording — every call made through Laravel's HTTP client, with latency, failures, timeouts and last error | integrations | System | app/Services/Monitoring/Ingest/DependencyRecorder.php:38 (:89 record, :79 announceTransport); registered as Http::globalMiddleware in app/Providers/MonitoringServiceProvider.php:185-217 → monitoring_dependency_buckets via BucketWriter.php:300 |
| Real-user web performance ingest — LCP, INP, CLS and TTFB from the storefront beacon | monitoring | System | public/assets/front-end/js/analytics-beacon.js:104 → app/Http/Controllers/Web/AnalyticsCollectController.php:80 → app/Services/Monitoring/Ingest/WebVitalsRecorder.php:30 → monitoring_series web.vitals.* |
| Host and runtime gauge collection — 14 collectors for CPU, memory, disk, network, PHP, DB, Redis, queue, scheduler, storage, hardware, energy, SSL and web server | monitoring | System | app/Services/Monitoring/Collectors/CollectorRegistry.php:14 (:21-36 the list); sampled once a minute by MonitoringFlush.php:57 |
| Queue backlog measurement — pending depth, oldest waiting job, stuck reserved jobs, worker processes and the failed_jobs table | monitoring | Admin | app/Services/Monitoring/Collectors/QueueCollector.php:46; rendered by QueuesPanel.php:33 (failures list at QueuesPanel.php failures()) |
| Scheduler definition collection — the Laravel schedule, which task is late, missed or failed, and whether cron is installed at all | monitoring | Admin | app/Services/Monitoring/Collectors/SchedulerCollector.php:25; rendered by SchedulerPanel.php:32 |
| Web server and PHP-FPM pool measurement — connections, worker pools, listen queue | monitoring | Admin | app/Services/Monitoring/Collectors/WebServerCollector.php:39 rendered by WebServerPanel.php:32; config/monitoring.php:147-148 nginx_status_url / php_fpm_status_url; app/Services/Monitoring/Panels/WebServerPanel.php:32 reading WebServerCollector.php:39; GET /admin/monitoring/webserver |
| Energy, temperature and power-draw measurement with an electricity cost estimate | monitoring | Admin | app/Services/Monitoring/Collectors/EnergyCollector.php:38 and HardwareCollector.php:24 rendered by EnergyPanel.php:40; config/monitoring.php:189-196 (estimated_mode, price_per_kwh); app/Services/Monitoring/Panels/EnergyPanel.php:40 reading monitoring_series energy.watts (:70,:627); GET /admin/monitoring/energy |
| Incident correlation — many firing rules inside 30 minutes become one incident with a timeline | monitoring | Admin | app/Services/Monitoring/Alerting/IncidentManager.php:18 (:46 attach, :29 CORRELATION_WINDOW_MINUTES 30, :67 releaseIfResolved) → monitoring_incidents (migration ops:131) |
| Event timeline write path — one place that records deploys, scheduler failures, backups, incidents, alerts, config changes, check transitions and human notes | monitoring | System | app/Services/Monitoring/EventLog.php:26 (:46 the eight types, :63 record) → monitoring_events; producers at MonitoringServiceProvider.php:104, CheckRunner.php:190, IncidentManager.php:94,:132, AlertEvaluator.php:246,:298, MonitoringSynthetic.php:108,:143, MonitoringDeployRecorded.php:73, MonitoringBackupRecorded.php:75, MonitoringRestoreTested.php:55, MonitoringAnnotate.php:49 |
| Overview — system status, the health score's twelve signals, service cards, traffic comparison and what needs attention | monitoring | Admin | app/Services/Monitoring/Panels/OverviewPanel.php:25; GET /admin/monitoring or /admin/monitoring/overview; view resources/views/admin-views/monitoring/sections/overview.blade.php |
| Live traffic — who is on the site right now and what they are hitting | monitoring | Admin | app/Services/Monitoring/Panels/LiveTrafficPanel.php:27 reading telemetry_requests, visit_sessions and monitoring_request_buckets; GET /admin/monitoring/live |
| Incidents — open problems, their signals, severity, MTTD and MTTR | monitoring | Admin | app/Services/Monitoring/Panels/IncidentsPanel.php:34 reading monitoring_incidents, monitoring_alert_states, monitoring_alert_rules, monitoring_events; GET /admin/monitoring/incidents |
| Timeline — deploys, alerts, incidents, backups, check transitions and scheduler failures on one axis | monitoring | Admin | app/Services/Monitoring/Panels/TimelinePanel.php:33 reading monitoring_events plus monitoring_deployments and monitoring_incidents; GET /admin/monitoring/timeline |
| Application — runtime versions, OPcache, route/config caching and the settings that affect speed | monitoring | Admin | app/Services/Monitoring/Panels/ApplicationPanel.php:38 reading the PHP runtime collector and config; GET /admin/monitoring/application |
| Requests — per-route percentiles, the slowest endpoints and the most-failing endpoints | monitoring | Admin | app/Services/Monitoring/Panels/RequestsPanel.php:26 reading monitoring_request_buckets, split by channel web/api/admin/vendor (:36); GET /admin/monitoring/requests |
| Traces — where one slow request's time actually went, as a span waterfall | monitoring | Developer | app/Services/Monitoring/Panels/TracesPanel.php:32 reading monitoring_traces + monitoring_spans; GET /admin/monitoring/traces |
| Logs — the tail of laravel.log, searchable and pivotable by correlation id | monitoring | Admin | app/Services/Monitoring/Panels/LogsPanel.php:38 reading storage/logs/laravel.log backwards, capped at 2MB / 200 entries; GET /admin/monitoring/logs, gated on monitoring_logs (MonitoringPermissionService.php:135) |
| Database — connections, throughput, locks, slow queries and table sizes | monitoring | Admin | app/Services/Monitoring/Panels/DatabasePanel.php:31 reading the DB collector, monitoring_slow_queries (:517,:575) and monitoring_request_buckets; GET /admin/monitoring/database |
| Redis and cache — memory, hit ratio, evictions and what actually uses Redis | monitoring | Admin | app/Services/Monitoring/Panels/RedisPanel.php:31 reading RedisCollector.php:42; GET /admin/monitoring/redis |
| Queues — pending work, oldest waiting job, worker verdict, throughput and recent failed jobs | monitoring | Admin | app/Services/Monitoring/Panels/QueuesPanel.php:33 reading the queue collector for depth/age and monitoring_series (queue.processed, queue.failed) for throughput (:411-422); GET /admin/monitoring/queues |
| Scheduler — every scheduled task with its last run, outcome, duration and next due time | monitoring | Admin | app/Services/Monitoring/Panels/SchedulerPanel.php:32 reading the schedule definition, monitoring_scheduled_runs (:443,:570) and a success rate over the window; GET /admin/monitoring/scheduler |
| Server — CPU, memory, processes and pressure | monitoring | Admin | app/Services/Monitoring/Panels/ServerPanel.php:33 reading CpuCollector.php:24 and MemoryCollector.php:30; GET /admin/monitoring/server |
| Network — bandwidth, packets, TCP state and DNS | monitoring | Admin | app/Services/Monitoring/Panels/NetworkPanel.php:39 reading NetworkCollector.php:33 and monitoring_series (:452); GET /admin/monitoring/network |
| Storage — disks, inodes, IO and the application's own storage directory | monitoring | Admin | app/Services/Monitoring/Panels/StoragePanel.php:32 reading DiskCollector.php:38 and StorageCollector.php:39; GET /admin/monitoring/storage |
| Web performance — what real shoppers experience (LCP, INP, CLS, TTFB) per page | monitoring | Admin | app/Services/Monitoring/Panels/WebVitalsPanel.php:39 reading monitoring_series web.vitals.*; GET /admin/monitoring/web-vitals |
| Android — traffic, latency, version mix and self-reported crash-free sessions | monitoring | Admin | app/Services/Monitoring/Panels/AndroidPanel.php:32 extending MobileAppPanel.php:47, reading monitoring_series requests.by_platform / app.health.*; GET /admin/monitoring/android; app/Services/Monitoring/Panels/IosPanel.php:33 extending MobileAppPanel.php:47; GET /admin/monitoring/ios |
| Payments — attempts, success rate, settlement reconciliation and money that reconciles nowhere | finance | Admin | app/Services/Monitoring/Panels/PaymentsPanel.php:43 reading orders, payment_requests, offline_payments, order_transactions, order_item_commissions, refund_transactions, paytabs_invoices and analytics_events; GET /admin/monitoring/payments |
| Order integrity — paid orders with no items, totals that disagree with their lines, duplicate submissions and orders stuck in a status | orders | Admin | app/Services/Monitoring/Panels/OrderIntegrityPanel.php:48 deriving from orders (:317,:380), order_details (:460) and order_status_histories (:958); threshold monitoring_settings thresholds.stuck_order_hours (:226, default config/monitoring.php:177 = 6); GET /admin/monitoring/orders |
| Inventory integrity — negative stock, double deductions, ledger drift and stuck reservations | inventory | Admin | app/Services/Monitoring/Panels/InventoryIntegrityPanel.php:47 deriving from products (:327,:389), stock_movements (:626), order_details (:1632), warehouse_stock (:1401) and product_batches (:1504); GET /admin/monitoring/inventory |
| Integrations — every outbound service this shop calls, with volume, failure rate, latency and when it last succeeded | integrations | Admin | app/Services/Monitoring/Panels/IntegrationsPanel.php:46 reading monitoring_dependency_buckets (:48), monitoring_check_results (:50) and the hand-maintained catalogue of every outbound seam (:115); GET /admin/monitoring/integrations |
| Security — refused requests by status, admin activity from the audit log, and suspicious sources | security | Admin | app/Services/Monitoring/Panels/SecurityPanel.php:43 reading telemetry_requests (:260,:340) and audit_logs (:745,:811,:912); GET /admin/monitoring/security, gated on monitoring_security |
| Deployments — which build started running when, with migrations run and errors before and after | platform | Developer | app/Services/Monitoring/Panels/DeploymentsPanel.php:39 reading monitoring_deployments (:67,:198); written only by app/Console/Commands/MonitoringDeployRecorded.php:28; GET /admin/monitoring/deployments |
| Backups — age, size trend, outcome and when a restore was last tested | platform | Admin | app/Services/Monitoring/Panels/BackupsPanel.php:41 reading monitoring_backups (:44) and monitoring_check_results (:47); written only by MonitoringBackupRecorded.php:29 and MonitoringRestoreTested.php:20; GET /admin/monitoring/backups |
| Synthetic tests — scripted journeys that run whether or not anyone is shopping | monitoring | Admin | app/Services/Monitoring/Panels/SyntheticsPanel.php:47 reading monitoring_settings.synthetics (:157), monitoring_check_results kind=synthetic (:159) and the check.up/check.duration_ms series (:161); GET /admin/monitoring/synthetics |
| SLA and uptime — availability per service with error budget, MTTD and MTTR | monitoring | Admin | app/Services/Monitoring/Panels/SlaPanel.php:45 deriving from the check.up series (:50), monitoring_check_results (:52), monitoring_incidents (:54), request buckets (:56) and dependency buckets (:58); targets from monitoring_settings sla.targets (:929); GET /admin/monitoring/sla |
| Alerts — every rule, its thresholds, what is currently firing, and whether the engine is still awake | monitoring | Admin | app/Services/Monitoring/Panels/AlertsPanel.php:31 reading monitoring_alert_rules (:42), monitoring_alert_states (:44) and monitoring_events (:46); GET /admin/monitoring/alerts, gated on monitoring_settings |
| Monitoring settings — thresholds, retention, sampling, privacy, energy price and integration endpoints, each with its origin | monitoring | Admin | app/Services/Monitoring/Panels/SettingsPanel.php:40 reading config/monitoring.php and monitoring_settings (:826); GET /admin/monitoring/settings, gated on monitoring_settings |
| Which storefront URLs open the mobile app instead of the browser (universal / app links) | integrations | Admin | config/deeplinks.php:26-54 `enabled_routes`, `android_paths`, `ios_paths`; ios_paths is written verbatim into public/.well-known/apple-app-site-association by app/Services/DeepLink/AssociationFileWriter; admin view at routes/admin/routes.php:1113 app-deep-link |
| Module publish/enable state for Blog, TaxModule, AI and Auction | platform | Admin | modules_statuses.json plus Modules/{name}/Addon/info.php `is_published`, read by getCheckAddonPublishedStatus() at app/Utils/module-helper.php:583; 40 call sites for Auction, 7 for TaxModule, 1 for AI; admin screens at routes/admin/routes.php:1081 (addon) and :1091 (addon-activation) |
| Application mode — demo/dev/live, which decides whether OTPs are random or the fixed test codes 1234/123456 and whether settings forms save at all | security | Admin | APP_MODE env, written by app/Http/Controllers/Admin/Settings/EnvironmentSettingsController.php:40; read at app/Services/Web/CustomerAuthService.php:15, app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:241,:293,:857, PhoneVerificationController.php:38,:80, v3/seller/auth/ForgotPasswordController.php:80, and app/Utils/settings.php:402-419 for the demo form lock |
| Force HTTPS on all generated URLs | security | Admin | FORCE_HTTPS env, written by app/Http/Controllers/Admin/Settings/EnvironmentSettingsController.php:56 (POST admin/system-setup/environment-update-force-https); read at app/Providers/AppServiceProvider.php:161 and app/Providers/SocialLoginServiceProvider.php:53 |
| Maintenance mode — taking the storefront, admin panel or any of the three apps offline with a message and a window | platform | Admin | business_settings `maintenance_mode`, `maintenance_system_setup`, `maintenance_duration_setup`, `maintenance_message_setup` written at app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:248-251 (route admin.business-settings.maintenance-mode, routes/admin/routes.php:1303); enforced by app/Http/Middleware/MaintenanceModeMiddleware.php:24 via app/Traits/MaintenanceModeTrait.php:10; published to apps at ConfigController.php:104-109 |
| Store identity — company name, email, phone, address, country code, registration/VAT/platform numbers, pagination size, timezone, currency symbol position, decimal places, business mode, copyright and cookie banner | platform | Admin | app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:135-165 writes 18 business_settings keys; route admin.business-settings.web-config.index (routes/admin/routes.php:1295) |
| Catalogue rules — low-stock limit, whether brands exist, whether digital products are sold, whether new seller products need approval, and whether seller shipping costs need approval | catalog | Admin | app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:438-442 writes `stock_limit`, `product_brand`, `digital_product`, `new_product_approval`, `product_wise_shipping_cost_approval`; view at :428; enforced at app/Services/ProductService.php:632,:864 and app/Services/Marketplace/InventoryService.php:140 |
| Product page trust signals — live viewer counter range, authenticity badge and its text, frequently-bought-together block and its size | catalog | Admin | app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:448-455 writes `product_live_viewers_status`/`_min`/`_max`, `product_authenticity_badge_status`/`_text`, `bought_together_status`/`_limit`/`_auto_fill`; consumed by app/Services/Storefront/ProductPageSignalsService.php:25,:92 and app/Services/Storefront/BoughtTogetherService.php:30; UI at resources/views/admin-views/business-settings/product-settings.blade.php:219-320 |
| Checkout rules — billing address capture, minimum order amount enforcement, order verification code, free delivery and who pays for it, guest checkout | orders | Admin | app/Http/Controllers/Admin/Settings/OrderSettingsController.php:35-41 writes `billing_input_by_customer`, `minimum_order_amount_status`, `order_verification`, `free_delivery_status`, `free_delivery_responsibility`, `guest_checkout`, `free_delivery_over_amount_seller`; enforced in app/Utils/OrderManager.php:2199-2305 |
| In-house shop order setup — the platform's own minimum order amount and free-delivery threshold, plus its banners, temporary close and vacation window | orders | Admin | app/Http/Controllers/Admin/Settings/InhouseShopController.php:173-190 writes `minimum_order_amount` and `free_delivery_over_amount`; :105-124 banners; :138 `temporary_close`; :157 `vacation_add`; routes/admin/routes.php:1406-1412 |
| Invoice content and layout | finance | Admin | app/Http/Controllers/Admin/Settings/InvoiceSettingsController.php:34,:60 write business_settings `invoice_settings`; route admin.business-settings.invoice-settings.index (routes/admin/routes.php:1393) |
| Homepage and listing ordering — the priority/sort rules for products, categories, brands, vendors, blogs and clearance sections | catalog | Admin | app/Http/Controllers/Admin/Settings/PrioritySetupController.php (routes/admin/routes.php:1416-1420 index/update/update-by-type) writing the *_list_priority business_settings keys; clearance-specific writes at app/Http/Controllers/Admin/Promotion/ClearanceSalePrioritySetupController.php:51-55 |
| Delivery staff rules — forgot-password channel and whether a delivery photo is required | shipping | Admin | app/Http/Controllers/Admin/Settings/DeliverymanSettingsController.php:35 `deliveryman_forgot_password_method`, :42 `upload_picture_on_delivery`; routes/admin/routes.php:1326-1331 |
| Customer programme settings — wallet, loyalty points and their rates, referral earning, add-funds limits | finance | Admin | app/Http/Controllers/Admin/Customer/CustomerController.php:487-501 writes 13 business_settings keys (wallet_status, loyalty_point_*, ref_earning_*, add_funds_to_wallet, min/max_add_fund_amount, active_auction_for_customer); route admin.business-settings.customer-settings (routes/admin/routes.php:1335) |
| Marketplace-wide vendor rules — seller POS, seller self-registration, per-seller minimum order, review replies, whether vendors may edit orders, vendor forgot-password channel | platform | Admin | app/Http/Controllers/Admin/Settings/VendorSettingsController.php:48-54; route admin.business-settings.vendor-settings.index (routes/admin/routes.php:1312) |
| Default platform commission percentage on every sale | finance | Admin | business_settings `sales_commission` written at app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:160 and defaulted at VendorSettingsController.php:31; read at app/Services/Marketplace/CommissionEngine.php:168 and app/Utils/Helpers.php:641, after a per-seller override from sellers.sales_commission_percentage (CommissionEngine.php:162) |
| Seller KYC policy — which documents are required and whether payouts are blocked until verified | compliance | Admin | app/Http/Controllers/Admin/Marketplace/SellerVerificationController.php:127-134 writes business_settings `kyc_required_documents` and `require_kyc_for_payout`; read at app/Services/Marketplace/SellerVerificationService.php:54 and :154; enforced at app/Services/Marketplace/PayoutService.php:63 |
| Brand registry enforcement — whether sellers are refused listings under brands they have not been granted | brands | Admin | business_settings `brand_claim_enforcement` (app/Services/Marketplace/BrandRegistryService.php:39), written at app/Http/Controllers/Admin/Marketplace/BrandRegistryController.php:175-180 (route admin.marketplace.brand-registry.enforcement, routes/admin/routes.php:632); read at BrandRegistryService.php:84 and enforced at app/Http/Requests/Concerns/ChecksBrandEntitlement.php:40 and RestAPI/v3/seller/ProductController.php:2231 |
| SMS gateway credentials and on/off state (fourteen providers) | notifications | Admin | addon_settings rows with settings_type='sms_config', written by app/Http/Controllers/Admin/ThirdParty/SMSModuleController.php (routes/admin/routes.php:1259-1262); read at app/Utils/SMSModule.php:240 get_settings() and app/Traits/SmsGateway.php:611 |
| Delivery Syria courier integration — credentials, rate sync and parcel creation | shipping | Admin | business_settings `delivery_syria` (app/Services/DeliverySyria/DeliverySyriaConfigService.php:23 SETTING_KEY, written at :121 with secrets encrypted); admin screen at app/Http/Controllers/Admin/ThirdParty/DeliverySyriaController.php with routes/admin/routes.php:1271-1274 including a verify-sync action |
| Marketing and tracking scripts — Google Analytics, Tag Manager, Meta Pixel and the rest | analytics | Admin | analytic_scripts table via app/Repositories/AnalyticScriptRepository.php:95, written from app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:403-421 (routes/admin/routes.php:1224-1225) |
| Storefront look — theme activation, draft/publish versions, section builder, colours, typography and layout tokens | platform | Admin | app/Http/Controllers/Admin/Settings/ThemeManagementController.php, ThemeSettingsController, ThemeBuilderController — routes/admin/routes.php:1483-1549; audited through app/Services/Theme/ThemeManager.php:102,:156,:223 and ThemeBuilderService.php:57-341 |
| Languages available in the panel and storefront, the default, and the translation strings | platform | Admin | business_settings `language` and `pnc_language`, written at app/Http/Controllers/Admin/Settings/LanguageController.php:107-108,:123,:138,:151,:268,:281; routes/admin/routes.php:1122-1136 including auto-translate |
| How customers and staff sign in — login options, social login on the login form, email/phone verification, OTP attempt limits and lockout windows, and the secret admin/employee login URLs | security | Admin | login_setups table via app/Http/Controllers/Admin/SystemSetup/SystemLoginSetupController.php:78-90; business_settings `maximum_otp_hit`, `otp_resend_time`, `temporary_block_time`, `maximum_login_hit`, `temporary_login_block_time` at :157-161; `admin_login_url`/`employee_login_url` at :181; routes/admin/routes.php:1156-1168 |
| Wording of every automated email | notifications | Admin | app/Http/Controllers/Admin/EmailTemplatesController.php (routes/admin/routes.php:1170-1176 view/update/update-status per type and tab) |
| Forced app upgrades — minimum required Android/iOS version for the customer, seller and delivery apps | platform | Admin | business_settings `user_app_version_control`, `seller_app_version_control`, `delivery_man_app_version_control` written at app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:305-312 (route admin.system-setup.app-settings, routes/admin/routes.php:1111); published at ConfigController.php:205-207 |
| Storefront content blocks — announcement bar, features section, company reliability badges, social media links | platform | Admin | `announcement` at app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:476 (routes/admin/routes.php:1681); `company_reliability` at routes/admin/routes.php:1634; features section at :1672; social media at app/Http/Controllers/Admin/Settings/SocialMediaSettingsController.php |
| Stock clearance sale setup — the platform's own clearance campaign and whether vendor clearance offers appear on the homepage | pricing | Admin | app/Repositories/StockClearanceSetupRepository.php with business_settings `stock_clearance_vendor_offer_in_homepage` written at app/Http/Controllers/Admin/Promotion/ClearanceSaleVendorOfferController.php:102 and priority keys at ClearanceSalePrioritySetupController.php:51-55 |

## CONNECTED TO SELLER (77)

The seller manages it, in the panel or the app.

| Capability | Area | Owner | Where it lives |
|---|---|---|---|
| Seller bulk price and stock jobs — queued updates across many products, with a receipt and a failures file | catalog | Seller | app/Http/Controllers/Admin/Marketplace/SellerOperationsController.php bulkJobs; app/Services/Marketplace/Bulk/SellerBulkJobService.php; routes/admin/routes.php:648; cron seller:run-stuck-bulk-jobs bootstrap/app.php:188; app/Services/Marketplace/Bulk/SellerBulkJobService.php:106 'seller.bulk_job_queued', :238 'seller.bulk_job_finished'; per-item price writes go through a model save at app/Services/Marketplace/Bulk/BulkPriceOperation.php:101 (so the price observer fires) and stock through app/Services/Marketplace/Bulk/BulkStockOperation.php:77; routes routes/rest_api/v3/seller.php:677-678; app/Console/Commands/RunStuckSellerBulkJobs.php:25 `seller:run-stuck-bulk-jobs`; scheduled bootstrap/app.php:188 every minute; app/Jobs/RunSellerBulkJob.php:25 (tries=1, timeout=900, failed() writes status=failed at lines 49-59); dispatched from app/Services/Marketplace/Bulk/SellerBulkJobService.php:112; |
| Order-wise and expense-wise transaction reports with PDF and Excel export | finance | Seller | routes/vendor/routes.php:463-474 — vendor/transaction/*; app-side equivalent is routes/rest_api/v3/seller.php:115 — GET transactions; app/Http/Controllers/Admin/TransactionReportController.php, ExpenseTransactionReportController.php, Report/RefundTransactionController.php; routes/admin/routes.php:800-808, :837-852; nav _side-bar.blade.php:611 |
| Order, product and stock reports with Excel and PDF export | analytics | Seller | routes/vendor/routes.php:447-461 — vendor/report/*; routes/rest_api/v3/seller.php:598-615 — seller-center/reports/orders\|products\|stock (+/export); app/Http/Controllers/Admin/ProductReportController.php, OrderReportController.php, ProductStockReportController.php, ProductWishlistReportController.php; routes/admin/routes.php:820-829, :854-866; nav _side-bar.blade.php:620/624 |
| Vacation mode and temporarily closing the shop | platform | Seller | routes/vendor/routes.php:339-340 — POST vendor/shop/add-vacation, close-shop-temporary; routes/rest_api/v3/seller.php:128-133 — PUT vacation-add, temporary-close; app/Http/Controllers/Admin/Settings/InhouseShopController.php; routes/admin/routes.php:1405-1414; nav _side-bar.blade.php:900 |
| Order queue and order detail in the classic seller panel — filter by status, open an order, see its lines | orders | Seller | routes/vendor/routes.php:191-195 — GET vendor/orders/list/{status}, GET vendor/orders/details/{id}; controller app/Http/Controllers/Vendor/Order/OrderController.php |
| Seller Center order list and order detail — saved views (ready to ship / shipped / delivered / cancelled), SLA countdown, per-order earnings breakdown | orders | Seller | routes/seller/routes.php:65-68 — GET vendor/orders, GET vendor/orders/{order}, middleware seller_can:orders.view,orders.manage; app/Http/Controllers/Seller/OrderController.php:29-68 |
| Change an order's status (confirmed, processing, out for delivery, delivered, cancelled) | orders | Seller | routes/vendor/routes.php:202 — POST vendor/orders/status; routes/rest_api/v3/seller.php:205 — PUT api/v3/seller/orders/order-detail-status/{id} (seller_can:orders.manage) |
| Assign a delivery man or a third-party courier to an order | shipping | Seller | routes/vendor/routes.php:199 — GET vendor/orders/add-delivery-man/{order_id}/{d_man_id}; routes/rest_api/v3/seller.php:206,209 — PUT assign-delivery-man, POST assign-third-party-delivery |
| Generate and download an order invoice | orders | Seller | routes/vendor/routes.php:194 — GET vendor/orders/generate-invoice/{id}; routes/rest_api/v3/seller.php:198 — GET api/v3/seller/orders/{id}/invoice |
| Update the delivery address on an order | orders | Seller | routes/vendor/routes.php:196 — POST vendor/orders/address-update; routes/rest_api/v3/seller.php:211 — POST api/v3/seller/orders/address-update |
| Export the order list to Excel | orders | Seller | routes/vendor/routes.php:193 — GET vendor/orders/export-excel/{status}; routes/rest_api/v3/seller.php:604-605 — GET seller-center/reports/orders/export |
| Create and edit a product listing — copy, images, variations, SEO, price, stock | catalog | Seller | routes/vendor/routes.php:154-187 — vendor/products/add, update/{id}; routes/rest_api/v3/seller.php:171-182 — api/v3/seller/products/add, update/{id} (seller_can:products.manage) |
| AI drafting of product copy — title, description, general setup, pricing, variations, SEO, image analysis | automation | Seller | Modules/AI/routes/vendor/routes.php:19-29 — vendor/product/*-auto-fill; Modules/AI/routes/api.php:23-34 — POST api/v3/seller/product/*-auto-fill (seller_can:products.manage) |
| Barcode generation for a product | catalog | Seller | routes/vendor/routes.php:166 — GET vendor/products/barcode/{id}; routes/rest_api/v3/seller.php:160 — GET api/v3/seller/products/barcode/generate |
| Restock requests — customers asking to be told when a product is back, and the seller restocking against that list | inventory | Seller | routes/vendor/routes.php:181-183 — vendor/products/request-restock-list, export-restock, delete-restock/{id}; routes/rest_api/v3/seller.php:167-168,188 — restock-request-list, restock-request-stock-update |
| Set the selling price and discount on a listing | pricing | Seller | routes/vendor/routes.php:170-171 — POST vendor/products/update/{id}; routes/rest_api/v3/seller.php:177 — PUT api/v3/seller/products/update/{id}; recorded by app/Services/Marketplace/PriceChangeRecorder.php |
| Bulk stock update across many listings, gated on inventory.manage rather than on price | inventory | Seller Staff | routes/rest_api/v3/seller.php:678 — POST api/v3/seller/seller-center/bulk-jobs/stock (seller_can:inventory.manage) |
| Price change history — who changed this price, when, and from what | pricing | Seller | routes/rest_api/v3/seller.php:496 — GET api/v3/seller/seller-center/finance/price-changes; app/Services/Marketplace/PriceChangeRecorder.php |
| Pricing policy — the floor under this shop's own prices | pricing | Seller | routes/rest_api/v3/seller.php:501,504 — GET/PUT api/v3/seller/seller-center/finance/pricing-policy (seller_can:products.manage); app/Services/Marketplace/PricingPolicyService.php; app/Services/Marketplace/PricingPolicyService.php:146 'seller.pricing_policy_changed' |
| Fee simulator — what a given sale would cost this shop in commission and fees | finance | Seller | routes/rest_api/v3/seller.php:492 — GET api/v3/seller/seller-center/finance/fee-simulator (seller_can:finance.view); app/Services/Marketplace/FeeSimulatorService.php |
| Reconciliation — does what I sold add up to what I was paid | finance | Seller | routes/rest_api/v3/seller.php:491 — GET api/v3/seller/seller-center/finance/reconciliation (seller_can:finance.view); app/Services/Marketplace/SellerReconciliationService.php |
| Clearance sale — put the shop's own stock on discount with its own config and SEO block | pricing | Seller | routes/vendor/routes.php:247-261 — vendor/clearance-sale/*; routes/rest_api/v3/seller.php:226-241 — api/v3/seller/clearance-sale/* (seller_can:promotions.manage) |
| Coupons — create, edit, activate and expire the shop's own coupons | pricing | Seller | routes/vendor/routes.php:234-245 — vendor/coupon/*; routes/rest_api/v3/seller.php:257-271 — api/v3/seller/coupon/* (seller_can:promotions.manage) |
| Set a product's stock quantity from the panel | inventory | Seller | routes/vendor/routes.php:169 — POST vendor/products/update-quantity; app/Http/Controllers/Vendor/Product/ProductController.php:788-818; routes/rest_api/v3/seller.php:187 — PUT products/quantity-update |
| Inventory overview and the stock movement ledger — what changed, by how much, why and who did it | inventory | Seller | routes/seller/routes.php:74-77 — GET vendor/inventory, vendor/inventory/movements; routes/rest_api/v3/seller.php:656-661 — seller-center/inventory/overview, movements |
| Adjust stock through the ledger — a signed correction with a reason, a balance-after and an audit line | inventory | Seller Staff | routes/rest_api/v3/seller.php:662-663 — POST api/v3/seller/seller-center/inventory/products/{id}/adjust (seller_can:inventory.manage); app/Services/Marketplace/InventoryService.php:35-80 |
| Low-stock threshold for this shop, and the global default behind it | inventory | Seller | routes/vendor/routes.php:341 — POST vendor/shop/update-other-settings (stock_limit per seller); global default at app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:438 (setting key stock_limit) |
| Category-wise shipping cost, and choosing order-wise / product-wise / category-wise shipping | shipping | Seller | routes/vendor/routes.php:372-378 — POST vendor/business-settings/shipping-type/index, category-wise-shipping-cost/index; routes/rest_api/v3/seller.php:273-284 — api/v3/seller/shipping/*; business_settings `shipping_method` written at app/Http/Controllers/Admin/Shipping/ShippingMethodController.php:161; shipping type at app/Http/Controllers/Admin/Shipping/ShippingTypeController.php:54; routes/admin/routes.php:1358-1376; seller equivalents at routes/vendor/routes.php:361-378 |
| The shop's own delivery men — add, edit, rate, suspend, see their order history | shipping | Seller | routes/vendor/routes.php:277-289 — vendor/delivery-man/*; routes/rest_api/v3/seller.php:335-349 — api/v3/seller/delivery-man/* (seller_can:orders.manage) |
| Delivery man wallet, cash collection and withdrawal approval | finance | Seller | routes/vendor/routes.php:290-309 — vendor/delivery-man/wallet/*, withdraw/*; routes/rest_api/v3/seller.php:351-362 — cash-receive, withdraw/status-update |
| Delivery man emergency contacts | shipping | Seller | routes/vendor/routes.php:311-320 — vendor/delivery-man/emergency-contact/*; routes/rest_api/v3/seller.php:364-372 |
| Account statement — the shop's ledger line by line with the running balance each entry left behind, exportable | finance | Seller | routes/rest_api/v3/seller.php:630-635 — GET api/v3/seller/seller-center/statement, /export (seller_can:finance.view); app/Services/Marketplace/SellerLedgerStatementService.php |
| Where the shop's money is sent — bank / withdrawal method details, default selection | finance | Seller | routes/vendor/routes.php:346-357 — vendor/shop/payment-information/*; routes/rest_api/v3/seller.php:390-405 — payment-information (finance.view to read, seller_owner to change) |
| VAT / tax report for the shop | finance | Seller | Modules/TaxModule/routes/vendor.php:19-20 — vendor/report/get-vat-report, -export; Modules/TaxModule/routes/api/v3/api.php:20 — GET api/v3/seller/get-vat-tax-report-list (seller_can:finance.view) |
| Brand claims — asking to sell under a brand, attaching evidence, submitting and withdrawing a claim | brands | Seller | routes/rest_api/v3/seller.php:464-482 — api/v3/seller/seller-center/brand-claims (products.view to read, products.manage to claim); app/Services/Marketplace/BrandRegistryService.php |
| Brand exposure — which of my listings depend on a brand claim that is not approved | brands | Seller | routes/rest_api/v3/seller.php:469 — GET api/v3/seller/seller-center/brand-claims/exposure |
| Automation rules — write a rule for my own shop, scope it to brands/categories/products, preview matches, run it now | automation | Seller | routes/seller/routes.php:82-97 — vendor/automation/* (products.view to read, products.manage to write); routes/rest_api/v3/seller.php:564-583; app/Services/SellerAutomation/SellerAutomationRuleService.php; command seller:run-automation |
| Automation history and undo — what a rule matched, what it actually changed, and putting one run back | automation | Seller | routes/seller/routes.php:99-104 — GET vendor/automation/history, POST vendor/automation/history/{action}/revert; routes/rest_api/v3/seller.php:568,580 — activity, activity/{id}/revert |
| Opportunities — computed suggestions from this shop's own data (fast sellers running out, viewed-not-bought, under category median) | analytics | Seller | routes/seller/routes.php:106-107 — GET vendor/opportunities; app/Services/SellerCenter/Automation/Opportunities.php |
| Control Tower — what is wrong, arranged by when it needs doing, plus the daily briefing | monitoring | Seller | routes/seller/routes.php:58 — GET vendor/control-tower; routes/rest_api/v3/seller.php:446-458 — seller-center/control-tower, /briefing, PUT issues/{id}/status; app/Services/SellerIntelligence/ControlTowerService.php |
| Issue Center — the detected problems list and one issue's detail with its resolving action | monitoring | Seller | routes/seller/routes.php:60-63 — GET vendor/issues, vendor/issues/{issue}; app/Services/SellerIntelligence/SellerInsightEngine.php; commands seller:refresh-insights, seller:escalate-issues (bootstrap/app.php:160,170) |
| Action Center — the one flat list of everything waiting for this seller, and dismissing an item | notifications | Seller | routes/rest_api/v3/seller.php:587-594 — GET api/v3/seller/seller-center/action-center, POST {id}/dismiss (seller_can:orders.view,products.view) |
| Seller staff and roles — define a role, grant permissions, add and remove team members | security | Seller | routes/vendor/routes.php:428-440 — vendor/business-settings/staff, roles; routes/rest_api/v3/seller.php:542-556 — seller-center/security (seller_can:staff.manage); app/Services/Marketplace/SellerTeamService.php |
| Sign a staff member out of every session, and see who last accessed the shop | security | Seller | routes/rest_api/v3/seller.php:546,554 — GET seller-center/security/access, POST security/staff/{id}/sign-out (seller_can:staff.manage) |
| The shop's own audit trail — what was done in this shop, by whom, with the before and after | security | Seller | routes/rest_api/v3/seller.php:547 — GET api/v3/seller/seller-center/security/audit (seller_can:staff.manage); app/Services/Marketplace/SellerAuditTrailService.php over app/Services/AuditLogger.php |
| Seller staff sign-in with their own credentials | security | Seller Staff | routes/vendor/routes.php:79-85 — vendor/staff-auth/login, logout (throttle:20,1); enforcement in app/Http/Middleware/SellerStaffAccessMiddleware.php |
| API keys the shop issues — mint a scoped key, see where each was last used, revoke one | integrations | Seller | routes/rest_api/v3/seller.php:516-523 — GET/POST/DELETE api/v3/seller/seller-center/integrations/keys (seller_can:shop_settings.manage); app/Services/Marketplace/SellerApiKeyService.php |
| Webhooks the shop registers — create, enable, disable, delete, send a test, read the delivery log | integrations | Seller | routes/rest_api/v3/seller.php:518-528 — api/v3/seller/seller-center/integrations/webhooks, /deliveries, /{id}/test (seller_can:shop_settings.manage); app/Services/Marketplace/SellerWebhookDispatcher.php; command seller:retry-webhooks (bootstrap/app.php:182) |
| The shop's own storefront analytics — visitors, sessions, product views, cart adds, revenue | analytics | Seller | routes/vendor/routes.php:104 — GET vendor/analytics; routes/rest_api/v3/seller.php:435-442 — seller-center/analytics, /activities (seller_can:finance.view,orders.view); app/Services/Analytics/Reporting/AnalyticsReporting.php; app/Http/Controllers/Vendor/AnalyticsController.php:53 (vendor id from the auth guard only) over AnalyticsReporting.php:632 forVendor; route GET vendor/analytics at routes/vendor/routes.php:104-105 |
| Shop profile — name, logo, banner, address, contact | platform | Seller | routes/vendor/routes.php:334-344 — vendor/shop/index, update/{id}; routes/rest_api/v3/seller.php:98 — PUT shop-update (seller_can:shop_settings.manage) |
| Seller profile and password, and the legacy bank-info form | platform | Seller | routes/vendor/routes.php:323-332 — vendor/profile/*, update-bank-info/{id}; routes/rest_api/v3/seller.php:93 — PUT seller-update (seller_owner) |
| Delete the seller account | platform | Seller | routes/rest_api/v3/seller.php:91-92 — DELETE/GET api/v3/seller/account-delete (seller_owner) |
| Seller notifications — the in-app list, marking one seen, and the FCM device token | notifications | Seller | routes/vendor/routes.php:88-90,272-274 — POST vendor/system/save-fcm-web-token, POST vendor/notification/index; routes/rest_api/v3/seller.php:377-386 — api/v3/seller/notification, /view; PUT cm-firebase-token at line 79 |
| Chat with customers and with delivery men | notifications | Seller | routes/vendor/routes.php:263-270 — vendor/messages/index/{type}; routes/rest_api/v3/seller.php:301-313 — api/v3/seller/messages/* (seller_can:orders.view,orders.manage) |
| Seller Center cockpit page in the classic panel — verification, performance, finance and SLA in one view | platform | Seller | routes/vendor/routes.php:420-424 — GET vendor/business-settings/seller-center; app/Http/Controllers/Vendor/Marketplace/SellerCenterController.php; API twin at routes/rest_api/v3/seller.php:417 — seller-center/overview |
| Seller Center home, global search, command palette and shell preferences (density, direction) | platform | Seller | routes/seller/routes.php:50-57,109-110 — GET vendor/overview, vendor/search, vendor/help, vendor/preferences/density\|direction; app/Services/SellerCenter/Shell.php, Search.php, Navigation.php |
| The same seller storefront analytics in the mobile seller app | analytics | Seller | app/Http/Controllers/RestAPI/v3/seller/SellerAnalyticsController.php:42 with #[ApiDoc] at :31; routes/rest_api/v3/seller.php:435-441 (GET api/v3/seller/seller-center/analytics, gated seller_can:finance.view,orders.view); Flutter lib/features/seller_center/screens/analytics_screen.dart:13 via lib/utill/app_constants.dart:168 |
| Counts of what is waiting for a seller right now (unchecked orders, restock requests) | orders | Seller | app/Http/Controllers/RestAPI/v3/seller/SellerAnalyticsController.php:73 activities(), ApiDoc at :63; route GET .../analytics/activities at routes/rest_api/v3/seller.php:438; web twin at Vendor/DashboardController 'real-time-activities' (routes/vendor/routes.php:116) |
| Telling a seller which of their products get traffic but do not convert | analytics | Seller | app/Services/SellerCenter/Automation/Opportunities.php:123-138 highTrafficLowConversion reading analytics_events (product_viewed, bots and staff excluded); shown at resources/views/seller-views/opportunities/index.blade.php via routes/seller/routes.php:106 |
| Seller reads their own shop's trail — actions by the owner, their staff and their API keys, plus decisions the marketplace recorded about the shop | security | Seller | app/Services/Marketplace/SellerAuditTrailService.php:35 forSeller() / :85 recent(); app/Http/Controllers/RestAPI/v3/seller/SellerSecurityController.php:348 audit(); route routes/rest_api/v3/seller.php:547 GET /api/v3/seller/security/audit behind seller_can:staff.manage |
| Flutter seller app security screen — the app's audit tab, filterable by action prefix | security | Seller | /home/user/sillercenter-syria-cosmatics/lib/features/security/domain/repositories/security_repository.dart:28 (GET security/audit); controller at lib/features/security/controllers/security_controller.dart:243; screen at lib/features/security/screens/security_screen.dart:277; tile at lib/features/security/widgets/security_widgets.dart:446 |
| Seller automation rules — create, edit, enable/disable, delete, and every action a rule takes on the catalogue | automation | Seller | app/Services/SellerAutomation/SellerAutomationRuleService.php:58 created, :99 updated, :141 status_changed, :154 deleted; app/Services/SellerAutomation/AutomationEngine.php:309 rule_applied, :221 action_reverted, :441 auto-suspended; actions app/Services/SellerAutomation/Actions/HideListingAction.php:80 and PublishListingAction.php:92; admin suspend/release app/Http/Controllers/Admin/Marketplace/SellerOperationsController.php:162/:199 |
| Outbound seller webhooks: a shop registers endpoints, picks events, gets a signing secret, and sees delivery health | integrations | Seller | app/Services/Marketplace/SellerWebhookDispatcher.php:38 EVENTS (order.placed, order.status_changed, order.refund_requested, product.stock_low, product.hidden_by_rule, payout.status_changed); raised by app/Observers/SellerWebhookEventObserver.php:34-115; delivered by app/Jobs/DeliverSellerWebhook.php:33; retried by `seller:retry-webhooks` every five minutes (bootstrap/app.php:182); API at routes/rest_api/v3/seller.php:511 |
| Seller API keys: issue, list, revoke, scope-narrow, and see last-used IP and time | security | Seller | app/Http/Controllers/RestAPI/v3/seller/SellerIntegrationController.php:36 keys() / :69 storeKey() / :116 revokeKey(); routes/rest_api/v3/seller.php:511; resolved on every request by app/Http/Middleware/SellerApiAuthMiddleware.php:58 |
| Seller roles, staff and permission catalogue over the API | security | Seller | app/Http/Controllers/RestAPI/v3/seller/SellerSecurityController.php:45 permissions() onward — 12 of 12 methods carry #[ApiDoc]; routes/rest_api/v3/seller.php:536, gated by seller_can:staff.manage (routes/rest_api/v3/seller.php:542) |
| A single automation rule may not change more than 500 products in one run, and defaults to 50 | automation | Seller | app/Services/SellerAutomation/SellerAutomationRuleService.php:21 (MAX_ACTIONS_PER_RUN = 500) enforced as validation at :178, default 50 at :216, but the create form pre-fills 5 at resources/views/seller-views/automation/builder.blade.php:23; ceiling exposed read-only at app/Http/Controllers/Seller/AutomationController.php:161 |
| Bounds a seller may pick for their own automation triggers: low-stock threshold 1-1000 units, stale-stock 7-365 days | automation | Seller | app/Services/SellerAutomation/Triggers/LowStockTrigger.php:34 ('threshold' => 'required\|integer\|min:1\|max:1000'), app/Services/SellerAutomation/Triggers/StaleStockTrigger.php:36 ('days' => 'required\|integer\|min:7\|max:365'); action floor at app/Services/SellerAutomation/Actions/SetDiscountAction.php:48 |
| Seller price floor policy — minimum margin percent, minimum price and whether it is enforced | pricing | Seller | seller_pricing_policies via app/Services/Marketplace/PricingPolicyService.php:129 save(); API only at routes/rest_api/v3/seller.php:501,:504 GET/PUT seller-center/finance/pricing-policy; enforced at app/Services/SellerAutomation/Actions/SetDiscountAction.php:90 and app/Services/Marketplace/Bulk/BulkPriceOperation.php:83; app/Services/Marketplace/PricingPolicyService.php:71-90 floorFor() reading seller_pricing_policies (min_price, min_margin_percent); margin bound validated at app/Http/Controllers/RestAPI/v3/seller/SellerFinanceControlController.php:203 (max:1000) |
| Recompute what each seller should be looking at (Action Center, home alerts) and notify them | notifications | System | app/Console/Commands/RefreshSellerInsights.php:24 `seller:refresh-insights`; scheduled bootstrap/app.php:160 hourly |
| Run the automation rules sellers wrote for their own catalogues | automation | Seller | app/Console/Commands/RunSellerAutomation.php:20 `seller:run-automation`; scheduled bootstrap/app.php:176 every 15 min; engine app/Services/SellerAutomation/AutomationEngine.php |
| Re-queue seller webhook deliveries whose next attempt has come due | integrations | Seller | app/Console/Commands/RetrySellerWebhooks.php:19 `seller:retry-webhooks`; scheduled bootstrap/app.php:182 every 5 min; dispatches App\Jobs\DeliverSellerWebhook |
| Deliver one seller webhook attempt off the request thread | integrations | Seller | app/Jobs/DeliverSellerWebhook.php:23 (tries=1); dispatched from app/Services/Marketplace/SellerWebhookDispatcher.php:106, app/Console/Commands/RetrySellerWebhooks.php:37 and app/Http/Controllers/RestAPI/v3/seller/SellerIntegrationController.php:388 (manual test) |
| Raise a seller's webhooks from the model - order placed/status, refund requested, payout status, stock crossing the low line | integrations | System | app/Observers/SellerWebhookEventObserver.php:23; registered app/Providers/ObserverServiceProvider.php:41,49-51 on Product, Order, RefundRequest, VendorPayoutRequest |
| Seller shop configuration — shop profile, banner, temporary close, vacation window and business TIN | platform | Seller | app/Http/Controllers/Vendor/ShopController.php (routes/vendor/routes.php:331-340 update / add-vacation / close-shop-temporary); API equivalents at routes/rest_api/v3/seller.php:97-99 shop-update and :129-131 vacation-add / temporary-close, both behind seller_can:shop_settings.manage |
| Seller API keys and outbound webhooks — the credentials and endpoints that let outside software act as a shop | integrations | Seller | app/Services/Marketplace/SellerApiKeyService.php and SellerWebhookDispatcher.php; API only at routes/rest_api/v3/seller.php:516-528 behind seller_can:shop_settings.manage; admin intervention (revoke key / disable webhook) at routes/admin/routes.php:651-652 |
| Seller automation rules — unattended catalogue changes (hide, publish, reprice) with triggers and settings fields | automation | Seller | app/Services/SellerAutomation/SellerAutomationRuleService.php:25 and AutomationEngine.php:43, both audited; Seller Center screens at routes/seller/routes.php:82-97; scheduled by `seller:run-automation` (bootstrap/app.php:176); admin suspend/release at routes/admin/routes.php:649-650 |
| Seller payment/bank information for payouts | finance | Seller | app/Http/Controllers/Vendor/VendorPaymentInfoController.php (routes/vendor/routes.php:346-356) plus bank info at routes/vendor/routes.php:326-327; app screens at lib/features/bank_info and lib/features/shop/screens/payment_info_screen.dart |

## CONNECTED TO DEVELOPER PORTAL (28)

Documented as an API capability an integrator can use.

| Capability | Area | Owner | Where it lives |
|---|---|---|---|
| Developer Portal: the live API surface derived from the route table | integrations | Developer | app/Http/Controllers/Admin/Telemetry/DeveloperPortalController.php; app/Services/DeveloperPortal/ApiManifest.php:216 describe(); routes/admin/routes.php:181-205; nav _side-bar.blade.php:879 |
| API console: send a live request from the portal | integrations | Developer | app/Http/Controllers/Admin/Telemetry/DeveloperPortalController.php try(); app/Services/DeveloperPortal/ApiConsole.php and ConsoleGuard.php; routes/admin/routes.php:199 admin.developer.try; app/Services/DeveloperPortal/ApiConsole.php:51 send() (dispatched through the app's own HTTP kernel, no URL field anywhere); POST admin/developer/try/{id} (routes/admin/routes.php:199) via app/Http/Controllers/Admin/Telemetry/DeveloperPortalController.php:163; per-admin rate limit config developer_portal.console.rate_limit_per_minute (config/developer_portal.php:49); app/Services/DeveloperPortal/ApiConsole.php:70 'developer.console.sent', recording method, path, status, duration, tier and whether a token was used but never which one; portal routes routes/admin/routes.php:181 admin.developer.* |
| OpenAPI and Postman collection download | integrations | Developer | app/Services/DeveloperPortal/Generators/{OpenApiGenerator,PostmanGenerator}.php; routes/admin/routes.php:189-190 admin.developer.openapi / postman; app/Services/DeveloperPortal/Generators/PostmanGenerator.php:1; GET admin/developer/download/postman (routes/admin/routes.php:190) via app/Http/Controllers/Admin/Telemetry/DeveloperPortalController.php:118 |
| API snapshot history and manifest rebuild | integrations | Developer | app/Services/DeveloperPortal/ApiSnapshotService.php; app/Console/Commands/{ApiSnapshotCommand,ApiManifestCommand}.php; routes/admin/routes.php:200-201 admin.developer.snapshot / refresh |
| The catalogue of webhook events a shop can subscribe to | integrations | Developer | routes/rest_api/v3/seller.php:518 — GET api/v3/seller/seller-center/integrations/events |
| Developer Portal coverage of the seller API | integrations | Developer | routes/admin/routes.php:180-202 — admin/developer/*; app/Services/DeveloperPortal/ApiManifest.php derives every route, #[ApiDoc] marks it documented |
| Mobile apps reporting what only the app can see (a banner tapped, a list navigated) | integrations | Developer | app/Http/Controllers/RestAPI/v1/AnalyticsEventController.php:46 with #[ApiDoc] at :33; route POST api/v1/analytics/events at routes/rest_api/v1/api.php:94; payload rules shared with the web beacon via Support/ClientEventIngest.php:37 |
| API activity as a measured quantity (calls per endpoint, error rate, latency, who is calling) | integrations | Developer | monitoring_request_buckets via app/Http/Middleware/MonitorRequest.php (bootstrap/app.php:58,78,88) read per documented endpoint by app/Services/DeveloperPortal/EndpointHealthService.php:33; raw rows also written by RecordHttpTelemetry into telemetry_requests (TelemetryRecorder.php:36); seller key usage as seller_api_keys.last_used_at (SellerOperationsOverview.php:53) |
| Live API manifest: every route under api/ described from the route table, its middleware, its auth and its validation rules | platform | Developer | app/Services/DeveloperPortal/ApiManifest.php:186 build() / :217 describe(); cached as `developer_portal:manifest` (ApiManifest.php:32) keyed on a route-table fingerprint (ApiManifest.php:443); rebuilt by POST admin/developer/refresh (routes/admin/routes.php:201) |
| #[ApiDoc] attribute — the only hand-written part of an endpoint's documentation (intent, stability, audience, visibility, since, sunset, scopes, emitted events) | platform | Developer | app/Services/DeveloperPortal/ApiDoc.php:33 (attribute) / :74 (constructor); read by ApiManifest::doc() at app/Services/DeveloperPortal/ApiManifest.php:319 |
| Endpoint classification — audience, resource group, version, visibility and api-vs-panel surface inferred for every route | platform | Developer | app/Services/DeveloperPortal/Support/EndpointClassifier.php:80 classify() / :107 visibility() / :24 PATH_AUDIENCE |
| Authentication requirement resolved per endpoint from the middleware that actually runs (Passport, Sanctum, seller token, delivery token, courier webhook secret, panel sessions) | security | Developer | app/Services/DeveloperPortal/Support/AuthResolver.php:92 resolve() / :22 SCHEMES / :146 genericGuard(); rendered on the endpoint page at resources/views/admin-views/telemetry/developer-endpoint.blade.php:96 and on the Authentication section (app/Services/Telemetry/DeveloperPortalService.php:259) |
| Request schema recovered from FormRequest classes and inline validator() calls, translated into readable field types | platform | Developer | app/Services/DeveloperPortal/Support/ValidationExtractor.php:40 forMethod() / :158 fromMethodBody(); app/Services/DeveloperPortal/Support/RuleTranslator.php:46 field() / :181 schema() |
| Response shapes learned from live traffic (keys and types only, never values) | platform | System | app/Services/DeveloperPortal/ResponseShapeRecorder.php:43 record(); app/Http/Middleware/RecordApiResponseShape.php:28 terminate(), registered in the api middleware group at bootstrap/app.php:93; stored in api_response_shapes (database/migrations/2026_08_28_000001_create_api_response_shapes_table.php); switch `developer_portal.record_response_shapes` (config/developer_portal.php:22) |
| Copy-paste code examples per endpoint in curl, Dart, Kotlin, Swift, JavaScript and PHP | platform | Developer | app/Services/DeveloperPortal/Generators/CodeExampleGenerator.php:32 all(); rendered at resources/views/admin-views/telemetry/developer-endpoint.blade.php:160 |
| Seller Center v3 API — the whole 86-endpoint surface the new seller app calls | platform | Seller | routes/rest_api/v3/seller.php:411 (seller-center prefix); 16 controllers under app/Http/Controllers/RestAPI/v3/seller/, each method carrying #[ApiDoc] |
| Seller automation rules over the API (create, preview, run, revert, history) | automation | Seller | app/Http/Controllers/RestAPI/v3/seller/SellerAutomationController.php:47 onward — 11 of 11 documented; routes/rest_api/v3/seller.php:564; executed by `seller:run-automation` every fifteen minutes (bootstrap/app.php:176) |
| Seller brand claims over the API | brands | Seller | app/Http/Controllers/RestAPI/v3/seller/SellerBrandClaimController.php:45 onward — 8 of 8 documented; routes/rest_api/v3/seller.php:464 |
| Seller reports over the API | analytics | Seller | app/Http/Controllers/RestAPI/v3/seller/SellerReportController.php:48 onward — 7 of 7 documented; routes/rest_api/v3/seller.php:598 |
| Seller bulk jobs over the API (import/export, progress, stuck-job recovery) | catalog | Seller | app/Http/Controllers/RestAPI/v3/seller/SellerBulkJobController.php:42 onward — 5 of 5 documented; routes/rest_api/v3/seller.php:670; swept by `seller:run-stuck-bulk-jobs` every minute (bootstrap/app.php:188) |
| Seller finance controls, statements and payouts over the API | finance | Seller | app/Http/Controllers/RestAPI/v3/seller/SellerFinanceControlController.php:50 (5/5), SellerStatementController.php:39 (2/2), SellerPayoutController.php:43 (3/3) — all documented; routes/rest_api/v3/seller.php:488, :630, :620; scoped seller_can:finance.view / payouts.request |
| Seller inventory and returns over the API | inventory | Seller | app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:61 (5/5) and SellerReturnController.php:49 (5/5) — all documented; routes/rest_api/v3/seller.php:654 and :639 |
| Seller verification / KYC submission over the API | compliance | Seller | app/Http/Controllers/RestAPI/v3/seller/SellerVerificationController.php:37 — 3 of 3 documented, declares emits `kyc_submitted`; routes/rest_api/v3/seller.php:422 |
| Seller control tower, action centre, analytics and home over the API | analytics | Seller | SellerControlTowerController.php:48 (3/3), SellerActionCenterController.php:41 (2/2), SellerAnalyticsController.php:42 (2/2), SellerCenterController.php:37 (2/2) under app/Http/Controllers/RestAPI/v3/seller/; routes/rest_api/v3/seller.php:446, :587, :435, :415 |
| Customer-app endpoints that do carry documentation: app health, deep links, analytics events, banners and theme sections | platform | Developer | app/Http/Controllers/RestAPI/v1/AppHealthController.php:42 (1/1), DeepLinkController.php:44 (2/2), AnalyticsEventController.php:41 (1/1), BannerController.php:51 (3/3), ThemeSectionController.php:130 (3/3); routes/rest_api/v1/api.php:54-66 |
| The Developer Portal API console allows 20 requests per minute per administrator and a 64 KB request body | integrations | Developer | config/developer_portal.php:49 (rate_limit_per_minute, env DEVELOPER_CONSOLE_RATE_LIMIT, default 20) enforced at app/Http/Controllers/Admin/Telemetry/DeveloperPortalController.php:196-207; app/Services/DeveloperPortal/ApiConsole.php:34 (MAX_BODY_BYTES = 64_000); writes off by default at config/developer_portal.php:47 |
| Freeze the API surface and detect breaking changes since the last release | integrations | Developer | app/Console/Commands/ApiSnapshotCommand.php:16 `api:snapshot`; not scheduled |
| APIs — this shop's own API surface joined to real traffic, by version and endpoint, including deprecated and never-called endpoints | monitoring | Developer | app/Services/Monitoring/Panels/ApisPanel.php:39 joining the Developer Portal manifest to monitoring_request_buckets (channel=api, :44); GET /admin/monitoring/apis |

## CONNECTED TO MONITOR (27)

Its health and its failures are visible to an operator.

| Capability | Area | Owner | Where it lives |
|---|---|---|---|
| Queue job outcomes measured at the worker — processed count, runtime and failures per queue | monitoring | System | app/Providers/MonitoringServiceProvider.php:127-169 (JobProcessing/JobProcessed/JobFailed listeners) → monitoring_series queue.processed\|{queue} and queue.failed\|{queue} |
| Marketplace incidents: acknowledge, assign and resolve an operational incident | monitoring | System | app/Services/Monitoring/Alerting/IncidentManager.php:70; app/Services/Monitoring/Panels/IncidentsPanel.php; section app/Services/Monitoring/MonitoringNavigation.php:26; routes/admin/routes.php:227-237 (GET only) |
| Monitoring operations centre (33 sections across situation, application, infrastructure, clients, business, operations) | monitoring | System | app/Http/Controllers/Admin/Telemetry/MonitoringController.php; app/Services/Monitoring/MonitoringNavigation.php:20-70; app/Services/Monitoring/Panels/*; routes/admin/routes.php:227-237; crons bootstrap/app.php:212-219; nav _side-bar.blade.php:875 |
| Endpoint health (which documented endpoints are actually being called and failing) | monitoring | Developer | app/Services/DeveloperPortal/EndpointHealthService.php; app/Services/Monitoring/Panels/ApisPanel.php:42; surfaced in the portal and the Monitoring apis section; app/Services/Monitoring/Panels/ApisPanel.php:39 / :100 data() / :743 deprecated() / :868 silent() / :923 unmatched(); rendered at resources/views/admin-views/monitoring/sections/apis.blade.php |
| Payment volume and outcome mix read from analytics events on the Monitoring page | finance | Admin | app/Services/Monitoring/Panels/PaymentsPanel.php:137 (source: analytics_events payment_started/succeeded/failed), queried at :731 and :645 |
| Integrations as a measured quantity (webhook delivery success, outbound calls to gateways and couriers) | integrations | Admin | 24-hour webhook counts at app/Services/Marketplace/SellerOperationsOverview.php:323 deliveryHealth (delivered/failed/pending), shown at admin/marketplace/seller-operations/integrations (routes/admin/routes.php:646); retries by seller:retry-webhooks (bootstrap/app.php:182). Outbound dependencies: app/Services/Monitoring/Panels/IntegrationsPanel.php:44-52 states that nothing writes monitoring_dependency_buckets. |
| Monitoring reads the trail back to report which security action families this deployment has ever written, and names the blind spots | monitoring | Admin | app/Services/Monitoring/Panels/SecurityPanel.php:796 auditVocabulary(); families checked against SECURITY_ACTION_PREFIXES at :76 (auth, role, permission, setting); rendered in resources/views/admin-views/monitoring/sections/security.blade.php |
| Per-endpoint live health: traffic, error rate, p95, last error, who still calls it, and whether it is safe to remove | monitoring | Admin | app/Services/DeveloperPortal/EndpointHealthService.php:33 forEndpoint() / :141 callers() / :198 removalSafety(); reads the Monitoring request buckets through App\Services\Monitoring\Support\SeriesReader |
| Endpoint lookup by path, so Monitoring can deep-link into an endpoint's documentation | monitoring | Admin | GET admin/developer/lookup (routes/admin/routes.php:194) via app/Http/Controllers/Admin/Telemetry/DeveloperPortalController.php:85; resolved by app/Services/DeveloperPortal/ApiManifest.php:146 findByPath() |
| Monitoring alert thresholds including how long an order may sit in one state before it is called stuck and the payment failure rate that warns | monitoring | Admin | config/monitoring.php:157-178 (thresholds map: stuck_order_hours 6, payment_failure_rate_warning 10.0, ssl_expiry_warning_days 21, backup_age_warning_hours 36, queue lag, p95, cpu/memory/disk); overridable per install via app/Services/Monitoring/Support/MonitoringSettings.php:53 threshold() and editable at app/Services/Monitoring/Panels/SettingsPanel.php:133-153 |
| The master clock: the server cron that runs every scheduled task on the platform | platform | System | bootstrap/app.php:135-248 (->withSchedule); requires the OS crontab entry `* * * * * cd <base> && php artisan schedule:run`. app/Console/Kernel.php:25-28 is an empty stub that says the schedule moved here. |
| Recording queue job throughput, runtime and failures from the worker | monitoring | System | app/Providers/MonitoringServiceProvider.php:127-169 (JobProcessing/JobProcessed/JobFailed listeners); series `queue.processed\|{queue}` and `queue.failed\|{queue}` in monitoring_series |
| Running the queue worker at all (no worker = bulk jobs, webhooks and queued mail never run) | platform | System | config/queue.php default connection; workers are started outside the app (supervisor/systemd). Worker presence is inferred in app/Services/Monitoring/Collectors/QueueCollector.php (workers_consuming, three-valued) and the driver is graded in app/Services/Monitoring/Panels/ApplicationPanel.php:416-420 |
| Recording every outbound HTTP call the platform makes (payment gateways, delivery partners, webhooks) | monitoring | System | app/Providers/MonitoringServiceProvider.php:185-217, Http::globalMiddleware -> App\Services\Monitoring\Ingest\DependencyRecorder |
| Collection heartbeat — drain the buffered minute and sample every gauge, once a minute | monitoring | System | app/Console/Commands/MonitoringFlush.php:27 (monitoring:flush); scheduled bootstrap/app.php:212 everyMinute withoutOverlapping runInBackground; app/Console/Commands/MonitoringFlush.php:29 `monitoring:flush`; scheduled bootstrap/app.php:212 every minute, runInBackground |
| Evaluate the monitoring alert rules against the latest measurements | monitoring | System | app/Console/Commands/MonitoringEvaluate.php:18 `monitoring:evaluate` (also seeds the shipped rules via --seed); scheduled bootstrap/app.php:216 every minute |
| Rollup and retention — minutes into hours into days, and pruning past each resolution's window | monitoring | System | app/Console/Commands/MonitoringRollup.php:23 (monitoring:rollup, --prune at :307,:337); scheduled bootstrap/app.php:218 hourlyAt(3) and :219 dailyAt 01:45; config/monitoring.php:73-81 retention; app/Console/Commands/MonitoringRollup.php:25 `monitoring:rollup`; scheduled bootstrap/app.php:218 hourly at :03 and bootstrap/app.php:219 `--prune` daily 01:45 |
| TLS certificate expiry watch | monitoring | Admin | app/Services/Monitoring/Collectors/SslCollector.php:37 and app/Services/Monitoring/Checks/SslCheck.php:18; threshold config/monitoring.php:175 ssl_expiry_warning_days 21 |
| Health and synthetic check runner — eight probes every five minutes, with history for uptime and MTTR | monitoring | System | app/Services/Monitoring/Checks/CheckRunner.php:23 (:26 the eight checks, :209 publishes check.up/check.duration_ms series); app/Console/Commands/MonitoringCheck.php:18; scheduled bootstrap/app.php:214 everyFiveMinutes → monitoring_check_results |
| Database liveness probe — round trip for select 1 | monitoring | System | app/Services/Monitoring/Checks/DatabaseCheck.php:17; thresholds db_latency_warning_ms/critical_ms config/monitoring.php:168-169 |
| Redis / cache liveness and latency probe | monitoring | System | app/Services/Monitoring/Checks/RedisCheck.php:17; thresholds config/monitoring.php:170-171 |
| Queue drain probe — is anything taking work off the queue | monitoring | System | app/Services/Monitoring/Checks/QueueCheck.php:17 (:64 fails outright on jobs reserved past retry_after) |
| Cron probe — has schedule:run fired, and did any task miss or fail its last run | monitoring | Admin | app/Services/Monitoring/Checks/SchedulerCheck.php:17 (:42 prints the exact crontab line when nothing has ever run) |
| Storage probe — disk space, inodes and whether the application's own storage directory is writable | monitoring | Admin | app/Services/Monitoring/Checks/StorageCheck.php:18; thresholds disk_warning/critical config/monitoring.php:162-163 |
| Backup age and restore-test probe — when a backup last succeeded and whether anyone has ever restored one | platform | Admin | app/Services/Monitoring/Checks/BackupCheck.php:21 reading monitoring_backups; threshold config/monitoring.php:176 backup_age_warning_hours 36 |
| Synthetic journey probe — fetch a real storefront page and assert its status and content | monitoring | Admin | app/Services/Monitoring/Checks/SyntheticCheck.php:25 reading the `synthetics` key of monitoring_settings; results into monitoring_check_results (kind=synthetic) |
| Alert rule evaluation — compare every enabled rule against the last minute, once a minute | monitoring | System | app/Services/Monitoring/Alerting/AlertEvaluator.php:43; app/Console/Commands/MonitoringEvaluate.php:16; scheduled bootstrap/app.php:216 everyMinute |

## INTERNAL BY DESIGN (52)

Infrastructure. No screen is appropriate, and the reason is stated.

**Presentation and query bounds that no operator would tune (search result ceiling, category tree depth, live-viewer refresh window, experience-health staleness, the 5% 'flat' band on the vendor dashboard)**  
`platform` · owner: System  
- Backend — app/Services/Search/ProductSearchService.php:32 (public const MAX_RESULTS = 500); index rebuilt weekly by bootstrap/app.php:192 (search:reindex-products, Sundays 04:00); app/Services/CategoryPageService.php:27 (private const MAX_TREE_DEPTH = 4); app/Services/Storefront/ProductPageSignalsService.php:21 (private const WINDOW = 600); on/off and range come from settings 'product_live_viewers_status' at :16; app/Services/Commerce/ExperienceHealth.php:177 (now()->subHours(26)) and :199 (now()->subMinutes(15)); metrics refreshed by bootstrap/app.php:234 (commerce:metrics-refresh hourlyAt 22) over app/Console/Commands/CommerceMetricsRefresh.php:33 (WINDOW_DAYS = 30) in chunks of 500 (:34); app/Services/Vendor/VendorDashboardStatsService.php:62 (if (abs($change) < 0.05))
- No surface on — Admin, Monitor, Dev Portal
- Internal: each is a rendering or query bound with no business meaning a marketplace operator would set. The tree depth is additionally bounded by the schema, which carries only three category columns, and the staleness tolerances are tied to refresh cadences in the same repo.

**Request-shaping guards — ingest rate limits, list page sizes, per-screen bulk-action caps, the report date-range ceiling and the automation rule-scope limit**  
`platform` · owner: System  
- Backend — config/analytics.php:144 (beacon rate_limit_per_minute 60), :145 (max_events_per_request 20), :129 (campaign click_rate_limit 120); applied at routes/web/routes.php:118 and :135 and routes/rest_api/v1/api.php:95; app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:297, SellerBulkJobController.php:226, SellerReturnController.php:336, SellerStatementController.php:134 (max(1, min(limit, 100))); SellerIntegrationController.php:413 and SellerFinanceControlController.php:128 (min(100,...)); SellerAutomationController.php:69 (min(50,...)); app/Services/SellerCenter/Lists/OrderList.php:189 (in_array($size, [25, 50, 100], true) ? $size : 25); the same pattern repeats in InventoryList, ProductList and IssueList pageSize(); app/Http/Controllers/Admin/Vendor/VendorController.php:60, app/Http/Controllers/Admin/Customer/CustomerController.php:48, app/Http/Controllers/Admin/Order/Orde
- Internal: these bound the size of a single request rather than expressing a marketplace policy, and each refuses loudly (a validation error or a clamped page size) rather than silently changing an answer. Worth naming only because the same cap is re-typed in seven controllers instead of shared, so raising one for an integration partner is seven edits.

**Storefront theme preview link lifetime (60 minutes, never more than 24 hours)**  
`platform` · owner: System  
- Backend — app/Services/Theme/ThemePreviewToken.php:27 (DEFAULT_MINUTES = 60), :30 (MAX_MINUTES = 1440); scheduled publishes at bootstrap/app.php:165 (theme:publish-due everyFiveMinutes)
- No surface on — Seller Web, Analytics, Monitor, Dev Portal
- Internal: a signed-token lifetime is a security bound rather than a merchandising setting, and a preview link that outlives a working session is the thing the ceiling exists to prevent. Worth noting only that an agency review longer than a day needs a fresh link.

**Retired theme-installer URL**  
`platform` · owner: Admin  
- Backend — routes/admin/routes.php:1079 Route::redirect('theme/setup', '/admin/theme') named admin.system-setup.theme.setup
- No surface on — Admin, Seller Web, Flutter App, Analytics, Monitor
- Internal: a one-line Route::redirect kept so old bookmarks land on the current theme screen. The zip-upload installer it replaced is gone and the redirect is the deliberate compatibility shim, not a leftover.

**Wipe the database and reimport demo data**  
`platform` · owner: Developer  
- Backend — app/Console/Commands/DatabaseRefresh.php:23 `database:refresh`; NOT scheduled anywhere in bootstrap/app.php despite the description 'Refresh database after a certain time'; guarded by config('app.mode')==='demo' at DatabaseRefresh.php:50-54; pairs with App\Http\Middleware\DatabaseRefreshMiddleware (bootstrap/app.php:50) which 503s the site while the cache key `demo_database_refresh` is set
- No surface on — Admin, Seller Web, Analytics, Monitor
- Internal: a destructive demo-platform reset that a marketplace operator must never reach from a screen. Worth one note — its description implies a schedule that does not exist, and the 503 middleware that serves it runs globally on every production request for a command production can never run.

**Repair a translation file that no longer parses**  
`platform` · owner: Developer  
- Backend — app/Console/Commands/RepairLanguageFiles.php:18 `lang:repair`; repairs resources/lang/{locale}/new-messages.php
- No surface on — Admin, Seller Web, Analytics, Monitor
- Internal: the failure it repairs makes every page in the application fatal at once, including the admin panel a button would live on, so a screen for it could not be reached when it was needed. The real gap is discoverability — an operator can only learn the command exists by reading the source.

**Health checks (database, redis, queue, storage, ssl, backup, scheduler, synthetic)**  
`monitoring` · owner: System  
- Backend — app/Services/Monitoring/Checks/*; app/Console/Commands/MonitoringCheck.php; cron bootstrap/app.php:214; app/Console/Commands/MonitoringCheck.php:20 `monitoring:check`; scheduled bootstrap/app.php:214 every 5 min; runners in app/Services/Monitoring/Checks/
- No surface on — Seller Web, Analytics, Dev Portal
- Infrastructure probes an operator reads on the Monitoring overview but would never configure by hand; they take no arguments and have no settings screen.

**Sidebar pin shortcuts (per-admin, server-side)**  
`platform` · owner: Admin  
- Backend — app/Http/Controllers/Admin/V2SidebarPinController.php; routes/admin/routes.php:132-136 admin.v2.sidebar-pins.*; toggle/replace wired at resources/views/layouts/admin/partials/v2/_body.blade.php:106-107
- No surface on — Analytics, Monitor
- Per-user navigation preference; the GET index at :133 is never called by the front end, which reads pins from the rendered sidebar instead.

**Kohl design-system gallery**  
`platform` · owner: Developer  
- Backend — routes/admin/routes.php:174 admin.design-system -> admin-views.kohl.gallery; referenced by no menu and no view
- No surface on — Seller Web, Flutter App, Analytics, Monitor
- A component gallery for reviewing the design system inside the real admin shell; deliberately unlinked, but nothing marks it as internal so a stray link would expose it.

**Component and component-snippet demo pages**  
`platform` · owner: Developer  
- Backend — routes/admin/routes.php:138 admin/component and :141 admin/component-snippets — closures returning layouts.admin.component views, with no route name and no reference anywhere
- No surface on — Seller Web, Flutter App, Analytics, Monitor
- Developer scratch pages left mounted on the production admin prefix; they carry no route name, so nothing can link to them and nothing can revoke them either.

**Auction time adjustment (demo mode only)**  
`platform` · owner: Developer  
- Backend — routes/admin/routes.php:1709-1731 — registered only when config('app.mode') == 'demo' and the Auction addon is published; inline closures, no controller
- No surface on — Seller Web, Flutter App, Analytics, Monitor
- A demo-seeding convenience that edits auction start and end times; it does not exist in a production deployment.

**Being told about a problem — aggregated push and mail when insights, SLA breaches or scorecard changes land**  
`notifications` · owner: System  
- Backend — app/Services/SellerIntelligence/SellerNotifier.php:35-80 — one message per fact per (seller, topic, 12h window); driven by commands seller:refresh-insights and seller:escalate-issues (bootstrap/app.php:160,170)
- No surface on — Admin, Seller Web, Analytics, Dev Portal
- The dispatch mechanism itself is plumbing an operator should not tune per seller — but see the next record: nothing lets a seller or an admin choose what gets sent.

**Wave-1 acceptance screen for the Seller Center component library**  
`platform` · owner: Developer  
- Backend — routes/seller/routes.php:113 — GET vendor/foundation; app/Http/Controllers/Seller/FoundationController.php
- No surface on — Admin, Analytics, Monitor, Dev Portal
- A debug-only gate screen deliberately kept out of the navigation; a marketplace operator would never need it, but it is a live route inside the seller panel.

**V2 sidebar pin shortcuts, persisted per seller**  
`platform` · owner: Seller  
- Backend — routes/vendor/routes.php:93-97 — GET/POST vendor/v2/sidebar-pins, /toggle, /replace; app/Http/Controllers/Vendor/V2SidebarPinController.php
- No surface on — Admin, Analytics, Monitor, Dev Portal
- Per-seller UI convenience with no business meaning; nothing for an operator to oversee.

**One door for recording behaviour, so every controller/service spells an event the same way**  
`analytics` · owner: Developer  
- Backend — app/Services/Analytics/Analytics.php:21-418 (pageViewed:30, productViewed:38, searchPerformed:86, cartAdded:103, checkoutStep:129, orderPlaced:192, payoutRequested:363, kycSubmitted:378)
- No surface on — Admin, Seller Web, Monitor, Dev Portal
- A code-level API a marketplace operator never touches; its output is what they see on the Analytics screens.

**Events buffered in memory and written once after the response, so analytics can never slow or fail a sale**  
`analytics` · owner: System  
- Backend — app/Services/Analytics/EventRecorder.php:58 (record) and :125 (flush, called from terminate); config/analytics.php:29 buffer_limit=40
- No surface on — Admin, Seller Web, Monitor, Dev Portal
- Infrastructure: the write path itself has no operator control, only the master switch ANALYTICS_ENABLED.

**Collapsing the same act recorded twice (double-clicked button, reloaded confirmation page)**  
`analytics` · owner: System  
- Backend — app/Services/Analytics/EventRecorder.php:206-231 dedupeKey(); config/analytics.php:46 dedupe_window_seconds=5; unique index on analytics_events.dedupe_key
- No surface on — Admin, Seller Web, Monitor, Dev Portal
- Correctness machinery; money events carry explicit keys (order:{id}, payment:{id}:{gateway}:{outcome}) so revenue cannot double-count.

**Stripping passwords, OTPs, tokens and card numbers out of anything an instrumentation call attaches to an event**  
`security` · owner: System  
- Backend — app/Services/Analytics/EventRecorder.php:237-248 properties() reusing App\Services\Monitoring\Support\Redactor
- No surface on — Seller Web, Analytics, Monitor, Dev Portal
- Stated to the operator as a sentence on the settings screen (settings.blade.php:41) but not configurable, which is correct for a redactor.

**Retention and rotation of the audit trail itself**  
`security` · owner: System  
- Backend — None found. audit_logs is created by database/migrations/2026_08_09_500001_create_audit_logs_table.php:34 with indexes on subject, actor, action and created_at (:68-71) and no pruning; app/Services/Retention/ contains only AbandonedCartService.php and ReviewEligibilityService.php; grep of app/ for audit_logs finds no scheduled command or job
- No surface on — Admin, Seller Web, Analytics, Monitor, Dev Portal
- An append-only trail with no expiry is the right default for an audit log — a marketplace operator should not be able to prune it — but it grows without bound and nothing monitors its size.

**Scoping the platform-wide trail to one shop, which is done with a LIKE over an unindexed JSON column**  
`monitoring` · owner: System  
- Backend — app/Services/Marketplace/SellerAuditTrailService.php:72-76 matches context LIKE '%"seller_id":N,%' / '...}%'; audit_logs has no index on context (database/migrations/2026_08_09_500001_create_audit_logs_table.php:68-71 index subject, actor, action, created_at only); bounded by MAX_ROWS = 200 at SellerAuditTrailService.php:30
- No surface on — Admin, Seller Web, Analytics, Monitor
- The row cap keeps it safe today, but every seller opening their security screen runs a full scan of the platform's entire audit history — this degrades as the trail grows and nothing watches for it.

**`php artisan api:manifest` — build and inspect the normalised API manifest from the command line**  
`platform` · owner: Developer  
- Backend — app/Console/Commands/ApiManifestCommand.php:17 (signature `api:manifest`)
- No surface on — Admin, Seller Web, Analytics, Monitor
- A developer's inspection tool for the same object the portal renders; a marketplace operator has the Rebuild button instead and never needs the CLI.

**Ledger and reconciliation treat differences under one cent as agreement**  
`finance` · owner: System  
- Backend — app/Services/Marketplace/ReconciliationService.php:26 and app/Services/Marketplace/SellerReconciliationService.php:33 (const EPSILON = 0.01); app/Services/Monitoring/Panels/PaymentsPanel.php:95 (MONEY_TOLERANCE) and OrderIntegrityPanel.php:120 (AMOUNT_TOLERANCE) repeat it
- No surface on — Analytics
- Belongs in System Infrastructure — one cent is the display precision of the money column, not a business choice, and an operator would never need to move it; the duplication across four files is a consistency risk, not a configuration gap.

**Seller API credentials: 6-character public prefix and 40-character secret**  
`security` · owner: Seller  
- Backend — app/Services/Marketplace/SellerApiKeyService.php:28 (PREFIX_LENGTH = 6), :29 (SECRET_LENGTH = 40); minimum accepted token length at app/Http/Middleware/SellerApiAuthMiddleware.php:16 (MINIMUM_TOKEN_LENGTH = 30)
- No surface on — Analytics, Monitor
- Belongs in System Infrastructure — credential entropy is a cryptographic parameter, not a business rule, and a marketplace operator has no reason to tune it.

**Delivery Syria courier API request timeouts of 20 seconds total and 10 seconds to connect**  
`shipping` · owner: System  
- Backend — app/Services/DeliverySyria/DeliverySyriaClient.php:27 (TIMEOUT = 20), :29 (CONNECT_TIMEOUT = 10); status codes app/Services/DeliverySyria/DeliverySyriaStatus.php:21-27
- No surface on — Seller Web, Flutter App, Analytics, Dev Portal
- Belongs in System Infrastructure — HTTP timeouts against one named carrier are a resilience setting rather than a business policy, and the carrier's credentials and rates are already admin-managed.

**Frequently-bought-together suggestions are cached for six hours**  
`catalog` · owner: System  
- Backend — app/Services/Storefront/BoughtTogetherService.php:26 (private const CACHE_TTL = 21600)
- No surface on — Admin, Seller Web, Analytics, Monitor, Dev Portal
- Belongs in System Infrastructure — a cache lifetime chosen against how slowly co-purchase patterns move, with no customer-visible policy meaning an operator would need to retune.

**Theme structure limits: 200 sections per theme, 24 blocks per section, 24 picked resources, 12 items per content bundle**  
`platform` · owner: Admin  
- Backend — app/Services/Theme/ThemePortabilityService.php:29 (MAX_SECTIONS = 200); app/Services/Theme/SectionRegistry.php:20 (MAX_PICKED_RESOURCES = 24) and :26 (MAX_BLOCKS_PER_SECTION = 24); app/Services/Theme/SectionDataResolver.php:27 (BUNDLE_LIMIT = 12); app/Services/Theme/ContentSource.php:45 (MAX_LIMIT = 24)
- No surface on — Seller Web, Analytics, Monitor, Dev Portal
- Belongs in System Infrastructure — render-cost guards on a page builder that an operator would experience as page-weight limits rather than as business policy.

**Percentage discounts on clearance products and coupons must be between 1 and 100**  
`pricing` · owner: Admin  
- Backend — app/Services/StockClearanceProductService.php:20 and :60 (discount_amount < 1 || > 100); app/Services/CouponService.php:125 (percentage discount >= 100 refused)
- No surface on — Analytics, Monitor, Dev Portal
- Belongs in System Infrastructure — arithmetic validity for a percentage rather than a pricing policy, though a maximum-discount policy (say, refuse anything over 70 percent without approval) is a real gap that neither of these provides.

**Seller Center badge counts are cached for 60 seconds and search returns 5 results per group**  
`platform` · owner: Seller  
- Backend — app/Services/SellerCenter/Counts.php:24 (private const TTL_SECONDS = 60); app/Services/SellerCenter/Search.php:23 (private const PER_GROUP = 5)
- No surface on — Admin, Analytics, Monitor, Dev Portal
- Belongs in System Infrastructure — a cache lifetime and a result-grouping choice that carry no business commitment and no operator would need to change.

**Campaign short codes are seven characters long**  
`analytics` · owner: Admin  
- Backend — app/Services/Analytics/CampaignService.php:28 (private const CODE_LENGTH = 7); campaign path and allowed hosts are configurable at config/analytics.php:126-127
- No surface on — Seller Web, Monitor, Dev Portal
- Belongs in System Infrastructure — a collision-space choice for generated short links, with the operator-facing parts of campaign links (path, allowed hosts) already configurable.

**Monitoring, analytics and telemetry rollups and pruning run on fixed times of day**  
`monitoring` · owner: System  
- Backend — bootstrap/app.php:212-247 (monitoring:flush everyMinute, monitoring:check everyFiveMinutes, monitoring:rollup hourlyAt(3) and --prune dailyAt 01:45, analytics:rollup hourlyAt(12) and --prune dailyAt 02:15, telemetry:rollup hourlyAt(7) and --prune dailyAt 01:30); heartbeat at :197-202
- No surface on — Seller Web
- Belongs in System Infrastructure — housekeeping cadences deliberately staggered across the hour, which an operator would never need to move; only the business-facing schedules in this same file (settlement, SLA, abandoned cart) are the orphans.

**Scheduler heartbeat — proof that the server cron is still firing**  
`monitoring` · owner: System  
- Backend — business_settings `scheduler_last_run_at`, written every five minutes at bootstrap/app.php:197-202 and read at app/Services/Telemetry/SystemHealthService.php:83; bootstrap/app.php:197-202, closure named 'scheduler-heartbeat', every 5 min; setting key `scheduler_last_run_at` in business_settings
- No surface on — Seller Web, Analytics
- A timestamp the system writes to itself so the dashboard can warn when settlements would silently stop maturing; editing it by hand would only defeat the check.

**Correlation id on every log line written during a request**  
`monitoring` · owner: Developer  
- Backend — app/Providers/MonitoringServiceProvider.php:226-244 (Log::shareContext with correlation_id/request_id from RequestContext); app/Providers/MonitoringServiceProvider.php:226-244
- No surface on — Seller Web, Analytics, Monitor
- Plumbing that makes the Logs section pivotable by request; no operator ever configures it.

**Rebuild the API manifest the Developer Portal reads**  
`integrations` · owner: Developer  
- Backend — app/Console/Commands/ApiManifestCommand.php:17 `api:manifest`; not scheduled, not called from any controller
- No surface on — Admin, Seller Web, Analytics, Monitor
- A deploy-time cache warmer only - the manifest rebuilds itself when the route table changes (ApiManifestCommand.php:11-13), so nothing breaks if it is never run.

**Find admin roles whose module_access JSON still holds pre-rename permission keys**  
`security` · owner: Developer  
- Backend — app/Console/Commands/AuditAdminRoleModuleKeys.php:10 `admin-roles:audit-module-keys`; not scheduled, but auto-run with --fix during software update at app/Traits/UpdateClass.php:763
- No surface on — Admin, Seller Web, Analytics, Monitor
- A one-off migration repair that the updater already fires; an operator whose staff lost menu access after an upgrade has no admin screen that would tell them why.

**Static audit of every Blade template for RTL, i18n, accessibility and layout defects**  
`platform` · owner: Developer  
- Backend — app/Console/Commands/AuditUiConsistency.php:24 `audit:ui`; not scheduled, not referenced by any controller or view
- No surface on — Admin, Seller Web, Analytics, Monitor, Dev Portal
- A CI gate for developers (exits non-zero on errors); a marketplace operator has no reason to run it and no screen would show its output.

**Create a minimal local test schema so the app boots without the proprietary SQL dump**  
`platform` · owner: Developer  
- Backend — app/Console/Commands/BootstrapTestSchema.php:31 `dev:bootstrap-test-schema`; refuses outside local/testing
- No surface on — Admin, Seller Web, Analytics, Monitor, Dev Portal
- Developer/CI tooling that is environment-gated; it must never appear on a production surface.

**Dump every registered admin GET route to JSON with its Blade path and keywords**  
`platform` · owner: Developer  
- Backend — app/Console/Commands/GenerateAdminRoutesJson.php:19 `generate:admin-routes-json`; not scheduled; output feeds routes.json in the repo root
- No surface on — Admin, Seller Web, Analytics, Monitor, Dev Portal
- A build-time artefact generator for the admin search index; nothing an operator configures.

**Scaffold a model, repository interface and implementation for a new entity**  
`platform` · owner: Developer  
- Backend — app/Console/Commands/GenerateEntity.php:11 `generate:entity {entity}`; documented in CLAUDE.md
- No surface on — Admin, Seller Web, Analytics, Monitor, Dev Portal
- Code generator for developers; has no runtime effect on a running marketplace.

**Build the CodeCanyon installable ZIP (strips non-default themes and the Auction/Gateways modules)**  
`platform` · owner: Developer  
- Backend — app/Console/Commands/InstallablePackage.php:19 `prepare:installable`
- No surface on — Admin, Seller Web, Analytics, Monitor, Dev Portal
- Release packaging that deletes directories from the working tree; running it on a live install would remove modules, so it must stay off every operator surface.

**Swap in the activation/update RouteServiceProvider before packaging**  
`platform` · owner: Developer  
- Backend — app/Console/Commands/PrepareDatabase.php:15 `prepare:database` (description is the unedited stub 'Command description'); copies installation/activate_update_routes.txt over app/Providers/RouteServiceProvider.php
- No surface on — Admin, Seller Web, Analytics, Monitor, Dev Portal
- Release-packaging step that overwrites a source file; misnamed (it touches no database) and must never be exposed to an operator.

**Build the update-only package (strips non-default themes and the Gateways module)**  
`platform` · owner: Developer  
- Backend — app/Console/Commands/UpdatePackage.php:16 `prepare:updatable` (description is the unedited stub 'Command description')
- No surface on — Admin, Seller Web, Analytics, Monitor, Dev Portal
- Release packaging that deletes directories from the working tree; destructive on a live install and correctly absent from every operator surface.

**Release automation's claim on a listing as soon as a human changes its visibility**  
`automation` · owner: System  
- Backend — app/Observers/AutomationClaimObserver.php:24; registered app/Providers/ObserverServiceProvider.php:42
- No surface on — Admin, Analytics, Monitor
- A correctness guard on the automation revert trail - it stops a months-old rule re-publishing something the seller deliberately hid - with nothing for an operator to configure.

**Per-request telemetry folded into one-minute buckets per route (hits, errors, latency histogram, DB time)**  
`monitoring` · owner: System  
- Backend — app/Http/Middleware/MonitorRequest.php:23 (global + web + api groups, bootstrap/app.php:58,78,88); app/Services/Monitoring/Ingest/RequestRecorder.php:25 → monitoring_request_buckets
- No surface on — Seller Web, Dev Portal
- Infrastructure — a marketplace operator never touches it; registered globally so 404s and unmatched routes are measured too.

**In-flight metric buffer — Redis or cache, so a web request never writes a monitoring row**  
`monitoring` · owner: System  
- Backend — app/Services/Monitoring/Ingest/MetricSink.php:35; config/monitoring.php:41 MONITORING_BUFFER (auto|redis|cache|none)
- No surface on — Seller Web
- Infrastructure; its driver and description are surfaced in self-health so 'buffer=none' cannot silently disable collection unnoticed.

**Redaction — secrets, tokens and customer values stripped before anything monitoring stores**  
`security` · owner: System  
- Backend — app/Services/Monitoring/Support/Redactor.php:1; config/monitoring.php:118-122 privacy (mask_ip, store_user_id, extra_redacted_keys); applied in EventLog.php:80, DependencyRecorder.php:241, MonitoringServiceProvider.php:101
- No surface on — Seller Web, Monitor
- Built-in secret names cannot be switched off; only the additive extra_redacted_keys list is configurable, and only from config.

**Cardinality guard — a URL with ids in it cannot explode the metrics tables**  
`monitoring` · owner: System  
- Backend — app/Services/Monitoring/Ingest/BucketWriter.php:94-208 (fold to __other__); config/monitoring.php:64 max_series_per_minute 400
- No surface on — Seller Web, Monitor
- Infrastructure safety valve; surfaced only as a number on the Settings page.

**Monitoring regression tests**  
`monitoring` · owner: Developer  
- Backend — tests/Feature/Monitoring/ — AlertEvaluationTest, AlertHistoryDiscrepancyTest, CardinalityCapTest, CheckRunnerTest, FoldSeamTest, HistogramTest, MobileAppSectionTest, MonitoringPermissionTest, PanelQueryStringTest, RedactorTest, SeriesCounterTest
- No surface on — Admin, Seller Web
- Engineering safety net an operator never touches; notably there is no test asserting that anything writes monitoring_error_groups, which is why the missing exception reporter survived.

**Developer Portal API console — whether admins may fire real requests at the live API, and whether write verbs are permitted**  
`platform` · owner: Developer  
- Backend — config/developer_portal.php:47-51 `console.enabled` (DEVELOPER_CONSOLE_ENABLED), `console.allow_writes` (DEVELOPER_CONSOLE_ALLOW_WRITES, default false), `console.rate_limit_per_minute`; enforced by app/Services/DeveloperPortal/ConsoleGuard.php, invoked from routes/admin/routes.php:199 POST developer/try/{id}
- No surface on — Seller Web, Analytics, Monitor
- Deliberately env-only and off by default — the config comment argues a switch that lets an admin panel POST against the live order system should require someone who knows which installation they are on, and ApiConsole.php:41 audits every attempt, so the absence of a screen is the control rather than a gap.

**Developer Portal response-shape learning — recording the keys and types real API responses return**  
`platform` · owner: Developer  
- Backend — config/developer_portal.php:22 `record_response_shapes` (DEVELOPER_PORTAL_RECORD_RESPONSES, default true); implemented by app/Services/DeveloperPortal/ResponseShapeRecorder.php
- No surface on — Admin, Seller Web, Analytics, Monitor
- Only keys and types are stored, never values, and it runs after the response is sent — a marketplace operator has no decision to make here, so no screen is warranted.

**Payment-gateway add-on presence — decides whether the platform uses the Modules/Gateways SMS and payment implementations or the built-in ones**  
`integrations` · owner: System  
- Backend — config('get_payment_publish_status') set at boot in app/Providers/AppServiceProvider.php:168 from app/Traits/AddonHelper.php:64 (scans Modules/Gateways/Addon/info.php); branched on at app/Utils/SMSModule.php:14, app/Utils/Helpers.php:827, app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php:45
- No surface on — Seller Web, Analytics
- Derived from what is on disk rather than from a stored value, which is right for a module-presence check — but note Modules/Gateways is absent here while app/Utils/SMSModule.php:8 and app/Http/Controllers/RestAPI/v1/auth/PhoneVerificationController.php:15 still import Modules\Gateways\Traits\SmsGateway, so restoring the add-on directory without its classes would fatal at SMSModule.php:15.

**Setup-guide progress checklist shown to admins and vendors**  
`platform` · owner: System  
- Backend — business_settings `setup_guide_requirements_for_admin`, read at app/Utils/panel-helpers.php:35,:83 and written by the system at :90-96; seeded by app/Traits/UpdateClass.php:920; vendor side updated from app/Http/Controllers/Vendor/ShopController.php:177
- No surface on — Analytics, Monitor, Dev Portal
- A progress marker the application ticks off as tasks are completed, not a policy anyone would want to edit — a screen would only let someone lie to themselves about setup state.

**Settings cache — the three-hour business_settings cache and the invalidation map behind every settings save**  
`platform` · owner: System  
- Backend — app/Utils/settings.php:26 Cache::remember(CACHE_BUSINESS_SETTINGS_TABLE, CACHE_FOR_3_HOURS), :54 clearWebConfigCacheKeys(), :433-546 cacheRemoveByType(); constants at app/Library/Constant.php:1131-1184; model hooks at app/Models/BusinessSetting.php:25,:29
- No surface on — Seller Web, Analytics
- Admin → System Setup → Optimize System can flush it (EnvironmentSettingsController.php:71), which is all an operator needs; the TTLs and key map are infrastructure. Worth flagging that several screens write settings without calling cacheRemoveByType, so a saved value can appear not to have taken for up to three hours.

## DEPRECATED (19)

Present in code, no longer part of the product.

**routes/shared.php and routes/test.php — route files no provider ever loads**  
`platform` · owner: Developer  
- Backend — routes/shared.php, routes/test.php present on disk; app/Providers/RouteServiceProvider.php:44-60 loads admin, vendor, seller, web, api v1/v2/v3, delivery-syria and the beta groups and never these two
- No surface on — Admin, Seller Web, Analytics, Monitor
- Dead: no provider maps either file, so nothing they declare is ever registered. Evidence is the complete map() list in RouteServiceProvider against the route table.

**44 AI auto-fill routes serving the Auction module, which is not installed**  
`catalog` · owner: Developer  
- Backend — Modules/AI/routes/admin/routes.php:34-42, Modules/AI/routes/vendor/routes.php:31-39, Modules/AI/routes/web.php:24-32 and Modules/AI/routes/api.php register 44 auction/* endpoints (verified in the route table); Modules/Auction is absent from Modules/ (only AI, Blog, TaxModule exist) and modules_statuses.json marks "Auction": false
- No surface on — Admin, Seller Web, Flutter App, Analytics, Monitor
- Dead: live authenticated endpoints across admin, vendor, customer and API namespaces for a module whose PHP directory does not exist and whose status flag is false; nothing can reach them and admin/auction-time still references \Modules\Auction\app\Models\AuctionProduct.

**Vendor\PaymentInformationController — a payment-details controller with no route**  
`finance` · owner: Developer  
- Backend — app/Http/Controllers/Vendor/PaymentInformationController.php:25 — class exists; grep across routes/ and app/ finds no reference other than its own declaration (the routed controller is VendorPaymentInfoController)
- No surface on — Admin, Seller Web, Flutter App, Analytics, Monitor, Dev Portal
- Dead: absent from the route table and referenced nowhere except its own declaration, shadowing the live VendorPaymentInfoController on a money-handling path — exactly the duplicate a later edit lands in by mistake.

**Whether a shop runs multi-warehouse stock and batch/expiry tracking**  
`inventory` · owner: Admin  
- Backend — app/Http/View/Composers/SellerCenterComposer.php:65 reads business_settings key `seller_warehouses_enabled` via getWebConfig(); consumed as the `warehouses_enabled` flag by app/Services/SellerCenter/Navigation.php:88 and :225; app/Http/View/Composers/SellerCenterComposer.php:66 reads business_settings key `seller_batches_enabled` via getWebConfig(); returned in the flags array passed to app/Services/SellerCenter/Navigation.php:41
- No surface on — Admin, Analytics, Monitor, Dev Portal
- OVERRULED — the settings sweep recorded seller_warehouses_enabled and seller_batches_enabled as unsettable orphan flags; both keys are now dead. A repo-wide grep finds them only inside a doc comment at app/Services/SellerCenter/ModuleFlags.php:14, and the question is answered from the shop's own data instead (ModuleFlags::hasWarehouses/hasBatches, used by SellerCenterComposer and by the seller API), which also removed the old disagreement between web and phone.

**Saving an analytics report configuration to come back to**  
`analytics` · owner: Admin  
- Backend — database/migrations/2026_08_25_000001_create_analytics_tables.php:287 creates analytics_saved_reports
- No surface on — Admin, Seller Web, Flutter App, Analytics, Monitor, Dev Portal
- Dead: analytics_saved_reports is created and dropped by the migration and referenced by no model, service, controller or view — verified by grep, the only three hits are inside the migration itself. A schema with no code; drop the table or build the screen.

**The Integrations page's statement about what outbound instrumentation exists**  
`monitoring` · owner: Developer  
- Backend — app/Services/Monitoring/Panels/IntegrationsPanel.php:1602 unmeasured(), returned unconditionally from data() at :304; contradicted by app/Providers/MonitoringServiceProvider.php:185-217, DependencyRecorder.php:89, BucketWriter.php:336-338 and MetricResolver.php:48-53
- No surface on — Seller Web, Monitor
- Dead text: unmeasured() is returned unconditionally and permanently tells the operator 'nothing records outbound calls — register an Http::globalMiddleware' and 'no alert rule can be written against a dependency', when MonitoringServiceProvider registers exactly that middleware, DependencyRecorder writes the series and MetricResolver resolves dependency.* metrics. This is the page an operator opens during a gateway or courier outage, and it lies about itself.

**Legacy single-page monitoring dashboard**  
`monitoring` · owner: Developer  
- Backend — resources/views/admin-views/telemetry/monitoring.blade.php (extends layouts.admin.app, wires itself to route('admin.monitoring.pulse'))
- No surface on — Admin, Seller Web
- Dead: superseded by the 33-section console; no controller renders the blade — MonitoringController returns admin-views.monitoring.index — so it is unreachable markup wired to a route it no longer owns.

**Email the seller that an order arrived for them**  
`notifications` · owner: Developer  
- Backend — app/Listeners/OrderReceivedNotifySellerListener.php:8, handle(OrderReceivedNotifySellerEvent) at line 21; NOT queued
- No surface on — Admin, Seller Web, Analytics, Monitor
- Dead: OrderReceivedNotifySellerEvent has no dispatch site anywhere in app/ or Modules/ and the listener is absent from the (already disabled) $listen map, so the listener is reachable only through auto-discovery of an event nothing raises.

**SendEmailJob — a queued mail job nothing dispatches**  
`notifications` · owner: Developer  
- Backend — app/Jobs/SendEmailJob.php:13; no tries/timeout/backoff and no failed() handler
- No surface on — Admin, Seller Web, Analytics
- Dead: no dispatch site exists in app/ or Modules/; queued mail actually goes through the ShouldQueue listeners, so the class is dead weight that would run with worker-default one-attempt semantics and no failed() handler if anything ever used it.

**Seller mobile API v2 — the previous seller app's entire surface, still routed**  
`integrations` · owner: Developer  
- Backend — routes/rest_api/v2/api.php:22 (header literally reads 'Old Seller Mobile APP API Routes') / :27; app/Http/Controllers/RestAPI/v2/seller/SellerController.php:27, ProductController.php:23, OrderController.php:30
- No surface on — Admin, Seller Web, Flutter App, Analytics
- Dead in practice: 95 live endpoints under a route-file header that literally reads 'Old Seller Mobile APP API Routes', zero documented, zero declared deprecated, and verified here — a grep of the Flutter seller app finds no v2 reference at all, so nothing this marketplace ships still calls it while it keeps the same data access as v3.

**ShareThis social sharing on the product detail page**  
`integrations` · owner: Admin  
- Backend — business_settings key `sharethis_property_id`, read at resources/themes/default/web-views/products/details.blade.php:1021 and used to build the script tag at :1027
- No surface on — Admin, Seller Web, Analytics, Monitor
- Dead: the sharethis_property_id key's only reference in the entire application is one blade read, so the widget is permanently off — while the quick-view partial beside it still hard-codes somebody else's ShareThis property id into the page.

**Legacy per-gateway SMS credential editor (Nexmo and friends)**  
`integrations` · owner: Developer  
- Backend — app/Http/Controllers/Admin/SmsGatewayController.php:9 — the only controller under app/Http/Controllers/Admin with no route in the route table, and no reference anywhere in app/, routes/ or Modules/
- No surface on — Admin, Seller Web, Flutter App, Analytics, Monitor, Dev Portal
- Dead: verified as the one controller under app/Http/Controllers/Admin with no route in the table and no reference anywhere, superseded by SMSModuleController — but it still contains live business_settings writes, so wiring it up by accident would create a second unaudited path to gateway configuration.

**Legacy v1 advanced search**  
`platform` · owner: Admin  
- Backend — app/Http/Controllers/Admin/AdvancedSearchController.php; routes/admin/routes.php:145-148 admin.advanced-search — referenced by no blade, no JS and no menu
- No surface on — Admin, Seller Web, Flutter App, Analytics, Monitor
- Dead: superseded by the v2 controller registered four lines below it and referenced by no blade, JS or menu; the route still resolves and still renders, which is the only reason to remove it deliberately rather than leave it.

**Auction feature — master switch, commission, entry fee, claim window, visibility durations and the per-seller permission**  
`platform` · owner: Admin  
- Backend — app/Http/Controllers/Admin/Settings/VendorSettingsController.php:43-54 — setting key active_auction_for_vendor, gated on auction_feature_status; business_settings keys `auction_feature_status`, `auction_commission_percentage`, `auction_entry_fee_amount_status`/`_value`, `auction_winner_claim_time_limit`, `auction_upcoming_visibility_duration`, `auction_expired_visibility_duration`, `auction_home_page_setup`, `auction_home_bg_image`, `auction_commitments` — read at app/Http/Controllers/RestAPI/v1/ConfigController.php:247-256, app/Utils/settings.php:16, app/Http/Controllers/Web/HomeController.php:74, app/Services/SEOSettingsService.php:31; seeded by app/Traits/UpdateClass.php:847-864
- No surface on — Seller Web, Analytics, Monitor
- Dead: verified that Modules/ contains only AI, Blog and TaxModule and modules_statuses.json marks Auction false, while core code still reads all nine settings keys and publishes them in the public /api/v1/config payload. The module that owned their screens is gone, so the two surviving toggles permanently refuse to switch on because they check auction_feature_status, which nothing can now set, and admin/auction-time still references \Modules\Auction\app\Models\AuctionProduct.

**Event-to-listener wiring for every notification and email on the platform**  
`platform` · owner: Developer  
- Backend — app/Providers/EventServiceProvider.php:60-130 declares 23 event->listener pairs but is COMMENTED OUT at bootstrap/providers.php:7; wiring actually comes from Laravel 12 auto-discovery of app/Listeners (Application::configure() calls withEvents() - vendor/laravel/framework/src/Illuminate/Foundation/Application.php:243, discovery default true at vendor/laravel/framework/src/Illuminate/Foundation/Support/Providers/EventServiceProvider.php:41,166-171)
- No surface on — Admin, Seller Web, Analytics, Monitor
- Dead: App\Providers\EventServiceProvider is commented out at bootstrap/providers.php:7 (verified), so its 23-pair $listen map governs nothing and all wiring comes from Laravel 12 auto-discovery keyed on each listener's handle() type-hint. The map is stale documentation that reads as configuration — which is exactly how the order-edit due-payment listener came to be bound to the wrong event before it was fixed.

**Cache invalidation when a business setting, a currency or a translation is written**  
`platform` · owner: Developer  
- Backend — app/Observers/BusinessSettingsObserver.php:7 - NOT registered: no ::observe() call and no #[ObservedBy] attribute anywhere in app/ or Modules/; app/Observers/CurrencyObserver.php:7 - NOT registered anywhere (no ::observe, no #[ObservedBy]); app/Observers/TranslationObserver.php:7 - NOT registered anywhere
- No surface on — Admin, Seller Web, Analytics, Monitor
- Dead files: BusinessSettingsObserver, CurrencyObserver and TranslationObserver are registered nowhere — no ::observe() call and no #[ObservedBy] attribute anywhere in app/ or Modules/ — so none of them ever runs. Controllers compensate with hand-written cache()->flush() calls, which is precisely the drift the observers were written to prevent: anything that writes a setting without that manual call serves stale config.

**Order and Product model lifecycle hooks**  
`platform` · owner: Developer  
- Backend — app/Observers/OrderObserver.php:8 - NOT registered, and every method body is commented out (lines 16, 24); app/Observers/ProductObserver.php:7 - NOT registered, every method body commented out (lines 14, 22, 30, 38, 46)
- No surface on — Admin, Seller Web, Analytics, Monitor
- Dead: neither observer is registered and every method body in both is commented out; the four observers actually registered on Product supersede them. Safe to delete.

**Reset local development settings after a database refresh**  
`platform` · owner: Developer  
- Backend — app/Console/Commands/LocalDatabaseRefresh.php:16 `local:database-refresh`; called only by DatabaseRefresh.php:71
- No surface on — Admin, Seller Web, Analytics, Monitor, Dev Portal
- Dead: its only caller guards on config('app.mode') != 'demo' inside a handle() that has already returned unless the mode IS demo, so the command can never be invoked by the path that exists to invoke it.

**session:flush — garbage-collect sessions and clear caches**  
`platform` · owner: Developer  
- Backend — app/Console/Commands/SessionFlush.php:16 `session:flush` (description is the unedited stub 'Command description'); not scheduled, not called from anywhere
- No surface on — Admin, Seller Web, Analytics, Monitor
- Dead: an unedited stub ('Command description') with no scheduler entry and no caller; sessions are garbage-collected by Laravel's lottery and cache clearing is already reachable from Admin → Settings.

## ORPHAN (86)

Found with no surface. Each has been ruled to an owner; the ruling is not the surface, so the list reaches zero only when the screen exists.

**Seller Center navigation registry — 41 of its 51 designed destinations resolve to no route and are silently dropped from the rail**  
`platform` · owner: Seller  
- Backend — app/Services/SellerCenter/Navigation.php:35-199 declares 51 route destinations; routes/seller/routes.php:50-113 builds 14 routes; the Route::has() guard at Navigation.php:225-230 drops the other 41 (verified: returns, refunds, warehouse, all 6 finance, all 4 pricing, shipments/picking/packing, integrations x4, team/roles, security, audit, compliance, brands x2, performance x3, reports/exports x3, advertising, approvals, cases, incidents, appeals, bulk-jobs, action center)
- No surface on — Admin, Seller Web, Analytics, Monitor
- Ruled: belongs to the Seller Center web panel. The registry is the design of record and the route table is one fifth of it, so a seller sees a menu that silently omits every capability the phone app already has; the drop is invisible from inside the product because a missing route removes the item rather than erroring.

**Five pages call route() on names that do not exist, so they throw RouteNotFoundException instead of rendering**  
`platform` · owner: Developer  
- Backend — resources/views/payment/paystack.blade.php:8 route('paystack.payment') — the real name is paystack.pay, so Paystack checkout is dead; resources/views/admin-views/transaction/list.blade.php:93 route('admin.transaction.transaction-export'); resources/views/admin-views/pages-and-media/page.blade.php:22 route('admin.pages-and-media.page-update'); resources/views/admin-views/third-party/payment-method/payment-option.blade.php:20 route('admin.business-settings.payment-method.payment-option'); resources/views/vendor-views/product/barcode.blade.php:53 route('vendor.products.edit') — all five verified absent from php artisan route:list
- No surface on — Analytics, Monitor, Dev Portal
- Ruled: belongs to Developer as a defect, not a missing surface. Each is a hard 500 on a page a customer or operator will reach — the Paystack one breaks a live payment method — and 19 further missing names elsewhere are correctly guarded by Route::has and degrade quietly, which shows the pattern was understood and these five were missed.

**Installer and software updater — the first-run wizard and the file-based update flow**  
`platform` · owner: Developer  
- Backend — routes/install.php and routes/update.php exist; app/Providers/RouteServiceProvider.php:54-55 has //$this->mapInstallRoutes(); and //$this->mapUpdateRoutes(); commented out, so app/Http/Controllers/InstallController.php (11 methods) and UpdateController.php are unreachable and the installer views' route('purchase.code') / route('install.db') names do not resolve
- No surface on — Admin, Seller Web, Analytics, Monitor, Dev Portal
- Ruled: belongs to Developer. Two whole route files are mapped by methods that are commented out, so a fresh install or an in-place update cannot be driven through any UI; either restore the mapping or delete the route files, controllers and views together.

**Unlinked admin developer pages — the Kohl design-system gallery and two component galleries mounted on the production admin prefix**  
`platform` · owner: Developer  
- Backend — routes/admin/routes.php:174 Route::view('design-system','admin-views.kohl.gallery')->name('design-system'); :138 GET admin/component and :141 GET admin/component-snippets, both closures with no route name at all; referenced by no menu, blade or JS
- No surface on — Seller Web, Analytics, Monitor, Dev Portal
- Ruled: belongs to Developer, off the production admin prefix. Three live URLs any panel user can open, two of them nameless, kept for component development rather than operation.

**vendor/get-order-data — an authenticated seller endpoint returning order data that nothing calls**  
`orders` · owner: Developer  
- Backend — routes/vendor/routes.php GET/POST vendor/get-order-data; the only other reference in the repo is the staff permission map at app/Http/Middleware/SellerStaffAccessMiddleware.php:107, i.e. no route() call, fetch or link anywhere
- No surface on — Admin, Seller Web, Flutter App, Analytics, Monitor, Dev Portal
- Ruled: belongs to Developer to delete or document. It is either dead code or an undocumented integration point on order data, and the difference matters because it is reachable with a seller session.

**Commerce campaigns, segments and experiments are absent from the admin sidebar**  
`automation` · owner: Admin  
- Backend — routes/admin/routes.php:1445-1463 registers 12 admin.commerce.{campaign,segment,experiment}.* routes; resources/views/admin-views/commerce/_nav.blade.php:12-24 is the only link path, reachable only after opening Collections
- No surface on — Seller Web, Monitor, Dev Portal
- Ruled: belongs to Admin navigation. Three complete, audited features exist and an operator finds them only by opening a fourth feature and noticing its tab strip — a discovery problem, not a build problem.

**Dual-control (maker-checker) gate on large seller payouts — above a set amount a payout needs two approvers**  
`finance` · owner: Admin  
- Backend — app/Services/Marketplace/PayoutService.php:152 openApprovalIfLarge(..., int $requiredApprovals = 2); threshold read from setting 'payout_dual_control_threshold' at app/Http/Controllers/Vendor/Marketplace/PayoutController.php:79 and app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:109; app/Http/Controllers/Vendor/Marketplace/PayoutController.php:79 and app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:109 read business_settings key `payout_dual_control_threshold` via getWebConfig(); when >0 they call PayoutService::openApprovalIfLarge()
- No surface on — Admin, Analytics, Monitor
- Ruled: belongs on Admin → Marketplace → Settlements, beside the maker-checker toggle that already has a screen. Verified by repo-wide grep — payout_dual_control_threshold appears at exactly two read sites and no writer — so it defaults to 0, dual control is off on every install, and arming it is a hand-written database row; the required approver count of 2 is a default argument as well.

**24-hour payout freeze after a seller changes their bank details**  
`finance` · owner: Admin  
- Backend — app/Services/Marketplace/PayoutService.php:37 (const COOLING_HOURS = 24), applied at app/Services/Marketplace/PayoutService.php:306 recordBankChange(), enforced in requestPayout(); no setting key exists
- No surface on — Admin, Analytics, Monitor
- Ruled: belongs in Admin Settings next to the payout queue. It is the platform's anti-account-takeover hold and the length is exactly what a risk team retunes after an incident, yet PayoutService.php:37 is a class constant with no setting key.

**Changing the shop's bank / payout account from the Flutter app or the v3 API**  
`finance` · owner: Seller  
- Backend — app/Http/Controllers/RestAPI/v3/seller/SellerController.php:352 seller_info_update(), writing bank_name/branch/account_no/holder_name at :364-375; route routes/rest_api/v3/seller.php:93 PUT /api/v3/seller/seller-update; Flutter caller /home/user/sillercenter-syria-cosmatics/lib/features/bank_info/domain/repositories/bank_info_repository.dart:19
- No surface on — Admin, Analytics, Monitor
- Ruled: a defect belonging to Developer on the v3 path. The web path calls PayoutService::recordBankChange, which writes the before/after audit row and arms the 24-hour cooling window; SellerController.php:352 writes the same columns directly and does neither, so a payout redirect performed from the phone is both unrecorded and undelayed.

**Mark a payout failed, or retry one a bank bounced**  
`finance` · owner: Admin  
- Backend — app/Models/VendorPayoutRequest.php:19 STATUS_FAILED; rendered at resources/views/admin-views/marketplace/payouts.blade.php:8; no route sets it — routes/admin/routes.php:566-573 offers approve, mark-paid and reject only
- No surface on — Admin, Analytics, Monitor
- Ruled: belongs on the Admin payout queue. VendorPayoutRequest::STATUS_FAILED exists and payouts.blade.php:8 colours the badge, but a grep of every STATUS_FAILED write shows only bulk jobs, automation actions and webhook deliveries setting it — nothing ever marks a payout failed, so a bounced transfer stays 'paid' and the seller is never made whole.

**Payment terms and scheduled cadences — payout frequency, minimum payout, holding period, settlement release time, SLA evaluation time and abandoned-cart send times**  
`finance` · owner: Admin  
- Backend — None found. Searched routes/admin/routes.php, app/Http/Controllers/Admin/Marketplace/PayoutController.php, app/Services/Marketplace/PayoutService.php, app/Services/Marketplace/SettlementEngine.php and config/ for a schedule, threshold or hold-period setting; bootstrap/app.php:147 ($schedule->command('marketplace:settle --release')->dailyAt('02:00')); command at app/Console/Commands/RunVendorSettlements.php:25; bootstrap/app.php:155 ($schedule->command('marketplace:evaluate-sla')->dailyAt('03:00')); command app/Console/Commands/EvaluateSellerSla.php; also manual 'evaluate all' button at resources/views/admin-views/marketplace/sla.blade.php:17; bootstrap/app.php:140 (cart:remind-abandoned everyThirtyMinutes) and :151 (--stage=2 dailyAt('10:00')); signed recovery link expiry at app/Console/Commands/SendAbandonedCartReminders.php:134 (now()->addDays(30))
- No surface on — Admin
- Ruled: belongs in Admin Settings. Settlement release is hard-scheduled at 02:00 (bootstrap/app.php:147), seller judgement at 03:00 (:155) and cart reminders at :140/:151, and there is no screen for a payout frequency, a minimum amount or a hold period — so changing the marketplace's payment-terms promise to its sellers is a deploy.

**Diagnose a payment gateway that is switched on but cannot take a payment**  
`finance` · owner: Admin  
- Backend — app/Console/Commands/PaymentGatewayCheck.php:27 `payment:check`; reads addon_settings live_values/test_values against the row's mode column
- No surface on — Admin, Seller Web, Analytics, Monitor
- Ruled: belongs on Admin → Third-party → Payment methods as a check button or a banner. Credentials live in addon_settings as separate live_values/test_values blobs and the controllers read only the blob matching the row's mode, so a shop can show a green, fully-filled gateway that refuses every payment; payment:check names the blank field and no screen ever runs it.

**Why a payment failed — gateway latency, failure reason, and whether the callback ever arrived**  
`finance` · owner: Admin  
- Backend — Declared unmeasurable at app/Services/Monitoring/Panels/PaymentsPanel.php:2039-2073 unrecorded(), returned from data() at :203 — payment_started (:2046), gateway_latency (:2051), webhook_receipts (:2056), payment_request_outcome (:2061), payment_request_order_link (:2066) — each naming the exact file that would have to produce it
- No surface on — Admin, Seller Web, Analytics, Monitor
- Ruled: belongs to Monitoring. No gateway callback leaves a receipt anywhere (PaymentsPanel.php:2056), so a callback that never arrived and one that arrived and failed are the same absent row, and a payment outage is visible only as orders that stopped appearing.

**Alerting on payout and settlement failure — duplicate settlements, paid orders with no settlement row, commission mismatches**  
`finance` · owner: Admin  
- Backend — Detection exists as read-only findings in PaymentsPanel.php:1507 (duplicate settlements), :1565 (paid order with no settlement row), :1677 (commission mismatch); the settlement run itself is marketplace:settle at bootstrap/app.php:147
- No surface on — Seller Web, Analytics, Monitor
- Ruled: belongs to Monitoring. PaymentsPanel really does detect these money-losing conditions, but computes them live on page load and publishes no series, so MetricResolver cannot see them and no rule can be written — a seller who is silently never paid is found only if an admin happens to open the section.

**Currency model — whether the marketplace runs single-currency or multi-currency with exchange rates**  
`finance` · owner: Admin  
- Backend — business_settings key `currency_model`, read 35× including app/Utils/currency.php:53,:78,:116,:158,:181, app/Traits/OrderEditManager.php:804 and app/Http/Controllers/Admin/Settings/CurrencyController.php:222,:245
- No surface on — Seller Web, Analytics, Monitor
- Ruled: belongs on the existing Admin Currency screen, which already reads and displays it. 35 branch sites including every conversion in app/Utils/currency.php depend on it and the only writer is the installer, so the audited bulk exchange-rate editor can be maintaining rates the platform will never apply.

**Payment success and abandonment rate**  
`finance` · owner: Developer  
- Backend — app/Services/Analytics/Analytics.php:163-186 paymentAttempted maps outcome 'started' onto AnalyticsEvent::PAYMENT_STARTED; no caller in the codebase passes 'started' (only 'succeeded' at OrderManager.php:1660 and 'failed' at app/Utils/module-helper.php:125)
- No surface on — Admin, Seller Web, Analytics, Monitor, Dev Portal
- Ruled: belongs to Developer to emit. payment_started is in the catalogue, mapped in the recorder and charted by the funnel's gateway breakdown, and verified here: the only three callers of paymentAttempted pass 'succeeded' or 'failed' and never 'started', so a shopper who left the gateway before it answered is invisible and the platform has no payment success rate at all.

**Seller-domain analytics events (payout requested, KYC submitted) are recorded as internal traffic and can never reach a report**  
`analytics` · owner: Developer  
- Backend — app/Services/Analytics/Analytics.php:363 payoutRequested, called from app/Services/Marketplace/PayoutService.php:128 (event payout_requested, dedupe payout:{reference}); app/Services/Analytics/Analytics.php:378 kycSubmitted, called from app/Services/Marketplace/SellerVerificationService.php:205 (event kyc_submitted)
- No surface on — Admin, Flutter App, Monitor, Dev Portal
- Ruled: belongs to Developer as a defect in BotDetector. Both events are only ever raised while a seller is authenticated, and BotDetector.php:126 flags any logged-in seller as internal, so every row is written with is_internal=1 and excluded by every rollup and every report — the events exist and are structurally unreportable.

**Twelve inbound payment-gateway callbacks (bKash, Flutterwave, LiqPay, MercadoPago, Paymera, PayMob, Paystack, PayTabs, Razorpay, SenangPay and others)**  
`integrations` · owner: Developer  
- Backend — Twelve routes under /payment/*/callback registered at routes/web/routes.php:499-584 (RazorPay :499, SenangPay :522, Paytm :528, Flutterwave :535, Paystack :541, bKash :549, LiqPay :555, MercadoPago :562, PayMob :568, PayTabs :574, Paymera :584) — all outside the api/ prefix so ApiManifest classifies them surface=panel (app/Services/DeveloperPortal/Support/EndpointClassifier.php:94)
- No surface on — Seller Web, Analytics, Dev Portal
- Ruled: belongs in the Developer Portal's partner surface. They are real external webhooks that move money, but they sit under /payment/* rather than api/, so EndpointClassifier marks them panel routes and the explorer, the OpenAPI export and the quality score all skip them.

**Monitoring and portal thresholds left as class constants beside the editable threshold map (duplicate-order window, payment capture grace, backup size-drop, incident correlation window, endpoint health verdicts)**  
`monitoring` · owner: Admin  
- Backend — app/Services/Monitoring/Panels/OrderIntegrityPanel.php:117 (DUPLICATE_GAP_SECONDS = 120), :105 (MINIMUM_LOOKBACK_DAYS = 7), :114 (STANDING_LOOKBACK_DAYS = 30), :120 (AMOUNT_TOLERANCE = 0.01), :88 (MAX_SAMPLE_ORDERS = 400); app/Services/Monitoring/Panels/PaymentsPanel.php:92 (CAPTURE_GRACE_MINUTES = 15), :95 (MONEY_TOLERANCE = 0.01), :113 (PAYTABS_APPROVED = 100); app/Services/Monitoring/Panels/BackupsPanel.php:65 (SIZE_DROP_PERCENT = 40.0), :50 (CHECK_CADENCE_MINUTES = 5); backup age threshold is configurable (config/monitoring.php:176 backup_age_warning_hours); app/Services/Monitoring/Alerting/IncidentManager.php:29 (public const CORRELATION_WINDOW_MINUTES = 30); evaluated by bootstrap/app.php:216 (monitoring:evaluate everyMinute); app/Services/DeveloperPortal/EndpointHealthService.php:262-270 verdict(); removal-safety advice keyed on 30 days of silence at :200, :209 and :226
- No surface on — Seller Web, Analytics
- Ruled: belongs in the same Admin monitoring threshold map that already holds stuck_order_hours and backup_age_warning_hours — these five sit one file away from it, so the omission is inconsistency rather than design. The endpoint health verdicts additionally duplicate error_rate_warning and p95_critical_ms, so the Developer Portal and the monitor can disagree about the same endpoint.

**Inventory as a measured quantity — stock-out frequency, how long stock sat at zero, sell-through**  
`analytics` · owner: Admin  
- Backend — None. No analytics event or rollup dimension touches stock; the nearest things are the point-in-time stock list at admin/stock/product-stock (routes/admin/routes.php:855) and the append-only movement log written by app/Services/Marketplace/InventoryService.php (surfaced at seller/inventory/movements, routes/seller/routes.php:76). Searched: AnalyticsEvent constants, AnalyticsRollup dimensions, product_metrics columns, SellerScorecardService metrics.
- No surface on — Analytics, Monitor
- Ruled: belongs to Analytics. Stock can be listed, adjusted, transferred and written off on every surface, and nothing counts any of it — product_metrics carries views and cart adds and has no stock column, so the cost of a stock-out is unanswerable on a platform whose whole job is stock.

**Quick stock edit from the classic product list — sets current_stock directly, with no reason, no movement row and no audit line**  
`inventory` · owner: Admin  
- Backend — app/Http/Controllers/Admin/Product/ProductController.php:800 updateQuantity(), writing through app/Repositories/ProductRepository.php:478 updateByParams at :821; the vendor equivalent is app/Http/Controllers/Vendor/Product/ProductController.php; neither file references AuditLogger
- No surface on — Analytics, Monitor, Dev Portal
- Ruled: belongs to Developer to route through InventoryService, which already writes a reason code, a movement ledger row and an audit line to the same column. Two stock-writing paths that disagree about whether a change is traceable will drive current_stock and the movement ledger apart, and the trail cannot say why.

**Approve or deny a seller's listing from the classic product screen**  
`catalog` · owner: Admin  
- Backend — app/Http/Controllers/Admin/Product/ProductController.php:947 approveStatus() (route routes/admin/routes.php:309 admin.products.approve-status), plus the denied-note and status writes at :925/:938/:953; the file contains zero AuditLogger references
- No surface on — Analytics, Monitor, Dev Portal
- Ruled: belongs to the audited moderation path that already exists. ProductModerationService records every decision with reason codes and history; ProductController::approveStatus/deny writes nothing — and the sidebar sends operators to the unaudited one, so whether a listing decision is recorded depends on which screen the operator happened to open.

**Keeping the storefront product search index in step with the catalogue, and rebuilding it when it drifts**  
`catalog` · owner: Admin  
- Backend — app/Console/Commands/RebuildProductSearchIndex.php:20 `search:reindex-products`; scheduled bootstrap/app.php:192 weekly Sunday 04:00; app/Observers/ProductSearchIndexObserver.php:20; registered app/Providers/ObserverServiceProvider.php:38-43 on Product
- No surface on — Admin, Seller Web
- Ruled: belongs on an Admin catalogue page as an index-health readout with a rebuild action. The observer swallows every exception so a product save can never fail, which means a broken index write is invisible, and the weekly reconcile command has no admin surface either — so an import that bypassed the observer leaves storefront search quietly incomplete with no way to notice or repair it from the panel.

**Mass product updates written through the query builder bypass the price observer**  
`pricing` · owner: Developer  
- Backend — app/Repositories/ProductRepository.php:478 updateByParams() uses $this->product->where($params)->update($data) — a builder update that fires no model events, so app/Observers/ProductPriceObserver.php:30 never runs; called for stock at app/Http/Controllers/Admin/Product/ProductController.php:821
- No surface on — Seller Web, Flutter App, Analytics, Monitor, Dev Portal
- Ruled: belongs to Developer as a latent hole. ProductRepository::updateByParams issues a builder update, which fires no model events, so any future price write through it would skip the price-change history, the audit row and the seller-visible price history in one step; today's callers touch stock and variations only.

**Which order states remain editable, and which remain cancellable**  
`orders` · owner: Admin  
- Backend — app/Services/OrderEditService.php:388 (in_array($order->order_status, ['pending','confirmed']) gate, plus order_type != 'default_type'); repeated for stock checks at :247 and :484; app/Http/Controllers/Admin/Customer/CustomerController.php:228 (in_array($order->order_status, ['pending','confirmed','processing','out_for_delivery'])); terminal states listed at app/Utils/OrderManager.php:2751; stock restoration states at app/Utils/OrderManager.php:93 (['returned','failed','canceled'])
- No surface on — Analytics, Monitor, Dev Portal
- Ruled: belongs in Admin → Order settings, which already exists. Both rules are inline status arrays repeated across at least three files, so a marketplace that wants cancellation to stop at 'processing' has to be given a code change.

**Minimum number of items required before a customer may check out**  
`orders` · owner: Admin  
- Backend — business_settings key `minimum_order_limit`, read only at app/Http/Controllers/RestAPI/v1/ConfigController.php:190 and shipped to the mobile apps in the /api/v1/config payload
- No surface on — Admin, Seller Web, Analytics, Monitor
- Ruled: belongs on the Admin order-settings screen that already carries the minimum order amount. It is seeded at install, read in exactly one place, shipped to all three mobile apps in /api/v1/config, and written by nothing — so the apps enforce a checkout rule the operator cannot see or change.

**Returns and refunds as measured quantities — return rate by reason, time to receive, restock rate, refund volume, value and time to settle**  
`returns` · owner: Admin  
- Backend — Partially. AnalyticsEvent::REFUND_REQUESTED is raised only as an order-status side effect (Analytics.php:214 maps 'returned'/'failed'); the RMA state machine at app/Services/Marketplace/ReturnLogisticsService.php writes no event, and admin/marketplace/returns (routes/admin/routes.php:706) is a queue, not a report. A return_rate ratio exists in SellerScorecardService.php:56 and SellerCenter/Lists/HomeMetrics.php:79.; None in analytics — no event is raised when a refund_request is created or approved (searched app/Services/RefundStatusService.php, RefundRequestService.php, Marketplace/RefundReversalService.php: no Analytics reference). Row-level surfaces: admin/report/transaction/refund-transaction-list (routes/admin/routes.php:801) and refund_rate on SellerScorecardService.php.
- No surface on — Monitor, Dev Portal
- Ruled: belongs to Analytics. No event is raised when a refund request is created or approved, and the RMA state machine writes nothing at all, so the platform has two half-measurements that cannot be joined: a rate derived from order_status on the scorecard, and an event named refund_requested that actually fires on an order status change.

**Approve or reject a customer refund**  
`returns` · owner: Admin  
- Backend — app/Http/Controllers/Admin/Order/RefundController.php:109 updateRefundStatus(); route routes/admin/routes.php:1704 admin.refund-section.refund.refund-status-update; wallet debit at :136-139, ledger reversal at :150; no AuditLogger in the file or in app/Services/RefundRequestService.php / RefundStatusService.php / RefundTransactionService.php; routes/vendor/routes.php:145-152 — vendor/refund/index/{status}, POST update-status; routes/rest_api/v3/seller.php:244-255 — api/v3/seller/refund/* (seller_can:orders.manage); app/Http/Controllers/Admin/Order/RefundController.php; routes/admin/routes.php:1698-1706 admin.refund-section.refund.*; nav _side-bar.blade.php:233
- No surface on — Monitor
- Ruled: belongs on the unified audit trail. Approval debits the seller's earnings, reverses the marketplace's commission and moves customer money, and writes only to its own refund_status history — so the audit centre cannot answer who approved any refund ever processed.

**Registering a second courier — credentials, rates, labels and tracking per carrier**  
`shipping` · owner: Admin  
- Backend — None found. Searched routes/admin/routes.php, app/Http/Controllers/Admin/Shipping, app/Services/Marketplace/ShippingRateService.php and app/Services/DeliverySyria for a carrier table or per-carrier credential store
- No surface on — Admin, Seller Web, Flutter App, Analytics, Monitor, Dev Portal
- Ruled: belongs in Admin as a carrier registry. Today carrier support is one hard-coded integration (Delivery Syria) plus flat and zone rates, with no carrier table in database/migrations, so onboarding a courier is a code change rather than a configuration.

**Shipping and fulfilment as measured quantities — what shipping costs, which zone is expensive, dispatch time and lateness**  
`shipping` · owner: Admin  
- Backend — Effectively none. checkout_shipping_set is recorded from the payment page alongside address and payment in one breath (Web/WebController.php:404-406), so it measures reaching checkout, not choosing a shipping method; the only shipping figure recorded anywhere is shipping_cost inside the order_placed properties JSON (OrderManager.php:1652), which no rollup reads. Shipping zones (routes/admin/routes.php:759) and rates are configured but never counted.; None. app/Services/Marketplace/FulfillmentService.php stamps packed/shipped timestamps on an overlay record and admin/marketplace/fulfillments (routes/admin/routes.php:742) lists them; AnalyticsEvent::ORDER_DELIVERED exists (Analytics.php:213) but only reaches the 'event' dimension — no delivery-time or dispatch-time metric is computed anywhere, and SLA metrics are cancellation/return/refund/rating only (SlaService.php:24).
- No surface on — Analytics, Monitor, Dev Portal
- Ruled: belongs to Analytics, and it is the measurement gap with the sharpest consequence: FulfillmentService stamps packed and shipped timestamps on every fulfilment and nothing ever subtracts them, so a marketplace that enforces an SLA policy and suspends sellers for breaching it cannot measure lateness. The only shipping number recorded anywhere is shipping_cost inside an order_placed properties JSON blob that no rollup reads.

**Inbound courier status webhook — POST /api/delivery-syria/orders/update-status**  
`integrations` · owner: Developer  
- Backend — POST /api/delivery-syria/orders/update-status — routes/rest_api/delivery_syria.php:16; app/Http/Controllers/Api/DeliverySyriaWebhookController.php; authenticated by app/Http/Middleware/DeliverySyriaWebhookAuthMiddleware (X-Platform + bearer webhook token); settings at routes/admin/routes.php:1272
- No surface on — Seller Web, Analytics
- Ruled: belongs in the Developer Portal with a written contract. It is the only genuinely external partner endpoint on the whole API — an outside courier POSTs order status changes into it under a shared secret — and it carries no ApiDoc, so the portal's Partner APIs section shows one endpoint described by a mechanically inferred summary.

**Create, rename or delete a brand in the catalogue**  
`brands` · owner: Admin  
- Backend — app/Http/Controllers/Admin/Product/BrandController.php (zero AuditLogger references); routes routes/admin/routes.php:381 admin.brand.*
- No surface on — Seller Web, Analytics, Monitor
- Ruled: belongs on the unified audit trail alongside the brand registry. The registry audits every claim decision; the CRUD that actually creates and deletes the brand records those claims point at audits nothing.

**Disputes and appeals — a channel for a seller to contest a rejection, a suspension, a brand revocation or a chargeback**  
`compliance` · owner: Admin  
- Backend — None found. Searched app/, Modules/, routes/ and database/migrations for dispute and appeal — hits are only the word 'dispute' inside SellerScorecardService.php and BrandRegistryService.php prose, plus a dead seller.appeals.index entry in app/Services/SellerCenter/Navigation.php that has no route; None — seller.cases.index, seller.incidents.index and seller.appeals.index at app/Services/SellerCenter/Navigation.php:137,195,196 have no route or controller; searched routes/**, app/Http/Controllers/{Seller,Vendor}, app/Services for a case or appeal service with no hits
- No surface on — Admin, Seller Web, Flutter App, Analytics, Monitor, Dev Portal
- Ruled: belongs to both panels and exists in neither. Searched app/, Modules/, routes/ and database/migrations for dispute|appeal|case: no controller, no table, no route — only prose in two service files and three dead nav entries (seller.cases.index, seller.incidents.index, seller.appeals.index). The panel can suspend a shop, deny a listing and revoke a brand claim, and the seller's only channel is a support ticket that carries no link to the decision it contests, so nobody can see how many decisions are being challenged.

**The seller's own account health and SLA standing**  
`compliance` · owner: Seller  
- Backend — SLA is evaluated by app/Services/Marketplace/SlaService.php via command marketplace:evaluate-sla (bootstrap/app.php:155); the nav entries seller.performance.index, .health, .sla, seller.appeals.index at app/Services/SellerCenter/Navigation.php:161-163,196 are unrouted
- No surface on — Seller Web, Flutter App, Analytics, Dev Portal
- Ruled: belongs in the Seller Center. The platform evaluates every approved seller against SLA policy daily and writes audited breaches, and no client renders account health — the seller sees a scorecard number and never the standing, the breach, or the deadline they are being judged against.

**Compliance as a measured quantity — unauthorised brand listings, verification standing, policy breaches over time**  
`compliance` · owner: Seller  
- Backend — Counts only: app/Services/SellerCenter/Counts.php:56-58 (brands_expiring, compliance_action, brands_pending) feeding nav badges; the seller Compliance page it badges (Navigation.php:167 'seller.compliance.index') has no route in routes/seller/routes.php and is filtered out at Navigation.php:230; breaches ledger at Marketplace/SlaService.php with admin/marketplace/sla (routes/admin/routes.php:697)
- No surface on — Seller Web, Flutter App, Analytics, Monitor, Dev Portal
- Ruled: belongs on the Seller Center compliance page, which does not exist. Counts.php already computes a compliance_action badge for that missing page, so the platform renders a number on a menu pointing at nothing, and no breach, verification or brand-claim figure is trended anywhere.

**Reporting how much traffic went unmeasured because of Do Not Track or missing consent**  
`analytics` · owner: Admin  
- Backend — app/Services/Analytics/Support/PrivacyGate.php:32 reason() — documented as being 'for the data-quality screen'
- No surface on — Admin, Seller Web, Analytics, Monitor, Dev Portal
- Ruled: belongs on the Analytics data-quality screen it was written for. PrivacyGate::reason() exists specifically to supply that number and has no caller anywhere, so a shop that turns consent on loses reported traffic with nothing explaining the drop.

**Seller issue policy — the weighted severity model, the escalation ladder, and how often the platform may interrupt a seller's phone**  
`automation` · owner: Admin  
- Backend — app/Services/SellerIntelligence/Severity/SeverityEngine.php:65-69 (weights), :72-84 (saturations: revenue 0.25, volume 0.10, urgency 6h, duration 168h, recurrence 10), :86-88 (BAND_CRITICAL 75, BAND_HIGH 40, BAND_MEDIUM 20); baseline window app/Services/SellerIntelligence/Severity/SellerBaselineProvider.php:23 (LOOKBACK_DAYS = 30); app/Services/SellerIntelligence/IssueEscalationService.php:41-45 (PROMOTE_AFTER_HOURS low 336 / medium 168 / high 48), :48 (PROMOTE_ON_OVERDUE = true), :51 (MAX_ESCALATION_LEVEL = 3); swept by bootstrap/app.php:170 every four hours; app/Services/SellerIntelligence/SellerNotifier.php:46 (WINDOW_HOURS = 12), :49 (PUSH_FROM_SEVERITY = ['critical','high']); driven by app/Console/Commands/RefreshSellerInsights.php scheduled hourly at bootstrap/app.php:160; None — no notification-preference route, table, model or setting key exists; searched routes/vendor, routes/se
- No surface on — Admin, Analytics, Monitor
- Ruled: belongs in Admin Settings as a policy page, with the notification half exposed to the seller. Thirteen severity constants decide what every seller sees first, an escalation ladder decides the marketplace's enforcement posture toward slow sellers, and a 12-hour window plus a critical/high floor decides what reaches their phone — none of it is settable by anyone, so the only way to stop the noise is to turn notifications off entirely.

**Scheduled operations — timed price changes, timed activations, campaign starts**  
`automation` · owner: Seller  
- Backend — None — no route, controller, table or command; the nav entries seller.pricing.scheduled and seller.automation.scheduled at app/Services/SellerCenter/Navigation.php:98,139 resolve to nothing
- No surface on — Admin, Seller Web, Flutter App, Analytics, Monitor, Dev Portal
- Ruled: belongs in the Seller Center and has no backend at all — no route, controller, table or command; two navigation destinations (seller.pricing.scheduled, seller.automation.scheduled) name a server that was never built and are filtered out of the rail.

**Whether a seller's automation rules and bulk jobs are actually succeeding**  
`automation` · owner: Admin  
- Backend — app/Services/SellerAutomation/AutomationEngine.php driven by seller:run-automation (bootstrap/app.php:176) and seller:run-stuck-bulk-jobs (:188); no reference to automation or bulk jobs anywhere in app/Services/Monitoring
- No surface on — Analytics, Monitor, Dev Portal
- Ruled: belongs to Monitoring. The sweep is recorded only as a scheduled-task row, so a run that exits 0 while every rule inside it fails is filed as a success; every run and every action already records an outcome and nothing aggregates them into a success rate or a trend.

**Commerce Experience master switch — storefront collections, campaigns, segments and experiments on or off**  
`automation` · owner: Admin  
- Backend — config/commerce.php:12 `'enabled' => env('COMMERCE_EXPERIENCE_ENABLED', true)`; read at app/Services/Commerce/CollectionResolver.php:42, ExperimentResolver.php:92, CampaignResolver.php:206, SegmentResolver.php:168
- No surface on — Seller Web, Monitor
- Ruled: belongs in Admin Settings. Four admin screens display the flag's state and none writes it, so the documented rollback path for the whole personalisation engine is one env line and a deploy.

**Seller report builder, saved report definitions and an exports centre**  
`analytics` · owner: Seller  
- Backend — None — seller.reports.index, seller.reports.builder and seller.exports.index at app/Services/SellerCenter/Navigation.php:175-177 have no route, controller or service
- No surface on — Admin, Seller Web, Monitor
- OVERRULED in part — the seller sweep recorded reports and exports as having no backend. Verified: GET seller-center/reports/{orders,products,stock} and three export endpoints do exist on the v3 API and are used by the Flutter app. What is missing is the web surface and the saved-definition/queued-export half: seller.reports.index, seller.reports.builder and seller.exports.index have no route, so on a browser every export is a synchronous download off one specific list.

**Folding the tail of a high-cardinality dimension into an __other__ row instead of dropping it**  
`analytics` · owner: Developer  
- Backend — app/Console/Commands/AnalyticsRollup.php:552 cap() = config('analytics.max_keys_per_dimension', 500), applied as ->limit() at :164, :235, :297, :337
- No surface on — Admin, Seller Web, Monitor, Dev Portal
- Ruled: belongs to Developer as a correctness gap. config/analytics.php:70 promises the tail beyond 500 keys is folded into __other__ 'and the fold is reported rather than hidden'; the analytics rollup applies a limit and writes no such row, so the tail is silently dropped and every breakdown's 'other' figure understates it. Monitoring's BucketWriter does implement the fold, which shows the intended shape.

**Pipeline health counters — events written, and events dropped because a request overflowed the buffer**  
`analytics` · owner: Admin  
- Backend — app/Services/Analytics/EventRecorder.php:166 health('events_written') and :169 health('events_dropped_buffer_full') into analytics_health
- No surface on — Admin, Seller Web, Monitor, Dev Portal
- Ruled: belongs on the Analytics data-quality screen. EventRecorder records both counters explicitly so that screen can show them, and collectionHealth reads only rollup_ran and write_failed — so a request loop quietly shortening the numbers is recorded and shown to nobody.

**Per-day performance of each campaign short link**  
`analytics` · owner: Admin  
- Backend — app/Console/Commands/AnalyticsRollup.php:353 rollupCampaignPerformance writing the analytics_daily dimension 'campaign_link'
- No surface on — Seller Web, Monitor, Dev Portal
- Ruled: belongs on the Admin Campaigns screen, which instead reads lifetime counters off analytics_campaigns. The rollup writes a campaign_link dimension every day and no section asks for it, so the day-by-day series is reachable only by guessing the export URL.

**The extra facts attached to each event — payment method, coupon code, shipping cost, guest flag, failure reason**  
`analytics` · owner: Admin  
- Backend — written at app/Utils/OrderManager.php:1646-1653 and Analytics.php:177-181; stored as analytics_events.properties (EventRecorder.php:109)
- No surface on — Seller Web, Flutter App, Monitor, Dev Portal
- Ruled: belongs to Analytics reporting. Every order writes them into analytics_events.properties and exactly one reader exists in the codebase (ExperimentReach pulling properties->experiment), so shipping cost, coupon and payment method per order are captured on every order and reportable on none.

**Daily history of request volume, visitors, errors and API load (telemetry_daily)**  
`analytics` · owner: Developer  
- Backend — app/Console/Commands/TelemetryRollup.php:31 (telemetry:rollup) writing telemetry_daily; scheduled bootstrap/app.php:243-249; app/Console/Commands/TelemetryRollup.php:31 `telemetry:rollup`; scheduled bootstrap/app.php:242 hourly at :07, bootstrap/app.php:246 `--date=yesterday` daily 00:20, bootstrap/app.php:247 `--prune` daily 01:30
- No surface on — Admin, Seller Web, Dev Portal
- Ruled: belongs to Developer to either surface or stop writing. Three scheduled runs a day maintain the table and the command's own header admits no screen reads it since Analytics moved to analytics_daily — it survives the raw-row prune as retention, so a quarter of the telemetry scheduler budget produces output nobody can look at.

**Analytics and telemetry policy — consent, Do Not Track, IP masking, bot and staff exclusion, what a session and a bounce are, and how long customer data is kept**  
`analytics` · owner: Admin  
- Backend — config/analytics.php:11 (`ANALYTICS_ENABLED`), :56-61 retention, :83-88 exclusions, :105-114 privacy, :125-130 campaign links, :141-146 beacon; read throughout app/Services/Analytics/; config/analytics.php:57-60 (event_days 90, session_days 400, daily_days 1100, click_days 400, each env-overridable); pruned by bootstrap/app.php:229 (analytics:rollup --days=2 --prune dailyAt 02:15); config/analytics.php:32 (session_gap_minutes 30), :36 (engaged_after_seconds 10), :46 (dedupe_window_seconds 5), :29 (buffer_limit 40), :72 (max_keys_per_dimension 500); config/telemetry.php:9 `TELEMETRY_ENABLED`, :12 `TELEMETRY_RETENTION_DAYS`, :15 session gap, :18 ignore_prefixes; consumed by app/Services/Telemetry/ and app/Console/Commands/TelemetryRollup.php (scheduled bootstrap/app.php:242-247)
- No surface on — Seller Web
- Ruled: belongs in Admin Settings and is the clearest case in the whole audit: the Analytics settings page opens with 'Read-only for now, and honest about it' and prints config() values with no form. Every privacy decision about live customer traffic — and two independent retention policies, in config/analytics.php and config/telemetry.php — can only be changed by editing .env and redeploying, so honouring a consent or erasure request is a deployment.

**Alert rules — seeding them, creating or editing one, setting a threshold, silencing one, and telling somebody when one fires**  
`monitoring` · owner: Admin  
- Backend — app/Services/Monitoring/Alerting/AlertEvaluator.php:62 (table monitoring_alert_rules, seeded in code at :83); app/Services/Monitoring/Alerting/AlertNotifier.php; app/Services/Monitoring/Panels/AlertsPanel.php:42; routes/admin/routes.php:227-237; app/Services/Monitoring/Alerting/AlertEvaluator.php:374-476 (defaultRules), installed only by `php artisan monitoring:evaluate --seed` (MonitoringEvaluate.php:19,33); Table monitoring_alert_rules (database/migrations/2026_08_24_000002_create_monitoring_operations_tables.php:77, whose comment says 'edited in Monitoring → Settings'); read by AlertsPanel.php:95 and AlertEvaluator.php:62; app/Services/Monitoring/Alerting/AlertNotifier.php:19 (fired) and :34 (recovered); log line always, email only when the rule's notify_email is true (:66) to notify_channels or mail.from.address (:90); app/Services/Monitoring/Alerting/MetricResolver.php:27 (:30 REQUE
- No surface on — Seller Web
- Ruled: belongs in Admin → Monitoring, which is registered GET-only. Three compounding failures mean nothing ever pages anyone: the scheduled monitoring:evaluate carries no --seed and there is no seeder, so a fresh install evaluates zero rules forever; no route can write a rule, so a threshold change is a hand-written SQL INSERT; and every shipped rule is created with notify_email=false with no screen to enable it, so alerts land only in laravel.log. The evaluator, incident correlator, cooldown machine, metric resolver and email notifier are all built and unreachable.

**Exception capture — grouped exceptions with stack traces, occurrence counts, affected users, and marking one resolved**  
`monitoring` · owner: Developer  
- Backend — Tables exist: database/migrations/2026_08_24_000001_create_monitoring_core_tables.php:110 (monitoring_error_groups) and :143 (monitoring_errors). Read by ErrorsPanel.php:175, SecurityPanel.php:1073, AndroidPanel.php:145, IosPanel.php:151, ApisPanel.php:808, DeploymentsPanel.php:553, HealthScoreService.php:370, DeveloperPortal/EndpointHealthService.php:287. Pruned by MonitoringRollup.php:307,337.; app/Services/Monitoring/Panels/ErrorsPanel.php:34 reading monitoring_error_groups and monitoring_errors; GET /admin/monitoring/errors; view resources/views/admin-views/monitoring/sections/errors.blade.php; Columns status/resolved_at/resolved_by on monitoring_error_groups (database/migrations/2026_08_24_000001_create_monitoring_core_tables.php:123-132); filtered by ErrorsPanel.php:55,:117; the capability is described as 'resolve or ignore them' at MonitoringPermissionService.php:38-41
- No surface on — Seller Web, Analytics, Monitor, Dev Portal
- Ruled: belongs to Developer, and it is the single largest hole in the platform. monitoring_error_groups and monitoring_errors are created, read by eight panels and two services and pruned by the rollup, and verified here: the only reference to the table outside readers is the migration itself, because bootstrap/app.php:249 withExceptions() is empty. The Errors page is permanently blank, the health score's error signal permanently unmeasured, and Security's authorisation-failure card, both crash-free cards, the portal's endpoint error lookup and the deploy before/after comparison are all structurally zero — the only error visibility left in the product is the HTTP 5xx rate.

**Defining the customer journeys the synthetic prober fetches**  
`monitoring` · owner: Admin  
- Backend — app/Console/Commands/MonitoringSynthetic.php:27 (list/add/remove, writes monitoring_settings.synthetics at :105 and :140, records an EventLog::CONFIG entry at :108,:143); app/Console/Commands/MonitoringSynthetic.php:29 `monitoring:synthetic {list|add|remove}`; writes monitoring_settings, consumed by app/Services/Monitoring/Checks/SyntheticCheck.php; `synthetics` key in monitoring_settings, read at app/Services/Monitoring/Panels/SyntheticsPanel.php:223-253 and Checks/SyntheticCheck; written only by app/Console/Commands/MonitoringSynthetic.php:105 and :140
- No surface on — Seller Web, Analytics
- Ruled: belongs in Admin → Monitoring → Settings, which SyntheticsPanel.php:470 itself says is read-only in this build. Adding a probe on your own checkout page is a shell command, and before that command existed it was a hand-written INSERT.

**Acknowledging an incident, adding notes, recording probable cause, linking the deploy that caused it and saying who resolved it**  
`monitoring` · owner: Admin  
- Backend — Columns acknowledged_at, notes, probable_cause, cause_evidence, deployment_id, resolved_by on monitoring_incidents (database/migrations/2026_08_24_000002_create_monitoring_operations_tables.php:142-151); declared unwritten at IncidentsPanel.php:25-28 and :1058,:1068
- No surface on — Admin, Seller Web, Monitor
- Ruled: belongs in Admin → Monitoring → Incidents. Six columns on monitoring_incidents have no writer anywhere, so there is no MTTA, no record of who took an incident and no cause attribution even though the deploy and error tables sit beside it — incident handling happens entirely outside the tool.

**Writing a human note onto the monitoring timeline**  
`monitoring` · owner: Admin  
- Backend — app/Console/Commands/MonitoringAnnotate.php:23 (--at backdates the entry); app/Console/Commands/MonitoringAnnotate.php:25 `monitoring:annotate`; writes EventLog::ANNOTATION
- No surface on — Seller Web, Analytics
- Ruled: belongs in Admin → Monitoring. The annotation renders on the admin timeline and can only be written from a shell, because the whole area is GET-only — the command says so at MonitoringAnnotate.php:20 — so an operator reading a chart cannot annotate what they are looking at.

**Recording that a backup ran and that a restore was tested**  
`monitoring` · owner: Admin  
- Backend — app/Console/Commands/MonitoringBackupRecorded.php:31 `monitoring:backup-recorded`; writes monitoring_backups, graded by app/Services/Monitoring/Checks/BackupCheck.php; app/Console/Commands/MonitoringRestoreTested.php:22 `monitoring:restore-tested`
- No surface on — Seller Web, Analytics
- Ruled: belongs in Admin → Monitoring → Backups. Both facts can only be written by shell commands an operator must bolt into their own backup script, so BackupCheck grades the shop degraded permanently for anyone deploying through cPanel or the built-in updater, and the Backups page can only report the gap.

**Recording a deployment, and comparing performance either side of it**  
`monitoring` · owner: Admin  
- Backend — app/Console/Commands/MonitoringDeployRecorded.php:30 `monitoring:deploy-recorded`; writes monitoring_deployments; Columns before_metrics / after_metrics on monitoring_deployments (database/migrations/2026_08_24_000002_create_monitoring_operations_tables.php:175-176); the missing job is named at DeploymentsPanel.php:636
- No surface on — Seller Web, Analytics
- Ruled: belongs in Admin → Monitoring → Deployments. The recording command is the only writer, so the timeline is permanently empty on most installs, and before_metrics/after_metrics have no writer at all — which means the single most useful monitoring sentence, 'p95 doubled at 14:20 and the deploy was at 14:19', cannot be produced.

**Changing a monitoring threshold, retention window, sampling rate or SLA target**  
`monitoring` · owner: Admin  
- Backend — app/Services/Monitoring/Support/MonitoringSettings.php:60 put() exists; monitoring_settings table created at database/migrations/2026_08_24_000002_create_monitoring_operations_tables.php:209 with the comment 'so an operator can change them from the panel without a deploy'; config/monitoring.php:11-206 defaults; live overrides in the `monitoring_settings` table via app/Services/Monitoring/Support/MonitoringSettings.php:41 get() / :60 put(); admin routes at routes/admin/routes.php:227-237; config/monitoring.php:73 retention / :92 tracing / :118 privacy; app/Services/Monitoring/Panels/SettingsPanel.php; section rendered at resources/views/admin-views/monitoring/sections/settings.blade.php:306
- No surface on — Seller Web, Analytics
- OVERRULED — the admin sweep filed this as internal infrastructure; it is not, because config/monitoring.php:154 and the migration both state the live values are edited in Monitoring → Settings, and SettingsPanel then admits it is read-only. MonitoringSettings::put() exists and is called from exactly two non-UI places, and the panels' own remedy strings tell the operator to run php artisan tinker to move a CPU threshold.

**Machine-readable JSON feed of every monitoring section**  
`monitoring` · owner: Developer  
- Backend — app/Http/Controllers/Admin/Telemetry/MonitoringController.php:61 (wantsJson or ?json=1 on the same section URL)
- No surface on — Seller Web, Monitor, Dev Portal
- Ruled: belongs in the Developer Portal. Every section returns its full payload as JSON on the same URL with ?json=1 — a complete monitoring API behind admin session auth — and it appears in no portal screen, no OpenAPI export and no Postman collection.

**Prometheus scrape endpoint and OTLP trace export**  
`monitoring` · owner: Developer  
- Backend — config/monitoring.php:132-135 declares 'GET /monitoring/metrics returns the text exposition format' with MONITORING_PROMETHEUS and MONITORING_PROMETHEUS_TOKEN; reported as a live setting by SettingsPanel.php:535-552 and ApplicationPanel.php:639-650; config/monitoring.php:100-107 (otlp_endpoint, otlp_headers, service_name) declaring 'finished traces are POSTed as OTLP/HTTP JSON by a queued job'
- No surface on — Admin, Seller Web, Monitor, Dev Portal
- Ruled: belongs to Developer to build or to delete the config. Verified: no route matching monitoring/metrics exists anywhere in routes/, and no OTLP exporter or job exists, yet config/monitoring.php documents both and two panels display the Prometheus endpoint as a live setting complete with a security warning about an exposure that cannot happen.

**Seller webhook delivery failure visibility**  
`integrations` · owner: Admin  
- Backend — app/Services/Marketplace/SellerWebhookDispatcher.php:121 (attempt), :194/:231 (failed), app/Jobs/DeliverSellerWebhook.php:35, retried by app/Console/Commands/RetrySellerWebhooks.php scheduled at bootstrap/app.php:182; counted only by app/Services/Marketplace/SellerOperationsOverview.php:325-338
- No surface on — Seller Web, Analytics, Monitor
- Ruled: belongs to Monitoring. The marketplace dispatches signed webhooks to sellers' own systems with a retry ledger and a five-minute retry sweep, and app/Services/Monitoring contains no reference to any of it — no panel, no check, no series, no rule. The only count lives in the admin operations overview, so a seller whose endpoint has rejected every delivery for a week produces nothing an operator would see.

**Blast radius — how many sellers a failure is affecting**  
`monitoring` · owner: Admin  
- Backend — No seller/vendor/shop_id dimension exists in any monitoring table or panel; the only 'vendor' in the model is a traffic channel label (RequestsPanel.php:36) and a user_type on traces (TracesPanel.php:74)
- No surface on — Admin, Seller Web, Analytics, Monitor
- Ruled: belongs to Monitoring as a dimension on every signal. No monitoring table or panel carries a seller, vendor or shop_id — 'vendor' exists only as a request-channel label — so the console can say the queue is backed up or orders are stuck and never whether that is one seller or all of them, which on a marketplace is the first question asked and turns every triage into a manual SQL session.

**Mobile app health ingest — self-reported sessions, crashes and ANRs from the phone apps**  
`monitoring` · owner: Developer  
- Backend — app/Services/Monitoring/Ingest/AppHealthRecorder.php:27; POST api/v1/app-health via app/Http/Controllers/RestAPI/v1/AppHealthController.php:32 (#[ApiDoc], throttle:30,1) registered at routes/rest_api/v1/api.php:81
- No surface on — Seller Web, Flutter App, Analytics, Monitor
- Ruled: belongs to the Flutter app. POST api/v1/app-health exists, is rate-limited, is documented and writes the app.health.* series the Android and iOS panels read — and a grep of the entire seller app finds no caller, so both mobile sections report crash-free sessions as not_configured, which is the one thing about a phone app the server cannot infer.

**Seeing which scheduled tasks are defined, and when each runs next**  
`monitoring` · owner: Admin  
- Backend — app/Services/Monitoring/Collectors/SchedulerCollector.php:104-125 reads app(Schedule::class); Laravel registers it via Artisan::starting() (vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php:362-367), so a web request sees an empty Schedule. Admitted in app/Services/Monitoring/Panels/SchedulerPanel.php:397-424.
- No surface on — Seller Web, Analytics
- Ruled: belongs on the Admin Scheduler page, which cannot serve it as built. Laravel registers the schedule through Artisan::starting(), so a web request resolves an empty Schedule; the page therefore cannot name the tasks that should run, withholds next-due times and the healthy/late/missed counts, and tells the operator to run php artisan schedule:list — i.e. SSH — instead.

**Retrying, forgetting or flushing a failed queue job**  
`monitoring` · owner: Admin  
- Backend — Laravel's failed_jobs table, read by app/Services/Monitoring/Collectors/QueueCollector.php:856,897,922; there is no write route - routes/admin/routes.php:227-236 registers Monitoring as GET-only
- No surface on — Seller Web, Analytics
- Ruled: belongs in Admin → Monitoring → Queues, which already reads failed_jobs and shows the ten most recent failures. Monitoring is registered GET-only, so a day of undelivered order confirmations, seller webhooks or bulk price changes can only be re-driven with php artisan queue:retry over SSH.

**Transactional notification delivery — every order, refund, wallet, OTP, verification, restock, referral and seller-onboarding email, SMS and push**  
`notifications` · owner: Admin  
- Backend — app/Listeners/AddFundToWalletListener.php:17, handle(AddFundToWalletEvent) at line 37; queued (ShouldQueue + QueuedMailDelivery: tries 2, timeout 30, backoff 60); app/Listeners/CashCollectListener.php:9, handle(CashCollectEvent) at line 24; NOT queued - runs inline in the admin request (dispatched app/Http/Controllers/Admin/Deliveryman/DeliveryManCashCollectController.php:68); app/Listeners/ChattingListener.php:9, handle(ChattingEvent) at line 24; NOT queued; app/Listeners/CustomerRegistrationListener.php:18, handle(CustomerRegistrationEvent) at line 32; queued with QueuedMailDelivery; app/Listeners/CustomerStatusUpdateListener.php:23, handle(CustomerStatusUpdateEvent) at line 31; queued; app/Listeners/DeliverymanPasswordResetListener.php:18, handle(DeliverymanPasswordResetEvent) at line 32; app/Listeners/DigitalProductDownloadListener.php:18, handle(DigitalProductDownloadEvent) at line
- No surface on — Seller Web, Analytics
- Ruled: belongs in Admin as a delivery log with a resend action, and it is the failure mode most invisible without opening the database. Twenty-three listeners send the platform's entire transactional traffic and not one records whether the message arrived: the fourteen SMS providers return the literal string 'error' and persist nothing, Mail:: bypasses the HTTP-client middleware entirely, and FCM push goes through a trait — so a shop whose SMS credentials expired sends no OTP, no customer can sign in, and every screen in the monitoring console stays green. Queued listeners at least land in failed_jobs; the eight that are not queued (chat, order status, cash collect, referral, delivery-man withdraw, refund) run inline and leave nothing at all. OVERRULED on one point: the jobs sweep recorded OrderEditDuePaymentListener as mis-bound to OrderEditEvent; it now type-hints OrderEditDuePaymentEvent and the due-payment notification fires correctly.

**Email template mail tester**  
`notifications` · owner: Admin  
- Backend — app/Http/Controllers/Admin/EmailTemplatesController.php:31 index() -> view('email-templates.mail-tester'); routes/admin/routes.php:1172 admin.system-setup.email-templates.index — referenced by no menu and no view
- No surface on — Seller Web, Flutter App, Analytics, Monitor
- Ruled: belongs in the Admin sidebar, one link away. The page renders and works; the sidebar points at the /{type}/{tab} view route instead, so the only way to test transactional mail is to type the URL.

**Authentication events — sign-in success, sign-in failure and lockout for admins, sellers and seller staff**  
`security` · owner: Admin  
- Backend — No auth.* action is recorded anywhere: grep of app/ and Modules/ finds no AuditLogger call in app/Http/Controllers/Admin/Auth/ or app/Http/Controllers/Vendor/Auth/; the gap is stated as a remedy in app/Services/Monitoring/Panels/SecurityPanel.php:714 and detected at :711 credentials()
- No surface on — Admin, Seller Web, Flutter App, Dev Portal
- Ruled: belongs on the audit trail, and the monitoring panel already prints the fix. A rejected password leaves no trace anywhere in the application: no auth.* action exists in app/ or Modules/, and the Admin and Vendor auth controllers contain zero AuditLogger calls, so a credential-stuffing run against the seller panel is indistinguishable from silence and monitoring can only count 401 responses, which measures refusal by any cause.

**The before/after values and actor context on every audited change**  
`security` · owner: Admin  
- Backend — written by app/Services/AuditLogger.php:47-48 into audit_logs.before / audit_logs.after; rendered only as a badge in resources/views/admin-views/marketplace/audit-log.blade.php:96 ('changed'); dropped entirely by the Flutter model /home/user/sillercenter-syria-cosmatics/lib/features/security/domain/models/security_models.dart:99; app/Services/AuditLogger.php:51-52 (clientIp/userAgent, stored on audit_logs.ip_address and .user_agent); returned by app/Services/Marketplace/SellerAuditTrailService.php:111; never rendered in resources/views/admin-views/marketplace/audit-log.blade.php (no ip_address reference in the file)
- No surface on — Seller Web, Analytics, Monitor
- Ruled: belongs on the Admin audit page and the seller's own trail — rows nobody can read are as much a gap as rows never written. AuditLogger captures before, after, ip_address and user_agent on every row; the admin page renders the word 'changed' and nothing else, and the Flutter model drops the diff entirely while its tile shows three of the eight returned fields. The bank-details change PayoutService records specifically so a fraud review can see what the account was redirected from and to cannot be read on any screen in this system.

**Who may read the audit trail**  
`security` · owner: Admin  
- Backend — routes/admin/routes.php:549 (['prefix' => 'marketplace', 'middleware' => ['module:marketplace']]) wrapping the audit-log route at :577; enforcement in app/Http/Middleware/ModulePermissionMiddleware.php:20
- No surface on — Seller Web, Flutter App, Analytics, Monitor, Dev Portal
- Ruled: belongs behind its own permission. The only screen that reads the trail sits inside the marketplace route group, so an admin role without that unrelated module flag cannot see a single audit row while theme, commerce, developer-console and approval events keep writing to it.

**The seller's web view of their own audit trail**  
`security` · owner: Seller  
- Backend — declared in the navigation registry at app/Services/SellerCenter/Navigation.php:193 as route 'seller.audit.index'; that route does not exist in routes/seller/routes.php (the file defines 37 routes, none named audit); the menu item is silently dropped by the route-existence filter at app/Services/SellerCenter/Navigation.php:230
- No surface on — Seller Web, Analytics, Monitor
- Ruled: belongs in the Seller Center, where the IA already reserves seller.audit.index — verified absent from the route table, so the route-existence filter silently drops the menu item. A seller on a browser cannot see what happened in their own shop; only the phone app can, and it drops the before/after values.

**Admin employee accounts and admin custom roles — who operates the platform and which modules they may touch**  
`security` · owner: Admin  
- Backend — app/Http/Controllers/Admin/Employee/EmployeeController.php and app/Http/Controllers/Admin/Employee/CustomRoleController.php (both contain zero AuditLogger references); routes routes/admin/routes.php:520 admin.employee.* and :533 admin.custom-role.*, including custom-role update at :537 and employee-role-status at :539
- No surface on — Seller Web, Analytics, Monitor, Dev Portal
- Ruled: belongs on the audit trail. Verified zero AuditLogger references in EmployeeController and CustomRoleController: the platform audits every change to a seller's permission model with before/after and none to its own, including granting an employee the 'marketplace' module that unlocks the audit page itself.

**Business settings — the several hundred DB-driven switches the whole platform boots from**  
`security` · owner: Admin  
- Backend — app/Services/BusinessSettingService.php and app/Services/SettingService.php (zero AuditLogger references); app/Http/Controllers/Admin/BusinessSettings/WebsiteSetupController.php (zero); route groups routes/admin/routes.php:1292, :1402, :1552, :1621, :1679; the gap is measured by app/Services/Monitoring/Panels/SecurityPanel.php:76 which looks for a 'setting' action family that never exists; app/Services/AuditLogger.php:29 record(), surfaced read-only at routes/admin/routes.php:577 admin.marketplace.audit-log
- No surface on — Seller Web, Analytics, Dev Portal
- Ruled: belongs on the audit trail, and it matters more here than in most codebases because CLAUDE.md is explicit that behaviour on this platform is DB-driven rather than code-driven. Only 11 of 139 admin controllers call AuditLogger and none of them is under Admin/Settings, so changing the commission percentage, the OTP lockout window, the storage backend, maintenance mode or the forced minimum app version leaves no record of who did it or what it was before — most behavioural change on this platform is unaudited by construction.

**reCAPTCHA on customer login, registration and both forgot-password flows, and the bot score that refuses a shopper**  
`security` · owner: Admin  
- Backend — app/Services/RecaptchaService.php:13 and :72 read business_settings key `recaptcha` ({status, site_key, secret_key}); enforced from app/Http/Controllers/Customer/Auth/CustomerAuthController.php:52,:368,:527, Customer/Auth/ForgotPasswordController.php:58,:151,:340 and Vendor/Auth/ForgotPasswordController.php:77; app/Services/RecaptchaService.php:27 (if (($data['score'] ?? 0) < 0.5))
- No surface on — Admin, Seller Web, Flutter App, Analytics, Dev Portal
- Ruled: belongs in Admin Settings. Verified by grep: the recaptcha key has read sites in RecaptchaService and the monitoring integrations panel and no writer anywhere in app/Http/Controllers/Admin or resources/views, so the platform's only bot defence on its authentication forms is seeded off at install and can be enabled — or its secret rotated — only by editing the database; the 0.5 score floor beside it is a class constant, and 0.5 is precisely the number an operator lowers when real customers start being blocked.

**Which channel a customer password reset is sent through — email or SMS OTP**  
`security` · owner: Admin  
- Backend — business_settings key `forgot_password_verification`, read at app/Http/Controllers/Customer/Auth/ForgotPasswordController.php:48, app/Http/Controllers/RestAPI/v1/auth/ForgotPasswordController.php:43 and exposed to the apps at app/Http/Controllers/RestAPI/v1/ConfigController.php:183
- No surface on — Admin, Seller Web, Analytics, Monitor
- Ruled: belongs in Admin Settings, where the vendor and delivery-man equivalents already have screens. Only the customer one has none, so switching customer account recovery to SMS is a hand-edited row.

**Seller staff reaching the shop's own analytics page**  
`security` · owner: Seller Staff  
- Backend — routes/vendor/routes.php:104 — GET vendor/analytics; the segment 'analytics' is absent from the map at app/Http/Middleware/SellerStaffAccessMiddleware.php:65-128 and therefore hits default => DENY
- No surface on — Admin, Seller Web, Monitor
- Ruled: a defect belonging to Developer in one line. Verified in the current file: the segment 'analytics' is still absent from the permission map in SellerStaffAccessMiddleware, so deny-by-default 403s every staff member on /vendor/analytics while the same person's API token reaches seller-center/analytics under finance.view — the two clients disagree about what a staff member may see.

**The authentication requirement the portal reports for the v2 seller API**  
`security` · owner: Developer  
- Backend — Route group routes/rest_api/v2/api.php:27 declares no auth middleware; the controllers authenticate in-line via Helpers::get_seller_by_token() (app/Utils/Helpers.php:523), used 48 times across app/Http/Controllers/RestAPI/v2/seller/
- No surface on — Seller Web, Analytics, Monitor
- Ruled: a defect belonging to Developer, and the single most dangerous claim the portal makes. Verified: routes/rest_api/v2/api.php:27 declares only api_lang, the controllers authenticate in-line through Helpers::get_seller_by_token(), and AuthResolver reads middleware only — so the portal tells every reader that balance-withdraw, shop-update and product delete on 55 live endpoints need no credentials. That is the one direction an auth resolver must never be wrong in.

**The permission scope an endpoint requires, and which endpoints a seller-issued API key may call**  
`security` · owner: Developer  
- Backend — app/Services/DeveloperPortal/Support/AuthResolver.php:182 permissions() — matches only `module:` and `can:` middleware; merged with ApiDoc::$scopes at app/Services/DeveloperPortal/ApiManifest.php:265; rendered at resources/views/admin-views/telemetry/developer-endpoint.blade.php:68; app/Http/Middleware/SellerApiAuthMiddleware.php:70 (a key is refused with 403 `api_key` unless the route declares a seller_can scope) / :96 routeDeclaresAScope(); scopes declared in routes/rest_api/v3/seller.php:23-622
- No surface on — Seller Web, Analytics, Monitor
- Ruled: belongs in the Developer Portal and resolves empty for all 537 endpoints. Verified in the current file: AuthResolver::permissions() matches only module: and can:, while the real gate on the seller API is seller_can: (53 route groups), and no controller anywhere passes ApiDoc(scopes:). On top of that, SellerApiAuthMiddleware refuses a key unless the route declares a scope — 232 of 248 seller endpoints accept a key and 16 refuse one — and that split is written down nowhere, so an integrator discovers it by getting a 403.

**Documented intent for the API — 438 of 537 endpoints carry no declared contract**  
`integrations` · owner: Developer  
- Backend — routes/rest_api/v1/api.php:52 onward; app/Http/Controllers/RestAPI/v1/ProductController.php:59 (1 of 31 documented), CustomerController.php:42 (0 of 23), OrderController.php:48 (0 of 15), CartController.php:43 (0 of 9); routes/rest_api/v1/api.php:103 (throttle:20,1 group); app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:44 (0 of 12), SocialAuthController.php:32 (0 of 5), PassportAuthController.php:203, PhoneVerificationController.php:55, EmailVerificationController.php:79, ForgotPasswordController.php:140; routes/rest_api/v2/api.php:132 / :144 (delivery_man_auth + actch:deliveryman_app); app/Http/Controllers/RestAPI/v2/delivery_man/DeliveryManController.php:45 (0 of 30), ChatController.php:21, WithdrawController.php:18, auth/LoginController.php:21; routes/rest_api/v3/seller.php:72 onward; app/Http/Controllers/RestAPI/v3/seller/ProductController.php:81 (0 of 30), Seller
- No surface on — Admin, Seller Web, Analytics
- Ruled: belongs to Developer as an #[ApiDoc] pass. The manifest describes all 537 endpoints mechanically and the miss count against the route table is zero, but only 99 carry a declared contract and 86 of those are the v3 Seller Center alone (86/86). Outside it: v2 is 0/95, v1 is 11/185, the rest of v3 is 2/170 — so the entire shopper app API, the entire delivery app API, 20 unauthenticated customer auth endpoints, 29 AI endpoints that spend money per call and the tax endpoints are all undescribed.

**API deprecation lifecycle and the change/breaking-change log**  
`integrations` · owner: Admin  
- Backend — Declared only in code by ApiDoc(stability/deprecatedSince/sunsetAt/replacedBy) — app/Services/DeveloperPortal/ApiDoc.php:53,81-83; surfaced by DeveloperPortalService::deprecations() (:364), OpenApiGenerator.php:201, PostmanGenerator.php:267 and ApisPanel.php:743; app/Console/Commands/ApiSnapshotCommand.php:16 (signature `api:snapshot`)
- No surface on — Seller Web, Analytics
- Ruled: belongs in the Developer Portal and is fully built and never run. Four surfaces are wired to render deprecations (portal screen, OpenAPI flag, Postman annotation, Monitoring panel) and zero endpoints declare one; the snapshot service, diff engine and severity classification exist, api_snapshots holds no rows, and verified here — api:snapshot is absent from a scheduler that runs 20 other commands. Three live API versions and no retirement machinery in use.

**Documentation for outbound seller webhooks — the event catalogue, the signature, the retry policy and the auto-disable behaviour**  
`integrations` · owner: Developer  
- Backend — Declared at app/Services/DeveloperPortal/PortalNavigation.php:55 with requires:'webhooks'; the capability probe returns true (app/Services/Telemetry/DeveloperPortalService.php:588 hasInboundWebhooks()); no branch in DeveloperPortalController::dataFor() (:236); no view; Declared at app/Services/DeveloperPortal/ApiDoc.php:87 and :88; carried into the manifest at app/Services/DeveloperPortal/ApiManifest.php:262-263
- No surface on — Seller Web, Analytics, Monitor, Dev Portal
- Ruled: belongs in the Developer Portal's webhooks section, which is the worst of the placeholders: the capability probe returns true so the entry renders enabled and opens onto an empty card, while a complete signed-delivery system with six events, SSRF-guarded dialling and a retry sweep sits beside it. ApiDoc carries emits and dependsOn into every manifest entry and no view renders either — and the only two endpoints that declare emits name events that do not exist in the real webhook vocabulary.

**Portal sections that render a placeholder — models and enums, integrations, and portal settings**  
`integrations` · owner: Developer  
- Backend — Declared at app/Services/DeveloperPortal/PortalNavigation.php:33; no branch in app/Http/Controllers/Admin/Telemetry/DeveloperPortalController.php:236 dataFor(); no view under resources/views/admin-views/telemetry/developer/; Declared at app/Services/DeveloperPortal/PortalNavigation.php:56; no branch in DeveloperPortalController::dataFor() (:236); no view. Monitoring already ships the equivalent at resources/views/admin-views/monitoring/sections/integrations.blade.php; Declared at app/Services/DeveloperPortal/PortalNavigation.php:58; no view. The real switches are env-only in config/developer_portal.php:22,46,47,49
- No surface on — Admin, Seller Web, Analytics, Dev Portal
- Ruled: belongs to Developer to build or unlist. DeveloperPortalController::dataFor() has no branch for any of them and no blade exists. Portal settings is the costliest: console enable, console writes, console rate limit and response-shape recording are env-only, so an operator cannot turn the Try It console off without a deploy — and the integrations section duplicates a screen Monitoring already has, so the honest fix there is a link.

**Request debugger — look up an X-Request-Id and see what happened**  
`monitoring` · owner: Admin  
- Backend — Declared at app/Services/DeveloperPortal/PortalNavigation.php:51; no branch in DeveloperPortalController::dataFor() (:236); no view; the request_id it promises is recorded by Monitoring (app/Services/Monitoring/Panels/ErrorsPanel.php:570, LogsPanel.php:623)
- No surface on — Admin, Seller Web, Analytics, Dev Portal
- Ruled: belongs in the Developer Portal or Monitoring, and the advice already points at it: the Errors section tells developers to keep the X-Request-Id because it is what makes a failure findable, Monitoring records request_id in its errors and logs panels, and there is no lookup-by-id screen anywhere.

**Creating, editing, repointing or deleting a seller's outbound webhook**  
`integrations` · owner: Seller  
- Backend — app/Http/Controllers/RestAPI/v3/seller/SellerIntegrationController.php:192 storeWebhook, :242 updateWebhook, :288 setWebhookStatus, :330 destroyWebhook; routes routes/rest_api/v3/seller.php:524-527; the file contains zero audit references
- No surface on — Seller Web, Analytics
- Ruled: belongs on the audit trail. Only the two paths that switch a webhook OFF are audited — the dispatcher's auto-disable and the admin kill switch — so repointing a live webhook at a new destination, which is how a shop's event data would be exfiltrated, writes nothing.

**Which AI model writes seller content, and how creative it is allowed to be**  
`integrations` · owner: Admin  
- Backend — Modules/AI/AIProviders/OpenAIProvider.php:39 ('model' => 'gpt-4o') and :46 ('temperature' => 0.3); provider selection is DB-driven via Modules/AI/AIProviders/AIProviderManager.php
- No surface on — Analytics, Monitor, Dev Portal
- Ruled: belongs in the AI module's admin settings, which already choose the provider from the database. Because the model name and temperature are hardcoded in the provider class, an operator can switch vendors but cannot change model or cost per call.

**AI provider credentials — the API key and organisation id the AI module runs on**  
`integrations` · owner: Admin  
- Backend — Modules/AI/app/Http/Controllers/Admin/AISettingController.php:37 store(), writing api_key at :45 and the enable flag at :47; no AuditLogger reference anywhere under Modules/ (grep of Modules/ for AuditLogger and audit_logs returns nothing)
- No surface on — Seller Web, Flutter App, Analytics, Monitor, Dev Portal
- Ruled: belongs on the audit trail. Verified by grep: no module — AI, Blog or TaxModule — writes a single audit row, so replacing the credential the whole AI module spends money through is one unrecorded form post.

**Paid advertising and sponsored placement — ad slots, budgets, billing**  
`platform` · owner: Admin  
- Backend — None found. Searched app/, Modules/, routes/ and database/migrations for advertis*, sponsored, ad_campaign, ads_ — only BannerService and unrelated substring hits
- No surface on — Admin, Seller Web, Flutter App, Analytics, Monitor, Dev Portal
- Ruled: belongs in Admin and has no backend at all. Searched app/, Modules/, routes/ and database/migrations for advertis*/sponsored/ad_campaign: only BannerService and unrelated substring hits. The marketplace can place its own banners and run merchandising overlays but cannot sell placement to a seller, price it, cap it or bill for it — a revenue line with nothing behind it, and the Seller Center advertises a destination for it that does not resolve.

**Feature flags and gradual rollout**  
`platform` · owner: Admin  
- Backend — None found. Searched app/, config/, routes/ and database/migrations for FeatureFlag, feature_flag, flag tables — nothing. Closest surfaces: addon publish/unpublish (routes/admin/routes.php:1081-1089), addon licence activation (:1091-1097) and modules_statuses.json
- No surface on — Admin, Seller Web, Flutter App, Analytics, Monitor, Dev Portal
- Ruled: belongs in Admin. No flag table, no config, no per-seller or per-percentage switch anywhere; the only lever is publishing or unpublishing an entire addon module, so every change to the marketplace is all-or-nothing for everyone at once.

**Duplicate addon manager mounted at /admin/addon**  
`platform` · owner: Admin  
- Backend — routes/admin/routes.php:1063-1071 admin.addon.* — the same AddonController and the same five actions already registered at :1081 under system-setup, outside the themes_and_addons module gate
- No surface on — Seller Web, Flutter App, Analytics, Monitor
- Ruled: belongs to Developer to delete — the gated twin under system-setup is canonical. Verified in the route file: the same controller and the same five actions including upload and delete, linked from no view and no menu, and unlike the twin it sits outside the themes_and_addons module gate, so an admin denied that permission can still publish and delete platform modules through it.

