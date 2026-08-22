<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeBuilderService;
use App\Services\Theme\ThemeManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * END-TO-END theme workflow, exactly as the Phase 1 completion gate specifies:
 *
 *   Create/Duplicate -> Edit -> Add/reorder/remove sections -> Save Draft -> Publish
 *   -> Storefront Render -> Restore revision
 *
 * This exercises the whole chain against the database rather than testing each service in
 * isolation, which is the point of the gate: components can each pass while the workflow is broken.
 */
class ThemeEndToEndWorkflowTest extends TestCase
{
    private ThemeManager $manager;
    private ThemeBuilderService $builder;
    private StorefrontThemeRenderer $storefront;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $registry = new SectionRegistry();
        $this->manager = new ThemeManager();
        $this->builder = new ThemeBuilderService($registry, app(\App\Services\Theme\SectionReadiness::class));
        $this->storefront = new StorefrontThemeRenderer($registry);

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
    }

    public function test_full_lifecycle_create_edit_publish_render_restore(): void
    {
        // 1. CREATE a theme (comes with an initial draft) and activate it
        $theme = $this->manager->createTheme(['name' => 'Storefront', 'slug' => 'storefront']);
        $this->manager->activate($theme);
        $draft = $theme->versions()->first();
        $this->assertSame(ThemeVersion::STATUS_DRAFT, $draft->status);

        // Storefront must still fall back to existing blades — nothing published yet
        $this->assertNull($this->storefront->sectionsFor('home'));

        // 2. EDIT: add sections
        $hero = $this->builder->addSection($draft, 'home', 'hero_banner');
        $slider = $this->builder->addSection($draft, 'home', 'product_slider', ['title' => 'Top picks', 'limit' => 8]);
        $brands = $this->builder->addSection($draft, 'home', 'brand_slider');
        $this->assertCount(3, $this->builder->getPageStructure($draft, 'home'));

        // 3. REORDER (drag & drop)
        $this->builder->reorderSections($draft, 'home', [$slider->id, $hero->id, $brands->id]);
        $this->assertSame(1, $slider->fresh()->sort_order);

        // 4. REMOVE a section
        $this->builder->deleteSection($brands);
        $this->assertNull(ThemeSection::find($brands->id));
        $this->assertSame(2, $hero->fresh()->sort_order); // gap closed

        // 5. SAVE DRAFT: global settings + section settings
        $draft->settings = ['colors' => ['primary' => '#0f766e']];
        $draft->save();
        $this->builder->updateSection($slider, ['title' => 'Best sellers', 'limit' => 12]);
        $this->assertSame('Best sellers', $slider->fresh()->settings['title']);

        // still not live
        $this->assertNull($this->storefront->sectionsFor('home'));

        // 6. PUBLISH
        $this->manager->publish($draft);
        $this->assertSame(ThemeVersion::STATUS_PUBLISHED, $draft->fresh()->status);

        // 7. STOREFRONT RENDER reflects exactly what was published
        $rendered = $this->storefront->sectionsFor('home');
        $this->assertCount(2, $rendered);
        $this->assertSame('product_slider', $rendered[0]['type']);
        $this->assertSame('Best sellers', $rendered[0]['settings']['title']);
        $this->assertSame(12, $rendered[0]['settings']['limit']);
        $this->assertSame('#0f766e', $this->storefront->globalSettings($this->manager)['colors']['primary']);

        // 8. Publish a second version, then RESTORE the first revision
        $v2 = $this->manager->createDraftFrom($draft->fresh(), 'v2');
        $this->builder->deleteSection(ThemeSection::where('theme_version_id', $v2->id)->first());
        $this->manager->publish($v2);
        $this->assertCount(1, $this->storefront->sectionsFor('home')); // v2 is live

        $restored = $this->manager->restoreVersion($draft->fresh());
        $this->assertSame(ThemeVersion::STATUS_DRAFT, $restored->status);      // restore is NON-destructive
        $this->assertSame(ThemeVersion::STATUS_PUBLISHED, $v2->fresh()->status); // live theme untouched
        $this->assertCount(1, $this->storefront->sectionsFor('home'));          // storefront still v2

        // publishing the restored draft brings the old layout back
        $this->manager->publish($restored);
        $this->assertCount(2, $this->storefront->sectionsFor('home'));
        $this->assertSame(ThemeVersion::STATUS_ARCHIVED, $v2->fresh()->status);
    }

    public function test_version_history_lists_all_revisions_newest_first(): void
    {
        $theme = $this->manager->createTheme(['name' => 'H', 'slug' => 'h']);
        $first = $theme->versions()->first();
        $this->manager->publish($first);
        $second = $this->manager->createDraftFrom($first->fresh());

        $history = $this->manager->versionHistory($theme->id);

        $this->assertCount(2, $history);
        $this->assertSame($second->id, $history->first()->id); // newest first
    }

    public function test_restore_never_mutates_or_deletes_the_source_version(): void
    {
        $theme = $this->manager->createTheme(['name' => 'S', 'slug' => 's'], ['colors' => ['primary' => '#111111']]);
        $source = $theme->versions()->first();
        $this->manager->publish($source);
        $sourceUpdatedAt = $source->fresh()->updated_at;

        $restored = $this->manager->restoreVersion($source->fresh());

        $this->assertNotSame($source->id, $restored->id);
        $this->assertNotNull(ThemeVersion::find($source->id));                       // not deleted
        $this->assertSame(ThemeVersion::STATUS_PUBLISHED, $source->fresh()->status); // not demoted
        $this->assertEquals($sourceUpdatedAt, $source->fresh()->updated_at);         // not touched
        $this->assertSame(['colors' => ['primary' => '#111111']], $restored->settings);
        $this->assertSame($source->id, $restored->based_on_version_id);              // lineage recorded
    }

    public function test_published_theme_cannot_be_edited_through_the_builder(): void
    {
        $theme = $this->manager->createTheme(['name' => 'P', 'slug' => 'p']);
        $this->manager->activate($theme);
        $version = $theme->versions()->first();
        $this->builder->addSection($version, 'home', 'hero_banner');
        $this->manager->publish($version);

        // every mutation must refuse a published version
        $this->assertNull($this->builder->addSection($version->fresh(), 'home', 'brand_slider'));
        $this->assertFalse($this->builder->reorderSections($version->fresh(), 'home', []));
        $section = ThemeSection::where('theme_version_id', $version->id)->first();
        $this->assertFalse($this->builder->updateSection($section, ['title' => 'x']));
        $this->assertFalse($this->builder->deleteSection($section));
        $this->assertNotNull(ThemeSection::find($section->id)); // survived
    }
}
