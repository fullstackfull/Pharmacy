<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Ingest\AppHealthRecorder;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\ReadsFoldedSeries;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * One shop's mobile app, from the only two places anything can be known about it.
 *
 * THE SERVER SEES CONVERSATION, NOT EXPERIENCE. Every request the app makes is measured here
 * already — how much it talks, how fast it is answered, which versions are in the wild — and none
 * of that needs the app to cooperate, because the shop is the other end of the conversation. It is
 * also everything the server can ever know. An app that crashed on launch sends nothing, and
 * "sends nothing" is indistinguishable from a quiet Tuesday.
 *
 * SO STABILITY IS SELF-REPORTED, AND SAYS SO. Sessions, crashes and ANRs arrive from the app
 * itself, at POST /api/v1/app-health. The card carries that provenance rather than presenting a
 * crash-free percentage as measured — a 100% crash-free figure derived from an app that never
 * reported anything is a lie with a reassuring shape. When nothing has been reported the card is
 * not_configured with the exact call to make, not a zero.
 *
 * THE VERSION TABLE IS WHY THE PAGE EXISTS. Traffic and crashes are both broken down by the
 * version that produced them, side by side, because the useful sentence is almost never "the app
 * is crashing" — it is "4.1.9 is crashing and 4.2.1 is not", which is the difference between an
 * outage and a rollout to finish. The two halves come from different sources and the table says
 * which cells came from where.
 *
 * Android and iOS are the same page over a different platform, so they are the same class over a
 * different platform() — the alternative is two files that drift the first time one is fixed.
 */
abstract class MobileAppPanel implements Panel
{
    use ReadsFoldedSeries;

    /** Traffic, measured server-side. `n` is requests, `sum` is total milliseconds. */
    private const TRAFFIC = 'requests.by_platform';

    private const TRAFFIC_ERRORS = 'requests.by_platform.errors';

    private const TRAFFIC_CLIENT_ERRORS = 'requests.by_platform.client_errors';

    /** The same, split by the version the app announced in X-App-Version. */
    private const VERSION_TRAFFIC = 'requests.by_app_version';

    private const VERSION_ERRORS = 'requests.by_app_version.errors';

    /** The label BucketWriter folds the long tail of client-supplied labels into. */
    private const FOLDED_LABEL = '__other__';

    /** Versions listed before the rest are summarised as a remainder. */
    private const MAX_VERSIONS = 12;

    /** Buckets the chart draws, whatever the window asks for. */
    private const MAX_TIMELINE_BUCKETS = 400;

    private const TRAFFIC_SOURCE = 'monitoring_series (requests.by_platform*, requests.by_app_version*)';

    private const HEALTH_SOURCE = 'monitoring_series (app.health.*)';

    private const MIDDLEWARE = 'app/Http/Middleware/MonitorRequest.php';

    private const INGEST = 'app/Http/Controllers/RestAPI/v1/AppHealthController.php';

    public function __construct(protected readonly SeriesReader $reader)
    {
    }

    /** Which app this page is about. The only difference between the two sections. */
    abstract protected function platform(): string;

    /**
     * The header the app must send for its traffic to be attributed here at all.
     *
     * Named on the page rather than assumed: a shop whose app sends no X-Platform is classified
     * from its user agent, which works for okhttp and CFNetwork and not for a custom one — so an
     * empty page has two possible causes and the operator needs to be able to tell them apart.
     */
    abstract protected function userAgentHint(): string;

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $traffic = $this->traffic($range, $window);
        $health = $this->health($range, $window);

        return [
            'platform' => $this->platform(),
            'window' => [
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
                'resolution' => $window['resolution'],
            ],
            'traffic' => $traffic,
            'timeline' => $this->timeline($range, $window),
            'stability' => $health,
            'versions' => $this->versions($traffic, $health),
            'reporting' => $this->reporting(),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // What the server measured

    /**
     * Requests, latency and error rates for this platform, and the same split by app version.
     *
     * One read for all five series rather than five reads: they share a resolution, a window and an
     * index, and the split by metric costs nothing in PHP.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function traffic(string $range, array $window): array
    {
        $prefix = $this->platform() . ':';

        try {
            $read = $this->acrossSeam($range, $window, fn (string $resolution, Carbon $from, ?Carbon $until) => $this->reader
                ->connection()
                ->table('monitoring_series')
                ->selectRaw('metric, label, SUM(samples) AS readings, SUM(value_sum) AS total')
                ->whereIn('metric', [
                    self::TRAFFIC, self::TRAFFIC_ERRORS, self::TRAFFIC_CLIENT_ERRORS,
                    self::VERSION_TRAFFIC, self::VERSION_ERRORS,
                ])
                ->where(fn ($query) => $query
                    ->where('label', $this->platform())
                    ->orWhere('label', 'like', $prefix . '%')
                    ->orWhere('label', self::FOLDED_LABEL))
                ->where('resolution', $resolution)
                ->where('bucket_at', '>=', $from)
                ->when($until !== null, fn ($query) => $query->where('bucket_at', '<', $until))
                ->groupBy('metric', 'label'));
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => 'The series table is created by `php artisan migrate`. Check the monitoring connection is reachable and migrated.',
                'source' => self::TRAFFIC_SOURCE,
                'metrics' => [],
                'versions' => [],
                'folded' => 0,
            ];
        }

        $totals = ['requests' => 0, 'duration_ms' => 0.0, 'errors' => 0, 'client_errors' => 0];
        $versions = [];
        $folded = 0;

        foreach ($read['rows'] as $row) {
            $readings = (int) $row->readings;

            match ($row->metric) {
                self::TRAFFIC => $this->addTraffic($totals, $readings, (float) $row->total),
                self::TRAFFIC_ERRORS => $totals['errors'] += $readings,
                self::TRAFFIC_CLIENT_ERRORS => $totals['client_errors'] += $readings,
                default => null,
            };

            if ($row->metric !== self::VERSION_TRAFFIC && $row->metric !== self::VERSION_ERRORS) {
                continue;
            }

            // The folded label is not this platform's — BucketWriter folds every client-labelled
            // series into one `__other__` row, so it may hold another platform's tail. Counted as
            // a remainder rather than attributed to a version that would then be wrong.
            if ($row->label === self::FOLDED_LABEL) {
                $folded += $row->metric === self::VERSION_TRAFFIC ? $readings : 0;
                continue;
            }

            $version = substr($row->label, strlen($prefix));
            $versions[$version] ??= ['version' => $version, 'requests' => 0, 'duration_ms' => 0.0, 'errors' => 0];

            if ($row->metric === self::VERSION_TRAFFIC) {
                $versions[$version]['requests'] += $readings;
                $versions[$version]['duration_ms'] += (float) $row->total;
            } else {
                $versions[$version]['errors'] += $readings;
            }
        }

        return [
            'state' => $totals['requests'] > 0 ? 'ok' : 'no_data',
            'note' => $totals['requests'] > 0
                ? null
                : 'This app sent no request in this window. That is a statement about traffic, not about quality — an app nobody opened and an app that cannot reach the shop look identical from here.',
            'remedy' => $totals['requests'] > 0
                ? null
                : 'Traffic is attributed by the X-Platform header, falling back to the user agent (' . $this->userAgentHint() . '). An app sending neither is counted as web.',
            'source' => self::TRAFFIC_SOURCE,
            'metrics' => $this->trafficMetrics($totals),
            'totals' => $totals,
            'versions' => $versions,
            'folded' => $folded,
        ];
    }

    /**
     * @param  array<string, float|int>  $totals
     */
    private function addTraffic(array &$totals, int $readings, float $duration): void
    {
        $totals['requests'] += $readings;
        $totals['duration_ms'] += $duration;
    }

    /**
     * @param  array<string, float|int>  $totals
     * @return array<string, Metric>
     */
    private function trafficMetrics(array $totals): array
    {
        $requests = (int) $totals['requests'];

        if ($requests === 0) {
            // Every figure below is a ratio of this, so none of them exists yet. Drawn as no_data
            // rather than as the zeroes the arithmetic would produce.
            return [
                'requests_from_this_app' => Metric::noData(self::TRAFFIC_SOURCE),
                'mean_response_time' => Metric::noData(self::TRAFFIC_SOURCE),
                'server_error_rate' => Metric::noData(self::TRAFFIC_SOURCE),
                'client_error_rate' => Metric::noData(self::TRAFFIC_SOURCE),
                'app_versions_seen' => Metric::noData(self::TRAFFIC_SOURCE),
            ];
        }

        return [
            'requests_from_this_app' => Metric::of($requests, self::TRAFFIC_SOURCE, 'requests'),
            'mean_response_time' => Metric::of(
                round($totals['duration_ms'] / $requests, 1),
                self::TRAFFIC_SOURCE,
                'ms',
                'The mean, not a percentile: the per-minute buckets hold a sum and a count, so a p95 cannot be derived from them.',
            ),
            'server_error_rate' => Metric::of(round(100 * $totals['errors'] / $requests, 2), self::TRAFFIC_SOURCE, '%'),
            'client_error_rate' => Metric::of(
                round(100 * $totals['client_errors'] / $requests, 2),
                self::TRAFFIC_SOURCE,
                '%',
                'A 4xx is usually the app asking for something that is gone or sending an expired token, not the server failing.',
            ),
        ];
    }

    /**
     * Requests from this app over the window.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function timeline(string $range, array $window): array
    {
        $series = $this->reader->series(self::TRAFFIC, $range, $this->platform());

        return [
            'state' => $series['points'] === [] ? 'no_data' : 'ok',
            'resolution' => $window['resolution'],
            'source' => self::TRAFFIC_SOURCE,
            // The shared chart renderer reads `hits`; only the field name is adapted.
            'points' => array_map(
                static fn (array $point) => ['t' => $point['t'], 'hits' => $point['v'] ?? 0],
                array_slice($series['points'], -self::MAX_TIMELINE_BUCKETS),
            ),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // What the app reported about itself

    /**
     * Sessions, crashes and ANRs as the app counted them.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function health(string $range, array $window): array
    {
        $prefix = $this->platform() . ':';

        try {
            $read = $this->acrossSeam($range, $window, fn (string $resolution, Carbon $from, ?Carbon $until) => $this->reader
                ->connection()
                ->table('monitoring_series')
                ->selectRaw('metric, label, SUM(samples) AS readings')
                ->where('metric', 'like', AppHealthRecorder::SERIES . '%')
                ->where('label', 'like', $prefix . '%')
                ->where('resolution', $resolution)
                ->where('bucket_at', '>=', $from)
                ->when($until !== null, fn ($query) => $query->where('bucket_at', '<', $until))
                ->groupBy('metric', 'label'));
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => null,
                'source' => self::HEALTH_SOURCE,
                'metrics' => [],
                'versions' => [],
                'reported' => false,
            ];
        }

        $totals = array_fill_keys(AppHealthRecorder::KINDS, 0);
        $versions = [];

        foreach ($read['rows'] as $row) {
            $kind = substr($row->metric, strlen(AppHealthRecorder::SERIES));

            if (!in_array($kind, AppHealthRecorder::KINDS, true)) {
                continue;
            }

            $version = substr($row->label, strlen($prefix));
            $versions[$version] ??= array_fill_keys(AppHealthRecorder::KINDS, 0);
            $versions[$version][$kind] += (int) $row->readings;
            $totals[$kind] += (int) $row->readings;
        }

        $sessions = $totals['sessions'];

        if ($sessions === 0) {
            return [
                'state' => 'not_configured',
                'note' => 'This app has not reported a single session, so nothing here can say whether it stayed running. A crash sends no request — it is the absence of traffic — so the server cannot infer this one.',
                'remedy' => 'Have the app POST to /api/v1/app-health on launch: {"platform":"' . $this->platform() . '","app_version":"…","sessions":1,"crashes":0}. '
                    . 'Counters only — no stack traces, device identifiers or user ids are accepted. It needs no token and always answers 204.',
                'source' => self::HEALTH_SOURCE,
                'metrics' => [],
                'versions' => $versions,
                'reported' => false,
            ];
        }

        $crashFree = round(100 * max(0, $sessions - $totals['crashes']) / $sessions, 2);

        return [
            'state' => 'ok',
            'note' => 'Reported by the app itself, not measured here.',
            'remedy' => null,
            'source' => self::HEALTH_SOURCE,
            'metrics' => [
                'crash_free_sessions' => Metric::of($crashFree, self::HEALTH_SOURCE, '%'),
                'sessions_reported' => Metric::of($sessions, self::HEALTH_SOURCE, 'sessions'),
                'crashes_reported' => Metric::of($totals['crashes'], self::HEALTH_SOURCE, 'crashes'),
                'app_not_responding' => Metric::of($totals['anrs'], self::HEALTH_SOURCE, 'events'),
            ],
            'versions' => $versions,
            'totals' => $totals,
            'reported' => true,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The two halves, laid against each other

    /**
     * One row per version: what it sent, and what it reported about itself.
     *
     * A version can appear on one side and not the other — traffic with no health report is an app
     * that has not been updated to send them; a health report with no traffic is an app that
     * crashed before its first call, which is the most interesting row on the page. Both are kept,
     * with the missing half left null rather than filled with a zero that would read as a measured
     * "no crashes".
     *
     * @param  array<string, mixed>  $traffic
     * @param  array<string, mixed>  $health
     * @return array<string, mixed>
     */
    private function versions(array $traffic, array $health): array
    {
        $names = array_unique(array_merge(
            array_keys($traffic['versions'] ?? []),
            array_keys($health['versions'] ?? []),
        ));

        $rows = [];

        foreach ($names as $version) {
            $seen = $traffic['versions'][$version] ?? null;
            $reported = $health['versions'][$version] ?? null;
            $sessions = $reported['sessions'] ?? 0;

            $rows[] = [
                'version' => $version,
                'requests' => $seen['requests'] ?? null,
                'mean_ms' => ($seen['requests'] ?? 0) > 0 ? round($seen['duration_ms'] / $seen['requests'], 1) : null,
                'error_rate' => ($seen['requests'] ?? 0) > 0 ? round(100 * $seen['errors'] / $seen['requests'], 2) : null,
                'sessions' => $reported === null ? null : $sessions,
                'crashes' => $reported === null ? null : $reported['crashes'],
                'crash_free' => $sessions > 0 ? round(100 * max(0, $sessions - $reported['crashes']) / $sessions, 2) : null,
            ];
        }

        // Busiest first, and a version that only reported crashes still sorts by what it did report
        // rather than sinking below every version with a single request.
        usort($rows, static fn (array $a, array $b) => (($b['requests'] ?? 0) + ($b['sessions'] ?? 0))
            <=> (($a['requests'] ?? 0) + ($a['sessions'] ?? 0)));

        return [
            'state' => $rows === [] ? 'no_data' : 'ok',
            'note' => $rows === []
                ? 'No app version has identified itself in this window.'
                : null,
            'remedy' => $rows === []
                ? 'Send X-App-Version on every request (any string of up to 32 characters: 4.2.1, 4.2.1-rc3). Without it traffic is still counted for the platform, it just cannot be attributed to a release.'
                : null,
            'source' => self::TRAFFIC_SOURCE . ' + ' . self::HEALTH_SOURCE,
            'rows' => array_slice($rows, 0, self::MAX_VERSIONS),
            'truncated' => count($rows) > self::MAX_VERSIONS,
            'limit' => self::MAX_VERSIONS,
            // Requests whose version was folded away by the client-label cap. Stated rather than
            // dropped: a table whose rows do not add up to the headline has to say why.
            'folded_requests' => $traffic['folded'] ?? 0,
        ];
    }

    /**
     * The contract, on the page.
     *
     * Everything above is either present or absent because of these three things, and an operator
     * looking at an empty section needs them without leaving it for the developer portal.
     *
     * @return array<string, mixed>
     */
    private function reporting(): array
    {
        return [
            'platform_header' => 'X-Platform: ' . $this->platform(),
            'version_header' => 'X-App-Version: 4.2.1',
            'user_agent_fallback' => $this->userAgentHint(),
            'health_endpoint' => 'POST ' . rtrim((string) config('app.url'), '/') . '/api/v1/app-health',
            'health_body' => '{"platform":"' . $this->platform() . '","app_version":"4.2.1","sessions":1,"crashes":0,"anrs":0}',
            'measured_by' => self::MIDDLEWARE,
            'reported_to' => self::INGEST,
        ];
    }
}
