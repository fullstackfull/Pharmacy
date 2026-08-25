<?php

namespace Tests\Feature;

use App\Services\SellerCenter\Navigation;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * One panel, and nothing in it taken away.
 *
 * The Seller Center's screens are mounted on `/vendor`, beside the classic panel's, and the first
 * route that matches a URL wins. That makes the load order load-bearing: the classic panel is
 * registered first, so a screen added in a later wave can only ever add an address — it can never
 * quietly take over one that already works.
 *
 * These tests are the guarantee. They dispatch real URLs through the real route table and assert
 * which controller answers, in both directions: every classic page still reaches its own
 * controller, and every Seller Center screen still reaches its own. A wave that breaks either
 * fails here rather than in a seller's hands.
 *
 * The narrowest case is `vendor/orders/{order}`, which is only survivable because it is numeric:
 * without `whereNumber`, it would swallow `vendor/orders/customers`, `vendor/orders/status` and
 * every other classic order endpoint.
 */
class SellerCenterRouteCollisionTest extends TestCase
{
    /** Classic pages that must keep answering, with the controller that has to answer them. */
    private const CLASSIC = [
        'vendor/dashboard' => 'Vendor\DashboardController',
        'vendor/orders/list/all' => 'Vendor\Order\OrderController',
        'vendor/orders/details/1' => 'Vendor\Order\OrderController',
        'vendor/orders/customers' => 'Vendor\Order\OrderController',
        'vendor/products/list/all' => 'Vendor\Product\ProductController',
        'vendor/products/add' => 'Vendor\Product\ProductController',
        'vendor/refund/index/pending' => 'Vendor\RefundController',
        'vendor/coupon/index' => 'Vendor\Coupon\CouponController',
        'vendor/clearance-sale' => 'Vendor\Promotion\ClearanceSaleController',
        'vendor/messages/index/customer' => 'Vendor\ChattingController',
        'vendor/business-settings/shipping-method/index' => 'Vendor\Shipping\ShippingMethodController',
    ];

    /** Seller Center screens that must keep answering, and are not allowed to be shadowed either. */
    private const SELLER_CENTER = [
        'vendor/overview' => 'Seller\HomeController',
        'vendor/control-tower' => 'Seller\ControlTowerController',
        'vendor/issues' => 'Seller\IssueController',
        'vendor/orders' => 'Seller\OrderController',
        'vendor/orders/1234' => 'Seller\OrderController',
        'vendor/products' => 'Seller\ProductController',
        'vendor/inventory' => 'Seller\InventoryController',
        'vendor/inventory/movements' => 'Seller\InventoryController',
        'vendor/automation' => 'Seller\AutomationController',
        'vendor/automation/new' => 'Seller\AutomationController',
        'vendor/automation/history' => 'Seller\AutomationHistoryController',
        'vendor/opportunities' => 'Seller\OpportunityController',
    ];

    public function test_every_classic_page_still_reaches_its_own_controller(): void
    {
        foreach (self::CLASSIC as $path => $controller) {
            $this->assertSame(
                $controller,
                $this->controllerFor($path),
                "{$path} no longer reaches {$controller} — a Seller Center route has shadowed it",
            );
        }
    }

    public function test_every_seller_center_screen_still_reaches_its_own_controller(): void
    {
        foreach (self::SELLER_CENTER as $path => $controller) {
            $this->assertSame(
                $controller,
                $this->controllerFor($path),
                "{$path} no longer reaches {$controller} — a classic route has shadowed it",
            );
        }
    }

    public function test_the_numeric_constraint_is_what_keeps_the_classic_order_endpoints_alive(): void
    {
        // `vendor/orders/{order}` sits in front of some twenty classic order endpoints. It is
        // survivable only because it refuses anything that is not a number.
        $this->assertSame('Seller\OrderController', $this->controllerFor('vendor/orders/1234'));

        // GET endpoints only: the classic order writes are POSTs, and this walks the GET table.
        foreach (['customers', 'list/all', 'details/1', 'export-excel/all'] as $segment) {
            $this->assertStringStartsWith(
                'Vendor\\',
                (string) $this->controllerFor('vendor/orders/' . $segment),
                "vendor/orders/{$segment} was swallowed by the Seller Center's numeric order route",
            );
        }
    }

    public function test_the_classic_panel_is_registered_first(): void
    {
        // The order is what makes the redesign additive rather than destructive, so it is asserted
        // rather than assumed: the classic panel's first route must be registered before the
        // Seller Center's.
        $positions = [];

        foreach (Route::getRoutes() as $index => $route) {
            $controller = $this->controllerOf($route);

            if ($controller === null) {
                continue;
            }

            if (str_starts_with($controller, 'Vendor\\') && !isset($positions['classic'])) {
                $positions['classic'] = $index;
            }
            if (str_starts_with($controller, 'Seller\\') && !isset($positions['seller_center'])) {
                $positions['seller_center'] = $index;
            }
        }

        $this->assertArrayHasKey('classic', $positions);
        $this->assertArrayHasKey('seller_center', $positions);
        $this->assertLessThan($positions['seller_center'], $positions['classic']);
    }

    public function test_no_screen_hard_codes_a_panel_url_that_does_not_exist(): void
    {
        // Destinations that have not been rebuilt yet are linked by their existing URL, which is the
        // one thing in this panel the router is not asked about. So they rot silently: sign-out
        // pointed at `vendor/auth/logout` while the route's address was the literal string
        // `vendor.auth.login` — a route name pasted into the URI slot — and signing out 404'd.
        $dead = [];

        foreach ($this->sellerCenterBlades() as $file) {
            foreach ($this->hardCodedPanelUrls((string) file_get_contents($file)) as $url) {
                if (!Navigation::pathIsRouted($url)) {
                    $dead[] = basename($file) . ' → ' . $url;
                }
            }
        }

        $this->assertSame([], $dead, "these links go nowhere:\n" . implode("\n", $dead));
    }

    /** @return array<int, string> */
    private function sellerCenterBlades(): array
    {
        $files = [];

        foreach ([
            resource_path('views/layouts/seller'),
            resource_path('views/seller-views'),
            resource_path('views/components/sc'),
        ] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * Every `url('vendor/…')` a template writes out, with a concatenated id standing in for the
     * dynamic tail so a parameterised path is still checked.
     *
     * @return array<int, string>
     */
    private function hardCodedPanelUrls(string $blade): array
    {
        preg_match_all("/url\(\s*'(vendor\/[^']*)'(\s*\.\s*[^)]+)?\)/", $blade, $found, PREG_SET_ORDER);

        $urls = [];
        foreach ($found as $match) {
            $path = rtrim($match[1], '/');
            // `url('vendor/products/edit/' . $product->id)` — the id is the part being appended.
            $urls[] = isset($match[2]) && trim($match[2]) !== '' ? $path . '/1' : $path;
        }

        return array_values(array_unique($urls));
    }

    public function test_every_seller_center_screen_is_reachable_by_staff_not_just_by_the_owner(): void
    {
        // The staff gate reads the second URL segment and is deny-by-default, which is right — a gap
        // fails closed. But both panels live on `/vendor`, so a Seller Center screen whose segment is
        // absent from that map is not "gated", it is unreachable: every staff member gets a 403 on a
        // screen the route itself would have let them open. Three waves of screens were behind that.
        $mapped = $this->staffMappedSegments();

        foreach (array_keys(self::SELLER_CENTER) as $path) {
            $segment = explode('/', trim($path, '/'))[1] ?? '';

            $this->assertContains(
                $segment,
                $mapped,
                "the Seller Center screen at {$path} is refused to every staff member: '{$segment}' is "
                . 'not in SellerStaffAccessMiddleware, which denies by default',
            );
        }
    }

    /**
     * The URL segments the staff gate knows about, read out of the middleware's own match arms.
     *
     * Read rather than duplicated: a copy of the list here would pass while the real map was wrong.
     *
     * @return array<int, string>
     */
    private function staffMappedSegments(): array
    {
        $source = file_get_contents(base_path('app/Http/Middleware/SellerStaffAccessMiddleware.php'));
        $body = substr($source, strpos($source, 'return match ($area) {'));
        $body = substr($body, 0, strpos($body, 'default =>'));

        preg_match_all("/'([a-z0-9\-]+)'\s*(?:,|=>)/", $body, $found);

        return array_values(array_unique($found[1] ?? []));
    }

    /** Which controller the router would hand this URL to, or null when nothing would. */
    private function controllerFor(string $path): ?string
    {
        $request = Request::create('/' . trim($path, '/'), 'GET');

        foreach (Route::getRoutes()->getRoutesByMethod()['GET'] ?? [] as $route) {
            if ($route->matches($request, includingMethod: false)) {
                return $this->controllerOf($route);
            }
        }

        return null;
    }

    private function controllerOf(RoutingRoute $route): ?string
    {
        $action = $route->getAction('controller');

        if (!is_string($action)) {
            return null;
        }

        $class = explode('@', $action)[0];

        return str_replace('App\\Http\\Controllers\\', '', $class);
    }
}
