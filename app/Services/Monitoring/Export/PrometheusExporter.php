<?php

namespace App\Services\Monitoring\Export;

use App\Services\Monitoring\HealthScoreService;
use App\Services\Monitoring\Support\Clock;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The scrape endpoint config/monitoring.php has always promised.
 *
 * The configuration declared `GET /monitoring/metrics returns the text exposition format`, two
 * panels displayed the endpoint as a live setting complete with a warning about exposing it — and
 * no such route existed anywhere in the application. An operator who wired Prometheus at it got a
 * 404 and a dashboard that stayed empty while the console insisted the exporter was on.
 *
 * What it emits, and why it is gauges rather than counters. Prometheus counters must be cumulative
 * and only ever reset when a process restarts; this store keeps rolling buckets and the rollup
 * prunes them, so a `_total` built from it would fall as often as it rose and every `rate()` over
 * it would be wrong. A gauge that says "this many in the last complete minute" is a smaller claim
 * that happens to be true, and `sum_over_time` gives back the total honestly.
 *
 * Cardinality is the other constraint. Prometheus stores one series per label combination forever,
 * so nothing here is labelled by route, URL, user or id — the per-route detail stays in the console,
 * which is indexed for it. Channel, method, metric name and service key are all bounded sets.
 */
class PrometheusExporter
{
    private const NAMESPACE = 'pharmacy';

    public function __construct(private readonly HealthScoreService $health)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('monitoring.prometheus.enabled', false)
            && config('monitoring.enabled', true)
            && $this->token() !== '';
    }

    public function token(): string
    {
        return (string) config('monitoring.prometheus.token', '');
    }

    /**
     * Constant-time comparison, and never a truthy check on a configured secret.
     *
     * An empty configured token with an empty presented token compares equal, which would turn a
     * half-finished setup into an open metrics endpoint. `enabled()` already refuses that case;
     * this refuses it a second time so a future caller cannot reintroduce it.
     */
    public function accepts(?string $presented): bool
    {
        $token = $this->token();

        return $token !== '' && is_string($presented) && hash_equals($token, $presented);
    }

    /** The whole exposition, as one text/plain body. */
    public function render(): string
    {
        $minute = Clock::now()->subMinute()->startOfMinute();
        $lines = [];

        $this->gauge($lines, 'up', 'Whether this application is serving the exposition.', [['labels' => [], 'value' => 1]]);
        $this->gauge($lines, 'build_info', 'The release currently deployed, as a label on a constant.', [
            ['labels' => ['release' => app_release_version(), 'service' => (string) config('monitoring.tracing.service_name', 'app')], 'value' => 1],
        ]);

        $this->appendHealth($lines);
        $this->appendRequests($lines, $minute);
        $this->appendSeries($lines, $minute);
        $this->appendDependencies($lines, $minute);
        $this->appendErrors($lines);

        return implode("\n", $lines) . "\n";
    }

    private function appendHealth(array &$lines): void
    {
        $health = $this->safely(fn () => $this->health->evaluate());

        if ($health === null) {
            return;
        }

        // A null score means "nothing measurable", which is not zero. Emitting zero would page
        // somebody for an outage that is actually an unconfigured collector.
        if ($health['score'] !== null) {
            $this->gauge($lines, 'health_score', 'Weighted health score across measured signals, 0-100.', [
                ['labels' => ['status' => (string) $health['status']], 'value' => (int) $health['score']],
            ]);
        }

        $this->gauge($lines, 'health_signals_measured', 'How many health signals this installation can actually measure.', [
            ['labels' => [], 'value' => (int) $health['coverage']['measured']],
        ]);
        $this->gauge($lines, 'health_signals_total', 'How many health signals exist.', [
            ['labels' => [], 'value' => (int) $health['coverage']['total']],
        ]);
    }

    private function appendRequests(array &$lines, \Illuminate\Support\Carbon $minute): void
    {
        $rows = $this->safely(fn () => $this->connection()->table('monitoring_request_buckets')
            ->where('resolution', 'minute')
            ->where('bucket_at', Clock::stamp($minute))
            ->selectRaw('channel, method, SUM(hits) as hits, SUM(errors) as errors, SUM(client_errors) as client_errors, SUM(duration_sum_ms) as duration_sum_ms')
            ->groupBy('channel', 'method')
            ->get());

        if ($rows === null || $rows->isEmpty()) {
            return;
        }

        $hits = $errors = $clientErrors = $latency = [];

        foreach ($rows as $row) {
            $labels = ['channel' => (string) $row->channel, 'method' => (string) $row->method];
            $hits[] = ['labels' => $labels, 'value' => (int) $row->hits];
            $errors[] = ['labels' => $labels, 'value' => (int) $row->errors];
            $clientErrors[] = ['labels' => $labels, 'value' => (int) $row->client_errors];
            $latency[] = [
                'labels' => $labels,
                'value' => $row->hits > 0 ? round($row->duration_sum_ms / $row->hits, 2) : 0,
            ];
        }

        $this->gauge($lines, 'http_requests_last_minute', 'Requests served in the last complete minute.', $hits);
        $this->gauge($lines, 'http_server_errors_last_minute', 'Responses of 5xx in the last complete minute.', $errors);
        $this->gauge($lines, 'http_client_errors_last_minute', 'Responses of 4xx in the last complete minute.', $clientErrors);
        $this->gauge($lines, 'http_request_duration_ms_mean_last_minute', 'Mean server-side response time in the last complete minute.', $latency);
    }

    /**
     * Every named series, under one metric name with the series as a label.
     *
     * One `pharmacy_series` gauge rather than a metric per series on purpose: a series is added by
     * a collector, and a shape that needs an exporter edit for each new one is a shape that goes
     * out of date the first week.
     */
    private function appendSeries(array &$lines, \Illuminate\Support\Carbon $minute): void
    {
        $rows = $this->safely(fn () => $this->connection()->table('monitoring_series')
            ->where('resolution', 'minute')
            ->where('bucket_at', Clock::stamp($minute))
            ->select('metric', 'label', 'value_last', 'value_sum', 'samples')
            ->orderBy('metric')
            ->limit(2000)
            ->get());

        if ($rows === null || $rows->isEmpty()) {
            return;
        }

        $values = [];

        foreach ($rows as $row) {
            // A gauge reports its last sample; a counted series has no last value and its sum is
            // the measurement. Choosing per row is what lets one metric name carry both.
            $value = $row->value_last ?? $row->value_sum;
            $values[] = [
                'labels' => array_filter(['metric' => (string) $row->metric, 'label' => (string) $row->label], static fn ($v) => $v !== ''),
                'value' => round((float) $value, 4),
            ];
        }

        $this->gauge($lines, 'series', 'Collector series for the last complete minute, named by the metric label.', $values);
    }

    private function appendDependencies(array &$lines, \Illuminate\Support\Carbon $minute): void
    {
        $rows = $this->safely(fn () => $this->connection()->table('monitoring_dependency_buckets')
            ->where('resolution', 'minute')
            ->where('bucket_at', Clock::stamp($minute))
            ->selectRaw('service, SUM(calls) as calls, SUM(failures) as failures, SUM(timeouts) as timeouts')
            ->groupBy('service')
            ->get());

        if ($rows === null || $rows->isEmpty()) {
            return;
        }

        $calls = $failures = $timeouts = [];

        foreach ($rows as $row) {
            $labels = ['service' => (string) $row->service];
            $calls[] = ['labels' => $labels, 'value' => (int) $row->calls];
            $failures[] = ['labels' => $labels, 'value' => (int) $row->failures];
            $timeouts[] = ['labels' => $labels, 'value' => (int) $row->timeouts];
        }

        $this->gauge($lines, 'dependency_calls_last_minute', 'Outbound calls per external service in the last complete minute.', $calls);
        $this->gauge($lines, 'dependency_failures_last_minute', 'Failed outbound calls per external service in the last complete minute.', $failures);
        $this->gauge($lines, 'dependency_timeouts_last_minute', 'Timed-out outbound calls per external service in the last complete minute.', $timeouts);
    }

    private function appendErrors(array &$lines): void
    {
        $open = $this->safely(fn () => $this->connection()->table('monitoring_error_groups')->where('status', 'open')->count());

        if ($open === null) {
            return;
        }

        $this->gauge($lines, 'error_groups_open', 'Distinct unresolved exception groups.', [['labels' => [], 'value' => $open]]);

        $recent = $this->safely(fn () => $this->connection()->table('monitoring_error_groups')
            ->where('last_seen_at', '>=', Clock::stamp(Clock::hoursAgo(1)))
            ->count());

        if ($recent !== null) {
            $this->gauge($lines, 'error_groups_seen_last_hour', 'Exception groups that occurred in the last hour.', [['labels' => [], 'value' => $recent]]);
        }
    }

    /**
     * @param  array<int, array{labels: array<string, string>, value: int|float}>  $samples
     */
    private function gauge(array &$lines, string $name, string $help, array $samples): void
    {
        if ($samples === []) {
            return;
        }

        $metric = self::NAMESPACE . '_' . $name;

        $lines[] = '# HELP ' . $metric . ' ' . str_replace(["\n", '\\'], [' ', '\\\\'], $help);
        $lines[] = '# TYPE ' . $metric . ' gauge';

        foreach ($samples as $sample) {
            $lines[] = $metric . $this->labels($sample['labels']) . ' ' . $this->number($sample['value']);
        }
    }

    /** @param array<string, string> $labels */
    private function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $pairs = [];
        foreach ($labels as $key => $value) {
            // The exposition format has no escape for a raw newline or an unescaped quote, and a
            // metric name arriving from a collector is not guaranteed to be clean.
            $pairs[] = $key . '="' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ' '], $value) . '"';
        }

        return '{' . implode(',', $pairs) . '}';
    }

    private function number(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        $trimmed = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    private function connection(): Connection
    {
        return DB::connection(config('monitoring.connection'));
    }

    /**
     * A scrape that half-answers is better than a scrape that 500s.
     *
     * Prometheus treats a failed scrape as "target down", so one unavailable monitoring table
     * would report the whole application as down. Each block is allowed to be missing instead.
     */
    private function safely(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }
}
