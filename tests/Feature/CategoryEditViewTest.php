<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\Product\CategoryController;
use App\Models\BusinessSetting;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesCatalogueSchema;
use Tests\TestCase;

/**
 * The category edit screen must be handed everything it renders.
 *
 * A sub-category's form offers the main category it sits under, and the view has always looped
 * over `$parentCategories` to build that select — but the controller never passed it, so opening
 * ANY sub-category for editing died with "Undefined variable $parentCategories" and the admin saw
 * a 500. These assert the view data the controller returns, which is the contract that broke, and
 * that the blade still needs it.
 */
class CategoryEditViewTest extends TestCase
{
    use CreatesCatalogueSchema;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->createCatalogueSchema();

        BusinessSetting::updateOrCreate(
            ['type' => 'pnc_language'],
            ['value' => json_encode(['en'])],
        );
    }

    private function editViewData(int $categoryId): array
    {
        $view = app(CategoryController::class)->getUpdateView(new Request(['id' => $categoryId]));

        return $view->getData();
    }

    public function test_the_edit_view_of_a_sub_category_carries_the_main_categories(): void
    {
        $parent = Category::create(['name' => 'Skincare', 'slug' => 'skincare', 'position' => 0]);
        $sub = Category::create(['name' => 'Serums', 'slug' => 'serums', 'position' => 1, 'parent_id' => $parent->id]);

        $data = $this->editViewData($sub->id);

        $this->assertArrayHasKey('parentCategories', $data);
        $this->assertSame(['Skincare'], collect($data['parentCategories'])->pluck('name')->all());
    }

    public function test_the_edit_view_of_a_sub_sub_category_also_carries_them(): void
    {
        $parent = Category::create(['name' => 'Skincare', 'slug' => 'skincare', 'position' => 0]);
        $sub = Category::create(['name' => 'Serums', 'slug' => 'serums', 'position' => 1, 'parent_id' => $parent->id]);
        $subSub = Category::create(['name' => 'Vitamin C', 'slug' => 'vitamin-c', 'position' => 2, 'parent_id' => $sub->id]);

        $this->assertNotEmpty($this->editViewData($subSub->id)['parentCategories']);
    }

    public function test_a_main_category_needs_no_parent_list(): void
    {
        $category = Category::create(['name' => 'Skincare', 'slug' => 'skincare', 'position' => 0]);

        $data = $this->editViewData($category->id);

        $this->assertArrayHasKey('parentCategories', $data);
        $this->assertCount(0, $data['parentCategories']);
    }

    public function test_the_form_still_renders_that_select_so_the_data_is_required(): void
    {
        $source = file_get_contents(resource_path('views/admin-views/category/category-edit.blade.php'));

        $this->assertStringContainsString('$parentCategories', $source);
        $this->assertStringContainsString('name="parent_id"', $source);
    }
}
