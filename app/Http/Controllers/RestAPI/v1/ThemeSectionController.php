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
    private const IMAGE_KEYS = ['image', 'image_mobile', 'background_image', 'logo', 'icon_image'];

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
            . 'as "Theme Banner" rows). All image URLs are absolute. `sections` is empty when the '
            . 'merchant has not published a theme for that page — render the app\'s default layout then.',
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
            'settings' => $this->absolutize($section['settings'] ?? []),
            'blocks' => array_map(fn (array $block) => [
                'id' => $block['id'],
                'type' => $block['type'],
                'settings' => $this->absolutize($block['settings'] ?? []),
            ], $blocks),
            'cards' => $bannerBacked
                ? array_map(fn (array $card) => $this->absolutize($card), $this->resolver->blockCards($blocks))
                : null,
        ];
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

        return $settings;
    }
}
