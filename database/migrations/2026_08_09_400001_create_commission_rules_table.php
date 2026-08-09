<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rule-based marketplace commission (Phase 3, Stage B).
 *
 * What exists today, measured rather than assumed: `Helpers::seller_sales_commission()` reads
 * `sellers.sales_commission_percentage`, falls back to the global `sales_commission` setting, and
 * multiplies. That is the whole engine. There is no category rate, no product override, no fixed
 * fee, no campaign rate, and no record anywhere of *which* rule produced a number.
 *
 * This table makes the rate a rule rather than a column, with an explicit, deterministic priority
 * so two rules can never both "win":
 *
 *     product (400) > vendor (300) > category (200) > global (100)
 *
 * The scope column carries the id being scoped to; a global rule has none. Rules are dated so a
 * campaign rate can start and end without editing anything, and `is_active` allows switching one
 * off without losing the record of it having existed.
 *
 * Additive and reversible: new table only, guarded, with a working down(). Nothing reads it until
 * the engine is wired in, so an install that migrates and stops here behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commission_rules')) {
            return;
        }

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();

            // global | category | vendor | product
            $table->string('scope_type', 20);

            // The id of the category/vendor/product this rule applies to. Null for global.
            $table->unsignedBigInteger('scope_id')->nullable();

            // percentage | fixed | percentage_plus_fixed
            $table->string('rate_type', 30)->default('percentage');

            // Percentage of the line's net amount. Four decimal places because marketplaces do use
            // rates like 12.5% and 8.75%.
            $table->decimal('percentage', 8, 4)->default(0);

            // A flat fee. Charged PER ITEM LINE, not per order — stated here because "$1 per order
            // item" and "$1 per order" are different products and the difference is easy to lose.
            $table->decimal('fixed_amount', 24, 4)->default(0);

            // Guard rails so a misconfigured rule cannot take an absurd cut. 0 means "no bound".
            $table->decimal('min_amount', 24, 4)->default(0);
            $table->decimal('max_amount', 24, 4)->default(0);

            // Higher wins. Defaulted from scope_type by the engine, but stored so an operator can
            // deliberately float a campaign rule above a product override.
            $table->unsignedInteger('priority')->default(100);

            $table->boolean('is_active')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Free text shown in the snapshot, so "why was this order charged 12%?" has an answer
            // that survives the rule being edited later.
            $table->string('label', 191)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id'], 'cr_scope_index');
            $table->index(['is_active', 'priority'], 'cr_active_priority_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
