<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer groups for B2B / wholesale pricing (Phase 3, Stage E).
 *
 * Measured: the platform has no notion of a customer *segment*. Every buyer sees the same price;
 * `users` carries no group or type, and there is no wholesale, VIP or contract tier. B2B needs exactly
 * that — a named group a buyer belongs to, carrying a default discount, that per-product prices can
 * hang off.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_groups')) {
            return;
        }

        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('type', 40)->default('wholesale');   // wholesale | vip | contract | ...
            $table->decimal('default_discount_percent', 6, 2)->default(0);
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('status', 'cg_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_groups');
    }
};
