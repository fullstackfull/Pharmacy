<?php

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\Checks\CheckResult;
use App\Services\Monitoring\Checks\CheckRunner;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Uptime is computed from these rows, so what is allowed to count as downtime matters more than
 * what the checks themselves do.
 */
class CheckRunnerTest extends TestCase
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
        config()->set('monitoring.buffer', 'none');

        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->getPdo();

        foreach (glob(database_path('migrations/*_create_monitoring_*_tables.php')) as $migration) {
            (require $migration)->up();
        }
    }

    public function test_a_check_that_cannot_run_here_is_neither_up_nor_down(): void
    {
        // A shop with no synthetic journey defined has no availability to report for one. Counting
        // that as 100% up flatters the figure; counting it as down invents an outage. It is
        // excluded, and uptime is computed only over checks that actually ran.
        app(CheckRunner::class)->run(['synthetic']);

        $recorded = DB::connection(self::CONNECTION)->table('monitoring_check_results')->get();
        $this->assertCount(1, $recorded);
        $this->assertSame(CheckResult::NOT_CONFIGURED, $recorded->first()->status);

        $this->assertSame(
            0,
            DB::connection(self::CONNECTION)->table('monitoring_series')->where('metric', 'check.up')->count(),
            'a not_configured check contributed to the uptime series',
        );
    }

    public function test_a_check_that_ran_is_recorded_with_its_history_and_its_series(): void
    {
        $results = app(CheckRunner::class)->run(['database']);

        $this->assertCount(1, $results);
        $this->assertSame('database', $results[0]->key);

        $row = DB::connection(self::CONNECTION)->table('monitoring_check_results')->where('check_key', 'database')->first();
        $this->assertNotNull($row, 'the check produced no history, so uptime cannot be computed from it');
        $this->assertNotNull($row->checked_at);

        $series = DB::connection(self::CONNECTION)->table('monitoring_series')
            ->where('metric', 'check.up')->where('label', 'database')->first();
        $this->assertNotNull($series, 'availability was not published as a series');
        $this->assertContains((float) $series->value_last, [0.0, 1.0], 'availability must be exactly up or down');
    }

    public function test_one_broken_check_does_not_stop_the_others(): void
    {
        $results = app(CheckRunner::class)->run();
        $keys = array_map(static fn (CheckResult $result) => $result->key, $results);

        foreach (['database', 'redis', 'queue', 'scheduler', 'storage', 'ssl', 'backup'] as $expected) {
            $this->assertContains($expected, $keys, "The {$expected} check did not produce a result.");
        }

        foreach ($results as $result) {
            $this->assertContains($result->status, [
                CheckResult::OK, CheckResult::DEGRADED, CheckResult::FAILING,
                CheckResult::UNKNOWN, CheckResult::NOT_CONFIGURED, CheckResult::NOT_SUPPORTED,
            ]);
        }
    }
}
