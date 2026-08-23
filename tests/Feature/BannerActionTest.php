<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Services\Theme\ActionResolver;
use App\Services\Theme\SectionDataResolver;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesCatalogueSchema;
use Tests\TestCase;

/**
 * What tapping a banner opens, on the phone.
 *
 * The admin's banner form does not store "category 44". It stores the STOREFRONT URL that shows
 * category 44 — `/products?category_id=44&data_from=category` — because that is what the web
 * navigates by. Read literally, that path is the catalogue, so a banner a merchant pointed at one
 * category opened the entire product list in the app while the web opened the category. Same
 * banner, same publish, two different destinations.
 *
 * These tests pin the reading: a catalogue filtered to exactly one category or brand IS that
 * category or brand, and the action carries what the app's screen actually needs — the id it
 * opens on and the name it puts in its title bar, whichever half the link happened to spell.
 */
class BannerActionTest extends TestCase
{
    use CreatesCatalogueSchema;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->createCatalogueSchema();
        Banner::query()->delete();
        config(['app.url' => 'https://shop.test']);
    }

    public function test_a_banner_pointing_at_a_category_opens_that_category_not_the_catalogue(): void
    {
        $category = Category::create(['name' => 'Vitamins', 'slug' => 'vitamins', 'position' => 0]);

        $action = app(ActionResolver::class)->resolve(
            "https://shop.test/products?category_id={$category->id}&data_from=category&page=1"
        );

        $this->assertSame('category', $action['type'], 'this is the link the banner form stores');
        $this->assertSame($category->id, $action['id']);
        $this->assertSame('Vitamins', $action['label'], 'the screen needs a title, and the link has no slug');
        $this->assertSame('vitamins', $action['slug']);
    }

    public function test_a_banner_pointing_at_a_brand_opens_that_brand(): void
    {
        $brand = Brand::create(['name' => 'Panadol', 'slug' => 'panadol', 'status' => 1]);

        $action = app(ActionResolver::class)->resolve(
            "https://shop.test/products?brand_id={$brand->id}&data_from=brand&page=1"
        );

        $this->assertSame('brand', $action['type']);
        $this->assertSame($brand->id, $action['id']);
        $this->assertSame('Panadol', $action['label']);
    }

    public function test_a_slug_link_carries_the_id_the_app_screen_opens_with(): void
    {
        $category = Category::create(['name' => 'Baby Care', 'slug' => 'baby-care', 'position' => 0]);

        $action = app(ActionResolver::class)->resolve('/category/baby-care');

        $this->assertSame('category', $action['type']);
        $this->assertSame($category->id, $action['id'], 'a slug-only action would open an empty list');
        $this->assertSame('Baby Care', $action['label']);
    }

    public function test_an_unknown_subject_still_produces_a_usable_action(): void
    {
        $action = app(ActionResolver::class)->resolve('/category/deleted-category');

        $this->assertSame('category', $action['type']);
        $this->assertSame('deleted-category', $action['slug']);
        $this->assertArrayNotHasKey('id', $action, 'nothing invented for a category that is gone');
        $this->assertArrayNotHasKey('label', $action);
    }

    public function test_the_unfiltered_catalogue_is_still_a_collection(): void
    {
        $action = app(ActionResolver::class)->resolve('/products?page=2');

        $this->assertSame('collection', $action['type']);
        $this->assertSame('all', $action['collection']);
    }

    public function test_the_banner_list_hands_the_app_the_destination_it_resolved(): void
    {
        $category = Category::create(['name' => 'Vitamins', 'slug' => 'vitamins', 'position' => 0]);

        Banner::create([
            'banner_type' => 'Main Banner',
            'theme' => 'default',
            'published' => 1,
            'resource_type' => 'category',
            'resource_id' => $category->id,
            'url' => "https://shop.test/products?category_id={$category->id}&data_from=category&page=1",
            'photo' => 'hero.webp',
        ]);

        $response = $this->getJson('/api/v1/banners');

        $response->assertOk();
        $banner = $response->json()[0];

        $this->assertSame('category', $banner['action']['type']);
        $this->assertSame($category->id, $banner['action']['id']);
        // The url stays exactly as it was: the web still navigates by it.
        $this->assertStringContainsString('category_id=', $banner['url']);
        $this->assertArrayHasKey('mobile_photo_full_url', $banner);
    }

    public function test_a_dashboard_banner_section_carries_the_merchants_phone_image(): void
    {
        Banner::create([
            'banner_type' => 'Main Banner',
            'theme' => 'default',
            'published' => 1,
            'resource_type' => 'custom',
            'url' => 'https://shop.test/products',
            'photo' => 'wide.webp',
            'mobile_photo' => 'tall.webp',
        ]);

        $cards = app(SectionDataResolver::class)->dashboardBanners('Main Banner', 6);

        $this->assertCount(1, $cards);
        // A string, like `image` beside it — the renderers echo both straight into src, and a
        // storage descriptor array there fatals the page. Which string depends on whether the
        // file exists; with no file on disk both resolve to the same placeholder, so the
        // distinction that matters here is present-vs-null, pinned by the test below.
        $this->assertIsString($cards[0]['image_mobile'], 'the phone image must reach the phone');
        $this->assertNotSame('', $cards[0]['image_mobile']);
    }

    public function test_a_banner_without_a_phone_image_says_so_rather_than_repeating_the_wide_one(): void
    {
        Banner::create([
            'banner_type' => 'Main Banner',
            'theme' => 'default',
            'published' => 1,
            'resource_type' => 'custom',
            'url' => 'https://shop.test/products',
            'photo' => 'wide.webp',
        ]);

        $cards = app(SectionDataResolver::class)->dashboardBanners('Main Banner', 6);

        // Null, not a copy: the client's own fallback decides, and a copied url would hide from
        // the merchant that no mobile image was ever uploaded.
        $this->assertNull($cards[0]['image_mobile']);
    }
}
