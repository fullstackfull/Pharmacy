<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the visitor behind a session was told apart from every other visitor.
 *
 * Not every identity is worth the same. A first-party cookie or a signed-in account identifies one
 * person; a masked network address identifies one *network*, so every phone behind the same carrier
 * NAT collapses into a single "visitor". Without this column the two are indistinguishable in the
 * table and the unique-visitor figure quietly reports the second as if it were the first.
 *
 * Nullable, because every session written before this migration genuinely does not record which it
 * was, and back-filling a guess would be inventing the very thing the column exists to disclose.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('analytics.connection') ?: config('database.default'));

        if (!$schema->hasTable('analytics_sessions') || $schema->hasColumn('analytics_sessions', 'identity_basis')) {
            return;
        }

        $schema->table('analytics_sessions', function (Blueprint $table) {
            // user | cookie | client | network
            $table->string('identity_basis', 16)->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('analytics.connection') ?: config('database.default'));

        if ($schema->hasTable('analytics_sessions') && $schema->hasColumn('analytics_sessions', 'identity_basis')) {
            $schema->table('analytics_sessions', function (Blueprint $table) {
                $table->dropColumn('identity_basis');
            });
        }
    }
};
