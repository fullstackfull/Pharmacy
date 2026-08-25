# Parity — notifications_chat

[← back to the matrix](../SELLER_WEB_APP_PARITY.md) · 48 capabilities

**32** BOTH · **7** WEB MISSING · **1** APP MISSING · **1** WEB ENHANCEMENT · **6** DEVICE SPECIFIC · **1** BACKEND MISSING

## Structural facts the implementer must know

```
SCOPE / GROUND TRUTH
Flutter endpoints for this domain are declared in /home/user/sillercenter-syria-cosmatics/lib/utill/app_constants.dart:20-24 (messages list/search/get-message/send/seen), :48 (cm-firebase-token), :88-92 (emergency-contact store/update/list/status-update/delete), :115-116 (notification list + view), :287-288 (FCM topics). Every one of these is served by routes/rest_api/v3/seller.php:79, 301-311, 364-372, 377-384.

CLIENT-SIDE BUSINESS STATE: none found. The only SharedPreferences write outside auth/session is the dark-theme flag (lib/theme/controllers/theme_controller.dart:16, key AppConstants.theme). There is no notification-preference, mute-conversation, quiet-hours or per-channel toggle anywhere in the app — searched lib/features/settings, lib/features/menu, lib/features/more, lib/features/profile and lib/utill/app_constants.dart:270-292. Neither side offers the seller any control over which notifications they receive; that is a genuine product gap in BOTH clients, not a parity gap.

TOP WEB-MISSING ITEMS, ranked by implementation value:
1. Live refresh of an open conversation (the panel's 20s poll only toasts — public/assets/back-end/js/vendor/common-script.js:6-22 — while the app re-fetches the thread and the inbox, lib/features/chat/controllers/chat_controller.dart:95-101). A seller with the chat page open never sees the reply arrive.
2. Emergency-contact search — the backend already supports ?search= (app/Http/Controllers/RestAPI/v3/seller/EmergencyContactController.php:18-21); only the vendor controller and blade need wiring.
3. Message-thread pagination and conversation-list pagination — the vendor controller uses dataLimit: 'all' in four places (app/Http/Controllers/Vendor/ChattingController.php:71, 95, 112, 138, 170, 184). A shop with a few thousand chat rows renders the entire history into one page.
4. Notification pagination — resources/views/layouts/vendor/partials/v2/_header.blade.php:101-105 runs three unbounded Notification queries plus (when the Auction addon is on) an AuctionNotification query on EVERY vendor page render.
5. Country dial code on emergency contact phone — app stores dialCode+number, web stores raw input, so the same table holds two formats.

BACKEND-MISSING / DEAD UI ON THE WEB SIDE
- Share Product offcanvas (resources/views/vendor-views/chatting/partials/_share-product-offcanvas.blade.php, included at index.blade.php:291) is entirely static demo markup — hardcoded <option value="2">Test</option>, img-view-demo.jpg thumbnails, no trigger button, no JS handler (grep for shareProductOffcanvas in public/assets/back-end/js returns nothing) and no route. Either build it or remove the include.
- ChattingController::getRenderMessagesView builds a detailsRoute pointing at the customer's order list (app/Http/Controllers/Vendor/ChattingController.php:290) and chatting.js:87-90 writes it into .user-details-route, but the vendor blade never renders that element — the class exists only in resources/views/admin-views/chatting/index.blade.php:209. "Open this customer's orders from the chat" is therefore missing in BOTH clients despite the data being prepared.
- The header renders auction notification rows with class auction-notif-read (resources/views/layouts/vendor/partials/v2/_header.blade.php:155) but no click handler exists anywhere in resources/ or public/assets/. Modules/Auction is also absent from this checkout (only AI, Blog, TaxModule are present), so I could not verify that side; it is excluded from the capability table.

PERMISSION DIVERGENCE — worth fixing while implementing
The two surfaces disagree on who may read customer conversations and notifications. The API gates messages behind seller_can:orders.view,orders.manage (routes/rest_api/v3/seller.php:303) and notifications behind seller_can:orders.view,products.view,finance.view (:380). The web panel maps both 'messages' and 'notification' to __allow__ for any active staff member (app/Http/Middleware/SellerStaffAccessMiddleware.php:81-82). A staff member with, say, only reviews.view is refused customer chat on mobile but can read every customer conversation in the browser. Emergency contacts are consistent (orders.manage on both, via the 'delivery-man' segment mapping at SellerStaffAccessMiddleware.php:102).

OTHER STRUCTURAL NOTES
- Message send is multipart on both sides with the same field names (media[] / file[]), so a shared validation rule set is feasible: app/Http/Requests/API/v3/SellerSendMessageRequest vs App\Http\Requests\Vendor\ChattingRequest. Only the web path rejects disallowed extensions explicitly (ChattingController.php:196-215); the API relies on the request class.
- Seen semantics differ: the API's get_message marks only the latest inbound message seen (app/Http/Controllers/RestAPI/v3/seller/ChatController.php:172-175) while the explicit seen/{type} endpoint and the whole web path mark every row of the conversation (:290, ChattingController.php:162-164). The app compensates by calling seen explicitly on tap; anything that relies on get_message alone will leave stale unread counts.
- ShopController@notification_index (app/Http/Controllers/RestAPI/v3/seller/ShopController.php:47-63) reuses the same query builder for the paginated page and for the new_notification count, so the count is computed after paginate() has already constrained the builder — verify this returns what you expect before mirroring the logic on the web.
- The app's inbox entry points are lib/features/menu/screens/more_screen.dart:127-129 and push deep links; the notification feed is reached only from the home app-bar bell (lib/features/home/screens/home_page_screen.dart:110-137). The web equivalents are the sidebar entries at resources/views/layouts/vendor/partials/v2/_side-bar.blade.php:472 (emergency contact) and :480 (inbox).
```

## BOTH (32)

**Browse the list of customer conversations (inbox)**  
`API: orders.view or orders.manage (routes/rest_api/v3/seller.php:303). Web: none — 'messages' is ALLOW for any active staff (app/Http/Middleware/SellerStaffAccessMiddleware.php:81)`  
- App — Yes — lib/features/chat/screens/inbox_screen.dart (InboxScreen), list rendered by lib/features/chat/widgets/chat_card_widget.dart
- Web — Yes — resources/views/vendor-views/chatting/index.blade.php left column, route vendor.messages.index?type=customer
- Server — App: GET /api/v3/seller/messages/list/customer (ChatController@list). Web: GET vendor/messages/index/customer (Vendor\ChattingController@getListView)
- Evidence — flutter: lib/features/chat/screens/inbox_screen.dart:34 getChatList(context,1,reload:true); lib/features/chat/domain/repositories/chat_repository.dart:24 '${AppConstants.cartUri}$type?limit=30&offset=$offset'; lib/utill/app_constants.dart:20 | web: resources/views/vendor-views/chatting/index.blade.php:48-99; app/Http/Controllers/Vendor/ChattingController.php:106-147; routes/vendor/routes.php:262

**Browse the list of delivery-man conversations**  
`API: orders.view/orders.manage. Web: ALLOW for any staff`  
- App — Yes — same InboxScreen with userTypeIndex==1 mapping to type 'delivery-man'
- Web — Yes — same view with ?type=delivery-man
- Server — App: GET /api/v3/seller/messages/list/delivery-man. Web: GET vendor/messages/index/delivery-man
- Evidence — flutter: lib/features/chat/controllers/chat_controller.dart:132 (_userTypeIndex == 0 ? 'customer' : 'delivery-man'); lib/features/chat/widgets/chat_header_widget.dart:57-61 | web: app/Http/Controllers/Vendor/ChattingController.php:65-104; resources/views/vendor-views/chatting/index.blade.php:36-41,100-147

**Switch between the customer and delivery-man conversation tabs**  
`see above`  
- App — Yes — lib/features/chat/widgets/chat_type_button_widget.dart tab buttons calling setUserTypeIndex
- Web — Yes — nav-tabs linking to the two ?type= routes
- Server — same list endpoints, type is a path segment
- Evidence — flutter: lib/features/chat/widgets/chat_type_button_widget.dart:89-96; lib/features/chat/controllers/chat_controller.dart:187-194 | web: resources/views/vendor-views/chatting/index.blade.php:29-42

**Search conversations by participant**  
`API: orders.view/orders.manage (routes/rest_api/v3/seller.php:307)`  
- App — Yes — search field in the inbox header, server-side search by first/last name
- Web — Partial — resources/views/vendor-views/chatting/index.blade.php:22-28 search box, but it is a client-side DOM filter over the already-rendered list (matches name, phone and last-message text). No server-side search route exists for the vendor panel.
- Server — App: GET /api/v3/seller/messages/search/{type}?search= (ChatController@search). Web: none
- Evidence — flutter: lib/features/chat/widgets/chat_header_widget.dart:30-45; lib/features/chat/controllers/chat_controller.dart:151-160; lib/features/chat/domain/repositories/chat_repository.dart:34 | web: public/assets/back-end/js/vendor/chatting.js:34-48 ($('#myInput').on('keyup'...) toggling .list_filter); no route in routes/vendor/routes.php:260-267

**See the per-conversation unread message count**  
`API: orders.view/orders.manage`  
- App — Yes — unseen_message_count rendered as a circle badge on each inbox row
- Web — Yes — countUnreadMessages badge per row
- Server — App: unseen_message_count computed in ChatController@list. Web: ChattingRepository::countUnreadMessages
- Evidence — flutter: lib/features/chat/widgets/chat_card_widget.dart:217-225; lib/features/chat/domain/models/chat_model.dart (unseenMessageCount); app/Http/Controllers/RestAPI/v3/seller/ChatController.php:64 | web: app/Http/Controllers/Vendor/ChattingController.php:88,131; resources/views/vendor-views/chatting/index.blade.php:83-89,132-137

**See which conversations have unseen messages (highlight / dot)**  
`API: orders.view/orders.manage`  
- App — Yes — row background tint plus bolded name when seenBySeller is false
- Web — Yes — .message-status bg-danger dot and non-bold styling for unread rows
- Server — chattings.seen_by_seller on both sides
- Evidence — flutter: lib/features/chat/widgets/chat_card_widget.dart:173-175,193-197 | web: resources/views/vendor-views/chatting/index.blade.php:94-97,142-145

**Open a conversation and read the message history**  
`API: orders.view/orders.manage`  
- App — Yes — lib/features/chat/screens/chat_screen.dart with MessageBubbleWidget
- Web — Yes — right pane, resources/views/vendor-views/chatting/messages.blade.php loaded inline and via AJAX
- Server — App: GET /api/v3/seller/messages/get-message/{type}/{id} (ChatController@get_message). Web: GET vendor/messages/message?user_id= / ?delivery_man_id= (ChattingController@getMessageByUser)
- Evidence — flutter: lib/features/chat/screens/chat_screen.dart:51 getMessageList(widget.userId,1); lib/features/chat/domain/repositories/chat_repository.dart:44 | web: app/Http/Controllers/Vendor/ChattingController.php:156-188; routes/vendor/routes.php:263; public/assets/back-end/js/vendor/chatting.js:55-108

**Mark a conversation as read/seen**  
`API: orders.view/orders.manage (routes/rest_api/v3/seller.php:306)`  
- App — Yes — explicit seen call when a conversation is tapped, plus optimistic local update
- Web — Yes — implicit: opening the thread flips seen_by_seller for every row of that conversation
- Server — App: POST /api/v3/seller/messages/seen/{type} (ChatController@seenMessage). Web: ChattingController@getMessageByUser updateAllWhere(seen_by_seller=1)
- Evidence — flutter: lib/features/chat/widgets/chat_card_widget.dart:159; lib/features/chat/controllers/chat_controller.dart:594-611; lib/features/chat/domain/repositories/chat_repository.dart:84 | web: app/Http/Controllers/Vendor/ChattingController.php:162-164,175-178; also :76-79,118-121 on first render

**Send a text message to a customer or delivery man**  
`API: orders.view/orders.manage. Web: ALLOW for any staff`  
- App — Yes — lib/features/chat/widgets/send_message_widget.dart composer + send button
- Web — Yes — textarea + send button in the chat composer form
- Server — App: POST /api/v3/seller/messages/send/{type} (multipart: id, message, media[], file[]). Web: POST vendor/messages/message?user_id=|delivery_man_id= (ChattingController@addVendorMessage)
- Evidence — flutter: lib/features/chat/widgets/send_message_widget.dart:115-138; lib/features/chat/domain/repositories/chat_repository.dart:52-77; app/Http/Controllers/RestAPI/v3/seller/ChatController.php:190-252 | web: resources/views/vendor-views/chatting/index.blade.php:231-258; app/Http/Controllers/Vendor/ChattingController.php:194-258; routes/vendor/routes.php:264

**Insert emoji into a message**  
`none`  
- App — Yes — emoji_picker_flutter panel toggled from the composer
- Web — Yes — picmo emoji picker bound to the #trigger label
- Server — none — client side only
- Evidence — flutter: lib/features/chat/widgets/send_message_widget.dart:82-91,144-167; lib/features/chat/controllers/chat_controller.dart:544-549 | web: resources/views/vendor-views/chatting/index.blade.php:226-228,302-303 (picmo-emoji.js, emoji.js)

**Attach images and videos to a message from device storage**  
`API: orders.view/orders.manage`  
- App — Yes — FilePicker multi-select restricted to image+video extensions, with thumbnail generation for video
- Web — Yes — <input type=file multiple> with an image/video accept list
- Server — media[] on send/{type} (App) and file/media on addVendorMessage (Web); both persist to chattings.attachment
- Evidence — flutter: lib/features/chat/controllers/chat_controller.dart:226-276; lib/features/chat/widgets/custom_image_pick_bottom_sheet.dart:203-216 | web: resources/views/vendor-views/chatting/index.blade.php:212-218; app/Http/Controllers/RestAPI/v3/seller/ChatController.php:194-208; app/Services/ChattingService::getAttachment via app/Http/Controllers/Vendor/ChattingController.php:220

**Attach documents / arbitrary files to a message**  
`API: orders.view/orders.manage`  
- App — Yes — FilePicker restricted to AppConstants.documentExtensions (plus archives when not demo)
- Web — Yes — second file input with getFileUploadFormats(type:'file')
- Server — file[] on send/{type} (App); file on addVendorMessage (Web), which also rejects disallowed extensions
- Evidence — flutter: lib/features/chat/controllers/chat_controller.dart:304-370; lib/features/chat/widgets/send_message_widget.dart:94-97 | web: resources/views/vendor-views/chatting/index.blade.php:219-225; app/Http/Controllers/Vendor/ChattingController.php:196-215

**Enforce attachment size and count limits before sending**  
`none`  
- App — Yes — per-file size, total size and file-count checks against configModel upload limits, with inline error text
- Web — Yes — data-max-size from getFileUploadMaxSize() plus form-advance-file-validation and an error string
- Server — limits sourced from web config (system_general_file_upload_max_size / image upload max size) in both cases
- Evidence — flutter: lib/features/chat/controllers/chat_controller.dart:279-299,336-375; lib/features/chat/screens/chat_screen.dart:226-234,298-305 | web: resources/views/vendor-views/chatting/index.blade.php:216,223,295; public/assets/back-end/js/select-multiple-file.js

**Preview picked attachments and remove one before sending**  
`none`  
- App — Yes — horizontal thumbnail strip with an X on each item, and a file chip list with a close icon
- Web — Yes — .image-array/.file-array preview containers with per-item remove
- Server — none — client side
- Evidence — flutter: lib/features/chat/screens/chat_screen.dart:180-224 (remove → pickMultipleMedia(true,index:index)), :239-296 (pickOtherFile(true,index:index)) | web: resources/views/vendor-views/chatting/index.blade.php:235-247; public/assets/back-end/js/select-multiple-image-for-message.js:95-114

**View an attached image full screen / in a lightbox**  
`API: orders.view/orders.manage`  
- App — Yes — MediaViewerScreen with a paged viewer
- Web — Yes — imgViewModal image slider per message
- Server — attachment paths from chattings.attachment_full_url
- Evidence — flutter: lib/features/chat/screens/media_viewer_screen.dart:19-27; lib/features/chat/widgets/message_bubble_widget.dart:99 (_MediaGridWidget → MediaViewerScreen) | web: resources/views/vendor-views/chatting/messages.blade.php:139-200 (imgViewModal); public/assets/back-end/js/vendor/chatting.js:96 imageSlider()

**Play an attached video**  
`API: orders.view/orders.manage`  
- App — Yes — video_player + chewie inside MediaViewerScreen, play overlay on the thumbnail
- Web — Yes — inline <video> element with a play button and a modal player
- Server — attachment path served from storage
- Evidence — flutter: lib/features/chat/screens/media_viewer_screen.dart:65-80,4,17; lib/features/chat/screens/chat_screen.dart:192-210 | web: resources/views/vendor-views/chatting/messages.blade.php:109-119,176-193; public/assets/back-end/js/vendor/chatting.js:97 toggleVideo()

**Download a single attachment (image, video or document)**  
`API: orders.view/orders.manage`  
- App — Yes — FlutterDownloader.enqueue from the media viewer and from the file chip
- Web — Yes — <a download href=path> on each document and each media item
- Server — static storage URL on both
- Evidence — flutter: lib/features/chat/screens/media_viewer_screen.dart:179-189,270-285; lib/features/chat/controllers/chat_controller.dart:487-504 | web: resources/views/vendor-views/chatting/messages.blade.php:71-76 (btn--download), :155-162

**Download every attachment on a message in one action**  
`API: orders.view/orders.manage`  
- App — Yes — download icon next to a media group loops the attachments through the downloader
- Web — Yes — .zip-download builds a client-side ZIP of the message's images/videos
- Server — none beyond the file URLs
- Evidence — flutter: lib/features/chat/widgets/message_bubble_widget.dart:84,105 onTap _downloadMediaForAll, :141-169 | web: resources/views/vendor-views/chatting/messages.blade.php:136-139; public/assets/back-end/js/vendor/chatting.js:343-421 downloadZip()

**See when each message was sent (timestamp)**  
`API: orders.view/orders.manage`  
- App — Yes — tap a bubble to reveal a relative/absolute timestamp; grouped-message time headers
- Web — Yes — a tooltip on each bubble with Today/Yesterday/weekday/full date formatting
- Server — chattings.created_at
- Evidence — flutter: lib/features/chat/controllers/chat_controller.dart:400-432,507-542; lib/features/chat/widgets/message_bubble_widget.dart:226-228,286-289 | web: resources/views/vendor-views/chatting/messages.blade.php:31-41,242-251

**Be alerted to a new incoming message while working elsewhere in the panel/app**  
`Web: ALLOW for any staff`  
- App — Yes — FCM push (foreground local notification + background/terminated system notification)
- Web — Yes — 20-second poll that raises a toast and plays a sound
- Server — App: ChattingEvent → PushNotificationTrait to the seller's cm_firebase_token. Web: GET vendor/messages/new-notification (ChattingController@getNewNotification)
- Evidence — flutter: lib/notification/my_notification.dart:69-154,220-278; lib/main.dart:114 onBackgroundMessage | web: public/assets/back-end/js/vendor/common-script.js:1-23 (setInterval 20000); app/Http/Controllers/Vendor/ChattingController.php:309-327; routes/vendor/routes.php:265; resources/views/layouts/vendor/partials/_alert-message.blade.php:3-7

**Browse the platform notification feed (admin/system notifications sent to sellers)**  
`API: orders.view or products.view or finance.view (routes/rest_api/v3/seller.php:380). Web: 'notification' is ALLOW for any staff`  
- App — Yes — lib/features/notification/screens/notification_screen.dart full-screen list
- Web — Yes — bell dropdown listing every Notification with sent_to='seller' created since the seller signed up
- Server — App: GET /api/v3/seller/notification?limit&offset (ShopController@notification_index). Web: inline query in the header blade (no route)
- Evidence — flutter: lib/features/notification/screens/notification_screen.dart:36-57; lib/features/notification/domain/repositories/notification_repository.dart:41-48; lib/utill/app_constants.dart:115 | web: resources/views/layouts/vendor/partials/v2/_header.blade.php:96-108,135-146; app/Http/Controllers/RestAPI/v3/seller/ShopController.php:43-64

**See the unread notification count**  
`API: orders.view/products.view/finance.view`  
- App — Yes — red badge on the home app-bar bell fed by new_notification
- Web — Yes — .notification_data_new_count badge on the header bell, plus a 'new' chip per unread row
- Server — App: new_notification in notification_index. Web: whereDoesntHave('notificationSeenBy')->count() in the header blade; refreshed by NotificationController@getNotificationModalView
- Evidence — flutter: lib/features/home/screens/home_page_screen.dart:110-137; lib/features/notification/domain/models/notification_model.dart:19; app/Http/Controllers/RestAPI/v3/seller/ShopController.php:61 | web: resources/views/layouts/vendor/partials/v2/_header.blade.php:106-108,129-131,140-144; app/Http/Controllers/Vendor/NotificationController.php:59-65

**Mark a notification as seen**  
`API: orders.view/products.view/finance.view (routes/rest_api/v3/seller.php:382)`  
- App — Yes — tapping a row calls the seen endpoint and reloads page 1
- Web — Yes — clicking a dropdown row POSTs the modal-view route, which stamps notification_seen and fades the badge
- Server — App: GET /api/v3/seller/notification/view?id= (ShopController@seller_notification_view). Web: POST vendor/notification/index (NotificationController@getNotificationModalView)
- Evidence — flutter: lib/features/notification/screens/notification_screen.dart:74; lib/features/notification/controllers/notification_controller.dart:33-39; lib/features/notification/domain/repositories/notification_repository.dart:13-20 | web: resources/views/layouts/vendor/partials/_script-partials.blade.php:207-228; app/Http/Controllers/Vendor/NotificationController.php:53-55; routes/vendor/routes.php:270

**Open a notification and read its detail**  
`API: orders.view/products.view/finance.view`  
- App — Yes — NotificationDialog with title, relative time and body copy
- Web — Yes — modal rendered from vendor-views.partials.notification-modal
- Server — App: title/description already in the list payload. Web: getNotificationModalView returns the rendered view
- Evidence — flutter: lib/features/notification/screens/notification_screen.dart:75,100-151 | web: app/Http/Controllers/Vendor/NotificationController.php:48-66; resources/views/vendor-views/partials/notification-modal.blade.php:1-24

**Jump to the seller's storefront from a notification**  
`none`  
- App — Yes — 'Visit' button opening {baseUrl}/shopView/{sellerId} externally
- Web — Yes — 'visit_store' button linking to the vendor-shop slug route
- Server — App: none (URL built client side from the profile user id). Web: route('vendor-shop',['slug'=>$shop->slug])
- Evidence — flutter: lib/features/notification/screens/notification_screen.dart:144-146,152-159 | web: resources/views/vendor-views/partials/notification-modal.blade.php:22; app/Http/Controllers/Vendor/NotificationController.php:50

**Register this device/browser to receive push notifications**  
`API: none beyond seller_api_auth (routes/rest_api/v3/seller.php:79 — outside any seller_can group)`  
- App — Yes — FCM token pushed to the seller record after login
- Web — Yes — firebase-init requests permission and POSTs the web token
- Server — App: PUT /api/v3/seller/cm-firebase-token (SellerController@update_cm_firebase_token). Web: POST vendor/system/save-fcm-web-token (FirebaseController@saveSellerWebToken)
- Evidence — flutter: lib/features/auth/domain/repositories/auth_repository.dart:86-99 (AppConstants.tokenUri, cm_firebase_token), :101-111 _getDeviceToken; lib/utill/app_constants.dart:48; app/Http/Controllers/RestAPI/v3/seller/SellerController.php:592-605 | web: resources/views/layouts/vendor/partials/_firebase-script.blade.php:27-52; routes/vendor/routes.php:86; app/Http/Controllers/FirebaseController.php:49

**List the shop's emergency contacts**  
`API: orders.manage (routes/rest_api/v3/seller.php:366). Web: orders.manage (SellerStaffAccessMiddleware.php:102 'delivery-man')`  
- App — Yes — EmergencyContactScreen reached from the Deliveryman setup screen
- Web — Yes — vendor/delivery-man/emergency-contact/index table
- Server — App: GET /api/v3/seller/delivery-man/emergency-contact/list. Web: EmergencyContactController@index
- Evidence — flutter: lib/features/emergency_contract/screens/emergency_contact_screen.dart:26; lib/features/emergency_contract/domain/repositories/emergency_contact_repository.dart:75-82; lib/utill/app_constants.dart:90; lib/features/delivery_man/screens/delivery_man_setup_screen.dart:25 | web: app/Http/Controllers/Vendor/DeliveryMan/EmergencyContactController.php:38-42; resources/views/vendor-views/delivery-man/emergency-contact/index.blade.php:58-120; routes/vendor/routes.php:311

**Add an emergency contact (name + phone)**  
`orders.manage on both sides`  
- App — Yes — floating '+' opens AddEmergencyContactWidget dialog
- Web — Yes — 'add new contact information' form at the top of the page
- Server — App: POST /api/v3/seller/delivery-man/emergency-contact/store. Web: POST vendor/delivery-man/emergency-contact/index (@add)
- Evidence — flutter: lib/features/emergency_contract/screens/emergency_contact_screen.dart:84-92; lib/features/emergency_contract/widgets/add_emergency_contact_widget.dart:108-123; lib/features/emergency_contract/domain/repositories/emergency_contact_repository.dart:15-33; app/Http/Controllers/RestAPI/v3/seller/EmergencyContactController.php:26-45 | web: resources/views/vendor-views/delivery-man/emergency-contact/index.blade.php:13-52; app/Http/Controllers/Vendor/DeliveryMan/EmergencyContactController.php:48-53; routes/vendor/routes.php:312

**Edit an existing emergency contact**  
`orders.manage`  
- App — Yes — swipe action opens the same dialog prefilled, submits as update
- Web — Yes — edit button opens an AJAX modal with the update form
- Server — App: PUT /api/v3/seller/delivery-man/emergency-contact/update. Web: GET vendor/.../update/{id} then POST vendor/.../update/{id}
- Evidence — flutter: lib/features/emergency_contract/widgets/emergency_contact_card_widget.dart:45-54,72-81; lib/features/emergency_contract/domain/repositories/emergency_contact_repository.dart:20-32 | web: resources/views/vendor-views/delivery-man/emergency-contact/index.blade.php:100-104; app/Http/Controllers/Vendor/DeliveryMan/EmergencyContactController.php:55-67; routes/vendor/routes.php:313-314

**Delete an emergency contact**  
`orders.manage`  
- App — Yes — swipe-to-delete action on the card
- Web — Yes — trash button submitting a DELETE form with a confirm dialog
- Server — App: DELETE /api/v3/seller/delivery-man/emergency-contact/delete. Web: DELETE vendor/delivery-man/emergency-contact/index
- Evidence — flutter: lib/features/emergency_contract/widgets/emergency_contact_card_widget.dart:36-44,62-71; lib/features/emergency_contract/controllers/emergency_contact_controller.dart:67-74 | web: resources/views/vendor-views/delivery-man/emergency-contact/index.blade.php:105-115; app/Http/Controllers/Vendor/DeliveryMan/EmergencyContactController.php:93-102; routes/vendor/routes.php:316

**Enable or disable an emergency contact (status toggle)**  
`orders.manage`  
- App — Yes — FlutterSwitch on the card posting status 0/1
- Web — Yes — switcher input with a confirm modal, PATCH to updateStatus
- Server — App: PUT /api/v3/seller/delivery-man/emergency-contact/status-update. Web: PATCH vendor/delivery-man/emergency-contact/index
- Evidence — flutter: lib/features/emergency_contract/widgets/emergency_contact_card_widget.dart:143-150; lib/features/emergency_contract/domain/repositories/emergency_contact_repository.dart:36-47; app/Http/Controllers/RestAPI/v3/seller/EmergencyContactController.php:70-80 | web: resources/views/vendor-views/delivery-man/emergency-contact/index.blade.php:79-97; app/Http/Controllers/Vendor/DeliveryMan/EmergencyContactController.php:73-87; routes/vendor/routes.php:315

**Call an emergency contact directly from the list**  
`none`  
- App — Yes — tapping the phone chip launches tel:/tel:// via url_launcher
- Web — Yes — the phone cell is a tel: anchor
- Server — none
- Evidence — flutter: lib/features/emergency_contract/widgets/emergency_contact_card_widget.dart:116-128,164-168 | web: resources/views/vendor-views/delivery-man/emergency-contact/index.blade.php:74-78

## WEB MISSING (7)

**Load the conversation list incrementally (pagination / infinite scroll)**  
`API: orders.view/orders.manage` · wave 2  
- App — Yes — PaginatedListViewWidget with limit=30 and offset paging
- Web — No — the vendor controller pulls every conversation in one query (dataLimit: 'all') and renders them all into the sidebar
- Server — App: GET /api/v3/seller/messages/list/{type}?limit&offset. Web: none (no paginator)
- Evidence — flutter: lib/features/chat/screens/inbox_screen.dart:85-91 PaginatedListViewWidget(totalSize/offset); lib/features/chat/domain/repositories/chat_repository.dart:24 | web: app/Http/Controllers/Vendor/ChattingController.php:71 dataLimit: 'all' (and :112) — full list rendered at resources/views/vendor-views/chatting/index.blade.php:49

**Load older messages in a long conversation (message pagination)**  
`API: orders.view/orders.manage` · wave 2  
- App — Yes — PaginatedListViewWidget with limit=30 pages back through history
- Web — No — getMessageByUser fetches the entire thread in one query (dataLimit: 'all') with no paging control
- Server — App: get-message accepts limit+offset (validated required). Web: none
- Evidence — flutter: lib/features/chat/screens/chat_screen.dart:98-104 onPaginate → getMessageList(offset); app/Http/Controllers/RestAPI/v3/seller/ChatController.php:136-139,159 | web: app/Http/Controllers/Vendor/ChattingController.php:170 dataLimit: 'all' (also :184)

**See day separators inside the conversation thread**  
`none` · wave 2  
- App — Yes — a centered date chip is inserted whenever the day changes
- Web — No — messages.blade.php renders a flat list; the date only exists in the per-bubble tooltip
- Server — none — presentation only
- Evidence — flutter: lib/features/chat/screens/chat_screen.dart:116-129 and _willShowDate at :321-336 | web: resources/views/vendor-views/chatting/messages.blade.php:1-30 loop has no date-break logic

**Auto-refresh the currently open conversation when the other party replies**  
`Web: ALLOW for any staff` · wave 2  
- App — Yes — the FCM foreground listener refreshes the open thread and the inbox counts in place
- Web — No — the 20s poll only shows a toast; the open thread and the sidebar counts are not re-rendered until the seller clicks
- Server — App: push payload type 'chatting' carries customer_id / delivery_man_id; refresh re-hits get-message + list. Web: none (getNewNotification returns only a count and marks seen_notification=1)
- Evidence — flutter: lib/features/chat/controllers/chat_controller.dart:95-101 onIncomingChatMessage, :84-91 openConversation tracking; lib/notification/my_notification.dart:78-96; lib/features/chat/screens/chat_screen.dart:50,57 | web: public/assets/back-end/js/vendor/common-script.js:10-19 (toast only); app/Http/Controllers/Vendor/ChattingController.php:309-327

**Page through notification history**  
`API: orders.view/products.view/finance.view` · wave 2  
- App — Yes — PaginatedListViewWidget, limit 20 per page
- Web — No — the header blade runs ->latest()->get() and renders the entire history into the dropdown on every page load
- Server — App: notification_index paginates. Web: none
- Evidence — flutter: lib/features/notification/screens/notification_screen.dart:41-47; lib/features/notification/controllers/notification_controller.dart:17-31; lib/utill/app_constants.dart:115 (limit=20&offset=) | web: resources/views/layouts/vendor/partials/v2/_header.blade.php:101-105 ($v2VendorNotifList = ...->latest()->get()), rendered at :135

**Pick a country dial code for the emergency contact phone number**  
`orders.manage` · wave 2  
- App — Yes — CodePickerWidget prefixed to the phone field; the stored value is dialCode + number
- Web — No — a plain <input type=tel> with no country selector, so the same shop's records diverge in format depending on where they were entered
- Server — the API stores whatever string it is given (no normalisation) — app/Http/Controllers/RestAPI/v3/seller/EmergencyContactController.php:38-43
- Evidence — flutter: lib/features/emergency_contract/widgets/add_emergency_contact_widget.dart:76-99,113 (phoneNumberWithCountryCode = _countryDialCode! + phone), :27,36-38 | web: resources/views/vendor-views/delivery-man/emergency-contact/index.blade.php:36-40 and _update-emergency-contact.blade.php:20-24 — no country code control

**Search emergency contacts by name or phone**  
`orders.manage` · wave 2  
- App — Yes — search field at the top of the emergency contact screen, hitting the list endpoint with ?search=
- Web — No — the index view has no search input and the controller passes no search filter to the repository
- Server — Supported: EmergencyContactController@list applies a name/phone LIKE filter when 'search' is present
- Evidence — flutter: lib/features/emergency_contract/screens/emergency_contact_screen.dart:44-70; lib/features/emergency_contract/controllers/emergency_contact_controller.dart:44-52; lib/features/emergency_contract/domain/repositories/emergency_contact_repository.dart:85-92; app/Http/Controllers/RestAPI/v3/seller/EmergencyContactController.php:18-21 | web: app/Http/Controllers/Vendor/DeliveryMan/EmergencyContactController.php:40 (getListWhere with only user_id, no search); resources/views/vendor-views/delivery-man/emergency-contact/index.blade.php:53-58 — no search field

## APP MISSING (1)

**Page through a long emergency contact list**  
`orders.manage`  
- App — No — the controller loads the whole list in one call and renders it in a shrink-wrapped ListView
- Web — Yes — paginated with getWebConfig('pagination_limit') and a links() footer
- Server — App: list endpoint returns every row (no limit/offset). Web: repository dataLimit = pagination_limit
- Evidence — flutter: lib/features/emergency_contract/domain/repositories/emergency_contact_repository.dart:75-82 (plain GET, no paging args); lib/features/emergency_contract/widgets/emergency_contact_list_widget.dart:28-36; app/Http/Controllers/RestAPI/v3/seller/EmergencyContactController.php:17-23 | web: app/Http/Controllers/Vendor/DeliveryMan/EmergencyContactController.php:40; resources/views/vendor-views/delivery-man/emergency-contact/index.blade.php:125-129

## WEB ENHANCEMENT (1)

**See a global unread-message badge outside the inbox**  
`Web: ALLOW for any staff`  
- App — No — the app's only global badge is the notification bell; the more-menu Inbox entry carries no count
- Web — Yes — header inbox icon with a total badge plus a customer/delivery-man split dropdown
- Server — Web: inline Chatting query in the header blade. App: none
- Evidence — flutter: lib/features/menu/screens/more_screen.dart:127-129 (plain Inbox item, no badge); lib/features/home/screens/home_page_screen.dart:110-137 (bell badge is notifications only) | web: resources/views/layouts/vendor/partials/v2/_header.blade.php:179-215

## DEVICE SPECIFIC (6)

**Capture a photo with the camera and attach it to a message**  
`API: orders.view/orders.manage`  
- App — Yes — 'open camera' option in the attachment bottom sheet (ImageSource.camera)
- Web — No — file input only
- Server — same send endpoint; the capture itself is client side
- Evidence — flutter: lib/features/chat/widgets/custom_image_pick_bottom_sheet.dart:219-232; lib/features/chat/controllers/chat_controller.dart:216-224 (ImageValidationHelper.validateAndPickImage(source: ImageSource.camera)) | web: no camera input in resources/views/vendor-views/chatting/index.blade.php:212-225 (accept list is file types only)

**Open a downloaded document in an external viewer app**  
`none`  
- App — Yes — OpenFile.open after the download completes
- Web — No — the browser hands the file to the OS; there is no equivalent in-panel action
- Server — none
- Evidence — flutter: lib/features/chat/controllers/chat_controller.dart:501-503 (OpenFile.open(openFileUrl)) | web: resources/views/vendor-views/chatting/messages.blade.php:56 plain target=_blank anchor — nothing further

**Ask the OS/browser for notification permission**  
`none`  
- App — Yes — Permission.notification requested at startup, and again before a bulk media download
- Web — Yes — Notification.requestPermission() in the firebase script
- Server — none
- Evidence — flutter: lib/main.dart:149-150; lib/features/chat/widgets/message_bubble_widget.dart:146-168 (openAppSettings on denial) | web: resources/views/layouts/vendor/partials/_firebase-script.blade.php:30

**Subscribe to broadcast push topics (all-sellers, maintenance mode) and unsubscribe on sign-out**  
`none`  
- App — Yes — subscribeToTopic('six_valley_seller') and ('maintenance_mode_start_vendor') on login; unsubscribe on clearSharedData
- Web — No — the web only stores a per-browser token; there is no topic subscription
- Server — none — handled directly against Firebase from the client
- Evidence — flutter: lib/features/auth/domain/repositories/auth_repository.dart:89-90,154-155; lib/utill/app_constants.dart:287-288 | web: not found — searched resources/views/layouts/vendor/partials/_firebase-script.blade.php:1-57 and public/assets/backend/libs/firebase/, no subscribeToTopic

**Deep-link from a push notification straight to the relevant screen (chat inbox, order details, refund, wallet, product list, notification feed)**  
`none`  
- App — Yes — payload.type routing in the local-notification tap handler, onMessageOpenedApp and the splash cold-start handler
- Web — No — the web push token is stored but no click-through routing exists in the panel
- Server — routing keys come from the ChattingEvent / PushNotificationTrait payload (type, order_id, refund_id, message_key)
- Evidence — flutter: lib/notification/my_notification.dart:43-67 (type=='chatting' → InboxScreen with initIndex by message_key), :157-190; lib/features/splash/screens/splash_screen.dart:98-133 | web: resources/views/layouts/vendor/partials/_firebase-script.blade.php:27-52 has no onMessage/notificationclick handler

**Rich push presentation (big-text and big-picture notifications with sound)**  
`none`  
- App — Yes — flutter_local_notifications BigText / BigPicture styles with a custom sound
- Web — No — browser default notification only
- Server — image field on the push payload resolved against /storage/app/public/notification/
- Evidence — flutter: lib/notification/my_notification.dart:220-278,281-313 | web: not found — no service-worker notification styling beyond public/firebase-messaging-sw.js default

## BACKEND MISSING (1)

**Share a product into a chat conversation**  
`none`  
- App — No — no product-share entry point in the chat composer
- Web — Partial (dead UI) — a Share Product offcanvas is included on the chat page but has no trigger button, no JS handler and no route; the product cards and dropdowns are hardcoded demo markup
- Server — none — no route in routes/vendor/routes.php and no controller method
- Evidence — flutter: not found — searched lib/features/chat/widgets/send_message_widget.dart:47-174 and lib/features/chat/**, no share action | web: resources/views/vendor-views/chatting/index.blade.php:291 include; resources/views/vendor-views/chatting/partials/_share-product-offcanvas.blade.php:1-60 (static <option value="2">Test</option>, img-view-demo.jpg); grep for shareProductOffcanvas in public/assets/back-end/js returns nothing

