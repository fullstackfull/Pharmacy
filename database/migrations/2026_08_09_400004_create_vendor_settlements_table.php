<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor settlements (Phase 3, Stage B).
 *
 * A settlement is a period drawn across the ledger: it claims the entries that are payable, sums
 * them, and freezes the result. It deliberately does **not** re-derive anything from orders — the
 * ledger already holds every financial event with its own balance, and re-deriving would mean two
 * sources of truth that can disagree.
 *
 * The claim is what makes it safe: each ledger entry carries `settlement_id`, set when a settlement
 * takes it. An entry can therefore belong to exactly one settlement, and the same earning can never
 * be paid twice — enforced by the database rather than by remembering.
 *
 * Once approved the totals are immutable. A correction is a new ledger entry that the *next*
 * settlement picks up, never an edit to an approved one, because a restated settlement cannot be
 * reconciled against the payout that was already made from it.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_settlements')) {
            return;
        }

        Schema::create('vendor_settlements', function (Blueprint $table) {
            $table->id();

            // Human-facing reference, unique so it can be quoted in correspondence.
            $table->string('reference', 60)->unique();

            $table->unsignedBigInteger('seller_id');
            $table->string('seller_is', 20)->default('seller');

            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            // Opening balance: what the ledger said before this period's entries. Stored so a
            // statement reads opening -> movements -> closing without re-summing all history.
            $table->decimal('opening_balance', 24, 4)->default(0);
            $table->decimal('total_credits', 24, 4)->default(0);
            $table->decimal('total_debits', 24, 4)->default(0);
            $table->decimal('net_amount', 24, 4)->default(0);
            $table->decimal('closing_balance', 24, 4)->default(0);

            $table->unsignedInteger('entry_count')->default(0);
            $table->string('currency', 10)->nullable();

            // draft | calculated | under_review | approved | paid | cancelled
            $table->string('status', 20)->default('draft');

            $table->timestamp('calculated_at')->nullable();

            // Approval is the point after which the totals stop moving, so who and when are kept.
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payout_reference', 191)->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'seller_is'], 'vs_seller_index');
            $table->index('status', 'vs_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_settlements');
    }
};
