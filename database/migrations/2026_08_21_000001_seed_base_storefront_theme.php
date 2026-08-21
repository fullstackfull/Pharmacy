<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds "الثيم الأساسي" — a system theme whose draft mirrors the hardcoded default home, section by
 * section, so merchants edit the real storefront in the Theme editor and build on it instead of
 * starting from an empty canvas. Left as a DRAFT and NOT activated: publishing/activating stays an
 * explicit merchant action, and until then the storefront keeps rendering the hardcoded home.
 */
return new class extends Migration
{
    private const SLUG = 'base-storefront';

    public function up(): void
    {
        if (!Schema::hasTable('themes') || DB::table('themes')->where('slug', self::SLUG)->exists()) {
            return;
        }

        $now = now();

        $themeId = DB::table('themes')->insertGetId([
            'name'        => 'الثيم الأساسي',
            'slug'        => self::SLUG,
            'description' => 'نسخة قابلة للتعديل من الواجهة الحالية للمتجر — عدّل عليها وابنِ على أساسها.',
            'is_active'   => false,
            'is_system'   => true,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $versionId = DB::table('theme_versions')->insertGetId([
            'theme_id'   => $themeId,
            'label'      => 'v1',
            'status'     => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // The hardcoded default home, in its on-page order. Flash deals stay legacy-only for now
        // (the builder has no renderer for that section type yet).
        $sections = [
            ['store_banner',   ['banner_type' => 'Main Banner', 'layout' => 'carousel', 'limit' => 6]],
            ['product_slider', ['source' => 'featured', 'title' => 'منتجات مميزة', 'limit' => 10]],
            ['category_grid',  ['title' => 'تسوق حسب الفئة', 'limit' => 12]],
            ['store_banner',   ['banner_type' => 'Home Promo Banner', 'layout' => 'grid', 'limit' => 6]],
            ['store_banner',   ['banner_type' => 'Main Section Banner', 'layout' => 'strip', 'limit' => 1]],
            ['product_slider', ['source' => 'new_arrival', 'title' => 'وصل حديثاً', 'limit' => 10]],
            ['store_banner',   ['banner_type' => 'Footer Banner', 'layout' => 'carousel', 'limit' => 6]],
            ['brand_slider',   ['title' => 'الماركات', 'limit' => 15]],
        ];

        foreach ($sections as $index => [$type, $settings]) {
            DB::table('theme_sections')->insert([
                'theme_version_id' => $versionId,
                'page'             => 'home',
                'type'             => $type,
                'sort_order'       => $index + 1,
                'is_visible'       => true,
                'settings'         => json_encode($settings),
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('themes')) {
            return;
        }
        // theme_versions/sections cascade from the theme row.
        DB::table('themes')->where('slug', self::SLUG)->where('is_system', true)->delete();
    }
};
