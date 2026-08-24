<?php

namespace App\Providers;

use App\Models\Product;
use App\Observers\ProductPriceObserver;
use App\Observers\ProductSearchIndexObserver;
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
        Product::observe([ProductSearchIndexObserver::class, ProductPriceObserver::class]);
    }
}
