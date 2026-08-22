<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operations half of the monitoring store: the things an operator acts on rather than charts.
 *
 * Health checks and their history, the scheduler's real heartbeat, alert rules and the state
 * machine that stops them spamming, incidents that group many signals into one problem, deploys to
 * mark the charts with, backups, and the settings the whole system reads its thresholds from.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('monitoring.connection', 'monitoring');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        /*
        | One row per check per run: liveness probes, synthetic journeys, SSL expiry, backup age.
        | The history is what makes uptime, MTTD and MTTR computable rather than claimed.
        */
        if (!$schema->hasTable('monitoring_check_results')) {
            $schema->create('monitoring_check_results', function (Blueprint $table) {
                $table->id();
                // Stable key: database, redis, queue, scheduler, storage, ssl, homepage, api.health…
                $table->string('check_key', 64)->index();
                // health | synthetic — a probe of a component vs a scripted user journey.
                $table->string('kind', 12)->default('health');
                // ok | degraded | failing | unknown | not_configured | not_supported
                $table->string('status', 16);
                $table->unsignedInteger('duration_ms')->nullable();
                $table->string('detail', 191)->nullable();
                $table->json('context')->nullable();
                $table->timestamp('checked_at')->index();

                $table->index(['check_key', 'checked_at'], 'monitoring_check_history');
            });
        }

        /*
        | The scheduler's real heartbeat — per task, not one global timestamp.
        |
        | The old dashboard read a single `scheduler_last_run_at` setting, so a cron that fired but
        | whose settlement command had been failing for a week still showed green. Every scheduled
        | task now records its own run, its duration and its outcome, and a task that SHOULD have
        | run and did not is detectable because its expected next run is stored with it.
        */
        if (!$schema->hasTable('monitoring_scheduled_runs')) {
            $schema->create('monitoring_scheduled_runs', function (Blueprint $table) {
                $table->id();
                $table->string('task', 191)->index();            // the command or the closure's name
                $table->string('expression', 40)->nullable();    // the cron expression
                // running | success | failed | skipped
                $table->string('status', 12)->default('running');
                $table->unsignedInteger('duration_ms')->nullable();
                $table->text('output')->nullable();              // truncated + redacted
                $table->text('error')->nullable();
                $table->timestamp('started_at')->index();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('expected_next_at')->nullable();

                $table->index(['task', 'started_at'], 'monitoring_scheduled_task_history');
            });
        }

        /*
        | Alert rules, edited in Monitoring → Settings. A rule is a metric, a comparison and two
        | thresholds; everything else here exists to stop it becoming noise.
        */
        if (!$schema->hasTable('monitoring_alert_rules')) {
            $schema->create('monitoring_alert_rules', function (Blueprint $table) {
                $table->id();
                $table->string('key', 96)->unique();             // stable id, e.g. cpu.high
                $table->string('name', 191);
                $table->string('metric', 96);                    // matches monitoring_series.metric
                $table->string('label', 96)->default('');
                $table->string('operator', 4)->default('>');     // > | < | >= | <= | ==
                $table->double('warning_threshold')->nullable();
                $table->double('critical_threshold')->nullable();
                // How long the condition must hold before it fires — the single most effective
                // defence against alerting on one unlucky sample.
                $table->unsignedInteger('for_seconds')->default(120);
                // How long after firing before it may fire again.
                $table->unsignedInteger('cooldown_seconds')->default(900);
                // Where it stops being a problem; below the firing threshold on purpose, so a
                // metric hovering on the line does not flap between alert and recovery.
                $table->double('recovery_threshold')->nullable();
                $table->boolean('enabled')->default(true);
                $table->boolean('notify_email')->default(false);
                $table->string('notify_channels', 191)->nullable();
                $table->string('description', 191)->nullable();
                $table->timestamps();
            });
        }

        /*
        | The live state of each rule. Separate from the rule so editing a threshold does not lose
        | the fact that something is currently on fire.
        */
        if (!$schema->hasTable('monitoring_alert_states')) {
            $schema->create('monitoring_alert_states', function (Blueprint $table) {
                $table->id();
                $table->string('rule_key', 96)->unique();
                // ok | pending | warning | critical
                $table->string('state', 12)->default('ok');
                $table->double('last_value')->nullable();
                $table->timestamp('breached_since')->nullable(); // when the condition first held
                $table->timestamp('fired_at')->nullable();
                $table->timestamp('recovered_at')->nullable();
                $table->timestamp('last_notified_at')->nullable();
                $table->unsignedInteger('fire_count')->default(0);
                $table->unsignedBigInteger('incident_id')->nullable();
                $table->timestamps();
            });
        }

        /*
        | Incidents: many signals, one problem.
        |
        | Twenty alerts about one database stall is twenty times the noise and none of the
        | insight. Signals attach to an incident, the incident carries the timeline, and the
        | correlation engine writes its best guess at the cause into it.
        */
        if (!$schema->hasTable('monitoring_incidents')) {
            $schema->create('monitoring_incidents', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 16)->unique();       // INC-00041
                $table->string('title', 191);
                // critical | major | minor | warning
                $table->string('severity', 12)->default('minor');
                // open | investigating | monitoring | resolved
                $table->string('status', 16)->default('open');
                $table->json('affected_services')->nullable();
                $table->json('signals')->nullable();             // what fired, with values
                $table->string('probable_cause', 191)->nullable();
                $table->text('cause_evidence')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('deployment_id')->nullable();
                $table->timestamp('started_at')->index();
                $table->timestamp('detected_at')->nullable();    // for MTTD
                $table->timestamp('acknowledged_at')->nullable();
                $table->timestamp('resolved_at')->nullable();    // for MTTR
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->timestamps();

                $table->index(['status', 'started_at'], 'monitoring_incident_open');
            });
        }

        /*
        | Deploys, so every chart can be marked with "this is when the code changed" — the single
        | most useful annotation there is when something got worse at a specific minute.
        */
        if (!$schema->hasTable('monitoring_deployments')) {
            $schema->create('monitoring_deployments', function (Blueprint $table) {
                $table->id();
                $table->string('release', 40)->index();          // version.json or the commit SHA
                $table->string('commit_sha', 40)->nullable();
                $table->string('branch', 96)->nullable();
                $table->string('environment', 24)->default('production');
                $table->string('deployed_by', 96)->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->unsignedInteger('migrations_run')->nullable();
                // success | failed | unknown
                $table->string('status', 12)->default('unknown');
                $table->text('notes')->nullable();
                // Filled in by the comparison job: p95, error rate, DB latency before and after.
                $table->json('before_metrics')->nullable();
                $table->json('after_metrics')->nullable();
                $table->timestamp('deployed_at')->index();
                $table->timestamps();
            });
        }

        /*
        | Backups. A backup nobody has restored is a hope, not a backup, which is why the last
        | successful RESTORE TEST is a first-class column here rather than a note somewhere.
        */
        if (!$schema->hasTable('monitoring_backups')) {
            $schema->create('monitoring_backups', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 16)->default('database'); // database | files
                $table->string('status', 12)->default('success'); // success | failed
                $table->string('destination', 191)->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('started_at')->index();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('restore_tested_at')->nullable();
                $table->string('restore_test_result', 191)->nullable();
                $table->timestamps();
            });
        }

        /*
        | Monitoring's own settings: thresholds, retention, the electricity price, privacy
        | switches. In the database rather than in .env so an operator can change them from the
        | panel without a deploy — config/monitoring.php holds the defaults a fresh install starts
        | from.
        */
        if (!$schema->hasTable('monitoring_settings')) {
            $schema->create('monitoring_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 96)->unique();
                $table->text('value')->nullable();
                $table->string('type', 12)->default('string');   // string|int|float|bool|json
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach ([
            'monitoring_settings',
            'monitoring_backups',
            'monitoring_deployments',
            'monitoring_incidents',
            'monitoring_alert_states',
            'monitoring_alert_rules',
            'monitoring_scheduled_runs',
            'monitoring_check_results',
        ] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
