<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The unified audit log (Phase 3, cross-cutting — spec item 84).
 *
 * The platform has no such table. Measured: there are narrow logs (error_logs, ai_setting_logs, the
 * vendor_bank_change_logs added earlier this stage), but nothing that answers, across the system,
 * "who did what, to which record, when, and what changed." The specification asks for exactly that,
 * and the financial core built this stage needs it: approving a settlement, paying a payout, and
 * reversing a refund are all consequential actions that currently leave no operator-visible trail
 * beyond the affected row itself.
 *
 * It is deliberately generic — actor, action, subject, before, after — so one engine serves every
 * module rather than each growing its own log. That is the spec's explicit instruction (item 82:
 * "Do not implement separate approval logic for every module"), applied to auditing.
 *
 * Append-only by discipline: nothing edits or deletes a row. A retention policy may prune old rows
 * later, but no code path rewrites one.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // --- who ---
            // Polymorphic actor: an admin, a seller, a customer, or null for a system/console action.
            $table->string('actor_type', 40)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 191)->nullable();   // captured so a deleted user still reads

            // --- what ---
            // A dotted action name: 'settlement.approved', 'payout.paid', 'refund.reversed'. The
            // prefix is the module, so the log filters by area without a separate column to drift.
            $table->string('action', 100);

            // --- to which record ---
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 140)->nullable();

            // --- what changed ---
            // Before/after as JSON. Null before = a creation; null after = a deletion. Only the
            // fields that changed need be stored, which keeps the row small and the diff readable.
            $table->json('before')->nullable();
            $table->json('after')->nullable();

            // Free-form context: an amount, a reference, a note — anything that makes the line
            // legible without opening the subject.
            $table->json('context')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'al_subject_index');
            $table->index(['actor_type', 'actor_id'], 'al_actor_index');
            $table->index('action', 'al_action_index');
            $table->index('created_at', 'al_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
