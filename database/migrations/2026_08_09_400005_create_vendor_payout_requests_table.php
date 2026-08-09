<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller-initiated payout requests, and the audit of bank-detail changes (Phase 3, Stage B).
 *
 * The platform already has `withdraw_requests`, but it runs over `seller_wallets` — the five mutable
 * decimals — and checks nothing against a real available balance. This runs over the ledger built in
 * this stage: a payout can only ask for what the ledger says is *available*, and asking reserves it,
 * so the same money cannot be requested twice or settled out from under a pending payout.
 *
 * The second table is the security control the specification asks for by name: every change to a
 * seller's bank details is logged, and a change starts a cooling period during which payouts are
 * blocked. That is the standard defence against an attacker who takes over a seller account and
 * immediately redirects the money — the delay gives the real seller time to notice.
 *
 * Additive and reversible: new tables only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('vendor_payout_requests')) {
            Schema::create('vendor_payout_requests', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 60)->unique();

                $table->unsignedBigInteger('seller_id');
                $table->string('seller_is', 20)->default('seller');

                $table->decimal('amount', 24, 4);
                $table->string('currency', 10)->nullable();

                // requested -> under_review -> approved -> processing -> paid
                //                                       \-> rejected  \-> failed
                $table->string('status', 20)->default('requested');

                // How the seller wants the money, captured at request time so a later bank change
                // does not silently redirect a payout already in flight.
                $table->string('method', 40)->nullable();
                $table->json('method_details')->nullable();

                // The ledger entry that reserved this amount, so the reservation can be released if
                // the request is rejected or fails.
                $table->unsignedBigInteger('reserve_entry_id')->nullable();
                // The ledger entry that actually paid it out, written when it is marked paid.
                $table->unsignedBigInteger('payout_entry_id')->nullable();

                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->string('payment_reference', 191)->nullable();

                $table->timestamps();

                $table->index(['seller_id', 'seller_is'], 'vpr_seller_index');
                $table->index('status', 'vpr_status_index');
            });
        }

        if (!Schema::hasTable('vendor_bank_change_logs')) {
            Schema::create('vendor_bank_change_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id');

                // What changed — kept as before/after so a fraud review can see exactly what the
                // account was redirected from and to, without reconstructing it from anywhere else.
                $table->json('previous')->nullable();
                $table->json('current')->nullable();

                // Payouts are blocked until this time. The cooling window, made concrete.
                $table->timestamp('cooling_until')->nullable();

                $table->unsignedBigInteger('changed_by')->nullable();
                $table->string('changed_by_type', 20)->nullable();
                $table->string('ip_address', 45)->nullable();

                $table->timestamps();

                $table->index('seller_id', 'vbcl_seller_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_bank_change_logs');
        Schema::dropIfExists('vendor_payout_requests');
    }
};
