<?php

namespace App\Services\Theme;

use App\Models\Banner;
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
            $base = fn () => Banner::where('published', 1)->where('banner_type', $bannerType);

            // Priority first so the merchant's ordering carries into the themed section too.
            $ordered = fn ($query) => $query->orderBy('priority')->orderByDesc('id');

            $scoped = $ordered((clone $base())->where('theme', theme_root_path()))
                ->take($this->bounded($limit, 24))->get();

            return $scoped->isNotEmpty()
                ? $scoped
                : $ordered($base())->take($this->bounded($limit, 24))->get();
        });

        return $rows->map(fn (Banner $banner) => [
            'image'       => $banner->photo_full_url,
            'title'       => $banner->title,
            'subtitle'    => $banner->sub_title,
            'link'        => $banner->url,
            'button_text' => $banner->button_text,
            'background'  => $banner->background_color,
            'badge'       => null,
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

        foreach ($blocks as $block) {
            $settings = $block['settings'] ?? [];
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
