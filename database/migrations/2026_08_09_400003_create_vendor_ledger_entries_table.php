<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vendor financial ledger (Phase 3, Stage B).
 *
 * What exists today, measured: `seller_wallets` holds five mutable decimals — total_earning,
 * withdrawn, commission_given, pending_withdraw, collected_cash — updated in place with
 * `->update([...])`. `seller_wallet_histories` is a flat log: seller, amount, order, product,
 * payment. No transaction type, no running balance, no status, no currency.
 *
 * So today you cannot answer, from the data alone:
 *   - why is this seller's balance what it is?
 *   - which of it is still inside the return window and which is genuinely theirs?
 *   - what did the balance read immediately before a given payout?
 *
 * This table answers all three. Every financial event is one immutable row carrying its own
 * balance_after, so the balance is a *consequence* of the entries rather than a number somebody
 * updated. Corrections are new entries, never edits — an edited ledger is not a ledger.
 *
 * `seller_wallets` is deliberately left untouched and still maintained by the existing code. This
 * runs alongside it until the reads are moved over, which is the migration strategy the phase
 * specification asks for: build new, backfill, verify, switch gradually.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_ledger_entries')) {
            return;
        }

        Schema::create('vendor_ledger_entries', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('seller_id');
            $table->string('seller_is', 20)->default('seller');

            // order_earning | commission_charge | refund | return_adjustment | shipping_fee
            // | fulfilment_fee | penalty | bonus | manual_adjustment | payout | settlement
            $table->string('entry_type', 40);

            // Debit reduces what the marketplace owes the seller; credit increases it. Both columns
            // rather than one signed amount, because a ledger that can be read at a glance is worth
            // more than a column saved, and it makes a sign error visible instead of silent.
            $table->decimal('debit', 24, 4)->default(0);
            $table->decimal('credit', 24, 4)->default(0);

            // The running balance as of this entry. Written under a row lock so concurrent events
            // cannot both read the same previous balance — the same lost-update class of bug fixed
            // for stock in Phase 2.
            $table->decimal('balance_after', 24, 4)->default(0);

            // pending   — earned, still inside the return window
            // available — settled and payable
            // reserved  — earmarked for a payout in flight
            // paid      — left the marketplace
            $table->string('status', 20)->default('pending');

            // When a pending entry becomes available. Null means "not on a timer".
            $table->timestamp('available_at')->nullable();

            $table->string('currency', 10)->nullable();

            // What this entry is about: order, order_details, settlement, payout, refund...
            $table->string('reference_type', 40)->nullable();
            $table->string('reference_id', 150)->nullable();

            $table->string('description', 512)->nullable();

            // Who caused it. Null for system-generated entries.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_type', 20)->nullable();

            // Set when a settlement consumes this entry, so the same earning cannot be paid twice.
            $table->unsignedBigInteger('settlement_id')->nullable();

            $table->timestamps();

            $table->index(['seller_id', 'seller_is'], 'vle_seller_index');
            $table->index(['seller_id', 'status'], 'vle_seller_status_index');
            $table->index(['reference_type', 'reference_id'], 'vle_reference_index');
            $table->index('settlement_id', 'vle_settlement_index');

            // Idempotency: one entry per (seller, type, reference). A retried order transaction or a
            // webhook delivered twice cannot credit the same earning again.
            $table->unique(['seller_id', 'entry_type', 'reference_type', 'reference_id'], 'vle_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_ledger_entries');
    }
};
