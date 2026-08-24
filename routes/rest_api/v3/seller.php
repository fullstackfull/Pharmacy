<?php

use App\Http\Controllers\RestAPI\v3\seller\auth\ForgotPasswordController;
use App\Http\Controllers\RestAPI\v3\seller\auth\LoginController as VendorLoginController;
use App\Http\Controllers\RestAPI\v3\seller\auth\RegisterController;
use App\Http\Controllers\RestAPI\v3\seller\BrandController;
use App\Http\Controllers\RestAPI\v3\seller\ChatController;
use App\Http\Controllers\RestAPI\v3\seller\ClearanceSaleController;
use App\Http\Controllers\RestAPI\v3\seller\CouponController;
use App\Http\Controllers\RestAPI\v3\seller\DeliveryManCashCollectController;
use App\Http\Controllers\RestAPI\v3\seller\DeliveryManController;
use App\Http\Controllers\RestAPI\v3\seller\DeliverymanWithdrawController;
use App\Http\Controllers\RestAPI\v3\seller\EmergencyContactController;
use App\Http\Controllers\RestAPI\v3\seller\OrderController;
use App\Http\Controllers\RestAPI\v3\seller\OrderEditController;
use App\Http\Controllers\RestAPI\v3\seller\POSCartController;
use App\Http\Controllers\RestAPI\v3\seller\POSController;
use App\Http\Controllers\RestAPI\v3\seller\ProductController;
use App\Http\Controllers\RestAPI\v3\seller\RefundController;
use App\Http\Controllers\RestAPI\v3\seller\SellerAnalyticsController;
use App\Http\Controllers\RestAPI\v3\seller\SellerBulkJobController;
use App\Http\Controllers\RestAPI\v3\seller\SellerCenterController;
use App\Http\Controllers\RestAPI\v3\seller\SellerInventoryController;
use App\Http\Controllers\RestAPI\v3\seller\SellerController;
use App\Http\Controllers\RestAPI\v3\seller\SellerPayoutController;
use App\Http\Controllers\RestAPI\v3\seller\SellerActionCenterController;
use App\Http\Controllers\RestAPI\v3\seller\SellerReportController;
use App\Http\Controllers\RestAPI\v3\seller\SellerReturnController;
use App\Http\Controllers\RestAPI\v3\seller\SellerStatementController;
use App\Http\Controllers\RestAPI\v3\seller\SellerVerificationController;
use App\Http\Controllers\RestAPI\v3\seller\shippingController;
use App\Http\Controllers\RestAPI\v3\seller\ShippingMethodController;
use App\Http\Controllers\RestAPI\v3\seller\ShopController;
use App\Http\Controllers\RestAPI\v3\seller\VendorPaymentInfoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Seller Mobile APP API Routes
|--------------------------------------------------------------------------
|*/

Route::group(['namespace' => 'RestAPI\v3\seller', 'prefix' => 'v3/seller', 'middleware' => ['api_lang']], function () {
    // Rate limited: seller login / OTP / password reset are brute-force targets.
    Route::group(['prefix' => 'auth', 'namespace' => 'auth', 'middleware' => ['throttle:20,1']], function () {

        Route::controller(VendorLoginController::class)->group(function () {
            Route::post('login', 'login');
        });
        Route::controller(ForgotPasswordController::class)->group(function () {
            Route::post('forgot-password', 'reset_password_request');
            Route::post('verify-otp', 'otp_verification_submit');
            Route::put('reset-password', 'reset_password_submit');
            Route::post('firebase-auth-token-store', 'firebaseAuthTokenStore');
            Route::post('firebase-auth-verify', 'firebaseAuthVerify');
            Route::post('check-vendor-exist-info', 'checkVendorExistInfo');
        });
    });

    Route::group(['prefix' => 'registration', 'namespace' => 'auth'], function () {
        Route::controller(RegisterController::class)->group(function () {
            Route::post('/', 'store');
        });
    });

    Route::group(['middleware' => ['seller_api_auth']], function () {
        Route::controller(SellerController::class)->group(function () {
            Route::put('language-change', 'language_change');
            Route::get('seller-info', 'getSellerInfo');
            Route::get('get-earning-statitics', 'getEarningStatics');
            Route::get('order-statistics', 'order_statistics');
            // Deleting an account is not a read. GET is kept only so builds already
            // installed keep working, and can go once they are gone; new callers
            // use DELETE, which no prefetcher, retry layer or proxy will replay.
            Route::delete('account-delete', 'account_delete');
            Route::get('account-delete', 'account_delete');
            Route::get('seller-delivery-man', 'seller_delivery_man');
            Route::get('shop-product-reviews', 'shop_product_reviews');
            Route::post('shop-product-reviews-reply', 'shopProductReviewReply');
            Route::get('shop-product-reviews-status', 'shop_product_reviews_status');
            Route::put('seller-update', 'seller_info_update');
            Route::get('monthly-earning', 'monthly_earning');
            Route::get('monthly-commission-given', 'monthly_commission_given');
            Route::put('cm-firebase-token', 'update_cm_firebase_token');
            Route::get('shop-info', 'shop_info');
            Route::get('transactions', 'transaction');
            Route::put('shop-update', 'shop_info_update');
            Route::post('update-setup-guide-app', 'updateSetupGuideApp');
            Route::get('withdraw-method-list', 'withdraw_method_list');
            Route::post('balance-withdraw', 'withdraw_request');
            Route::post('balance-withdraw-update', 'withdraw_request_update');
            Route::delete('close-withdraw-request', 'close_withdraw_request');
        });

        Route::controller(ShopController::class)->group(function () {
            Route::put('vacation-add', 'vacation_add');
            Route::put('temporary-close', 'temporary_close');
        });

        Route::group(['prefix' => 'brands'], function () {
            Route::controller(BrandController::class)->group(function () {
                Route::get('/', 'getBrands');
            });
        });

        Route::controller(ProductController::class)->group(function () {
            Route::get('top-delivery-man', 'top_delivery_man');
            Route::get('categories', 'get_categories');

            Route::group(['prefix' => 'products'], function () {
                Route::get('list', 'getProductList');
                Route::post('upload-images', 'upload_images');
                Route::post('upload-digital-product', 'upload_digital_product');
                Route::post('delete-digital-product', 'deleteDigitalProduct');
                Route::post('add', 'add_new');
                Route::get('details/{id}', 'details');
                Route::get('stock-out-list', 'stock_out_list');
                Route::put('status-update', 'status_update');
                Route::get('edit/{id}', 'edit');
                Route::put('update/{id}', 'updateProduct');
                Route::get('review-list/{id}', 'review_list');
                Route::put('quantity-update', 'updateProductQuantity');
                Route::delete('delete/{id}', 'delete');
                Route::get('barcode/generate', 'barcode_generate');
                Route::get('top-selling-product', 'top_selling_products');
                Route::get('most-popular-product', 'most_popular_products');
                Route::get('delete-image', 'deleteImage');
                Route::get('get-product-images/{id}', 'getProductImages');
                Route::get('stock-limit-status', 'getStockLimitStatus');
                Route::get('delete-preview-file', 'deletePreviewFile');
                Route::get('digital-author-list', 'getDigitalProductsAuthorList');
                Route::get('digital-publishing-house-list', 'getDigitalPublishingHouseList');
                Route::post('restock-request-list', 'getRestockRequestList');
                Route::get('restock-request-delete', 'deleteRestockRequest');
                Route::post('restock-request-stock-update', 'updateRestockQuantity');
                Route::get('restock-request-brands-list', 'getRestockRequestBrands');
            });
        });

        Route::group(['prefix' => 'orders'], function () {
            Route::controller(OrderController::class)->group(function () {
                Route::post('list', 'list');
                // Ahead of the catch-all `/{id}`, which would otherwise swallow it.
                Route::get('{id}/invoice', 'invoice')->whereNumber('id');
                // Declared before the catch-all `{id}` below, which would otherwise swallow it.
                Route::get('{id}/breakdown', 'breakdown')->whereNumber('id');
                Route::get('/{id}', 'details');
                Route::put('order-detail-status/{id}', 'order_detail_status');
                Route::put('assign-delivery-man', 'assign_delivery_man');
                Route::put('order-wise-product-upload', 'digital_file_upload_after_sell');
                Route::put('delivery-charge-date-update', 'amount_date_update');
                Route::post('assign-third-party-delivery', 'assign_third_party_delivery');
                Route::post('update-payment-status', 'update_payment_status');
                Route::post('address-update', 'address_update');
                Route::post('order-detail-info-update', 'updateOrderDetails');
            });

            Route::controller(OrderEditController::class)->group(function () {
                Route::post('edit-order-submit', 'submitEditOrder');
                Route::post('edit-order-validation', 'checkEditOrderValidation');
                Route::post('assign-order-in-cod', 'assignOrderInCOD');
            });
        });

        Route::group(['prefix' => 'clearance-sale'], function () {
            Route::controller(ClearanceSaleController::class)->group(function () {
                Route::get('product-list', 'list');
                Route::post('product-add', 'addClearanceProduct');
                Route::post('product-delete', 'deleteClearanceProduct');
                Route::post('all-product-delete', 'deleteAllClearanceProduct');
                Route::post('product-status-update', 'updateClearanceProductStatus');
                Route::post('product-discount-update', 'updateClearanceProductDiscount');
                Route::post('config-status-update', 'updateClearanceConfigStatus');
                Route::get('config-data', 'getConfigData');
                Route::post('config-data-update', 'updateConfigData');
            });
        });

        Route::group(['prefix' => 'refund'], function () {
            Route::controller(RefundController::class)->group(function () {
                Route::get('list', 'list');
                Route::get('single-item', 'getSingleItem');
                Route::get('refund-details', 'refund_details');
                Route::post('refund-status-update', 'refund_status_update');
            });
        });

        Route::group(['prefix' => 'coupon'], function () {
            Route::controller(CouponController::class)->group(function () {
                Route::get('list', 'list');
                Route::post('store', 'store');
                Route::put('update/{id}', 'update');
                Route::put('status-update/{id}', 'status_update');
                Route::delete('delete/{id}', 'delete');
                Route::post('check-coupon', 'check_coupon');
                Route::get('customers', 'customers');
            });
        });

        Route::group(['prefix' => 'shipping'], function () {
            Route::controller(shippingController::class)->group(function () {
                Route::get('get-shipping-method', 'get_shipping_type');
                Route::get('selected-shipping-method', 'selected_shipping_type');
                Route::get('all-category-cost', 'all_category_cost');
                Route::post('set-category-cost', 'set_category_cost');
            });
        });

        Route::group(['prefix' => 'shipping-method'], function () {
            Route::controller(ShippingMethodController::class)->group(function () {
                Route::get('list', 'list');
                Route::post('add', 'store');
                Route::get('edit/{id}', 'edit');
                Route::put('status', 'status_update');
                Route::put('update/{id}', 'update');
                Route::delete('delete/{id}', 'delete');
            });
        });

        Route::group(['prefix' => 'messages'], function () {
            Route::controller(ChatController::class)->group(function () {
                Route::get('list/{type}', 'list');
                Route::get('get-message/{type}/{id}', 'get_message');
                Route::post('send/{type}', 'send_message');
                Route::post('seen/{type}', 'seenMessage');
                Route::get('search/{type}', 'search');
            });
        });

        Route::group(['prefix' => 'pos'], function () {
            Route::controller(POSController::class)->group(function () {
                Route::get('get-categories', 'get_categories');
                Route::get('customers', 'customers');
                Route::post('customer-store', 'customer_store');
                Route::get('products', 'get_product_by_barcode');
                Route::get('product-list', 'product_list');
                Route::post('place-order', 'place_order');
                Route::get('get-invoice', 'get_invoice');
            });

            Route::controller(POSCartController::class)->group(function () {
                Route::post('get-tax-amount', 'getTaxAmountCart');
            });
        });

        Route::group(['prefix' => 'delivery-man'], function () {
            Route::controller(DeliveryManController::class)->group(function () {
                Route::get('list', 'list');
                Route::post('store', 'store');
                Route::put('update/{id}', 'update');
                Route::get('details/{id}', 'details');
                Route::post('status-update', 'status');
                Route::get('delete/{id}', 'delete');
                Route::get('reviews/{id}', 'reviews');
                Route::get('order-list/{id}', 'order_list');
                Route::get('order-status-history/{id}', 'order_status_history');
                Route::get('earning/{id}', 'earning');
            });

            Route::controller(DeliveryManCashCollectController::class)->group(function () {
                Route::post('cash-receive', 'cash_receive');
                Route::get('collect-cash-list/{id}', 'list');
            });

            Route::group(['prefix' => 'withdraw'], function () {
                Route::controller(DeliverymanWithdrawController::class)->group(function () {
                    Route::get('list', 'list');
                    Route::get('details/{id}', 'details');
                    Route::put('status-update', 'status_update');
                });
            });

            Route::group(['prefix' => 'emergency-contact'], function () {
                Route::controller(EmergencyContactController::class)->group(function () {
                    Route::get('list', 'list');
                    Route::post('store', 'store');
                    Route::put('update', 'update');
                    Route::put('status-update', 'status_update');
                    Route::delete('delete', 'destroy');
                });
            });
        });

        Route::group(['prefix' => 'notification'], function () {
            Route::controller(ShopController::class)->group(function () {
                Route::get('/', 'notification_index');
                Route::get('/view', 'seller_notification_view');
            });
        });

        Route::group(['prefix' => 'payment-information', 'as' => 'payment-information.'], function () {
            Route::controller(VendorPaymentInfoController::class)->group(function () {
                Route::get('list', 'index');
                Route::get('withdrawal-method-list', 'getWithdrawalMethods');
                Route::post('add', 'add');
                Route::post('update', 'update');
                Route::post('default', 'updateDefault');
                Route::post('status', 'updateStatus');
                Route::get('delete', 'delete');
            });
        });

        /*
         * Seller Center (marketplace suite) — the same services the web hub uses,
         * exposed to the mobile app. Identity always from the auth token's seller.
         */
        Route::group(['prefix' => 'seller-center'], function () {
            Route::controller(SellerCenterController::class)->group(function () {
                Route::get('overview', 'overview');
                Route::get('scorecard', 'scorecard');
            });

            Route::group(['prefix' => 'verification'], function () {
                Route::controller(SellerVerificationController::class)->group(function () {
                    Route::get('/', 'index');
                    Route::post('submit', 'submit');
                    Route::get('document/{id}', 'document')->whereNumber('id');
                });
            });

            Route::group(['prefix' => 'analytics'], function () {
                Route::controller(SellerAnalyticsController::class)->group(function () {
                    Route::get('/', 'index');
                    Route::get('activities', 'activities');
                });
            });

            // Everything waiting for the seller, from the one insight store — so Home, this list
            // and notifications cannot disagree about what needs attention.
            Route::group(['prefix' => 'action-center'], function () {
                Route::controller(SellerActionCenterController::class)->group(function () {
                    Route::get('/', 'index');
                    Route::post('{id}/dismiss', 'dismiss')->whereNumber('id');
                });
            });

            // The panel's report pages, as data. Every figure comes from SellerReportService, which
            // the vendor controllers read too, so the app and the panel cannot drift apart.
            Route::group(['prefix' => 'reports'], function () {
                Route::controller(SellerReportController::class)->group(function () {
                    Route::get('orders', 'orders');
                    Route::get('orders/export', 'exportOrders');
                    Route::get('orders/export-pdf', 'exportOrdersPdf');
                    Route::get('products', 'products');
                    Route::get('products/export', 'exportProducts');
                    Route::get('stock', 'stock');
                    Route::get('stock/export', 'exportStock');
                });
            });

            // The only place a seller's money leaves the platform, so reading and moving are two
            // different permissions: a finance clerk can be given the books without being given the
            // ability to withdraw from them.
            Route::group(['prefix' => 'payouts'], function () {
                Route::controller(SellerPayoutController::class)->group(function () {
                    Route::get('/', 'index')->middleware('seller_can:finance.view');
                    Route::post('/', 'store')->middleware('seller_can:payouts.request');
                    Route::post('{id}/cancel', 'cancel')->whereNumber('id')->middleware('seller_can:payouts.request');
                });
            });

            // The account, line by line. Reading the books is a separate permission from moving
            // money out of them, and this is squarely the first.
            Route::group(['prefix' => 'statement', 'middleware' => 'seller_can:finance.view'], function () {
                Route::controller(SellerStatementController::class)->group(function () {
                    Route::get('/', 'index');
                    Route::get('export', 'export');
                });
            });

            // The goods coming back. Reading is open to anyone who can open the app; deciding what
            // happens to a return is the same permission as working an order.
            Route::group(['prefix' => 'returns'], function () {
                Route::controller(SellerReturnController::class)->group(function () {
                    Route::get('/', 'index');
                    Route::get('{id}', 'show')->whereNumber('id');
                    Route::post('{id}/in-transit', 'markInTransit')->whereNumber('id')->middleware('seller_can:orders.manage');
                    Route::post('{id}/receive', 'receive')->whereNumber('id')->middleware('seller_can:orders.manage');
                    Route::post('{id}/reject', 'reject')->whereNumber('id')->middleware('seller_can:orders.manage');
                });
            });

            // The stock ledger the marketplace has kept since Phase 3, which no seller has ever been
            // able to see. Reading is open to anyone who can open the app; changing a balance needs
            // the same permission as changing it one product at a time.
            Route::group(['prefix' => 'inventory'], function () {
                Route::controller(SellerInventoryController::class)->group(function () {
                    Route::get('overview', 'overview');
                    Route::get('movements', 'movements');
                    Route::get('warehouses', 'warehouses');
                    Route::get('batches', 'batches');
                    Route::post('products/{id}/adjust', 'adjust')
                        ->whereNumber('id')->middleware('seller_can:inventory.manage');
                });
            });

            // Bulk changes are gated by what they change, not by the fact that they are bulk: a
            // warehouse clerk who may set stock one product at a time may set it for four hundred,
            // and still may not touch a price.
            Route::group(['prefix' => 'bulk-jobs'], function () {
                Route::controller(SellerBulkJobController::class)->group(function () {
                    Route::get('/', 'index');
                    Route::get('{id}', 'show')->whereNumber('id');
                    Route::get('{id}/failures', 'downloadFailures')->whereNumber('id');
                    Route::post('price', 'storePriceUpdate')->middleware('seller_can:products.manage');
                    Route::post('stock', 'storeStockUpdate')->middleware('seller_can:inventory.manage');
                });
            });
        });

    });

    /*
     * These two were declared AFTER the seller_api_auth group closed, so they inherited no
     * authentication: any anonymous caller could iterate {seller_id} and dump every vendor's full
     * catalogue (including unpublished and out-of-stock items). Moved under seller_api_auth.
     * The controller must still verify the token's seller owns {seller_id} — see the ownership
     * check added in getVendorAllProducts().
     */
    Route::group(['middleware' => ['seller_api_auth']], function () {
        Route::controller(ProductController::class)->group(function () {
            Route::group(['prefix' => 'products'], function () {
                Route::get('{seller_id}/all-products', 'getVendorAllProducts');
                Route::get('{seller_id}/edit-order-all-products', 'editOrderVendorAllProducts');
            });
        });
    });
});

