<?php

namespace App\Services\DeveloperPortal\Support;

use Illuminate\Routing\Route;

/**
 * What a caller must present, and how often they may call.
 *
 * Read from the middleware the route actually runs, not from a convention. That distinction has
 * teeth in this application: the customer API authenticates with Passport tokens through
 * Authenticate:api, while the vendor API does NOT — SellerApiAuthMiddleware compares the bearer
 * value against a plain auth_token column on the seller row. A portal that assumed one mechanism
 * because the other was configured would send every vendor-app developer down the wrong path.
 *
 * The rate limit is read the same way: `throttle:20,1` on an auth route is a real number a client
 * needs to design its retry loop around, and it is on the route, not in a document.
 */
class AuthResolver
{
    /** Middleware that authenticates, and the scheme each one represents. */
    private const SCHEMES = [
        'App\Http\Middleware\Authenticate:api' => [
            'scheme' => 'bearer',
            'mechanism' => 'passport',
            'actor' => 'customer',
            'header' => 'Authorization: Bearer <access_token>',
            'note' => 'A Laravel Passport access token, issued by the customer login endpoints.',
        ],
        'App\Http\Middleware\Authenticate:sanctum' => [
            'scheme' => 'bearer',
            'mechanism' => 'sanctum',
            'actor' => 'customer',
            'header' => 'Authorization: Bearer <token>',
            'note' => 'A Laravel Sanctum token.',
        ],
        'App\Http\Middleware\SellerApiAuthMiddleware' => [
            'scheme' => 'bearer',
            'mechanism' => 'seller_token',
            'actor' => 'vendor',
            'header' => 'Authorization: Bearer <seller_auth_token>',
            'note' => 'The vendor auth_token returned by the seller login endpoint. This is NOT a Passport token and is not interchangeable with the customer one.',
        ],
        'App\Http\Middleware\DeliveryManAuth' => [
            'scheme' => 'bearer',
            'mechanism' => 'delivery_token',
            'actor' => 'delivery_man',
            'header' => 'Authorization: Bearer <delivery_man_token>',
            'note' => 'The delivery man auth token returned by the delivery app login endpoint.',
        ],
        'App\Http\Middleware\DeliverySyriaWebhookAuthMiddleware' => [
            'scheme' => 'shared_secret',
            'mechanism' => 'webhook_secret',
            'actor' => 'partner',
            'header' => 'A shared secret configured with the courier',
            'note' => 'Inbound courier webhook. The secret is configured in the Delivery Syria settings, never in the request.',
        ],
        'App\Http\Middleware\AdminMiddleware' => [
            'scheme' => 'session',
            'mechanism' => 'admin_session',
            'actor' => 'admin',
            'header' => 'Admin panel session cookie',
            'note' => 'Panel-only. Not reachable by an external integration.',
        ],
        'App\Http\Middleware\SellerMiddleware' => [
            'scheme' => 'session',
            'mechanism' => 'vendor_session',
            'actor' => 'vendor',
            'header' => 'Vendor panel session cookie',
            'note' => 'Panel-only. Not reachable by an external integration.',
        ],
        'App\Http\Middleware\CustomerMiddleware' => [
            'scheme' => 'session',
            'mechanism' => 'customer_session',
            'actor' => 'customer',
            'header' => 'Storefront session cookie',
            'note' => 'Storefront-only.',
        ],
    ];

    /** Middleware that makes authentication optional rather than required. */
    private const OPTIONAL = [
        'App\Http\Middleware\APIGuestMiddleware',
        'App\Http\Middleware\GuestMiddleware',
        'guestCheck',
        'apiGuestCheck',
    ];

    /**
     * @return array<string, mixed>
     */
    public function resolve(Route $route): array
    {
        $middleware = $this->middleware($route);
        $matched = null;
        $optional = false;

        foreach ($middleware as $item) {
            if (isset(self::SCHEMES[$item])) {
                $matched ??= self::SCHEMES[$item];

                continue;
            }

            if (in_array($item, self::OPTIONAL, true)) {
                $optional = true;
            }
        }

        if ($matched === null) {
            return [
                'required' => false,
                'scheme' => 'none',
                'mechanism' => 'public',
                'actor' => 'anyone',
                'header' => null,
                // "Optional" is a real and different answer from "public": these endpoints behave
                // differently for a signed-in caller (a cart tied to an account rather than a guest
                // id), and a client that never sends a token silently gets the guest behaviour.
                'note' => $optional
                    ? 'Public, but behaves differently when a customer token is sent: without one the caller is treated as a guest.'
                    : 'No authentication required.',
                'optional_auth' => $optional,
            ];
        }

        return $matched + ['required' => true, 'optional_auth' => $optional];
    }

    /**
     * The permissions or scopes this route demands, taken from the module middleware.
     *
     * @return array<int, string>
     */
    public function permissions(Route $route): array
    {
        $permissions = [];

        foreach ($this->middleware($route) as $item) {
            if (!is_string($item)) {
                continue;
            }

            if (str_starts_with($item, 'module:')) {
                foreach (explode(',', substr($item, 7)) as $module) {
                    $permissions[] = trim($module);
                }
            }

            if (str_starts_with($item, 'can:')) {
                $permissions[] = trim(substr($item, 4));
            }
        }

        return array_values(array_filter(array_unique($permissions)));
    }

    /**
     * The tightest rate limit on this route.
     *
     * Several throttles can apply at once — the api group's 3000/minute plus a 20/minute on the
     * login route — and the one a client will actually hit is the smallest. Reporting the group's
     * limit would tell a developer they have 3000 attempts at a password.
     *
     * @return array<string, mixed>|null
     */
    public function rateLimit(Route $route): ?array
    {
        $tightest = null;

        foreach ($this->middleware($route) as $item) {
            if (!is_string($item) || !preg_match('/ThrottleRequests:(.+)$|^throttle:(.+)$/', $item, $matches)) {
                continue;
            }

            $arguments = explode(',', $matches[1] !== '' ? $matches[1] : ($matches[2] ?? ''));

            if (!is_numeric($arguments[0] ?? null)) {
                // A named limiter (throttle:global) — report the name; its numbers live in the
                // RateLimiter definition rather than on the route.
                $tightest ??= ['limiter' => trim((string) ($arguments[0] ?? '')), 'requests' => null, 'minutes' => null];

                continue;
            }

            $requests = (int) $arguments[0];
            $minutes = (int) ($arguments[1] ?? 1);

            if ($tightest === null || ($tightest['requests'] ?? PHP_INT_MAX) > $requests) {
                $tightest = ['limiter' => null, 'requests' => $requests, 'minutes' => max(1, $minutes)];
            }
        }

        return $tightest;
    }

    /**
     * The middleware on a route, with aliases resolved to the classes they stand for.
     *
     * gatherMiddleware() returns what the route file wrote — `auth:api`, `module:reports` — which
     * is the alias, not the class. Matching on aliases alone would break the moment somebody
     * renames one in bootstrap/app.php, and matching on classes alone would match nothing at all.
     * So both spellings are returned: `auth:api` AND `App\Http\Middleware\Authenticate:api`.
     *
     * @return array<int, string>
     */
    public function middleware(Route $route): array
    {
        try {
            $aliases = app('router')->getMiddleware();
            $resolved = [];

            foreach ($route->gatherMiddleware() as $item) {
                if (!is_string($item)) {
                    continue;
                }

                $resolved[] = $item;

                [$name, $parameters] = array_pad(explode(':', $item, 2), 2, null);

                if (isset($aliases[$name]) && is_string($aliases[$name])) {
                    $resolved[] = $aliases[$name] . ($parameters !== null ? ':' . $parameters : '');
                }
            }

            return array_values(array_unique($resolved));
        } catch (\Throwable) {
            return [];
        }
    }

    /** The middleware as written in the route file, for the internal developer view. */
    public function declaredMiddleware(Route $route): array
    {
        try {
            return array_values(array_filter($route->gatherMiddleware(), static fn ($item) => is_string($item)));
        } catch (\Throwable) {
            return [];
        }
    }

    /** Every authentication scheme this API offers, for the Authentication section. */
    public function schemes(): array
    {
        return self::SCHEMES;
    }
}
