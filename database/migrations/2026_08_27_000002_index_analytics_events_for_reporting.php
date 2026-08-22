<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The index every report actually needs, and two that were paying for nothing.
 *
 * Every read in AnalyticsReporting filters analytics_events on is_bot, is_internal and a date
 * range — analytics_sessions has exactly that index and its events twin did not, so a time-range
 * scan read the whole table and grew with it.
 *
 * The two dropped indexes are leading prefixes of composites declared thirty lines below them in
 * the original migration, so they answer nothing the composite cannot, and they cost a write on
 * every event recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('analytics.connection') ?: config('database.default'));

        if (!$schema->hasTable('analytics_events')) {
            return;
        }

        $existing = $this->indexNames($schema);

        $schema->table('analytics_events', function (Blueprint $table) use ($existing) {
            if (!in_array('analytics_event_real_time', $existing, true)) {
                $table->index(['is_bot', 'is_internal', 'occurred_at'], 'analytics_event_real_time');
            }

            // Guarded individually: an installation that never had them, or that has already had
            // this migration run, must not fail on a name that is not there.
            if (in_array('analytics_events_name_index', $existing, true)) {
                $table->dropIndex('analytics_events_name_index');
            }

            if (in_array('analytics_events_session_id_index', $existing, true)) {
                $table->dropIndex('analytics_events_session_id_index');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('analytics.connection') ?: config('database.default'));

        if (!$schema->hasTable('analytics_events')) {
            return;
        }

        $existing = $this->indexNames($schema);

        $schema->table('analytics_events', function (Blueprint $table) use ($existing) {
            if (in_array('analytics_event_real_time', $existing, true)) {
                $table->dropIndex('analytics_event_real_time');
            }

            if (!in_array('analytics_events_name_index', $existing, true)) {
                $table->index('name');
            }

            if (!in_array('analytics_events_session_id_index', $existing, true)) {
                $table->index('session_id');
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private function indexNames(\Illuminate\Database\Schema\Builder $schema): array
    {
        try {
            return array_column($schema->getIndexes('analytics_events'), 'name');
        } catch (\Throwable) {
            return [];
        }
    }
};
