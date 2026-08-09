<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The commission snapshot, per order item (Phase 3, Stage B).
 *
 * This is the table the whole financial core rests on. `order_details` has no commission column at
 * all today; the only figure kept is one aggregate `orders.admin_commission`, computed from
 * whatever the seller's percentage happened to be that day. So:
 *
 *   - Change a seller's rate, and there is nothing recording what the old orders were charged
 *     under. The aggregate survives, but *why* it was that number does not.
 *   - An order split across sellers has one number for the whole thing.
 *   - Refunding one line of a five-line order cannot subtract that line's commission, because the
 *     line's commission was never a separate figure.
 *
 * Every column here is written once, at order time, and never recalculated. Settlements, seller
 * statements and reconciliation all read from this rather than re-deriving from live rules — which
 * is the difference between accounting and guessing.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_item_commissions')) {
            return;
        }

        Schema::create('order_item_commissions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_details_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is', 20)->default('admin');

            // --- what the rule was, frozen ---
            // Nullable because a rule can be deleted; the copied values below stay valid either way.
            $table->unsignedBigInteger('commission_rule_id')->nullable();
            $table->string('rule_scope_type', 20)->nullable();
            $table->string('rule_label', 191)->nullable();
            $table->string('rate_type', 30)->default('percentage');
            $table->decimal('percentage', 8, 4)->default(0);
            $table->decimal('fixed_amount', 24, 4)->default(0);

            // --- what it was applied to, frozen ---
            // The line total after product discount, before commission. Kept so the arithmetic can
            // be re-checked years later without reconstructing prices.
            $table->decimal('commissionable_amount', 24, 4)->default(0);

            // --- the result, frozen ---
            $table->decimal('commission_amount', 24, 4)->default(0);
            $table->decimal('seller_net_amount', 24, 4)->default(0);
            $table->string('currency', 10)->nullable();

            // Set when a refund or return reverses part of this line, so the settlement can net it
            // off without editing the original figures.
            $table->decimal('reversed_amount', 24, 4)->default(0);

            $table->timestamps();

            // One snapshot per order line. This is also what makes writing it idempotent — a retried
            // order transaction cannot double-charge commission.
            $table->unique('order_details_id', 'oic_order_details_unique');
            $table->index('order_id', 'oic_order_index');
            $table->index(['seller_id', 'seller_is'], 'oic_seller_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_commissions');
    }
};
