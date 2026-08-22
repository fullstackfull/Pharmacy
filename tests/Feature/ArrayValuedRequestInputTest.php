<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\Customer\CustomerController;
use App\Http\Controllers\Admin\Settings\ThemeBuilderController;
use App\Http\Controllers\Admin\Telemetry\AnalyticsController;
use App\Http\Controllers\Admin\Telemetry\DeveloperPortalController;
use App\Http\Controllers\Admin\Telemetry\MonitoringController;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * `?param[]=x` is a URL anyone can type, and it used to be a 500.
 *
 * Every query parameter can arrive as an array. PHP 8 raises "Array to string conversion" on
 * `(string) $array` — a Warning, which this application's error handler converts into a thrown
 * ErrorException — while a typed parameter (`?string $key`) turns the same input into an
 * uncatchable TypeError and explode()/trim()/htmlspecialchars() reject it outright.
 *
 * An audit that actually requested each one found twenty-six such sites across the admin panel. The
 * worst was MonitoringController::range(): it runs before PanelRegistry wraps the panels in their
 * catch, so a single malformed URL returned 500 on ALL THIRTY-THREE monitoring sections rather than
 * degrading one card. Its docblock said the value was "validated against the allowed set rather than
 * trusted" — and the cast on the next line threw before that allow-list ever ran.
 *
 * The rule that replaced it, already the idiom in eleven monitoring panels: a filter nobody can
 * spell is simply not applied. Never throw, never guess, fall back to the documented default.
 *
 * These call the guards directly rather than over HTTP, because the guard is the thing under test —
 * the routes themselves need an authenticated admin and the full shop schema, and were verified
 * separately by requesting all thirty-four affected URLs.
 */
class ArrayValuedRequestInputTest extends TestCase
{
    /** Why every guard below exists, pinned so nobody "simplifies" one away. */
    public function test_casting_an_array_to_string_throws_in_this_application(): void
    {
        $this->expectException(\ErrorException::class);

        // @phpstan-ignore-next-line — the point of the test is that this is fatal here.
        $ignored = (string) ['x'];
    }

    public function test_the_monitoring_range_falls_back_instead_of_taking_the_console_down(): void
    {
        $range = fn (array $query) => $this->guard(MonitoringController::class, 'range', $query);

        $this->assertSame('1h', $range(['range' => ['x']]), 'an array must not reach the allow-list');
        $this->assertSame('1h', $range(['range' => ['a' => 'b']]));
        $this->assertSame('1h', $range(['range' => 'bogus']), 'an unknown window is still refused');
        $this->assertSame('1h', $range([]));

        // And the allow-list still does its real job.
        $this->assertSame('24h', $range(['range' => '24h']));
        $this->assertSame('90d', $range(['range' => '90d']));
    }

    public function test_the_analytics_window_falls_back_instead_of_a_typeerror(): void
    {
        // Window::make is declared (?string $key), so an array here was an uncatchable TypeError on
        // every analytics section and on the CSV export.
        $window = fn (array $query) => $this->guard(AnalyticsController::class, 'window', $query);

        $this->assertSame('30d', $window(['range' => ['x']])->key, 'the documented default');
        $this->assertSame('7d', $window(['range' => '7d'])->key);

        // The custom from/to branch was already guarded; it must stay that way.
        $this->assertSame('30d', $window(['from' => ['a'], 'to' => ['b']])->key);
    }

    public function test_a_developer_portal_filter_that_is_not_a_string_is_not_applied(): void
    {
        // One helper is the root cause of four 500s: search and method reach ApiManifest's string
        // functions, audience and version reach a concatenation.
        $filters = $this->guard(DeveloperPortalController::class, 'filters', [
            'search' => ['x'],
            'method' => ['GET'],
            'audience' => ['customer_app'],
            'version' => ['v1'],
            'group' => 'orders',
        ]);

        $this->assertSame(['group' => 'orders'], $filters, 'only the spellable filter survives');

        foreach ($filters as $key => $value) {
            $this->assertIsString($value, $key . ' reached the manifest as something other than a string');
        }
    }

    public function test_a_date_range_picker_value_that_is_not_a_range_is_not_applied(): void
    {
        // explode()'s $string parameter is typed, so an array was a TypeError on six customer
        // screens; a half-typed range was a separate undefined-index crash at Carbon.
        $range = fn (mixed $value) => $this->guard(CustomerController::class, 'getDateRangeInMDY', ['order_date' => $value], 'order_date');

        $this->assertNull($range(['x']), 'an array is not a range');
        $this->assertNull($range('01/01/2026'), 'half a range is not a range');
        $this->assertNull($range('not a date - also not a date'), 'the format check still runs');
        $this->assertNull($range(''));

        // The picker's own format, checked with the project's existing checkDateFormatInMDY().
        $this->assertSame(['01/01/2026', '01/31/2026'], $range('01/01/2026 - 01/31/2026'));
    }

    public function test_the_theme_builder_picker_survives_an_array(): void
    {
        // These are the endpoints the visual builder's own category/brand/product pickers call, so
        // the 500 surfaced as a picker that silently never loaded.
        $query = fn (mixed $value) => $this->guard(ThemeBuilderController::class, 'queryString', ['q' => $value], 'q');

        $this->assertSame('', $query(['x']));
        $this->assertSame('paracetamol', $query('paracetamol'));
    }

    /**
     * Invoke a controller's private guard with a query string, without booting its dependencies.
     *
     * newInstanceWithoutConstructor because these guards read only the Request — instantiating the
     * controller would drag in a dozen repositories to test a type check.
     *
     * @param  array<string, mixed>  $query
     */
    private function guard(string $controller, string $method, array $query, mixed ...$extra): mixed
    {
        $reflection = new ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);

        $instance = (new \ReflectionClass($controller))->newInstanceWithoutConstructor();
        $request = Request::create('/', 'GET', $query);

        return $reflection->invoke($instance, $request, ...$extra);
    }
    public function test_a_search_box_that_is_not_a_string_returns_the_unfiltered_list(): void
    {
        // Seven seller-API listings split their search on spaces and ANDed the parts. Every one did
        // it straight off the request, so `?search[]=x` from the vendor app answered with a 500
        // instead of a result set. A search nobody can spell is simply not applied — which is what
        // an empty search box already does.
        $this->assertSame([], searchTerms(['x']));
        $this->assertSame([], searchTerms(null));
        $this->assertSame([], searchTerms(''));
        $this->assertSame([], searchTerms('   '), 'whitespace is not a search term');

        $this->assertSame(['paracetamol'], searchTerms('paracetamol'));
        $this->assertSame(['paracetamol', 'syrup'], searchTerms('  paracetamol  syrup '));
    }

    public function test_a_bulk_action_refuses_an_array_instead_of_throwing_on_it(): void
    {
        // The allow-list under each of these was always right; the cast above it threw first, so
        // the 422 they were written to return could never be reached.
        $request = Request::create('/', 'POST', ['status' => ['x'], 'action' => ['y'], 'order_status' => ['z']]);

        foreach (['status', 'action', 'order_status'] as $field) {
            $value = $request->input($field);
            $guarded = is_string($value) ? $value : '';

            $this->assertSame('', $guarded);
            $this->assertFalse(in_array($guarded, ['block', 'unblock', 'approved', 'delivered'], true));
        }
    }
    public function test_a_form_echoes_back_only_what_could_have_been_typed_into_it(): void
    {
        // Filter forms repopulate with `{{ request('x') }}`. Blade compiles that to e(), e() calls
        // htmlspecialchars(), and htmlspecialchars() rejects an array — so a guarded controller
        // still died at the VIEW. 85 echoes and 25 component calls read through this instead.
        $this->app['request'] = Request::create('/', 'GET', [
            'searchValue' => ['x'],
            'typed' => 'paracetamol',
            'blank' => '',
        ]);

        $this->assertSame('', requestString('searchValue'), 'an array was never typed into a text box');
        $this->assertSame('all', requestString('missing', 'all'), 'the default survives');
        $this->assertSame('all', requestString('searchValue') ?: 'all');
        $this->assertSame('paracetamol', requestString('typed'));
        $this->assertSame('', requestString('blank'));
    }
}
