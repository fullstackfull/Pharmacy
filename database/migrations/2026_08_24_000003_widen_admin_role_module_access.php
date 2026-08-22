<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give an admin role room for the permissions it is now offered.
 *
 * `admin_roles.module_access` holds a JSON array of granted keys in a varchar(250). The twelve
 * module keys the panel shipped with encode to 159 characters, which left 91 — and the six
 * monitoring capabilities take a full role to 287.
 *
 * That would not have failed loudly. This connection runs with strict mode off
 * (config/database.php: 'strict' => false), so MariaDB truncates an over-long value and warns
 * instead of rejecting it. The stored text would be cut mid-key, json_decode would return null,
 * Helpers::module_permission_check would find an empty array — and an administrator who had just
 * been granted MORE permissions would silently lose ALL of them.
 *
 * TEXT rather than a bigger varchar: the list grows every time the panel gains an area, and there
 * is no reason to have this conversation again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_roles') || !Schema::hasColumn('admin_roles', 'module_access')) {
            return;
        }

        // Raw DDL rather than Schema::table()->change(): doctrine/dbal's change() rebuilds the
        // column definition from its own introspection, which on this table has historically
        // dropped the nullability. One statement, one change, nothing else touched.
        DB::statement('ALTER TABLE `admin_roles` MODIFY `module_access` TEXT NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('admin_roles') || !Schema::hasColumn('admin_roles', 'module_access')) {
            return;
        }

        // Only narrow again if every stored value still fits, or the rollback itself would be the
        // truncation this migration exists to prevent.
        $longest = (int) DB::table('admin_roles')->selectRaw('MAX(CHAR_LENGTH(module_access)) AS length')->value('length');
        if ($longest <= 250) {
            DB::statement('ALTER TABLE `admin_roles` MODIFY `module_access` VARCHAR(250) NULL');
        }
    }
};
