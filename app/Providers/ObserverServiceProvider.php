<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\Product;
use App\Models\RefundRequest;
use App\Models\VendorPayoutRequest;
use App\Observers\AutomationClaimObserver;
use App\Observers\ProductPriceObserver;
use App\Observers\ProductSearchIndexObserver;
use App\Observers\SellerWebhookEventObserver;
use Illuminate\Support\ServiceProvider;

class ObserverServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Keeps the normalised search index in step with the catalogue however a product is written
        // — admin panel, vendor panel, three API versions or the bulk importer. The observer
        // swallows its own failures, so an index write can never fail a product save.
        // Records every price change, whoever made it. Same reasoning as the index observer and
        // more pressing: a price moved at seven call sites in one service alone, and none of them
        // wrote down what it had been.
        // And releases automation's claim on a listing the moment anybody else changes whether it
        // is visible, so a rule cannot put back something the seller took down on purpose.
        Product::observe([
            ProductSearchIndexObserver::class,
            ProductPriceObserver::class,
            SellerWebhookEventObserver::class,
            AutomationClaimObserver::class,
        ]);

        // A seller's webhooks are raised from the model rather than from the call sites, for the
        // same reason the price history is: an order's status is written from the admin panel, the
        // vendor panel, three API versions, the delivery partner's callback and a queued job, and
        // the next writer will be added by somebody who never heard of webhooks.
        Order::observe(SellerWebhookEventObserver::class);
        RefundRequest::observe(SellerWebhookEventObserver::class);
        VendorPayoutRequest::observe(SellerWebhookEventObserver::class);
    }
}
