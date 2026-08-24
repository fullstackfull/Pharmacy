<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turn the insight row into a first-class operational issue.
 *
 * Phase 3 asks for an Issue architecture and the platform already has three quarters of one:
 * `seller_insights` carries identity, dedup, severity, the entity it is about and the action to
 * take, and the engine already auto-resolves anything a producer stops reporting. Building a second
 * table beside it would leave two lists of a seller's problems that could disagree — the exact
 * failure the brief warns about.
 *
 * So the table grows rather than being replaced, and keeps its name. Renaming it would break every
 * reader for a cosmetic gain, and the brief is explicit about not renaming production tables
 * casually. The row is the issue; `seller_insights` is what it is called.
 *
 * Every column added is nullable or defaulted, and the backfill derives the new state from what the
 * old columns already said — so existing rows arrive in the new model correctly rather than as a
 * pile of nulls, and nothing that reads the old columns notices.
 *
 * `status` is the substantive addition. Until now a row was open, dismissed or resolved, which
 * cannot express the states a seller actually works in: acknowledged but not started, in progress,
 * waiting on somebody else. And it distinguishes a problem the platform fixed by itself from one a
 * person fixed — without that, "auto-resolved" is a claim nobody can check.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('seller_insights')) {
            return;
        }

        Schema::table('seller_insights', function (Blueprint $table) {
            if (!Schema::hasColumn('seller_insights', 'category')) {
                /** The domain this belongs to: orders, inventory, catalog, pricing, returns, shipping, finance, integrations. */
                $table->string('category', 40)->nullable()->after('type');
            }

            if (!Schema::hasColumn('seller_insights', 'status')) {
                /** detected|open|acknowledged|in_progress|waiting|resolved|auto_resolved|dismissed */
                $table->string('status', 20)->default('open')->after('severity');
            }

            if (!Schema::hasColumn('seller_insights', 'impact_score')) {
                /**
                 * 0–100, computed from documented arithmetic over real figures. Never a number
                 * anybody chose: severity is derived from this, so an invented score would be an
                 * invented priority.
                 */
                $table->unsignedTinyInteger('impact_score')->default(0)->after('impact');
            }

            if (!Schema::hasColumn('seller_insights', 'affected_count')) {
                /** How many things this issue is about. One issue for forty orders, not forty issues. */
                $table->unsignedInteger('affected_count')->default(1)->after('impact_score');
            }

            if (!Schema::hasColumn('seller_insights', 'due_at')) {
                /** When it stops being fixable in time. Distinct from expires_at, which is when it stops being news. */
                $table->timestamp('due_at')->nullable()->after('expires_at');
            }

            if (!Schema::hasColumn('seller_insights', 'first_detected_at')) {
                /**
                 * When this problem first appeared, kept across re-detections.
                 *
                 * `created_at` cannot answer it once a row is updated in place, and "how long has
                 * this been true" is what escalation runs on.
                 */
                $table->timestamp('first_detected_at')->nullable()->after('due_at');
            }

            if (!Schema::hasColumn('seller_insights', 'last_detected_at')) {
                $table->timestamp('last_detected_at')->nullable()->after('first_detected_at');
            }

            if (!Schema::hasColumn('seller_insights', 'detection_count')) {
                /** How many sweeps have seen it. A problem detected forty times is a different problem. */
                $table->unsignedInteger('detection_count')->default(1)->after('last_detected_at');
            }

            if (!Schema::hasColumn('seller_insights', 'escalation_level')) {
                /** 0 = as detected. Each step up is a severity promotion the engine made, not a detector. */
                $table->unsignedTinyInteger('escalation_level')->default(0)->after('detection_count');
            }

            if (!Schema::hasColumn('seller_insights', 'assigned_staff_id')) {
                /** Which member of the seller's team owns it. Null means the shop, not nobody. */
                $table->unsignedBigInteger('assigned_staff_id')->nullable()->after('escalation_level');
            }

            if (!Schema::hasColumn('seller_insights', 'resolution_type')) {
                /** auto|seller|expired|superseded — how it ended, which "resolved_at" alone cannot say. */
                $table->string('resolution_type', 40)->nullable()->after('resolved_at');
            }

            if (!Schema::hasColumn('seller_insights', 'resolution_message')) {
                $table->text('resolution_message')->nullable()->after('resolution_type');
            }

            if (!Schema::hasColumn('seller_insights', 'metadata')) {
                /** Whatever the detector needs to explain itself later — the figures behind the score. */
                $table->json('metadata')->nullable()->after('action_params');
            }
        });

        $this->backfill();
        $this->addIndexes();
    }

    /**
     * Bring existing rows into the new model from what the old columns already said.
     *
     * Without this every row that predates the migration would read as `open` with no history, and
     * a resolved insight would reappear in the Control Tower on the first load.
     */
    private function backfill(): void
    {
        // The three states the old columns could express, mapped in the order they take precedence:
        // a row that was both dismissed and resolved was resolved first.
        DB::table('seller_insights')->whereNotNull('resolved_at')
            ->update(['status' => 'resolved', 'resolution_type' => 'auto']);

        DB::table('seller_insights')->whereNull('resolved_at')->whereNotNull('dismissed_at')
            ->update(['status' => 'dismissed']);

        DB::table('seller_insights')->whereNull('resolved_at')->whereNull('dismissed_at')
            ->update(['status' => 'open']);

        // History it never kept. `created_at` is the best available answer for when it first
        // appeared and `updated_at` for when it was last seen — both true for a row written by the
        // upsert, which is every row.
        DB::table('seller_insights')->whereNull('first_detected_at')->update([
            'first_detected_at' => DB::raw('created_at'),
            'last_detected_at' => DB::raw('updated_at'),
        ]);

        // Category from the producer name, which is what the three existing producers encode.
        foreach ([
            'ORDER_SLA' => 'orders',
            'INVENTORY_RISK' => 'inventory',
            'LISTING_QUALITY' => 'catalog',
        ] as $type => $category) {
            DB::table('seller_insights')->where('type', $type)->whereNull('category')
                ->update(['category' => $category]);
        }
    }

    private function addIndexes(): void
    {
        // The Control Tower's query: this seller's live issues, worst first. The old open-index was
        // built on the dismissed/resolved columns that `status` now supersedes.
        $this->addIndex('seller_insights', ['seller_id', 'status', 'severity', 'impact_score'], 'si_seller_status_idx');
        // And escalation's: what has been standing too long.
        $this->addIndex('seller_insights', ['status', 'due_at'], 'si_status_due_idx');
    }

    /** @param array<int, string> $columns */
    private function addIndex(string $table, array $columns, string $name): void
    {
        if (!Schema::hasTable($table) || $this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }

    private function hasIndex(string $table, string $name): bool
    {
        try {
            if (DB::connection()->getDriverName() !== 'mysql') {
                return collect(Schema::getIndexes($table))->contains(fn ($index) => $index['name'] === $name);
            }

            return DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$name]) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('seller_insights')) {
            return;
        }

        foreach (['si_seller_status_idx', 'si_status_due_idx'] as $index) {
            if ($this->hasIndex('seller_insights', $index)) {
                Schema::table('seller_insights', fn (Blueprint $table) => $table->dropIndex($index));
            }
        }

        Schema::table('seller_insights', function (Blueprint $table) {
            foreach ([
                'category', 'status', 'impact_score', 'affected_count', 'due_at',
                'first_detected_at', 'last_detected_at', 'detection_count', 'escalation_level',
                'assigned_staff_id', 'resolution_type', 'resolution_message', 'metadata',
            ] as $column) {
                if (Schema::hasColumn('seller_insights', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
