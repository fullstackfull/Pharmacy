<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 3.2 — Merchandising. One nullable column on the 3.1 table; NULL means "no merchandising",
 * which is exactly how every collection behaved before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_collections')
            && !Schema::hasColumn('product_collections', 'merchandising')) {
            Schema::table('product_collections', function (Blueprint $table) {
                // Validated by MerchandisingRules before saving: pins, exclusions, boosts,
                // min_items, automatic replacement, and the fallback choice.
                $table->json('merchandising')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_collections')
            && Schema::hasColumn('product_collections', 'merchandising')) {
            Schema::table('product_collections', function (Blueprint $table) {
                $table->dropColumn('merchandising');
            });
        }
    }
};
