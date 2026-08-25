<?php

namespace App\Http\Controllers\Telemetry;

use App\Services\Monitoring\Export\PrometheusExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * The Prometheus scrape target.
 *
 * Deliberately not under /admin and not on the web middleware group: a scrape arrives every fifteen
 * seconds from a machine with no session and no cookie jar, and putting it behind StartSession would
 * write a session file per scrape until the disk filled.
 *
 * It is off unless a token is configured, and a request without that token is answered 404 rather
 * than 403 — a 403 confirms the endpoint exists, which is the one thing an unauthenticated caller
 * should not learn about a metrics feed.
 */
class MetricsExportController extends Controller
{
    public function __construct(private readonly PrometheusExporter $exporter)
    {
    }

    public function prometheus(Request $request): Response
    {
        if (!$this->exporter->enabled() || !$this->exporter->accepts($this->presentedToken($request))) {
            // An empty 404 rather than abort(): the error VIEW is a themed HTML page that reads
            // shop settings, which is a database round trip and a page of markup to answer a
            // scraper that wanted four hundred bytes of text.
            return response('', 404);
        }

        return response($this->exporter->render(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Bearer first, query string second.
     *
     * A token in a URL ends up in access logs and referrers, so it is the fallback for scrapers
     * that cannot set a header, not the documented way in.
     */
    private function presentedToken(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $query = $request->query('token');

        return is_string($query) && $query !== '' ? $query : null;
    }
}
