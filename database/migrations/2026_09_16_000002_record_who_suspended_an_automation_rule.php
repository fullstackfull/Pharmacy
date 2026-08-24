<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who stopped this rule.
 *
 * Two very different things were both stored as `suspended`: the breaker tripping because the
 * seller's own rule kept failing, and the marketplace stopping a rule on purpose. The first is the
 * seller's to clear — it is their rule and their mistake. The second is not, or the marketplace's
 * decision lasts exactly until the seller reopens the page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('seller_automation_rules') || Schema::hasColumn('seller_automation_rules', 'suspended_by')) {
            return;
        }

        Schema::table('seller_automation_rules', function (Blueprint $table) {
            /** platform (the breaker) | marketplace (a person here). Null when the rule is not suspended. */
            $table->string('suspended_by', 20)->nullable()->after('suspension_reason');
        });

        // Anything already suspended was suspended by the breaker: the control a person uses to do
        // it deliberately ships with this same change, so no existing row can have come from one.
        DB::table('seller_automation_rules')
            ->where('status', 'suspended')
            ->update(['suspended_by' => 'platform']);
    }

    public function down(): void
    {
        if (Schema::hasTable('seller_automation_rules') && Schema::hasColumn('seller_automation_rules', 'suspended_by')) {
            Schema::table('seller_automation_rules', function (Blueprint $table) {
                $table->dropColumn('suspended_by');
            });
        }
    }
};
