<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reusable approval / maker-checker engine (Phase 3 — spec item 82).
 *
 * The specification is explicit: "Create reusable approval workflow architecture... Do not implement
 * separate approval logic for every module." And the evidence that it is needed is in this codebase:
 * maker-checker approval has now been written by hand four times this stage — settlement approve,
 * settlement pay, payout approve, payout pay — each with its own status field and its own guard.
 *
 * This table is a single approval record that can hang off ANY subject: a settlement, a payout, a
 * refund above a threshold, a price change, a product, a seller application. One engine, one audit
 * trail, one place to enforce "the person who requests cannot be the person who approves."
 *
 * It deliberately does not replace the existing status fields on settlements and payouts — those
 * stay as they are so nothing breaks. This runs alongside as the general mechanism new workflows use
 * and old ones can adopt gradually, which is the phase's stated migration strategy.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('approval_requests')) {
            return;
        }

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 60)->unique();

            // What kind of thing needs approving — 'refund', 'payout', 'price_change', 'seller'...
            // The type drives which policy (e.g. a threshold) applies, without a column per workflow.
            $table->string('workflow', 60);

            // The subject the approval is about, polymorphic so one table serves every module.
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 140)->nullable();

            // pending -> approved | rejected | cancelled
            $table->string('status', 20)->default('pending');

            // The money or magnitude the decision is about, when there is one — so a threshold policy
            // ("over 1000 needs two approvers") has something to compare against.
            $table->decimal('amount', 24, 4)->nullable();
            $table->string('currency', 10)->nullable();

            // How many distinct approvers this request needs. 1 is ordinary maker-checker; 2 is the
            // dual-control the spec asks for on large refunds, payouts and bank changes.
            $table->unsignedTinyInteger('required_approvals')->default(1);
            $table->unsignedTinyInteger('approvals_count')->default(0);

            // --- the maker ---
            $table->string('requested_by_type', 20)->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->text('request_note')->nullable();

            // Free-form payload describing what will happen on approval, so the approver sees the
            // change without opening the subject, and so the action can be replayed on approval.
            $table->json('payload')->nullable();

            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['workflow', 'status'], 'apr_workflow_status_index');
            $table->index(['subject_type', 'subject_id'], 'apr_subject_index');
        });

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('approval_request_id');

            // approve | reject
            $table->string('decision', 20);

            // Who decided. The engine refuses to let this be the maker, and refuses to let one person
            // count twice — the two rules that make dual-control real rather than cosmetic.
            $table->string('actor_type', 20)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 191)->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            // One decision per approver per request — the database enforces "cannot approve twice."
            $table->unique(['approval_request_id', 'actor_type', 'actor_id'], 'apd_one_per_actor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_requests');
    }
};
