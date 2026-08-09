<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock movement log (Phase 3, Stage C).
 *
 * Measured: `products.current_stock` is a single number with no history. It is written from ~10 places
 * (orders, POS, the API, product edits) and, crucially, an admin can change it on the product form
 * with **no reason and no trail** — so "why is this 480 and not 500?" is unanswerable, and a
 * miscount, a breakage or a theft looks identical to a sale.
 *
 * This is an append-only log of *reasoned* stock changes. It is seeded by the two paths this phase
 * owns and can write to safely — manual adjustments (new) and purchase-order receipts (wired into the
 * procurement service built alongside this) — each row carrying the signed change, the resulting
 * balance, a reason, and who made it. It deliberately does NOT retrofit the ten sale-side write points
 * in one move; that is called out in the docs rather than half-done here.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_movements')) {
            return;
        }

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('seller_id')->nullable();

            // adjustment | receipt | sale | return | transfer — extensible as more paths are wired.
            $table->string('type', 20);

            // Signed: +N added stock, -N removed it.
            $table->integer('qty_change');

            // The product's stock immediately after this movement, captured for an auditable running
            // history that does not depend on replaying every row.
            $table->integer('balance_after')->nullable();

            $table->string('reason', 60)->nullable();     // damage | loss | theft | count_correction | found | expiry | ...
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note', 512)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_type', 20)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'created_at'], 'sm_product_time_idx');
            $table->index('type', 'sm_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
