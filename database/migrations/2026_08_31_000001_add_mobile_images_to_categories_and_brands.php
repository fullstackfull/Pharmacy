<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The same second, phone-shaped image banners already have, for categories and brands.
 *
 * A category icon drawn at 62px in a round tile and the same file on a wide web page are not the
 * same picture: what reads on the storefront is often unreadable once it is a circle on a phone.
 * The merchant can now upload one for each, and — exactly as with `banners.mobile_photo` — leaving
 * it empty is the normal case: every existing category and brand keeps working untouched because
 * the apps fall back to the web image.
 *
 * Additive and guarded, so it is safe to run on a database that already has the columns.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('categories', 'mobile_icon')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->string('mobile_icon')->nullable()->after('icon');
            });
        }

        if (!Schema::hasColumn('brands', 'mobile_image')) {
            Schema::table('brands', function (Blueprint $table) {
                $table->string('mobile_image')->nullable()->after('image');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('categories', 'mobile_icon')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('mobile_icon');
            });
        }

        if (Schema::hasColumn('brands', 'mobile_image')) {
            Schema::table('brands', function (Blueprint $table) {
                $table->dropColumn('mobile_image');
            });
        }
    }
};
