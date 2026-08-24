<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 3.4 — Customer segments. One NEW table. A segment is a rule-based reading of data the
 * shop already records (orders, registration dates); membership is computed per request from a
 * short-lived per-customer cache, never stored, so there is nothing here to migrate or corrupt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customer_segments')) {
            Schema::create('customer_segments', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                // What sections target: joins the audience token list beside guest/customer.
                $table->string('key', 60)->unique();
                $table->boolean('status')->default(true);
                // Allowlisted [{field, operator, value}] rows, AND-combined (SegmentRules).
                $table->json('rules')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_segments');
    }
};
