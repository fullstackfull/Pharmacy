<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\SubCategoryAddRequest;
use App\Http\Requests\Admin\SubCategoryUpdateRequest;
use Tests\TestCase;

/**
 * Sub-categories and sub-sub-categories carry their own icon.
 *
 * The dashboard only ever offered the upload for a top-level category — the field was wrapped in a
 * `$categoryType == 'category'` guard — so every level below it was stuck with the `def.png`
 * placeholder and the storefront's category strip fell back to letter chips for all of them.
 */
class CategoryIconTest extends TestCase
{
    private function renderedForms(): string
    {
        return implode("\n", array_map('file_get_contents', [
            resource_path('views/admin-views/category/offcanvas/_category-add.blade.php'),
            resource_path('views/admin-views/category/offcanvas/_category-edit.blade.php'),
            resource_path('views/admin-views/category/category-edit.blade.php'),
        ]));
    }

    public function test_the_icon_upload_is_no_longer_gated_on_the_category_being_top_level(): void
    {
        $forms = $this->renderedForms();

        // The three guards that used to hide the field below the top level.
        $this->assertStringNotContainsString("@if (\$categoryType == 'category')\n                        <div class=\"d-flex flex-column gap-20\">", $forms);
        $this->assertStringNotContainsString("@if (\$category->position == 0)\n                        <div class=\"d-flex flex-column gap-20\">", $forms);
        $this->assertStringNotContainsString("@if (\$category['parent_id'] == 0)\n                                <div class=\"col-lg-6 mt-4 mt-lg-0 from_part_2\">", $forms);
    }

    public function test_every_category_form_still_posts_the_icon_under_the_same_field_name(): void
    {
        // The service maps `image` -> `icon` for any category; a renamed field would save nothing.
        $this->assertSame(3, substr_count($this->renderedForms(), 'name="image" id="category-image"'));
    }

    public function test_the_icon_is_required_only_for_a_main_category(): void
    {
        $forms = $this->renderedForms();

        // Requiredness is decided by one flag per form rather than by hiding the field.
        $this->assertSame(3, substr_count($forms, '@php($iconRequired'));
        $this->assertStringContainsString('@if ($iconRequired)<span class="text-danger">*</span>@endif', $forms);
    }

    public function test_an_icon_uploaded_on_a_sub_category_is_validated_like_any_other(): void
    {
        // It used to reach the disk with no rule at all on the add path, and with a hand-written
        // mime list on the update path that rejected the .webp the uploader itself produces.
        // The shared image-rule helper mixes strings with Rule objects, so flattening describes
        // each entry by what it is rather than assuming everything casts to a string.
        $flatten = static fn (mixed $rule): string => implode('|', array_map(
            static fn (mixed $item): string => is_string($item) ? $item : get_debug_type($item),
            is_array($rule) ? $rule : [$rule],
        ));

        $addRules = (new SubCategoryAddRequest())->rules();
        $this->assertArrayHasKey('image', $addRules);
        $this->assertStringContainsString('nullable', $flatten($addRules['image']));
        $this->assertStringContainsString('image', $flatten($addRules['image']));

        $updateRules = (new SubCategoryUpdateRequest())->rules();
        $this->assertArrayHasKey('image', $updateRules);
        $this->assertStringContainsString('nullable', $flatten($updateRules['image']));
        $this->assertStringNotContainsString('mimes:jpg,jpeg,png|max:', $flatten($updateRules['image']));
    }

    public function test_the_sub_category_lists_show_the_icon_column(): void
    {
        foreach (['sub-category-view', 'sub-sub-category-view'] as $view) {
            $blade = file_get_contents(resource_path("views/admin-views/category/{$view}.blade.php"));

            $this->assertStringContainsString("translate('icon')", $blade, "{$view} has no icon column header");
            $this->assertStringContainsString('category_icon_url($category)', $blade, "{$view} does not resolve the icon");
            // A category with no artwork gets a slot, not a grey placeholder image that reads as broken.
            $this->assertStringContainsString('category-icon-placeholder', $blade);
        }
    }

    public function test_a_category_without_a_real_icon_resolves_to_null_rather_than_a_placeholder_image(): void
    {
        $this->assertNull(category_icon_url(['icon' => 'def.png', 'icon_full_url' => ['path' => 'https://cdn/def.png']]));
        $this->assertNull(category_icon_url(['icon' => null]));
        $this->assertSame(
            'https://cdn/real.webp',
            category_icon_url(['icon' => 'real.webp', 'icon_full_url' => ['path' => 'https://cdn/real.webp']]),
        );
    }
}
