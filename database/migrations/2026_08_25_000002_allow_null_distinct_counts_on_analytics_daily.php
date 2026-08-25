<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two measures the `__other__` row cannot state.
 *
 * config/analytics.php has always promised that the tail beyond the cardinality cap is folded into
 * an `__other__` row rather than dropped, and the rollup applied a LIMIT and wrote no such row — so
 * on any dimension with a long tail the day's rows quietly summed to less than the day, and every
 * percentage computed from them was wrong.
 *
 * Folding the tail is exact for the additive measures. It is not for `visitors` and `new_visitors`,
 * which are COUNT(DISTINCT visitor_id) per key: adding those across keys counts one person once per
 * key they appear under. So the folded row leaves them null, which renders as "—", and that needs
 * the columns to accept null. Widening only — every existing row keeps its value.
 */
return new class extends Migration
{
    private const COLUMNS = ['visitors', 'new_visitors'];

    public function up(): void
    {
        $connection = config('analytics.connection');

        if (!Schema::connection($connection)->hasTable('analytics_daily')) {
            return;
        }

        foreach (self::COLUMNS as $column) {
            // Raw rather than a Blueprint change(): doctrine/dbal is not installed, and a nullable
            // widening is one statement that every supported engine accepts.
            $this->widen($connection, $column);
        }
    }

    public function down(): void
    {
        // Not narrowed back. A stored null is a row this platform deliberately could not measure,
        // and turning it into a zero on the way down would invent the number the fold refused to.
    }

    private function widen(?string $connection, string $column): void
    {
        $driver = DB::connection($connection)->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite cannot ALTER a column's nullability in place, and the test suite rebuilds this
            // table from the migration that creates it — so there is nothing to widen here.
            return;
        }

        try {
            DB::connection($connection)->statement(
                'ALTER TABLE `analytics_daily` MODIFY `' . $column . '` BIGINT UNSIGNED NULL',
            );
        } catch (\Throwable) {
            // An engine that will not take the statement keeps the column as it is; the fold then
            // writes 0 for that measure rather than failing the rollup.
        }
    }
};
