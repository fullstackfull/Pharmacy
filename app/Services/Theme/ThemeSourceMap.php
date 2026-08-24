<?php

namespace App\Services\Theme;

/**
 * Where a section's DATA comes from, named per instance.
 *
 * A section payload carries a section's LOOK — settings, blocks, cards. Its products, categories
 * and vendors live behind the catalogue APIs, and which endpoint applies depends on what the
 * merchant chose in the builder. Spelling that mapping out is what makes the home page drivable
 * from the builder at all: the client renders the section list in order and follows each section's
 * `source`, instead of hardcoding which rail calls which endpoint.
 *
 * `kind` is one of:
 *   inline — everything needed is already in the payload (banners, text, FAQs, stats)
 *   api    — fetch `endpoint` with `params`; every endpoint named here exists in v1
 *   none   — no public API feeds this section yet; `note` says what is missing, so an absent rail
 *            reads as a known gap rather than a bug
 *
 * Extracted from the mobile sections endpoint so the versioned delivery path and the original
 * endpoint cannot drift into two different answers for the same section.
 */
class ThemeSourceMap
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    public function for(string $type, array $settings, array $blocks): array
    {
        return match ($type) {
            'product_slider' => $this->products($settings),

            'product_tabs' => [
                'kind' => 'api',
                // One source per tab, in tab order — the client fetches as the shopper switches.
                'tabs' => array_map(
                    fn (array $block) => $this->products($block['settings'] ?? []),
                    array_values(array_filter($blocks, fn (array $block) => ($block['type'] ?? null) === 'tab')),
                ),
            ],

            'category_grid' => $this->api('/api/v1/categories'),
            'brand_slider' => $this->api('/api/v1/brands'),

            // The showcases are product rails scoped to one picked category/brand — their data is
            // that scope's products, not the taxonomy list.
            'category_showcase' => $this->endpointFor(
                ContentSource::scoped('category', $settings['category_id'] ?? null, $settings['limit'] ?? null),
            ),
            'brand_showcase' => $this->endpointFor(
                ContentSource::scoped('brand', $settings['brand_id'] ?? null, $settings['limit'] ?? null),
            ),

            // A bundle is exactly these products in exactly this order — and no more of them than
            // the storefront's own bundle draws.
            'bundle' => $this->endpointFor(
                ContentSource::picked($settings['product_ids'] ?? null, SectionDataResolver::BUNDLE_LIMIT),
            ),
            'vendor_slider', 'vendor_showcase' => $this->api('/api/v1/seller/list/all'),

            'flash_deal' => $this->api('/api/v1/flash-deals', [], 'Then /api/v1/flash-deals/products/{deal_id} for the products.'),
            'deal_of_the_day' => $this->api('/api/v1/dealsoftheday/deal-of-the-day'),
            'featured_deal' => $this->api('/api/v1/deals/featured'),
            'clearance_sale' => $this->api('/api/v1/products/clearance-sale'),

            'coupon_strip' => $this->api('/api/v1/coupon/list', [],
                'Requires an authenticated customer; render the strip from `cards` for guests.'),

            'recently_viewed' => ['kind' => 'none',
                'note' => 'Backed by the web visitor cookie; no app equivalent yet. Hide this section in the app.'],
            'blog_posts' => ['kind' => 'none',
                'note' => 'The Blog module exposes no public API. Hide this section in the app.'],
            'trending_searches' => ['kind' => 'none',
                'note' => 'Search-term aggregation has no public endpoint. Hide this section in the app.'],

            // Everything else renders entirely from its own payload.
            default => ['kind' => 'inline'],
        };
    }

    /**
     * The catalogue endpoint behind one product source, exactly as the storefront resolves it.
     *
     * Every endpoint named here was checked against routes/rest_api/v1/api.php — a source hint
     * that points at a route which does not exist is worse than no hint at all.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function products(array $settings): array
    {
        return $this->endpointFor(ContentSource::fromSettings($settings));
    }

    /**
     * The catalogue endpoint one source resolves to.
     *
     * Takes the source object rather than a settings bag, so the mapping from "what the merchant
     * chose" to "which route serves it" is the only thing this method knows — the reading of the
     * choice itself happens once, in {@see ContentSource}.
     *
     * @return array<string, mixed>
     */
    public function endpointFor(ContentSource $source): array
    {
        $paged = ['limit' => $source->limit, 'offset' => 1];

        return match ($source->kind) {
            'best_selling' => $this->api('/api/v1/products/best-sellings', $paged),
            'new_arrival'  => $this->api('/api/v1/products/new-arrival', $paged),
            'top_rated'    => $this->api('/api/v1/products/top-rated', $paged),

            // The picked category or brand — the builder's picker follows the source dropdown, so
            // one key serves both.
            'category' => $this->api('/api/v1/categories/products/' . (int) $source->id, $paged),
            'brand'    => $this->api('/api/v1/brands/products/' . (int) $source->id, $paged),

            'manual' => $this->api('/api/v1/products/by-ids', ['ids' => implode(',', $source->ids)]),

            // A dynamic collection (Phase 3.1). The endpoint answers in the same product-list
            // dialect as every route above, which is what lets every installed build render a
            // collection the day it is composed — the hint is new, the contract is not.
            'collection' => $this->api('/api/v1/products/theme-collection', $paged + ['id' => (int) $source->id]),

            default => $this->api('/api/v1/products/featured', $paged),
        };
    }

    /**
     * The merchant's hand-picked ids, in their order — the same normalization the storefront's
     * resolver applies, so the app and the web can never disagree about what "these products" means.
     *
     * @return array<int, int>
     */
    public function pickedIds(string|array|null $picked): array
    {
        return ContentSource::pickedIds($picked);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function api(string $endpoint, array $params = [], ?string $note = null): array
    {
        $source = ['kind' => 'api', 'endpoint' => $endpoint, 'params' => $params];

        if ($note !== null) {
            $source['note'] = $note;
        }

        return $source;
    }
}
