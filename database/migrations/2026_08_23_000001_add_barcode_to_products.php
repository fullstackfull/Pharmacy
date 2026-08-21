<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The printed barcode a product already carries on its box (EAN-13, UPC, whatever the supplier
 * used), beside the store's own SKU in `code`.
 *
 * They are not the same number: the SKU is generated here and is unique by rule, while the barcode
 * comes from outside and is what a scanner reads at the counter. Additive and nullable, so every
 * existing product keeps working with nothing filled in.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('products', 'barcode')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode', 64)->nullable()->after('code')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('products', 'barcode')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['barcode']);
            $table->dropColumn('barcode');
        });
    }
};
