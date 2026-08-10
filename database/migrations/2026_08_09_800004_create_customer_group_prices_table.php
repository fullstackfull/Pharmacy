<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-group product pricing (Phase 3, Stage E).
 *
 * One row overrides one product's price for one group — either a fixed `price` or a `discount_percent`
 * off the base. A group with no row for a product falls to its `default_discount_percent`; a customer
 * in no group sees the base price. Nothing here writes to the product's own price.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_group_prices')) {
            return;
        }

        Schema::create('customer_group_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_group_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('price', 24, 2)->nullable();               // fixed override
            $table->decimal('discount_percent', 6, 2)->nullable();     // or a percentage off base
            $table->timestamps();

            $table->unique(['customer_group_id', 'product_id'], 'cgp_group_product_unique');
            $table->index('product_id', 'cgp_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_group_prices');
    }
};
