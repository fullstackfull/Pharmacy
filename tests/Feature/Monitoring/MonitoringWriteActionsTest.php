<?php

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\Alerting\AlertEvaluator;
use App\Services\Monitoring\Operations\MonitoringAlertRules;
use App\Services\Monitoring\Operations\MonitoringConfiguration;
use App\Services\Monitoring\Operations\MonitoringIncidents;
use App\Services\Monitoring\Operations\MonitoringJournal;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\MonitoringSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The console can act, not only report.
 *
 * It shipped GET-only, and the cost was uneven. Some sections merely could not be tuned; others
 * could not do their job at all — no alert rule could be written so nothing ever paged anyone, no
 * backup could be recorded so BackupCheck graded every cPanel install permanently degraded, and six
 * incident columns had no writer so there was no record of who took an incident or what caused it.
 *
 * These tests hold the writes and the rules that keep them safe: a key the running code does not
 * read back is refused rather than stored, an emptied field means "back to the shipped default"
 * rather than "zero", notes append rather than replace, and a cause is never inferred from a
 * timestamp.
 */
class MonitoringWriteActionsTest extends TestCase
{
    private const CONNECTION = 'monitoring_test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.' . self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        config()->set('monitoring.connection', self::CONNECTION);

        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->getPdo();

        foreach (glob(database_path('migrations/*_create_monitoring_*_tables.php')) as $migration) {
            (require $migration)->up();
        }

        app(MonitoringSettings::class)->forget();
    }

    // ────────────────────────────────────────────────────────────── settings

    public function test_a_threshold_can_be_changed_and_is_read_back(): void
    {
        $result = app(MonitoringConfiguration::class)->save(['thresholds.cpu_warning' => 91]);

        $this->assertArrayHasKey('thresholds.cpu_warning', $result['saved']);
        $this->assertSame(91.0, app(MonitoringSettings::class)->threshold('cpu_warning'));
    }

    /** A row for a key nothing reads back would be a control that silently does nothing. */
    public function test_a_key_the_running_code_ignores_is_refused_rather_than_stored(): void
    {
        $result = app(MonitoringConfiguration::class)->save(['tracing.sample_rate' => 0.5]);

        $this->assertSame([], $result['saved']);
        $this->assertSame(['tracing.sample_rate' => 'not_a_setting_the_running_code_reads_back'], $result['refused']);
    }

    public function test_a_value_outside_what_it_could_mean_is_refused(): void
    {
        $result = app(MonitoringConfiguration::class)->save(['thresholds.cpu_warning' => 400]);

        $this->assertSame([], $result['saved']);
        $this->assertArrayHasKey('thresholds.cpu_warning', $result['refused']);
    }

    /** Clearing a field is "go back to what shipped", which is not the same instruction as zero. */
    public function test_clearing_a_field_returns_the_shipped_default(): void
    {
        $configuration = app(MonitoringConfiguration::class);
        $configuration->save(['thresholds.cpu_warning' => 91]);

        $configuration->save(['thresholds.cpu_warning' => '']);

        $this->assertSame(
            (float) config('monitoring.thresholds.cpu_warning'),
            app(MonitoringSettings::class)->threshold('cpu_warning'),
        );
    }

    /** Retention was read straight from config, so a stored row was saved and then ignored. */
    public function test_a_retention_window_changed_here_is_the_one_the_platform_prunes_by(): void
    {
        app(MonitoringConfiguration::class)->save(['retention.trace_days' => 21]);

        $this->assertSame(21, app(MonitoringSettings::class)->retentionDays('trace_days', 3));
    }

    // ──────────────────────────────────────────────────────────── synthetics

    public function test_a_probe_can_be_added_and_removed(): void
    {
        $configuration = app(MonitoringConfiguration::class);

        $added = $configuration->addJourney('Home', 'https://example.test/', 200, null, null, 15);

        $this->assertTrue($added['ok']);
        $this->assertSame(['Home'], array_column($configuration->journeys(), 'name'));

        $this->assertTrue($configuration->removeJourney('Home'));
        $this->assertSame([], $configuration->journeys());
    }

    /** The same rule the check applies, applied at entry so the refusal names its reason. */
    public function test_a_probe_that_could_reach_cloud_metadata_is_refused(): void
    {
        $result = app(MonitoringConfiguration::class)->addJourney('Metadata', 'http://169.254.169.254/latest/', 200, null, null, 15);

        $this->assertFalse($result['ok']);
        $this->assertSame('only_http_urls_can_be_probed_and_never_a_cloud_metadata_address', $result['error']);
    }

    // ─────────────────────────────────────────────────────────────── journal

    public function test_a_backup_and_its_restore_test_are_recorded(): void
    {
        $journal = app(MonitoringJournal::class);

        $backup = $journal->recordBackup(['kind' => 'database', 'status' => 'success', 'destination' => '/backups/db.sql']);
        $this->assertTrue($backup['ok']);

        $tested = $journal->recordRestoreTest(null, false, 'restored to staging');
        $this->assertTrue($tested['ok']);
        $this->assertSame($backup['id'], $tested['id']);

        $row = DB::connection(self::CONNECTION)->table('monitoring_backups')->find($backup['id']);
        $this->assertNotNull($row->restore_tested_at);
        $this->assertSame('restored to staging', $row->restore_test_result);
    }

    /** A test of a backup that is not there would be a test of nothing. */
    public function test_a_restore_test_with_no_backup_behind_it_is_refused(): void
    {
        $result = app(MonitoringJournal::class)->recordRestoreTest(null, false, null);

        $this->assertFalse($result['ok']);
        $this->assertSame('no_backup_to_mark_as_restore_tested', $result['error']);
    }

    public function test_a_deployment_is_recorded_and_lands_on_the_timeline(): void
    {
        $result = app(MonitoringJournal::class)->recordDeployment(['release' => '2.4.1', 'status' => 'success']);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, DB::connection(self::CONNECTION)->table('monitoring_deployments')->where('release', '2.4.1')->count());
        $this->assertSame(1, DB::connection(self::CONNECTION)->table('monitoring_events')->where('type', 'deploy')->count());
    }

    public function test_a_note_reaches_the_timeline_and_an_empty_one_does_not(): void
    {
        $journal = app(MonitoringJournal::class);

        $this->assertTrue($journal->annotate('Supplier import started'));
        $this->assertFalse($journal->annotate('   '));

        $this->assertSame(1, DB::connection(self::CONNECTION)->table('monitoring_events')->where('type', 'annotation')->count());
    }

    // ───────────────────────────────────────────────────────────── incidents

    public function test_an_incident_can_be_taken_noted_attributed_and_closed(): void
    {
        $id = $this->openIncident();
        $incidents = app(MonitoringIncidents::class);

        $this->assertTrue($incidents->acknowledge($id, 'Rana'));
        $this->assertFalse($incidents->acknowledge($id, 'Rana'), 'acknowledging twice must not restamp it');

        $this->assertTrue($incidents->note($id, 'restarted the worker', 'Rana'));
        $this->assertTrue($incidents->note($id, 'that did not help', 'Rana'));

        $this->assertTrue($incidents->attribute($id, 'queue worker died', 'oldest job 40 minutes', null, 'Rana'));
        $this->assertTrue($incidents->resolve($id, 'Rana', 7));

        $row = DB::connection(self::CONNECTION)->table('monitoring_incidents')->find($id);

        $this->assertNotNull($row->acknowledged_at);
        $this->assertSame('queue worker died', $row->probable_cause);
        $this->assertSame('resolved', $row->status);
        $this->assertSame(7, (int) $row->resolved_by);

        // Appended, never replaced: the attempt that did not work is usually the one worth reading.
        $this->assertStringContainsString('restarted the worker', $row->notes);
        $this->assertStringContainsString('that did not help', $row->notes);
    }

    /** A cause is offered from the deploys nearby and chosen by a person, never inferred. */
    public function test_only_deploys_near_the_incident_are_offered_as_causes(): void
    {
        $id = $this->openIncident(startedAt: Clock::now()->copy()->subMinutes(10));

        $this->insertDeployment('near', Clock::now()->copy()->subMinutes(15));
        $this->insertDeployment('far', Clock::now()->copy()->subDays(3));

        $candidates = array_column(app(MonitoringIncidents::class)->candidateDeployments($id), 'release');

        $this->assertSame(['near'], $candidates);
    }

    // ─────────────────────────────────────────────────────────── alert rules

    public function test_a_rule_can_be_created_silenced_and_deleted(): void
    {
        $rules = app(MonitoringAlertRules::class);

        $created = $rules->save('queue.depth', [
            'name' => 'Queue is backing up',
            'metric' => 'queue.pending',
            'warning_threshold' => 100,
            'enabled' => true,
        ]);
        $this->assertTrue($created['ok']);

        $this->assertTrue($rules->setEnabled('queue.depth', false));
        $this->assertSame(0, (int) DB::connection(self::CONNECTION)->table('monitoring_alert_rules')->where('key', 'queue.depth')->value('enabled'));

        $this->assertTrue($rules->delete('queue.depth'));
        $this->assertSame(0, DB::connection(self::CONNECTION)->table('monitoring_alert_rules')->where('key', 'queue.depth')->count());
    }

    /** A rule with no threshold can never fire, and would look like cover it is not providing. */
    public function test_a_rule_with_neither_threshold_is_refused(): void
    {
        $result = app(MonitoringAlertRules::class)->save('nothing', ['name' => 'Nothing', 'metric' => 'x']);

        $this->assertFalse($result['ok']);
        $this->assertSame('a_rule_needs_a_warning_or_a_critical_threshold', $result['error']);
    }

    /** The scheduled evaluator ran without --seed, so a fresh install evaluated zero rules forever. */
    public function test_the_shipped_rules_install_themselves_on_the_first_evaluation(): void
    {
        $this->assertSame(0, DB::connection(self::CONNECTION)->table('monitoring_alert_rules')->count());

        app(AlertEvaluator::class)->seedDefaults();

        $this->assertGreaterThan(0, DB::connection(self::CONNECTION)->table('monitoring_alert_rules')->count());
    }

    /** An alert that only reaches laravel.log is a log line, not an alert. */
    public function test_the_shipped_rules_are_created_wanting_to_reach_a_person(): void
    {
        app(AlertEvaluator::class)->seedDefaults();

        $this->assertSame(
            0,
            DB::connection(self::CONNECTION)->table('monitoring_alert_rules')->where('notify_email', false)->count(),
            'a shipped rule was created with email off and no screen used to exist to turn it on',
        );
    }

    private function openIncident(?\Illuminate\Support\Carbon $startedAt = null): int
    {
        $startedAt ??= Clock::now();

        return (int) DB::connection(self::CONNECTION)->table('monitoring_incidents')->insertGetId([
            'reference' => 'INC-1',
            'title' => 'Queue is backing up',
            'severity' => 'critical',
            'status' => 'open',
            'started_at' => $startedAt->toDateTimeString(),
            'detected_at' => $startedAt->toDateTimeString(),
            'created_at' => Clock::stamp(),
            'updated_at' => Clock::stamp(),
        ]);
    }

    private function insertDeployment(string $release, \Illuminate\Support\Carbon $at): void
    {
        DB::connection(self::CONNECTION)->table('monitoring_deployments')->insert([
            'release' => $release,
            'environment' => 'testing',
            'status' => 'success',
            'deployed_at' => $at->toDateTimeString(),
            'created_at' => Clock::stamp(),
            'updated_at' => Clock::stamp(),
        ]);
    }
}
