<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Syria — synced governorate rate table (optional courier integration).
 *
 * Delivery Syria prices a parcel as "total order weight (KG) × the per-kilo base cost of the
 * destination governorate". That per-governorate price list is not something the store owns — it is
 * pulled from the courier's own account when the admin verifies the Secret (POST /api/secret/cheack).
 * This table is where that pulled price list is cached so the store can show a delivery-cost estimate
 * without calling the courier on every page load, and so a re-sync updates an existing governorate row
 * in place rather than creating duplicates (keyed by the courier's own `code`).
 *
 * Purely additive and reversible: a new table only, guarded, with a working down(). Nothing here is
 * consulted unless the admin has enabled and verified the Delivery Syria integration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delivery_syria_governorate_rates')) {
            return;
        }

        Schema::create('delivery_syria_governorate_rates', function (Blueprint $table) {
            $table->id();

            // The courier's own identifiers for the destination. `code` is the stable key used to match
            // a governorate on re-sync (its name may be re-spelled); it is unique so a re-sync upserts.
            $table->unsignedBigInteger('code')->unique();
            $table->string('governorate', 191);

            // Price per 1 KG to this governorate, as returned by the courier. Same precision the
            // platform's own shipping_zones table uses, so estimates line up.
            $table->decimal('base_cost', 24, 2)->default(0);

            // When this row was last refreshed from the courier — shown to the admin so a stale price
            // list is obvious.
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_syria_governorate_rates');
    }
};
