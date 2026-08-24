<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deferred work remembers which kind of credential created it, not just a staff id.
 *
 * A bulk job and an automation rule both re-resolve their creator when they run, so that a shop
 * suspended or a permission revoked in the meantime stops the work. That worked for staff and
 * failed open for keys: a key principal has no staff id, a null staff id meant "the owner", and an
 * owner can do anything — so a rule written by a key scoped to one permission kept running with
 * every permission, and kept running after the key was revoked.
 *
 * Recording the key makes the same re-resolution honest for both.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['seller_automation_rules', 'seller_bulk_jobs'] as $table) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'created_by_api_key_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('created_by_api_key_id')->nullable()->after('created_by_staff_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['seller_automation_rules', 'seller_bulk_jobs'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'created_by_api_key_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('created_by_api_key_id');
                });
            }
        }
    }
};
