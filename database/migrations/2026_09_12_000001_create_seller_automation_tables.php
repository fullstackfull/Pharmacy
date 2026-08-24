<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rules a seller writes, and the complete record of what those rules did.
 *
 * A rule that changes a shop without leaving a trail is worse than no rule at all: the seller finds
 * a listing hidden, cannot tell whether they did it, whether a colleague did it, or whether the
 * marketplace did — and the only safe response is to stop trusting automation entirely. So three
 * tables rather than one: the rule, every time it ran, and every individual thing it touched with
 * the value before and after.
 *
 * The run table exists separately from the action table because "the rule ran and matched nothing"
 * is itself a fact worth keeping. A seller looking at a rule that has done nothing for a week needs
 * to know whether it is working and finding nothing, or quietly broken.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('seller_automation_rules')) {
            Schema::create('seller_automation_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id');

                /** Who wrote it, when it was staff rather than the owner. */
                $table->unsignedBigInteger('created_by_staff_id')->nullable();

                $table->string('name', 160);

                /** Which trigger selects the subjects, e.g. out_of_stock. */
                $table->string('trigger', 60);

                /** What is done to each subject, e.g. hide_listing. */
                $table->string('action', 60);

                /** The trigger's own settings — thresholds, windows. Validated against the trigger. */
                $table->json('trigger_settings')->nullable();

                /** The action's own settings. Validated against the action. */
                $table->json('action_settings')->nullable();

                /** active | paused | suspended — suspended is the breaker, and only a person clears it. */
                $table->string('status', 20)->default('active');

                /**
                 * The ceiling on one run.
                 *
                 * A rule that would touch more of the catalogue than this does nothing at all and
                 * trips the breaker instead. Applying half of a change the seller did not intend is
                 * the worst of both outcomes.
                 */
                $table->unsignedInteger('max_actions_per_run')->default(50);

                /** How long to wait between runs, so a rule cannot thrash. */
                $table->unsignedInteger('cooldown_minutes')->default(15);

                $table->timestamp('last_run_at')->nullable();
                $table->timestamp('last_fired_at')->nullable();
                $table->unsignedInteger('run_count')->default(0);
                $table->unsignedInteger('applied_count')->default(0);

                /** Consecutive failures. Reset by a clean run, not by time. */
                $table->unsignedInteger('consecutive_failures')->default(0);

                $table->timestamp('suspended_at')->nullable();
                $table->string('suspension_reason', 191)->nullable();
                $table->timestamps();

                $table->index(['seller_id', 'status'], 'sar_seller_status_idx');
                $table->index(['status', 'last_run_at'], 'sar_status_run_idx');
            });
        }

        if (!Schema::hasTable('seller_automation_runs')) {
            Schema::create('seller_automation_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('rule_id');
                $table->unsignedBigInteger('seller_id');

                /** applied | no_match | capped | failed — what this run amounted to. */
                $table->string('outcome', 20);

                $table->unsignedInteger('matched_count')->default(0);
                $table->unsignedInteger('applied_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);

                /** True when nothing was written — a preview the seller asked for. */
                $table->boolean('dry_run')->default(false);

                $table->text('message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['rule_id', 'created_at'], 'sarun_rule_time_idx');
                $table->index(['seller_id', 'created_at'], 'sarun_seller_time_idx');
            });
        }

        if (!Schema::hasTable('seller_automation_actions')) {
            Schema::create('seller_automation_actions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('run_id');
                $table->unsignedBigInteger('rule_id');
                $table->unsignedBigInteger('seller_id');

                /** What was acted on: product, order. */
                $table->string('subject_type', 40);
                $table->unsignedBigInteger('subject_id');

                /** A label captured at the time, so the trail still reads after a rename. */
                $table->string('subject_label', 191)->nullable();

                $table->string('action', 60);

                /** applied | skipped | failed */
                $table->string('status', 20);

                /** Why it was skipped or how it failed. Never a shrug. */
                $table->string('reason', 191)->nullable();

                $table->json('before')->nullable();
                $table->json('after')->nullable();

                /** Set when a seller undoes this action from the trail. */
                $table->timestamp('reverted_at')->nullable();
                $table->timestamps();

                $table->index(['seller_id', 'created_at'], 'saact_seller_time_idx');
                $table->index(['rule_id', 'created_at'], 'saact_rule_time_idx');
                $table->index(['subject_type', 'subject_id'], 'saact_subject_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_automation_actions');
        Schema::dropIfExists('seller_automation_runs');
        Schema::dropIfExists('seller_automation_rules');
    }
};
