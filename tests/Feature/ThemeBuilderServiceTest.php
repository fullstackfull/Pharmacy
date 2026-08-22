<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\ThemeBuilderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ThemeBuilderServiceTest extends TestCase
{
    private ThemeBuilderService $builder;
    private ThemeVersion $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ThemeBuilderService(new SectionRegistry(), app(\App\Services\Theme\SectionReadiness::class));

        foreach (['theme_blocks', 'theme_sections', 'theme_versions', 'themes'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('themes', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('slug', 120)->unique();
            $t->string('description', 500)->nullable(); $t->string('preview_image', 2048)->nullable();
            $t->boolean('is_active')->default(false); $t->boolean('is_system')->default(false);
            $t->string('created_by_type', 40)->nullable(); $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });
        Schema::create('theme_versions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('theme_id'); $t->string('label')->nullable();
            $t->string('status', 20)->default('draft'); $t->json('settings')->nullable();
            $t->unsignedBigInteger('based_on_version_id')->nullable(); $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
        Schema::create('theme_sections', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('theme_version_id'); $t->string('page', 60)->default('home');
            $t->string('type', 80); $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_visible')->default(true); $t->json('settings')->nullable(); $t->timestamps();
        });
        Schema::create('theme_blocks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('theme_section_id'); $t->string('type', 80);
            $t->unsignedInteger('sort_order')->default(0); $t->boolean('is_visible')->default(true);
            $t->json('settings')->nullable(); $t->timestamps();
        });

        $theme = Theme::create(['name' => 'T', 'slug' => 't', 'is_active' => true]);
        $this->draft = ThemeVersion::create(['theme_id' => $theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);
    }

    public function test_adds_sections_in_order(): void
    {
        $a = $this->builder->addSection($this->draft, 'home', 'hero_banner');
        $b = $this->builder->addSection($this->draft, 'home', 'product_slider');

        $this->assertSame(1, $a->sort_order);
        $this->assertSame(2, $b->sort_order);
    }

    public function test_rejects_unknown_section_type(): void
    {
        $this->assertNull($this->builder->addSection($this->draft, 'home', 'not_a_real_section'));
    }

    public function test_published_version_cannot_be_edited(): void
    {
        $published = ThemeVersion::create(['theme_id' => $this->draft->theme_id, 'status' => ThemeVersion::STATUS_PUBLISHED]);

        $this->assertFalse($this->builder->isEditable($published));
        $this->assertNull($this->builder->addSection($published, 'home', 'hero_banner'));
    }

    public function test_settings_are_normalized_and_unknown_keys_dropped(): void
    {
        $section = $this->builder->addSection($this->draft, 'home', 'product_slider', [
            'title' => 'Top picks', 'limit' => '8', 'source' => 'best_selling',
            'evil_key' => 'should not persist', 'arrows' => 'true',
        ]);

        $this->assertSame('Top picks', $section->settings['title']);
        $this->assertSame(8, $section->settings['limit']);          // coerced to number
        $this->assertTrue($section->settings['arrows']);            // coerced to boolean
        $this->assertArrayNotHasKey('evil_key', $section->settings); // dropped
        $this->assertArrayHasKey('padding_top', $section->settings); // common defaults applied
    }

    public function test_invalid_select_value_falls_back_to_default(): void
    {
        $section = $this->builder->addSection($this->draft, 'home', 'product_slider', ['source' => 'hacked']);
        $this->assertSame('featured', $section->settings['source']);
    }

    public function test_reorder_applies_requested_order(): void
    {
        $a = $this->builder->addSection($this->draft, 'home', 'hero_banner');
        $b = $this->builder->addSection($this->draft, 'home', 'product_slider');
        $c = $this->builder->addSection($this->draft, 'home', 'brand_slider');

        $this->builder->reorderSections($this->draft, 'home', [$c->id, $a->id, $b->id]);

        $this->assertSame(1, $c->fresh()->sort_order);
        $this->assertSame(2, $a->fresh()->sort_order);
        $this->assertSame(3, $b->fresh()->sort_order);
    }

    public function test_reorder_with_partial_list_keeps_omitted_sections(): void
    {
        $a = $this->builder->addSection($this->draft, 'home', 'hero_banner');
        $b = $this->builder->addSection($this->draft, 'home', 'product_slider');

        $this->builder->reorderSections($this->draft, 'home', [$b->id]); // 'a' omitted

        $this->assertSame(1, $b->fresh()->sort_order);
        $this->assertNotNull($a->fresh());          // not deleted
        $this->assertSame(2, $a->fresh()->sort_order);
    }

    public function test_duplicate_places_copy_after_original_with_blocks(): void
    {
        $a = $this->builder->addSection($this->draft, 'home', 'hero_banner');
        $b = $this->builder->addSection($this->draft, 'home', 'product_slider');
        ThemeBlock::create(['theme_section_id' => $a->id, 'type' => 'slide', 'sort_order' => 1, 'is_visible' => true, 'settings' => ['x' => 1]]);

        $copy = $this->builder->duplicateSection($a);

        $this->assertSame(2, $copy->sort_order);      // right after the original
        $this->assertSame(3, $b->fresh()->sort_order); // pushed down
        $this->assertCount(1, $copy->blocks);
        $this->assertSame('slide', $copy->blocks->first()->type);
    }

    public function test_delete_closes_the_ordering_gap(): void
    {
        $a = $this->builder->addSection($this->draft, 'home', 'hero_banner');
        $b = $this->builder->addSection($this->draft, 'home', 'product_slider');
        $c = $this->builder->addSection($this->draft, 'home', 'brand_slider');

        $this->builder->deleteSection($b);

        $this->assertSame(1, $a->fresh()->sort_order);
        $this->assertSame(2, $c->fresh()->sort_order); // gap closed
        $this->assertNull(ThemeSection::find($b->id));
    }

    public function test_visibility_toggle(): void
    {
        $a = $this->builder->addSection($this->draft, 'home', 'hero_banner');

        $this->builder->setSectionVisibility($a, false);
        $this->assertFalse($a->fresh()->is_visible);
    }

    public function test_page_structure_is_ordered_and_includes_blocks(): void
    {
        $a = $this->builder->addSection($this->draft, 'home', 'hero_banner');
        $this->builder->addSection($this->draft, 'home', 'product_slider');
        ThemeBlock::create(['theme_section_id' => $a->id, 'type' => 'slide', 'sort_order' => 1, 'is_visible' => true]);

        $structure = $this->builder->getPageStructure($this->draft, 'home');

        $this->assertCount(2, $structure);
        $this->assertSame('hero_banner', $structure[0]['type']);
        $this->assertCount(1, $structure[0]['blocks']);
        $this->assertSame('product_slider', $structure[1]['type']);
    }

    public function test_registry_filters_section_types_per_page(): void
    {
        $registry = new SectionRegistry();

        $this->assertArrayHasKey('hero_banner', $registry->forPage('home'));
        $this->assertArrayNotHasKey('hero_banner', $registry->forPage('footer'));
        $this->assertArrayHasKey('footer_columns', $registry->forPage('footer'));
    }
}
