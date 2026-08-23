<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Services\BannerService;
use App\Services\BrandService;
use App\Services\CategoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesCatalogueSchema;
use Tests\TestCase;

/**
 * The second, phone-shaped image — the one the apps draw.
 *
 * It began as a banner-only field and is now offered wherever a picture reaches a phone: banners,
 * categories and brands. Three rules have to hold identically in all three, because a merchant who
 * learns the field on one screen will expect it on the next:
 *
 *   1. Empty means "use the web image" — never a blank tile.
 *   2. Uploading replaces; the file it replaced is deleted.
 *   3. It can be taken away again. An upload field can only ever replace, so removal is an
 *      explicit checkbox — without it, a mobile image once uploaded was permanent.
 */
class MobileImageTest extends TestCase
{
    use CreatesCatalogueSchema;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->createCatalogueSchema();
    }

    /** A request the way a form submits one: no files, just fields. */
    private function formRequest(array $fields = []): Request
    {
        return Request::create('/', 'POST', $fields);
    }

    public function test_a_category_without_a_phone_icon_falls_back_to_the_web_one(): void
    {
        $category = Category::create(['name' => 'Vitamins', 'slug' => 'vitamins', 'icon' => 'wide.webp']);

        $this->assertSame(
            $category->icon_full_url,
            $category->fresh()->mobile_icon_full_url,
            'a category with no mobile icon must still have a picture'
        );
    }

    public function test_a_brand_without_a_phone_logo_falls_back_to_the_web_one(): void
    {
        $brand = Brand::create(['name' => 'Panadol', 'slug' => 'panadol', 'image' => 'wide.webp', 'status' => 1]);

        $this->assertSame($brand->image_full_url, $brand->fresh()->mobile_image_full_url);
    }

    public function test_a_banner_without_a_phone_image_falls_back_to_the_web_one(): void
    {
        $banner = Banner::create([
            'banner_type' => 'Main Banner', 'theme' => 'default', 'published' => 1,
            'resource_type' => 'custom', 'photo' => 'wide.webp',
        ]);

        $this->assertSame($banner->photo_full_url, $banner->fresh()->mobile_photo_full_url);
    }

    public function test_the_phone_image_is_kept_when_a_form_sends_no_file(): void
    {
        // The common case, and the one that must never surprise: editing a title cannot silently
        // drop an image the merchant uploaded weeks ago.
        $banner = Banner::create([
            'banner_type' => 'Main Banner', 'theme' => 'default', 'published' => 1,
            'resource_type' => 'custom', 'photo' => 'wide.webp', 'mobile_photo' => 'tall.webp',
        ]);

        $data = app(BannerService::class)->getProcessedData(
            request: $this->formRequest(['banner_type' => 'Main Banner', 'resource_type' => 'custom']),
            bannerUrl: 'https://shop.test/products',
            image: $banner->photo,
            mobileImage: $banner->mobile_photo,
        );

        $this->assertSame('tall.webp', $data['mobile_photo']);
    }

    public function test_the_phone_image_can_be_taken_away_again(): void
    {
        $banner = Banner::create([
            'banner_type' => 'Main Banner', 'theme' => 'default', 'published' => 1,
            'resource_type' => 'custom', 'photo' => 'wide.webp', 'mobile_photo' => 'tall.webp',
        ]);

        $data = app(BannerService::class)->getProcessedData(
            request: $this->formRequest([
                'banner_type' => 'Main Banner',
                'resource_type' => 'custom',
                'remove_mobile_image' => '1',
            ]),
            bannerUrl: 'https://shop.test/products',
            image: $banner->photo,
            mobileImage: $banner->mobile_photo,
        );

        $this->assertNull($data['mobile_photo'], 'the checkbox is the only way back to none');
    }

    public function test_a_category_keeps_or_drops_its_phone_icon_the_same_way(): void
    {
        $category = Category::create([
            'name' => 'Vitamins', 'slug' => 'vitamins', 'icon' => 'wide.webp', 'mobile_icon' => 'tall.webp',
        ]);

        $fields = ['name' => ['Vitamins'], 'lang' => ['en'], 'priority' => 1];

        $kept = app(CategoryService::class)->getUpdateData(
            request: $this->formRequest($fields),
            data: $category,
        );
        $this->assertSame('tall.webp', $kept['mobile_icon']);

        $removed = app(CategoryService::class)->getUpdateData(
            request: $this->formRequest($fields + ['remove_mobile_image' => '1']),
            data: $category,
        );
        $this->assertNull($removed['mobile_icon']);
    }

    public function test_a_brand_keeps_or_drops_its_phone_logo_the_same_way(): void
    {
        $brand = Brand::create([
            'name' => 'Panadol', 'slug' => 'panadol', 'image' => 'wide.webp',
            'mobile_image' => 'tall.webp', 'status' => 1,
        ]);

        $fields = ['name' => ['Panadol'], 'lang' => ['en']];

        $kept = app(BrandService::class)->getUpdateData(
            request: $this->formRequest($fields),
            data: $brand,
        );
        $this->assertSame('tall.webp', $kept['mobile_image']);

        $removed = app(BrandService::class)->getUpdateData(
            request: $this->formRequest($fields + ['remove_mobile_image' => '1']),
            data: $brand,
        );
        $this->assertNull($removed['mobile_image']);
    }

    public function test_every_serialized_category_and_brand_carries_its_phone_image(): void
    {
        // Appended, not hand-added to one controller: the catalogue endpoints return these models
        // directly, so appending is what makes the key present on every one of them at once.
        $category = Category::create([
            'name' => 'Vitamins', 'slug' => 'vitamins', 'icon' => 'wide.webp', 'position' => 0,
        ]);
        $brand = Brand::create([
            'name' => 'Panadol', 'slug' => 'panadol', 'image' => 'wide.webp', 'status' => 1,
        ]);

        $this->assertArrayHasKey('mobile_icon_full_url', $category->fresh()->toArray());
        $this->assertArrayHasKey('mobile_image_full_url', $brand->fresh()->toArray());
    }
}
