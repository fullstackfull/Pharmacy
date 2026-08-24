<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 3.1 — Dynamic Collections.
 *
 * Two NEW tables; nothing existing is touched, so rollback is dropping them.
 *
 * - product_collections: a named, reusable answer to "which products" — allowlisted rules, a
 *   ranking, and (from 3.2) merchandising. Referenced by sections through their existing
 *   settings bag, so no theme table changes either.
 * - product_metrics: one precomputed row per product (sales/views/cart-adds over 30 days,
 *   rating, wishlist count), rebuilt by `commerce:metrics-refresh` on the scheduler. Collections
 *   rank against this table so a Home request never aggregates order or analytics tables
 *   (§23); losing it costs freshness, never correctness.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_collections')) {
            Schema::create('product_collections', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 60)->unique();
                $table->boolean('status')->default(true);
                // Allowlisted [{field, operator, value}] rows, AND-combined. Validated by
                // CollectionRuleRegistry before saving; never raw SQL, never arbitrary columns.
                $table->json('rules')->nullable();
                $table->string('sort_by', 40)->default('sales_30d');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('product_metrics')) {
            Schema::create('product_metrics', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->unique();
                $table->unsignedBigInteger('sales_30d')->default(0);
                $table->unsignedBigInteger('views_30d')->default(0);
                $table->unsignedBigInteger('carted_30d')->default(0);
                $table->decimal('rating', 4, 2)->default(0);
                $table->unsignedBigInteger('wishlist_count')->default(0);
                $table->timestamp('computed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_metrics');
        Schema::dropIfExists('product_collections');
    }
};
