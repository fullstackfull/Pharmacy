<?php

namespace App\Http\Controllers\Admin\Telemetry;

use App\Http\Controllers\BaseController;
use App\Services\DeveloperPortal\ApiManifest;
use App\Services\DeveloperPortal\ApiSnapshotService;
use App\Services\DeveloperPortal\Generators\OpenApiGenerator;
use App\Services\DeveloperPortal\Generators\PostmanGenerator;
use App\Services\DeveloperPortal\PortalNavigation;
use App\Services\Telemetry\DeveloperPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Developer Portal.
 *
 * Thin by design: every screen's data comes from a service, and the controller's whole job is to
 * decide which section is open and hand it what it asked for. The portal is a reader — nothing
 * here writes to the API it documents, and the one thing it does write (an API snapshot) is an
 * explicit act with its own button.
 */
class DeveloperPortalController extends BaseController
{
    public function __construct(
        private readonly DeveloperPortalService $portal,
        private readonly ApiManifest $manifest,
        private readonly ApiSnapshotService $snapshots,
    ) {
    }

    public function index(?Request $request = null, ?string $type = null): View|JsonResponse|RedirectResponse
    {
        $request ??= request();
        $section = $type ?: (string) $request->query('section', 'overview');

        if (!PortalNavigation::has($section)) {
            $section = 'overview';
        }

        $capabilities = $this->portal->capabilities();

        return view('admin-views.telemetry.developer', [
            'section' => $section,
            'meta' => PortalNavigation::meta($section),
            'navigation' => PortalNavigation::grouped($capabilities),
            'capabilities' => $capabilities,
            'manifest' => $this->manifest->get(),
            'data' => $this->dataFor($section, $request),
        ]);
    }

    /** One endpoint's page: reference, examples, live health and history in one place. */
    public function endpoint(Request $request, string $id): View|RedirectResponse
    {
        $endpoint = $this->portal->endpoint($id);

        if ($endpoint === null) {
            return redirect()->route('admin.developer.section', ['section' => 'explorer'])
                ->with('error', translate('that_endpoint_is_no_longer_in_the_route_table'));
        }

        return view('admin-views.telemetry.developer-endpoint', [
            'endpoint' => $endpoint,
            'manifest' => $this->manifest->get(),
            'navigation' => PortalNavigation::grouped($this->portal->capabilities()),
        ]);
    }

    /**
     * Find an endpoint by the path another section knows it by.
     *
     * Monitoring keys everything by route pattern; the portal keys everything by a hash of the
     * methods and the URI, which nothing outside the manifest can compute. Rather than teach
     * Monitoring how portal ids are made — a coupling that would break the first time the scheme
     * changed — it links here and the portal does the lookup. A path it cannot place lands on the
     * explorer with the search already filled in, which is the useful answer rather than a 404.
     */
    public function lookup(Request $request): RedirectResponse
    {
        $path = (string) $request->query('path', '');
        $method = $request->query('method');
        $endpoint = $path === '' ? null : $this->manifest->findByPath($path, is_string($method) ? $method : null);

        if ($endpoint === null) {
            return redirect()->route('admin.developer.section', [
                'section' => 'explorer',
                'search' => mb_substr($path, 0, 191),
            ]);
        }

        return redirect()->route('admin.developer.endpoint', ['id' => $endpoint['id']]);
    }

    /** The generated OpenAPI document. */
    public function openapi(Request $request, OpenApiGenerator $generator): StreamedResponse
    {
        $filters = $this->filters($request);
        $format = $request->query('format') === 'yaml' ? 'yaml' : 'json';

        $body = $format === 'yaml' ? $generator->toYaml($filters) : $generator->toJson($filters);
        $name = 'openapi-' . ($filters['audience'] ?? $filters['version'] ?? 'all') . '.' . $format;

        return response()->streamDownload(
            fn () => print($body),
            $name,
            ['Content-Type' => $format === 'yaml' ? 'application/yaml' : 'application/json'],
        );
    }

    /** The generated Postman collection. */
    public function postman(Request $request, PostmanGenerator $generator): StreamedResponse
    {
        $filters = $this->filters($request);
        $body = $generator->toJson($filters);

        return response()->streamDownload(
            fn () => print($body),
            'postman-' . ($filters['audience'] ?? 'all') . '.json',
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Freeze the current API surface and record what changed.
     *
     * The one write in the portal, and deliberately manual as well as scheduled: somebody about
     * to ship a risky change should be able to mark the line themselves.
     */
    public function snapshot(Request $request): RedirectResponse
    {
        $label = trim((string) $request->input('label')) ?: 'manual-' . now()->format('Y-m-d H:i');
        $result = $this->snapshots->captureAndRecord($label, auth('admin')->id());

        if ($result['unavailable'] ?? false) {
            return redirect()->route('admin.developer.section', ['section' => 'changelog'])
                ->with('error', translate('the_snapshot_tables_are_not_installed_so_nothing_was_captured')
                    . ' — php artisan migrate');
        }

        $message = $result['first']
            ? translate('first_snapshot_captured_there_is_nothing_to_compare_it_against_yet')
            : translate('snapshot_captured') . ': ' . $result['changes'] . ' ' . translate('change_s_recorded')
                . ($result['breaking'] > 0 ? ' — ' . $result['breaking'] . ' ' . translate('breaking') : '');

        return redirect()->route('admin.developer.section', ['section' => 'changelog'])->with('success', $message);
    }

    /** Rebuild the manifest by hand, for the moment after a deployment. */
    public function refresh(): RedirectResponse
    {
        $this->manifest->forget();
        $this->manifest->get();

        return back()->with('success', translate('the_api_manifest_was_rebuilt_from_the_live_route_table'));
    }

    // -------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function dataFor(string $section, Request $request): array
    {
        return match ($section) {
            'overview' => $this->portal->overview(),
            'quick_start' => ['base_url' => $this->manifest->get()['base_url']],
            'explorer' => $this->portal->explorer(
                $this->filters($request),
                perPage: 50,
                page: max(1, (int) $request->query('page', 1)),
            ),
            'customer_app' => $this->portal->explorer(['audience' => 'customer_app'] + $this->filters($request), 200),
            'vendor_app' => $this->portal->explorer(['audience' => 'vendor_app'] + $this->filters($request), 300),
            'delivery_app' => $this->portal->explorer(['audience' => 'delivery_app'] + $this->filters($request), 100),
            'partner' => $this->portal->explorer(['audience' => 'partner'] + $this->filters($request), 100),
            'authentication', 'errors', 'rate_limits', 'pagination', 'uploads' => $this->portal->conventions($section),
            'versions' => $this->portal->versions(),
            'changelog' => $this->portal->changelog($request->query('severity')),
            'deprecations' => ['endpoints' => $this->portal->deprecations()],
            'quality' => $this->portal->quality(),
            'health' => ['api' => $this->portal->apiHealth((string) $request->query('range', '24h'))],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return array_filter([
            'search' => $request->query('search'),
            'audience' => $request->query('audience'),
            'version' => $request->query('version'),
            'group' => $request->query('group'),
            'method' => $request->query('method'),
            'visibility' => $request->query('visibility'),
            'auth' => $request->query('auth'),
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
