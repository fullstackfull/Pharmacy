<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warehouses / stock locations (Phase 3, Stage C).
 *
 * Measured: the platform has no notion of *where* stock physically sits — a product carries one
 * `current_stock` number and nothing more. A multi-location operation cannot answer "how much of this
 * is in the Damascus warehouse vs Aleppo?".
 *
 * This adds a location registry. It is deliberately a layer *over* `current_stock`, not a replacement:
 * `current_stock` stays the single source of truth for sellable quantity, and warehouse allocation
 * partitions that same quantity across locations (with an "unallocated" remainder). So adopting this
 * changes no selling behaviour — it adds visibility and inter-location transfers, without touching the
 * order path that decrements sellable stock.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('warehouses')) {
            return;
        }

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();   // null = platform warehouse
            $table->string('name', 191);
            $table->string('code', 40)->nullable();
            $table->string('address', 512)->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');   // active | inactive
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status'], 'wh_seller_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
