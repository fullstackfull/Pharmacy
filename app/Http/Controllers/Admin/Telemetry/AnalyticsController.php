<?php

namespace App\Http\Controllers\Admin\Telemetry;

use App\Http\Controllers\BaseController;
use App\Services\Analytics\Reporting\AnalyticsNavigation;
use App\Services\Analytics\AnalyticsPermissionService;
use App\Services\Analytics\CampaignService;
use App\Services\Analytics\Reporting\AnalyticsReporting;
use App\Services\Analytics\Reporting\Window;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Analytics area.
 *
 * Thin on purpose: it resolves the section and the date window, asks the reporting service for
 * that section's data, and renders. Every judgement about what a number means lives in the
 * reporting service, so the screens and any future export or API cannot disagree about what
 * "sessions" counts.
 */
class AnalyticsController extends BaseController
{
    public function __construct(
        private readonly AnalyticsReporting $reporting,
        private readonly CampaignService $campaigns,
        private readonly AnalyticsPermissionService $permissions,
    ) {
    }

    /**
     * Signature is the project's ControllerInterface one — `?Request`, `?string $type` — because
     * BaseController declares it and PHP checks compatibility at class load. A nicer parameter
     * name here is a fatal error on every admin page.
     */
    public function index(?Request $request = null, ?string $type = null): View|JsonResponse|RedirectResponse
    {
        $request ??= request();
        $section = $type ?: (string) $request->query('section', 'overview');

        if (!AnalyticsNavigation::has($section)) {
            $section = 'overview';
        }

        // Checked before any data is fetched, so a section this admin may not open never runs its
        // queries — the difference between a page they cannot see and data they cannot reach.
        if (!$this->permissions->can($this->permissions->capabilityForSection($section))) {
            return redirect()
                ->route('admin.analytics.section', ['section' => 'overview'])
                ->with('error', translate('access_Denied') . '!');
        }

        $window = $this->window($request);

        return view('admin-views.analytics.index', [
            'section' => $section,
            'meta' => AnalyticsNavigation::meta($section),
            'navigation' => AnalyticsNavigation::grouped($this->permissions),
            'window' => $window,
            'ranges' => Window::RANGES,
            'health' => $this->reporting->collectionHealth(),
            'data' => $this->dataFor($section, $window, $request),
        ]);
    }

    /**
     * A CSV of whatever is on screen.
     *
     * Streamed rather than built in memory: a year of daily rows for a busy dimension is not
     * something to assemble in a string before sending the first byte.
     */
    public function export(Request $request, string $dimension): StreamedResponse|RedirectResponse
    {
        if (!in_array($dimension, AnalyticsReporting::DIMENSIONS, true)
            || !$this->permissions->can(AnalyticsPermissionService::EXPORT)) {
            return back();
        }

        $window = $this->window($request);
        $breakdown = $this->reporting->breakdown($window, $dimension, 5000);

        $filename = "analytics-{$dimension}-{$window->fromDate()}-to-{$window->toDate()}.csv";

        return response()->streamDownload(function () use ($breakdown, $dimension) {
            $handle = fopen('php://output', 'wb');

            // A BOM, because a merchant opens this in Excel and Arabic product names without one
            // arrive as mojibake.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                $dimension, 'sessions', 'visitors', 'new_visitors', 'pageviews', 'events',
                'bounce_rate_pct', 'engagement_rate_pct', 'avg_duration_seconds',
                'cart_adds', 'orders', 'revenue', 'conversion_rate_pct', 'share_pct',
            ]);

            foreach ($breakdown['rows'] as $row) {
                fputcsv($handle, [
                    $row['key'], $row['sessions'], $row['visitors'], $row['new_visitors'],
                    $row['pageviews'], $row['events'], $row['bounce_rate'], $row['engagement_rate'],
                    $row['avg_duration'], $row['cart_adds'], $row['orders'], $row['revenue'],
                    $row['conversion_rate'], $row['share'],
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Create a campaign link.
     *
     * The destination is validated here and never again taken from a request — the redirect
     * endpoint only ever follows a row that passed this check.
     */
    public function storeCampaign(Request $request): RedirectResponse
    {
        if (!$this->permissions->can(AnalyticsPermissionService::CAMPAIGNS)) {
            return back()->with('error', translate('access_Denied') . '!');
        }

        $result = $this->campaigns->create($request->all(), auth('admin')->id());

        return redirect()
            ->route('admin.analytics.section', ['section' => 'campaigns'])
            ->with($result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? translate('campaign_link_created')
                    : translate((string) $result['error']));
    }

    public function toggleCampaign(Request $request, int $id): RedirectResponse
    {
        if (!$this->permissions->can(AnalyticsPermissionService::CAMPAIGNS)) {
            return back()->with('error', translate('access_Denied') . '!');
        }

        $this->campaigns->setActive($id, $request->boolean('active'));

        return redirect()->route('admin.analytics.section', ['section' => 'campaigns']);
    }

    /** The QR for one short link, as an SVG the browser can render or a merchant can save. */
    public function campaignQr(int $id): \Illuminate\Http\Response|RedirectResponse
    {
        $campaign = $this->campaigns->find($id);

        if ($campaign === null) {
            return back();
        }

        $svg = $this->campaigns->qrSvg($campaign->code, 512);

        if ($svg === null) {
            return back();
        }

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'inline; filename="qr-' . $campaign->code . '.svg"',
        ]);
    }

    /** The Live screen polls this rather than reloading a page every few seconds. */
    public function live(Request $request): JsonResponse
    {
        return response()->json($this->reporting->live((int) $request->query('minutes', 30)));
    }

    // -------------------------------------------------------------------------------------------

    private function window(Request $request): Window
    {
        $from = $request->query('from');
        $to = $request->query('to');

        if (is_string($from) && is_string($to) && $from !== '' && $to !== '') {
            return Window::between($from, $to);
        }

        return Window::make($request->query('range'));
    }

    /**
     * @return array<string, mixed>
     */
    private function dataFor(string $section, Window $window, Request $request): array
    {
        return match ($section) {
            'overview' => [
                'totals' => $this->reporting->totals($window),
                'trend' => $this->reporting->trend($window),
                'sources' => $this->reporting->breakdown($window, 'source', 8),
                'devices' => $this->reporting->breakdown($window, 'device', 6),
                'pages' => $this->reporting->breakdown($window, 'path', 8),
                'funnel' => $this->reporting->funnel($window),
            ],
            'live' => ['live' => $this->reporting->live()],
            'campaigns' => [
                'campaigns' => $this->campaigns->all(),
                'allowed_hosts' => app(\App\Services\Analytics\Support\CampaignDestination::class)->allowedHosts(),
                'ready' => $this->campaigns->ready(),
                // Whether a short link opens the app on a phone that has it. Without this the
                // section could not tell a merchant why their QR code opens a browser.
                'app_links' => $this->appLinkState(),
            ],
            'acquisition' => [
                'sources' => $this->reporting->breakdown($window, 'source', 30),
                'mediums' => $this->reporting->breakdown($window, 'medium', 20),
                'campaigns' => $this->reporting->breakdown($window, 'campaign', 20),
                'basis' => $this->reporting->breakdown($window, 'attribution_basis', 10),
                'landing' => $this->reporting->breakdown($window, 'landing_path', 20),
            ],
            'audience' => [
                'devices' => $this->reporting->breakdown($window, 'device', 10),
                'os' => $this->reporting->breakdown($window, 'os', 10),
                'browsers' => $this->reporting->breakdown($window, 'browser', 10),
                'countries' => $this->reporting->breakdown($window, 'country', 20),
                'languages' => $this->reporting->breakdown($window, 'language', 10),
                'app_versions' => $this->reporting->breakdown($window, 'app_version', 10),
                'new_vs_returning' => $this->reporting->breakdown($window, 'new_vs_returning', 4),
            ],
            'retention' => ['cohorts' => $this->reporting->cohorts()],
            'behaviour' => [
                'pages' => $this->reporting->breakdown($window, 'path', 50),
                'landing' => $this->reporting->breakdown($window, 'landing_path', 25),
            ],
            'catalogue' => [
                'products' => $this->withNames($this->reporting->breakdown($window, 'product', 40), 'products'),
                'categories' => $this->withNames($this->reporting->breakdown($window, 'category', 30), 'categories'),
                'brands' => $this->withNames($this->reporting->breakdown($window, 'brand', 20), 'brands'),
            ],
            'search' => [
                'terms' => $this->reporting->breakdown($window, 'search_term', 50),
                'no_results' => $this->reporting->breakdown($window, 'search_no_results', 50),
            ],
            'vendors' => ['shops' => $this->withNames($this->reporting->breakdown($window, 'vendor', 40), 'sellers')],
            'funnel' => [
                'funnel' => $this->reporting->funnel($window),
                'gateways' => $this->reporting->breakdown($window, 'gateway', 10),
            ],
            'revenue' => [
                'totals' => $this->reporting->totals($window),
                'trend' => $this->reporting->trend($window),
                'sources' => $this->reporting->breakdown($window, 'source', 20),
                'campaigns' => $this->reporting->breakdown($window, 'campaign', 20),
            ],
            'timing' => [
                'hours' => $this->reporting->breakdown($window, 'hour', 24),
                'weekdays' => $this->reporting->breakdown($window, 'weekday', 7),
            ],
            'events' => ['events' => $this->reporting->breakdown($window, 'event', 60)],
            'journeys' => ['journey' => $this->journey($request)],
            'quality' => [
                'health' => $this->reporting->collectionHealth(),
                'excluded' => $this->reporting->excludedTraffic($window),
                'events' => $this->reporting->breakdown($window, 'event', 40),
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function journey(Request $request): ?array
    {
        $visitorId = $request->query('visitor');

        return is_string($visitorId) && $visitorId !== ''
            ? $this->reporting->journey($visitorId)
            : null;
    }

    /**
     * Resolve entity ids to names.
     *
     * The rollups store ids, deliberately: a product renamed last week must not fragment into two
     * rows in a report about last month. Names are looked up once here, at read time, so the
     * report always shows what the record is called NOW.
     *
     * @param  array<string, mixed>  $breakdown
     * @return array<string, mixed>
     */
    private function withNames(array $breakdown, string $table): array
    {
        if (($breakdown['rows'] ?? []) === []) {
            return $breakdown;
        }

        $ids = array_filter(array_map(static fn (array $row) => (int) $row['key'], $breakdown['rows']));

        if ($ids === []) {
            return $breakdown;
        }

        try {
            $column = $table === 'sellers' ? 'f_name' : 'name';
            $names = \Illuminate\Support\Facades\DB::table($table)
                ->whereIn('id', $ids)
                ->pluck($column, 'id');
        } catch (\Throwable) {
            return $breakdown;
        }

        foreach ($breakdown['rows'] as $index => $row) {
            // An id with no row behind it is a record that has been deleted since. Saying so is
            // more useful than showing a bare number, and hiding it would silently change the
            // totals the rest of the screen is computed from.
            $breakdown['rows'][$index]['name'] = $names[(int) $row['key']] ?? null;
            $breakdown['rows'][$index]['deleted'] = !isset($names[(int) $row['key']]);
        }

        return $breakdown;
    }

    /**
     * Whether campaign short links currently open the mobile app.
     *
     * Three things have to line up: the app has to be set up at all, /go/* has to be on the
     * published path list, and the file on disk has to actually carry that list. Any one of them
     * missing means a QR code on a poster opens a browser, and the campaigns section is where a
     * merchant would look to find out why.
     *
     * @return array<string, mixed>
     */
    private function appLinkState(): array
    {
        $links = app(\App\Services\DeepLink\AppLinkService::class);
        $writer = app(\App\Services\DeepLink\AssociationFileWriter::class);

        $path = '/' . trim((string) config('analytics.campaigns.path', 'go'), '/') . '/x';
        $published = $writer->published();

        return [
            'configured' => $links->isConfiguredForAnyPlatform(),
            'campaign_path_is_published' => $links->opensTheApp($path),
            'files_are_current' => !$published['exists']
                || $published['paths'] === $links->paths(\App\Services\DeepLink\AppLinkService::PLATFORM_IOS),
            'setup_url' => route('admin.system-setup.app-deep-link'),
        ];
    }

}
