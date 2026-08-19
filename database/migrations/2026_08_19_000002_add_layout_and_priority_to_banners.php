<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Layout and ordering for the banners that render as a grid (home promos and
 * category sections): `layout` says how wide a banner sits — full row, half
 * row beside its neighbour, or pooled into a rotating slider — and `priority`
 * orders them. Existing banners default to a full-width row in creation order,
 * which is what every current banner slot already does.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            if (!Schema::hasColumn('banners', 'layout')) {
                $table->string('layout', 20)->default('full')->after('banner_type');
            }
            if (!Schema::hasColumn('banners', 'priority')) {
                $table->integer('priority')->default(0)->after('layout');
            }
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            foreach (['layout', 'priority'] as $column) {
                if (Schema::hasColumn('banners', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
