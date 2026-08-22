<?php

namespace App\Services\Analytics\Reporting;

use App\Services\Analytics\AnalyticsEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The read side: every number the Analytics screens show.
 *
 * Three rules hold this together, and each exists because of a specific way an analytics screen
 * lies to the person reading it.
 *
 * 1. TODAY IS NOT ROLLED UP YET. The rollup runs hourly, so a window that includes today is short
 *    by however long it has been since the last run. Reading only analytics_daily would make every
 *    morning look like a collapse in traffic. So today is read live from the session and event
 *    tables and spliced in — and only today, so nothing is ever counted twice.
 *
 * 2. NO DATA IS NOT ZERO. A section with nothing to show says which of the two it is: nothing
 *    happened, or nothing is collecting. Those need opposite responses from a merchant, and a
 *    zero shows them as identical.
 *
 * 3. BOTS AND STAFF ARE EXCLUDED, AND THE EXCLUSION IS VISIBLE. Every figure here is real customer
 *    traffic; the size of what was filtered is available separately, because a filter nobody can
 *    audit is indistinguishable from a filter that has silently stopped working.
 */
class AnalyticsReporting
{
    /** Dimensions the rollup writes, so a screen can never ask for one that does not exist. */
    public const DIMENSIONS = [
        'source', 'medium', 'campaign', 'device', 'os', 'browser', 'country', 'language',
        'app_version', 'landing_path', 'attribution_basis', 'new_vs_returning', 'hour', 'weekday',
        'path', 'event', 'product', 'category', 'shop', 'brand', 'gateway', 'vendor',
        'search_term', 'search_no_results', 'campaign_link', 'excluded_traffic', 'totals',
    ];

    /**
     * Is analytics collecting at all?
     *
     * Asked before anything else on every screen. An empty chart means one thing when the pipeline
     * is healthy and something entirely different when it stopped a week ago.
     *
     * @return array<string, mixed>
     */
    public function collectionHealth(): array
    {
        if (!$this->ready()) {
            return [
                'state' => 'not_installed',
                'message' => 'The analytics tables are not present on this installation. Run php artisan migrate.',
            ];
        }

        if (!config('analytics.enabled', true)) {
            return [
                'state' => 'disabled',
                'message' => 'Analytics collection is switched off (ANALYTICS_ENABLED=false). Nothing is being recorded.',
            ];
        }

        $lastEvent = $this->connection()->table('analytics_events')->max('occurred_at');
        $lastRollup = $this->connection()->table('analytics_health')->where('signal', 'rollup_ran')->value('occurred_at');
        $writeFailure = $this->connection()->table('analytics_health')->where('signal', 'write_failed')->first();

        $eventAgeMinutes = $lastEvent !== null ? (int) round(Carbon::parse($lastEvent)->diffInMinutes(Carbon::now())) : null;
        $rollupAgeHours = $lastRollup !== null ? (int) floor(Carbon::parse($lastRollup)->diffInHours(Carbon::now())) : null;

        if ($lastEvent === null) {
            return [
                'state' => 'no_events',
                'message' => 'No event has ever been recorded. Visit the storefront once — if nothing appears, check that the RecordAnalytics middleware is in the web group.',
            ];
        }

        // The rollup never having run is the exact failure that left the old Analytics page empty
        // for months, so it is called out by name rather than shown as a quiet gap in the charts.
        if ($lastRollup === null) {
            return [
                'state' => 'rollup_never_ran',
                'message' => 'Events are being collected but analytics:rollup has never run, so every window except today is empty. Install the scheduler cron: * * * * * cd ' . base_path() . ' && php artisan schedule:run',
                'last_event_at' => $lastEvent,
            ];
        }

        if ($rollupAgeHours !== null && $rollupAgeHours > 6) {
            return [
                'state' => 'rollup_stale',
                'message' => "The rollup last ran {$rollupAgeHours} hours ago, so recent days may be incomplete. Check that the scheduler is running.",
                'last_event_at' => $lastEvent,
                'last_rollup_at' => $lastRollup,
            ];
        }

        return [
            'state' => 'healthy',
            'last_event_at' => $lastEvent,
            'last_event_age_minutes' => $eventAgeMinutes,
            'last_rollup_at' => $lastRollup,
            'write_failures' => $writeFailure !== null ? (int) $writeFailure->count : 0,
            'write_failure_detail' => $writeFailure->detail ?? null,
        ];
    }

    /**
     * The headline figures for a window, with the previous period beside them.
     *
     * @return array<string, mixed>
     */
    public function totals(Window $window): array
    {
        $current = $this->totalsFor($window->fromDate(), $window->toDate(), $window->includesToday());
        $previous = $this->totalsFor($window->previousFromDate(), $window->previousToDate(), false);

        $metrics = [];

        foreach ($current as $key => $value) {
            $was = $previous[$key] ?? 0;
            $metrics[$key] = [
                'value' => $value,
                'previous' => $was,
                // A change from zero is not a percentage. Reporting "+100%" from one visit to two
                // is arithmetically true and useless; from zero it is not even that.
                'change_pct' => $was > 0 ? round(100 * ($value - $was) / $was, 1) : null,
            ];
        }

        $metrics['bounce_rate'] = $this->derivedRate($current, $previous, 'bounces', 'sessions');
        $metrics['engagement_rate'] = $this->derivedRate($current, $previous, 'engaged_sessions', 'sessions');
        $metrics['conversion_rate'] = $this->derivedRate($current, $previous, 'orders', 'sessions');
        $metrics['pages_per_session'] = $this->derivedRatio($current, $previous, 'pageviews', 'sessions');
        $metrics['average_order_value'] = $this->derivedRatio($current, $previous, 'revenue', 'orders');
        $metrics['session_duration'] = $this->derivedRatio($current, $previous, 'duration_seconds', 'sessions');

        return $metrics;
    }

    /**
     * The day-by-day series a trend chart draws, with no missing days.
     *
     * A chart that simply omits a day with no traffic draws a straight line through it, which reads
     * as steady rather than as silent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function trend(Window $window): array
    {
        $rows = $this->rollupQuery($window->fromDate(), $window->toDate())
            ->where('dimension', 'totals')
            ->where('dimension_key', 'all')
            ->get()
            ->keyBy('date');

        $today = Carbon::today()->toDateString();
        $live = $window->includesToday() ? $this->liveTotals($today) : null;

        $series = [];

        foreach ($window->dates() as $date) {
            $row = $rows->get($date);

            $point = [
                'date' => $date,
                'sessions' => (int) ($row->sessions ?? 0),
                'visitors' => (int) ($row->visitors ?? 0),
                'pageviews' => (int) ($row->pageviews ?? 0),
                'orders' => (int) ($row->orders ?? 0),
                'revenue' => (float) ($row->revenue ?? 0),
            ];

            if ($date === $today && $live !== null) {
                // Today's rollup may be an hour old; the live count is never lower, so taking the
                // larger of the two keeps the point monotonic instead of jumping backwards when
                // the rollup lands.
                foreach (['sessions', 'visitors', 'pageviews', 'orders', 'revenue'] as $metric) {
                    $point[$metric] = max($point[$metric], $live[$metric] ?? 0);
                }
            }

            $series[] = $point;
        }

        return $series;
    }

    /**
     * A ranked breakdown of one dimension.
     *
     * @return array<string, mixed>
     */
    public function breakdown(Window $window, string $dimension, int $limit = 25): array
    {
        if (!in_array($dimension, self::DIMENSIONS, true)) {
            return ['state' => 'unknown_dimension', 'rows' => []];
        }

        $rows = $this->rollupQuery($window->fromDate(), $window->toDate())
            ->where('dimension', $dimension)
            ->selectRaw('dimension_key')
            ->selectRaw('SUM(sessions) sessions')
            ->selectRaw('SUM(visitors) visitors')
            ->selectRaw('SUM(new_visitors) new_visitors')
            ->selectRaw('SUM(pageviews) pageviews')
            ->selectRaw('SUM(events) events')
            ->selectRaw('SUM(bounces) bounces')
            ->selectRaw('SUM(engaged_sessions) engaged_sessions')
            ->selectRaw('SUM(duration_seconds) duration_seconds')
            ->selectRaw('SUM(cart_adds) cart_adds')
            ->selectRaw('SUM(checkouts) checkouts')
            ->selectRaw('SUM(orders) orders')
            ->selectRaw('SUM(revenue) revenue')
            ->groupBy('dimension_key')
            ->orderByDesc(DB::raw('SUM(sessions) + SUM(events)'))
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return ['state' => $this->emptyReason($window), 'rows' => []];
        }

        $total = (float) $rows->sum('sessions') ?: (float) $rows->sum('events');

        return [
            'state' => 'ok',
            'rows' => $rows->map(function ($row) use ($total) {
                $sessions = (int) $row->sessions;
                $weight = $sessions > 0 ? $sessions : (int) $row->events;

                return [
                    'key' => $row->dimension_key,
                    'sessions' => $sessions,
                    'visitors' => (int) $row->visitors,
                    'new_visitors' => (int) $row->new_visitors,
                    'pageviews' => (int) $row->pageviews,
                    'events' => (int) $row->events,
                    'bounce_rate' => $sessions > 0 ? round(100 * $row->bounces / $sessions, 1) : null,
                    'engagement_rate' => $sessions > 0 ? round(100 * $row->engaged_sessions / $sessions, 1) : null,
                    'avg_duration' => $sessions > 0 ? (int) round($row->duration_seconds / $sessions) : null,
                    'cart_adds' => (int) $row->cart_adds,
                    'orders' => (int) $row->orders,
                    'revenue' => round((float) $row->revenue, 2),
                    'conversion_rate' => $sessions > 0 ? round(100 * $row->orders / $sessions, 2) : null,
                    'share' => $total > 0 ? round(100 * $weight / $total, 1) : null,
                ];
            })->all(),
        ];
    }

    /**
     * How much traffic was excluded, and why.
     *
     * Deliberately reported alongside the numbers rather than hidden behind a setting: on this
     * shop bots were 36% of recorded sessions, and a merchant comparing this screen against a
     * server log needs to know that before concluding the analytics is broken.
     *
     * @return array<string, mixed>
     */
    public function excludedTraffic(Window $window): array
    {
        $rows = $this->rollupQuery($window->fromDate(), $window->toDate())
            ->where('dimension', 'excluded_traffic')
            ->selectRaw('dimension_key, SUM(sessions) sessions, SUM(visitors) visitors, SUM(pageviews) pageviews')
            ->groupBy('dimension_key')
            ->get();

        $counted = (int) $this->rollupQuery($window->fromDate(), $window->toDate())
            ->where('dimension', 'totals')->where('dimension_key', 'all')->sum('sessions');

        $excluded = (int) $rows->sum('sessions');

        return [
            'rows' => $rows->map(fn ($row) => [
                'kind' => $row->dimension_key,
                'sessions' => (int) $row->sessions,
                'visitors' => (int) $row->visitors,
                'pageviews' => (int) $row->pageviews,
            ])->all(),
            'excluded_sessions' => $excluded,
            'counted_sessions' => $counted,
            'excluded_share' => ($excluded + $counted) > 0 ? round(100 * $excluded / ($excluded + $counted), 1) : null,
        ];
    }

    /**
     * The commerce funnel, measured from the event stream rather than from separate counters.
     *
     * Every step is a count of DISTINCT SESSIONS that reached it, not a count of events: a customer
     * who adds three items to a basket is one session that reached "added to cart", and counting
     * the three would make the funnel widen in the middle.
     *
     * @return array<string, mixed>
     */
    public function funnel(Window $window): array
    {
        $steps = [
            ['key' => 'visited', 'label' => 'visited_the_shop', 'event' => null],
            ['key' => 'viewed_product', 'label' => 'viewed_a_product', 'event' => AnalyticsEvent::PRODUCT_VIEWED],
            ['key' => 'added_to_cart', 'label' => 'added_to_cart', 'event' => AnalyticsEvent::CART_ADDED],
            ['key' => 'started_checkout', 'label' => 'started_checkout', 'event' => AnalyticsEvent::CHECKOUT_STARTED],
            ['key' => 'reached_payment', 'label' => 'reached_payment', 'event' => AnalyticsEvent::CHECKOUT_PAYMENT],
            ['key' => 'ordered', 'label' => 'placed_an_order', 'event' => AnalyticsEvent::ORDER_PLACED],
        ];

        $from = $window->from->copy()->startOfDay();
        $to = $window->to->copy()->endOfDay();

        $sessions = (int) $this->realSessions($from, $to)->distinct()->count('id');

        if ($sessions === 0) {
            return ['state' => $this->emptyReason($window), 'steps' => []];
        }

        $reached = $this->realEvents($from, $to)
            ->whereNotNull('session_id')
            ->selectRaw('name, COUNT(DISTINCT session_id) sessions')
            ->groupBy('name')
            ->pluck('sessions', 'name');

        $rows = [];
        $previous = null;

        foreach ($steps as $step) {
            $count = $step['event'] === null ? $sessions : (int) ($reached[$step['event']] ?? 0);

            $rows[] = [
                'key' => $step['key'],
                'label' => $step['label'],
                'sessions' => $count,
                'share_of_all' => round(100 * $count / $sessions, 1),
                // Step-to-step is where the money leaks, and it is a different number from share
                // of all: 40% of everyone reaching checkout is healthy; 40% of the people who
                // already had a full basket is a broken payment form.
                'step_conversion' => $previous !== null && $previous > 0 ? round(100 * $count / $previous, 1) : null,
                'dropped' => $previous !== null ? max(0, $previous - $count) : null,
            ];

            $previous = $count;
        }

        return ['state' => 'ok', 'sessions' => $sessions, 'steps' => $rows];
    }

    /**
     * Retention: of the visitors first seen in a week, how many came back in later weeks.
     *
     * Computed from analytics_visitors.first_seen_at against session dates, because a cohort is a
     * question about people over time and the daily rollups have already thrown the people away.
     *
     * @return array<string, mixed>
     */
    public function cohorts(int $weeks = 8): array
    {
        if (!$this->ready()) {
            return ['state' => 'not_installed', 'cohorts' => []];
        }

        $start = Carbon::today()->startOfWeek()->subWeeks($weeks - 1);

        $rows = $this->connection()->table('analytics_sessions as s')
            ->join('analytics_visitors as v', 'v.visitor_id', '=', 's.visitor_id')
            ->where('v.first_seen_at', '>=', $start)
            ->where('v.is_bot', false)
            ->where('v.is_internal', false)
            ->where('s.is_bot', false)
            ->selectRaw('YEARWEEK(v.first_seen_at, 3) cohort_week')
            ->selectRaw('YEARWEEK(s.started_at, 3) active_week')
            ->selectRaw('COUNT(DISTINCT s.visitor_id) visitors')
            ->groupBy('cohort_week', 'active_week')
            ->get();

        if ($rows->isEmpty()) {
            return ['state' => 'no_data', 'cohorts' => []];
        }

        $grid = [];
        $sizes = [];

        foreach ($rows as $row) {
            $cohort = (string) $row->cohort_week;
            $offset = $this->weekOffset($cohort, (string) $row->active_week);

            if ($offset < 0) {
                continue;
            }

            $grid[$cohort][$offset] = (int) $row->visitors;

            if ($offset === 0) {
                $sizes[$cohort] = (int) $row->visitors;
            }
        }

        krsort($grid);
        $cohorts = [];

        foreach ($grid as $cohort => $offsets) {
            $size = $sizes[$cohort] ?? 0;

            if ($size === 0) {
                continue;
            }

            ksort($offsets);
            $cohorts[] = [
                'cohort' => $this->weekLabel($cohort),
                'size' => $size,
                'retention' => array_map(
                    static fn (int $count) => ['visitors' => $count, 'pct' => round(100 * $count / $size, 1)],
                    $offsets,
                ),
            ];
        }

        return ['state' => 'ok', 'cohorts' => $cohorts];
    }

    /**
     * What a single visitor actually did, in order.
     *
     * The one report that cannot come from an aggregate: a journey is the sequence, and every
     * rollup in the system has thrown the sequence away by design.
     *
     * @return array<string, mixed>
     */
    public function journey(string $visitorId, int $limit = 200): array
    {
        if (!$this->ready()) {
            return ['state' => 'not_installed'];
        }

        $visitor = $this->connection()->table('analytics_visitors')->where('visitor_id', $visitorId)->first();

        if ($visitor === null) {
            return ['state' => 'not_found'];
        }

        $sessions = $this->connection()->table('analytics_sessions')
            ->where('visitor_id', $visitorId)
            ->orderByDesc('started_at')
            ->limit(25)
            ->get();

        $events = $this->connection()->table('analytics_events')
            ->where('visitor_id', $visitorId)
            ->orderBy('occurred_at')
            ->limit($limit)
            ->get(['name', 'category', 'session_id', 'entity_type', 'entity_id', 'value', 'path', 'occurred_at']);

        return [
            'state' => 'ok',
            'visitor' => $visitor,
            'sessions' => $sessions->all(),
            'events' => $events->groupBy('session_id')->map(fn ($group) => $group->all())->all(),
        ];
    }

    /**
     * The most recent activity, for the Live screen.
     *
     * Reads the event table directly: "live" and "rolled up" are contradictory.
     *
     * @return array<string, mixed>
     */
    public function live(int $minutes = 30): array
    {
        if (!$this->ready()) {
            return ['state' => 'not_installed'];
        }

        $since = Carbon::now()->subMinutes($minutes);

        $active = (int) $this->connection()->table('analytics_sessions')
            ->where('last_activity_at', '>=', $since)
            ->where('is_bot', false)
            ->where('is_internal', false)
            ->count();

        $events = $this->connection()->table('analytics_events')
            ->where('occurred_at', '>=', $since)
            ->where('is_bot', false)
            ->where('is_internal', false)
            ->orderByDesc('occurred_at')
            ->limit(60)
            ->get(['name', 'category', 'entity_type', 'entity_id', 'value', 'path', 'channel', 'occurred_at']);

        $perMinute = $this->connection()->table('analytics_events')
            ->where('occurred_at', '>=', $since)
            ->where('is_bot', false)
            ->where('is_internal', false)
            ->selectRaw("DATE_FORMAT(occurred_at, '%Y-%m-%d %H:%i:00') minute, COUNT(*) events")
            ->groupBy('minute')
            ->orderBy('minute')
            ->get();

        return [
            'state' => $active === 0 && $events->isEmpty() ? 'quiet' : 'ok',
            'window_minutes' => $minutes,
            'active_sessions' => $active,
            'events' => $events->all(),
            'per_minute' => $perMinute->all(),
        ];
    }

    /**
     * One vendor's own numbers, and nothing else's.
     *
     * The scoping is a WHERE on vendor_id applied at the query, not a filter applied to a result
     * set that was fetched wider — the difference between a vendor who cannot see another's data
     * and one who merely is not shown it.
     *
     * @return array<string, mixed>
     */
    public function forVendor(int $vendorId, Window $window): array
    {
        if (!$this->ready()) {
            return ['state' => 'not_installed'];
        }

        $from = $window->from->copy()->startOfDay();
        $to = $window->to->copy()->endOfDay();

        $events = $this->realEvents($from, $to)->where('vendor_id', $vendorId);

        $summary = (clone $events)
            ->selectRaw('COUNT(DISTINCT visitor_id) visitors')
            ->selectRaw('COUNT(DISTINCT session_id) sessions')
            ->selectRaw('SUM(CASE WHEN name = ? THEN 1 ELSE 0 END) product_views', [AnalyticsEvent::PRODUCT_VIEWED])
            ->selectRaw('SUM(CASE WHEN name = ? THEN 1 ELSE 0 END) cart_adds', [AnalyticsEvent::CART_ADDED])
            ->selectRaw('SUM(CASE WHEN name = ? THEN 1 ELSE 0 END) orders', [AnalyticsEvent::ORDER_PLACED])
            ->selectRaw('COALESCE(SUM(CASE WHEN name = ? THEN value ELSE 0 END), 0) revenue', [AnalyticsEvent::ORDER_PLACED])
            ->first();

        $products = (clone $events)
            ->where('entity_type', 'product')
            ->selectRaw('entity_id, COUNT(*) events, COUNT(DISTINCT visitor_id) visitors')
            ->selectRaw('SUM(CASE WHEN name = ? THEN 1 ELSE 0 END) views', [AnalyticsEvent::PRODUCT_VIEWED])
            ->selectRaw('SUM(CASE WHEN name = ? THEN 1 ELSE 0 END) cart_adds', [AnalyticsEvent::CART_ADDED])
            ->groupBy('entity_id')
            ->orderByDesc('events')
            ->limit(25)
            ->get();

        return [
            'state' => ($summary->sessions ?? 0) > 0 ? 'ok' : $this->emptyReason($window),
            'summary' => [
                'visitors' => (int) ($summary->visitors ?? 0),
                'sessions' => (int) ($summary->sessions ?? 0),
                'product_views' => (int) ($summary->product_views ?? 0),
                'cart_adds' => (int) ($summary->cart_adds ?? 0),
                'orders' => (int) ($summary->orders ?? 0),
                'revenue' => round((float) ($summary->revenue ?? 0), 2),
            ],
            'products' => $products->all(),
        ];
    }

    // -------------------------------------------------------------------------------------------

    /**
     * @return array<string, float|int>
     */
    private function totalsFor(string $from, string $to, bool $spliceToday): array
    {
        $row = $this->rollupQuery($from, $to)
            ->where('dimension', 'totals')
            ->where('dimension_key', 'all')
            ->selectRaw('SUM(sessions) sessions, SUM(visitors) visitors, SUM(new_visitors) new_visitors')
            ->selectRaw('SUM(pageviews) pageviews, SUM(events) events, SUM(bounces) bounces')
            ->selectRaw('SUM(engaged_sessions) engaged_sessions, SUM(duration_seconds) duration_seconds')
            ->selectRaw('SUM(cart_adds) cart_adds, SUM(checkouts) checkouts, SUM(orders) orders')
            ->selectRaw('COALESCE(SUM(revenue), 0) revenue')
            ->first();

        $totals = [
            'sessions' => (int) ($row->sessions ?? 0),
            'visitors' => (int) ($row->visitors ?? 0),
            'new_visitors' => (int) ($row->new_visitors ?? 0),
            'pageviews' => (int) ($row->pageviews ?? 0),
            'events' => (int) ($row->events ?? 0),
            'bounces' => (int) ($row->bounces ?? 0),
            'engaged_sessions' => (int) ($row->engaged_sessions ?? 0),
            'duration_seconds' => (int) ($row->duration_seconds ?? 0),
            'cart_adds' => (int) ($row->cart_adds ?? 0),
            'checkouts' => (int) ($row->checkouts ?? 0),
            'orders' => (int) ($row->orders ?? 0),
            'revenue' => round((float) ($row->revenue ?? 0), 2),
        ];

        if (!$spliceToday) {
            return $totals;
        }

        // Today only, and by replacement rather than addition: the rollup may already hold part of
        // today, and adding the live figure on top would count the morning twice.
        $today = Carbon::today()->toDateString();
        $rolledToday = $this->rollupQuery($today, $today)
            ->where('dimension', 'totals')->where('dimension_key', 'all')
            ->first();

        $live = $this->liveTotals($today);

        foreach ($totals as $metric => $value) {
            $rolled = (float) ($rolledToday->{$metric} ?? 0);
            $liveValue = (float) ($live[$metric] ?? 0);

            if ($liveValue > $rolled) {
                $totals[$metric] = $metric === 'revenue'
                    ? round($value - $rolled + $liveValue, 2)
                    : (int) ($value - $rolled + $liveValue);
            }
        }

        return $totals;
    }

    /**
     * Today, straight from the session table.
     *
     * @return array<string, float|int>
     */
    private function liveTotals(string $date): array
    {
        $from = Carbon::parse($date)->startOfDay();
        $to = Carbon::parse($date)->endOfDay();

        $row = $this->realSessions($from, $to)
            ->selectRaw('COUNT(*) sessions, COUNT(DISTINCT visitor_id) visitors, SUM(is_new_visitor) new_visitors')
            ->selectRaw('SUM(pageviews) pageviews, SUM(events) events, SUM(is_bounce) bounces')
            ->selectRaw('SUM(is_engaged) engaged_sessions, SUM(duration_seconds) duration_seconds')
            ->selectRaw('SUM(cart_adds) cart_adds, SUM(checkouts) checkouts, SUM(orders) orders')
            ->selectRaw('COALESCE(SUM(revenue), 0) revenue')
            ->first();

        return [
            'sessions' => (int) ($row->sessions ?? 0),
            'visitors' => (int) ($row->visitors ?? 0),
            'new_visitors' => (int) ($row->new_visitors ?? 0),
            'pageviews' => (int) ($row->pageviews ?? 0),
            'events' => (int) ($row->events ?? 0),
            'bounces' => (int) ($row->bounces ?? 0),
            'engaged_sessions' => (int) ($row->engaged_sessions ?? 0),
            'duration_seconds' => (int) ($row->duration_seconds ?? 0),
            'cart_adds' => (int) ($row->cart_adds ?? 0),
            'checkouts' => (int) ($row->checkouts ?? 0),
            'orders' => (int) ($row->orders ?? 0),
            'revenue' => round((float) ($row->revenue ?? 0), 2),
        ];
    }

    /**
     * @param  array<string, float|int>  $current
     * @param  array<string, float|int>  $previous
     * @return array<string, mixed>
     */
    private function derivedRate(array $current, array $previous, string $numerator, string $denominator): array
    {
        $now = $current[$denominator] > 0 ? round(100 * $current[$numerator] / $current[$denominator], 1) : null;
        $was = $previous[$denominator] > 0 ? round(100 * $previous[$numerator] / $previous[$denominator], 1) : null;

        return [
            'value' => $now,
            'previous' => $was,
            // Rates are compared in percentage POINTS, not as a percentage of a percentage. A
            // bounce rate moving from 40% to 44% is four points; calling it "+10%" is the kind of
            // arithmetic that gets a decision made on the wrong number.
            'change_points' => $now !== null && $was !== null ? round($now - $was, 1) : null,
        ];
    }

    /**
     * @param  array<string, float|int>  $current
     * @param  array<string, float|int>  $previous
     * @return array<string, mixed>
     */
    private function derivedRatio(array $current, array $previous, string $numerator, string $denominator): array
    {
        $now = $current[$denominator] > 0 ? round($current[$numerator] / $current[$denominator], 2) : null;
        $was = $previous[$denominator] > 0 ? round($previous[$numerator] / $previous[$denominator], 2) : null;

        return [
            'value' => $now,
            'previous' => $was,
            'change_pct' => $now !== null && $was !== null && $was > 0 ? round(100 * ($now - $was) / $was, 1) : null,
        ];
    }

    /**
     * Why a section is empty. Never simply "no data".
     */
    private function emptyReason(Window $window): string
    {
        $health = $this->collectionHealth();

        if (in_array($health['state'], ['not_installed', 'disabled', 'no_events', 'rollup_never_ran'], true)) {
            return $health['state'];
        }

        return 'no_traffic';
    }

    private function rollupQuery(string $from, string $to): \Illuminate\Database\Query\Builder
    {
        return $this->connection()->table('analytics_daily')
            ->whereBetween('date', [$from, $to]);
    }

    private function realSessions(Carbon $from, Carbon $to): \Illuminate\Database\Query\Builder
    {
        $query = $this->connection()->table('analytics_sessions')->whereBetween('started_at', [$from, $to]);

        if (config('analytics.exclude_bots', true)) {
            $query->where('is_bot', false);
        }

        if (config('analytics.exclude_internal', true)) {
            $query->where('is_internal', false);
        }

        return $query;
    }

    private function realEvents(Carbon $from, Carbon $to): \Illuminate\Database\Query\Builder
    {
        $query = $this->connection()->table('analytics_events')->whereBetween('occurred_at', [$from, $to]);

        if (config('analytics.exclude_bots', true)) {
            $query->where('is_bot', false);
        }

        if (config('analytics.exclude_internal', true)) {
            $query->where('is_internal', false);
        }

        return $query;
    }

    private function weekOffset(string $cohort, string $active): int
    {
        $cohortStart = $this->weekStart($cohort);
        $activeStart = $this->weekStart($active);

        return $cohortStart === null || $activeStart === null
            ? -1
            : (int) round($cohortStart->diffInDays($activeStart) / 7);
    }

    private function weekStart(string $yearWeek): ?Carbon
    {
        if (!preg_match('/^(\d{4})(\d{2})$/', $yearWeek, $matches)) {
            return null;
        }

        return Carbon::now()->setISODate((int) $matches[1], (int) $matches[2])->startOfWeek();
    }

    private function weekLabel(string $yearWeek): string
    {
        return $this->weekStart($yearWeek)?->toDateString() ?? $yearWeek;
    }

    public function ready(): bool
    {
        try {
            return Schema::connection(config('analytics.connection'))->hasTable('analytics_daily')
                && Schema::connection(config('analytics.connection'))->hasTable('analytics_events');
        } catch (\Throwable) {
            return false;
        }
    }

    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('analytics.connection'));
    }
}
