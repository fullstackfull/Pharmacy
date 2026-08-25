# Parity — returns_refunds

[← back to the matrix](../SELLER_WEB_APP_PARITY.md) · 36 capabilities

**14** BOTH · **13** WEB MISSING · **4** APP MISSING · **3** WEB ENHANCEMENT · **1** DEVICE SPECIFIC · **1** BACKEND MISSING

## Structural facts the implementer must know

```
STRUCTURE. Two distinct sub-domains sit under this heading and they are NOT the same feature. (1) refund_requests — a customer asks for money back; the seller approves/rejects. Present on both surfaces. (2) return_shipments (RMA) — the physical goods coming back, with an authorized → in_transit → received/restocked → rejected state machine that writes stock movements. Present ONLY in the Flutter app (lib/features/returns/*) and the ADMIN web panel; the vendor web panel has nothing: grep -c 'returns' /home/user/Pharmacy/routes/vendor/routes.php = 0, and grep 'ReturnShipment|ReturnLogisticsService' over /home/user/Pharmacy/app/Http/Controllers/Vendor/ and /home/user/Pharmacy/resources/views/vendor-views/ = 0 hits. Eight of the WEB MISSING rows are this one absent feature; implement them as a single vendor returns module modelled on /home/user/Pharmacy/app/Http/Controllers/Admin/Marketplace/ReturnLogisticsController.php + /home/user/Pharmacy/resources/views/admin-views/marketplace/returns.blade.php, scoped by seller_id exactly as /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerReturnController.php:236 does.

HIGHEST-SEVERITY FINDING (not cosmetic). Approving a refund through the seller API opens the RMA (/home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/RefundController.php:206-208 → openReturnFor at :225-257). Approving the same refund through the VENDOR WEB does not: /home/user/Pharmacy/app/Http/Controllers/Vendor/RefundController.php:151-184 writes refund status, order_details.refund_request, a refund_status row and fires RefundEvent — and RefundEvent only sends notifications (/home/user/Pharmacy/app/Listeners/RefundListener.php:24-27). So a seller who approves on the web gives back the money and never gets the units back, while the same seller approving in the app does. Fix by extracting openReturnFor() into a shared service call used by both controllers (it is already idempotent on refund_request_id — /home/user/Pharmacy/app/Services/Marketplace/ReturnLogisticsService.php:183-204 — so backfilling is safe).

BUSINESS-RULE DIVERGENCE BETWEEN THE TWO REFUND PATHS. The web enforces a two-decision cap and maintains the counters (approved_count/denied_count: /home/user/Pharmacy/app/Http/Controllers/Vendor/RefundController.php:130-132, 175-176); the API enforces nothing and never increments them (/home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/RefundController.php:129-216). A seller can therefore evade the cap by deciding in the app, and the web's warning banners (details.blade.php:36-69) will misreport how many attempts remain. Also: approved_note is REQUIRED on web (/home/user/Pharmacy/app/Http/Requests/Vendor/RefundStatusRequest.php:32) but optional on the API (RestAPI/v3/seller/RefundController.php:135); and loyalty-point sufficiency is checked for both 'approved' and 'refunded' on web (Vendor/RefundController.php:141-143) but only 'approved' on the API (:162). Whichever direction you unify, do it in one service — the two controllers are currently parallel re-implementations.

LATENT WEB BUG worth fixing while in this file: /home/user/Pharmacy/app/Http/Controllers/Vendor/RefundController.php:153 calls $this->loyaltyPointTransactionRepo->addLoyaltyPointTransaction(...) but that property is not injected in the constructor (:35-46) and BaseController declares no properties (/home/user/Pharmacy/app/Http/Controllers/BaseController.php:11-14). It is only reachable when refund_status == 'refunded' with loyalty points enabled — a status the vendor UI never submits but RefundStatusRequest:31 accepts — so a hand-crafted POST fatals there. Also details.blade.php:341 prints $refund['created_at'] on every status-log row instead of $status->created_at, so the log shows one date repeated.

REFUND AMOUNT IS COMPUTED TWICE, DIFFERENTLY. The API uses OrderManager::getRefundDetailsForSingleOrderDetails (RestAPI/v3/seller/RefundController.php:110-121); the web recomputes coupon/referral/subtotal inline (Vendor/RefundController.php:104-115). Any parity work on the amount breakdown should collapse these onto the OrderManager helper, or the two surfaces can legitimately quote a customer different refundable amounts.

SETTINGS / TOGGLES / CLIENT-SIDE STATE. I searched the Flutter refund and returns features for SharedPreferences, settings, preferences, config, toggles and feature flags: none exist — no business state is stored client-side in this domain. The only local state is UI state and it is legitimate: the selected date preset (String result, refund_screen.dart:29), the filter-dot flag (_isFilterActive, refund_controller.dart:58, 263-268) and the selected status tab index (_refundTypeIndex, refund_controller.dart:39). Two caveats: (a) the restock flag is deliberately a server-side decision taken at receipt, not a client toggle (SellerReturnController.php:159-163) — keep it that way on the web; (b) the app buckets refunds into status tabs client-side after downloading everything (refund_controller.dart:188-201), which is presentation only but see pagination below.

PAGINATION / SCALE. GET /api/v3/seller/refund/list has no limit and returns ->get() on the whole history (RestAPI/v3/seller/RefundController.php:75) — the app downloads every refund the seller ever had, then filters locally. The web paginates properly (pagination_limit, Vendor/RefundController.php:80). The returns API does paginate (SellerReturnController.php:58, limit/offset at :334-342) and the repository accepts an offset (return_repository.dart:12), but ReturnController never advances it (return_controller.dart:37-56), so the app only ever shows the newest 25 returns. Build the web returns list with real pagination and consider capping the refund API.

DEAD / DEBUG CODE IN THE APP (flag for cleanup, not parity): setDummyRefundData() fabricates fake refund rows in the production controller (/home/user/sillercenter-syria-cosmatics/lib/features/refund/controllers/refund_controller.dart:63-125, invoked only from commented-out initState at refund_screen.dart:40-52); getRefundStatusList returns a hardcoded local list and is never called (refund_repository.dart:37-50); getRefundList is called from build() (refund_screen.dart:55), firing a network request on every rebuild; and after a decision the controller sets _refundTypeIndex to 1 for 'approved' and 2 for 'rejected' (refund_controller.dart:137-141) while the tabs are 1=pending, 2=approved, 3=rejected (refund_screen.dart:243-247) — so the app lands on the wrong tab after every decision.

INSIGHTS. The backend produces returns-category insights for sellers — unanswered refund requests past 48h and returns stuck unprocessed past 72h — with actionKeys 'open_refund' and 'open_returns' (/home/user/Pharmacy/app/Services/SellerIntelligence/Producers/ReturnsRiskProducer.php:82, 121). Neither surface consumes them for this domain: the Flutter action handler only implements open_order and open_product (/home/user/sillercenter-syria-cosmatics/lib/features/action_center/widgets/insight_action_handler.dart:31-43), so those insights are tappable dead ends; the vendor web has no insight UI at all (grep -l insight over resources/views/vendor-views/ → nothing). Cheap win once the web returns queue exists.

PERMISSIONS. API: refund endpoints all require seller_can:orders.manage (routes/rest_api/v3/seller.php:246); returns reads allow orders.view OR orders.manage while every write requires orders.manage (routes/rest_api/v3/seller.php:641-648). Web: SellerStaffAccessMiddleware maps the whole /vendor/refund area to orders.manage for read and write alike (app/Http/Middleware/SellerStaffAccessMiddleware.php:89) — a staffer with orders.view can read refunds in the app but not on the web. When adding /vendor/returns, add it to that match block (mirroring the API split: orders.view to read, orders.manage to act) or it falls through to default => DENY and every staff member is locked out.
```

## BOTH (14)

**Browse the refund requests customers raised against this seller's orders**  
`orders.manage (API: routes/rest_api/v3/seller.php:246 seller_can:orders.manage; web: app/Http/Middleware/SellerStaffAccessMiddleware.php:89 'refund' => 'orders.manage')`  
- App — Yes — /home/user/sillercenter-syria-cosmatics/lib/features/refund/screens/refund_screen.dart:55 (getRefundList) rendering RefundWidget cards
- Web — Yes — /home/user/Pharmacy/resources/views/vendor-views/refund/index.blade.php:37-139 table, reached from sidebar /home/user/Pharmacy/resources/views/layouts/vendor/partials/v2/_side-bar.blade.php:146-147
- Server — GET /api/v3/seller/refund/list (routes/rest_api/v3/seller.php:248 → app/Http/Controllers/RestAPI/v3/seller/RefundController.php:24-77) | web: vendor.refund.index (routes/vendor/routes.php:144 → app/Http/Controllers/Vendor/RefundController.php:53-84)
- Evidence — Flutter list call /home/user/sillercenter-syria-cosmatics/lib/features/refund/domain/repositories/refund_repository.dart:97-104 hitting AppConstants.refundListUri (/home/user/sillercenter-syria-cosmatics/lib/utill/app_constants.dart:49); web list built in /home/user/Pharmacy/app/Http/Controllers/Vendor/RefundController.php:63-84 and rendered at /home/user/Pharmacy/resources/views/vendor-views/refund/index.blade.php:50-137

**Filter refund requests by status (pending / approved / rejected / refunded)**  
`orders.manage`  
- App — Yes — RefundTypeButton tabs at /home/user/sillercenter-syria-cosmatics/lib/features/refund/screens/refund_screen.dart:243-249, bucketed client-side in refund_controller.dart:188-201
- Web — Yes — status tabs /home/user/Pharmacy/resources/views/vendor-views/refund/index.blade.php:19-22 hitting vendor.refund.index/{status}
- Server — Web filters server-side (app/Repositories/RefundRequestRepository.php:80-82); API returns all rows and the app buckets them locally
- Evidence — Flutter buckets in /home/user/sillercenter-syria-cosmatics/lib/features/refund/controllers/refund_controller.dart:192-200 against AppConstants.pending/approved/rejected/done (/home/user/sillercenter-syria-cosmatics/lib/utill/app_constants.dart:261-266); web /home/user/Pharmacy/app/Http/Controllers/Vendor/RefundController.php:72-76

**Filter refund requests by a custom date range**  
`orders.manage`  
- App — Yes — custom_date opens VatFilterBottomSheet(formRefund:true) at /home/user/sillercenter-syria-cosmatics/lib/features/refund/screens/refund_screen.dart:134-149, applied at /home/user/sillercenter-syria-cosmatics/lib/features/vat_management/widgets/vat_filter_bottomsheet.dart:141
- Web — Yes — from_date/to_date offcanvas /home/user/Pharmacy/resources/views/vendor-views/refund/partials/_filter-offcanvas.blade.php:18-38 with Clear Filter at :46-48
- Server — API: date_type=custom_date + start_date/end_date (RestAPI/v3/seller/RefundController.php:30-44,72-74) | web: from_date/to_date whereBetween (app/Repositories/RefundRequestRepository.php:83-89)
- Evidence — Flutter /home/user/sillercenter-syria-cosmatics/lib/features/refund/domain/repositories/refund_repository.dart:97-104; web /home/user/Pharmacy/app/Http/Controllers/Vendor/RefundController.php:67-76 + /home/user/Pharmacy/app/Repositories/RefundRequestRepository.php:83-89

**Open one refund request and see its full detail**  
`orders.manage`  
- App — Yes — RefundDetailsScreen (/home/user/sillercenter-syria-cosmatics/lib/features/refund/screens/refund_details_screen.dart:39 getRefundReqInfo) composed by refund_details_widget.dart:78-338
- Web — Yes — /home/user/Pharmacy/resources/views/vendor-views/refund/details.blade.php:34-370 via vendor.refund.details
- Server — GET /api/v3/seller/refund/refund-details?order_details_id= (routes/rest_api/v3/seller.php:250 → RestAPI/v3/seller/RefundController.php:95-127) and GET single-item (:79-93) | web: app/Http/Controllers/Vendor/RefundController.php:90-116
- Evidence — Flutter repo call /home/user/sillercenter-syria-cosmatics/lib/features/refund/domain/repositories/refund_repository.dart:14-21 (AppConstants.refundItemDetails, app_constants.dart:50); web route /home/user/Pharmacy/routes/vendor/routes.php:145

**See the refundable amount broken down (price x qty, product discount, coupon discount, referral discount, tax, subtotal, total refundable)**  
`orders.manage`  
- App — Yes — RefundPricingWidget /home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/refund_pricing_widget.dart:56-89
- Web — Yes — amount list /home/user/Pharmacy/resources/views/vendor-views/refund/details.blade.php:161-229 (qty, total price, total discount, coupon discount, referral discount, tax, subtotal, refundable amount)
- Server — API computes via OrderManager::getRefundDetailsForSingleOrderDetails (RestAPI/v3/seller/RefundController.php:109-121); web computes inline (app/Http/Controllers/Vendor/RefundController.php:104-115)

**Read the customer's refund reason in full**  
`orders.manage`  
- App — Yes — /home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/refund_details_widget.dart:251-297 (full text, no truncation)
- Web — Yes — truncated at 100 chars with a See More modal /home/user/Pharmacy/resources/views/vendor-views/refund/details.blade.php:234-243 + partials/_refund-reason-modal.blade.php:14-17
- Server — refund_reason on the refund request row, returned by both list/detail payloads
- Evidence — Flutter /home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/refund_details_widget.dart:280-286 and list preview refund_widget.dart:155-176; web /home/user/Pharmacy/resources/views/vendor-views/refund/details.blade.php:236-243

**View the customer's uploaded refund evidence images full-screen**  
`orders.manage`  
- App — Yes — thumbnail strip /home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/refund_attachment_list_widget.dart:16-46 opening ImageDialogWidget (image_diaglog_widget.dart:13-45)
- Web — Yes — thumbnail strip /home/user/Pharmacy/resources/views/vendor-views/refund/details.blade.php:245-272 opening the carousel modal partials/_img-view-modal.blade.php:6-47
- Server — images/images_full_url on the refund request (Flutter model refund_model.dart:59-65; web $refund->images_full_url)
- Evidence — Flutter /home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/refund_attachment_list_widget.dart:25; web /home/user/Pharmacy/resources/views/vendor-views/refund/partials/_img-view-modal.blade.php:27-34

**See and contact the delivery man attached to the refunded order**  
`orders.manage`  
- App — Yes — DeliveryManInfoWidget /home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/delivery_man_info_widget.dart:42-85 (tel: at :52, mailto: at :69-74)
- Web — Yes — deliveryman info card /home/user/Pharmacy/resources/views/vendor-views/refund/details.blade.php:275-308 (mailto at :296, tel at :304)
- Server — API returns deliveryman_details (RestAPI/v3/seller/RefundController.php:122); web reads $order->deliveryMan
- Evidence — Flutter mount point /home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/refund_details_widget.dart:311-312; web /home/user/Pharmacy/resources/views/vendor-views/refund/details.blade.php:281-306

**Approve a refund request, with an approval note**  
`orders.manage`  
- App — Yes — approve button + confirmation dialog /home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/approve_reject_widget.dart:101-133 → updateRefundStatus(...,'approved',note)
- Web — Yes — approve modal from the detail page /home/user/Pharmacy/resources/views/vendor-views/refund/details.blade.php:398-424 and from the list row partials/_approval-modal.blade.php:1-31
- Server — POST /api/v3/seller/refund/refund-status-update (routes/rest_api/v3/seller.php:251 → RestAPI/v3/seller/RefundController.php:129-216) | web: vendor.refund.update-status (routes/vendor/routes.php:146... :146 is export; update-status is :145+1 → routes/vendor/routes.php:145) → app/Http/Controllers/Vendor/RefundController.php:122-188
- Evidence — Flutter call chain approve_reject_widget.dart:121 → refund_controller.dart:129-149 → refund_repository.dart:24-34; web form /home/user/Pharmacy/resources/views/vendor-views/refund/details.blade.php:402-419 posting to vendor.refund.update-status (/home/user/Pharmacy/routes/vendor/routes.php:145). Note: the approval note is REQUIRED on web (app/Http/Requests/Vendor/RefundStatusRequest.php:32) but optional on the API (RestAPI/v3/seller/RefundController.php:135)

**Reject a refund request with a mandatory reason/note**  
`orders.manage`  
- App — Yes — reject button + note dialog /home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/approve_reject_widget.dart:58-96, empty note blocked at :77-79
- Web — Yes — reject modal /home/user/Pharmacy/resources/views/vendor-views/refund/details.blade.php:375-397 and list partials/_reject-modal.blade.php:1-32; note required server-side
- Server — Same endpoints as approve; note required_if rejected (RestAPI/v3/seller/RefundController.php:135) and rejected_note required (app/Http/Requests/Vendor/RefundStatusRequest.php:33)

**Read the refund's status change log (who changed it, to what, with which note)**  
`orders.manage`  
- App — Yes — ChangeLog screen opened from the detail app bar /home/user/sillercenter-syria-cosmatics/lib/features/refund/screens/refund_details_screen.dart:101-119, rendered by change_log_widget.dart:51-100 (status, updated_by, reason)
- Web — Yes — 'Refund Request Logs' table /home/user/Pharmacy/resources/views/vendor-views/refund/details.blade.php:312-370 with a See More note modal (partials/_note-modal.blade.php)
- Server — refund_request.refundStatus relation returned by refund-details (RestAPI/v3/seller/RefundController.php:103) and eager-read on web ($refund->refundStatus)
- Evidence — Flutter /home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/change_log_widget.dart:80-87; web /home/user/Pharmacy/resources/views/vendor-views/refund/details.blade.php:327-360. Web log rows print $refund['created_at'] for every entry rather than the status row's own timestamp (details.blade.php:341) — see notes

**Be prevented from changing a refund status the admin already decided**  
`orders.manage`  
- App — Yes — action bar hidden when the last status change was by admin (/home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/refund_details_widget.dart:326-333, and controller guard refund_controller.dart:219-233)
- Web — Yes — approve/reject buttons and modals hidden when change_by == 'admin' (/home/user/Pharmacy/resources/views/vendor-views/refund/details.blade.php:20-31, :373)
- Server — Enforced server-side on both paths — RestAPI/v3/seller/RefundController.php:167-170 (403) and app/Http/Controllers/Vendor/RefundController.php:145-149
- Evidence — Flutter gate /home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/refund_details_widget.dart:328-331; web gate /home/user/Pharmacy/resources/views/vendor-views/refund/details.blade.php:20 and server check /home/user/Pharmacy/app/Http/Controllers/Vendor/RefundController.php:145-149

**Be blocked from approving a refund when the customer no longer holds the loyalty points earned on that order**  
`orders.manage`  
- App — Yes (server-enforced) — the app surfaces the API error via ApiChecker (/home/user/sillercenter-syria-cosmatics/lib/features/refund/controllers/refund_controller.dart:142-146)
- Web — Yes — inline JSON error before any state change (/home/user/Pharmacy/app/Http/Controllers/Vendor/RefundController.php:139-143)
- Server — API: RestAPI/v3/seller/RefundController.php:157-165 (approved only) | web: app/Http/Controllers/Vendor/RefundController.php:139-143 (approved and refunded)
- Evidence — API check /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/RefundController.php:159-165; web check /home/user/Pharmacy/app/Http/Controllers/Vendor/RefundController.php:139-143

**See on an edited order that money must be handed back to the customer**  
`orders.view`  
- App — Yes — 'need to return' card on order details (/home/user/sillercenter-syria-cosmatics/lib/features/order_details/screens/order_details_screen.dart:194-201) and the return line in the payment history (widgets/payment_status_widget.dart:281-312)
- Web — Yes — Return Amount row with pending badge (/home/user/Pharmacy/resources/views/vendor-views/order/order-details.blade.php:752-770) and the list-row warning icon (order/list.blade.php:107-114)
- Server — order.latest_edit_history.order_return_amount / order_return_payment_status on both surfaces (API: app/Http/Controllers/RestAPI/v3/seller/OrderController.php:386, 633)

## WEB MISSING (13)

**See every refund request in one unfiltered list ("All", no status filter)**  
`orders.manage` · wave 4  
- App — Yes — 'All' tab at /home/user/sillercenter-syria-cosmatics/lib/features/refund/screens/refund_screen.dart:241 with refundTypeIndex==0 showing the full list (refund_screen.dart:69-70)
- Web — No — tabs are hardcoded to pending/approved/refunded/rejected only (/home/user/Pharmacy/resources/views/vendor-views/refund/index.blade.php:19-22); the route always carries a status and the repo applies where status = {tab}
- Server — Present — the API returns everything when date_type/status are absent (RestAPI/v3/seller/RefundController.php:46-75); web repo filters on the route status (app/Repositories/RefundRequestRepository.php:80-82)
- Evidence — Flutter /home/user/sillercenter-syria-cosmatics/lib/features/refund/screens/refund_screen.dart:69-79 maps tab 0 to the unfiltered list; web /home/user/Pharmacy/routes/vendor/routes.php:144 is Route::get('index/{status}') and /home/user/Pharmacy/app/Http/Controllers/Vendor/RefundController.php:69-82 always passes 'status' => $status into the filter, so no 'all' view exists (sidebar offers only the 4 statuses, /home/user/Pharmacy/resources/views/layouts/vendor/partials/v2/_side-bar.blade.php:162-179)

**Filter refund requests by quick date preset (today / this week / this month / all time)**  
`orders.manage` · wave 4  
- App — Yes — app-bar popup menu at /home/user/sillercenter-syria-cosmatics/lib/features/refund/screens/refund_screen.dart:120-227 sending date_type=today|this_week|this_month|all
- Web — No — the filter offcanvas offers only a from/to date pair (/home/user/Pharmacy/resources/views/vendor-views/refund/partials/_filter-offcanvas.blade.php:18-38); no preset links anywhere in the refund views
- Server — API supports date_type presets (app/Http/Controllers/RestAPI/v3/seller/RefundController.php:59-71); the web controller only reads from_date/to_date (app/Http/Controllers/Vendor/RefundController.php:67-76)
- Evidence — Flutter presets /home/user/sillercenter-syria-cosmatics/lib/features/refund/screens/refund_screen.dart:123-133 → repo /home/user/sillercenter-syria-cosmatics/lib/features/refund/domain/repositories/refund_repository.dart:99 ('?date_type=...'); web has no equivalent — grepped /home/user/Pharmacy/resources/views/vendor-views/refund/ and /home/user/Pharmacy/app/Http/Controllers/Vendor/RefundController.php, only from_date/to_date exist

**See and contact the customer behind a refund request (name, photo, tap-to-call phone, email) from the refund itself**  
`orders.manage` · wave 4  
- App — Yes — CustomerInfoWidget /home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/customer_info_widget.dart:52-95 with tel: launch at :68
- Web — Partial — customer name + phone/email appear only in the list row (/home/user/Pharmacy/resources/views/vendor-views/refund/index.blade.php:95-106); the refund details page has no customer block at all
- Server — customer relation already loaded on both paths (RestAPI/v3/seller/RefundController.php:46; app/Http/Controllers/Vendor/RefundController.php:79)
- Evidence — grep -i customer /home/user/Pharmacy/resources/views/vendor-views/refund/details.blade.php returns only line 235 ('refund_reason_by_customer') — no name/phone/email anywhere on the detail page; Flutter card at /home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/customer_info_widget.dart:58-95, mounted from refund_details_widget.dart:303-307

**Approving a refund opens a return (RMA) so the physical goods are tracked back and can be restocked**  
`orders.manage` · wave 4  
- App — Yes — approving through the app triggers it server-side (/home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/approve_reject_widget.dart:121 → POST refund-status-update)
- Web — No — the vendor web approval writes the refund status only; no ReturnLogisticsService call, so no RMA is created and the units are never restocked
- Server — API only: app/Http/Controllers/RestAPI/v3/seller/RefundController.php:206-208 calling openReturnFor() at :225-257 → ReturnLogisticsService::authorizeForRefund (app/Services/Marketplace/ReturnLogisticsService.php:183-204)
- Evidence — API path /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/RefundController.php:206-208 and :238-253; web path /home/user/Pharmacy/app/Http/Controllers/Vendor/RefundController.php:151-184 has no ReturnLogisticsService usage (grep ReturnLogisticsService over app/Http/Controllers/Vendor → 0 hits), and the RefundEvent listener only sends notifications (/home/user/Pharmacy/app/Listeners/RefundListener.php:24-57). A refund approved on the web silently loses the stock — the exact bug the API path was written to close.

**Browse the return shipments (goods physically coming back) for this shop**  
`orders.view or orders.manage to read (routes/rest_api/v3/seller.php:641-643)` · wave 4  
- App — Yes — ReturnsScreen /home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/returns_screen.dart:29,85-96, reached from the More menu (/home/user/sillercenter-syria-cosmatics/lib/features/menu/screens/more_screen.dart:124-126)
- Web — No — the vendor panel has no returns feature at all
- Server — GET /api/v3/seller/seller-center/returns (routes/rest_api/v3/seller.php:639-642 → app/Http/Controllers/RestAPI/v3/seller/SellerReturnController.php:49-70). Admin-only web equivalent exists at routes/admin/routes.php:706-713 → Admin/Marketplace/ReturnLogisticsController.php
- Evidence — Flutter repo /home/user/sillercenter-syria-cosmatics/lib/features/returns/domain/repositories/return_repository.dart:12-23 against AppConstants.returnsUri (/home/user/sillercenter-syria-cosmatics/lib/utill/app_constants.dart:187). Web: grep -c 'returns' /home/user/Pharmacy/routes/vendor/routes.php → 0; grep ReturnShipment over resources/views/vendor-views + app/Http/Controllers/Vendor → 0 hits. Only the admin panel has an RMA queue (/home/user/Pharmacy/resources/views/admin-views/marketplace/returns.blade.php via ReturnLogisticsController).

**Filter returns by state: authorized / in transit / received / restocked / rejected**  
`orders.view / orders.manage` · wave 4  
- App — Yes — choice chips built from the server's status list /home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/returns_screen.dart:41-55, 105-122
- Web — No — no returns screen exists to filter
- Server — status query param validated against the five states (app/Http/Controllers/RestAPI/v3/seller/SellerReturnController.php:316-332); statuses list returned at :66
- Evidence — Flutter chip handler /home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/returns_screen.dart:117-119 → return_controller.dart:37-56 → return_repository.dart:14-18; no counterpart under /home/user/Pharmacy/resources/views/vendor-views/

**Open one return and see its reference, quantity, reason, linked order, tracking number, received date and internal note**  
`orders.view / orders.manage` · wave 4  
- App — Yes — ReturnDetailsScreen /home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/return_details_screen.dart:152-182
- Web — No — not found anywhere in the vendor panel
- Server — GET /api/v3/seller/seller-center/returns/{id} (routes/rest_api/v3/seller.php:642 → SellerReturnController.php:84-104, payload assembled at :94-101 and :298-313)
- Evidence — Flutter detail rows /home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/return_details_screen.dart:162-181 over ReturnShipmentModel (/home/user/sillercenter-syria-cosmatics/lib/features/returns/domain/models/return_models.dart:44-61); web: no route, controller or blade — grep 'ReturnShipment|returns.index' over /home/user/Pharmacy/routes/vendor/routes.php, app/Http/Controllers/Vendor/, resources/views/vendor-views/ → 0 hits

**Mark an authorized return as in transit and record the customer's tracking number**  
`orders.manage (routes/rest_api/v3/seller.php:645)` · wave 4  
- App — Yes — dialog with tracking field /home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/return_details_screen.dart:104-134, button shown only while status == authorized (:218-228)
- Web — No
- Server — POST /api/v3/seller/seller-center/returns/{id}/in-transit (routes/rest_api/v3/seller.php:645 → SellerReturnController.php:116-137 → ReturnLogisticsService::markInTransit, app/Services/Marketplace/ReturnLogisticsService.php:61-74)
- Evidence — Flutter repo /home/user/sillercenter-syria-cosmatics/lib/features/returns/domain/repositories/return_repository.dart:36-39; the admin panel has the equivalent action (routes/admin/routes.php:710 → Admin/Marketplace/ReturnLogisticsController.php:71-83) but the vendor panel has none

**Record which carrier is bringing a return back**  
`orders.manage` · wave 4  
- App — Partial — the API call accepts a carrier (/home/user/sillercenter-syria-cosmatics/lib/features/returns/domain/repositories/return_repository.dart:36-39) but the in-transit dialog collects only a tracking number, so carrier is never sent (return_details_screen.dart:112-118, 133)
- Web — No — no vendor returns UI at all
- Server — Supported and persisted: carrier validated at app/Http/Controllers/RestAPI/v3/seller/SellerReturnController.php:124-127 and written at app/Services/Marketplace/ReturnLogisticsService.php:67-71
- Evidence — Backend field ready (/home/user/Pharmacy/app/Services/Marketplace/ReturnLogisticsService.php:68-70; admin form uses it at /home/user/Pharmacy/app/Http/Controllers/Admin/Marketplace/ReturnLogisticsController.php:74-79) but neither seller surface captures it — Flutter dialog has one TextField (tracking) at /home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/return_details_screen.dart:112-118 and passes only trackingNumber at :133. Build the web form with both fields.

**Receive returned goods and decide at receipt whether they can be sold again (restock yes/no)**  
`orders.manage (routes/rest_api/v3/seller.php:646)` · wave 4  
- App — Yes — receive dialog asks 'can these goods be sold again?' with No-do-not-restock / Yes-restock (/home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/return_details_screen.dart:44-67, button at :229-233)
- Web — No — the vendor cannot receive a return at all, so refunded stock never returns to inventory from the web
- Server — POST .../returns/{id}/receive with restock flag (routes/rest_api/v3/seller.php:646 → SellerReturnController.php:151-175, :161-163 applies the flag) → ReturnLogisticsService::receive restocks under a row lock and writes a `return` stock movement (app/Services/Marketplace/ReturnLogisticsService.php:80-146)
- Evidence — Flutter restock decision /home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/return_details_screen.dart:45-66 → return_repository.dart:42; backend restock + movement logging /home/user/Pharmacy/app/Services/Marketplace/ReturnLogisticsService.php:88-131. Web vendor: no receive route (grep 'receive' over /home/user/Pharmacy/routes/vendor/routes.php → none for returns); only admin has it (routes/admin/routes.php:711). Highest-value gap after the RMA-on-approval one.

**See whether a received return was actually restocked, and when it arrived**  
`orders.view / orders.manage` · wave 4  
- App — Yes — received_at row and a green 'restocked: yes' row (/home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/return_details_screen.dart:170-179), plus distinct status chip colours for received vs restocked (widgets/return_widgets.dart:33-39)
- Web — No
- Server — status restocked|received and received_at in the summary payload (app/Http/Controllers/RestAPI/v3/seller/SellerReturnController.php:302-311; service sets them at ReturnLogisticsService.php:133-136)
- Evidence — Flutter /home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/return_details_screen.dart:174-179 and /home/user/sillercenter-syria-cosmatics/lib/features/returns/domain/models/return_models.dart:63-64; nothing equivalent under /home/user/Pharmacy/resources/views/vendor-views/ (grep -i 'restock' there returns only product restock-request views, unrelated)

**Refuse a return with a required reason when what came back is not acceptable**  
`orders.manage (routes/rest_api/v3/seller.php:647)` · wave 4  
- App — Yes — reject dialog with a 255-char reason, empty reason blocked client-side (/home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/return_details_screen.dart:69-102, button at :235-241)
- Web — No
- Server — POST .../returns/{id}/reject, reason required|string|max:255 (routes/rest_api/v3/seller.php:647 → SellerReturnController.php:187-202 → ReturnLogisticsService::reject, app/Services/Marketplace/ReturnLogisticsService.php:148-162)
- Evidence — Flutter /home/user/sillercenter-syria-cosmatics/lib/features/returns/domain/repositories/return_repository.dart:45; API validation /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerReturnController.php:195. Note the admin equivalent makes reason nullable (/home/user/Pharmacy/app/Http/Controllers/Admin/Marketplace/ReturnLogisticsController.php:104) — the web vendor build should follow the seller API and require it.

**See what a refund did to the seller's own balance — the money debited and the commission credited back**  
`orders.view / orders.manage` · wave 5  
- App — Yes — 'effect on your balance' ledger block on the return detail (/home/user/sillercenter-syria-cosmatics/lib/features/returns/screens/return_details_screen.dart:184-205) with the commission-credit explainer at :200-203
- Web — No — no ledger/statement view exists in the vendor panel (grep VendorLedgerEntry over app/Http/Controllers/Vendor/ and routes/vendor/routes.php → 0 hits); the refund detail page shows the customer-facing amount only
- Server — Returned with each return: app/Http/Controllers/RestAPI/v3/seller/SellerReturnController.php:102 → ledgerFor() at :247-267 (VendorLedgerEntry TYPE_REFUND + TYPE_COMMISSION_CHARGE)
- Evidence — Flutter model /home/user/sillercenter-syria-cosmatics/lib/features/returns/domain/models/return_models.dart:102-129 and render at return_details_screen.dart:189-204; backend query /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/SellerReturnController.php:253-266. The seller-facing statement API exists (routes/rest_api/v3/seller.php:631-636) with no web counterpart.

## APP MISSING (4)

**Search refund requests by order id, refund id, or customer name/phone**  
`orders.manage`  
- App — No — the refund app bar has only the date filter (/home/user/sillercenter-syria-cosmatics/lib/features/refund/screens/refund_screen.dart:91-233); the repository never sends a `search` parameter (refund_repository.dart:97-104)
- Web — Yes — search box /home/user/Pharmacy/resources/views/vendor-views/refund/index.blade.php:14-16 ('search_by_order_id_or_refund_id')
- Server — Both endpoints support it — API `search` on order_id (RestAPI/v3/seller/RefundController.php:53-58); web searchValue over order_id, refund id and customer name/phone (app/Repositories/RefundRequestRepository.php:68-79)
- Evidence — Backend search already implemented at /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/RefundController.php:53-58, but /home/user/sillercenter-syria-cosmatics/lib/features/refund/domain/repositories/refund_repository.dart:99 builds the URL with date params only; web control at /home/user/Pharmacy/resources/views/vendor-views/refund/index.blade.php:14-16

**Export the refund request list to Excel**  
`orders.manage`  
- App — No — no export control on the refund screen (/home/user/sillercenter-syria-cosmatics/lib/features/refund/screens/refund_screen.dart:80-268) and no export endpoint in the refund repository
- Web — Yes — export button /home/user/Pharmacy/resources/views/vendor-views/refund/index.blade.php:26-29
- Server — vendor.refund.export (routes/vendor/routes.php:146 → app/Http/Controllers/Vendor/RefundController.php:190-218, RefundRequestExport). No API equivalent in routes/rest_api/v3/seller.php:244-254
- Evidence — Web export chain /home/user/Pharmacy/routes/vendor/routes.php:146 and /home/user/Pharmacy/app/Http/Controllers/Vendor/RefundController.php:190-218; Flutter refund feature has no export — grepped /home/user/sillercenter-syria-cosmatics/lib/features/refund/ for 'export'/'download', nothing found

**Cap the seller at two refund decisions (max 2 approvals / 2 rejections) with the remaining-attempts warning**  
`orders.manage`  
- App — No — nothing reads or shows approved_count/denied_count; the app can flip approve/reject indefinitely (/home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/approve_reject_widget.dart:39-47 only mirrors the last status)
- Web — Yes — server refuses a third decision (app/Http/Controllers/Vendor/RefundController.php:130-132) and the UI hides the button plus shows the warning banner (details.blade.php:21-30, :36-69)
- Server — Only the web controller enforces and increments the counters (app/Http/Controllers/Vendor/RefundController.php:130-132, 175-176). The API path never reads or increments them (RestAPI/v3/seller/RefundController.php:129-216)
- Evidence — Web guard /home/user/Pharmacy/app/Http/Controllers/Vendor/RefundController.php:130-132 and counter increments at :175-176; API equivalent absent — the whole of /home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/RefundController.php:129-216 contains no approved_count/denied_count reference. This is a business-rule divergence, not just a UI gap: refunds decided in the app never advance the counters the web relies on.

**Record that the excess amount from an edited order was actually returned to the customer (wallet or manual, with a payment note)**  
`orders.manage (web area mapping, app/Http/Middleware/SellerStaffAccessMiddleware.php:87)`  
- App — No — the card is display-only, explicitly built with showButton:false and onTap:null (/home/user/sillercenter-syria-cosmatics/lib/features/order_details/screens/order_details_screen.dart:195-200); no matching endpoint in utill/app_constants.dart
- Web — Partial — endpoint and modal exist but nothing opens the modal: it is included at /home/user/Pharmacy/resources/views/vendor-views/order/list.blade.php:231 with no data-target anywhere in vendor-views (the admin panel has the trigger, admin-views/order/list.blade.php:244)
- Server — POST vendor.orders.customer-return-amount (routes/vendor/routes.php:200 → app/Http/Controllers/Vendor/Order/OrderController.php:207-250). No API equivalent — grep 'customer-return-amount' over routes/rest_api/v3/seller.php → 0 hits
- Evidence — Web form /home/user/Pharmacy/resources/views/vendor-views/order/partials/modal/order-edit-return-amount-modal.blade.php:1-59 posting to vendor.orders.customer-return-amount (/home/user/Pharmacy/routes/vendor/routes.php:200); trigger absent — grep 'returnDueAmountModal' across /home/user/Pharmacy/resources/views/vendor-views/ hits only the modal's own id. Flutter has no endpoint and no button (/home/user/sillercenter-syria-cosmatics/lib/features/order_details/screens/order_details_screen.dart:195-200).

## WEB ENHANCEMENT (3)

**See how many refund requests sit in each status without opening the list**  
`orders.manage`  
- App — No — RefundTypeButton receives the list but renders only the label, never a count (/home/user/sillercenter-syria-cosmatics/lib/features/refund/screens/refund_screen.dart:328-402, count unused at :384-397)
- Web — Yes — sidebar badges $v2RefundPending/$v2RefundApproved/$v2RefundRefunded/$v2RefundRejected (/home/user/Pharmacy/resources/views/layouts/vendor/partials/v2/_side-bar.blade.php:150-152, 162-179)
- Server — Composed for the web sidebar view only; no per-status count field in the API list response (RestAPI/v3/seller/RefundController.php:76 returns a bare collection)

**Download a refund evidence image to keep as proof**  
`orders.manage`  
- App — No — the image dialog only displays the picture, no download/share action (/home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/image_diaglog_widget.dart:13-45)
- Web — Yes — Download Image link /home/user/Pharmacy/resources/views/vendor-views/refund/partials/_img-view-modal.blade.php:10-19
- Server — Static storage URL, no endpoint needed

**Approve or reject a refund straight from the list without opening it**  
`orders.manage`  
- App — No — the list card only navigates to the detail screen (/home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/refund_widget.dart:22-25); the action bar lives on the detail screen only (refund_details_widget.dart:326-333)
- Web — Yes — per-row approve/reject icon buttons /home/user/Pharmacy/resources/views/vendor-views/refund/index.blade.php:110-134 wired to the per-row modals included at :160-165
- Server — Same update-status endpoint for both surfaces

## DEVICE SPECIFIC (1)

**Open the refund request straight from a push notification about it**  
`orders.manage`  
- App — Yes — deep link from FCM payload type 'refund' (/home/user/sillercenter-syria-cosmatics/lib/notification/my_notification.dart:57-58 and :177-178; cold start /home/user/sillercenter-syria-cosmatics/lib/features/splash/screens/splash_screen.dart:127-128)
- Web — No equivalent (web push token is saved at routes/vendor/routes.php:85 but nothing routes a refund payload to the refund page)
- Server — RefundListener pushes to the seller (app/Listeners/RefundListener.php:39-50) with refund + order_details_id payload
- Evidence — Flutter deep-link handler /home/user/sillercenter-syria-cosmatics/lib/notification/my_notification.dart:57-58; payload model /home/user/sillercenter-syria-cosmatics/lib/notification/models/notification_body.dart:28-29. The underlying business capability (being alerted to a new refund request) exists on both; only the tap-through is device specific.

## BACKEND MISSING (1)

**See the refunded order's type (POS vs regular) and its payment status on the refund**  
`orders.manage`  
- App — UI exists but never populated — RefundDataWidget renders order_type (/home/user/sillercenter-syria-cosmatics/lib/features/refund/widgets/refund_pricing_widget.dart:177-199) and payment_status (:202-221)
- Web — No — the refund summary shows only payment method (/home/user/Pharmacy/resources/views/vendor-views/refund/details.blade.php:97-99)
- Server — Missing — both API list and single-item constrain the order relation to select('id','payment_method') (app/Http/Controllers/RestAPI/v3/seller/RefundController.php:47-49 and :83-85), so order_type and payment_status are always null in the payload
- Evidence — Flutter parses order_type/payment_status at /home/user/sillercenter-syria-cosmatics/lib/features/refund/domain/models/refund_model.dart:245-250, but the API never sends them (/home/user/Pharmacy/app/Http/Controllers/RestAPI/v3/seller/RefundController.php:47-49); the app therefore always falls back to 'regular' (refund_pricing_widget.dart:192-194) and hides the payment status row (:202)

