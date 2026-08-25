<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Switches that can be thrown for some of the shop rather than all of it.
 *
 * There was no flag table, no config and no per-seller or per-percentage switch anywhere. The only
 * lever was publishing or unpublishing an entire addon module — so every change to this marketplace
 * was all-or-nothing for everyone at once, which means a change that goes wrong goes wrong for every
 * seller and every shopper simultaneously, and the only way back is a deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('feature_flags')) {
            return;
        }

        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            // Dotted, like a policy key: seller_center.new_orders_table.
            $table->string('key', 96)->unique();
            $table->string('description', 500)->nullable();
            // The master switch. Off means off for everyone, whatever the rollout says.
            $table->boolean('enabled')->default(false);
            // 0-100. The share of subjects the flag is on for once it is enabled.
            $table->unsignedTinyInteger('rollout_percent')->default(0);
            // Always on for these sellers, whatever the percentage decides — the pilot group.
            $table->json('seller_ids')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
