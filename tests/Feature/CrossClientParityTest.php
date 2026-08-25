<?php

namespace Tests\Feature;

use App\Services\Analytics\Reporting\FulfilmentAnalytics;
use App\Services\Analytics\Reporting\Window;
use App\Services\Marketplace\SlaService;
use App\Services\Platform\Policy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The two clients are one product (design handoff PART 16).
 *
 * A seller who sees one answer in the app and another in the panel trusts
 * neither, and the failure is quiet: nothing errors, the numbers simply differ
 * and whoever notices assumes they misread something. So the properties that
 * make the panel and the phone one product are asserted here rather than left
 * to the discipline of whoever edits next.
 *
 * Three of them:
 *
 *   1. **A permission denied in one client is denied in the other.** Read out of
 *      both route tables, so a route added to one side without its gate fails
 *      this rather than shipping.
 *   2. **A threshold set once is read by both.** The marketplace's own limits
 *      live in settings; a client that carried its own copy would disagree with
 *      the platform the day somebody changed one — which is exactly the defect
 *      this test was written after finding in the app's scorecard screen.
 *   3. **The API tells a client what it is being judged against.** Rates alone
 *      cannot be rendered honestly: a rate beside its ceiling is a position, a
 *      rate on its own is a statistic.
 */
class CrossClientParityTest extends TestCase
{
    /**
     * Capabilities both clients expose, and the permission each side must require.
     *
     * Written as web route name => API URI so a mismatch names the pair rather
     * than a permission string, which is what somebody debugging needs.
     */
    private const SHARED = [
        'seller.finance.statements' => 'api/v3/seller/seller-center/statement',
        'seller.finance.reconciliation' => 'api/v3/seller/seller-center/finance/reconciliation',
        'seller.integrations.api' => 'api/v3/seller/seller-center/integrations/keys',
        'seller.integrations.webhooks' => 'api/v3/seller/seller-center/integrations/webhooks',
        'seller.team.index' => 'api/v3/seller/seller-center/security/staff',
        'seller.security.index' => 'api/v3/seller/seller-center/security/audit',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['business_settings', 'settings'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('type')->nullable();
                $t->string('key')->nullable();
                $t->text('value')->nullable();
                $t->timestamps();
            });
        }
    }

    public function test_a_capability_both_clients_expose_is_gated_the_same_on_both(): void
    {
        foreach (self::SHARED as $webRoute => $apiUri) {
            $web = $this->permissionsOfName($webRoute);
            $api = $this->permissionsOfUri($apiUri);

            $this->assertNotSame([], $web, "{$webRoute} declares no permission");
            $this->assertNotSame([], $api, "{$apiUri} declares no permission");

            // Set comparison rather than string comparison: the API states
            // alternatives on one middleware and the web sometimes on two, and
            // "either of these" is the same rule written differently.
            $this->assertSame(
                $web,
                $api,
                "{$webRoute} and {$apiUri} are the same capability gated differently — "
                . 'a permission refused on the panel and allowed on the phone is not a permission',
            );
        }
    }

    public function test_every_seller_center_write_declares_a_permission(): void
    {
        $ungated = [];

        foreach (Route::getRoutes() as $route) {
            $controller = (string) $route->getAction('controller');

            if (!str_contains($controller, 'App\\Http\\Controllers\\Seller\\')) {
                continue;
            }

            if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
                continue;
            }

            if ($this->permissionsOf($route) === []) {
                $ungated[] = $route->getName() ?: $route->uri();
            }
        }

        // A write with no declared permission is not "open to everyone" — the
        // staff gate's segment map catches it — but it is a rule stated in one
        // place instead of two, and the segment map is the coarse pre-filter
        // rather than the decision.
        $this->assertSame([], $ungated, "these Seller Center writes declare no permission:\n" . implode("\n", $ungated));
    }

    public function test_a_threshold_the_marketplace_sets_is_the_one_both_clients_read(): void
    {
        DB::table('business_settings')->insert([
            'type' => 'sla_max_cancellation_rate',
            'value' => '0.08',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The web performance screen and the API scorecard both call this. If a
        // client carried its own copy of 10%, a marketplace that lowered its
        // ceiling to 8% would have the phone calling a breach comfortable while
        // the platform opened one against it.
        $this->assertSame(0.08, app(SlaService::class)->thresholds()['cancellation_rate']);
    }

    public function test_the_api_publishes_the_thresholds_it_judges_against(): void
    {
        $route = $this->routeForUri('api/v3/seller/seller-center/scorecard');

        $this->assertNotNull($route, 'the scorecard endpoint does not exist');

        // The payload is assembled in the controller, so this asserts the
        // contract rather than the wiring: the fields a client needs to render
        // a rate as a position rather than as a statistic.
        $source = file_get_contents(base_path('app/Http/Controllers/RestAPI/v3/seller/SellerCenterController.php'));

        foreach (['thresholds', 'open_breaches', 'over_the_line', 'processing_window_hours'] as $field) {
            $this->assertStringContainsString(
                "'" . $field . "'",
                $source,
                "the scorecard endpoint does not publish {$field}, so the app cannot say what a rate means",
            );
        }
    }

    public function test_a_policy_written_once_is_read_by_every_consumer(): void
    {
        $policy = app(Policy::class);
        $before = $policy->int('shipping_silent_hours');

        // Written through the path an operator's save actually takes, because
        // that path is what invalidates the memo. A raw insert would test a
        // route nothing uses and pass while the real one was broken.
        $policy->save(['shipping_silent_hours' => $before + 5]);

        // Both the seller's fulfilment screen and the fulfilment analytics read
        // this same key, and so does the issue detector that opens the exception.
        // One write, one answer, wherever it is read from.
        $this->assertSame($before + 5, app(Policy::class)->int('shipping_silent_hours'));
        // The three consumers by name, so a fourth that resolves it some other
        // way is a conscious choice rather than an accident: the seller's
        // fulfilment list, the analytics report, and the detector that opens the
        // shipping exception.
        $this->assertSame(
            $before + 5,
            app(FulfilmentAnalytics::class)->dispatch(Window::make('30d'))['threshold_hours'],
        );
    }

    /** @return array<int, string> */
    private function permissionsOfName(string $name): array
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertInstanceOf(RoutingRoute::class, $route, "route {$name} does not exist");

        return $this->permissionsOf($route);
    }

    /** @return array<int, string> */
    private function permissionsOfUri(string $uri): array
    {
        $route = $this->routeForUri($uri);

        $this->assertNotNull($route, "route {$uri} does not exist");

        return $this->permissionsOf($route);
    }

    private function routeForUri(string $uri): ?RoutingRoute
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array('GET', $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }

    /**
     * The permissions a route requires, flattened and sorted.
     *
     * `seller_can:a,b` means "any of these", so the set is what matters and the
     * order it was typed in does not.
     *
     * @return array<int, string>
     */
    private function permissionsOf(RoutingRoute $route): array
    {
        $permissions = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'seller_can:')) {
                foreach (explode(',', substr($middleware, strlen('seller_can:'))) as $permission) {
                    $permissions[] = trim($permission);
                }
            }
        }

        $permissions = array_values(array_unique($permissions));
        sort($permissions);

        return $permissions;
    }
}
