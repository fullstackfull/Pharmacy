<?php

namespace Tests\Feature\Monitoring;

use App\Jobs\ExportTraceToOtlp;
use App\Services\Monitoring\Export\PrometheusExporter;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\MonitoringSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The two exports the configuration promised and the application never served.
 *
 * config/monitoring.php declared a Prometheus scrape endpoint at `GET /monitoring/metrics` and an
 * OTLP trace exporter — two panels even displayed the scrape URL as a live setting, complete with a
 * warning about exposing it. No such route existed and no such job existed, so an operator who
 * wired a collector at either got a 404 and an empty dashboard while the console insisted both were
 * configured. These tests hold the endpoints and, more importantly, the two rules that keep them
 * from becoming the incident: an unauthenticated scrape learns nothing, and a partly unavailable
 * store answers with what it has rather than reporting the whole application down.
 */
class TelemetryExportTest extends TestCase
{
    private const CONNECTION = 'monitoring_test';
    private const TOKEN = 'scrape-token-for-tests';

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
        config()->set('monitoring.enabled', true);

        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->getPdo();

        foreach (glob(database_path('migrations/*_create_monitoring_*_tables.php')) as $migration) {
            (require $migration)->up();
        }

        app(MonitoringSettings::class)->forget();
    }

    private function switchExporterOn(): void
    {
        config()->set('monitoring.prometheus.enabled', true);
        config()->set('monitoring.prometheus.token', self::TOKEN);
    }

    // ──────────────────────────────────────────────────────────────── prometheus

    public function test_the_scrape_endpoint_is_absent_until_it_is_configured(): void
    {
        config()->set('monitoring.prometheus.enabled', false);

        $this->get('/monitoring/metrics')->assertNotFound();
    }

    /**
     * An enabled exporter with no token is a half-finished setup, not an open metrics endpoint.
     * Metrics name the routes, the queues and the dependencies of a live shop.
     */
    public function test_an_enabled_exporter_without_a_token_still_serves_nothing(): void
    {
        config()->set('monitoring.prometheus.enabled', true);
        config()->set('monitoring.prometheus.token', '');

        $this->assertFalse(app(PrometheusExporter::class)->enabled());
        $this->assertFalse(app(PrometheusExporter::class)->accepts(''));
        $this->get('/monitoring/metrics')->assertNotFound();
    }

    /** 404 rather than 403: a 403 confirms the endpoint is there, which is itself information. */
    public function test_a_wrong_token_is_answered_as_if_the_endpoint_did_not_exist(): void
    {
        $this->switchExporterOn();

        $this->get('/monitoring/metrics?token=wrong')->assertNotFound();
        $this->withHeader('Authorization', 'Bearer wrong')->get('/monitoring/metrics')->assertNotFound();
    }

    public function test_a_scrape_with_the_right_token_returns_the_exposition_format(): void
    {
        $this->switchExporterOn();

        $response = $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)->get('/monitoring/metrics');

        $response->assertOk();
        $this->assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('# TYPE pharmacy_up gauge', $response->getContent());
        $this->assertStringContainsString('pharmacy_up 1', $response->getContent());
        $this->assertStringContainsString('pharmacy_build_info{', $response->getContent());
    }

    public function test_a_scraper_that_cannot_set_a_header_may_pass_the_token_in_the_query(): void
    {
        $this->switchExporterOn();

        $this->get('/monitoring/metrics?token=' . self::TOKEN)->assertOk();
    }

    public function test_traffic_from_the_last_complete_minute_is_exposed_by_channel(): void
    {
        $this->switchExporterOn();

        DB::connection(self::CONNECTION)->table('monitoring_request_buckets')->insert([
            'resolution' => 'minute',
            'bucket_at' => Clock::stamp(Clock::now()->subMinute()->startOfMinute()),
            'channel' => 'web',
            'route' => 'product/{slug}',
            'method' => 'GET',
            'hits' => 40,
            'errors' => 2,
            'client_errors' => 3,
            'duration_sum_ms' => 4000,
        ]);

        $body = app(PrometheusExporter::class)->render();

        $this->assertStringContainsString('pharmacy_http_requests_last_minute{channel="web",method="GET"} 40', $body);
        $this->assertStringContainsString('pharmacy_http_server_errors_last_minute{channel="web",method="GET"} 2', $body);
        $this->assertStringContainsString('pharmacy_http_request_duration_ms_mean_last_minute{channel="web",method="GET"} 100', $body);
    }

    /**
     * One metric name carrying the series as a label, so a collector added next month is exported
     * without an edit here.
     */
    public function test_collector_series_are_exported_under_one_metric_name(): void
    {
        $this->switchExporterOn();

        DB::connection(self::CONNECTION)->table('monitoring_series')->insert([
            'resolution' => 'minute',
            'bucket_at' => Clock::stamp(Clock::now()->subMinute()->startOfMinute()),
            'metric' => 'server.cpu.usage',
            'label' => '',
            'samples' => 1,
            'value_sum' => 42.5,
            'value_last' => 42.5,
        ]);

        $this->assertStringContainsString(
            'pharmacy_series{metric="server.cpu.usage"} 42.5',
            app(PrometheusExporter::class)->render(),
        );
    }

    /**
     * Prometheus treats a failed scrape as "target down". One missing monitoring table must not
     * therefore report the entire shop as down.
     */
    public function test_a_missing_table_costs_one_block_rather_than_the_whole_scrape(): void
    {
        $this->switchExporterOn();
        DB::connection(self::CONNECTION)->statement('DROP TABLE monitoring_request_buckets');

        $body = app(PrometheusExporter::class)->render();

        $this->assertStringContainsString('pharmacy_up 1', $body);
        $this->assertStringNotContainsString('pharmacy_http_requests_last_minute', $body);
    }

    // ─────────────────────────────────────────────────────────────────────── otlp

    public function test_no_collector_configured_means_no_export(): void
    {
        config()->set('monitoring.tracing.otlp_endpoint', null);

        $this->assertNull(ExportTraceToOtlp::endpoint());
    }

    public function test_a_finished_trace_is_posted_to_the_collector_as_otlp_json(): void
    {
        config()->set('monitoring.tracing.otlp_endpoint', 'https://collector.test');
        config()->set('monitoring.tracing.otlp_headers', 'x-api-key=abc123');
        Http::fake(['collector.test/*' => Http::response('', 200)]);

        $this->seedTrace();

        app()->call([new ExportTraceToOtlp('aaaabbbbccccddddeeeeffff00001111'), 'handle']);

        Http::assertSent(function ($request) {
            $span = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

            return $request->url() === 'https://collector.test/v1/traces'
                && $request->hasHeader('x-api-key', 'abc123')
                && $span['traceId'] === 'aaaabbbbccccddddeeeeffff00001111'
                && $span['name'] === 'GET product/{slug}'
                // Offsets are relative in this store and absolute in the protocol.
                && $span['endTimeUnixNano'] > $span['startTimeUnixNano'];
        });
    }

    /** A trace with no spans is nothing to look at; posting it is a wasted round trip. */
    public function test_a_trace_without_spans_is_not_posted(): void
    {
        config()->set('monitoring.tracing.otlp_endpoint', 'https://collector.test');
        Http::fake();

        app()->call([new ExportTraceToOtlp('nosuchtraceatall0000000000000000'), 'handle']);

        Http::assertNothingSent();
    }

    private function seedTrace(): void
    {
        $connection = DB::connection(self::CONNECTION);

        $connection->table('monitoring_traces')->insert([
            'trace_id' => 'aaaabbbbccccddddeeeeffff00001111',
            'route' => 'product/{slug}',
            'method' => 'GET',
            'channel' => 'web',
            'status' => 200,
            'duration_ms' => 300,
            'release' => '1.0.0',
            'captured_because' => 'slow',
            'started_at' => Clock::stamp(Clock::minutesAgo(2)),
        ]);

        $connection->table('monitoring_spans')->insert([
            'trace_id' => 'aaaabbbbccccddddeeeeffff00001111',
            'span_id' => '1111222233334444',
            'kind' => 'controller',
            'name' => 'GET product/{slug}',
            'start_offset_ms' => 0,
            'duration_ms' => 300,
            'failed' => false,
            'attributes' => json_encode(['db.queries' => 12]),
        ]);
    }
}
