<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product moderation history (Phase 3, Stage A — spec item 7).
 *
 * What exists today, measured: `products.request_status` is a single tinyint (0 pending, 1 approved,
 * 2 denied) and `products.denied_note` is one text field that the next decision overwrites. So there
 * is no history — you cannot see who rejected a product, when, why, or how many times it went back
 * and forth — no structured reasons, and no distinction between "rejected outright" and "needs a
 * change and can be resubmitted."
 *
 * This table is the append-only record of every moderation decision. It sits ALONGSIDE
 * `request_status`, which the storefront and the Flutter apps read — the service keeps that field in
 * sync so nothing that depends on it changes, while this adds the trail the spec asks for.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_moderation_events')) {
            return;
        }

        Schema::create('product_moderation_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');

            // approved | rejected | needs_changes | suspended | resubmitted
            $table->string('action', 30);

            // Structured reason codes, so a seller sees "missing image, price issue" as data rather
            // than free prose, and the marketplace can report on why products are rejected.
            $table->json('reason_codes')->nullable();

            // The moderator's free-text note, kept per event rather than overwritten.
            $table->text('note')->nullable();

            // The request_status this decision moved the product from and to, so the history is
            // self-contained and reconcilable against the live column.
            $table->tinyInteger('previous_request_status')->nullable();
            $table->tinyInteger('new_request_status')->nullable();

            $table->string('moderator_type', 20)->nullable();   // admin | system
            $table->unsignedBigInteger('moderator_id')->nullable();
            $table->string('moderator_name', 191)->nullable();

            $table->timestamps();

            $table->index('product_id', 'pme_product_index');
            $table->index('action', 'pme_action_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_moderation_events');
    }
};
