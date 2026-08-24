<?php

namespace Tests\Feature;

use App\Services\Theme\ContentSource;
use App\Services\Theme\ThemeSourceMap;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Nothing the control panel offers may lead nowhere.
 *
 * Every screen shipped on this branch is only real if the panel can reach it and everything on
 * it lands on a live route with a live controller method. This walks the actual blade sources —
 * the sidebar, the App Builder area, Commerce Experience, Theme Management — collects every
 * route() they name, and holds each against the router; then it reflects every admin
 * app-builder/commerce/theme route onto its controller, and every endpoint the theme's source
 * map can hint an app at onto the API routes. A screen that references a missing route, a route
 * whose method was renamed, or a hint at an endpoint that does not exist all fail HERE, not in
 * front of a merchant or inside an installed app.
 */
class AdminPanelWiringTest extends TestCase
{
    /** The blade surfaces this branch owns end to end, sidebar included. */
    private const SURFACES = [
        'views/layouts/admin/partials/v2/_side-bar.blade.php',
        'views/admin-views/app-builder',
        'views/admin-views/commerce',
        'views/admin-views/theme',
    ];

    public function test_every_route_the_panel_references_exists(): void
    {
        $missing = [];

        foreach ($this->referencedRouteNames() as $name => $files) {
            // Add-on module routes register only while their add-on is published, and the blade
            // guards those links behind the same check ($v2Auction) — a static scan cannot see
            // the guard, so their absence here is the add-on being off, not a broken link.
            if (str_starts_with($name, 'admin.auction.')) {
                continue;
            }

            if (!Route::has($name)) {
                $missing[$name] = implode(', ', array_unique($files));
            }
        }

        $this->assertSame([], $missing, 'these blades link to routes that do not exist');
    }

    public function test_every_app_builder_commerce_and_theme_route_lands_on_a_real_method(): void
    {
        $broken = [];

        foreach (Route::getRoutes()->getRoutesByName() as $name => $route) {
            if (!preg_match('/^admin\.(app-builder|commerce|theme)\./', $name)) {
                continue;
            }

            $controller = $route->getAction('controller');
            if (!is_string($controller) || !str_contains($controller, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $controller, 2);

            if (!class_exists($class) || !method_exists($class, $method)) {
                $broken[$name] = $controller;
            }
        }

        $this->assertSame([], $broken, 'these routes point at controller methods that do not exist');
    }

    public function test_the_sidebar_offers_every_area_this_branch_built(): void
    {
        $sidebar = File::get(resource_path('views/layouts/admin/partials/v2/_side-bar.blade.php'));

        foreach ([
            'admin.app-builder.index'          => 'the App Builder',
            'admin.commerce.collections.index' => 'Commerce Experience',
            'admin.theme.index'                => 'Theme Management',
            'admin.analytics.index'            => 'Analytics',
        ] as $routeName => $area) {
            $this->assertStringContainsString($routeName, $sidebar, $area . ' has no door in the main panel');
        }
    }

    public function test_the_area_navs_offer_every_screen_of_their_area(): void
    {
        $appBuilderNav = File::get(resource_path('views/admin-views/app-builder/_nav.blade.php'));
        foreach (['index', 'pages', 'sections', 'media', 'templates', 'health'] as $screen) {
            $this->assertStringContainsString('admin.app-builder.' . ($screen === 'index' ? 'index' : $screen),
                $appBuilderNav, "the App Builder nav is missing its {$screen} screen");
        }
        $this->assertStringContainsString('admin.theme.settings.index', $appBuilderNav, 'global styles link');
        $this->assertStringContainsString('admin.theme.index', $appBuilderNav, 'publishing link');

        $commerceNav = File::get(resource_path('views/admin-views/commerce/_nav.blade.php'));
        foreach (['collections', 'campaigns', 'segments', 'experiments'] as $screen) {
            $this->assertStringContainsString('admin.commerce.' . $screen . '.index',
                $commerceNav, "the Commerce nav is missing its {$screen} screen");
        }
    }

    public function test_every_endpoint_the_theme_can_hint_an_app_at_exists(): void
    {
        $map = app(ThemeSourceMap::class);
        $hints = [];

        // Every product source kind the builder can store, hinted exactly as delivery hints it.
        foreach (ContentSource::KINDS as $kind) {
            $hints[$kind] = $map->products([
                'source' => $kind, 'source_id' => 1, 'collection_id' => 1,
                'product_ids' => '1,2', 'limit' => 8,
            ]);
        }

        $paths = collect(Route::getRoutes())->map(fn ($route) => '/' . ltrim($route->uri(), '/'));
        $unrouted = [];

        foreach ($hints as $kind => $hint) {
            if (($hint['kind'] ?? null) !== 'api') {
                continue;
            }

            // Path parameters in registered URIs ({id}) match any concrete segment in the hint.
            $matched = $paths->contains(function (string $uri) use ($hint) {
                // The placeholder must survive preg_quote untouched, so it is alphanumeric.
                $template = preg_replace('/\{[^}]+\}/', 'XPARAMX', $uri);
                $pattern = '#^' . str_replace('XPARAMX', '[^/]+', preg_quote($template, '#')) . '$#';

                return (bool) preg_match($pattern, $hint['endpoint']);
            });

            if (!$matched) {
                $unrouted[$kind] = $hint['endpoint'];
            }
        }

        $this->assertSame([], $unrouted,
            'the payload would send installed apps to endpoints that do not exist');
    }

    public function test_the_measurement_pipeline_has_both_of_its_ends(): void
    {
        // The web beacon's collect endpoint, and the theme payload endpoints the app syncs on.
        $this->assertTrue(Route::has('analytics.collect'), 'the beacon has nowhere to send impressions');

        foreach (['api.v1.theme.home', 'api.v1.theme.version', 'api.v1.theme.sections'] as $name) {
            $this->assertTrue(Route::has($name), $name . ' is how every installed app syncs');
        }
    }

    // ---------------------------------------------------------------------------------------

    /** @return array<string, array<int, string>> route name => blade files naming it */
    private function referencedRouteNames(): array
    {
        $names = [];

        foreach (self::SURFACES as $surface) {
            $path = resource_path($surface);
            $files = is_dir($path) ? File::allFiles($path) : [new \SplFileInfo($path)];

            foreach ($files as $file) {
                if (!str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                preg_match_all("/route\\('((?:admin|analytics)\\.[a-z0-9._-]+)'/", File::get($file->getPathname()), $matches);

                foreach ($matches[1] as $name) {
                    $names[$name][] = $file->getFilename();
                }
            }
        }

        ksort($names);

        return $names;
    }
}
