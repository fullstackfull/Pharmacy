<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use Illuminate\Http\Request;

/**
 * The iOS app, seen from the only place this shop can see it: the requests it sends.
 *
 * Traffic, latency and version mix are shared with Android and live in MobileAppPanel — the two
 * sections ask the same questions of the same series one platform apart, and two copies of that
 * would drift the first time either was fixed. What is added here is what is NOT the same:
 *
 * WHAT "iOS" MEANS. Every figure on this page is the subset of requests that MonitorRequest
 * decided was iOS, and that decision is a header first and a user-agent guess second. The guess
 * matches darwin, cfnetwork, iphone and ipad — which is not the same set as "iPhones and iPads".
 * It admits mobile Safari on an iPhone and any Mac client built on URLSession, and it misses an
 * iPad in desktop mode, which sends a Macintosh user agent and is counted as web. So on a shop
 * whose app does not set X-Platform this section is closer to "Apple clients" than to "the iOS
 * app". The rule is published as an ordered list of the branches the middleware actually takes, so
 * the number can be challenged rather than merely believed, and the share of it that rests on the
 * guess is published as an unconfigured reading, because nothing records which branch fired.
 *
 * THE REQUEST TIMELINE. monitoring_series stores a counter's request count in `samples` and its
 * millisecond total in `value_sum`; SeriesReader::series() returns `value_sum` for a counter,
 * which is the right answer for a summed gauge and the wrong one for a request count. Drawing it
 * under a chart labelled "requests" put the millisecond total on the axis. So the chart is read
 * here from `samples`, with the 5xx counter as a second line — and the two lines are two counters
 * over one bucket key, not a ratio computed anywhere.
 *
 * WHAT THIS PAGE CANNOT SEE. Start-up time, crash detail, hangs, per-version 4xx and requests that
 * never left the handset are named as unconfigured readings with the exact change each needs. They
 * are not drawn as zeroes, and not left off the page: a section that silently omits start-up time
 * reads as a section where start-up time is fine.
 *
 * Nothing in the URL reaches a query here. The panel takes no filters at all, so a hostile query
 * string has nothing to arrive in; `$range` is validated by the controller before it is passed.
 */
class IosPanel extends MobileAppPanel
{
    /** Requests per bucket for one platform: `samples` is the count, `value_sum` the millisecond total. */
    private const TRAFFIC = 'requests.by_platform';

    /** 5xx responses to the same platform, counted in `samples`. */
    private const TRAFFIC_ERRORS = 'requests.by_platform.errors';

    /**
     * Buckets the chart draws, newest first, before the tail is dropped.
     *
     * Two metrics per bucket, so the row cap is twice the point cap. Read newest-first and
     * reversed for display: ordering oldest-first and truncating would drop the newest buckets,
     * which is the half of the chart anybody opened it for.
     */
    private const MAX_TIMELINE_POINTS = 200;

    private const TIMELINE_SOURCE = 'monitoring_series (requests.by_platform, requests.by_platform.errors)';

    private const MIDDLEWARE = 'app/Http/Middleware/MonitorRequest.php';

    private const RECORDER = 'app/Services/Monitoring/Ingest/RequestRecorder.php';

    private const INGEST = 'app/Services/Monitoring/Ingest/AppHealthRecorder.php';

    protected function platform(): string
    {
        return 'ios';
    }

    protected function userAgentHint(): string
    {
        // What MonitorRequest::platform() looks for when no X-Platform header arrives — CFNetwork
        // and Darwin are in the user agent of anything built on URLSession, which is every native
        // iOS HTTP client, and iphone/ipad catch a WKWebView shell.
        return 'darwin, cfnetwork, iphone, ipad';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);

        return array_merge($this->shared($range, $request), [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'identification' => $this->identification(),
            // Replaces the shared timeline, which reads the millisecond total where the chart
            // expects a request count. See the class doc.
            'timeline' => $this->requestTimeline($range, $window),
            'not_measured' => $this->notMeasured(),
        ]);
    }

    /**
     * The half of the page Android and iOS share, with the one card that goes missing put back.
     *
     * `app_versions_seen` is published by the shared panel only when there is NO traffic, so the
     * card vanishes at the exact moment it has something to say. It is a count of what the version
     * table below already lists, so it costs no read.
     *
     * @return array<string, mixed>
     */
    private function shared(string $range, Request $request): array
    {
        $data = parent::data($range, $request);

        $versions = $data['traffic']['versions'] ?? [];

        if (($data['traffic']['state'] ?? null) === 'ok' && !isset($data['traffic']['metrics']['app_versions_seen'])) {
            $data['traffic']['metrics']['app_versions_seen'] = Metric::of(
                value: count($versions),
                source: 'monitoring_series (requests.by_app_version)',
                unit: null,
                note: $versions === []
                    ? 'This app sent traffic but no request carried an X-App-Version header, so none of it can be attributed to a release.'
                    : null,
            );
        }

        return $data;
    }

    // -------------------------------------------------------------------------------------------
    // What counts as iOS here

    /**
     * How a request comes to be on this page, transcribed from the branch that decides it.
     *
     * Published as the middleware's own ordered rules rather than as a sentence, because the
     * useful question is never "is this figure right" but "which of these five rules produced it",
     * and only the first two of them are the app declaring itself. The order matters more on this
     * platform than on the other one: the Android branch is tested first, so a client whose agent
     * somehow satisfies both is not counted here at all.
     *
     * @return array<string, mixed>
     */
    private function identification(): array
    {
        return [
            'source' => self::MIDDLEWARE . '::platform()',
            'recorder' => self::RECORDER . '::finishRequest()',
            'header' => 'X-Platform: ios',
            'version_header' => 'X-App-Version: 4.2.1',
            'version_pattern' => '^[0-9A-Za-z.+-]{1,32}$',
            'declared' => true,
            'rules' => [
                [
                    'test' => 'X-Platform header is ios (any case)',
                    'outcome' => 'Counted on this page. The header is read first and nothing else is consulted.',
                    'certain' => true,
                ],
                [
                    'test' => 'X-Platform header is android or web',
                    'outcome' => 'Not counted here, whatever the user agent says.',
                    'certain' => true,
                ],
                [
                    'test' => 'No usable X-Platform, and the user agent contains darwin, cfnetwork, iphone or ipad',
                    'outcome' => 'Counted on this page — unless it also contains okhttp or android, which is tested first and wins. This is an inference, not a declaration.',
                    'certain' => false,
                ],
                [
                    'test' => 'No usable X-Platform, and the user agent matches neither iOS nor Android',
                    'outcome' => 'Counted as web. An iPad in desktop mode lands here: it sends a Macintosh user agent.',
                    'certain' => true,
                ],
                [
                    'test' => 'No user agent at all',
                    'outcome' => 'No platform is recorded. The request is in the shop totals and on no platform page.',
                    'certain' => true,
                ],
            ],
            'caveat' => 'Rule three is a guess and it can be wrong in both directions. Mobile Safari puts the word "iPhone" in its'
                . ' user agent and every Mac application built on URLSession puts "CFNetwork" and "Darwin" in its own, so a'
                . ' shopper browsing on a handset — or a Mac client that is not this app at all — is counted here whenever the'
                . ' app-only header is absent; an iPad in desktop mode sends a Macintosh user agent and is counted as web'
                . ' instead. On a deployment whose app does not send X-Platform, read this section as "Apple clients" rather'
                . ' than as "the iOS app".',
            'remedy' => 'Send `X-Platform: ios` from the app on every request. It is checked before the user agent, so it'
                . ' removes the ambiguity entirely — and send `X-App-Version` with it, or the traffic cannot be attributed to a release.',
            // The one number that would settle the caveat, and nothing produces it.
            'attribution_split' => Metric::notConfigured(
                source: 'monitoring_series (requests.by_platform)',
                remedy: 'Record which branch decided the platform — a `requests.by_platform_source|ios:header` /'
                    . ' `|ios:agent` counter written beside the existing one in ' . self::RECORDER . '::finishRequest()'
                    . ' would do it, and costs one more increment on a path that already writes four.',
                note: 'Nothing records whether a request was filed here because it declared X-Platform or because its user'
                    . ' agent merely looked like a Darwin client, so the share of this page that rests on the guess cannot'
                    . ' be quantified. It is not zero and it is not known.',
            ),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The chart

    /**
     * Requests and 5xx responses from this app, one point per bucket.
     *
     * Read straight from the two counters rather than through SeriesReader::series(), which
     * returns `value_sum` for a counter — correct for a summed gauge, and the millisecond total
     * rather than the request count for this one.
     *
     * Bounded three ways: the window, the indexed bucket_at it is bounded on, and a hard row cap.
     * Rides monitoring_series_lookup (metric, resolution, bucket_at); the label is filtered after
     * the index has already narrowed the read to two metrics inside one window.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function requestTimeline(string $range, array $window): array
    {
        $resolution = (string) $window['resolution'];
        $rowLimit = self::MAX_TIMELINE_POINTS * 2;

        try {
            $rows = $this->reader->connection()->table('monitoring_series')
                ->whereIn('metric', [self::TRAFFIC, self::TRAFFIC_ERRORS])
                ->where('label', $this->platform())
                ->where('resolution', $resolution)
                ->where('bucket_at', '>=', $this->reader->since($range))
                // Newest first, then reversed below. Ordering oldest-first and cutting at the limit
                // would drop the most recent buckets, which is the end of the chart anyone is
                // actually looking at.
                ->orderByDesc('bucket_at')
                ->limit($rowLimit + 1)
                ->get(['bucket_at', 'metric', 'samples', 'value_sum']);
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: losing the chart must not blank the
            // traffic, version and stability cards, which were read perfectly well.
            return [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => 'The series table is created by `php artisan migrate`. Check the monitoring connection is reachable and migrated.',
                'source' => self::TIMELINE_SOURCE,
                'resolution' => $resolution,
                'points' => [],
                'truncated' => false,
                'limit' => self::MAX_TIMELINE_POINTS,
            ];
        }

        $truncated = $rows->count() > $rowLimit;
        $buckets = [];

        foreach ($rows->take($rowLimit) as $row) {
            $at = $this->isoStamp($row->bucket_at);
            if ($at === null) {
                continue;
            }

            // hits starts null, not 0: a bucket holding only an error row would otherwise claim a
            // measured zero requests beside a non-zero error count.
            $buckets[$at] ??= ['t' => $at, 'hits' => null, 'errors' => 0, 'avg_ms' => null];

            $samples = (int) $row->samples;

            if ($row->metric === self::TRAFFIC) {
                $buckets[$at]['hits'] = $samples;
                $buckets[$at]['avg_ms'] = $samples > 0 ? round((float) $row->value_sum / $samples, 1) : null;

                continue;
            }

            $buckets[$at]['errors'] = $samples;
        }

        ksort($buckets);

        $orphans = count(array_filter($buckets, static fn (array $point) => $point['hits'] === null));
        $points = array_values(array_filter($buckets, static fn (array $point) => $point['hits'] !== null));

        return [
            // A single reading is not a line, and the chart renderer needs two points to draw one.
            'state' => count($points) >= 2 ? 'ok' : 'no_data',
            'note' => $this->timelineNote($points, $resolution, $orphans),
            'remedy' => null,
            'source' => self::TIMELINE_SOURCE,
            'resolution' => $resolution,
            'points' => $points,
            'truncated' => $truncated,
            'limit' => self::MAX_TIMELINE_POINTS,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $points
     */
    private function timelineNote(array $points, string $resolution, int $orphans): ?string
    {
        $note = match (count($points)) {
            0 => 'No ' . $resolution . ' bucket in this window holds a request from this app, so there is no line to draw.',
            1 => 'Only one ' . $resolution . ' bucket in this window holds a request from this app. One reading is a value, not a trend.',
            default => $resolution === 'minute'
                ? null
                : 'The newest ' . $resolution . ' bucket is still filling, so its point covers a part of that ' . $resolution . ' rather than all of it.',
        };

        if ($orphans > 0) {
            // Never silently dropped: a bucket with an error count and no request count means the
            // two counters disagree, which is a finding about the writer rather than about the app.
            $note = trim(($note ?? '') . ' ' . $orphans . ' bucket(s) recorded a failed response with no matching request count and are not drawn.');
        }

        return $note === '' ? null : $note;
    }

    private function isoStamp(mixed $stored): ?string
    {
        try {
            return Clock::parse($stored)->toIso8601String();
        } catch (\Throwable) {
            // A bucket whose stamp cannot be parsed has no place on a time axis, and guessing one
            // would put a real measurement at an invented moment.
            return null;
        }
    }

    // -------------------------------------------------------------------------------------------
    // What this section cannot see

    /**
     * The questions an iOS section is opened for that this deployment cannot answer.
     *
     * Published as readings with their reason rather than left off the page. A section that simply
     * omits start-up time reads as a section where start-up time is fine, and a crash count with
     * no diagnosis beside it reads as a crash count nobody needed to explain.
     *
     * @return array<string, mixed>
     */
    private function notMeasured(): array
    {
        return [
            'state' => 'not_configured',
            'source' => self::TIMELINE_SOURCE . ', ' . self::INGEST,
            'note' => 'These are not empty measurements. Nothing on this deployment produces them at all, so each names the'
                . ' exact change that would, and none of them is drawn as a zero or a percentage.',
            'fields' => [
                'app_start_time' => Metric::notConfigured(
                    source: 'no start-up ingest',
                    remedy: 'Extend the app-health report with a start-up distribution: ' . self::INGEST . ' accepts three'
                        . ' integer counters today, so add bucketed fields (start_ms_le_500, start_ms_le_1000, start_ms_le_2000, …)'
                        . ' written into monitoring_series exactly as sessions and crashes are. On this platform the app does'
                        . ' not have to time anything itself — MetricKit delivers a launch-time histogram once a day — so it'
                        . ' has a distribution to post rather than a mean it would have to invent.',
                    note: 'Cold start is timed on the handset, between process launch and the first frame. The shop sees'
                        . ' neither: the first request an app makes arrives long after its screen is up, so no arrival time'
                        . ' here is a start-up time.',
                ),
                'crash_diagnostics' => Metric::notConfigured(
                    source: 'monitoring_error_groups, monitoring_errors',
                    remedy: 'Both tables already carry platform, app_version, release, fingerprint and stack_trace and nothing'
                        . ' in this build writes either. A crash-report endpoint — fed by MetricKit crash diagnostics, which'
                        . ' arrive on the device already symbolicated — would group app crashes the same way server'
                        . ' exceptions are grouped.',
                    note: 'Crashes are counted, never described. The app-health ingest deliberately accepts three integers and'
                        . ' stores no stack trace, device model or user id, so this page can say that a release is crashing'
                        . ' and can never say why.',
                ),
                'app_hangs' => Metric::notConfigured(
                    source: 'monitoring_series (app.health.anrs)',
                    remedy: 'Report hangs into the counter that already exists — POST {"platform":"ios","app_version":"…","anrs":N}'
                        . ' to the app-health endpoint, counting MetricKit hang diagnostics. Reporting them under a name borrowed'
                        . ' from the other platform is worth doing before adding a field: the counter is there and nothing fills it.',
                    note: '"App not responding" is an Android mechanism and iOS has no ANR; the stability card carries that'
                        . ' counter only because the ingest accepts the same three counters for both platforms. Whatever is in'
                        . ' it on this platform is whatever the app chose to post under that name, and a zero in it means'
                        . ' nothing was posted — not that no session ever hung or was killed by the watchdog.',
                ),
                'client_error_rate_by_version' => Metric::notConfigured(
                    source: 'monitoring_series (requests.by_app_version.errors)',
                    remedy: 'Add a `requests.by_app_version.client_errors` counter beside the 5xx one in ' . self::RECORDER
                        . '::finishRequest(). It is one increment on a path that already writes it for the platform.',
                    note: 'The version table counts 5xx responses only. 4xx is counted for the platform as a whole, so a'
                        . ' release that started sending expired tokens or calling a route that has moved raises nothing in'
                        . ' the per-version column — and a 4xx storm is what a bad release usually looks like first.',
                ),
                'response_time_percentiles' => Metric::notSupported(
                    source: 'monitoring_series (requests.by_platform)',
                    note: 'The platform counters hold a sum and a count, so a mean is the only honest figure derivable from'
                        . ' them. Real percentiles live on monitoring_request_buckets, whose histogram is keyed by route,'
                        . ' method and channel and carries no platform — so a p95 for this app cannot be interpolated'
                        . ' from anything stored today.',
                    remedy: 'A per-platform p95 needs a latency histogram on the platform key, which means a second bucket'
                        . ' family rather than a second column. Until then the Requests section has real percentiles for'
                        . ' the API routes this app calls, across all clients.',
                ),
                'requests_that_never_arrived' => Metric::notSupported(
                    source: self::MIDDLEWARE,
                    note: 'A request that failed to leave the handset — no signal, a DNS failure, a TLS error, a timeout on'
                        . ' the way out — is invisible here by definition. Every figure on this page is assembled from'
                        . ' requests this shop answered, so the app can be failing entirely while this section stays quiet.',
                    remedy: 'Only the app can count these. They would have to be reported the way sessions and crashes are,'
                        . ' as counters posted to the app-health endpoint.',
                ),
            ],
        ];
    }
}
