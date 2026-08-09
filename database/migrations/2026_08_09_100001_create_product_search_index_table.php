<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Normalised search index for products (Phase 2.1).
 *
 * Why a separate table rather than a column on `products`:
 *
 *  - A product has one row PER LOCALE. The catalogue is bilingual — every product carries an Arabic
 *    name in `translations` alongside its Latin one — and a single column could hold only one.
 *  - `products` is the hottest table in the application and is read by the Flutter apps through
 *    several API versions. Leaving it untouched means nothing that reads it can be affected.
 *  - The index is derived data. It can be dropped and rebuilt from source at any time, which is not
 *    true of a column that other code might start writing to.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_search_index')) {
            return;
        }

        Schema::create('product_search_index', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('locale', 20)->default('default');

            // Name kept separate from the rest so a name match can outrank a description match.
            // Both are already normalised — folded, lowercased, punctuation collapsed.
            $table->string('name_normalized', 512)->default('');
            $table->text('text_normalized')->nullable();

            $table->timestamps();

            // One row per product per locale; also the lookup path when reindexing a single product.
            $table->unique(['product_id', 'locale'], 'psi_product_locale_unique');
            $table->index('locale', 'psi_locale_index');

            // 191 chars keeps the key inside the 767-byte limit on older utf8mb4 setups. A prefix
            // index is enough: it serves the "starts with" tier, which is the one that has to be
            // fast. The contains tier scans, and scans a table far narrower than `products`.
            $table->index([DB::raw('name_normalized(191)')], 'psi_name_prefix_index');

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_search_index');
    }
};
