<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Point every section at its page row, without taking the slug away.
 *
 * Both columns are kept on purpose, and it is worth saying why rather than tidying one away: the
 * slug is what every renderer, every cache key, every API request and every installed app already
 * speaks, and a section whose page row is missing must still render. So `page` stays the truth for
 * reading, and `experience_page_id` is the truth for editing — the id is what lets a page be
 * renamed, disabled or duplicated without touching a single section.
 *
 * A section with a null id is not broken; it is a section that predates this migration or arrived
 * through an import, and it resolves by slug exactly as before.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('theme_sections')) {
            return;
        }

        if (!Schema::hasColumn('theme_sections', 'experience_page_id')) {
            Schema::table('theme_sections', function (Blueprint $table) {
                $table->unsignedBigInteger('experience_page_id')->nullable()->after('theme_version_id');
                $table->index(['experience_page_id', 'sort_order']);
            });
        }

        $this->backfill();
    }

    /**
     * Match each section to the page row for its version's theme and its own slug.
     *
     * Done as one update per (theme, slug) pair rather than row by row: a shop with a long history
     * of drafts can hold thousands of sections, and this runs inside a deploy window.
     */
    private function backfill(): void
    {
        if (!Schema::hasTable('experience_pages') || !Schema::hasTable('theme_versions')) {
            return;
        }

        foreach (DB::table('experience_pages')->get() as $page) {
            DB::table('theme_sections')
                ->whereNull('experience_page_id')
                ->where('page', $page->slug)
                ->whereIn('theme_version_id', DB::table('theme_versions')
                    ->where('theme_id', $page->theme_id)
                    ->pluck('id'))
                ->update(['experience_page_id' => $page->id]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('theme_sections', 'experience_page_id')) {
            Schema::table('theme_sections', function (Blueprint $table) {
                $table->dropIndex(['experience_page_id', 'sort_order']);
                $table->dropColumn('experience_page_id');
            });
        }
    }
};
