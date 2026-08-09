<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders — the procurement document (Phase 3, Stage C).
 *
 * A header per order to a supplier, with a status that walks draft → ordered → partially_received →
 * received (or canceled). Receiving a line increments product stock; the header status is derived
 * from how much of the order has been received, so "what is still on order" is always answerable.
 *
 * Scoped by `seller_id` (null = admin/platform) exactly like suppliers, so the same flow serves
 * in-house replenishment and a seller's own procurement.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_orders')) {
            return;
        }

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('seller_id')->nullable();

            // draft (editable) → ordered (placed) → partially_received / received; or canceled.
            $table->string('status', 20)->default('draft');

            $table->string('currency', 10)->nullable();
            $table->decimal('subtotal', 24, 2)->default(0);
            $table->decimal('total', 24, 2)->default(0);

            $table->date('expected_at')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->string('notes', 1000)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status'], 'po_seller_status_idx');
            $table->index('supplier_id', 'po_supplier_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
