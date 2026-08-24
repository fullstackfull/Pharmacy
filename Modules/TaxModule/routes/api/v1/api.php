<?php

use Illuminate\Support\Facades\Route;
use Modules\TaxModule\app\Http\Controllers\Api\V1\TaxController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Localised like every other API route the apps call. Without this the `lang`
// header the clients send is ignored here alone, and tax names come back in the
// panel's default language while the rest of the screen is in the seller's.
Route::group(['prefix' => 'v1', 'as' => 'v1.', 'middleware' => ['api_lang']], function () {
    Route::group(['prefix' => 'vat-tax', 'as' => 'vat-tax.'], function () {
        Route::get('get-taxVat-list', [TaxController::class, 'getTaxVatList']);
        Route::post('get-calculated-tax', [TaxController::class, 'getCalculateTax']);
    });
});
