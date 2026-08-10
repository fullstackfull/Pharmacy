<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment routing rules — payment orchestration (Phase 3, Stage E).
 *
 * Measured: the platform has 36 payment gateways (in `addon_settings`, settings_type `payment_config`)
 * each with an is_active flag — but the choice is all-or-nothing. There is no way to say "hide
 * PayPal over 5,000", "prefer the local gateway for domestic orders", or route by amount or country.
 * That routing layer is the gap.
 *
 * A rule targets one gateway with an action — `hide` or `prefer` — under optional conditions (amount
 * range, country). The resolver applies matching rules to the list of active gateways. It is
 * **non-breaking**: with no rules the list comes back exactly as the platform assembled it, so nothing
 * about checkout changes until rules are configured and the resolver is consulted.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_routing_rules')) {
            return;
        }

        Schema::create('payment_routing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('gateway_code', 60);         // e.g. paypal, stripe, razor_pay
            $table->string('action', 10)->default('hide');   // hide | prefer

            // Optional conditions — a null condition does not constrain.
            $table->decimal('min_amount', 24, 2)->nullable();
            $table->decimal('max_amount', 24, 2)->nullable();
            $table->string('country', 120)->nullable();

            $table->unsignedInteger('priority')->default(10);   // lower first, for prefer ordering
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority'], 'prr_status_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_routing_rules');
    }
};
