<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase-order lines (Phase 3, Stage C).
 *
 * One row per ordered item. `product_id` links to a catalogue product when the line replenishes one
 * (so receiving can increment its stock); it is nullable so a purchase can also cover something not
 * yet in the catalogue, captured by description/sku. `qty_received` tracks partial receipts against
 * `qty_ordered` — the pair is what drives the header's derived status and prevents over-receiving.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_order_items')) {
            return;
        }

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('product_id')->nullable();

            $table->string('description', 255)->nullable();
            $table->string('sku', 120)->nullable();

            $table->unsignedInteger('qty_ordered')->default(0);
            $table->unsignedInteger('qty_received')->default(0);
            $table->decimal('unit_cost', 24, 2)->default(0);
            $table->decimal('line_total', 24, 2)->default(0);

            $table->timestamps();

            $table->index('purchase_order_id', 'poi_po_idx');
            $table->index('product_id', 'poi_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
