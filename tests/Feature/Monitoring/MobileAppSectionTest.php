<?php

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\Ingest\AppHealthRecorder;
use App\Services\Monitoring\Ingest\BucketWriter;
use App\Services\Monitoring\Panels\AndroidPanel;
use App\Services\Monitoring\Panels\IosPanel;
use App\Services\Monitoring\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Android and iOS sections, and the two things they must never do.
 *
 * They must never present a crash-free percentage that nothing reported — an app that has sent no
 * session is `not_configured`, not 100% healthy. And they must never let a client-supplied version
 * string decide how many rows go into monitoring_series, because that string arrives in a header
 * anybody can set.
 */
class MobileAppSectionTest extends TestCase
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
    }

    private function series(): \Illuminate\Database\Query\Builder
    {
        return DB::connection(self::CONNECTION)->table('monitoring_series');
    }

    /** @param array<string, array<string, float|int>> $points */
    private function store(array $points): void
    {
        app(BucketWriter::class)->apply([intdiv(Clock::now()->getTimestamp(), 60) * 60 => $points]);
    }

    private function android(string $range = '1h'): array
    {
        return app(AndroidPanel::class)->data($range, Request::create('/admin/monitoring/android'));
    }

    public function test_an_app_that_reported_nothing_is_not_configured_rather_than_perfect(): void
    {
        $this->store(['ser|requests.by_platform|android' => ['n' => 10, 'sum' => 1000]]);

        $panel = $this->android();

        $this->assertSame('ok', $panel['traffic']['state'], 'traffic is measured server-side and was recorded');
        $this->assertSame('not_configured', $panel['stability']['state']);
        $this->assertSame([], $panel['stability']['metrics'], 'a crash-free figure must not be invented');
        $this->assertStringContainsString('/api/v1/app-health', $panel['stability']['remedy']);
    }

    public function test_a_silent_app_is_no_data_and_says_which_header_attributes_it(): void
    {
        $panel = $this->android();

        $this->assertSame('no_data', $panel['traffic']['state']);
        $this->assertStringContainsString('X-Platform', $panel['traffic']['remedy']);
        $this->assertStringContainsString('okhttp', $panel['traffic']['remedy']);

        foreach ($panel['traffic']['metrics'] as $label => $metric) {
            $this->assertFalse($metric->isOk(), $label . ' was drawn as a number with no traffic behind it');
        }
    }

    public function test_crash_free_is_derived_from_what_the_app_reported(): void
    {
        $this->store([
            'ser|requests.by_platform|android' => ['n' => 100, 'sum' => 20000],
            'ser|requests.by_platform.errors|android' => ['n' => 3],
            'ser|app.health.sessions|android:4.2.1' => ['n' => 200],
            'ser|app.health.crashes|android:4.2.1' => ['n' => 4],
        ]);

        $panel = $this->android();

        $this->assertSame('ok', $panel['stability']['state']);
        $this->assertSame(98.0, $panel['stability']['metrics']['crash_free_sessions']->value);
        $this->assertSame(200.0, (float) $panel['stability']['metrics']['sessions_reported']->value);
        $this->assertSame(3.0, (float) $panel['traffic']['metrics']['server_error_rate']->value);
        $this->assertSame(200.0, (float) $panel['traffic']['metrics']['mean_response_time']->value);
    }

    public function test_a_version_that_only_crashed_is_still_a_row(): void
    {
        // The most interesting row on the page: an app that died before it could call anything.
        $this->store([
            'ser|requests.by_platform|android' => ['n' => 10, 'sum' => 1000],
            'ser|requests.by_app_version|android:4.2.1' => ['n' => 10, 'sum' => 1000],
            'ser|app.health.sessions|android:4.1.9' => ['n' => 50],
            'ser|app.health.crashes|android:4.1.9' => ['n' => 50],
        ]);

        $rows = collect($this->android()['versions']['rows'])->keyBy('version');

        $this->assertNull($rows['4.2.1']['sessions'], 'a version with no health report must not be drawn as zero crashes');
        $this->assertNull($rows['4.2.1']['crash_free']);
        $this->assertNull($rows['4.1.9']['requests'], 'a version with no traffic must not be drawn as zero requests');
        $this->assertSame(0.0, $rows['4.1.9']['crash_free'], 'every session crashed');
    }

    public function test_one_platform_never_reads_the_others_rows(): void
    {
        $this->store([
            'ser|requests.by_platform|ios' => ['n' => 40, 'sum' => 4000],
            'ser|app.health.sessions|ios:3.9.1' => ['n' => 90],
            'ser|app.health.crashes|ios:3.9.1' => ['n' => 9],
        ]);

        $this->assertSame('no_data', $this->android()['traffic']['state']);
        $this->assertSame('not_configured', $this->android()['stability']['state']);

        $ios = app(IosPanel::class)->data('1h', Request::create('/admin/monitoring/ios'));

        $this->assertSame('ok', $ios['traffic']['state']);
        $this->assertSame(90.0, $ios['stability']['metrics']['crash_free_sessions']->value);
    }

    public function test_an_invented_version_cannot_grow_the_series_table_without_bound(): void
    {
        config()->set('monitoring.max_labels_per_client_series', 10);

        $points = [];
        for ($i = 0; $i < 300; $i++) {
            $points['ser|requests.by_app_version|android:invented-' . $i] = ['n' => 1, 'sum' => 100];
        }
        // The real release, busier than every invented one put together, so the cap is also being
        // asked to keep the row that matters rather than whichever three hundred arrived first.
        $points['ser|requests.by_app_version|android:4.2.1'] = ['n' => 5000, 'sum' => 500000];

        $this->store($points);

        $stored = $this->series()->where('metric', 'requests.by_app_version')->get();

        $this->assertLessThanOrEqual(11, $stored->count(), 'a header anybody can set decided the row count');
        $this->assertTrue($stored->contains(fn ($row) => $row->label === '__other__'), 'the tail must be folded, not dropped');
        $this->assertTrue($stored->contains(fn ($row) => $row->label === 'android:4.2.1'), 'the busiest release was evicted by invented ones');
        $this->assertSame(5300, (int) $stored->sum('samples'), 'folding must not lose a single request');
    }

    public function test_the_health_endpoint_stores_counters_and_refuses_everything_else(): void
    {
        $recorder = app(AppHealthRecorder::class);

        $this->assertSame(0, $recorder->record('windows', '1.0', ['sessions' => 5]), 'an unknown platform is not recorded');
        $this->assertSame(0, $recorder->record('android', '1.0', ['sessions' => 99999999]), 'an absurd count is not recorded');
        $this->assertSame(0, $recorder->record('android', '1.0', ['sessions' => 'many']), 'a non-numeric count is not recorded');
        $this->assertSame(2, $recorder->record('android', '4.2.1', ['sessions' => 3, 'crashes' => 1, 'stack' => 'x']));

        $stored = $this->series()->pluck('samples', 'metric');

        $this->assertSame(3, (int) $stored['app.health.sessions']);
        $this->assertSame(1, (int) $stored['app.health.crashes']);
        $this->assertArrayNotHasKey('app.health.stack', $stored->all(), 'only the three named counters are accepted');
    }

    public function test_a_version_string_that_is_not_one_is_stored_as_unknown(): void
    {
        // Rather than dropped: the sessions are real even when the version field is junk, and a
        // shape like `4.2.1"; DROP` must never reach the label column as written.
        app(AppHealthRecorder::class)->record('ios', 'not a version at all', ['sessions' => 7]);

        $row = $this->series()->where('metric', 'app.health.sessions')->first();

        $this->assertSame('ios:unknown', $row->label);
        $this->assertSame(7, (int) $row->samples);
    }
}
