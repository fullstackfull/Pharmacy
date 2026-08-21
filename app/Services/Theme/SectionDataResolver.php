<?php

namespace App\Services\Theme;

use App\Models\Banner;
use App\Services\BannerService;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Supplies the data a themed home section renders.
 *
 * Why a service and not queries inside the blade: the storefront went down once because a section
 * blade filtered `categories.status`, a column this schema does not have. A view that queries is a
 * view that can 500 the shop. Every read here is wrapped, returns an empty collection on failure,
 * and the caller renders nothing instead of throwing.
 *
 * It also normalizes the two sources of "a banner" — theme-builder blocks and rows created in
 * Promotion -> Banners — into ONE card shape, so a merchant can add a banner in either place and
 * the same section renderers display it.
 */
class SectionDataResolver
{
    /** Top-level categories for the category grid. */
    public function categories(int $limit): Collection
    {
        return $this->safely(fn () => Category::where('position', 0)
            ->orderBy('priority')
            ->take($this->bounded($limit, 24))
            ->get(['id', 'name', 'slug', 'icon']));
    }

    /** Products for a product slider, per the section's `source` setting. */
    public function products(array $settings): Collection
    {
        $limit = $this->bounded((int) ($settings['limit'] ?? 8), 24);
        $source = (string) ($settings['source'] ?? 'featured');
        $reference = (int) ($settings['source_id'] ?? 0);

        return $this->safely(function () use ($limit, $source, $reference) {
            $query = Product::active();

            $query = match ($source) {
                'best_selling' => $query->withCount('orderDetails')->orderByDesc('order_details_count'),
                'new_arrival'  => $query->latest('id'),
                'top_rated'    => $query->withAvg('reviews', 'rating')->orderByDesc('reviews_avg_rating'),
                'category'     => $query->where('category_id', $reference)->latest('id'),
                'brand'        => $query->where('brand_id', $reference)->latest('id'),
                default        => $query->where('featured', 1)->latest('id'),
            };

            return $query->take($limit)->get();
        });
    }

    public function brands(int $limit): Collection
    {
        return $this->safely(fn () => Brand::where('status', 1)
            ->orderBy('name')
            ->take($this->bounded($limit, 24))
            ->get(['id', 'name', 'slug', 'image']));
    }

    /**
     * Banners created in the dashboard (Promotion -> Banners), as render-ready cards.
     *
     * Matches the storefront's own filter — published, and belonging to the active folder theme —
     * so what the merchant sees listed there is what the theme shows. When nothing matches the
     * active theme (banners added before a theme switch) the theme filter is dropped rather than
     * rendering an empty section, since an orphaned banner is still a banner the merchant created.
     */
    public function dashboardBanners(string $bannerType, int $limit): array
    {
        $rows = $this->safely(function () use ($bannerType, $limit) {
            $base = fn () => Banner::with(['storage'])
                ->where('published', 1)
                ->where('banner_type', $bannerType)
                // Placement types find their page through their resource; one pointing
                // elsewhere renders an image that leads nowhere.
                ->when(
                    BannerService::REQUIRED_RESOURCE_TYPES[$bannerType] ?? null,
                    fn ($query, $resourceType) => $query->where('resource_type', $resourceType),
                );

            // Same ordering as BannerPlacementService — priority first, then creation
            // order — so a section shows the banners in the arrangement the merchant
            // sees in their built-in slot.
            $ordered = fn ($query) => $query->orderBy('priority')->orderBy('id');

            $scoped = $ordered((clone $base())->where('theme', theme_root_path()))
                ->take($this->bounded($limit, 24))->get();

            return $scoped->isNotEmpty()
                ? $scoped
                : $ordered($base())->take($this->bounded($limit, 24))->get();
        });

        return $rows->map(fn (Banner $banner) => [
            // photo_full_url is the storage descriptor array, not a url. The section
            // partials echo `image` straight into src, so resolve it here — echoing the
            // array fatals the whole home page the moment a theme with a banner section
            // is published.
            'image'       => getStorageImages(path: $banner->photo_full_url, type: 'banner'),
            'title'       => $banner->title,
            'subtitle'    => $banner->sub_title,
            'link'        => $banner->url,
            'button_text' => $banner->button_text,
            'background'  => $banner->background_color,
            'badge'       => null,
            // Keys the slide-shaped renderers read; a dashboard banner has no per-slide styling,
            // so these carry the renderer defaults instead of being absent and fataling the view.
            'eyebrow'     => null,
            'text_color'  => null,
            'align'       => 'start',
            'overlay'     => null,
            // A grid banner already carries how wide it wants to sit; a mosaic honours it so the
            // arrangement is the same whether the banners render in their built-in slot or here.
            'span'        => ($banner->layout ?? 'full') === 'full' ? 'wide' : 'small',
        ])->all();
    }

    /**
     * Theme-builder blocks as the same card shape as dashboardBanners(), so both feed the same
     * renderers and a section can be switched between sources without touching the markup.
     */
    public function blockCards(array $blocks): array
    {
        $cards = [];

        // Banner-backed blocks render their linked Promotion -> Banners row LIVE: the banner's
        // image/link/text win over the block's own copies, so an edit in Banner Setup shows on the
        // storefront without touching the theme. An unpublished linked banner hides its card, the
        // same way unpublishing hides a banner from its built-in slot.
        $overrides = app(ThemeBannerLink::class)->cardOverrides(
            array_map(fn ($block) => (int) (($block['settings']['banner_id'] ?? 0)), $blocks)
        );

        foreach ($blocks as $block) {
            $settings = $block['settings'] ?? [];

            $linkedId = (int) ($settings['banner_id'] ?? 0);
            $linked = $overrides[$linkedId] ?? null;
            if ($linkedId > 0 && ($linked === null || !$linked['published'])) {
                // Deleted or unpublished in Banner Setup -> gone from the theme too.
                continue;
            }
            if ($linked !== null) {
                foreach (['image', 'image_mobile', 'link', 'title', 'subtitle', 'button_text', 'background'] as $key) {
                    if (!empty($linked[$key])) {
                        $settings[$key] = $linked[$key];
                    }
                }
            }

            $cards[] = [
                'image'        => $settings['image'] ?? null,
                'image_mobile' => $settings['image_mobile'] ?? null,
                'eyebrow'      => $settings['eyebrow'] ?? null,
                'title'        => $settings['title'] ?? null,
                'subtitle'     => $settings['subtitle'] ?? ($settings['body'] ?? null),
                'link'         => $settings['link'] ?? null,
                'button_text'  => $settings['button_text'] ?? null,
                'badge'        => $settings['badge'] ?? null,
                'span'         => $settings['span'] ?? 'small',
                'align'        => $settings['align'] ?? 'start',
                'media_side'   => $settings['media_side'] ?? 'start',
                'text_color'   => $settings['text_color'] ?? null,
                'background'   => $settings['background'] ?? null,
                'overlay'      => $settings['overlay'] ?? null,
                'icon'         => $settings['icon'] ?? null,
            ];
        }

        return $cards;
    }

    /** Clamp a merchant-supplied count so a stray value cannot fetch the whole catalogue. */
    private function bounded(int $value, int $max): int
    {
        return max(1, min($value, $max));
    }

    /** Run a read; a broken query returns nothing rather than taking the storefront down. */
    private function safely(callable $query): Collection
    {
        try {
            $result = $query();
        } catch (\Throwable $exception) {
            report($exception);
            return collect();
        }

        return $result instanceof Collection ? $result : collect($result);
    }
}
