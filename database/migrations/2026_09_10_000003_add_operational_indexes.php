<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two indexes that existing code is already waiting on.
 *
 * `order_status_histories(order_id, created_at)` — `OrderIntegrityPanel` disables its own
 * stuck-order check because this join column has no index, and says so in a comment: "a check that
 * takes the shop down to find a missing audit row is worse than the missing audit row". The order
 * timeline shipped in Phase 2 reads the same column, and so will the stuck-order detector. One index
 * unblocks all three.
 *
 * `stock_movements(seller_id, created_at)` — Phase 2 gave a seller their own stock ledger, filtered
 * by `seller_id`, and there was nothing for that filter to ride.
 *
 * Both are plain secondary indexes on existing columns: additive, no data touched, no lock beyond
 * the build.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('order_status_histories', ['order_id', 'created_at'], 'osh_order_time_idx');
        $this->addIndex('stock_movements', ['seller_id', 'created_at'], 'sm_seller_time_idx');
    }

    public function down(): void
    {
        $this->dropIndex('order_status_histories', 'osh_order_time_idx');
        $this->dropIndex('stock_movements', 'sm_seller_time_idx');
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addIndex(string $table, array $columns, string $name): void
    {
        if (!Schema::hasTable($table) || $this->hasIndex($table, $name)) {
            return;
        }

        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, fn ($blueprint) => $blueprint->index($columns, $name));
    }

    private function dropIndex(string $table, string $name): void
    {
        if (Schema::hasTable($table) && $this->hasIndex($table, $name)) {
            Schema::table($table, fn ($blueprint) => $blueprint->dropIndex($name));
        }
    }

    /** Driver-aware: SHOW INDEX is MySQL's, and the test suite runs on SQLite. */
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
};
