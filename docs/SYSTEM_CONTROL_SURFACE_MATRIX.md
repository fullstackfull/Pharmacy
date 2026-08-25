# System Control Surface Matrix

> Every meaningful capability the platform has, and which surface owns it.

The question this document answers, for each capability: **who manages it, who can see its
status, where is it configured, where are its failures visible, and where is its history?**
A capability with no answer is not a design decision; it is a capability nobody owns.

| | |
|---|---|
| Capabilities audited | 607 |
| Fully connected to a surface | 432 |
| Internal by design | 52 |
| Deprecated | 19 |
| **Orphaned — no owner, no surface** | **104** |

Orphans are enumerated with their evidence in [ORPHAN_BACKEND_CAPABILITIES.md](ORPHAN_BACKEND_CAPABILITIES.md).
The per-domain reading is in [FINAL_PLATFORM_COVERAGE_AUDIT.md](FINAL_PLATFORM_COVERAGE_AUDIT.md).

## How to read a cell

`None` is a gap: that surface could reasonably own this and does not. `N/A` is not a gap — the
dimension does not apply, as a nightly reconciliation job has no phone screen. The distinction is
the whole point of the document, so it is never blurred.

## Analytics (47)

| Capability | Admin | Seller Web | Flutter App | Analytics | Monitor | Dev Portal | Audit | Owner | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| Seller-domain analytics events (payout requested, KYC submitted) are recorded as internal traffic and can never reach a report | **None** | Submit | **None** | Events | **None** | **None** | Yes | Developer | ORPHAN |
| Inventory as a measured quantity — stock-out frequency, how long stock sat at zero, sell-through | View | Manage | View | **None** | **None** | Documented | Yes | Admin | ORPHAN |
| Reporting how much traffic went unmeasured because of Do Not Track or missing consent | **None** | **None** | N/A | **None** | **None** | **None** | No | Admin | ORPHAN |
| Seller report builder, saved report definitions and an exports centre | **None** | **None** | View | Metrics | **None** | Full | No | Seller | ORPHAN |
| Folding the tail of a high-cardinality dimension into an __other__ row instead of dropping it | **None** | **None** | N/A | Metrics | **None** | **None** | No | Developer | ORPHAN |
| Pipeline health counters — events written, and events dropped because a request overflowed the buffer | **None** | **None** | N/A | Metrics | **None** | **None** | No | Admin | ORPHAN |
| Per-day performance of each campaign short link | View | **None** | N/A | Metrics | **None** | **None** | No | Admin | ORPHAN |
| The extra facts attached to each event — payment method, coupon code, shipping cost, guest flag, failure reason | View | **None** | **None** | Events | **None** | **None** | No | Admin | ORPHAN |
| Saving an analytics report configuration to come back to | **None** | **None** | **None** | **None** | **None** | **None** | No | Admin | DEPRECATED |
| Daily history of request volume, visitors, errors and API load (telemetry_daily) | **None** | **None** | N/A | Metrics | Failures | **None** | No | Developer | ORPHAN |
| Analytics and telemetry policy — consent, Do Not Track, IP masking, bot and staff exclusion, what a session and a bounce are, and how long customer data is kept | View | **None** | N/A | Metrics | Health | N/A | No | Admin | ORPHAN |
| Tracked marketing campaigns (UTM links, short links, QR codes) | Configure | **None** | **None** | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| In-house and vendor product sale reports | Oversight | View | View | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Order, product and stock reports with Excel and PDF export | Oversight | Manage | Manage | Metrics | **None** | Documented | No | Seller | CONNECTED TO SELLER |
| Customer segments | Configure | **None** | **None** | Metrics | **None** | Partial | Yes | Admin | CONNECTED TO ADMIN |
| Storefront experiments (A/B tests) | Configure | **None** | **None** | Metrics | **None** | Partial | Yes | Admin | CONNECTED TO ADMIN |
| Marketing / analytics third-party tags (GA, Pixel) | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Analytics area: visits, acquisition, funnels, catalogue, search, revenue | Oversight | **None** | **None** | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Analytics data export and live feed | View | **None** | **None** | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Admin dashboard: order status, earnings and real-time activity | Oversight | View | View | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Opportunities — computed suggestions from this shop's own data (fast sellers running out, viewed-not-bought, under category median) | **None** | View | **None** | **None** | **None** | **None** | No | Seller | CONNECTED TO SELLER |
| The shop's own storefront analytics — visitors, sessions, product views, cart adds, revenue | Oversight | View | View | Metrics | **None** | Documented | No | Seller | CONNECTED TO SELLER |
| Catalogue of the 34 named things a shopper can do that the platform counts | View | **None** | **None** | Events | **None** | Partial | No | System | CONNECTED TO ADMIN |
| One door for recording behaviour, so every controller/service spells an event the same way | **None** | **None** | N/A | Events | **None** | **None** | No | Developer | INTERNAL BY DESIGN |
| Events buffered in memory and written once after the response, so analytics can never slow or fail a sale | **None** | **None** | N/A | Events | **None** | **None** | No | System | INTERNAL BY DESIGN |
| Collapsing the same act recorded twice (double-clicked button, reloaded confirmation page) | **None** | **None** | N/A | Events | **None** | **None** | No | System | INTERNAL BY DESIGN |
| Knowing one visitor from another and stitching their requests into visits | View | **None** | N/A | Metrics | **None** | **None** | No | System | CONNECTED TO ADMIN |
| Deciding which source, medium and campaign a visit is credited to | View | **None** | **None** | Metrics | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Keeping crawlers and the shop's own staff out of every reported figure, while still showing how much was excluded | View | **None** | **None** | Metrics | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Compressing a day of behaviour into the rollups every chart older than today reads | Oversight | **None** | **None** | Metrics | Health | **None** | No | System | CONNECTED TO ADMIN |
| Storefront beacon reporting the few things a page load cannot see (filter navigations, banner taps, sections scrolled into view) | View | **None** | N/A | Events | **None** | **None** | No | System | CONNECTED TO ADMIN |
| Building a campaign link (UTM builder), issuing a short link and a printable QR code | Configure | **None** | N/A | Events | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Counting campaign clicks separately for web and app, and crediting revenue from sessions rather than clicks | Oversight | **None** | N/A | Metrics | **None** | Documented | No | Admin | CONNECTED TO ADMIN |
| The Analytics area itself: 17 sections grouped by the question a merchant is asking | Oversight | **None** | N/A | Metrics | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Exporting any analytics breakdown as a CSV a merchant opens in Excel | View | **None** | N/A | **None** | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Following one visitor's trail through their visits, in order | View | **None** | N/A | **None** | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Live view of who is on the shop right now and what they are doing | View | **None** | **None** | Metrics | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Weekly retention cohorts — how many visitors come back week after week | View | **None** | **None** | Metrics | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| The same seller storefront analytics in the mobile seller app | **None** | View | View | Metrics | **None** | Documented | No | Seller | CONNECTED TO SELLER |
| Telling a seller which of their products get traffic but do not convert | **None** | View | **None** | Metrics | **None** | **None** | No | Seller | CONNECTED TO SELLER |
| Merchandising and advertising as a measured quantity (which banner and which placed section earns the click) | Oversight | **None** | N/A | Metrics | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Accounts as a measured quantity (sign-ups, sign-ins, reviews left, wishlist and compare adds) | View | **None** | **None** | Events | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Seller reports over the API | Oversight | **None** | View | Metrics | **None** | Full | No | Seller | CONNECTED TO DEVELOPER PORTAL |
| Seller control tower, action centre, analytics and home over the API | Oversight | Manage | View | Metrics | **None** | Full | No | Seller | CONNECTED TO DEVELOPER PORTAL |
| Campaign short codes are seven characters long | Configure | **None** | N/A | Metrics | **None** | **None** | No | Admin | INTERNAL BY DESIGN |
| Commerce analytics instrumentation hung off model events (orders, wishlists, compares, reviews, sign-ups, logins) | Oversight | **None** | N/A | Events | **None** | N/A | No | System | CONNECTED TO ADMIN |
| Marketing and tracking scripts — Google Analytics, Tag Manager, Meta Pixel and the rest | Configure | **None** | **None** | Events | **None** | **None** | No | Admin | CONNECTED TO ADMIN |

## Automation (24)

| Capability | Admin | Seller Web | Flutter App | Analytics | Monitor | Dev Portal | Audit | Owner | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| Commerce campaigns, segments and experiments are absent from the admin sidebar | Configure | **None** | N/A | Metrics | **None** | **None** | Yes | Admin | ORPHAN |
| How many variants a storefront experiment may run, and how many rules a segment or campaign may carry | Configure | **None** | N/A | Metrics | **None** | **None** | Yes | Admin | ORPHAN |
| Seller issue policy — the weighted severity model, the escalation ladder, and how often the platform may interrupt a seller's phone | **None** | View | View | **None** | **None** | Documented | Yes | Admin | ORPHAN |
| Scheduled operations — timed price changes, timed activations, campaign starts | **None** | **None** | **None** | **None** | **None** | **None** | No | Seller | ORPHAN |
| Whether a seller's automation rules and bulk jobs are actually succeeding | Oversight | View | View | **None** | **None** | **None** | Partial | Admin | ORPHAN |
| Commerce Experience master switch — storefront collections, campaigns, segments and experiments on or off | View | **None** | N/A | Events | **None** | N/A | Partial | Admin | ORPHAN |
| Seller automation oversight: stop a rule that is damaging a catalogue | Oversight | Manage | Manage | **None** | **None** | Documented | Yes | Admin | CONNECTED TO ADMIN |
| Abandoned cart recovery settings and reminder emails | Configure | **None** | **None** | Events | Failures | Partial | No | Admin | CONNECTED TO ADMIN |
| AI drafting of product copy — title, description, general setup, pricing, variations, SEO, image analysis | Configure | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| See how much AI generation quota this shop has left | Configure | **None** | View | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Automation rules — write a rule for my own shop, scope it to brands/categories/products, preview matches, run it now | Oversight | Manage | Manage | **None** | Failures | Documented | Yes | Seller | CONNECTED TO SELLER |
| Automation history and undo — what a rule matched, what it actually changed, and putting one run back | Oversight | Manage | Manage | **None** | **None** | Documented | Yes | Seller | CONNECTED TO SELLER |
| How many distinct shoppers saw each experiment variant | Oversight | **None** | N/A | Metrics | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Automation as a measured quantity (how often rules fire, what they changed, how often they fail) | Oversight | Manage | **None** | **None** | **None** | **None** | Yes | Seller | CONNECTED TO ADMIN |
| Seller automation rules — create, edit, enable/disable, delete, and every action a rule takes on the catalogue | Oversight | Manage | Manage | **None** | **None** | Full | Yes | Seller | CONNECTED TO SELLER |
| Seller automation rules over the API (create, preview, run, revert, history) | Oversight | Manage | Manage | **None** | Health | Full | Yes | Seller | CONNECTED TO DEVELOPER PORTAL |
| A single automation rule may not change more than 500 products in one run, and defaults to 50 | Oversight | Manage | Manage | **None** | **None** | Documented | Yes | Seller | CONNECTED TO SELLER |
| Bounds a seller may pick for their own automation triggers: low-stock threshold 1-1000 units, stale-stock 7-365 days | **None** | Manage | Manage | **None** | **None** | Documented | Yes | Seller | CONNECTED TO SELLER |
| Abandoned-cart reminders — on/off, guests included, idle window, stop-reminding age and minimum cart value | Configure | **None** | N/A | Metrics | **None** | N/A | No | Admin | CONNECTED TO ADMIN |
| Email customers who left items in their cart | Configure | **None** | N/A | **None** | Failures | N/A | No | Admin | CONNECTED TO ADMIN |
| Run the automation rules sellers wrote for their own catalogues | Oversight | Manage | Manage | **None** | Failures | Documented | Yes | Seller | CONNECTED TO SELLER |
| Move storefront campaigns through their lifecycle and flush the delivery cache at the transition | View | **None** | N/A | **None** | Failures | N/A | No | Admin | CONNECTED TO ADMIN |
| Release automation's claim on a listing as soon as a human changes its visibility | **None** | View | View | **None** | **None** | N/A | No | System | INTERNAL BY DESIGN |
| Seller automation rules — unattended catalogue changes (hide, publish, reprice) with triggers and settings fields | Oversight | Manage | Manage | **None** | Failures | Documented | Yes | Seller | CONNECTED TO SELLER |

## Brands (11)

| Capability | Admin | Seller Web | Flutter App | Analytics | Monitor | Dev Portal | Audit | Owner | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| Create, rename or delete a brand in the catalogue | Configure | **None** | View | **None** | **None** | Partial | No | Admin | ORPHAN |
| Brand registry: decide who may sell under a brand, on documentary evidence | Approve | **None** | Manage | **None** | **None** | Documented | Yes | Admin | CONNECTED TO ADMIN |
| Brand enforcement switch: turn the brand registry from a report into a refusal | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Brand catalogue (the brand list itself, distinct from the brand registry) | Configure | **None** | **None** | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Brand claims — asking to sell under a brand, attaching evidence, submitting and withdrawing a claim | Approve | **None** | Submit | **None** | **None** | Documented | Yes | Seller | CONNECTED TO SELLER |
| Brand exposure — which of my listings depend on a brand claim that is not approved | Oversight | **None** | View | **None** | **None** | Documented | No | Seller | CONNECTED TO SELLER |
| Brand verification and the brand registry as a measured quantity (claims, approvals, time to decide) | Approve | Submit | **None** | **None** | **None** | **None** | Yes | Admin | CONNECTED TO ADMIN |
| Approve, reject or revoke a seller's claim to sell under a brand | Approve | **None** | Submit | **None** | **None** | Documented | Yes | Admin | CONNECTED TO ADMIN |
| Seller brand claims over the API | Approve | **None** | Manage | **None** | **None** | Full | Yes | Seller | CONNECTED TO DEVELOPER PORTAL |
| Brand claim enforcement: whether listings under an unclaimed brand are blocked or merely reported | Configure | Submit | Submit | **None** | **None** | Documented | Yes | Admin | CONNECTED TO ADMIN |
| Brand registry enforcement — whether sellers are refused listings under brands they have not been granted | Configure | Submit | Submit | **None** | **None** | Documented | No | Admin | CONNECTED TO ADMIN |

## Catalog (31)

| Capability | Admin | Seller Web | Flutter App | Analytics | Monitor | Dev Portal | Audit | Owner | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| 44 AI auto-fill routes serving the Auction module, which is not installed | **None** | **None** | **None** | **None** | **None** | Partial | No | Developer | DEPRECATED |
| Approve or deny a seller's listing from the classic product screen | Approve | View | View | **None** | **None** | **None** | No | Admin | ORPHAN |
| The listing quality bar (a score under 70 is raised for improvement) | **None** | View | View | **None** | **None** | Documented | No | Admin | ORPHAN |
| Merchandising limits per collection — 12 pins, 100 exclusions, 20 boosts, boost weight up to 1000, fallback chains 5 deep | Configure | **None** | N/A | Metrics | **None** | **None** | Yes | Admin | ORPHAN |
| Keeping the storefront product search index in step with the catalogue, and rebuilding it when it drifts | **None** | **None** | N/A | Events | Failures | N/A | No | Admin | ORPHAN |
| Seller bulk price and stock jobs — queued updates across many products, with a receipt and a failures file | Oversight | **None** | Manage | **None** | Failures | Full | Yes | Seller | CONNECTED TO SELLER |
| Product moderation queue with per-product history | Approve | **None** | **None** | **None** | **None** | Partial | Yes | Admin | CONNECTED TO ADMIN |
| Legacy vendor product approval (approve / deny) on the product list | Approve | Submit | Submit | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| In-house product catalogue (create, edit, publish, feature, delete) | Configure | Manage | Manage | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Bulk product import from spreadsheet | Configure | Manage | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Categories, sub-categories and sub-sub-categories | Configure | **None** | **None** | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Product attributes | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Product gallery / media library | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Customer reviews moderation and admin replies | Approve | View | View | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Most demanded products merchandising | Configure | **None** | **None** | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Storefront priority setup (default sort and ranking rules) | Configure | **None** | **None** | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Commerce collections (rule-driven product sets) | Configure | **None** | **None** | Metrics | **None** | Partial | Yes | Admin | CONNECTED TO ADMIN |
| Create and edit a product listing — copy, images, variations, SEO, price, stock | Approve | Manage | Manage | Metrics | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Product approval decision and the rejection reason the seller reads | Approve | View | View | **None** | **None** | **None** | Yes | Admin | CONNECTED TO ADMIN |
| Barcode generation for a product | **None** | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Read customer reviews and reply to them | Configure | Manage | Manage | Events | **None** | Partial | No | Seller | CONNECTED TO ADMIN |
| How many shoppers actually scrolled to each composed section of the storefront | Oversight | **None** | N/A | Metrics | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Per-product engagement summary (30-day views, cart adds, sales, rating, wishlists) that dynamic collections rank by | Oversight | **None** | **None** | Metrics | Failures | **None** | No | Admin | CONNECTED TO ADMIN |
| Catalogue demand: what products, categories, brands and shops are looked at, and what converts | Oversight | View | View | Metrics | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Search demand, including the terms customers type that return nothing | Oversight | **None** | **None** | Metrics | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Moderate a product listing with reason codes and history — approve, reject, request changes, suspend, single or bulk | Approve | View | View | **None** | **None** | **None** | Yes | Admin | CONNECTED TO ADMIN |
| Seller bulk jobs over the API (import/export, progress, stuck-job recovery) | Oversight | **None** | Manage | **None** | Failures | Full | Yes | Seller | CONNECTED TO DEVELOPER PORTAL |
| Frequently-bought-together suggestions are cached for six hours | **None** | **None** | N/A | **None** | **None** | **None** | No | System | INTERNAL BY DESIGN |
| Catalogue rules — low-stock limit, whether brands exist, whether digital products are sold, whether new seller products need approval, and whether seller shipping costs need approval | Configure | View | View | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Product page trust signals — live viewer counter range, authenticity badge and its text, frequently-bought-together block and its size | Configure | **None** | **None** | Events | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Homepage and listing ordering — the priority/sort rules for products, categories, brands, vendors, blogs and clearance sections | Configure | **None** | **None** | **None** | **None** | **None** | No | Admin | CONNECTED TO ADMIN |

## Compliance (22)

| Capability | Admin | Seller Web | Flutter App | Analytics | Monitor | Dev Portal | Audit | Owner | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| Batch expiry warning horizon — stock expiring within 30 days is shown as expiring soon | View | **None** | View | **None** | **None** | Documented | Yes | Admin | ORPHAN |
| How much notice a seller gets before a verification document expires (45 days) | **None** | View | View | **None** | **None** | **None** | No | Admin | ORPHAN |
| Disputes and appeals — a channel for a seller to contest a rejection, a suspension, a brand revocation or a chargeback | **None** | **None** | **None** | **None** | **None** | **None** | No | Admin | ORPHAN |
| The seller's own account health and SLA standing | Oversight | **None** | **None** | **None** | Failures | **None** | Yes | Seller | ORPHAN |
| Compliance as a measured quantity — unauthorised brand listings, verification standing, policy breaches over time | Oversight | **None** | **None** | **None** | **None** | **None** | Partial | Seller | ORPHAN |
| Seller health tiers — the good / watch / at-risk bands on the admin scorecard | View | View | View | **None** | **None** | **None** | No | Admin | ORPHAN |
| SLA policy — maximum cancellation, return and refund rates, minimum rating, processing deadline | Configure | View | View | Metrics | Failures | **None** | Partial | Admin | CONNECTED TO ADMIN |
| Seller KYC — which documents are required, whether payouts are gated on them, and reviewing what a seller submits | Approve | Submit | Submit | Events | **None** | Full | Partial | Admin | CONNECTED TO ADMIN |
| Arm the KYC-required-for-payout gate and the required document list | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Seller SLA policy: set the thresholds a seller is held to | Configure | **None** | **None** | **None** | **None** | Partial | Partial | Admin | CONNECTED TO ADMIN |
| Evaluate SLA breaches on demand | Oversight | **None** | **None** | **None** | **None** | Partial | Yes | System | CONNECTED TO ADMIN |
| Seller performance scorecard (quality metrics and health tier) | Oversight | View | View | **None** | **None** | Documented | No | Admin | CONNECTED TO ADMIN |
| Per-category governance: return window, tax class, required attributes, forced moderation | Configure | View | View | **None** | **None** | Partial | Yes | Admin | CONNECTED TO ADMIN |
| Honouring Do Not Track / Global Privacy Control and cookie consent before anything is recorded | View | **None** | N/A | **None** | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Deleting raw events, visits and clicks once they are past their retention window | View | **None** | N/A | **None** | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Seller performance as a measured quantity (fulfilment, cancellation, return, refund, rating, strikes, health tier) | Oversight | View | View | **None** | **None** | Documented | Partial | Admin | CONNECTED TO ADMIN |
| Seller issues as a measured quantity (open issues, severity mix, which shops are worst) | Oversight | Manage | Manage | **None** | **None** | Documented | Partial | Admin | CONNECTED TO ADMIN |
| Seller verification / KYC submission over the API | Approve | **None** | Submit | **None** | **None** | Full | Yes | Seller | CONNECTED TO DEVELOPER PORTAL |
| KYC verification can be made a hard requirement before a seller may withdraw money | Configure | Submit | Submit | **None** | **None** | Documented | Yes | Admin | CONNECTED TO ADMIN |
| Evaluate every seller against SLA thresholds and reconcile the breach ledger | Configure | **None** | N/A | **None** | Failures | N/A | Partial | Admin | CONNECTED TO ADMIN |
| Raise the severity of seller issues nobody has answered | Oversight | View | View | **None** | Failures | Documented | Yes | System | CONNECTED TO ADMIN |
| Seller KYC policy — which documents are required and whether payouts are blocked until verified | Configure | Submit | Submit | **None** | **None** | Documented | Yes | Admin | CONNECTED TO ADMIN |

## Finance (57)

| Capability | Admin | Seller Web | Flutter App | Analytics | Monitor | Dev Portal | Audit | Owner | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| Dual-control (maker-checker) gate on large seller payouts — above a set amount a payout needs two approvers | **None** | Submit | Submit | **None** | **None** | Documented | Yes | Admin | ORPHAN |
| 24-hour payout freeze after a seller changes their bank details | **None** | View | View | **None** | **None** | Documented | Yes | Admin | ORPHAN |
| Changing the shop's bank / payout account from the Flutter app or the v3 API | **None** | Manage | Manage | **None** | **None** | Partial | No | Seller | ORPHAN |
| Mark a payout failed, or retry one a bank bounced | **None** | View | View | **None** | **None** | Partial | Partial | Admin | ORPHAN |
| Payment terms and scheduled cadences — payout frequency, minimum payout, holding period, settlement release time, SLA evaluation time and abandoned-cart send times | **None** | View | View | Metrics | Health | N/A | No | Admin | ORPHAN |
| How far back a seller's finance reconciliation looks, and how many example rows it shows | Oversight | **None** | View | **None** | **None** | Documented | No | Admin | ORPHAN |
| How late money may be before it is called a finance-integrity problem (6-hour grace on delivered orders) | **None** | View | View | **None** | Failures | Documented | No | Admin | ORPHAN |
| Diagnose a payment gateway that is switched on but cannot take a payment | **None** | **None** | N/A | **None** | **None** | N/A | No | Admin | ORPHAN |
| Why a payment failed — gateway latency, failure reason, and whether the callback ever arrived | **None** | **None** | N/A | **None** | **None** | N/A | No | Admin | ORPHAN |
| Alerting on payout and settlement failure — duplicate settlements, paid orders with no settlement row, commission mismatches | View | **None** | N/A | **None** | **None** | N/A | No | Admin | ORPHAN |
| Currency model — whether the marketplace runs single-currency or multi-currency with exchange rates | View | **None** | View | **None** | **None** | Partial | No | Admin | ORPHAN |
| Vendor\PaymentInformationController — a payment-details controller with no route | **None** | **None** | **None** | **None** | **None** | **None** | No | Developer | DEPRECATED |
| Payment success and abandonment rate | **None** | **None** | N/A | **None** | **None** | **None** | No | Developer | ORPHAN |
| Commission rules — the rate the marketplace charges, by global, category, vendor or product scope | Configure | **None** | **None** | Metrics | **None** | **None** | Partial | Admin | CONNECTED TO ADMIN |
| Per-seller commission override on the vendor record | Configure | View | View | **None** | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Seller payout queue — approve, mark paid or reject a requested payout | Approve | Submit | Submit | **None** | **None** | Full | Partial | Admin | CONNECTED TO ADMIN |
| Legacy vendor withdraw requests — the classic money-out channel, still live beside ledger payouts | Approve | Submit | Submit | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Vendor settlements — calculate, approve, mark paid, cancel | Approve | View | View | **None** | **None** | Partial | Partial | Admin | CONNECTED TO ADMIN |
| Separation of duties on settlements — the approver may not be the payer | Configure | **None** | N/A | **None** | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Payment gateway credentials, live/test mode and on-off state | Configure | **None** | **None** | Events | Failures | **None** | No | Admin | CONNECTED TO ADMIN |
| Payment routing rules — hide or prefer a gateway by order amount or destination | Configure | **None** | **None** | Events | Health | **None** | Partial | Admin | CONNECTED TO ADMIN |
| Payout methods available to sellers (withdrawal method registry) | Configure | View | View | **None** | **None** | Documented | No | Admin | CONNECTED TO ADMIN |
| Financial reconciliation: integrity checks over ledger, commission snapshots and settlements | Oversight | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Per-seller ledger and running balance | View | View | View | **None** | **None** | Documented | No | Admin | CONNECTED TO ADMIN |
| Approvals inbox (reusable maker-checker engine) | Approve | **None** | **None** | **None** | **None** | Partial | Yes | Admin | CONNECTED TO ADMIN |
| Admin and vendor earnings reports | Oversight | View | View | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Order-wise and expense-wise transaction reports with PDF and Excel export | Oversight | Manage | View | Metrics | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Customer wallet: balances, manual fund adjustment and bonus rules | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Customer loyalty points | Oversight | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Exchange-rate governance with change history | Configure | **None** | View | **None** | **None** | Partial | Yes | Admin | CONNECTED TO ADMIN |
| Offline payment methods | Configure | **None** | View | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Invoice settings | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| VAT / tax rules and tax reports (TaxModule addon) | Configure | View | View | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Currencies and system currency switch | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Fee simulator — what a given sale would cost this shop in commission and fees | View | **None** | View | **None** | **None** | Documented | No | Seller | CONNECTED TO SELLER |
| Reconciliation — does what I sold add up to what I was paid | Oversight | **None** | View | **None** | **None** | Documented | No | Seller | CONNECTED TO SELLER |
| The commission percentage this shop pays | Configure | View | View | **None** | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Delivery man wallet, cash collection and withdrawal approval | Oversight | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Legacy balance withdrawal request against the seller wallet | Approve | Manage | Manage | Events | **None** | Partial | No | Seller | CONNECTED TO ADMIN |
| Ledger payout — request the withdrawable balance, which reserves it, and cancel a pending request | Approve | Manage | Manage | Events | **None** | Documented | Yes | Seller | CONNECTED TO ADMIN |
| Account statement — the shop's ledger line by line with the running balance each entry left behind, exportable | View | **None** | View | **None** | **None** | Documented | No | Seller | CONNECTED TO SELLER |
| Where the shop's money is sent — bank / withdrawal method details, default selection | Configure | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Settlements — the marketplace calculating what it owes each seller and releasing it | Configure | **None** | **None** | **None** | Failures | **None** | Yes | Admin | CONNECTED TO ADMIN |
| VAT / tax report for the shop | Configure | View | View | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Payment volume and outcome mix read from analytics events on the Monitoring page | Oversight | **None** | N/A | Metrics | Health | **None** | No | Admin | CONNECTED TO MONITOR |
| Revenue as a measured quantity (trend, average order value, revenue by source and campaign) | Oversight | View | View | Metrics | **None** | Documented | No | Admin | CONNECTED TO ADMIN |
| Payouts and settlements as a measured quantity (payout volume, ageing, settlement value over time) | Approve | Submit | Submit | **None** | **None** | Documented | Yes | Admin | CONNECTED TO ADMIN |
| Seller bank / payout account change made from the vendor web panel | View | Manage | **None** | **None** | **None** | **None** | Yes | Seller | CONNECTED TO ADMIN |
| Seller finance controls, statements and payouts over the API | Oversight | **None** | Manage | Metrics | **None** | Full | Yes | Seller | CONNECTED TO DEVELOPER PORTAL |
| Seller earnings are held pending until the return window closes before they can be settled or paid | Configure | View | View | **None** | **None** | Documented | No | Admin | CONNECTED TO ADMIN |
| Ledger and reconciliation treat differences under one cent as agreement | Oversight | View | View | **None** | Failures | Documented | No | System | INTERNAL BY DESIGN |
| Mature seller earnings out of the return window and calculate vendor settlements | Approve | **None** | N/A | **None** | Failures | N/A | Yes | Admin | CONNECTED TO ADMIN |
| Payments — attempts, success rate, settlement reconciliation and money that reconciles nowhere | View | **None** | N/A | Events | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Invoice content and layout | Configure | View | View | **None** | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Customer programme settings — wallet, loyalty points and their rates, referral earning, add-funds limits | Configure | **None** | View | Events | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Default platform commission percentage on every sale | Configure | View | View | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Seller payment/bank information for payouts | Oversight | Manage | Manage | **None** | **None** | Documented | No | Seller | CONNECTED TO SELLER |

## Integrations (46)

| Capability | Admin | Seller Web | Flutter App | Analytics | Monitor | Dev Portal | Audit | Owner | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| Twelve inbound payment-gateway callbacks (bKash, Flutterwave, LiqPay, MercadoPago, Paymera, PayMob, Paystack, PayTabs, Razorpay, SenangPay and others) | Configure | **None** | N/A | **None** | Failures | **None** | No | Developer | ORPHAN |
| Inbound courier status webhook — POST /api/delivery-syria/orders/update-status | Configure | **None** | N/A | **None** | Failures | Partial | No | Developer | ORPHAN |
| Seller webhook delivery failure visibility | Oversight | **None** | View | **None** | **None** | Full | Partial | Admin | ORPHAN |
| Documented intent for the API — 438 of 537 endpoints carry no declared contract | **None** | **None** | Manage | **None** | Health | Partial | No | Developer | ORPHAN |
| Seller mobile API v2 — the previous seller app's entire surface, still routed | **None** | **None** | **None** | **None** | Health | Partial | No | Developer | DEPRECATED |
| API deprecation lifecycle and the change/breaking-change log | View | **None** | N/A | **None** | Failures | Partial | No | Admin | ORPHAN |
| Documentation for outbound seller webhooks — the event catalogue, the signature, the retry policy and the auto-disable behaviour | Oversight | **None** | Manage | **None** | **None** | **None** | Partial | Developer | ORPHAN |
| Portal sections that render a placeholder — models and enums, integrations, and portal settings | **None** | **None** | N/A | **None** | Health | **None** | No | Developer | ORPHAN |
| Creating, editing, repointing or deleting a seller's outbound webhook | Oversight | **None** | Manage | **None** | Failures | Full | Partial | Seller | ORPHAN |
| Outbound webhook retry policy — five attempts, doubling backoff, 8-second timeout | Oversight | View | View | **None** | Failures | Documented | Yes | Admin | ORPHAN |
| Which AI model writes seller content, and how creative it is allowed to be | Configure | Submit | Submit | **None** | **None** | **None** | No | Admin | ORPHAN |
| AI provider credentials — the API key and organisation id the AI module runs on | Configure | **None** | **None** | **None** | **None** | **None** | No | Admin | ORPHAN |
| ShareThis social sharing on the product detail page | **None** | **None** | N/A | **None** | **None** | N/A | No | Admin | DEPRECATED |
| Legacy per-gateway SMS credential editor (Nexmo and friends) | **None** | **None** | **None** | **None** | **None** | **None** | No | Developer | DEPRECATED |
| Seller webhook oversight: disable an endpoint being hammered | Oversight | **None** | Manage | **None** | Failures | Documented | Yes | Admin | CONNECTED TO ADMIN |
| Mail (SMTP / SendGrid) configuration and test send | Configure | **None** | **None** | **None** | Health | Partial | No | Admin | CONNECTED TO ADMIN |
| SMS gateway configuration | Configure | **None** | **None** | **None** | Health | Partial | No | Admin | CONNECTED TO ADMIN |
| Social login providers | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Social media chat widgets | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Google Maps API key | Configure | **None** | View | **None** | Health | Partial | No | Admin | CONNECTED TO ADMIN |
| Developer Portal: the live API surface derived from the route table | Oversight | **None** | **None** | **None** | **None** | Full | Partial | Developer | CONNECTED TO DEVELOPER PORTAL |
| API console: send a live request from the portal | Configure | **None** | **None** | **None** | **None** | Full | Yes | Developer | CONNECTED TO DEVELOPER PORTAL |
| OpenAPI and Postman collection download | View | **None** | **None** | **None** | **None** | Full | No | Developer | CONNECTED TO DEVELOPER PORTAL |
| API snapshot history and manifest rebuild | Oversight | **None** | **None** | **None** | **None** | Full | No | Developer | CONNECTED TO DEVELOPER PORTAL |
| API keys the shop issues — mint a scoped key, see where each was last used, revoke one | Oversight | **None** | Manage | **None** | **None** | Documented | Yes | Seller | CONNECTED TO SELLER |
| Webhooks the shop registers — create, enable, disable, delete, send a test, read the delivery log | Oversight | **None** | Manage | **None** | Failures | Documented | Yes | Seller | CONNECTED TO SELLER |
| The catalogue of webhook events a shop can subscribe to | **None** | **None** | View | **None** | **None** | Documented | No | Developer | CONNECTED TO DEVELOPER PORTAL |
| Advertising and marketplace campaigns a seller could join | Configure | **None** | **None** | Events | **None** | **None** | Yes | Admin | CONNECTED TO ADMIN |
| Developer Portal coverage of the seller API | Oversight | **None** | N/A | **None** | **None** | Partial | Yes | Developer | CONNECTED TO DEVELOPER PORTAL |
| Mobile apps reporting what only the app can see (a banner tapped, a list navigated) | View | **None** | **None** | Events | **None** | Documented | No | Developer | CONNECTED TO DEVELOPER PORTAL |
| Integrations as a measured quantity (webhook delivery success, outbound calls to gateways and couriers) | Oversight | Manage | **None** | **None** | Failures | **None** | Partial | Admin | CONNECTED TO MONITOR |
| API activity as a measured quantity (calls per endpoint, error rate, latency, who is calling) | Oversight | View | **None** | **None** | Health | Full | Partial | Developer | CONNECTED TO DEVELOPER PORTAL |
| Generated OpenAPI 3.1 specification, downloadable as JSON or YAML | View | **None** | N/A | **None** | **None** | Full | No | Developer | CONNECTED TO ADMIN |
| Outbound seller webhooks: a shop registers endpoints, picks events, gets a signing secret, and sees delivery health | Oversight | **None** | Manage | **None** | **None** | Partial | Yes | Seller | CONNECTED TO SELLER |
| The Developer Portal API console allows 20 requests per minute per administrator and a 64 KB request body | Configure | **None** | N/A | **None** | **None** | Full | Yes | Developer | CONNECTED TO DEVELOPER PORTAL |
| Re-queue seller webhook deliveries whose next attempt has come due | Oversight | **None** | Manage | **None** | Failures | Documented | No | Seller | CONNECTED TO SELLER |
| Rebuild the API manifest the Developer Portal reads | **None** | **None** | N/A | **None** | **None** | Full | No | Developer | INTERNAL BY DESIGN |
| Freeze the API surface and detect breaking changes since the last release | Configure | **None** | N/A | **None** | **None** | Full | No | Developer | CONNECTED TO DEVELOPER PORTAL |
| Republish the Android/iOS deep-link association files from the stored app setup | Configure | **None** | N/A | **None** | **None** | N/A | No | Admin | CONNECTED TO ADMIN |
| Deliver one seller webhook attempt off the request thread | Oversight | **None** | Manage | **None** | Failures | Documented | Partial | Seller | CONNECTED TO SELLER |
| Raise a seller's webhooks from the model - order placed/status, refund requested, payout status, stock crossing the low line | Oversight | **None** | Manage | **None** | Failures | Documented | Partial | System | CONNECTED TO SELLER |
| Outbound dependency call recording — every call made through Laravel's HTTP client, with latency, failures, timeouts and last error | View | **None** | N/A | N/A | **None** | N/A | No | System | CONNECTED TO ADMIN |
| Integrations — every outbound service this shop calls, with volume, failure rate, latency and when it last succeeded | View | **None** | N/A | N/A | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Which storefront URLs open the mobile app instead of the browser (universal / app links) | Configure | **None** | N/A | Events | **None** | Documented | No | Admin | CONNECTED TO ADMIN |
| Payment-gateway add-on presence — decides whether the platform uses the Modules/Gateways SMS and payment implementations or the built-in ones | Oversight | **None** | N/A | **None** | Failures | N/A | No | System | INTERNAL BY DESIGN |
| Seller API keys and outbound webhooks — the credentials and endpoints that let outside software act as a shop | Oversight | **None** | Manage | **None** | Failures | Documented | Yes | Seller | CONNECTED TO SELLER |

## Inventory (24)

| Capability | Admin | Seller Web | Flutter App | Analytics | Monitor | Dev Portal | Audit | Owner | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| Whether a shop runs multi-warehouse stock and batch/expiry tracking | **None** | View | View | **None** | **None** | **None** | No | Admin | DEPRECATED |
| Low-stock threshold used by the seller API and the Flutter app | Configure | View | View | **None** | **None** | Documented | No | Admin | CONNECTED TO ADMIN |
| What counts as low stock — three surviving and mutually inconsistent definitions (7 days of cover, 1/3 days of cover, 14 days of cover) | **None** | View | View | **None** | **None** | Documented | No | Admin | ORPHAN |
| When unsold stock is called dead capital (90 days, at least 3 units) | **None** | View | View | **None** | **None** | Documented | No | Admin | ORPHAN |
| Quick stock edit from the classic product list — sets current_stock directly, with no reason, no movement row and no audit line | Configure | Manage | Manage | **None** | **None** | **None** | No | Admin | ORPHAN |
| Limited stock list and restock requests | Oversight | View | View | **None** | Failures | Partial | No | Admin | CONNECTED TO ADMIN |
| Inventory adjustments with reason codes and a movement log | Configure | **None** | Manage | **None** | Failures | Documented | Yes | Admin | CONNECTED TO ADMIN |
| Batch and expiry tracking with write-off | Configure | **None** | Manage | **None** | **None** | Documented | Yes | Admin | CONNECTED TO ADMIN |
| Multi-warehouse locations, stock placement and transfers | Configure | **None** | Manage | **None** | **None** | Documented | Yes | Admin | CONNECTED TO ADMIN |
| Suppliers registry | Configure | **None** | **None** | **None** | **None** | Partial | Partial | Admin | CONNECTED TO ADMIN |
| Purchase orders (create, place, receive, cancel) | Configure | **None** | **None** | **None** | **None** | Partial | Yes | Admin | CONNECTED TO ADMIN |
| Restock requests — customers asking to be told when a product is back, and the seller restocking against that list | Oversight | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Bulk stock update across many listings, gated on inventory.manage rather than on price | Oversight | **None** | Manage | **None** | Failures | Documented | Yes | Seller Staff | CONNECTED TO SELLER |
| Set a product's stock quantity from the panel | Oversight | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Inventory overview and the stock movement ledger — what changed, by how much, why and who did it | Oversight | View | View | **None** | **None** | Documented | No | Seller | CONNECTED TO SELLER |
| Adjust stock through the ledger — a signed correction with a reason, a balance-after and an audit line | Configure | **None** | Manage | **None** | **None** | Documented | Yes | Seller Staff | CONNECTED TO SELLER |
| Warehouses — where the shop's stock physically sits, and moving it between locations | Configure | **None** | View | **None** | **None** | Documented | Yes | Admin | CONNECTED TO ADMIN |
| Batches and expiry dates on stock, and writing off expired units | Configure | **None** | View | **None** | **None** | Documented | Yes | Admin | CONNECTED TO ADMIN |
| Low-stock threshold for this shop, and the global default behind it | Configure | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Reasoned stock adjustment with a locked row, a movement ledger entry and an audit line | **None** | View | Manage | **None** | **None** | Full | Yes | Seller | CONNECTED TO ADMIN |
| Suppliers — the vendors the marketplace itself buys from | **None** | **None** | **None** | **None** | **None** | **None** | Partial | Admin | CONNECTED TO ADMIN |
| Seller inventory and returns over the API | Oversight | Manage | Manage | **None** | **None** | Full | Yes | Seller | CONNECTED TO DEVELOPER PORTAL |
| Global and per-seller reorder level that drives the low-stock and restock screens | Configure | Manage | **None** | **None** | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Inventory integrity — negative stock, double deductions, ledger drift and stuck reservations | View | **None** | N/A | N/A | **None** | **None** | No | Admin | CONNECTED TO ADMIN |

## Monitoring (93)

| Capability | Admin | Seller Web | Flutter App | Analytics | Monitor | Dev Portal | Audit | Owner | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| Monitoring and portal thresholds left as class constants beside the editable threshold map (duplicate-order window, payment capture grace, backup size-drop, incident correlation window, endpoint health verdicts) | Oversight | **None** | N/A | **None** | Failures | Full | No | Admin | ORPHAN |
| Alert rules — seeding them, creating or editing one, setting a threshold, silencing one, and telling somebody when one fires | View | **None** | N/A | N/A | Alerts | Partial | No | Admin | ORPHAN |
| Queue job outcomes measured at the worker — processed count, runtime and failures per queue | View | **None** | N/A | N/A | Alerts | N/A | No | System | CONNECTED TO MONITOR |
| Exception capture — grouped exceptions with stack traces, occurrence counts, affected users, and marking one resolved | View | **None** | N/A | **None** | **None** | **None** | No | Developer | ORPHAN |
| Defining the customer journeys the synthetic prober fetches | View | **None** | N/A | **None** | Health | N/A | No | Admin | ORPHAN |
| Acknowledging an incident, adding notes, recording probable cause, linking the deploy that caused it and saying who resolved it | **None** | **None** | N/A | N/A | **None** | N/A | No | Admin | ORPHAN |
| Writing a human note onto the monitoring timeline | View | **None** | N/A | **None** | Alerts | N/A | No | Admin | ORPHAN |
| Recording that a backup ran and that a restore was tested | View | **None** | N/A | **None** | Alerts | N/A | No | Admin | ORPHAN |
| Recording a deployment, and comparing performance either side of it | View | **None** | N/A | **None** | Alerts | N/A | No | Admin | ORPHAN |
| Changing a monitoring threshold, retention window, sampling rate or SLA target | View | **None** | N/A | **None** | Health | N/A | No | Admin | ORPHAN |
| Machine-readable JSON feed of every monitoring section | View | **None** | N/A | N/A | **None** | **None** | No | Developer | ORPHAN |
| Prometheus scrape endpoint and OTLP trace export | **None** | **None** | N/A | N/A | **None** | **None** | No | Developer | ORPHAN |
| The Integrations page's statement about what outbound instrumentation exists | View | **None** | N/A | N/A | **None** | N/A | No | Developer | DEPRECATED |
| Legacy single-page monitoring dashboard | **None** | **None** | N/A | N/A | N/A | N/A | No | Developer | DEPRECATED |
| Blast radius — how many sellers a failure is affecting | **None** | **None** | N/A | **None** | **None** | N/A | No | Admin | ORPHAN |
| Mobile app health ingest — self-reported sessions, crashes and ANRs from the phone apps | View | **None** | **None** | **None** | **None** | Documented | No | Developer | ORPHAN |
| Seeing which scheduled tasks are defined, and when each runs next | View | **None** | N/A | **None** | Failures | N/A | No | Admin | ORPHAN |
| Retrying, forgetting or flushing a failed queue job | View | **None** | N/A | **None** | Failures | N/A | No | Admin | ORPHAN |
| Request debugger — look up an X-Request-Id and see what happened | **None** | **None** | N/A | **None** | Failures | **None** | No | Admin | ORPHAN |
| Marketplace incidents: acknowledge, assign and resolve an operational incident | View | **None** | **None** | **None** | Alerts | Partial | No | System | CONNECTED TO MONITOR |
| Seller-facing issue register (Control Tower issues seen from the marketplace side) | Oversight | View | View | **None** | **None** | Partial | Partial | Admin | CONNECTED TO ADMIN |
| 404 / error log review | Oversight | **None** | **None** | **None** | Failures | Partial | No | Admin | CONNECTED TO ADMIN |
| Monitoring operations centre (33 sections across situation, application, infrastructure, clients, business, operations) | Oversight | **None** | **None** | **None** | Health | Partial | No | System | CONNECTED TO MONITOR |
| Health checks (database, redis, queue, storage, ssl, backup, scheduler, synthetic) | Oversight | **None** | N/A | **None** | Health | **None** | No | System | INTERNAL BY DESIGN |
| Endpoint health (which documented endpoints are actually being called and failing) | Oversight | **None** | **None** | **None** | Health | Full | No | Developer | CONNECTED TO MONITOR |
| Control Tower — what is wrong, arranged by when it needs doing, plus the daily briefing | Oversight | View | Manage | **None** | **None** | Documented | Yes | Seller | CONNECTED TO SELLER |
| Issue Center — the detected problems list and one issue's detail with its resolving action | Oversight | View | View | **None** | Failures | **None** | Yes | Seller | CONNECTED TO SELLER |
| Seller operations oversight for the marketplace — rules, keys, webhooks, team and bulk jobs across every shop, with three interventions | Oversight | **None** | **None** | **None** | **None** | **None** | Yes | Admin | CONNECTED TO ADMIN |
| Analytics grading itself, so a stopped pipeline reads as broken instead of as a quiet week | Oversight | View | **None** | Metrics | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Monitoring reads the trail back to report which security action families this deployment has ever written, and names the blind spots | Oversight | **None** | N/A | **None** | Health | **None** | No | Admin | CONNECTED TO MONITOR |
| Scoping the platform-wide trail to one shop, which is done with a LIKE over an unindexed JSON column | **None** | **None** | View | **None** | **None** | Full | No | System | INTERNAL BY DESIGN |
| Per-endpoint live health: traffic, error rate, p95, last error, who still calls it, and whether it is safe to remove | View | **None** | N/A | **None** | Health | Full | No | Admin | CONNECTED TO MONITOR |
| Endpoint lookup by path, so Monitoring can deep-link into an endpoint's documentation | View | **None** | N/A | **None** | Health | Full | No | Admin | CONNECTED TO MONITOR |
| Monitoring alert thresholds including how long an order may sit in one state before it is called stuck and the payment failure rate that warns | Configure | **None** | N/A | **None** | Alerts | N/A | No | Admin | CONNECTED TO MONITOR |
| Monitoring, analytics and telemetry rollups and pruning run on fixed times of day | Oversight | **None** | N/A | Metrics | Health | N/A | No | System | INTERNAL BY DESIGN |
| Scheduler heartbeat — proof that the server cron is still firing | View | **None** | N/A | **None** | Health | N/A | No | System | INTERNAL BY DESIGN |
| Scheduled task run ledger — one row per task per run with duration, exit status and error | Oversight | **None** | N/A | **None** | Failures | N/A | No | System | CONNECTED TO ADMIN |
| Recording queue job throughput, runtime and failures from the worker | Oversight | **None** | N/A | **None** | Failures | N/A | No | System | CONNECTED TO MONITOR |
| Recording every outbound HTTP call the platform makes (payment gateways, delivery partners, webhooks) | Oversight | **None** | N/A | **None** | Failures | N/A | No | System | CONNECTED TO MONITOR |
| Correlation id on every log line written during a request | View | **None** | N/A | **None** | **None** | N/A | No | Developer | INTERNAL BY DESIGN |
| Collection heartbeat — drain the buffered minute and sample every gauge, once a minute | Oversight | **None** | N/A | **None** | Health | N/A | No | System | CONNECTED TO MONITOR |
| Evaluate the monitoring alert rules against the latest measurements | View | **None** | N/A | **None** | Alerts | N/A | No | System | CONNECTED TO MONITOR |
| Rollup and retention — minutes into hours into days, and pruning past each resolution's window | View | **None** | N/A | **None** | Health | N/A | No | System | CONNECTED TO MONITOR |
| Operations console — one admin area with 33 sections covering the server, the store and both apps | View | **None** | N/A | N/A | Health | **None** | No | Admin | CONNECTED TO ADMIN |
| Health score — one 0-100 number for 'is the shop alright', with the count of signals it could actually measure | View | **None** | N/A | N/A | Health | **None** | No | Admin | CONNECTED TO ADMIN |
| Pulse strip — the status/score/staleness header the console polls every few seconds | View | **None** | N/A | N/A | Health | **None** | No | Admin | CONNECTED TO ADMIN |
| Monitoring watching itself — whether the collector has stopped, which buffer is in use, and how much disk monitoring costs | View | **None** | N/A | N/A | Health | **None** | No | Admin | CONNECTED TO ADMIN |
| Section map — one declaration of which sections exist, their group, hint and required capability | View | **None** | N/A | N/A | **None** | N/A | No | Admin | CONNECTED TO ADMIN |
| Per-request telemetry folded into one-minute buckets per route (hits, errors, latency histogram, DB time) | View | **None** | N/A | N/A | Alerts | **None** | No | System | INTERNAL BY DESIGN |
| In-flight metric buffer — Redis or cache, so a web request never writes a monitoring row | View | **None** | N/A | N/A | Health | N/A | No | System | INTERNAL BY DESIGN |
| Sampled distributed tracing — full span trees for slow and failed requests | View | **None** | N/A | N/A | **None** | N/A | No | Developer | CONNECTED TO ADMIN |
| Slow query capture — queries past the threshold, fingerprinted and attributed to a route | View | **None** | N/A | N/A | **None** | N/A | No | Developer | CONNECTED TO ADMIN |
| Real-user web performance ingest — LCP, INP, CLS and TTFB from the storefront beacon | View | **None** | N/A | Events | **None** | **None** | No | System | CONNECTED TO ADMIN |
| Cardinality guard — a URL with ids in it cannot explode the metrics tables | View | **None** | N/A | N/A | **None** | N/A | No | System | INTERNAL BY DESIGN |
| Host and runtime gauge collection — 14 collectors for CPU, memory, disk, network, PHP, DB, Redis, queue, scheduler, storage, hardware, energy, SSL and web server | View | **None** | N/A | N/A | Alerts | N/A | No | System | CONNECTED TO ADMIN |
| Queue backlog measurement — pending depth, oldest waiting job, stuck reserved jobs, worker processes and the failed_jobs table | View | **None** | N/A | N/A | Alerts | N/A | No | Admin | CONNECTED TO ADMIN |
| Scheduler definition collection — the Laravel schedule, which task is late, missed or failed, and whether cron is installed at all | View | **None** | N/A | N/A | Alerts | N/A | No | Admin | CONNECTED TO ADMIN |
| TLS certificate expiry watch | View | **None** | N/A | N/A | Alerts | N/A | No | Admin | CONNECTED TO MONITOR |
| Web server and PHP-FPM pool measurement — connections, worker pools, listen queue | View | **None** | N/A | N/A | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Energy, temperature and power-draw measurement with an electricity cost estimate | View | **None** | N/A | N/A | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Health and synthetic check runner — eight probes every five minutes, with history for uptime and MTTR | View | **None** | N/A | N/A | Alerts | N/A | No | System | CONNECTED TO MONITOR |
| Database liveness probe — round trip for select 1 | View | **None** | N/A | N/A | Alerts | N/A | No | System | CONNECTED TO MONITOR |
| Redis / cache liveness and latency probe | View | **None** | N/A | N/A | Alerts | N/A | No | System | CONNECTED TO MONITOR |
| Queue drain probe — is anything taking work off the queue | View | **None** | N/A | N/A | Alerts | N/A | No | System | CONNECTED TO MONITOR |
| Cron probe — has schedule:run fired, and did any task miss or fail its last run | View | **None** | N/A | N/A | Alerts | N/A | No | Admin | CONNECTED TO MONITOR |
| Storage probe — disk space, inodes and whether the application's own storage directory is writable | View | **None** | N/A | N/A | Alerts | N/A | No | Admin | CONNECTED TO MONITOR |
| Synthetic journey probe — fetch a real storefront page and assert its status and content | View | **None** | N/A | N/A | Alerts | N/A | No | Admin | CONNECTED TO MONITOR |
| Alert rule evaluation — compare every enabled rule against the last minute, once a minute | View | **None** | N/A | N/A | Health | N/A | No | System | CONNECTED TO MONITOR |
| Incident correlation — many firing rules inside 30 minutes become one incident with a timeline | View | **None** | N/A | N/A | **None** | N/A | No | Admin | CONNECTED TO ADMIN |
| Event timeline write path — one place that records deploys, scheduler failures, backups, incidents, alerts, config changes, check transitions and human notes | View | **None** | N/A | N/A | **None** | N/A | No | System | CONNECTED TO ADMIN |
| Overview — system status, the health score's twelve signals, service cards, traffic comparison and what needs attention | View | **None** | N/A | N/A | Health | **None** | No | Admin | CONNECTED TO ADMIN |
| Live traffic — who is on the site right now and what they are hitting | View | **None** | N/A | Metrics | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Incidents — open problems, their signals, severity, MTTD and MTTR | View | **None** | N/A | N/A | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Timeline — deploys, alerts, incidents, backups, check transitions and scheduler failures on one axis | View | **None** | N/A | N/A | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Application — runtime versions, OPcache, route/config caching and the settings that affect speed | View | **None** | N/A | N/A | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Requests — per-route percentiles, the slowest endpoints and the most-failing endpoints | View | **None** | N/A | N/A | Alerts | **None** | No | Admin | CONNECTED TO ADMIN |
| Traces — where one slow request's time actually went, as a span waterfall | View | **None** | N/A | N/A | **None** | **None** | No | Developer | CONNECTED TO ADMIN |
| Logs — the tail of laravel.log, searchable and pivotable by correlation id | View | **None** | N/A | N/A | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Database — connections, throughput, locks, slow queries and table sizes | View | **None** | N/A | N/A | Alerts | **None** | No | Admin | CONNECTED TO ADMIN |
| Redis and cache — memory, hit ratio, evictions and what actually uses Redis | View | **None** | N/A | N/A | Alerts | **None** | No | Admin | CONNECTED TO ADMIN |
| Queues — pending work, oldest waiting job, worker verdict, throughput and recent failed jobs | View | **None** | N/A | N/A | Alerts | **None** | No | Admin | CONNECTED TO ADMIN |
| Scheduler — every scheduled task with its last run, outcome, duration and next due time | View | **None** | N/A | N/A | Alerts | **None** | No | Admin | CONNECTED TO ADMIN |
| Server — CPU, memory, processes and pressure | View | **None** | N/A | N/A | Alerts | **None** | No | Admin | CONNECTED TO ADMIN |
| Network — bandwidth, packets, TCP state and DNS | View | **None** | N/A | N/A | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Storage — disks, inodes, IO and the application's own storage directory | View | **None** | N/A | N/A | Alerts | **None** | No | Admin | CONNECTED TO ADMIN |
| Web performance — what real shoppers experience (LCP, INP, CLS, TTFB) per page | View | **None** | N/A | Events | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Android — traffic, latency, version mix and self-reported crash-free sessions | View | **None** | **None** | **None** | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| APIs — this shop's own API surface joined to real traffic, by version and endpoint, including deprecated and never-called endpoints | View | **None** | N/A | N/A | **None** | Full | No | Developer | CONNECTED TO DEVELOPER PORTAL |
| Synthetic tests — scripted journeys that run whether or not anyone is shopping | View | **None** | N/A | N/A | Alerts | **None** | No | Admin | CONNECTED TO ADMIN |
| SLA and uptime — availability per service with error budget, MTTD and MTTR | View | **None** | N/A | N/A | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Alerts — every rule, its thresholds, what is currently firing, and whether the engine is still awake | View | **None** | N/A | N/A | Health | **None** | No | Admin | CONNECTED TO ADMIN |
| Monitoring settings — thresholds, retention, sampling, privacy, energy price and integration endpoints, each with its origin | View | **None** | N/A | N/A | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Monitoring regression tests | **None** | **None** | N/A | N/A | N/A | N/A | No | Developer | INTERNAL BY DESIGN |

## Notifications (18)

| Capability | Admin | Seller Web | Flutter App | Analytics | Monitor | Dev Portal | Audit | Owner | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| Transactional notification delivery — every order, refund, wallet, OTP, verification, restock, referral and seller-onboarding email, SMS and push | Configure | **None** | N/A | **None** | Failures | N/A | No | Admin | ORPHAN |
| Email the seller that an order arrived for them | **None** | **None** | N/A | **None** | **None** | N/A | No | Developer | DEPRECATED |
| SendEmailJob — a queued mail job nothing dispatches | **None** | **None** | N/A | **None** | Failures | N/A | No | Developer | DEPRECATED |
| Email template mail tester | View | **None** | **None** | **None** | **None** | Partial | No | Admin | ORPHAN |
| Newsletter subscribers | View | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Send a push notification to customers | Oversight | **None** | **None** | Events | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Push notification message templates and Firebase setup | Configure | **None** | View | **None** | Failures | Partial | No | Admin | CONNECTED TO ADMIN |
| Site-wide announcement bar | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Email templates for every transactional message | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Action Center — the one flat list of everything waiting for this seller, and dismissing an item | Oversight | **None** | Manage | **None** | **None** | Documented | No | Seller | CONNECTED TO SELLER |
| Seller notifications — the in-app list, marking one seen, and the FCM device token | Configure | View | View | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Being told about a problem — aggregated push and mail when insights, SLA breaches or scorecard changes land | **None** | **None** | View | **None** | Failures | **None** | Yes | System | INTERNAL BY DESIGN |
| Chat with customers and with delivery men | Oversight | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Seller notifications and issue escalation raised by the intelligence services | Oversight | View | View | **None** | **None** | **None** | Yes | System | CONNECTED TO ADMIN |
| Recompute what each seller should be looking at (Action Center, home alerts) and notify them | Oversight | View | Manage | **None** | Failures | Documented | No | System | CONNECTED TO SELLER |
| Tell customers the shop has gone into maintenance mode | Configure | **None** | N/A | **None** | Failures | N/A | No | Admin | CONNECTED TO ADMIN |
| SMS gateway credentials and on/off state (fourteen providers) | Configure | **None** | N/A | **None** | Failures | **None** | No | Admin | CONNECTED TO ADMIN |
| Wording of every automated email | Configure | **None** | N/A | **None** | **None** | N/A | No | Admin | CONNECTED TO ADMIN |

## Orders (26)

| Capability | Admin | Seller Web | Flutter App | Analytics | Monitor | Dev Portal | Audit | Owner | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| vendor/get-order-data — an authenticated seller endpoint returning order data that nothing calls | **None** | **None** | **None** | **None** | **None** | **None** | No | Developer | ORPHAN |
| What counts as a late order — three definitions that disagree with the configurable SLA deadline (72-hour stuck, quarter-of-window urgent, fixed 120/480-minute colour bands) | **None** | View | View | **None** | **None** | Documented | No | Admin | ORPHAN |
| Which order states remain editable, and which remain cancellable | Configure | Manage | Manage | **None** | **None** | **None** | No | Admin | ORPHAN |
| Minimum number of items required before a customer may check out | **None** | **None** | View | **None** | **None** | Partial | No | Admin | ORPHAN |
| Fulfilment workflow overlay (pick / pack / ship) | Configure | **None** | **None** | **None** | **None** | Partial | Yes | Admin | CONNECTED TO ADMIN |
| Order list, detail and status changes (single and bulk) | Oversight | Manage | Manage | Metrics | Failures | Partial | No | Admin | CONNECTED TO ADMIN |
| Edit an order after placement (add, remove, reprice lines) | Configure | Manage | Manage | **None** | Failures | Partial | No | Admin | CONNECTED TO ADMIN |
| Order payment status, due amount and COD switching | Oversight | Manage | Manage | **None** | Health | Partial | No | Admin | CONNECTED TO ADMIN |
| Order settings (statuses, delivery, order rules) | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Point of sale (admin-side in-store selling) | Oversight | Manage | Manage | Events | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Order queue and order detail in the classic seller panel — filter by status, open an order, see its lines | Oversight | Manage | Manage | Events | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Seller Center order list and order detail — saved views (ready to ship / shipped / delivered / cancelled), SLA countdown, per-order earnings breakdown | Oversight | View | View | **None** | **None** | Documented | No | Seller | CONNECTED TO SELLER |
| Change an order's status (confirmed, processing, out for delivery, delivered, cancelled) | Configure | Manage | Manage | Events | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Generate and download an order invoice | View | Manage | View | **None** | **None** | Documented | No | Seller | CONNECTED TO SELLER |
| Update the delivery address on an order | Oversight | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Upload the digital file a customer bought, after the sale | Configure | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO ADMIN |
| Export the order list to Excel | View | Manage | Manage | **None** | **None** | Documented | No | Seller | CONNECTED TO SELLER |
| Point of sale — take an in-store order, scan a barcode, apply a coupon, print an invoice | Configure | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO ADMIN |
| The commerce funnel — visit, product, cart, checkout, payment, order — and where it leaks | View | **None** | **None** | Metrics | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Counts of what is waiting for a seller right now (unchecked orders, restock requests) | **None** | View | View | **None** | **None** | Documented | No | Seller | CONNECTED TO SELLER |
| Orders as a measured quantity (volume, status mix, conversion, revenue per order) | Oversight | View | View | Metrics | Health | Documented | Partial | Admin | CONNECTED TO ADMIN |
| Order fulfilment lifecycle — open a fulfilment, advance it, cancel it | Oversight | View | Manage | **None** | **None** | Documented | Yes | Seller | CONNECTED TO ADMIN |
| Order status changes — the most frequent consequential action on the platform | Oversight | Manage | Manage | Events | Failures | Documented | Partial | Seller | CONNECTED TO ADMIN |
| Order integrity — paid orders with no items, totals that disagree with their lines, duplicate submissions and orders stuck in a status | View | **None** | N/A | N/A | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Checkout rules — billing address capture, minimum order amount enforcement, order verification code, free delivery and who pays for it, guest checkout | Configure | View | View | Events | Failures | Partial | No | Admin | CONNECTED TO ADMIN |
| In-house shop order setup — the platform's own minimum order amount and free-delivery threshold, plus its banners, temporary close and vacation window | Configure | **None** | View | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |

## Platform (122)

| Capability | Admin | Seller Web | Flutter App | Analytics | Monitor | Dev Portal | Audit | Owner | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| Seller Center navigation registry — 41 of its 51 designed destinations resolve to no route and are silently dropped from the rail | **None** | **None** | Manage | **None** | **None** | Full | No | Seller | ORPHAN |
| Five pages call route() on names that do not exist, so they throw RouteNotFoundException instead of rendering | View | View | N/A | **None** | **None** | **None** | No | Developer | ORPHAN |
| Installer and software updater — the first-run wizard and the file-based update flow | **None** | **None** | N/A | **None** | **None** | **None** | No | Developer | ORPHAN |
| routes/shared.php and routes/test.php — route files no provider ever loads | **None** | **None** | N/A | **None** | **None** | N/A | No | Developer | DEPRECATED |
| Unlinked admin developer pages — the Kohl design-system gallery and two component galleries mounted on the production admin prefix | View | **None** | N/A | **None** | **None** | **None** | No | Developer | ORPHAN |
| Presentation and query bounds that no operator would tune (search result ceiling, category tree depth, live-viewer refresh window, experience-health staleness, the 5% 'flat' band on the vendor dashboard) | **None** | View | N/A | Metrics | **None** | **None** | No | System | INTERNAL BY DESIGN |
| Request-shaping guards — ingest rate limits, list page sizes, per-screen bulk-action caps, the report date-range ceiling and the automation rule-scope limit | View | View | View | Metrics | Alerts | Documented | No | System | INTERNAL BY DESIGN |
| Silent truncation caps — 500 open issues, 500 SLA deadlines, 200 audit rows, 200 sellers in the admin rollup, 200 automation rules per sweep | Oversight | View | View | **None** | **None** | Documented | No | Admin | ORPHAN |
| Storefront theme preview link lifetime (60 minutes, never more than 24 hours) | Configure | **None** | N/A | **None** | **None** | **None** | Yes | System | INTERNAL BY DESIGN |
| Paid advertising and sponsored placement — ad slots, budgets, billing | **None** | **None** | **None** | **None** | **None** | **None** | No | Admin | ORPHAN |
| Feature flags and gradual rollout | **None** | **None** | **None** | **None** | **None** | **None** | No | Admin | ORPHAN |
| Duplicate addon manager mounted at /admin/addon | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | ORPHAN |
| Legacy v1 advanced search | **None** | **None** | **None** | **None** | **None** | Partial | No | Admin | DEPRECATED |
| Retired theme-installer URL | **None** | **None** | **None** | **None** | **None** | Partial | No | Admin | INTERNAL BY DESIGN |
| Auction feature — master switch, commission, entry fee, claim window, visibility durations and the per-seller permission | Configure | **None** | View | **None** | **None** | Partial | No | Admin | DEPRECATED |
| Event-to-listener wiring for every notification and email on the platform | **None** | **None** | N/A | **None** | **None** | N/A | No | Developer | DEPRECATED |
| Cache invalidation when a business setting, a currency or a translation is written | **None** | **None** | N/A | **None** | **None** | N/A | No | Developer | DEPRECATED |
| Order and Product model lifecycle hooks | **None** | **None** | N/A | **None** | **None** | N/A | No | Developer | DEPRECATED |
| Wipe the database and reimport demo data | **None** | **None** | N/A | **None** | **None** | N/A | No | Developer | INTERNAL BY DESIGN |
| Reset local development settings after a database refresh | **None** | **None** | N/A | **None** | **None** | **None** | No | Developer | DEPRECATED |
| Repair a translation file that no longer parses | **None** | **None** | N/A | **None** | **None** | N/A | No | Developer | INTERNAL BY DESIGN |
| session:flush — garbage-collect sessions and clear caches | **None** | **None** | N/A | **None** | **None** | N/A | No | Developer | DEPRECATED |
| Approve, reject or suspend a seller account (single and bulk) | Approve | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Marketplace campaigns: scheduled storefront overlays with conflict checking | Configure | **None** | **None** | Metrics | **None** | Partial | Yes | Admin | CONNECTED TO ADMIN |
| Banner placement across storefront and app | Configure | **None** | **None** | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Customer accounts: list, block/unblock, delete, manual creation | Configure | **None** | **None** | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Vendor directory, profile view and manual vendor creation | Configure | **None** | **None** | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Support tickets | Oversight | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Customer and vendor chat inbox | Oversight | Manage | Manage | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Contact form submissions | Oversight | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Help topics / FAQ content | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Blog (Blog module addon) | Configure | **None** | **None** | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Theme management: versions, publish, schedule, restore, import/export | Configure | **None** | **None** | **None** | **None** | Partial | Yes | Admin | CONNECTED TO ADMIN |
| Visual theme builder (sections, blocks, delivery rules, media) | Configure | **None** | **None** | Metrics | **None** | Partial | Yes | Admin | CONNECTED TO ADMIN |
| Global theme settings (branding, colours, typography, layout) | Configure | **None** | **None** | **None** | **None** | Partial | Partial | Admin | CONNECTED TO ADMIN |
| App Builder: mobile-app pages, sections, media, templates and health | Configure | **None** | **None** | Metrics | **None** | Partial | Partial | Admin | CONNECTED TO ADMIN |
| Business pages (privacy policy, about, terms) and social media links | Configure | **None** | View | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Seller-recruitment landing page content and registration reasons | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Vacation mode and temporarily closing the shop | Configure | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| SEO settings, robots.txt and webmaster tools | Configure | **None** | **None** | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| SEO templates, translations, per-page meta and redirects | Configure | **None** | **None** | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| SEO health audit | Oversight | **None** | **None** | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Sitemap generation and upload | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Environment setup, HTTPS forcing, cache optimise, Passport install | Configure | **None** | **None** | **None** | Health | Partial | No | Developer | CONNECTED TO ADMIN |
| Maintenance mode (store kill switch) | Configure | **None** | **None** | **None** | Health | Partial | No | Admin | CONNECTED TO ADMIN |
| Business / web configuration (the 71-template settings surface) | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Seller-facing platform settings (registration open, seller POS, order editing, minimum order) | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Customer-facing settings and product settings | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Languages and translation management (including auto-translate) | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| File storage backend (local vs S3) and credentials | Configure | **None** | View | **None** | Health | Partial | No | Developer | CONNECTED TO ADMIN |
| File manager / media gallery | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Database maintenance (clean-up) | Configure | **None** | **None** | **None** | Health | Partial | No | Developer | CONNECTED TO ADMIN |
| Software update / version installer | Configure | **None** | **None** | **None** | Health | Partial | No | Developer | CONNECTED TO ADMIN |
| Addon modules: publish, unpublish, upload, delete | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Addon licence activation by purchase code | Configure | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| App settings and deep links (mobile app configuration) | Configure | **None** | **None** | Events | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Admin global search | View | **None** | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Sidebar pin shortcuts (per-admin, server-side) | Configure | Manage | N/A | **None** | **None** | Partial | No | Admin | INTERNAL BY DESIGN |
| Kohl design-system gallery | View | **None** | **None** | **None** | **None** | Partial | No | Developer | INTERNAL BY DESIGN |
| Component and component-snippet demo pages | View | **None** | **None** | **None** | **None** | Partial | No | Developer | INTERNAL BY DESIGN |
| Auction time adjustment (demo mode only) | Configure | **None** | **None** | **None** | **None** | Partial | No | Developer | INTERNAL BY DESIGN |
| Auction management (Auction module addon) | Configure | Manage | Manage | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Shop profile — name, logo, banner, address, contact | Oversight | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Other shop setup — minimum order amount, free delivery threshold, business TIN and its expiry | Configure | Manage | Manage | **None** | **None** | Documented | No | Seller | CONNECTED TO ADMIN |
| Seller profile and password, and the legacy bank-info form | Oversight | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Delete the seller account | **None** | **None** | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Whether sellers can register at all, and how a seller resets their password | Configure | **None** | **None** | **None** | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Seller Center cockpit page in the classic panel — verification, performance, finance and SLA in one view | Oversight | View | View | **None** | **None** | Documented | No | Seller | CONNECTED TO SELLER |
| Seller Center home, global search, command palette and shell preferences (density, direction) | **None** | Manage | **None** | **None** | **None** | **None** | No | Seller | CONNECTED TO SELLER |
| Wave-1 acceptance screen for the Seller Center component library | **None** | View | N/A | **None** | **None** | **None** | No | Developer | INTERNAL BY DESIGN |
| V2 sidebar pin shortcuts, persisted per seller | **None** | Manage | N/A | **None** | **None** | **None** | No | Seller | INTERNAL BY DESIGN |
| Read-only view of what analytics collects, what it excludes, what it keeps and for how long | View | **None** | N/A | **None** | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Theme lifecycle — activate, publish, restore a version, add/edit/reorder/delete sections, scheduled publish outcomes | Configure | **None** | N/A | **None** | **None** | **None** | Yes | Admin | CONNECTED TO ADMIN |
| Experience pages and merchandising objects — collections, campaigns, segments, experiments | Configure | **None** | **None** | Metrics | **None** | **None** | Yes | Admin | CONNECTED TO ADMIN |
| Live API manifest: every route under api/ described from the route table, its middleware, its auth and its validation rules | View | **None** | N/A | **None** | Health | Full | No | Developer | CONNECTED TO DEVELOPER PORTAL |
| #[ApiDoc] attribute — the only hand-written part of an endpoint's documentation (intent, stability, audience, visibility, since, sunset, scopes, emitted events) | View | **None** | N/A | **None** | **None** | Full | No | Developer | CONNECTED TO DEVELOPER PORTAL |
| Endpoint classification — audience, resource group, version, visibility and api-vs-panel surface inferred for every route | View | **None** | N/A | **None** | **None** | Full | No | Developer | CONNECTED TO DEVELOPER PORTAL |
| Request schema recovered from FormRequest classes and inline validator() calls, translated into readable field types | View | **None** | N/A | **None** | **None** | Full | No | Developer | CONNECTED TO DEVELOPER PORTAL |
| Response shapes learned from live traffic (keys and types only, never values) | View | **None** | N/A | **None** | **None** | Full | No | System | CONNECTED TO DEVELOPER PORTAL |
| Copy-paste code examples per endpoint in curl, Dart, Kotlin, Swift, JavaScript and PHP | View | **None** | N/A | **None** | **None** | Full | No | Developer | CONNECTED TO DEVELOPER PORTAL |
| API surface snapshots and the generated changelog of added, removed and breaking changes | Oversight | **None** | N/A | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| `php artisan api:manifest` — build and inspect the normalised API manifest from the command line | **None** | **None** | N/A | **None** | **None** | Full | No | Developer | INTERNAL BY DESIGN |
| Documentation quality score — what is undocumented, unclassified or missing a schema, grouped by reason | Oversight | **None** | N/A | **None** | **None** | Full | No | Admin | CONNECTED TO ADMIN |
| Portal navigation: 25 sections grouped into Getting started, Reference, Conventions, Change management, Tools and Operations | View | **None** | N/A | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Portal section: API Explorer — filter every endpoint by audience, version, group, method, visibility and auth | View | **None** | N/A | **None** | Health | Full | No | Admin | CONNECTED TO ADMIN |
| Portal section: Errors — the error envelope and what each HTTP status means on this API | View | **None** | N/A | **None** | **None** | Partial | No | Developer | CONNECTED TO ADMIN |
| Portal section: Rate limits — the throttles actually configured on the routes, tightest first | View | **None** | N/A | **None** | **None** | Full | No | Developer | CONNECTED TO ADMIN |
| Portal section: Pagination and File uploads — the conventions, plus the endpoints that use each | View | **None** | N/A | **None** | **None** | Full | No | Developer | CONNECTED TO ADMIN |
| Portal section: Versions — endpoints per version, audiences per version, and 30-day traffic to decide what can be retired | Oversight | **None** | N/A | **None** | Health | Full | No | Admin | CONNECTED TO ADMIN |
| Portal section: Deprecations — what is going away, when, and what replaces it | Oversight | **None** | N/A | **None** | Failures | Partial | No | Admin | CONNECTED TO ADMIN |
| Portal section: Changelog — API changes generated from snapshot diffs rather than written by hand | Oversight | **None** | N/A | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Seller Center v3 API — the whole 86-endpoint surface the new seller app calls | Oversight | Manage | Manage | Metrics | Health | Full | Yes | Seller | CONNECTED TO DEVELOPER PORTAL |
| Customer-app endpoints that do carry documentation: app health, deep links, analytics events, banners and theme sections | Configure | **None** | N/A | Events | Health | Documented | No | Developer | CONNECTED TO DEVELOPER PORTAL |
| Theme structure limits: 200 sections per theme, 24 blocks per section, 24 picked resources, 12 items per content bundle | Configure | **None** | N/A | **None** | **None** | **None** | No | Admin | INTERNAL BY DESIGN |
| Seller Center badge counts are cached for 60 seconds and search returns 5 results per group | **None** | View | N/A | **None** | **None** | **None** | No | Seller | INTERNAL BY DESIGN |
| The master clock: the server cron that runs every scheduled task on the platform | Oversight | **None** | N/A | **None** | Health | N/A | No | System | CONNECTED TO MONITOR |
| Running the queue worker at all (no worker = bulk jobs, webhooks and queued mail never run) | View | **None** | N/A | **None** | Health | N/A | No | System | CONNECTED TO MONITOR |
| Publish theme versions a merchant scheduled for a future date/time | Configure | **None** | N/A | **None** | Failures | N/A | Yes | Admin | CONNECTED TO ADMIN |
| Static audit of every Blade template for RTL, i18n, accessibility and layout defects | **None** | **None** | N/A | **None** | **None** | **None** | No | Developer | INTERNAL BY DESIGN |
| Create a minimal local test schema so the app boots without the proprietary SQL dump | **None** | **None** | N/A | **None** | **None** | **None** | No | Developer | INTERNAL BY DESIGN |
| Fix file permissions on sitemap.xml and robots.txt after deployment | Configure | **None** | N/A | **None** | **None** | N/A | No | Admin | CONNECTED TO ADMIN |
| Dump every registered admin GET route to JSON with its Blade path and keywords | **None** | **None** | N/A | **None** | **None** | **None** | No | Developer | INTERNAL BY DESIGN |
| Scaffold a model, repository interface and implementation for a new entity | **None** | **None** | N/A | **None** | **None** | **None** | No | Developer | INTERNAL BY DESIGN |
| Build the CodeCanyon installable ZIP (strips non-default themes and the Auction/Gateways modules) | **None** | **None** | N/A | **None** | **None** | **None** | No | Developer | INTERNAL BY DESIGN |
| Swap in the activation/update RouteServiceProvider before packaging | **None** | **None** | N/A | **None** | **None** | **None** | No | Developer | INTERNAL BY DESIGN |
| Build the update-only package (strips non-default themes and the Gateways module) | **None** | **None** | N/A | **None** | **None** | **None** | No | Developer | INTERNAL BY DESIGN |
| Backup age and restore-test probe — when a backup last succeeded and whether anyone has ever restored one | View | **None** | N/A | N/A | Failures | N/A | No | Admin | CONNECTED TO MONITOR |
| Deployments — which build started running when, with migrations run and errors before and after | View | **None** | N/A | N/A | **None** | **None** | No | Developer | CONNECTED TO ADMIN |
| Backups — age, size trend, outcome and when a restore was last tested | View | **None** | N/A | N/A | Failures | **None** | No | Admin | CONNECTED TO ADMIN |
| Developer Portal API console — whether admins may fire real requests at the live API, and whether write verbs are permitted | View | **None** | N/A | **None** | **None** | Full | Yes | Developer | INTERNAL BY DESIGN |
| Developer Portal response-shape learning — recording the keys and types real API responses return | **None** | **None** | N/A | **None** | **None** | Full | No | Developer | INTERNAL BY DESIGN |
| Module publish/enable state for Blog, TaxModule, AI and Auction | Configure | **None** | View | **None** | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Maintenance mode — taking the storefront, admin panel or any of the three apps offline with a message and a window | Configure | **None** | View | **None** | Health | Partial | No | Admin | CONNECTED TO ADMIN |
| Store identity — company name, email, phone, address, country code, registration/VAT/platform numbers, pagination size, timezone, currency symbol position, decimal places, business mode, copyright and cookie banner | Configure | **None** | View | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Marketplace-wide vendor rules — seller POS, seller self-registration, per-seller minimum order, review replies, whether vendors may edit orders, vendor forgot-password channel | Configure | View | View | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Storefront look — theme activation, draft/publish versions, section builder, colours, typography and layout tokens | Configure | **None** | N/A | Events | **None** | N/A | Yes | Admin | CONNECTED TO ADMIN |
| Languages available in the panel and storefront, the default, and the translation strings | Configure | **None** | Manage | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Forced app upgrades — minimum required Android/iOS version for the customer, seller and delivery apps | Configure | **None** | View | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Storefront content blocks — announcement bar, features section, company reliability badges, social media links | Configure | **None** | View | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Seller shop configuration — shop profile, banner, temporary close, vacation window and business TIN | Oversight | Manage | Manage | **None** | **None** | Documented | No | Seller | CONNECTED TO SELLER |
| Setup-guide progress checklist shown to admins and vendors | View | View | Submit | **None** | **None** | **None** | No | System | INTERNAL BY DESIGN |
| Settings cache — the three-hour business_settings cache and the invalidation map behind every settings save | Oversight | **None** | N/A | **None** | Health | N/A | No | System | INTERNAL BY DESIGN |

## Pricing (15)

| Capability | Admin | Seller Web | Flutter App | Analytics | Monitor | Dev Portal | Audit | Owner | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| Mass product updates written through the query builder bypass the price observer | Configure | **None** | **None** | **None** | **None** | **None** | No | Developer | ORPHAN |
| What counts as a suspicious price swing (more than half the previous price within 48 hours) | **None** | View | View | **None** | **None** | Documented | No | Admin | ORPHAN |
| Coupons, and the promotional discount surfaces beside them (flash deals, deal of the day, featured deals, clearance offers) | Configure | Manage | Manage | Metrics | **None** | Documented | No | Admin | CONNECTED TO ADMIN |
| Flash deals, deal of the day and featured deals | Configure | **None** | **None** | Metrics | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Clearance sale with vendor offers and priority setup | Approve | Manage | Manage | Metrics | **None** | Documented | No | Admin | CONNECTED TO ADMIN |
| Set the selling price and discount on a listing | Oversight | Manage | Manage | **None** | **None** | Partial | Partial | Seller | CONNECTED TO SELLER |
| Price change history — who changed this price, when, and from what | View | **None** | View | **None** | **None** | Documented | Yes | Seller | CONNECTED TO SELLER |
| Pricing policy — the floor under this shop's own prices | Configure | View | Manage | **None** | **None** | Documented | Yes | Seller | CONNECTED TO SELLER |
| Clearance sale — put the shop's own stock on discount with its own config and SEO block | Oversight | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Coupons — create, edit, activate and expire the shop's own coupons | Oversight | Manage | Manage | Events | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Product price change, from any writer — admin panel, vendor panel, three API versions, bulk importer or an automation rule | View | Manage | Manage | **None** | **None** | Documented | Yes | Seller | CONNECTED TO ADMIN |
| Percentage discounts on clearance products and coupons must be between 1 and 100 | Configure | Manage | Manage | **None** | **None** | **None** | No | Admin | INTERNAL BY DESIGN |
| Seller price floor policy — minimum margin percent, minimum price and whether it is enforced | Configure | Manage | Manage | **None** | **None** | Documented | Yes | Seller | CONNECTED TO SELLER |
| Record every product price change, whoever made it and from which surface | View | View | View | **None** | **None** | Documented | Yes | System | CONNECTED TO ADMIN |
| Stock clearance sale setup — the platform's own clearance campaign and whether vendor clearance offers appear on the homepage | Configure | Manage | Manage | **None** | **None** | Documented | No | Admin | CONNECTED TO ADMIN |

## Returns (7)

| Capability | Admin | Seller Web | Flutter App | Analytics | Monitor | Dev Portal | Audit | Owner | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| Returns and refunds as measured quantities — return rate by reason, time to receive, restock rate, refund volume, value and time to settle | Oversight | View | View | Events | **None** | **None** | Partial | Admin | ORPHAN |
| Approve or reject a customer refund | Approve | Manage | Manage | Events | **None** | Partial | No | Admin | ORPHAN |
| The returns response promise — 48 hours to answer a return request, 72 hours to process it | **None** | View | View | **None** | **None** | Documented | No | Admin | ORPHAN |
| Global return / refund policy (refund day limit, wallet refunds) | Configure | View | View | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| RMA / returns logistics queue (authorize, in-transit, receive, reject) | Configure | **None** | Manage | **None** | **None** | Documented | Yes | Admin | CONNECTED TO ADMIN |
| Return authorisations — authorise, receive (restocking), reject | Approve | View | Manage | **None** | **None** | Full | Yes | Admin | CONNECTED TO ADMIN |
| Return window in days, globally and per category, deciding how long a customer may ask for a refund | Configure | View | View | **None** | **None** | Documented | No | Admin | CONNECTED TO ADMIN |

## Security (47)

| Capability | Admin | Seller Web | Flutter App | Analytics | Monitor | Dev Portal | Audit | Owner | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| Authentication events — sign-in success, sign-in failure and lockout for admins, sellers and seller staff | **None** | **None** | **None** | Events | Alerts | **None** | No | Admin | ORPHAN |
| The before/after values and actor context on every audited change | View | **None** | View | **None** | **None** | Documented | Yes | Admin | ORPHAN |
| Who may read the audit trail | View | **None** | **None** | **None** | **None** | **None** | Yes | Admin | ORPHAN |
| The seller's web view of their own audit trail | View | **None** | View | **None** | **None** | Full | Yes | Seller | ORPHAN |
| Admin employee accounts and admin custom roles — who operates the platform and which modules they may touch | Configure | **None** | N/A | **None** | **None** | **None** | No | Admin | ORPHAN |
| Business settings — the several hundred DB-driven switches the whole platform boots from | Configure | **None** | View | **None** | Health | **None** | No | Admin | ORPHAN |
| reCAPTCHA on customer login, registration and both forgot-password flows, and the bot score that refuses a shopper | **None** | **None** | **None** | **None** | Health | **None** | No | Admin | ORPHAN |
| Which channel a customer password reset is sent through — email or SMS OTP | **None** | **None** | View | **None** | **None** | Partial | No | Admin | ORPHAN |
| Minimum password length — 6 characters on some surfaces and 8 on others | **None** | **None** | **None** | **None** | **None** | Documented | No | Admin | ORPHAN |
| Brute-force tolerance — 20 attempts a minute on auth endpoints, 3000 a minute globally | **None** | **None** | **None** | **None** | Alerts | Documented | No | Admin | ORPHAN |
| Seller staff reaching the shop's own analytics page | **None** | **None** | View | Metrics | **None** | Documented | No | Seller Staff | ORPHAN |
| The authentication requirement the portal reports for the v2 seller API | View | **None** | N/A | **None** | **None** | Full | No | Developer | ORPHAN |
| The permission scope an endpoint requires, and which endpoints a seller-issued API key may call | View | **None** | Manage | **None** | **None** | Partial | No | Developer | ORPHAN |
| Unified audit trail viewer | Oversight | **None** | **None** | **None** | **None** | Partial | Yes | Admin | CONNECTED TO ADMIN |
| Seller API key oversight: revoke a leaked key | Oversight | **None** | Manage | **None** | **None** | Full | Yes | Admin | CONNECTED TO ADMIN |
| Seller staff and role oversight (who is acting for a shop) | Oversight | Manage | Manage | **None** | **None** | Full | Yes | Admin | CONNECTED TO ADMIN |
| Admin employees and custom roles / permissions | Configure | **None** | **None** | **None** | Health | Partial | No | Admin | CONNECTED TO ADMIN |
| Login and OTP settings, and the secret admin login URL | Configure | **None** | **None** | **None** | Health | Partial | No | Admin | CONNECTED TO ADMIN |
| Firebase OTP authentication configuration | Configure | **None** | **None** | **None** | Health | Partial | No | Admin | CONNECTED TO ADMIN |
| Admin profile and password | Configure | Manage | Manage | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Admin authentication and logout | Configure | Manage | Manage | **None** | Health | Partial | No | Admin | CONNECTED TO ADMIN |
| Seller staff and roles — define a role, grant permissions, add and remove team members | Oversight | Manage | Manage | **None** | **None** | Documented | Yes | Seller | CONNECTED TO SELLER |
| Sign a staff member out of every session, and see who last accessed the shop | **None** | **None** | Manage | **None** | **None** | Documented | Yes | Seller | CONNECTED TO SELLER |
| The shop's own audit trail — what was done in this shop, by whom, with the before and after | Oversight | **None** | View | **None** | **None** | Documented | Yes | Seller | CONNECTED TO SELLER |
| Seller staff sign-in with their own credentials | **None** | Manage | **None** | **None** | **None** | **None** | No | Seller Staff | CONNECTED TO SELLER |
| Stripping passwords, OTPs, tokens and card numbers out of anything an instrumentation call attaches to an event | View | **None** | N/A | **None** | **None** | **None** | No | System | INTERNAL BY DESIGN |
| Five separate permissions over analytics: read, export, campaign links, individual journeys, collection settings | Configure | **None** | N/A | **None** | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| The one place anything records who did what — resolves the actor from the admin guard, the seller guard, or the API token's principal, stamps IP and user agent, and never throws into the caller | View | **None** | View | **None** | Health | **None** | Yes | System | CONNECTED TO ADMIN |
| Admin audit center — the single screen where an operator reads the platform's activity trail, filtered by module, actor type and free-text search | View | **None** | N/A | **None** | **None** | **None** | No | Admin | CONNECTED TO ADMIN |
| Seller reads their own shop's trail — actions by the owner, their staff and their API keys, plus decisions the marketplace recorded about the shop | View | **None** | View | **None** | **None** | Full | No | Seller | CONNECTED TO SELLER |
| Flutter seller app security screen — the app's audit tab, filterable by action prefix | **None** | **None** | View | **None** | **None** | Full | No | Seller | CONNECTED TO SELLER |
| Retention and rotation of the audit trail itself | **None** | **None** | N/A | **None** | **None** | **None** | No | System | INTERNAL BY DESIGN |
| Authentication requirement resolved per endpoint from the middleware that actually runs (Passport, Sanctum, seller token, delivery token, courier webhook secret, panel sessions) | View | **None** | N/A | **None** | **None** | Full | No | Developer | CONNECTED TO DEVELOPER PORTAL |
| Console safety guard: which endpoints may be fired at all, which need a typed confirmation, and which are never sent | Configure | **None** | N/A | **None** | **None** | Full | Partial | Admin | CONNECTED TO ADMIN |
| Portal section: Authentication — the token types this API issues and what each one opens, with usage counts | View | **None** | N/A | **None** | **None** | Full | No | Developer | CONNECTED TO ADMIN |
| Seller API keys: issue, list, revoke, scope-narrow, and see last-used IP and time | Oversight | **None** | Manage | **None** | **None** | Documented | Yes | Seller | CONNECTED TO SELLER |
| Seller roles, staff and permission catalogue over the API | Oversight | **None** | Manage | **None** | **None** | Full | Yes | Seller | CONNECTED TO SELLER |
| Who may read the API documentation | View | **None** | **None** | **None** | **None** | Full | No | Admin | CONNECTED TO ADMIN |
| Visibility policy on generated artefacts (who an OpenAPI or Postman download may be handed to) | Configure | **None** | N/A | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Seller API credentials: 6-character public prefix and 40-character secret | Oversight | Manage | Manage | **None** | **None** | Documented | Yes | Seller | INTERNAL BY DESIGN |
| Find admin roles whose module_access JSON still holds pre-rename permission keys | **None** | **None** | N/A | **None** | **None** | N/A | No | Developer | INTERNAL BY DESIGN |
| Fine-grained monitoring permissions — six capabilities so not every admin sees logs, security and server internals | Configure | **None** | N/A | N/A | **None** | N/A | No | Admin | CONNECTED TO ADMIN |
| Redaction — secrets, tokens and customer values stripped before anything monitoring stores | View | **None** | N/A | N/A | **None** | N/A | No | System | INTERNAL BY DESIGN |
| Security — refused requests by status, admin activity from the audit log, and suspicious sources | View | **None** | N/A | Metrics | **None** | **None** | Yes | Admin | CONNECTED TO ADMIN |
| Application mode — demo/dev/live, which decides whether OTPs are random or the fixed test codes 1234/123456 and whether settings forms save at all | Configure | **None** | N/A | **None** | **None** | N/A | No | Admin | CONNECTED TO ADMIN |
| Force HTTPS on all generated URLs | Configure | **None** | N/A | **None** | Health | N/A | No | Admin | CONNECTED TO ADMIN |
| How customers and staff sign in — login options, social login on the login form, email/phone verification, OTP attempt limits and lockout windows, and the secret admin/employee login URLs | Configure | **None** | View | **None** | Alerts | Partial | No | Admin | CONNECTED TO ADMIN |

## Shipping (17)

| Capability | Admin | Seller Web | Flutter App | Analytics | Monitor | Dev Portal | Audit | Owner | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| Registering a second courier — credentials, rates, labels and tracking per carrier | **None** | **None** | **None** | **None** | **None** | **None** | No | Admin | ORPHAN |
| Shipping and fulfilment as measured quantities — what shipping costs, which zone is expensive, dispatch time and lateness | Oversight | Manage | View | **None** | **None** | **None** | Partial | Admin | ORPHAN |
| How long a shipment may go without courier movement before it is raised as an exception (72 hours) | **None** | View | View | **None** | **None** | Documented | No | Admin | ORPHAN |
| Shipping zones — destination-based rate rules that override the flat shipping cost | Configure | **None** | **None** | **None** | **None** | **None** | Partial | Admin | CONNECTED TO ADMIN |
| Carrier configuration for Delivery Syria — base URL, hub, pickup point, secret and webhook tokens | Configure | **None** | **None** | **None** | Failures | **None** | No | Admin | CONNECTED TO ADMIN |
| Shipping methods and shipping responsibility (admin vs seller) | Configure | Manage | **None** | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Dispatch an order to the carrier | Oversight | **None** | **None** | **None** | Health | Partial | No | Admin | CONNECTED TO ADMIN |
| Delivery men: accounts, earnings, cash collection, withdrawals, ratings | Configure | Manage | Manage | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Delivery man settings and delivery-zone restrictions (countries, zip codes) | Configure | **None** | View | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Assign a delivery man or a third-party courier to an order | Oversight | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Shipping methods and their rates for this shop | Configure | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO ADMIN |
| Category-wise shipping cost, and choosing order-wise / product-wise / category-wise shipping | Configure | Manage | Manage | **None** | **None** | Documented | No | Seller | CONNECTED TO SELLER |
| The shop's own delivery men — add, edit, rate, suspend, see their order history | Configure | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Delivery man emergency contacts | **None** | Manage | Manage | **None** | **None** | Partial | No | Seller | CONNECTED TO SELLER |
| Delivery Syria courier API request timeouts of 20 seconds total and 10 seconds to connect | Configure | **None** | **None** | **None** | Failures | **None** | No | System | INTERNAL BY DESIGN |
| Delivery staff rules — forgot-password channel and whether a delivery photo is required | Configure | **None** | N/A | **None** | **None** | Partial | No | Admin | CONNECTED TO ADMIN |
| Delivery Syria courier integration — credentials, rate sync and parcel creation | Configure | **None** | **None** | **None** | Failures | **None** | No | Admin | CONNECTED TO ADMIN |

