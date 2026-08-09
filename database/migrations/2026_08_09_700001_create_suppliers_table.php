<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers — the supply side (Phase 3, Stage C).
 *
 * Measured: the platform models the sell side in depth (sellers, products, orders) but has no concept
 * of where inventory comes from. There is no supplier, no purchase order, no receiving — stock is a
 * single `products.current_stock` number that only ever goes down as things sell. Procurement is the
 * missing half.
 *
 * A supplier is owned by whoever procures from it: `seller_id` null means a platform/admin supplier,
 * a value means that seller's own supplier — so the same table serves in-house replenishment and a
 * seller managing their own inventory, without one being able to see the other's.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suppliers')) {
            return;
        }

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();

            // Null = platform/admin-owned supplier; a value scopes it to one seller.
            $table->unsignedBigInteger('seller_id')->nullable();

            $table->string('name', 191);
            $table->string('contact_name', 191)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('tax_number', 60)->nullable();
            $table->string('address', 512)->nullable();
            $table->string('status', 20)->default('active');   // active | inactive
            $table->string('notes', 1000)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status'], 'suppliers_seller_status_idx');
            $table->index('name', 'suppliers_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
