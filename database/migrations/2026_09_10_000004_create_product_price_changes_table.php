<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed this price, from what, and why.
 *
 * None of those three questions could be answered. `ProductService` writes `unit_price` at seven
 * call sites and is not among `AuditLogger`'s consumers, so a price change left no trace anywhere:
 * not who made it, not what it was before, not whether a person typed it or a bulk job applied it.
 *
 * A price is the most consequential number a seller owns and the easiest to get wrong at speed.
 * Without this row, "the price on my best seller halved overnight" is unanswerable — and a pricing
 * control engine, a scheduled price change or a promotion that restores a previous price all need
 * to know what the previous price was.
 *
 * `source` matters as much as the numbers. A price that moved because a promotion ended is a
 * different event from one a staff member typed, and only the row can tell them apart afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_price_changes')) {
            return;
        }

        Schema::create('product_price_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('seller_id')->nullable();

            /** Null when the product had no price before — a first listing, not a change. */
            $table->decimal('previous_price', 24, 3)->nullable();
            $table->decimal('new_price', 24, 3);

            $table->decimal('previous_discount', 24, 3)->nullable();
            $table->decimal('new_discount', 24, 3)->nullable();
            $table->string('previous_discount_type', 20)->nullable();
            $table->string('new_discount_type', 20)->nullable();

            /** seller_ui | admin_ui | api | bulk_job | promotion | automation | import */
            $table->string('source', 30)->default('seller_ui');

            /** Free text where a source carries one — a bulk job's note, a promotion's name. */
            $table->string('reason', 191)->nullable();

            $table->string('actor_type', 30)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 191)->nullable();

            $table->timestamps();

            // A product's own history, newest first — the only query this table is read by.
            $table->index(['product_id', 'created_at'], 'ppc_product_time_idx');
            // And a shop-wide view of recent pricing activity.
            $table->index(['seller_id', 'created_at'], 'ppc_seller_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_changes');
    }
};
