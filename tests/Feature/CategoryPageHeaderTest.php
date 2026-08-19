<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Category;
use App\Services\BannerPlacementService;
use App\Services\BannerService;
use App\Services\CategoryPageService;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesCatalogueSchema;
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
    use CreatesCatalogueSchema;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->createCatalogueSchema();

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

    public function test_section_banners_are_grouped_by_the_category_they_sit_above(): void
    {
        $tree = $this->makeTree();
        $first = $this->makeBanner($tree['parent']->id, ['banner_type' => 'Category Section Banner', 'layout' => 'half', 'priority' => 2]);
        $second = $this->makeBanner($tree['parent']->id, ['banner_type' => 'Category Section Banner', 'layout' => 'half', 'priority' => 1]);
        $this->makeBanner($tree['child']->id, ['banner_type' => 'Category Section Banner', 'published' => 0]);

        $sectionBanners = app(BannerPlacementService::class)->getCategorySectionBanners();

        $this->assertCount(1, $sectionBanners);
        // both halves belong to the same row, lowest priority first
        $this->assertSame(
            [$second->id, $first->id],
            $sectionBanners->get($tree['parent']->id)->pluck('id')->all()
        );
    }

    public function test_home_promo_banners_are_not_bound_to_a_category_and_follow_priority(): void
    {
        $late = $this->makeBanner(0, ['banner_type' => 'Home Promo Banner', 'resource_type' => 'custom', 'priority' => 5]);
        $early = $this->makeBanner(0, ['banner_type' => 'Home Promo Banner', 'resource_type' => 'custom', 'priority' => 1]);
        $this->makeBanner(0, ['banner_type' => 'Home Promo Banner', 'resource_type' => 'custom', 'published' => 0]);

        $promos = app(BannerPlacementService::class)->getHomePromoBanners();

        $this->assertSame([$early->id, $late->id], $promos->pluck('id')->all());
    }

    public function test_a_home_promo_banner_is_not_served_to_the_category_placements(): void
    {
        $tree = $this->makeTree();
        $this->makeBanner($tree['parent']->id, ['banner_type' => 'Home Promo Banner']);

        $this->assertNull(app(CategoryPageService::class)->getPageHeader($tree['parent'])['banner']);
        $this->assertCount(0, app(BannerPlacementService::class)->getCategorySectionBanners());
    }

    public function test_an_unknown_layout_falls_back_to_a_full_row(): void
    {
        $data = app(BannerService::class)->getProcessedData(
            request: new \Illuminate\Http\Request(['banner_type' => 'Home Promo Banner', 'layout' => 'diagonal', 'priority' => '3']),
            bannerUrl: 'https://example.test',
            image: 'kept.webp',
        );

        $this->assertSame('full', $data['layout']);
        $this->assertSame(3, $data['priority']);
        $this->assertSame('kept.webp', $data['photo']);
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
