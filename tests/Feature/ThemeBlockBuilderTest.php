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

/**
 * Blocks are the repeatable children of a section — the hero slides and banner tiles a merchant
 * actually edits. They carry the same guarantees as sections: draft-only, schema-normalized, and a
 * block type must be one the parent section declares.
 */
class ThemeBlockBuilderTest extends TestCase
{
    private ThemeBuilderService $builder;
    private SectionRegistry $registry;
    private ThemeVersion $draft;
    private ThemeVersion $published;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['theme_blocks', 'theme_sections', 'theme_versions', 'themes'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('themes', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('slug', 120)->unique();
            $t->string('description', 500)->nullable(); $t->string('preview_image', 2048)->nullable();
            $t->boolean('is_active')->default(false); $t->boolean('is_system')->default(false);
            $t->string('created_by_type', 40)->nullable(); $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });
        Schema::create('theme_versions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('theme_id'); $t->string('label', 120)->nullable();
            $t->string('status', 20)->default('draft'); $t->json('settings')->nullable();
            $t->timestamp('published_at')->nullable(); $t->timestamps();
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

        $this->registry = new SectionRegistry();
        $this->builder = new ThemeBuilderService($this->registry);

        $theme = Theme::create(['name' => 'T', 'slug' => 't', 'is_active' => true]);
        $this->draft = ThemeVersion::create(['theme_id' => $theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);
        $this->published = ThemeVersion::create(['theme_id' => $theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED]);
    }

    private function section(ThemeVersion $version, string $type = 'hero_banner'): ThemeSection
    {
        return ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => $type,
            'sort_order' => 1, 'is_visible' => true, 'settings' => [],
        ]);
    }

    public function test_a_slide_can_be_added_to_a_hero_on_a_draft(): void
    {
        $block = $this->builder->addBlock($this->section($this->draft), 'slide', ['title' => 'Radiance']);

        $this->assertNotNull($block);
        $this->assertSame('slide', $block->type);
        $this->assertSame('Radiance', $block->settings['title']);
        $this->assertSame(1, $block->sort_order);
    }

    public function test_a_published_version_refuses_new_blocks(): void
    {
        $this->assertNull($this->builder->addBlock($this->section($this->published), 'slide'));
    }

    public function test_a_section_refuses_a_block_type_it_does_not_declare(): void
    {
        // A footer menu column has no business inside a hero carousel.
        $this->assertNull($this->builder->addBlock($this->section($this->draft), 'menu'));
        $this->assertNull($this->builder->addBlock($this->section($this->draft), 'not_a_type'));
    }

    public function test_block_settings_are_normalized_against_the_schema(): void
    {
        $block = $this->builder->addBlock($this->section($this->draft), 'slide', [
            'title'   => 'Glow',
            'overlay' => '45',
            'align'   => 'nowhere',        // not an allowed option -> falls back to the default
            'evil'    => '<script>x</script>',
        ]);

        $this->assertArrayNotHasKey('evil', $block->settings);
        $this->assertSame(45, $block->settings['overlay']);
        $this->assertSame('start', $block->settings['align']);
    }

    public function test_blocks_can_be_reordered_and_missing_ids_keep_a_stable_tail(): void
    {
        $section = $this->section($this->draft);
        $first = $this->builder->addBlock($section, 'slide', ['title' => 'A']);
        $second = $this->builder->addBlock($section, 'slide', ['title' => 'B']);
        $third = $this->builder->addBlock($section, 'slide', ['title' => 'C']);

        $this->assertTrue($this->builder->reorderBlocks($section, [$third->id, $first->id]));

        $this->assertSame(1, $third->fresh()->sort_order);
        $this->assertSame(2, $first->fresh()->sort_order);
        $this->assertSame(3, $second->fresh()->sort_order);   // omitted, appended after the listed ones
    }

    public function test_deleting_a_block_closes_the_gap(): void
    {
        $section = $this->section($this->draft);
        $first = $this->builder->addBlock($section, 'slide');
        $second = $this->builder->addBlock($section, 'slide');
        $third = $this->builder->addBlock($section, 'slide');

        $this->assertTrue($this->builder->deleteBlock($second));

        $this->assertSame(1, $first->fresh()->sort_order);
        $this->assertSame(2, $third->fresh()->sort_order);
        $this->assertNull(ThemeBlock::find($second->id));
    }

    public function test_duplicating_a_section_carries_its_blocks(): void
    {
        $section = $this->section($this->draft);
        $this->builder->addBlock($section, 'slide', ['title' => 'A']);
        $this->builder->addBlock($section, 'slide', ['title' => 'B']);

        $copy = $this->builder->duplicateSection($section->fresh());

        $this->assertNotNull($copy);
        $this->assertCount(2, $copy->blocks);
    }

    public function test_the_block_cap_is_enforced(): void
    {
        $section = $this->section($this->draft);
        for ($i = 0; $i < SectionRegistry::MAX_BLOCKS_PER_SECTION; $i++) {
            $this->assertNotNull($this->builder->addBlock($section, 'slide'));
        }

        $this->assertNull($this->builder->addBlock($section->fresh(), 'slide'));
    }

    public function test_the_new_banner_sections_declare_usable_block_types(): void
    {
        $this->assertSame(['split'], $this->registry->blockTypesFor('split_banner'));
        $this->assertSame(['mosaic_tile'], $this->registry->blockTypesFor('banner_mosaic'));
        $this->assertSame(['usp'], $this->registry->blockTypesFor('usp_strip'));
        // A strip is a single banner configured on the section itself, so it takes no blocks.
        $this->assertFalse($this->registry->allowsBlocks('banner_strip'));
        $this->assertFalse($this->registry->allowsBlocks('store_banner'));
    }

    public function test_the_store_banner_section_offers_the_dashboard_banner_types(): void
    {
        $schema = $this->registry->schemaFor('store_banner');

        $this->assertSame(SectionRegistry::STORE_BANNER_TYPES, $schema['banner_type']['options']);
        $this->assertContains('mosaic', $schema['layout']['options']);
    }

    public function test_a_block_label_falls_back_to_its_type_label_when_untitled(): void
    {
        $this->assertSame('Radiance', $this->registry->blockLabel('slide', ['title' => 'Radiance']));
        $this->assertSame('hero_slide', $this->registry->blockLabel('slide', []));
    }
}
