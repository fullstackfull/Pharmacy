<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Every registered route must point at a controller method that exists.
 *
 * Laravel resolves `'Controller@method'` at request time, not at boot, so a route whose target was
 * renamed or deleted stays silently registered and answers with a 500 (BadMethodCallException)
 * instead of a 404. The audit that produced this test found **19 such routes** across the web,
 * admin, vendor, REST v1/v2 and module route files — leftovers from upstream refactors, including:
 *
 *   GET  /checkout-review                  -> WebController@checkout_review        (never written)
 *   GET  /top-rated, /best-sell            -> WebController@top_rated, @best_sell  (renamed away)
 *   GET  /api/v1/customer/address/get/{id} -> CustomerController@get_address       (never written)
 *   Route::resource('ai', AIController)    -> store/update/destroy                 (scaffold stub)
 *
 * None of them were linked from a working page, which is exactly why they survived: nothing in the
 * UI exercised them, so nothing reported them. They were reachable by URL all the same, and with
 * APP_DEBUG on a 500 hands the visitor a stack trace.
 *
 * This test is the reason that cannot come back quietly. It is a whole-application invariant, so it
 * also covers routes added after it — including any this project adds itself.
 */
class RouteTargetsExistTest extends TestCase
{
    public function test_every_route_resolves_to_a_real_controller_method(): void
    {
        $broken = [];

        foreach (Router::getRoutes() as $route) {
            /** @var Route $route */
            $action = $route->getAction('uses');

            // Closures and invokable-controller strings are resolved elsewhere; only the
            // 'Class@method' form can rot without anything noticing.
            if (!is_string($action) || !str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            if (!class_exists($class)) {
                $broken[] = sprintf('%-55s missing class  %s', $route->uri(), $class);
                continue;
            }

            // method_exists() is the right check even though controllers inherit __call():
            // Illuminate\Routing\Controller::__call() exists only to throw BadMethodCallException.
            if (!method_exists($class, $method)) {
                $broken[] = sprintf('%-55s missing method %s::%s()', $route->uri(), class_basename($class), $method);
            }
        }

        $this->assertSame([], $broken, sprintf(
            "%d route(s) point at a controller method that does not exist. Each one answers 500, not 404:\n\n%s\n",
            count($broken),
            implode("\n", $broken)
        ));
    }

    /**
     * The scaffold page the AI module published at `/ai` — unauthenticated, titled
     * "AI Module - Laravel", rendering "Hello World" — must not come back with the module's routes.
     */
    public function test_the_ai_module_scaffold_route_is_not_published(): void
    {
        $uris = collect(Router::getRoutes())->map(fn (Route $route) => $route->uri())->all();

        $this->assertNotContains('ai', $uris, 'the module scaffold resource route is publicly routable again');
        $this->assertNotContains('ai/create', $uris);
    }
}
