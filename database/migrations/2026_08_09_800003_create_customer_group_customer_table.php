<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which customers belong to which groups (Phase 3, Stage E). A plain pivot, so a customer can be
 * placed in a group without touching the core `users` table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_group_customer')) {
            return;
        }

        Schema::create('customer_group_customer', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_group_id');
            $table->unsignedBigInteger('customer_id');
            $table->timestamps();

            $table->unique(['customer_group_id', 'customer_id'], 'cgc_group_customer_unique');
            $table->index('customer_id', 'cgc_customer_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_group_customer');
    }
};
