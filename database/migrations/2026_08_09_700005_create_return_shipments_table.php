<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Returns logistics — RMA / return shipments (Phase 3, Stage C).
 *
 * Measured: the platform has a refund flow (`refund_requests`, `refund_statuses`) and a *financial*
 * reversal (the commission reversal built in Stage B), but nothing physical. When a refund is
 * approved the money is handled — and the goods are simply gone from the books: no return
 * authorisation, no shipment tracking, and, crucially, **no restock**. An approved return leaves the
 * product's sellable stock one unit short forever.
 *
 * This adds the physical side: a return authorisation with a logistics state machine, and — the
 * closing of the loop — a restock on receipt that runs through the inventory movement log built
 * alongside this (the `return` movement type was reserved there for exactly this). It links to the
 * refund_request so the physical and financial sides of one return can be seen together, without
 * duplicating the financial reversal that already exists.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('return_shipments')) {
            return;
        }

        Schema::create('return_shipments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();

            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('refund_request_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();

            $table->unsignedInteger('qty')->default(1);
            $table->string('reason', 120)->nullable();

            // authorized → in_transit → received → restocked | rejected.
            $table->string('status', 20)->default('authorized');

            // Whether the goods go back into sellable stock on receipt. False for damaged/unsellable
            // returns, which are received (so the return closes) but not restocked.
            $table->boolean('restock')->default(true);

            $table->string('carrier', 120)->nullable();
            $table->string('tracking_number', 120)->nullable();
            $table->timestamp('received_at')->nullable();

            $table->string('note', 512)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_type', 20)->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status'], 'rs_seller_status_idx');
            $table->index('status', 'rs_status_idx');
            $table->index('refund_request_id', 'rs_refund_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_shipments');
    }
};
