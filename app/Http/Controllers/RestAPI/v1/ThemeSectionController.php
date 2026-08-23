<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Theme\SectionDataResolver;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\StorefrontThemeRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The published theme, for a client that is not the web storefront.
 *
 * The theme builder, Banner Setup and the storefront are already one system on the web:
 * StorefrontThemeRenderer serves the PUBLISHED version, and blockCards() resolves every
 * banner-backed block against its Promotion → Banners row live — an edit in Banner Setup shows on
 * the storefront without touching the theme, and unpublishing a banner hides its tile. The mobile
 * app had no way into that pipeline, so integrators queried theme_sections by hand: a hardcoded
 * version id that goes stale on the next publish, no publish-state checks, no banner overrides,
 * and a query per block.
 *
 * This endpoint IS that pipeline. Whatever the merchant publishes in the builder is what it
 * answers, section order and visibility included; whatever they edit in Banner Setup is live in
 * it, exactly as on the web. It adds only what a phone needs and a blade does not: image URLs are
 * made absolute, because the app has no origin to resolve `/storage/...` against.
 */
class ThemeSectionController extends Controller
{
    /** The page areas the builder composes; anything else has no sections to serve. */
    private const PAGES = ['home', 'header', 'footer'];

    /** Settings keys whose value is an image path a phone must be able to fetch. */
    private const IMAGE_KEYS = ['image', 'image_2', 'image_3', 'image_mobile', 'background_image', 'logo', 'icon_image'];

    public function __construct(
        private readonly StorefrontThemeRenderer $renderer,
        private readonly SectionDataResolver $resolver,
    ) {
    }

    #[ApiDoc(
        summary: 'The published theme page, with every banner-backed block resolved live',
        description: 'One request per page area (home, header, footer), optionally narrowed to one '
            . 'section type: /api/v1/theme/sections?page=home&type=banner_mosaic. '
            . 'Serves the PUBLISHED theme version — never a draft, never a version id the caller names. '
            . 'Each section carries its normalized `settings`, its visible `blocks`, and — for sections '
            . 'whose blocks are banner-backed (mosaic tiles, slides, banners, splits) — render-ready '
            . '`cards`: the block merged with its linked Promotion → Banners row, so an edit or '
            . 'unpublish in Banner Setup changes this response without any theme change. Manage the '
            . 'images in Admin → Promotion → Banner Setup (builder uploads register themselves there '
            . 'as "Theme Banner" rows). All image URLs are absolute, and responsive settings arrive '
            . 'resolved for the mobile breakpoint. A banner_mosaic renders per `settings.display`: '
            . '"grid" (asymmetric wall; tile shapes small/square 1x1, wide 2x1, tall 1x2, large 2x2, '
            . 'strip full-width) or "swipe" (one horizontally swipeable row; the shape sets card '
            . 'width against settings.height). A card whose `images` holds more than one frame '
            . 'crossfades through them every settings.rotate_ms. Every card carries a structured `target` — what tapping it opens: '
            . '{kind: product|category|brand|shop, id, slug, name} resolved from the linked banner\'s '
            . 'own resource picker or from the link URL; {kind: url} for a link that matches no '
            . 'catalogue shape (feed it to the deep-link resolve API); {kind: none} for a tile that '
            . 'opens nothing. Each section also carries `source` — where its DATA '
            . 'lives: `inline` (everything is in this payload), `api` (fetch the named v1 endpoint with '
            . 'the given params, plus the standard guest_id/token the app already sends), or `none` '
            . '(no public API feeds it yet; the note says why — hide that section). `sections` is empty '
            . 'when the merchant has not published a theme for that page — render the app\'s default '
            . 'layout then.',
        audience: ApiDoc::CUSTOMER_APP,
        visibility: ApiDoc::PARTNER_VISIBLE,
        stability: ApiDoc::STABLE,
        since: 'v1',
    )]
    public function sections(Request $request): JsonResponse
    {
        // The house rule for request input: a value nobody can spell is not a filter. `?page[]=x`
        // must fall back, never 500 a public endpoint.
        $page = $request->query('page', 'home');
        $page = is_string($page) && in_array($page, self::PAGES, true) ? $page : 'home';

        $type = $request->query('type');
        $type = is_string($type) && $type !== '' ? $type : null;

        $sections = $this->renderer->sectionsFor($page) ?? [];

        if ($type !== null) {
            $sections = array_values(array_filter($sections, fn (array $section) => $section['type'] === $type));
        }

        return response()->json([
            'page' => $page,
            'sections' => array_map(fn (array $section) => $this->present($section), $sections),
        ]);
    }

    /**
     * One section as the app renders it.
     *
     * `cards` exists only where the web renders cards — sections whose blocks are banner-backed —
     * and comes from the SAME resolver the storefront uses, so the two can never disagree about
     * which tile shows or which image it shows.
     *
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function present(array $section): array
    {
        $blocks = $section['blocks'] ?? [];
        $bannerBacked = array_intersect(
            array_column($blocks, 'type'),
            SectionRegistry::BANNER_BACKED_BLOCK_TYPES,
        ) !== [];

        return [
            'id' => $section['id'],
            'type' => $section['type'],
            'settings' => $this->absolutize($this->forPhone($section['settings'] ?? [])),
            'blocks' => array_map(fn (array $block) => [
                'id' => $block['id'],
                'type' => $block['type'],
                'settings' => $this->absolutize($this->forPhone($block['settings'] ?? [])),
            ], $blocks),
            'cards' => $bannerBacked
                ? array_map(fn (array $card) => $this->absolutize($card), $this->resolver->blockCards($blocks, withTargets: true))
                : null,
            'source' => $this->sourceFor($section['type'], $section['settings'] ?? [], $blocks),
        ];
    }

    /**
     * Responsive settings, resolved for the client this endpoint serves.
     *
     * The builder stores per-breakpoint values as `<key>_mobile` / `<key>_tablet` siblings and the
     * web picks at render time. This endpoint has exactly one breakpoint, so the mobile value wins
     * in place; the siblings stay in the payload for a client that wants the full picture.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function forPhone(array $settings): array
    {
        foreach ($settings as $key => $value) {
            if (str_ends_with($key, '_mobile') && $value !== null && $value !== '') {
                $settings[substr($key, 0, -7)] = $value;
            }
        }

        return $settings;
    }

    /**
     * Where the app fetches this section's DATA, named per instance.
     *
     * The payload carries a section's look — settings, blocks, cards — but a product slider's
     * products or a category grid's counts live behind the catalogue APIs, and which one depends on
     * the settings the merchant chose. Spelling that mapping out here is what makes the whole app
     * home page drivable from the builder: the app renders the section list in order and follows
     * each section's `source`, instead of hardcoding which rail calls which endpoint.
     *
     * `kind` is one of:
     *   inline — everything needed is already in this payload (banners, text, FAQs, stats).
     *   api    — fetch `endpoint` with `params`; every endpoint named here exists in v1 and is
     *            documented in the Developer Portal.
     *   none   — this section has no public API to feed it yet; `note` says what is missing, so an
     *            absent rail in the app reads as a known gap and not a mystery.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function sourceFor(string $type, array $settings, array $blocks): array
    {
        return match ($type) {
            'product_slider' => $this->productSource($settings),
            'product_tabs' => [
                'kind' => 'api',
                // One source per tab, in tab order — the app fetches as the shopper switches.
                'tabs' => array_map(
                    fn (array $block) => $this->productSource($block['settings'] ?? []),
                    array_values(array_filter($blocks, fn (array $block) => $block['type'] === 'tab')),
                ),
            ],
            'category_grid', 'category_showcase' => ['kind' => 'api', 'endpoint' => '/api/v1/categories', 'params' => []],
            'brand_slider', 'brand_showcase' => ['kind' => 'api', 'endpoint' => '/api/v1/brands', 'params' => []],
            'flash_deal' => ['kind' => 'api', 'endpoint' => '/api/v1/flash-deals', 'params' => [],
                'note' => 'Then /api/v1/flash-deals/products/{deal_id} for the products.'],
            'deal_of_the_day' => ['kind' => 'api', 'endpoint' => '/api/v1/dealsoftheday/deal-of-the-day', 'params' => []],
            'featured_deal' => ['kind' => 'api', 'endpoint' => '/api/v1/deals/featured', 'params' => []],
            'clearance_sale' => ['kind' => 'api', 'endpoint' => '/api/v1/products/clearance-sale', 'params' => []],
            'vendor_slider', 'vendor_showcase' => ['kind' => 'api', 'endpoint' => '/api/v1/seller/list/all', 'params' => []],
            'coupon_strip' => ['kind' => 'api', 'endpoint' => '/api/v1/coupon/list', 'params' => [],
                'note' => 'Requires an authenticated customer; render the strip from `cards` for guests.'],
            'recently_viewed' => ['kind' => 'none',
                'note' => 'Backed by the web visitor cookie; no app equivalent yet. Hide this section in the app.'],
            'blog_posts' => ['kind' => 'none',
                'note' => 'The Blog module exposes no public API. Hide this section in the app.'],
            // Everything else renders entirely from this payload.
            default => ['kind' => 'inline'],
        };
    }

    /**
     * The catalogue endpoint behind one product source, exactly as the storefront resolves it.
     *
     * Every endpoint named here was checked against routes/rest_api/v1/api.php — a source hint that
     * points at a route that does not exist is worse than none.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function productSource(array $settings): array
    {
        $source = is_string($settings['source'] ?? null) ? $settings['source'] : 'featured';
        $limit = (int) ($settings['limit'] ?? 10);

        return match ($source) {
            'best_selling' => ['kind' => 'api', 'endpoint' => '/api/v1/products/best-sellings', 'params' => ['limit' => $limit, 'offset' => 1]],
            'new_arrival' => ['kind' => 'api', 'endpoint' => '/api/v1/products/new-arrival', 'params' => ['limit' => $limit, 'offset' => 1]],
            'top_rated' => ['kind' => 'api', 'endpoint' => '/api/v1/products/top-rated', 'params' => ['limit' => $limit, 'offset' => 1]],
            // The picked category or brand lives in `source_id` — the picker follows the source
            // dropdown, so one key serves both. (An earlier map read `category_id`, a key the
            // schema never stores, and pointed every category rail at /products/0.)
            'category' => ['kind' => 'api', 'endpoint' => '/api/v1/categories/products/' . (int) ($settings['source_id'] ?? 0), 'params' => ['limit' => $limit, 'offset' => 1]],
            'brand' => ['kind' => 'api', 'endpoint' => '/api/v1/brands/products/' . (int) ($settings['source_id'] ?? 0), 'params' => ['limit' => $limit, 'offset' => 1]],
            'manual' => ['kind' => 'api', 'endpoint' => '/api/v1/products/by-ids',
                'params' => ['ids' => implode(',', $this->pickedIds($settings['product_ids'] ?? null))]],
            default => ['kind' => 'api', 'endpoint' => '/api/v1/products/featured', 'params' => ['limit' => $limit, 'offset' => 1]],
        };
    }

    /**
     * The merchant's hand-picked ids, in their order — the same normalization the storefront's
     * resolver applies, so the app and the web can never disagree about what "these products" means.
     *
     * @return array<int, int>
     */
    private function pickedIds(string|array|null $picked): array
    {
        $ids = is_array($picked) ? $picked : explode(',', (string) $picked);

        return array_values(array_filter(array_map('intval', $ids), fn ($id) => $id > 0));
    }

    /**
     * Image paths a browser resolves against the page origin, made whole for a client that has none.
     *
     * Only the known image keys are touched, and only when they hold a root-relative path — a full
     * URL, a data URI or ordinary text passes through untouched.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function absolutize(array $settings): array
    {
        foreach (self::IMAGE_KEYS as $key) {
            $value = $settings[$key] ?? null;

            if (is_string($value) && str_starts_with($value, '/')) {
                $settings[$key] = url($value);
            }
        }

        // A multi-frame tile's whole frame list, same rule as its lead image.
        if (isset($settings['images']) && is_array($settings['images'])) {
            $settings['images'] = array_map(
                fn ($frame) => is_string($frame) && str_starts_with($frame, '/') ? url($frame) : $frame,
                $settings['images'],
            );
        }

        return $settings;
    }
}
