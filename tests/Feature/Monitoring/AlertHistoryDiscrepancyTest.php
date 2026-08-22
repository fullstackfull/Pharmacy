<?php

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\Panels\AlertsPanel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A timeline that could not be read is not a timeline that disagrees.
 *
 * The alert history is checked against a second, independent record of the same firings: the
 * lifetime counter on each rule. When the two disagree the page says so, because an empty list
 * would otherwise read as "nothing has ever fired". But the check used to run on the counter alone,
 * so a timeline whose query FAILED — no rows read, none readable — produced the same accusation:
 * "the rule state table records 4 firings that do not appear on this timeline". That sends an
 * operator hunting a lost alert when the thing to fix is the query.
 */
class AlertHistoryDiscrepancyTest extends TestCase
{
    private const CONNECTION = 'monitoring_discrepancy';

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
    }

    public function test_a_readable_but_empty_timeline_beside_a_counter_is_reported_as_a_discrepancy(): void
    {
        $this->ruleThatHasFired(4);

        $events = $this->events();

        $this->assertSame('no_data', $events['state']);
        $this->assertSame('missing_from_timeline', $events['discrepancy']);
        $this->assertSame(4, $events['firings_recorded_in_state']);
    }

    public function test_an_unreadable_timeline_is_reported_as_unreadable_not_as_a_disagreement(): void
    {
        $this->ruleThatHasFired(4);

        Schema::connection(self::CONNECTION)->drop('monitoring_events');

        $events = $this->events();

        $this->assertSame('failed', $events['state']);
        $this->assertSame('not_comparable', $events['discrepancy'], 'nothing was read, so nothing was compared');
        $this->assertSame(4, $events['firings_recorded_in_state']);
    }

    public function test_a_rule_that_has_never_fired_raises_nothing_either_way(): void
    {
        $this->ruleThatHasFired(0);

        $this->assertNull($this->events()['discrepancy']);

        Schema::connection(self::CONNECTION)->drop('monitoring_events');

        $this->assertNull($this->events()['discrepancy']);
    }

    public function test_the_page_renders_each_case_and_never_both(): void
    {
        $view = file_get_contents(resource_path('views/admin-views/monitoring/sections/alerts.blade.php'));

        // The accusation is guarded by the panel's verdict, not by the counter alone.
        $this->assertStringContainsString("=== 'missing_from_timeline'", $view);
        $this->assertStringContainsString("=== 'not_comparable'", $view);
        $this->assertStringNotContainsString("@if ((\$events['firings_recorded_in_state'] ?? 0) > 0)", $view);
    }

    // ---------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function events(): array
    {
        return app(AlertsPanel::class)->data('24h', Request::create('/admin/monitoring/alerts'))['events'];
    }

    private function ruleThatHasFired(int $times): void
    {
        $connection = DB::connection(self::CONNECTION);

        $connection->table('monitoring_alert_rules')->insert([
            'key' => 'cpu',
            'name' => 'CPU',
            'metric' => 'server.cpu.usage_pct',
            'operator' => '>',
            'warning_threshold' => 80,
            'critical_threshold' => 95,
            'for_seconds' => 0,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $connection->table('monitoring_alert_states')->insert([
            'rule_key' => 'cpu',
            'state' => 'ok',
            'fire_count' => $times,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
