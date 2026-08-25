<?php

use App\Http\Controllers\Seller\ControlTowerController;
use App\Http\Controllers\Seller\FoundationController;
use App\Http\Controllers\Seller\HomeController;
use App\Http\Controllers\Seller\InventoryController;
use App\Http\Controllers\Seller\IssueController;
use App\Http\Controllers\Seller\OrderController;
use App\Http\Controllers\Seller\HelpController;
use App\Http\Controllers\Seller\PreferencesController;
use App\Http\Controllers\Seller\ProductController;
use App\Http\Controllers\Seller\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Seller Center
|--------------------------------------------------------------------------
|
| The seller's operations surface (design handoff 01, route prefix `/seller`).
|
| Two things are true of every route below and are enforced here rather than remembered per
| controller: the request carries a `SellerPrincipal` resolved from the session, and any route
| touching a permissioned domain declares that permission with `seller_can:` — the same middleware
| the seller app's API uses, reading the same keys. A screen's menu item disappearing is a courtesy;
| this is the enforcement.
|
| The classic vendor panel at `/vendor` is untouched. Destinations that have not been rebuilt yet
| are linked into it from the navigation registry, so no capability the seller has today is lost
| while the waves land.
*/

Route::group(['middleware' => ['maintenance_mode', 'actch:admin_panel']], function () {
    Route::group([
        'prefix' => 'seller',
        'as' => 'seller.',
        'middleware' => ['seller', 'seller_staff_access', 'seller_center'],
    ], function () {

        // ── shell preferences ────────────────────────────────────────────
        Route::controller(PreferencesController::class)->prefix('preferences')->as('preferences.')->group(function () {
            Route::get('density', 'density')->name('density');
            Route::get('direction', 'direction')->name('direction');
        });

        // ── wave 2 · core seller operations ──────────────────────────────
        Route::get('/', HomeController::class)->name('home');
        Route::get('home', HomeController::class);
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

        Route::get('search', SearchController::class)->name('search');
        Route::get('help', [HelpController::class, 'index'])->name('help');

        // Wave 1's acceptance screen. Debug-only, and deliberately not in the navigation.
        Route::get('foundation', FoundationController::class)->name('foundation');
    });
});
