<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Category;
use App\Services\CategoryPageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The category landing header contract.
 *
 * A "Category Banner" names the category page it belongs to through its
 * category resource, and a page with no banner of its own inherits the nearest
 * ancestor's — one banner can therefore cover a whole branch of the tree. The
 * sub-category strip is always the open category's own direct children.
 */
class CategoryPageHeaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        if (!Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('slug')->nullable();
                $table->string('icon')->nullable();
                $table->string('icon_storage_type')->nullable();
                $table->integer('parent_id')->default(0);
                $table->integer('position')->default(0);
                $table->integer('priority')->default(0);
                $table->boolean('home_status')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('banners')) {
            Schema::create('banners', function (Blueprint $table) {
                $table->id();
                $table->string('photo')->nullable();
                $table->string('mobile_photo')->nullable();
                $table->string('banner_type');
                $table->string('theme')->default('default');
                $table->integer('published')->default(0);
                $table->string('url')->nullable();
                $table->string('resource_type')->nullable();
                $table->unsignedBigInteger('resource_id')->nullable();
                $table->string('title')->nullable();
                $table->string('sub_title')->nullable();
                $table->string('button_text')->nullable();
                $table->string('background_color')->nullable();
                $table->timestamps();
            });
        }

        // Saving a banner registers its file in `storages`; category names resolve
        // through `translations` + the settings table; the sub-category counts read
        // `products`.
        if (!Schema::hasTable('storages')) {
            Schema::create('storages', function (Blueprint $table) {
                $table->id();
                $table->string('data_type')->nullable();
                $table->unsignedBigInteger('data_id')->nullable();
                $table->string('key')->nullable();
                $table->string('value')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $table) {
                $table->id();
                $table->string('type')->nullable();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('translations')) {
            Schema::create('translations', function (Blueprint $table) {
                $table->id();
                $table->string('translationable_type')->nullable();
                $table->unsignedBigInteger('translationable_id')->nullable();
                $table->string('locale')->nullable();
                $table->string('key')->nullable();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        // Product::active() joins the seller and filters on the publication columns.
        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->unsignedBigInteger('category_id')->nullable();
                $table->unsignedBigInteger('sub_category_id')->nullable();
                $table->unsignedBigInteger('sub_sub_category_id')->nullable();
                $table->unsignedBigInteger('brand_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('added_by')->default('admin');
                $table->string('product_type')->default('physical');
                $table->integer('status')->default(1);
                $table->integer('request_status')->default(1);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('sellers')) {
            Schema::create('sellers', function (Blueprint $table) {
                $table->id();
                $table->string('status')->default('approved');
                $table->timestamps();
            });
        }

        Banner::query()->delete();
        Category::query()->delete();
    }

    private function makeTree(): array
    {
        $parent = Category::create(['name' => 'Skin care', 'slug' => 'skin-care', 'parent_id' => 0, 'position' => 0, 'priority' => 1]);
        $child = Category::create(['name' => 'Face serums', 'slug' => 'face-serums', 'parent_id' => $parent->id, 'position' => 1, 'priority' => 1]);
        $sibling = Category::create(['name' => 'Cleansers', 'slug' => 'cleansers', 'parent_id' => $parent->id, 'position' => 1, 'priority' => 2]);
        $grandChild = Category::create(['name' => 'Vitamin C', 'slug' => 'vitamin-c', 'parent_id' => $child->id, 'position' => 2, 'priority' => 1]);

        return compact('parent', 'child', 'sibling', 'grandChild');
    }

    private function makeBanner(int $categoryId, array $overrides = []): Banner
    {
        return Banner::create(array_merge([
            'banner_type' => 'Category Banner',
            'theme' => 'default',
            'published' => 1,
            'resource_type' => 'category',
            'resource_id' => $categoryId,
            'photo' => 'banner.webp',
        ], $overrides));
    }

    public function test_the_category_gets_its_own_banner(): void
    {
        $tree = $this->makeTree();
        $banner = $this->makeBanner($tree['parent']->id);

        $header = app(CategoryPageService::class)->getPageHeader($tree['parent']);

        $this->assertSame($banner->id, $header['banner']->id);
    }

    public function test_a_sub_category_inherits_the_nearest_ancestors_banner(): void
    {
        $tree = $this->makeTree();
        $parentBanner = $this->makeBanner($tree['parent']->id);

        $this->assertSame($parentBanner->id, app(CategoryPageService::class)->getPageHeader($tree['child'])['banner']->id);
        $this->assertSame($parentBanner->id, app(CategoryPageService::class)->getPageHeader($tree['grandChild'])['banner']->id);
    }

    public function test_an_own_banner_wins_over_an_inherited_one(): void
    {
        $tree = $this->makeTree();
        $this->makeBanner($tree['parent']->id);
        $childBanner = $this->makeBanner($tree['child']->id);

        $this->assertSame($childBanner->id, app(CategoryPageService::class)->getPageHeader($tree['child'])['banner']->id);
    }

    public function test_an_unpublished_banner_is_never_served(): void
    {
        $tree = $this->makeTree();
        $this->makeBanner($tree['parent']->id, ['published' => 0]);

        $this->assertNull(app(CategoryPageService::class)->getPageHeader($tree['parent'])['banner']);
    }

    public function test_other_banner_types_are_not_treated_as_category_banners(): void
    {
        $tree = $this->makeTree();
        $this->makeBanner($tree['parent']->id, ['banner_type' => 'Main Banner']);

        $this->assertNull(app(CategoryPageService::class)->getPageHeader($tree['parent'])['banner']);
    }

    public function test_sub_categories_are_the_open_categorys_own_children_in_priority_order(): void
    {
        $tree = $this->makeTree();

        $subCategories = app(CategoryPageService::class)->getPageHeader($tree['parent'])['subCategories'];

        $this->assertSame(['Face serums', 'Cleansers'], $subCategories->pluck('name')->all());
    }

    public function test_a_leaf_category_reports_no_sub_categories(): void
    {
        $tree = $this->makeTree();

        $this->assertCount(0, app(CategoryPageService::class)->getPageHeader($tree['grandChild'])['subCategories']);
    }

    public function test_a_request_without_a_category_yields_an_empty_header(): void
    {
        $service = app(CategoryPageService::class);

        $header = $service->getPageHeader($service->resolveCategory(['data_from' => 'latest']));

        $this->assertNull($header['category']);
        $this->assertNull($header['banner']);
        $this->assertCount(0, $header['subCategories']);
    }

    public function test_section_banners_are_keyed_by_the_category_they_sit_above(): void
    {
        $tree = $this->makeTree();
        $sectionBanner = $this->makeBanner($tree['parent']->id, ['banner_type' => 'Category Section Banner']);
        $this->makeBanner($tree['child']->id, ['banner_type' => 'Category Section Banner', 'published' => 0]);

        $sectionBanners = app(CategoryPageService::class)->getSectionBanners();

        $this->assertCount(1, $sectionBanners);
        $this->assertSame($sectionBanner->id, $sectionBanners->get($tree['parent']->id)->id);
    }

    public function test_a_section_banner_is_not_served_as_a_category_page_banner(): void
    {
        $tree = $this->makeTree();
        $this->makeBanner($tree['parent']->id, ['banner_type' => 'Category Section Banner']);

        $this->assertNull(app(CategoryPageService::class)->getPageHeader($tree['parent'])['banner']);
    }

    public function test_a_banner_without_a_mobile_image_reports_the_web_image_as_its_mobile_url(): void
    {
        $banner = $this->makeBanner(1, ['photo' => 'web.webp']);

        $this->assertSame($banner->photo_full_url, $banner->mobile_photo_full_url);
    }

    public function test_a_banner_with_a_mobile_image_reports_its_own(): void
    {
        $banner = $this->makeBanner(1, ['photo' => 'web.webp', 'mobile_photo' => 'phone.webp']);

        $this->assertNotSame($banner->photo_full_url, $banner->mobile_photo_full_url);
        $this->assertSame('phone.webp', $banner->mobile_photo_full_url['key']);
    }

    public function test_the_deepest_category_filter_wins_when_resolving_the_page(): void
    {
        $tree = $this->makeTree();
        $service = app(CategoryPageService::class);

        $resolved = $service->resolveCategory([
            'category_id' => $tree['parent']->id,
            'sub_category_id' => $tree['child']->id,
        ]);

        $this->assertSame($tree['child']->id, $resolved->id);
    }
}
