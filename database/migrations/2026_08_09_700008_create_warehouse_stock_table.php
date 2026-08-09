<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-warehouse stock allocation (Phase 3, Stage C).
 *
 * One row per (warehouse, product): how much of a product's stock sits in that location. The sum of a
 * product's rows here is what has been *allocated* to locations; `current_stock` minus that sum is the
 * still-unallocated remainder. Allocation and transfers move quantity between these rows without
 * changing `current_stock`, so the sellable total is preserved while its physical distribution becomes
 * visible.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('warehouse_stock')) {
            return;
        }

        Schema::create('warehouse_stock', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id'], 'ws_wh_product_unique');
            $table->index('product_id', 'ws_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stock');
    }
};
