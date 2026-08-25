<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A receipt for every gateway callback that lands.
 *
 * No gateway callback left a trace anywhere. A callback that never arrived and one that arrived and
 * was rejected were the same absence of a row — which is why the payments page could name the
 * symptom ("money captured with no order") and never the cause, and why a payment outage was visible
 * only as orders that quietly stopped appearing.
 *
 * Kept small on purpose: which gateway, which reference, what it decided, when it landed. Not the
 * callback body — a gateway payload carries card fragments, customer addresses and signing material,
 * and a table an operator reads on screen is the last place any of that belongs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_gateway_receipts')) {
            return;
        }

        Schema::create('payment_gateway_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 40);
            // The gateway's own id for the attempt, when it sends one back.
            $table->string('reference', 100)->nullable();
            // Our payment_requests.id, when the callback carries enough to find it.
            $table->uuid('payment_request_id')->nullable();
            // success | failure | ignored — what the callback was taken to mean.
            $table->string('outcome', 12);
            $table->string('note', 191)->nullable();
            $table->string('ip', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['gateway', 'created_at'], 'payment_receipt_gateway_window');
            $table->index('payment_request_id', 'payment_receipt_request');
            $table->index('reference', 'payment_receipt_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_receipts');
    }
};
