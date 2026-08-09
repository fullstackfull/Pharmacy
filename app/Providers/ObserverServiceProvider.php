<?php

namespace App\Providers;

use App\Models\Product;
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
        Product::observe([ProductSearchIndexObserver::class]);
    }
}
