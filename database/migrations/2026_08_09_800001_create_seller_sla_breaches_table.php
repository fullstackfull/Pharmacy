<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller SLA breach ledger (Phase 3, Stage E).
 *
 * The seller scorecard (built earlier this phase) computes quality metrics and a health tier, but its
 * thresholds are hard-coded and it is point-in-time: there is no *policy* an operator can set, and no
 * record of *when* a seller crossed a line or *for how long*. This adds that — a configurable policy
 * (the thresholds live in settings) evaluated against the scorecard, and an append/clear ledger of
 * breaches so an operator can see who is out of policy now and who has a history of it.
 *
 * A breach opens when a metric crosses its threshold and clears when the seller comes back inside it,
 * so at most one open row exists per (seller, metric) — the row is the current state, its timestamps
 * the history.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_sla_breaches')) {
            return;
        }

        Schema::create('seller_sla_breaches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');

            // Which policy line was crossed: cancellation_rate | return_rate | refund_rate | rating.
            $table->string('metric', 40);

            $table->decimal('threshold', 12, 4);
            $table->decimal('actual_value', 12, 4);

            $table->string('status', 12)->default('open');   // open | cleared
            $table->timestamp('cleared_at')->nullable();

            $table->timestamps();

            $table->index(['seller_id', 'metric', 'status'], 'sla_seller_metric_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_sla_breaches');
    }
};
