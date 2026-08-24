<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When somebody else changed the thing a rule had changed.
 *
 * Automation claims what it did: a listing it hid is one it may put back when stock returns, and one
 * whose hiding it may undo. Both claims are only true while nothing else has touched that listing.
 * A seller who republishes a hidden line by hand and later hides it again on purpose was, until
 * this column existed, republished by the rule that hid it months earlier.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('seller_automation_actions') || Schema::hasColumn('seller_automation_actions', 'superseded_at')) {
            return;
        }

        Schema::table('seller_automation_actions', function (Blueprint $table) {
            $table->timestamp('superseded_at')->nullable()->after('reverted_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('seller_automation_actions') && Schema::hasColumn('seller_automation_actions', 'superseded_at')) {
            Schema::table('seller_automation_actions', function (Blueprint $table) {
                $table->dropColumn('superseded_at');
            });
        }
    }
};
