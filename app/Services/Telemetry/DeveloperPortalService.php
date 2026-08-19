<?php

namespace App\Services\Telemetry;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The developer portal's data: the REST API surface read LIVE from the route
 * table, so the reference can never drift from the code the apps actually
 * call. Grouped by version and resource, flagged with what each route needs
 * (bearer token, guest allowed).
 */
class DeveloperPortalService
{
    /** @return array<string, array<string, array<int, array<string, mixed>>>> */
    public function apiReference(): array
    {
        $versions = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (!str_starts_with($uri, 'api/')) {
                continue;
            }

            $segments = explode('/', $uri);
            $version = $segments[1] ?? 'api';
            $resource = $segments[2] ?? '/';
            $middleware = $route->gatherMiddleware();

            $needsAuth = false;
            foreach ($middleware as $item) {
                if (is_string($item) && (str_starts_with($item, 'auth') || str_contains($item, 'passport'))) {
                    $needsAuth = true;
                }
            }

            $versions[$version][$resource][] = [
                'methods' => implode('|', array_values(array_diff($route->methods(), ['HEAD']))),
                'uri' => '/' . $uri,
                'name' => $route->getName(),
                'auth' => $needsAuth,
                'params' => array_map(fn ($name) => '{' . $name . '}', $route->parameterNames()),
            ];
        }

        foreach ($versions as &$resources) {
            ksort($resources);
            foreach ($resources as &$routes) {
                usort($routes, fn ($a, $b) => strcmp($a['uri'], $b['uri']));
            }
        }
        ksort($versions);

        return $versions;
    }

    /** Copy-paste integration recipes for the app teams. */
    public function guides(string $baseUrl): array
    {
        return [
            [
                'title' => translate('authentication'),
                'body' => translate('obtain_a_bearer_token_then_send_it_on_every_authenticated_call'),
                'snippet' => "curl -X POST {$baseUrl}/api/v1/auth/login \\\n"
                    . "  -H 'Content-Type: application/json' \\\n"
                    . "  -d '{\"email\": \"customer@example.com\", \"password\": \"secret\"}'\n\n"
                    . "# then:\ncurl {$baseUrl}/api/v1/customer/info \\\n  -H 'Authorization: Bearer <token>'",
            ],
            [
                'title' => translate('banners_and_media'),
                'body' => translate('banner_records_carry_photo_full_url_use_its_path_as_is_do_not_build_storage_urls_yourself'),
                'snippet' => "curl {$baseUrl}/api/v1/banners\n\n"
                    . "# response items include:\n"
                    . "# photo_full_url: { key, path } — load path directly\n"
                    . "# resource_type: product|category|shop|brand|custom + resource_id",
            ],
            [
                'title' => translate('catalogue'),
                'body' => translate('products_categories_and_search_the_endpoints_the_store_app_lists_products_with'),
                'snippet' => "curl '{$baseUrl}/api/v1/products/latest?limit=10&offset=1'\n"
                    . "curl '{$baseUrl}/api/v1/categories'\n"
                    . "curl '{$baseUrl}/api/v1/products/search?name=serum&limit=10&offset=1'",
            ],
            [
                'title' => translate('category_page_header'),
                'body' => translate('one_call_returns_the_category_banner_and_its_sub_categories_for_the_category_screen'),
                'snippet' => "curl {$baseUrl}/api/v1/categories/page-header/7\n\n"
                    . "# {\n"
                    . "#   category:      { id, name, slug, position, parent_id, icon_full_url },\n"
                    . "#   banner:        null | { id, title, sub_title, button_text, background_color,\n"
                    . "#                           url, resource_type, resource_id, photo_full_url,\n"
                    . "#                           inherited },   # inherited = the banner belongs to a parent\n"
                    . "#   sub_categories: [ { id, name, slug, position, products_count, icon_full_url } ]\n"
                    . "# }\n"
                    . "# Render the banner above the grid and the sub_categories as an entry strip;\n"
                    . "# hide either half when it is null/empty. Banners come from admin > banners\n"
                    . "# (type: Category Banner) and are NOT part of /api/v1/banners.",
            ],
            [
                'title' => translate('home_promo_banners'),
                'body' => translate('one_call_returns_the_home_promo_grid_with_each_banners_layout_and_both_images'),
                'snippet' => "curl {$baseUrl}/api/v1/banners/home-promos\n\n"
                    . "# [ { id, title, sub_title, button_text, background_color, url,\n"
                    . "#     layout,   # full | half | slider — how the banner is meant to sit\n"
                    . "#     priority, # display order, lowest first\n"
                    . "#     resource_type, resource_id,   # what tapping it opens\n"
                    . "#     photo_full_url, mobile_photo_full_url } ]\n"
                    . "# Render in the given order: `full` on its own row, two `half` side by\n"
                    . "# side, every `slider` pooled into one rotating slot. These banners\n"
                    . "# belong to no category and are NOT part of /api/v1/banners.",
            ],
            [
                'title' => translate('category_section_banners'),
                'body' => translate('one_call_returns_every_category_section_banner_with_both_its_web_and_mobile_image'),
                'snippet' => "curl {$baseUrl}/api/v1/banners/category-sections\n\n"
                    . "# [ {\n"
                    . "#   id, title, sub_title, button_text, background_color, url,\n"
                    . "#   photo_full_url,         # wide image, what the web renders\n"
                    . "#   mobile_photo_full_url,  # phone-shaped image (falls back to the wide one)\n"
                    . "#   category_id, category: { id, name, slug }\n"
                    . "# } ]\n"
                    . "# Render each banner above that category's product row on the home\n"
                    . "# screen, using mobile_photo_full_url. Like the category page banner,\n"
                    . "# these are NOT part of /api/v1/banners.",
            ],
            [
                'title' => translate('vendor_app'),
                'body' => translate('the_seller_api_lives_under_api_v3_seller_with_its_own_token'),
                'snippet' => "curl -X POST {$baseUrl}/api/v3/seller/auth/login \\\n"
                    . "  -H 'Content-Type: application/json' \\\n"
                    . "  -d '{\"email\": \"vendor@example.com\", \"password\": \"secret\"}'",
            ],
            [
                'title' => translate('configuration'),
                'body' => translate('base_config_currencies_languages_and_feature_flags_for_app_bootstrapping'),
                'snippet' => "curl {$baseUrl}/api/v1/config",
            ],
        ];
    }
}
