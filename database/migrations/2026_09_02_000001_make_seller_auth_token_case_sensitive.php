<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A session token must match exactly, including its case.
 *
 * `sellers.auth_token` is a text column under the database's default collation, which for this
 * schema is `utf8mb4_unicode_ci` — case-insensitive. Every lookup of a bearer token therefore folds
 * case, so `AbC…` and `aBc…` authenticate the same session. That cuts the effective alphabet of the
 * 50-character token the login issues from 62 symbols to 36: roughly 22 bits of entropy given away
 * for nothing.
 *
 * Nothing is re-encoded and no value changes — only the comparison rule for the column. Existing
 * tokens keep working, because a token compared against itself matches under either collation.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'sellers' => 'auth_token',
        'delivery_men' => 'auth_token',
    ];

    public function up(): void
    {
        $this->apply('utf8mb4_bin');
    }

    public function down(): void
    {
        $this->apply('utf8mb4_unicode_ci');
    }

    private function apply(string $collation): void
    {
        // SQLite compares text case-sensitively already and has no COLLATE for this, so the test
        // suite has nothing to change; only MySQL/MariaDB carries the loose default.
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::COLUMNS as $table => $column) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` TEXT CHARACTER SET utf8mb4 COLLATE {$collation}");
        }
    }
};
