<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\BrandPageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesCatalogueSchema;
use Tests\TestCase;

/**
 * The brand landing header contract.
 *
 * A "Brand Banner" heads the page of the brand its brand resource names, and the
 * category strip is derived from the catalogue — only categories holding an
 * active product of that brand, so a chip can never lead to an empty list.
 */
class BrandPageHeaderTest extends TestCase
{
    use CreatesCatalogueSchema;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->createCatalogueSchema();
        // Product::active() hides every branded product while the brand feature is
        // off (upstream: a store that does not use brands should not list them), and
        // a brand page only exists when it is on.
        DB::table('business_settings')->updateOrInsert(
            ['type' => 'product_brand'],
            ['value' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        Cache::flush();

        foreach ([Banner::class, Brand::class, Category::class, Product::class] as $model) {
            if (Schema::hasTable((new $model)->getTable())) {
                $model::query()->delete();
            }
        }
    }

    private function makeCatalogue(): array
    {
        $brand = Brand::create(['name' => 'Topface', 'slug' => 'topface', 'status' => 1]);
        $otherBrand = Brand::create(['name' => 'Calla', 'slug' => 'calla', 'status' => 1]);

        $eyes = Category::create(['name' => 'Eyes', 'slug' => 'eyes', 'parent_id' => 0, 'position' => 0]);
        $face = Category::create(['name' => 'Face', 'slug' => 'face', 'parent_id' => 0, 'position' => 0]);
        $hair = Category::create(['name' => 'Hair', 'slug' => 'hair', 'parent_id' => 0, 'position' => 0]);

        $product = fn (Brand $owner, Category $category, array $overrides = []) => Product::create(array_merge([
            'name' => 'Item', 'brand_id' => $owner->id, 'category_id' => $category->id,
            'added_by' => 'admin', 'product_type' => 'physical', 'status' => 1, 'request_status' => 1,
        ], $overrides));

        $product($brand, $face);
        $product($brand, $face);
        $product($brand, $eyes);
        $product($brand, $hair, ['status' => 0]);   // unpublished: its category must not appear
        $product($otherBrand, $hair);               // another brand's category must not appear

        return compact('brand', 'otherBrand', 'eyes', 'face', 'hair');
    }

    private function makeBanner(int $brandId, array $overrides = []): Banner
    {
        return Banner::create(array_merge([
            'banner_type' => 'Brand Banner',
            'theme' => 'default',
            'published' => 1,
            'resource_type' => 'brand',
            'resource_id' => $brandId,
            'photo' => 'brand.webp',
        ], $overrides));
    }

    public function test_only_categories_holding_that_brands_active_products_are_offered(): void
    {
        $catalogue = $this->makeCatalogue();

        $categories = app(BrandPageService::class)->getPageHeader($catalogue['brand'])['categories'];

        $this->assertSame(['Face', 'Eyes'], $categories->pluck('name')->all());
    }

    public function test_each_category_reports_how_many_products_the_brand_has_in_it(): void
    {
        $catalogue = $this->makeCatalogue();

        $categories = app(BrandPageService::class)->getPageHeader($catalogue['brand'])['categories'];

        $this->assertSame(2, $categories->firstWhere('name', 'Face')->products_count);
        $this->assertSame(1, $categories->firstWhere('name', 'Eyes')->products_count);
    }

    public function test_the_brand_gets_its_own_banner_and_no_one_elses(): void
    {
        $catalogue = $this->makeCatalogue();
        $banner = $this->makeBanner($catalogue['brand']->id);

        $this->assertSame($banner->id, app(BrandPageService::class)->getPageHeader($catalogue['brand'])['banner']->id);
        $this->assertNull(app(BrandPageService::class)->getPageHeader($catalogue['otherBrand'])['banner']);
    }

    public function test_an_unpublished_brand_banner_is_never_served(): void
    {
        $catalogue = $this->makeCatalogue();
        $this->makeBanner($catalogue['brand']->id, ['published' => 0]);

        $this->assertNull(app(BrandPageService::class)->getPageHeader($catalogue['brand'])['banner']);
    }

    public function test_the_selected_category_is_read_back_from_the_request(): void
    {
        $catalogue = $this->makeCatalogue();

        $header = app(BrandPageService::class)->getPageHeader(
            $catalogue['brand'],
            ['category_ids' => [$catalogue['eyes']->id]],
        );

        $this->assertSame($catalogue['eyes']->id, $header['activeCategoryId']);
    }

    public function test_a_brand_with_no_products_yields_an_empty_strip_without_crashing(): void
    {
        $emptyBrand = Brand::create(['name' => 'Empty', 'slug' => 'empty', 'status' => 1]);

        $header = app(BrandPageService::class)->getPageHeader($emptyBrand);

        $this->assertCount(0, $header['categories']);
        $this->assertNull($header['banner']);
    }

    public function test_no_brand_yields_an_empty_header(): void
    {
        $header = app(BrandPageService::class)->getPageHeader(null);

        $this->assertNull($header['brand']);
        $this->assertCount(0, $header['categories']);
    }
}
