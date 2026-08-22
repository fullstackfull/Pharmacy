<?php

namespace App\Services\Monitoring\Support;

use Illuminate\Support\Facades\DB;

/**
 * Reading side of the store: windows, series, percentiles and comparisons.
 *
 * Every panel goes through here rather than writing its own queries, for three reasons. The
 * resolution choice (minute / hour / day) has to be consistent or two charts of the same thing
 * disagree. The UTC boundary has to be crossed in exactly one place, or the timezone bug this
 * system already had comes back. And every query has to stay inside an index — a monitoring page
 * that table-scans is a monitoring page that becomes the incident.
 */
class SeriesReader
{
    /**
     * Window definitions, matched to MonitoringController::availableRanges().
     *
     * @var array<string, array{minutes: int, resolution: string, points: int}>
     */
    private const RANGES = [
        'live' => ['minutes' => 5, 'resolution' => 'minute', 'points' => 5],
        '15m' => ['minutes' => 15, 'resolution' => 'minute', 'points' => 15],
        '1h' => ['minutes' => 60, 'resolution' => 'minute', 'points' => 60],
        '6h' => ['minutes' => 360, 'resolution' => 'minute', 'points' => 72],
        '24h' => ['minutes' => 1440, 'resolution' => 'hour', 'points' => 24],
        '7d' => ['minutes' => 10080, 'resolution' => 'hour', 'points' => 84],
        '30d' => ['minutes' => 43200, 'resolution' => 'day', 'points' => 30],
        '90d' => ['minutes' => 129600, 'resolution' => 'day', 'points' => 90],
    ];

    public function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('monitoring.connection', 'monitoring'));
    }

    /** @return array{minutes: int, resolution: string, points: int} */
    public function window(string $range): array
    {
        return self::RANGES[$range] ?? self::RANGES['1h'];
    }

    public function since(string $range): \Illuminate\Support\Carbon
    {
        return Clock::minutesAgo($this->window($range)['minutes']);
    }

    /**
     * The same window, one window earlier — the baseline every comparison is drawn against.
     *
     * "p95 is 420ms" means nothing on its own; "+18% against the previous hour" is what tells
     * somebody whether to care.
     *
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    public function previousWindow(string $range): array
    {
        $minutes = $this->window($range)['minutes'];

        return [Clock::minutesAgo($minutes * 2), Clock::minutesAgo($minutes)];
    }

    /**
     * The newest bucket written to a table, for staleness checks.
     */
    public function newestBucketAt(string $table): ?string
    {
        try {
            $column = $table === 'monitoring_request_buckets' || $table === 'monitoring_series' ? 'bucket_at' : 'created_at';

            return $this->connection()->table($table)->max($column);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A named gauge over time, ready to chart.
     *
     * @return array{points: array<int, array{t: string, v: float|null}>, latest: float|null, unit: string|null}
     */
    public function series(string $metric, string $range, ?string $label = null): array
    {
        $window = $this->window($range);

        try {
            $rows = $this->connection()->table('monitoring_series')
                ->where('metric', $metric)
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $this->since($range))
                ->when($label !== null, fn ($query) => $query->where('label', $label))
                ->orderBy('bucket_at')
                ->limit(max(1, $window['points']) * 4)
                ->get(['bucket_at', 'samples', 'value_sum', 'value_last', 'value_min', 'value_max', 'label']);

            $points = [];
            foreach ($rows as $row) {
                // A gauge's honest value for a bucket is its last reading; a counter's is its sum.
                // value_last is null for counters, which is how the two are told apart without a
                // separate column saying which kind this is.
                $value = $row->value_last !== null
                    ? (float) $row->value_last
                    : ((int) $row->samples > 0 ? (float) $row->value_sum : null);

                $points[] = ['t' => Clock::parse($row->bucket_at)->toIso8601String(), 'v' => $value];
            }

            return [
                'points' => $points,
                'latest' => $points === [] ? null : end($points)['v'],
                'unit' => null,
            ];
        } catch (\Throwable) {
            return ['points' => [], 'latest' => null, 'unit' => null];
        }
    }

    /**
     * Request totals and percentiles for a window, optionally narrowed to one route or channel.
     *
     * @return array<string, mixed>
     */
    public function requestSummary(string $range, ?string $route = null, ?string $channel = null, ?\Illuminate\Support\Carbon $since = null, ?\Illuminate\Support\Carbon $until = null): array
    {
        $window = $this->window($range);

        try {
            $narrow = fn ($query) => $query
                ->when($until !== null, fn ($inner) => $inner->where('bucket_at', '<', $until))
                ->when($route !== null, fn ($inner) => $inner->where('route', $route))
                ->when($channel !== null, fn ($inner) => $inner->where('channel', $channel));

            $from = $since ?? $this->since($range);

            $rows = $narrow($this->connection()->table('monitoring_request_buckets')
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $from))
                ->get();

            // The tail. Rollups fold minutes into hours on a schedule, so a 24-hour window read
            // from hourly buckets alone is blind to everything since the last fold — which, an
            // hour after a busy release, is the exact window somebody is looking at. The minute
            // buckets newer than the newest folded one close that gap without double counting,
            // because a minute is only ever in one of the two sets.
            if ($window['resolution'] !== 'minute') {
                $rows = $rows->concat($this->unfoldedTail($window['resolution'], $from, $until, $narrow));
            }

            return $this->summariseBuckets($rows, $window['minutes']);
        } catch (\Throwable) {
            return $this->emptySummary();
        }
    }

    /**
     * Minute buckets that no rollup has folded into the given resolution yet.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function unfoldedTail(string $resolution, \Illuminate\Support\Carbon $from, ?\Illuminate\Support\Carbon $until, callable $narrow): \Illuminate\Support\Collection
    {
        $newestFolded = $this->connection()->table('monitoring_request_buckets')
            ->where('resolution', $resolution)
            ->max('bucket_at');

        // Where nothing has been folded at all, every minute in the window is the tail.
        $boundary = $newestFolded !== null
            ? Clock::parse($newestFolded)->addMinutes($resolution === 'day' ? 1440 : 60)
            : $from;

        return $narrow($this->connection()->table('monitoring_request_buckets')
            ->where('resolution', 'minute')
            ->where('bucket_at', '>=', $boundary->max($from)))
            ->get();
    }

    /**
     * Fold a set of bucket rows into one summary with real percentiles.
     *
     * @param  iterable<object>  $rows
     * @return array<string, mixed>
     */
    public function summariseBuckets(iterable $rows, int $windowMinutes): array
    {
        $histogram = new Histogram();
        $hits = 0;
        $errors = 0;
        $clientErrors = 0;
        $timeouts = 0;
        $dbMs = 0;
        $dbQueries = 0;
        $externalMs = 0;
        $responseBytes = 0;
        $requestBytes = 0;

        foreach ($rows as $row) {
            $hits += (int) $row->hits;
            $errors += (int) $row->errors;
            $clientErrors += (int) ($row->client_errors ?? 0);
            $timeouts += (int) ($row->timeouts ?? 0);
            $dbMs += (int) ($row->db_ms_sum ?? 0);
            $dbQueries += (int) ($row->db_query_count ?? 0);
            $externalMs += (int) ($row->external_ms_sum ?? 0);
            $responseBytes += (int) ($row->response_bytes_sum ?? 0);
            $requestBytes += (int) ($row->request_bytes_sum ?? 0);

            $histogram->merge(Histogram::fromState(
                json_decode((string) ($row->duration_buckets ?? '[]'), true) ?: [],
                (int) $row->hits,
                (float) ($row->duration_sum_ms ?? 0),
                isset($row->duration_min_ms) ? (float) $row->duration_min_ms : null,
                isset($row->duration_max_ms) ? (float) $row->duration_max_ms : null,
            ));
        }

        if ($hits === 0) {
            return $this->emptySummary();
        }

        $percentiles = $histogram->standardPercentiles();

        return [
            'has_data' => true,
            'hits' => $hits,
            'errors' => $errors,
            'client_errors' => $clientErrors,
            'timeouts' => $timeouts,
            'error_rate' => round(100 * $errors / $hits, 3),
            'client_error_rate' => round(100 * $clientErrors / $hits, 3),
            'timeout_rate' => round(100 * $timeouts / $hits, 3),
            'requests_per_second' => round($hits / max(1, $windowMinutes * 60), 3),
            'requests_per_minute' => round($hits / max(1, $windowMinutes), 2),
            'p50' => $percentiles['p50'],
            'p75' => $percentiles['p75'],
            'p90' => $percentiles['p90'],
            'p95' => $percentiles['p95'],
            'p99' => $percentiles['p99'],
            'max' => $percentiles['max'],
            'avg' => $percentiles['avg'],
            'db_ms_avg' => round($dbMs / $hits, 1),
            'db_queries_avg' => round($dbQueries / $hits, 1),
            'external_ms_avg' => round($externalMs / $hits, 1),
            'response_bytes_avg' => (int) round($responseBytes / $hits),
            'request_bytes_avg' => (int) round($requestBytes / $hits),
        ];
    }

    /**
     * The same summary for this window and the one before it, with the deltas already computed.
     *
     * @return array<string, mixed>
     */
    public function comparison(string $range, ?string $route = null, ?string $channel = null): array
    {
        [$previousStart, $previousEnd] = $this->previousWindow($range);

        $current = $this->requestSummary($range, $route, $channel);
        $previous = $this->requestSummary($range, $route, $channel, since: $previousStart, until: $previousEnd);

        $delta = [];
        foreach (['hits', 'error_rate', 'p95', 'p99', 'avg', 'db_ms_avg', 'requests_per_second'] as $field) {
            $now = $current[$field] ?? null;
            $before = $previous[$field] ?? null;

            // No baseline means no percentage. An unexplained "0%" reads as "nothing changed",
            // which is a different claim from "there is nothing to compare against".
            $delta[$field] = ($now === null || $before === null || $before == 0)
                ? null
                : round(100 * ($now - $before) / $before, 1);
        }

        return ['current' => $current, 'previous' => $previous, 'delta' => $delta];
    }

    /**
     * Per-route breakdown, ordered by whatever the operator is hunting.
     *
     * @param  string  $sort  hits | p95 | errors | total_time
     * @return array<int, array<string, mixed>>
     */
    public function routeBreakdown(string $range, string $sort = 'hits', int $limit = 25, ?string $channel = null): array
    {
        $window = $this->window($range);

        try {
            $rows = $this->connection()->table('monitoring_request_buckets')
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $this->since($range))
                ->when($channel !== null, fn ($query) => $query->where('channel', $channel))
                ->get();

            $byRoute = [];
            foreach ($rows as $row) {
                $key = $row->channel . ' ' . $row->method . ' ' . $row->route;
                $byRoute[$key][] = $row;
            }

            $routes = [];
            foreach ($byRoute as $key => $group) {
                $summary = $this->summariseBuckets($group, $window['minutes']);
                if (!$summary['has_data']) {
                    continue;
                }
                [$channelName, $method, $route] = explode(' ', $key, 3);
                $routes[] = array_merge($summary, [
                    'channel' => $channelName,
                    'method' => $method,
                    'route' => $route,
                    // Total time is the honest ranking for "what should I fix": a 40ms endpoint
                    // called a million times costs more than a 4s one called twice.
                    'total_time_ms' => (int) round($summary['avg'] * $summary['hits']),
                ]);
            }

            usort($routes, match ($sort) {
                'p95' => static fn ($a, $b) => ($b['p95'] ?? 0) <=> ($a['p95'] ?? 0),
                'errors' => static fn ($a, $b) => [$b['errors'], $b['error_rate']] <=> [$a['errors'], $a['error_rate']],
                'total_time' => static fn ($a, $b) => $b['total_time_ms'] <=> $a['total_time_ms'],
                default => static fn ($a, $b) => $b['hits'] <=> $a['hits'],
            });

            return array_slice($routes, 0, $limit);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * A request-rate line for charting, split by status class.
     *
     * @return array<string, mixed>
     */
    public function requestTimeline(string $range): array
    {
        $window = $this->window($range);

        try {
            $rows = $this->connection()->table('monitoring_request_buckets')
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $this->since($range))
                ->groupBy('bucket_at')
                ->orderBy('bucket_at')
                ->limit(max(1, $window['points']) * 4)
                ->get([
                    'bucket_at',
                    DB::raw('SUM(hits) as hits'),
                    DB::raw('SUM(errors) as errors'),
                    DB::raw('SUM(client_errors) as client_errors'),
                    DB::raw('SUM(duration_sum_ms) as duration_sum_ms'),
                ]);

            $points = [];
            foreach ($rows as $row) {
                $hits = (int) $row->hits;
                $points[] = [
                    't' => Clock::parse($row->bucket_at)->toIso8601String(),
                    'hits' => $hits,
                    'errors' => (int) $row->errors,
                    'client_errors' => (int) $row->client_errors,
                    'avg_ms' => $hits > 0 ? round((int) $row->duration_sum_ms / $hits, 1) : null,
                ];
            }

            return ['points' => $points, 'resolution' => $window['resolution']];
        } catch (\Throwable) {
            return ['points' => [], 'resolution' => $window['resolution']];
        }
    }

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        // Explicitly "no data", not a row of zeros: zero requests and zero measurements are
        // different facts and the UI renders them differently.
        return [
            'has_data' => false,
            'hits' => 0,
            'errors' => 0,
            'client_errors' => 0,
            'timeouts' => 0,
            'error_rate' => null,
            'client_error_rate' => null,
            'timeout_rate' => null,
            'requests_per_second' => null,
            'requests_per_minute' => null,
            'p50' => null, 'p75' => null, 'p90' => null, 'p95' => null, 'p99' => null,
            'max' => null, 'avg' => null,
            'db_ms_avg' => null, 'db_queries_avg' => null, 'external_ms_avg' => null,
            'response_bytes_avg' => null, 'request_bytes_avg' => null,
        ];
    }
}
