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

    /**
     * The newest release notes, read straight from CHANGELOG.md.
     *
     * The portal's job is to be current; a hand-maintained "what's new" panel is exactly the
     * thing that goes stale. This parses the top entries of the repo's own changelog, so shipping
     * a release updates the portal by itself.
     *
     * @return array<int, array{version:string, title:string, points:array<int,string>}>
     */
    public function releaseNotes(int $limit = 3): array
    {
        try {
            $path = base_path('CHANGELOG.md');
            if (!is_file($path)) {
                return [];
            }

            $contents = (string) file_get_contents($path);
            // Entries look like: "## v4.4 — headline" followed by "- bullet" lines.
            preg_match_all('/^## (v[\d.]+)\s*[—-]\s*(.+)$/m', $contents, $matches, PREG_OFFSET_CAPTURE);

            $notes = [];
            foreach ($matches[0] as $index => $heading) {
                if (count($notes) >= max(1, $limit)) {
                    break;
                }

                $start = $heading[1] + strlen($heading[0]);
                $end = $matches[0][$index + 1][1] ?? strlen($contents);
                $body = substr($contents, $start, $end - $start);

                preg_match_all('/^- \*\*(.+?)\*\*/m', $body, $bullets);
                $points = array_slice(array_map('trim', $bullets[1] ?? []), 0, 5);

                $notes[] = [
                    'version' => trim($matches[1][$index][0]),
                    'title'   => trim($matches[2][$index][0]),
                    'points'  => $points,
                ];
            }

            return $notes;
        } catch (\Throwable) {
            return [];
        }
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
                'title' => translate('brand_page_header'),
                'body' => translate('one_call_returns_the_brand_banner_and_the_categories_its_products_live_in'),
                'snippet' => "curl {$baseUrl}/api/v1/brands/page-header/3\n\n"
                    . "# {\n"
                    . "#   brand:      { id, name, slug, image_full_url },\n"
                    . "#   banner:     null | { id, title, sub_title, url,\n"
                    . "#                        photo_full_url, mobile_photo_full_url },\n"
                    . "#   categories: [ { id, name, slug, products_count, icon_full_url } ]\n"
                    . "# }\n"
                    . "# The categories are only those holding active products of this brand, so a\n"
                    . "# chip never leads to an empty list. Filter with the same category id:\n"
                    . "# /api/v1/categories/products/{category_id}?brand_id=3",
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
            [
                'title' => translate('theme_banners'),
                'body' => translate('the_theme_builder_can_mint_its_own_banner_rows_treat_banner_type_as_an_open_set'),
                'snippet' => "curl {$baseUrl}/api/v1/banners\n\n"
                    . "# banner_type is an OPEN set. Types an app may now receive:\n"
                    . "#   Main Banner | Popup Banner | Footer Banner | Main Section Banner\n"
                    . "#   Category Banner | Category Section Banner | Home Promo Banner\n"
                    . "#   Brand Banner | Theme Banner   <-- minted by the Theme Builder\n"
                    . "# 'Theme Banner' has NO built-in slot: it renders only where the\n"
                    . "# merchant placed it in the theme. Apps should ignore unknown types\n"
                    . "# rather than failing on them.",
            ],
            [
                'title' => translate('category_and_brand_page_banners'),
                'body' => translate('page_banners_are_now_editable_from_the_category_and_brand_forms_and_stay_the_same_banner_rows'),
                'snippet' => "# A category's / brand's page banner is a normal banner row:\n"
                    . "#   banner_type    = 'Category Banner' | 'Brand Banner'\n"
                    . "#   resource_type  = 'category' | 'brand'\n"
                    . "#   resource_id    = that category's / brand's id\n\n"
                    . "curl {$baseUrl}/api/v1/categories/page-header/{category_id}\n"
                    . "curl {$baseUrl}/api/v1/brands/page-header/{brand_id}",
            ],
            [
                'title' => translate('addresses_zip_is_optional'),
                'body' => translate('zip_is_only_required_when_the_zip_allow_list_restriction_is_on_and_is_billing_is_optional'),
                'snippet' => "curl -X POST {$baseUrl}/api/v1/customer/address/add \\\n"
                    . "  -H 'Authorization: Bearer <token>' \\\n"
                    . "  -H 'Content-Type: application/json' \\\n"
                    . "  -d '{\"contact_person_name\": \"...\", \"address\": \"...\",\n"
                    . "       \"city\": \"...\", \"country\": \"...\", \"phone\": \"...\"}'\n\n"
                    . "# zip: omit it freely — required ONLY when config.delivery_zip_code_area_restriction = 1\n"
                    . "# is_billing: optional, defaults to 0\n"
                    . "# Orders now always carry a billing address: it falls back to the\n"
                    . "# shipping address when the customer does not enter a separate one.",
            ],
            [
                'title' => translate('a_theme_reaches_the_store_only_once_published'),
                'body' => translate('sections_colors_and_typography_all_travel_with_the_published_version_a_draft_changes_nothing'),
                'snippet' => "# The storefront reads the ACTIVE theme's PUBLISHED version only:\n"
                    . "#   themes.is_active = 1  AND  theme_versions.status = 'published'\n"
                    . "# Until both hold, the built-in home renders and Theme Settings colours\n"
                    . "# are not injected — this is the usual cause of 'the look did not change'.\n"
                    . "# The builder shows an activate + publish bar whenever that is the case.\n\n"
                    . "# Section settings carry breakpoint overrides beside the desktop value:\n"
                    . "#   padding_top | padding_top_tablet | padding_top_mobile\n"
                    . "#   columns     | columns_tablet     | columns_mobile\n"
                    . "#   height, visible — same pattern; an absent key means 'inherit'.",
            ],
            [
                'title' => translate('theme_sections_choose_real_records'),
                'body' => translate('a_section_stores_the_ids_it_shows_so_an_app_can_mirror_the_same_selection'),
                'snippet' => "# Section settings carry the merchant's picks as ids:\n"
                    . "#   product_slider:    source = category|brand|manual\n"
                    . "#                      source_id   -> that category / brand id\n"
                    . "#                      product_ids -> \"12,7,90\" (order = display order)\n"
                    . "#   category_grid:     category_ids -> \"3,8,1\" (empty = top-level by priority)\n"
                    . "#   flash_deal:        deal_id -> a specific deal (empty = whichever runs now)\n"
                    . "#   category_showcase: category_id -> the category whose banner,\n"
                    . "#                      sub-categories and products the block shows\n\n"
                    . "# A category pick includes everything filed under it, matched on all three\n"
                    . "# levels: category_id, sub_category_id, sub_sub_category_id.\n"
                    . "curl '{$baseUrl}/api/v1/categories/products/{category_id}?brand_id=3'",
            ],
        ];
    }
}
