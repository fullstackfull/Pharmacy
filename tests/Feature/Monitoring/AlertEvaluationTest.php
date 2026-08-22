<?php

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\Alerting\AlertEvaluator;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\MonitoringSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The alert engine decides when somebody gets woken up, so the ways it can be wrong are the ways
 * monitoring becomes worse than nothing: an alert that fires on a metric that stopped arriving, or
 * one that flaps every minute, teaches everybody to ignore the one that matters.
 */
class AlertEvaluationTest extends TestCase
{
    private const CONNECTION = 'monitoring_test';

    protected function setUp(): void
    {
        parent::setUp();

        // The monitoring tables on their own sqlite database, built from the real migrations, so
        // this exercises the shipped schema rather than a hand-written stand-in.
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

        $this->assertTrue(Schema::connection(self::CONNECTION)->hasTable('monitoring_alert_rules'));
    }

    public function test_a_metric_that_stopped_arriving_does_not_fire(): void
    {
        // The single most important behaviour here. Absent is not zero: a collector that died must
        // show up as "monitoring is blind", never as "every metric reads 0 and all is well".
        $this->rule(['key' => 'cpu', 'metric' => 'server.cpu.usage_pct', 'operator' => '<', 'warning_threshold' => 50]);

        $outcome = $this->evaluateOnce('cpu');

        $this->assertSame('ok', $outcome['state']);
        $this->assertSame('no data in the window', $outcome['note']);
        $this->assertNull($outcome['value']);
    }

    public function test_one_bad_sample_does_not_fire_before_the_condition_has_held(): void
    {
        $this->rule(['key' => 'cpu', 'metric' => 'server.cpu.usage_pct', 'warning_threshold' => 80, 'for_seconds' => 300]);
        $this->sample('server.cpu.usage_pct', 95, minutesAgo: 0);

        $outcome = $this->evaluateOnce('cpu');

        $this->assertSame('pending', $outcome['state'], 'a single spike was treated as an outage');
        $this->assertSame(95.0, $outcome['value']);
    }

    public function test_a_condition_that_holds_for_its_whole_window_fires(): void
    {
        $this->rule(['key' => 'cpu', 'metric' => 'server.cpu.usage_pct', 'warning_threshold' => 80, 'for_seconds' => 120]);

        foreach ([3, 2, 1, 0] as $minutesAgo) {
            $this->sample('server.cpu.usage_pct', 95, $minutesAgo);
        }

        // First pass records when the breach began; the state machine needs a prior state to
        // measure "has held for" against, exactly as it does minute to minute in production.
        $this->evaluateOnce('cpu');
        $this->backdateBreach('cpu', minutes: 5);
        $outcome = $this->evaluateOnce('cpu');

        $this->assertSame('warning', $outcome['state']);

        $state = DB::connection(self::CONNECTION)->table('monitoring_alert_states')->where('rule_key', 'cpu')->first();
        $this->assertNotNull($state->fired_at);
        $this->assertSame(1, (int) $state->fire_count);
        $this->assertNotNull($state->incident_id, 'a firing rule should have opened an incident');
    }

    public function test_critical_wins_over_warning_only_when_critical_itself_holds(): void
    {
        $this->rule([
            'key' => 'cpu',
            'metric' => 'server.cpu.usage_pct',
            'warning_threshold' => 80,
            'critical_threshold' => 95,
            'for_seconds' => 120,
        ]);

        // Above warning throughout, above critical for one minute only.
        $this->sample('server.cpu.usage_pct', 85, 2);
        $this->sample('server.cpu.usage_pct', 99, 1);
        $this->sample('server.cpu.usage_pct', 85, 0);

        $this->evaluateOnce('cpu');
        $this->backdateBreach('cpu', minutes: 5);

        $this->assertSame('warning', $this->evaluateOnce('cpu')['state']);
    }

    public function test_a_metric_hovering_on_the_line_does_not_flap(): void
    {
        // Recovery is deliberately inside the firing threshold. Without it, a metric sitting on 80
        // alternates between firing and recovering every single minute.
        $this->rule([
            'key' => 'cpu',
            'metric' => 'server.cpu.usage_pct',
            'warning_threshold' => 80,
            'recovery_threshold' => 70,
            'for_seconds' => 60,
        ]);

        $this->sample('server.cpu.usage_pct', 95, 1);
        $this->sample('server.cpu.usage_pct', 95, 0);
        $this->evaluateOnce('cpu');
        $this->backdateBreach('cpu', minutes: 5);
        $this->assertSame('warning', $this->evaluateOnce('cpu')['state']);

        // Back under the firing threshold, still above recovery: still an alert.
        $this->clearSamples();
        $this->sample('server.cpu.usage_pct', 75, 0);
        $outcome = $this->evaluateOnce('cpu');
        $this->assertSame('warning', $outcome['state']);
        $this->assertSame('below threshold but not yet recovered', $outcome['note']);

        // Properly back: recovered, and the incident closes with it.
        $this->clearSamples();
        $this->sample('server.cpu.usage_pct', 40, 0);
        $this->assertSame('recovered', $this->evaluateOnce('cpu')['note']);

        $incident = DB::connection(self::CONNECTION)->table('monitoring_incidents')->first();
        $this->assertSame('resolved', $incident->status);
        $this->assertNotNull($incident->resolved_at, 'without a resolved_at there is no time-to-recover to measure');
    }

    public function test_the_worst_label_decides_an_unlabelled_rule(): void
    {
        // Two disks, one nearly full. Averaging them would report a healthy 50% while the server
        // is minutes from stopping.
        $this->rule(['key' => 'disk', 'metric' => 'server.disk.used_pct', 'warning_threshold' => 80, 'for_seconds' => 60]);

        foreach ([1, 0] as $minutesAgo) {
            $this->sample('server.disk.used_pct', 12, $minutesAgo, label: '/dev/vdb');
            $this->sample('server.disk.used_pct', 94, $minutesAgo, label: '/dev/vda');
        }

        $this->evaluateOnce('disk');
        $this->backdateBreach('disk', minutes: 5);
        $outcome = $this->evaluateOnce('disk');

        $this->assertSame('warning', $outcome['state']);
        $this->assertSame(94.0, $outcome['value']);
    }

    public function test_a_gap_in_the_data_does_not_count_towards_the_hold_time(): void
    {
        // Rule 2 asks that a breach held CONTINUOUSLY. The clock used to keep running through an
        // outage, so after a collector came back a single breaching sample was treated as five
        // minutes of sustained breach and paged immediately.
        $this->rule(['key' => 'cpu', 'metric' => 'server.cpu.usage_pct', 'warning_threshold' => 80, 'for_seconds' => 300]);

        $this->sample('server.cpu.usage_pct', 95, 0);
        $this->assertSame('pending', $this->evaluateOnce('cpu')['state']);

        // The collector stops. Five minutes pass with nothing recorded.
        $this->clearSamples();
        $this->backdateBreach('cpu', minutes: 5);
        $outcome = $this->evaluateOnce('cpu');

        $this->assertSame('ok', $outcome['state'], 'silence is not a breach');
        $this->assertNull(
            DB::connection(self::CONNECTION)->table('monitoring_alert_states')->where('rule_key', 'cpu')->value('breached_since'),
            'the breach clock must not survive a gap in the data',
        );

        // It comes back, still breaching. That is a NEW breach and has to hold again.
        $this->sample('server.cpu.usage_pct', 95, 0);
        $this->assertSame('pending', $this->evaluateOnce('cpu')['state']);
    }

    public function test_a_rule_that_recovers_and_breaks_again_is_not_silenced_by_the_old_cooldown(): void
    {
        // The cooldown gates repeat messages about ONE episode. Carrying it past a recovery gated
        // the FIRST message of the next one — an incident opened, the state flipped to firing, and
        // nobody was told.
        $this->rule([
            'key' => 'disk', 'metric' => 'server.disk.used_pct',
            'warning_threshold' => 80, 'for_seconds' => 0, 'cooldown_seconds' => 3600,
        ]);

        $this->sample('server.disk.used_pct', 94, 0);
        $this->assertSame('warning', $this->evaluateOnce('disk')['state']);

        $this->clearSamples();
        $this->sample('server.disk.used_pct', 10, 0);
        $this->assertSame('ok', $this->evaluateOnce('disk')['state']);

        $this->assertNull(
            DB::connection(self::CONNECTION)->table('monitoring_alert_states')->where('rule_key', 'disk')->value('last_notified_at'),
            'recovery has to end the episode the cooldown belongs to',
        );

        $this->clearSamples();
        $this->sample('server.disk.used_pct', 94, 0);
        $this->assertSame('fired and notified', $this->evaluateOnce('disk')['note']);
    }

    public function test_the_shipped_rules_are_installed_once_and_not_reinstated_after_deletion(): void
    {
        $evaluator = app(AlertEvaluator::class);

        $this->assertGreaterThan(0, $evaluator->seedDefaults());

        DB::connection(self::CONNECTION)->table('monitoring_alert_rules')->where('key', 'disk.used')->delete();
        app(MonitoringSettings::class)->forget();

        $this->assertSame(0, $evaluator->seedDefaults(), 'seeding ran twice and would have resurrected a deleted rule');
        $this->assertFalse(
            DB::connection(self::CONNECTION)->table('monitoring_alert_rules')->where('key', 'disk.used')->exists(),
        );
    }

    // ---------------------------------------------------------------------------------------

    private function rule(array $attributes): void
    {
        DB::connection(self::CONNECTION)->table('monitoring_alert_rules')->insert($attributes + [
            'name' => 'Test rule',
            'label' => '',
            'operator' => '>',
            'warning_threshold' => null,
            'critical_threshold' => null,
            'recovery_threshold' => null,
            'for_seconds' => 120,
            'cooldown_seconds' => 900,
            'enabled' => true,
            'notify_email' => false,
            'created_at' => Clock::stamp(),
            'updated_at' => Clock::stamp(),
        ]);
    }

    private function sample(string $metric, float $value, int $minutesAgo, string $label = ''): void
    {
        DB::connection(self::CONNECTION)->table('monitoring_series')->insert([
            'resolution' => 'minute',
            'bucket_at' => Clock::minutesAgo($minutesAgo)->startOfMinute()->toDateTimeString(),
            'metric' => $metric,
            'label' => $label,
            'samples' => 1,
            'value_sum' => $value,
            'value_min' => $value,
            'value_max' => $value,
            'value_last' => $value,
        ]);
    }

    private function clearSamples(): void
    {
        DB::connection(self::CONNECTION)->table('monitoring_series')->delete();
    }

    /** Pretend the breach started five minutes ago, which is what the next minute's run would see. */
    private function backdateBreach(string $ruleKey, int $minutes): void
    {
        DB::connection(self::CONNECTION)->table('monitoring_alert_states')
            ->where('rule_key', $ruleKey)
            ->update(['breached_since' => Clock::minutesAgo($minutes)->toDateTimeString()]);
    }

    private function evaluateOnce(string $ruleKey): array
    {
        foreach (app(AlertEvaluator::class)->evaluate() as $outcome) {
            if ($outcome['rule'] === $ruleKey) {
                return $outcome;
            }
        }

        $this->fail("The rule {$ruleKey} was not evaluated.");
    }
}
