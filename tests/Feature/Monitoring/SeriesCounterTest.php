<?php

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\Ingest\BucketWriter;
use App\Services\Monitoring\Panels\OverviewPanel;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What a series point means, and the card that got it wrong.
 *
 * monitoring_series stores two numbers per bucket and they are not interchangeable: `samples` counts
 * the readings, `value_sum` totals whatever the WRITER decided to total there. For a gauge that is a
 * reading; for requests.by_platform it is the response time in milliseconds; for requests.by_status
 * it is nothing at all, because that writer only ever increments the count.
 *
 * SeriesReader::series() returns value_sum for a counter — correct, and lethal to a caller that
 * assumes it is a count. The overview's Android and iOS cards summed it and published the result
 * under the word "requests", so an hour with twelve requests read "1,190 requests" and the error grew
 * with the latency: a slow app looked like a busy one, which is the opposite of what the card is for.
 *
 * These pin the contract that replaced the assumption, and the arithmetic of the card that depended
 * on it.
 */
class SeriesCounterTest extends TestCase
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
    }

    /** @param array<string, array<string, float|int>> $points */
    private function store(array $points): void
    {
        app(BucketWriter::class)->apply([intdiv(Clock::now()->getTimestamp(), 60) * 60 => $points]);
    }

    public function test_a_counters_value_is_its_sum_and_its_count_is_reported_separately(): void
    {
        // Twelve requests that took 1,190 ms in total — the exact shape RequestRecorder writes.
        $this->store(['ser|requests.by_platform|android' => ['n' => 12, 'sum' => 1190]]);

        $series = app(SeriesReader::class)->series('requests.by_platform', '1h', 'android');

        $this->assertSame(12, $series['samples'], 'the sample count is the request count');
        $this->assertSame(12, $series['points'][0]['n']);
        $this->assertSame(1190.0, $series['points'][0]['v'], 'v is still the writer\'s total, unchanged');
    }

    public function test_a_gauge_still_reports_its_last_reading(): void
    {
        // The other half of the contract: nothing about the gauge path may have moved.
        $this->store(['ser|server.cpu.usage_pct|' => ['n' => 1, 'sum' => 93.0, 'last' => 93.0]]);

        $series = app(SeriesReader::class)->series('server.cpu.usage_pct', '1h', '');

        $this->assertSame(93.0, $series['points'][0]['v']);
        $this->assertSame(93.0, $series['latest']);
        $this->assertSame('ok', $series['state']);
    }

    public function test_a_read_that_failed_says_so_instead_of_looking_empty(): void
    {
        // An empty result that cannot say whether it looked is indistinguishable from a quiet window,
        // which is how "0 samples" ends up drawn over a database nobody could reach.
        config()->set('monitoring.connection', 'no_such_connection');

        $series = app(SeriesReader::class)->series('server.cpu.usage_pct', '1h');

        $this->assertSame('failed', $series['state']);
        $this->assertSame(0, $series['samples']);
        $this->assertSame([], $series['points']);
        $this->assertNotNull($series['note']);
    }

    public function test_the_overview_platform_card_counts_requests_not_milliseconds(): void
    {
        $this->store(['ser|requests.by_platform|android' => ['n' => 12, 'sum' => 1190]]);

        $card = $this->platformCard('android');

        $this->assertSame(12, $card['value'], 'the card published the millisecond total as a request count');
        $this->assertSame('requests', $card['unit']);
        $this->assertStringContainsString('12 requests', $card['detail']);
    }

    public function test_the_platform_card_separates_no_traffic_from_no_reading(): void
    {
        $this->assertSame('no_data', $this->platformCard('ios')['state'], 'nothing was sent');

        config()->set('monitoring.connection', 'no_such_connection');

        $this->assertSame('unavailable', $this->platformCard('ios')['state'], 'nothing could be read');
    }

    /** @return array<string, mixed> */
    private function platformCard(string $platform): array
    {
        $method = new \ReflectionMethod(OverviewPanel::class, 'platformCard');
        $method->setAccessible(true);

        return $method->invoke(app(OverviewPanel::class), $platform, $platform);
    }
}
