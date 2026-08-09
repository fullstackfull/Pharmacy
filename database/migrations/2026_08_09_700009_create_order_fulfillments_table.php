<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order fulfilment — pick / pack / ship workflow (Phase 3, Stage C).
 *
 * Measured: an order walks pending → confirmed → processing → out_for_delivery → delivered. The
 * warehouse-side work in the middle — pick the items off the shelf, pack them, stage them for
 * dispatch — has no representation at all; "processing" is one opaque state with no pick list, no
 * packed marker, no record of which location fulfilled it.
 *
 * This adds that layer as an **overlay**: a fulfilment record per order (per seller for a split order)
 * with its own pick → pack → ready → shipped lifecycle, optionally tied to the warehouse it is picked
 * from (the multi-warehouse work built alongside this). It writes only to its own table and never
 * touches `orders.order_status`, so it enriches the middle of the flow without changing the state
 * machine the storefront and the apps already rely on.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_fulfillments')) {
            return;
        }

        Schema::create('order_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();   // which location fulfils it

            // pending → picking → packed → ready → shipped; or canceled.
            $table->string('status', 20)->default('pending');

            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('carrier', 120)->nullable();
            $table->string('tracking_number', 120)->nullable();

            $table->timestamp('picked_at')->nullable();
            $table->timestamp('packed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();

            $table->string('note', 512)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'order_id'], 'of_status_order_idx');
            $table->index('seller_id', 'of_seller_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fulfillments');
    }
};
