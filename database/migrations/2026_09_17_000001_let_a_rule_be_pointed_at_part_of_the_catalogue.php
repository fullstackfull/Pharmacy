<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which part of the shop a rule is allowed to touch.
 *
 * Until now a rule applied to everything its trigger selected, which is fine for "republish when
 * restocked" and alarming for "mark down anything that has not sold in ninety days". A seller who
 * wants that second rule for one clearance brand had no way to say so, and the only safe answer
 * was not to write the rule at all.
 *
 * Nullable and absent by default, so every rule that exists keeps applying exactly as it does now.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('seller_automation_rules') || Schema::hasColumn('seller_automation_rules', 'scope')) {
            return;
        }

        Schema::table('seller_automation_rules', function (Blueprint $table) {
            $table->json('scope')->nullable()->after('action_settings');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('seller_automation_rules') && Schema::hasColumn('seller_automation_rules', 'scope')) {
            Schema::table('seller_automation_rules', function (Blueprint $table) {
                $table->dropColumn('scope');
            });
        }
    }
};
