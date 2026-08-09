<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product batches with expiry (Phase 3, Stage C).
 *
 * Measured: a product has one `current_stock` number and no notion of *when* any of it expires. For a
 * catalogue that carries perishable or dated goods that is a real blind spot — nothing answers "what
 * is expiring this month?" and expired stock is written off, if at all, by editing the stock field
 * with no reason.
 *
 * This is a lightweight tracking overlay: a batch records that a quantity of a product carries a given
 * expiry, so expiry becomes visible and actionable. It is deliberately decoupled from `current_stock`
 * — that stays the single source of truth for sellable quantity — so adopting this changes no selling
 * behaviour. The one action that touches stock, writing off an expired batch, goes through the
 * inventory adjustment path (reason `expiry`) built alongside this, so the write-off is reasoned and
 * logged like any other stock change.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_batches')) {
            return;
        }

        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('seller_id')->nullable();

            $table->string('batch_number', 120)->nullable();
            $table->date('expiry_date')->nullable();

            $table->unsignedInteger('quantity')->default(0);          // units still held in this batch
            $table->unsignedInteger('initial_quantity')->default(0);  // as first recorded, for reference

            // active → depleted (written off or consumed). Expiry is derived from expiry_date at read
            // time, so an unswept expired batch is still recognised as expired.
            $table->string('status', 20)->default('active');

            $table->string('reference_type', 40)->nullable();   // e.g. purchase_order, when received via one
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note', 512)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'expiry_date'], 'pb_product_expiry_idx');
            $table->index(['status', 'expiry_date'], 'pb_status_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_batches');
    }
};
