<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Frequently bought together": the products a merchant hand-picks as companions for this one.
 *
 * Stored as a comma-separated id list on the product itself, the way this schema already stores
 * `category_ids` and `colors` — no new table, nothing to join, and a product with no picks simply
 * carries null and falls back to what customers actually buy together.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('products', 'bought_together_ids')) {
            Schema::table('products', function (Blueprint $table) {
                $table->text('bought_together_ids')->nullable()->after('category_ids');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'bought_together_ids')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('bought_together_ids');
            });
        }
    }
};
