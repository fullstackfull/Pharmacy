<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Analytics\Analytics;
use App\Services\Analytics\AnalyticsEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where the browser reports what a page load cannot.
 *
 * Two things are invisible to server-side collection, and both matter here. The storefront's
 * product filter navigates with history.pushState and a jQuery request, so filtering, sorting and
 * changing a price range are real page changes that produce no HTML response and are therefore
 * never counted. And anything that happens without a navigation at all — a wishlist toggle, a
 * quick view — leaves no trace on the server beyond an endpoint hit that could be anything.
 *
 * The security shape is the interesting part, because this is a public unauthenticated POST:
 *
 *  - Only allow-listed event names are accepted, and NONE of them involve money. A page that could
 *    post an order with a value of its choosing would make revenue analytics a suggestion box.
 *  - No value the client sends is trusted for anything except a page identity. Prices, totals and
 *    order ids come from the server or not at all.
 *  - Same-origin only, rate limited per visitor, and a hard cap on events per request.
 *  - It always answers 204 regardless. Telling a prober which names are valid is free
 *    reconnaissance, and a beacon that returns an error just makes the browser retry.
 */
class AnalyticsCollectController extends Controller
{
    public function __invoke(Request $request, Analytics $analytics): JsonResponse
    {
        // 204 for everything: this endpoint has nothing to say to its caller, and the browser is
        // using sendBeacon, which cannot read a response anyway.
        $silence = response()->json(null, 204);

        if (!config('analytics.enabled', true) || !config('analytics.beacon.enabled', true)) {
            return $silence;
        }

        if (!$this->sameOrigin($request)) {
            return $silence;
        }

        $events = $request->input('events');

        if (!is_array($events)) {
            return $silence;
        }

        $limit = (int) config('analytics.beacon.max_events_per_request', 20);

        foreach (array_slice($events, 0, $limit) as $event) {
            if (!is_array($event) || !isset($event['name']) || !is_string($event['name'])) {
                continue;
            }

            if (!AnalyticsEvent::isClientAllowed($event['name'])) {
                continue;
            }

            $analytics->fromClient($event['name'], $this->clean($event), $request);
        }

        $analytics->flush($request);

        return $silence;
    }

    /**
     * Only accept beacons that came from this shop's own pages.
     *
     * sendBeacon cannot set headers, so a CSRF token is not available — but the browser does set
     * Origin on a cross-origin POST, and a request whose Origin is not ours has no business
     * writing into this shop's analytics.
     */
    private function sameOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin') ?: $request->headers->get('Referer');

        if (!is_string($origin) || $origin === '') {
            // No Origin at all is a same-origin navigation-time POST in some browsers, and also
            // what a naive script sends. Accepted, because the payload can do nothing dangerous.
            return true;
        }

        $host = parse_url($origin, PHP_URL_HOST);

        return is_string($host) && strcasecmp($host, $request->getHost()) === 0;
    }

    /**
     * Reduce a client payload to the few fields it is allowed to influence.
     *
     * Everything else is dropped rather than sanitised: a beacon that can set arbitrary properties
     * is a beacon that can fill the events table with whatever a bored visitor pastes into a
     * console.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function clean(array $event): array
    {
        $properties = [];

        foreach (['position', 'variant', 'list', 'depth', 'section'] as $key) {
            if (isset($event['properties'][$key]) && is_scalar($event['properties'][$key])) {
                $properties[$key] = mb_substr((string) $event['properties'][$key], 0, 64);
            }
        }

        return array_filter([
            'entity_type' => isset($event['entity_type']) && is_string($event['entity_type'])
                ? mb_substr($event['entity_type'], 0, 24)
                : null,
            // Ids are coerced to digits: an entity id is a database key, and anything else in that
            // column is somebody probing.
            'entity_id' => isset($event['entity_id']) && preg_match('/^\d{1,18}$/', (string) $event['entity_id'])
                ? (string) $event['entity_id']
                : null,
            'path' => isset($event['path']) && is_string($event['path']) ? mb_substr($event['path'], 0, 300) : null,
            'dedupe_key' => isset($event['dedupe_key']) && is_string($event['dedupe_key'])
                ? mb_substr($event['dedupe_key'], 0, 64)
                : null,
            'properties' => $properties,
        ], static fn ($value) => $value !== null && $value !== []);
    }
}
