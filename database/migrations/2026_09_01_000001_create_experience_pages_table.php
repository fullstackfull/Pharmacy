<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A page becomes a thing, instead of a string.
 *
 * Until now "which page" was `theme_sections.page` — a free varchar holding one of three words.
 * That is enough to place a section and not enough for anything a merchant will ask for next: a
 * page cannot be created, named, disabled, duplicated or scheduled, because there is no row to
 * carry any of it. An Offers page or a Ramadan landing page has nowhere to exist.
 *
 * The column stays. Every reader still resolves by slug, and this table is what a slug now points
 * at. That is deliberate: the migration below can run on a live shop with published sections and
 * change nothing about what renders — it only gives the three existing pages an identity.
 *
 * `channel` is on the page rather than on the section because a page belongs to one surface: the
 * web's home and the customer app's home are the same page today, so both are seeded as `shared`,
 * and a channel-specific page (a vendor-app dashboard) is a different row rather than a flag.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('experience_pages')) {
            Schema::create('experience_pages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('theme_id');
                // 'shared' until a page is built for one surface only; then 'web' / 'customer_app'.
                $table->string('channel', 40)->default('shared');
                $table->string('slug', 60);
                $table->string('title')->nullable();
                // system pages ship with the engine and cannot be deleted; custom ones are the
                // merchant's own and can be.
                $table->string('kind', 20)->default('custom');
                $table->boolean('is_enabled')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['theme_id', 'channel', 'slug']);
                $table->index(['theme_id', 'is_enabled']);
            });
        }

        $this->seedSystemPages();
    }

    /**
     * Give every existing theme the three pages it already renders.
     *
     * Read from the sections themselves rather than assumed, so a shop that somehow has sections on
     * a fourth page keeps them addressable instead of losing them to a hardcoded list.
     */
    private function seedSystemPages(): void
    {
        if (!Schema::hasTable('themes') || !Schema::hasTable('theme_sections')) {
            return;
        }

        $system = ['home' => 'Home', 'header' => 'Header', 'footer' => 'Footer'];

        $slugs = DB::table('theme_sections')
            ->select('page')
            ->distinct()
            ->pluck('page')
            ->filter(fn ($page) => is_string($page) && $page !== '')
            ->all();

        $slugs = array_values(array_unique([...array_keys($system), ...$slugs]));

        foreach (DB::table('themes')->pluck('id') as $themeId) {
            foreach ($slugs as $order => $slug) {
                $exists = DB::table('experience_pages')
                    ->where('theme_id', $themeId)
                    ->where('channel', 'shared')
                    ->where('slug', $slug)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('experience_pages')->insert([
                    'theme_id'   => $themeId,
                    'channel'    => 'shared',
                    'slug'       => $slug,
                    'title'      => $system[$slug] ?? ucfirst(str_replace(['-', '_'], ' ', $slug)),
                    'kind'       => isset($system[$slug]) ? 'system' : 'custom',
                    'is_enabled' => true,
                    'sort_order' => $order,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_pages');
    }
};
