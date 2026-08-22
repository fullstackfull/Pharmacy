<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a campaign click actually happened.
 *
 * Clicks were recorded without recording the surface, so a short link opened in the app and the
 * same link opened in a browser were indistinguishable — which made the whole point of publishing
 * /go/* as an app link unmeasurable. Nullable, because every row written before this migration
 * genuinely does not know, and guessing "web" for them would be inventing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('analytics.connection') ?: config('database.default'));

        if (!$schema->hasTable('analytics_campaign_clicks')) {
            return;
        }

        if ($schema->hasColumn('analytics_campaign_clicks', 'surface')) {
            return;
        }

        $schema->table('analytics_campaign_clicks', function (Blueprint $table) {
            $table->string('surface', 16)->nullable()->after('device');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('analytics.connection') ?: config('database.default'));

        if ($schema->hasTable('analytics_campaign_clicks') && $schema->hasColumn('analytics_campaign_clicks', 'surface')) {
            $schema->table('analytics_campaign_clicks', function (Blueprint $table) {
                $table->dropColumn('surface');
            });
        }
    }
};
