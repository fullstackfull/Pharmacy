<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Destination-based shipping zones (Phase 3, Stage C).
 *
 * Measured: the platform's shipping is flat or category-based — `shipping_methods.cost` is one number,
 * `category_shipping_costs` is per-category, and nothing is aware of *where* the parcel is going or how
 * much it weighs. A real logistics operation prices by destination and weight; there is no way to say
 * "coastal governorates cost 5, the interior 8, free over 100."
 *
 * This adds a destination rate layer: a zone matches a set of countries (and optionally regions), and
 * carries a rate rule — a flat base, a per-kg component, and an optional free-over-value threshold. It
 * is **additive and non-breaking**: the resolver returns null when no zone matches, so any checkout
 * that has not adopted it falls straight back to the existing flat shipping. One rate rule per zone
 * keeps this bounded; multiple named methods per zone (standard/express) is a later refinement.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shipping_zones')) {
            return;
        }

        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);

            // Destination match: JSON lists of country names/codes and optional regions. A zone with
            // an empty countries list is treated as a catch-all (lowest priority) — the "rest of world".
            $table->json('countries')->nullable();
            $table->json('regions')->nullable();

            // The rate rule.
            $table->decimal('base_cost', 24, 2)->default(0);
            $table->decimal('per_kg_cost', 24, 2)->default(0);
            $table->decimal('free_over', 24, 2)->nullable();   // order value at/above which shipping is free

            // Lower number wins when several zones match a destination — a specific zone beats a catch-all.
            $table->unsignedInteger('priority')->default(10);
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority'], 'sz_status_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_zones');
    }
};
