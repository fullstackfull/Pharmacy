<?php

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The seam between the folded buckets and the minutes past them.
 *
 * The rollup folds the parent that is still in progress — a run at 10:03 writes an hour bucket
 * holding three minutes of that hour — so a reader that treats the newest folded parent as complete
 * loses everything between the run and the end of that hour. Measured on a real installation, that
 * was 17% of a day's requests missing from every coarse window: not an error anybody would see,
 * just a number that was too small.
 */
class FoldSeamTest extends TestCase
{
    private const CONNECTION = 'monitoring_seam';

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

        foreach (glob(database_path('migrations/*_create_monitoring_core_tables.php')) as $migration) {
            (require $migration)->up();
        }

        $this->assertTrue(Schema::connection(self::CONNECTION)->hasTable('monitoring_request_buckets'));
    }

    private function bucket(string $resolution, string $at, int $hits): void
    {
        DB::connection(self::CONNECTION)->table('monitoring_request_buckets')->insert([
            'resolution' => $resolution,
            'bucket_at' => $at,
            'channel' => 'web',
            'route' => '/',
            'method' => 'GET',
            'hits' => $hits,
            'duration_sum_ms' => $hits * 100,
            'duration_min_ms' => 100,
            'duration_max_ms' => 100,
        ]);
    }

    public function test_the_minutes_after_a_partial_fold_are_not_lost(): void
    {
        $hourAgo = now()->subHour()->startOfHour();
        $thisHour = now()->startOfHour();

        // A complete hour, then a fold that ran three minutes into this one.
        $this->bucket('hour', $hourAgo->toDateTimeString(), 100);
        $this->bucket('hour', $thisHour->toDateTimeString(), 3);

        for ($minute = 0; $minute < 3; $minute++) {
            $this->bucket('minute', $thisHour->copy()->addMinutes($minute)->toDateTimeString(), 1);
        }

        // …and twenty minutes of traffic since, which no fold has seen.
        for ($minute = 3; $minute < 23; $minute++) {
            $this->bucket('minute', $thisHour->copy()->addMinutes($minute)->toDateTimeString(), 2);
        }

        $summary = app(SeriesReader::class)->requestSummary('24h');

        // 100 folded + 3 in the partial hour + 40 since = 143. The old boundary skipped the
        // twenty minutes AND the partial hour's own three, reporting 100.
        $this->assertSame(143, (int) $summary['hits']);
    }

    public function test_the_headline_the_route_table_and_the_chart_all_agree(): void
    {
        // Three readers of the same buckets on the same page. Only the summary crossed the seam, so
        // the headline counted the last hour and the table under it did not — and the busiest route
        // of that hour was missing from the list of the busiest routes.
        $hourAgo = now()->subHour()->startOfHour();
        $thisHour = now()->startOfHour();

        $this->bucket('hour', $hourAgo->toDateTimeString(), 100);
        $this->bucket('hour', $thisHour->toDateTimeString(), 3);

        for ($minute = 0; $minute < 3; $minute++) {
            $this->bucket('minute', $thisHour->copy()->addMinutes($minute)->toDateTimeString(), 1);
        }
        for ($minute = 3; $minute < 23; $minute++) {
            $this->bucket('minute', $thisHour->copy()->addMinutes($minute)->toDateTimeString(), 2);
        }

        $reader = app(SeriesReader::class);

        $summary = (int) $reader->requestSummary('24h')['hits'];
        $table = (int) array_sum(array_column($reader->routeBreakdown('24h', 'hits', 500), 'hits'));
        $chart = (int) array_sum(array_column($reader->requestTimeline('24h')['points'] ?? [], 'hits'));

        $this->assertSame(143, $summary);
        $this->assertSame($summary, $table, 'the route table has to add up to the headline above it');
        $this->assertSame($summary, $chart, 'the chart has to end where the traffic does');
    }

    public function test_a_window_with_nothing_folded_yet_reads_every_minute(): void
    {
        $start = now()->startOfHour();

        for ($minute = 0; $minute < 5; $minute++) {
            $this->bucket('minute', $start->copy()->addMinutes($minute)->toDateTimeString(), 4);
        }

        $this->assertSame(20, (int) app(SeriesReader::class)->requestSummary('24h')['hits']);
    }

    public function test_nothing_is_counted_twice_across_the_seam(): void
    {
        $thisHour = now()->startOfHour();

        $this->bucket('hour', $thisHour->toDateTimeString(), 5);
        for ($minute = 0; $minute < 5; $minute++) {
            $this->bucket('minute', $thisHour->copy()->addMinutes($minute)->toDateTimeString(), 1);
        }

        // The hour bucket and its own minutes describe the same five requests.
        $this->assertSame(5, (int) app(SeriesReader::class)->requestSummary('24h')['hits']);
    }
}
