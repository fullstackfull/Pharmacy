<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three questions a section could not answer before: who is it for, when does it run, and is
 * it still the same section after a version is duplicated.
 *
 * Everything here is nullable and defaults to "no restriction", so every section that already
 * exists keeps behaving exactly as it does today — the storefront cannot notice this migration
 * until a merchant actually sets a rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('theme_sections')) {
            return;
        }

        Schema::table('theme_sections', function (Blueprint $table) {
            // Identity that survives duplication. A version copy mints new row ids, so a client
            // that cached "section 41 is collapsed" loses that the moment a draft is published.
            // The uuid travels with the copy, which is what makes per-section client state and
            // per-section telemetry meaningful across publishes.
            if (!Schema::hasColumn('theme_sections', 'uuid')) {
                $table->uuid('uuid')->nullable()->after('id');
            }

            // Scheduling, decided on the server. A campaign that ends at midnight must end at the
            // shop's midnight, not on whatever a phone believes the time is.
            if (!Schema::hasColumn('theme_sections', 'starts_at')) {
                $table->timestamp('starts_at')->nullable()->after('is_visible');
            }
            if (!Schema::hasColumn('theme_sections', 'ends_at')) {
                $table->timestamp('ends_at')->nullable()->after('starts_at');
            }

            // Where it may appear: a subset of web / app, and of desktop / tablet / mobile.
            // NULL means everywhere, which is what every existing section means today.
            if (!Schema::hasColumn('theme_sections', 'platforms')) {
                $table->json('platforms')->nullable()->after('ends_at');
            }

            // Who may see it: guest / customer, and the coarse buckets the shop already knows.
            // Deliberately coarse — this is a visibility rule, not a tracking profile.
            if (!Schema::hasColumn('theme_sections', 'audience')) {
                $table->json('audience')->nullable()->after('platforms');
            }
        });

        // Indexed separately: adding an index inside the same closure as the column that backs it
        // fails on MySQL, which resolves the whole ALTER before the index sees the new column.
        if (Schema::hasColumn('theme_sections', 'uuid')) {
            Schema::table('theme_sections', function (Blueprint $table) {
                $table->index('uuid', 'theme_sections_uuid_index');
            });
        }

        // Backfill: every existing section gets an identity, so the app never meets a null uuid.
        // Chunked and driver-neutral rather than one UPDATE with a generated value, because the
        // uuid has to be unique per row and no portable SQL expression produces that.
        if (Schema::hasColumn('theme_sections', 'uuid')) {
            \App\Models\ThemeSection::query()
                ->whereNull('uuid')
                ->select(['id'])
                ->chunkById(200, function ($sections) {
                    foreach ($sections as $section) {
                        \App\Models\ThemeSection::query()
                            ->whereKey($section->id)
                            ->update(['uuid' => (string) \Illuminate\Support\Str::uuid()]);
                    }
                });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('theme_sections')) {
            return;
        }

        if (Schema::hasColumn('theme_sections', 'uuid')) {
            Schema::table('theme_sections', function (Blueprint $table) {
                $table->dropIndex('theme_sections_uuid_index');
            });
        }

        Schema::table('theme_sections', function (Blueprint $table) {
            foreach (['uuid', 'starts_at', 'ends_at', 'platforms', 'audience'] as $column) {
                if (Schema::hasColumn('theme_sections', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
