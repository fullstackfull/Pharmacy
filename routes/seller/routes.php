<?php

use App\Http\Controllers\Seller\ActionCenterController;
use App\Http\Controllers\Seller\ApprovalController;
use App\Http\Controllers\Seller\AuditController;
use App\Http\Controllers\Seller\AutomationController;
use App\Http\Controllers\Seller\AutomationHistoryController;
use App\Http\Controllers\Seller\BrandController;
use App\Http\Controllers\Seller\BulkJobController;
use App\Http\Controllers\Seller\ComplianceController;
use App\Http\Controllers\Seller\ControlTowerController;
use App\Http\Controllers\Seller\FoundationController;
use App\Http\Controllers\Seller\FinanceController;
use App\Http\Controllers\Seller\FulfilmentController;
use App\Http\Controllers\Seller\HomeController;
use App\Http\Controllers\Seller\IncidentController;
use App\Http\Controllers\Seller\IntegrationController;
use App\Http\Controllers\Seller\InventoryController;
use App\Http\Controllers\Seller\IssueController;
use App\Http\Controllers\Seller\OpportunityController;
use App\Http\Controllers\Seller\OrderController;
use App\Http\Controllers\Seller\HelpController;
use App\Http\Controllers\Seller\PerformanceController;
use App\Http\Controllers\Seller\PreferencesController;
use App\Http\Controllers\Seller\PricingController;
use App\Http\Controllers\Seller\ProductController;
use App\Http\Controllers\Seller\RefundController;
use App\Http\Controllers\Seller\ReturnController;
use App\Http\Controllers\Seller\SearchController;
use App\Http\Controllers\Seller\SecurityController;
use App\Http\Controllers\Seller\TeamController;
use App\Http\Controllers\Seller\WarehouseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Seller Center
|--------------------------------------------------------------------------
|
| The seller's operations surface (design handoff 01), mounted on `/vendor` — the panel sellers
| already know. One panel, and the new screens are added to it rather than swapped in over the old
| ones: every page that exists today is still at the address it is at today, working as it works
| today.
|
| Two things are true of every route below and are enforced here rather than remembered per
| controller: the request carries a `SellerPrincipal` resolved from the session, and any route
| touching a permissioned domain declares that permission with `seller_can:` — the same middleware
| the seller app's API uses, reading the same keys. A screen's menu item disappearing is a courtesy;
| this is the enforcement.
|
| Nothing here replaces or removes a legacy page. The navigation registry links to both — the new
| screens by route, the existing ones by their own URL — so a seller moves between them inside one
| panel and nothing they use today stops working (PART 15: everything PRESERVED).
*/

Route::group(['middleware' => ['maintenance_mode', 'actch:admin_panel']], function () {
    Route::group([
        // `/vendor`, not a second panel beside it. Route *names* stay `seller.` so the Seller
        // Center's own links are one namespace, and so they cannot collide with the several
        // hundred `vendor.` names the classic panel already owns.
        'prefix' => 'vendor',
        'as' => 'seller.',
        'middleware' => ['seller', 'seller_staff_access', 'seller_center'],
    ], function () {

        // ── shell preferences ────────────────────────────────────────────
        Route::controller(PreferencesController::class)->prefix('preferences')->as('preferences.')->group(function () {
            Route::get('density', 'density')->name('density');
            Route::get('direction', 'direction')->name('direction');
        });

        // ── wave 2 · core seller operations ──────────────────────────────
        // Beside the classic dashboard, not over it. Both are reachable from the same navigation.
        Route::get('overview', HomeController::class)->name('home');
        Route::get('control-tower', ControlTowerController::class)->name('control-tower');

        Route::controller(IssueController::class)->group(function () {
            Route::get('issues', 'index')->name('issues.index');
            Route::get('issues/{issue}', 'show')->name('issues.show')->whereNumber('issue');
        });

        Route::controller(OrderController::class)->middleware('seller_can:orders.view,orders.manage')->group(function () {
            Route::get('orders', 'index')->name('orders.index');
            Route::get('orders/{order}', 'show')->name('orders.show')->whereNumber('order');
        });

        Route::controller(ProductController::class)->middleware('seller_can:products.view,products.manage')->group(function () {
            Route::get('products', 'index')->name('products.index');
        });

        Route::controller(InventoryController::class)->middleware('seller_can:products.view,products.manage')->group(function () {
            Route::get('inventory', 'index')->name('inventory.index');
            Route::get('inventory/movements', 'movements')->name('inventory.movements');
        });

        // ── wave 3 · automation ──────────────────────────────────────────
        // Reading a rule and the record of what it did is catalogue history; writing one changes
        // the catalogue. The two are gated separately, exactly as the seller app's API gates them.
        Route::controller(AutomationController::class)->prefix('automation')->as('automation.')->group(function () {
            Route::middleware('seller_can:products.view,products.manage')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('{rule}/preview', 'preview')->name('preview')->whereNumber('rule');
            });

            Route::middleware('seller_can:products.manage')->group(function () {
                Route::get('new', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('{rule}/edit', 'edit')->name('edit')->whereNumber('rule');
                Route::put('{rule}', 'update')->name('update')->whereNumber('rule');
                Route::put('{rule}/status', 'setStatus')->name('status')->whereNumber('rule');
                Route::post('{rule}/run', 'runNow')->name('run')->whereNumber('rule');
                Route::delete('{rule}', 'destroy')->name('destroy')->whereNumber('rule');
            });
        });

        Route::controller(AutomationHistoryController::class)->group(function () {
            Route::get('automation/history', 'index')->name('automation.history')
                ->middleware('seller_can:products.view,products.manage');
            Route::post('automation/history/{action}/revert', 'revert')->name('automation.revert')
                ->whereNumber('action')->middleware('seller_can:products.manage');
        });

        Route::get('opportunities', OpportunityController::class)->name('opportunities.index')
            ->middleware('seller_can:products.view,products.manage');

        // The shop's own history. The navigation has reserved this name since Wave 1 and the route
        // did not exist, so the menu item was silently dropped and a seller could read their trail
        // only from the phone app — which drops the before/after values.
        Route::get('audit', [AuditController::class, 'index'])->name('audit.index')
            ->middleware('seller_can:staff.manage');

        // ── wave 4 · fulfilment ──────────────────────────────────────────
        // Everything waiting for this seller. The Control Tower's counts point here, so the missing
        // route was worse than a missing page: it was a badge that led nowhere.
        Route::controller(ActionCenterController::class)->group(function () {
            Route::get('actions', 'index')->name('actions');
            Route::post('actions/{insight}/dismiss', 'dismiss')->name('actions.dismiss')->whereNumber('insight');
        });

        // Returns and refunds. Reading either is order history; moving a return changes stock, so
        // the writes carry the manage permission and the reads do not.
        Route::controller(ReturnController::class)->prefix('returns')->as('returns.')->group(function () {
            Route::middleware('seller_can:orders.view,orders.manage')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('{rma}', 'show')->name('show')->whereNumber('rma');
            });

            Route::middleware('seller_can:orders.manage')->group(function () {
                Route::post('{rma}/in-transit', 'markInTransit')->name('in-transit')->whereNumber('rma');
                Route::post('{rma}/receive', 'receive')->name('receive')->whereNumber('rma');
                Route::post('{rma}/reject', 'reject')->name('reject')->whereNumber('rma');
            });
        });

        // Read-only by design: a seller cannot approve their own refund, and a screen that showed a
        // disabled button would be arguing with its reader rather than informing them.
        Route::get('refunds', [RefundController::class, 'index'])->name('refunds.index')
            ->middleware('seller_can:orders.view,orders.manage');

        /*
        | Fulfilment: four screens over one list service.
        |
        | Picking, packing, shipments and exceptions are four questions about the same rows. The
        | exceptions view is the one with consequences — the packed and shipped timestamps have been
        | written since the record was built and nothing ever subtracted them, so a marketplace that
        | suspends sellers for lateness could not show a seller which order was late.
        */
        Route::controller(FulfilmentController::class)->group(function () {
            Route::middleware('seller_can:orders.view,orders.manage')->group(function () {
                Route::get('shipments', 'index')->name('shipments.index');
                Route::get('shipments/exceptions', 'exceptions')->name('shipments.exceptions');
                Route::get('picking', 'picking')->name('picking.index');
                Route::get('packing', 'packing')->name('packing.index');
            });

            Route::post('shipments/{fulfilment}/advance', 'advance')->name('shipments.advance')
                ->whereNumber('fulfilment')->middleware('seller_can:orders.manage');
        });

        // Where the stock physically is. current_stock says how much a seller has and never said
        // where any of it was, so a shop with two locations could not tell which one to pick from.
        Route::get('warehouse', [WarehouseController::class, 'index'])->name('warehouse.index')
            ->middleware('seller_can:products.view,products.manage');

        // The receipt for every bulk change. A bulk operation that reports "done" and quietly
        // refused four hundred rows is worse than one that fails outright.
        Route::controller(BulkJobController::class)->prefix('bulk-jobs')->as('bulk-jobs.')
            ->middleware('seller_can:products.view,products.manage')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('{job}', 'show')->name('show')->whereNumber('job');
            });

        // ── wave 5 · finance and pricing ─────────────────────────────────
        /*
        | Six views of one ledger, behind one controller.
        |
        | A controller each would mean six places that could disagree about what "available" means —
        | and the reason this area exists at all is that a seller stopped trusting a single number
        | nobody could account for.
        */
        Route::controller(FinanceController::class)->prefix('finance')->as('finance.')
            ->middleware('seller_can:finance.view')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('transactions', 'transactions')->name('transactions');
                Route::get('statements', 'statements')->name('statements');
                Route::get('payouts', 'payouts')->name('payouts');
                Route::get('reconciliation', 'reconciliation')->name('reconciliation');
                Route::get('fees', 'fees')->name('fees');
            });

        // The shop's own price floor, and every price that has moved. Reading the history is
        // catalogue history; moving the floor changes what the catalogue may charge.
        Route::controller(PricingController::class)->prefix('pricing')->as('pricing.')->group(function () {
            Route::middleware('seller_can:products.view,products.manage')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('history', 'history')->name('history');
            });

            Route::post('/', 'save')->name('save')->middleware('seller_can:products.manage');
        });

        // ── wave 6 · trust ───────────────────────────────────────────────
        /*
        | The standing a seller is being judged against.
        |
        | The platform evaluates every approved seller against SLA policy daily and writes audited
        | breaches, and no client rendered any of it — a marketplace that suspends shops for crossing
        | a line it never showed them is not enforcing a policy, it is springing a trap.
        */
        Route::controller(PerformanceController::class)->prefix('performance')->as('performance.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('health', 'health')->name('health');
            Route::get('sla', 'sla')->name('sla');
        });

        // The badge on this destination has been computed since Wave 1 and the page behind it did
        // not exist, so the platform rendered a number on a menu item pointing at nothing.
        Route::get('compliance', [ComplianceController::class, 'index'])->name('compliance.index');

        // Which brands this shop may sell, and what a revocation would cost it in listings.
        Route::controller(BrandController::class)->prefix('brands')->as('brands.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('protection', 'protection')->name('protection');
        });

        // Issues nobody answered in time. Not a second queue — the record of what escalation
        // promoted, which is what eventually reaches the marketplace.
        Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');

        // Requests of this shop's that are waiting on somebody at the marketplace. Read-only: the
        // approver is by definition not the requester.
        Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index')
            ->middleware('seller_can:staff.manage');

        // ── Wave 7 — Enterprise.

        // Who works in this shop, and what each of them may do. Reading only: the classic staff
        // forms already write roles and people, and two forms writing the same role is how two
        // people end up disagreeing about what `orders.manage` means.
        Route::controller(TeamController::class)->prefix('team')->as('team.')
            ->middleware('seller_can:staff.manage')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('roles', 'roles')->name('roles');
            });

        // Who can act as this shop right now — people and keys alike — and what has been done in
        // its name. Read-only by nature: an access review that could change access is not a review.
        Route::get('security', [SecurityController::class, 'index'])->name('security.index')
            ->middleware('seller_can:staff.manage');

        // Keys and webhooks. These existed only on the phone, which is the wrong device for the
        // work: nobody wires up an ERP on a handset.
        Route::controller(IntegrationController::class)->prefix('integrations')->as('integrations.')
            ->middleware('seller_can:shop_settings.manage')->group(function () {
                Route::get('/', 'index')->name('index');

                Route::get('api', 'api')->name('api');
                Route::post('api', 'storeKey')->name('api.store');
                Route::delete('api/{key}', 'revokeKey')->whereNumber('key')->name('api.revoke');

                Route::get('webhooks', 'webhookList')->name('webhooks');
                Route::post('webhooks', 'storeWebhook')->name('webhooks.store');
                Route::put('webhooks/{webhook}', 'updateWebhook')->whereNumber('webhook')->name('webhooks.update');
                Route::post('webhooks/{webhook}/status', 'setWebhookStatus')->whereNumber('webhook')->name('webhooks.status');
                Route::post('webhooks/{webhook}/test', 'testWebhook')->whereNumber('webhook')->name('webhooks.test');
                Route::delete('webhooks/{webhook}', 'destroyWebhook')->whereNumber('webhook')->name('webhooks.destroy');

                Route::get('health', 'health')->name('health');
            });

        Route::get('search', SearchController::class)->name('search');
        Route::get('help', [HelpController::class, 'index'])->name('help');

        // Wave 1's acceptance screen. Debug-only, and deliberately not in the navigation.
        Route::get('foundation', FoundationController::class)->name('foundation');
    });
});
