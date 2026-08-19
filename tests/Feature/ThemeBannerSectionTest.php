<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Services\Theme\SectionDataResolver;
use App\Services\Theme\SectionRegistry;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesCatalogueSchema;
use Tests\TestCase;

/**
 * The bridge between the dashboard's banners and the theme builder.
 *
 * A merchant can place any dashboard banner type as a theme section to control
 * where in the page order it sits. Two things must hold for that to be safe:
 * the section renderers receive an image URL (they echo it straight into src —
 * handing them the storage descriptor array fatals the whole home page), and
 * the merchant's own ordering and layout survive the trip.
 */
class ThemeBannerSectionTest extends TestCase
{
    use CreatesCatalogueSchema;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->createCatalogueSchema();
        Banner::query()->delete();
    }

    private function makeBanner(array $overrides = []): Banner
    {
        return Banner::create(array_merge([
            'banner_type' => 'Home Promo Banner',
            'theme' => 'default',
            'published' => 1,
            'resource_type' => 'custom',
            'url' => 'https://example.test',
            'photo' => 'promo.webp',
        ], $overrides));
    }

    public function test_the_section_receives_an_image_url_not_the_storage_array(): void
    {
        $this->makeBanner();

        $cards = app(SectionDataResolver::class)->dashboardBanners('Home Promo Banner', 6);

        $this->assertCount(1, $cards);
        // A string is the whole point: the partials echo this into src. Which string
        // it is depends on whether the file exists — a missing file resolves to the
        // placeholder, which is correct and still safe to echo.
        $this->assertIsString($cards[0]['image']);
        $this->assertNotSame('', $cards[0]['image']);
    }

    public function test_the_merchants_ordering_carries_into_the_themed_section(): void
    {
        $this->makeBanner(['priority' => 3, 'title' => 'third']);
        $this->makeBanner(['priority' => 1, 'title' => 'first']);
        $this->makeBanner(['priority' => 2, 'title' => 'second']);

        $cards = app(SectionDataResolver::class)->dashboardBanners('Home Promo Banner', 6);

        $this->assertSame(['first', 'second', 'third'], array_column($cards, 'title'));
    }

    public function test_a_banners_own_layout_becomes_its_span_in_the_mosaic(): void
    {
        $this->makeBanner(['layout' => 'full', 'title' => 'wide one']);
        $this->makeBanner(['layout' => 'half', 'title' => 'narrow one']);

        $cards = collect(app(SectionDataResolver::class)->dashboardBanners('Home Promo Banner', 6))
            ->keyBy('title');

        $this->assertSame('wide', $cards['wide one']['span']);
        $this->assertSame('small', $cards['narrow one']['span']);
    }

    public function test_unpublished_banners_never_reach_a_themed_section(): void
    {
        $this->makeBanner(['published' => 0]);

        $this->assertCount(0, app(SectionDataResolver::class)->dashboardBanners('Home Promo Banner', 6));
    }

    public function test_every_grid_banner_type_is_placeable_from_the_theme_editor(): void
    {
        foreach (\App\Services\BannerService::GRID_TYPES as $type) {
            $this->assertContains($type, SectionRegistry::STORE_BANNER_TYPES,
                "{$type} must be offered as a theme section so its position can be changed");
        }
    }
}
