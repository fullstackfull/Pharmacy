<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give a staff member a way to hold an API session of their own.
 *
 * `seller_staff` has carried credentials since it was created, but only the web panel ever used
 * them: the staff login signs the member in as their parent seller on a session, and the stateless
 * API has no session to read. So a warehouse employee or a finance clerk could not use the seller
 * app at all — the only way in was to be handed the owner's own token, which carries owner rights.
 *
 * The column is a token like the seller's, and compared the same way: case-sensitively, so the
 * fifty characters it holds are worth fifty characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('seller_staff') || Schema::hasColumn('seller_staff', 'auth_token')) {
            return;
        }

        Schema::table('seller_staff', function (Blueprint $table) {
            $table->text('auth_token')->nullable()->after('password');
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `seller_staff` MODIFY `auth_token` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('seller_staff') && Schema::hasColumn('seller_staff', 'auth_token')) {
            Schema::table('seller_staff', function (Blueprint $table) {
                $table->dropColumn('auth_token');
            });
        }
    }
};
