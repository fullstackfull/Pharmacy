<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\ThemeManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies the Theme System foundation (Phase 1.1) by provisioning only the additive theme tables
 * on the in-memory sqlite connection. Settings resolution is pure; publish/duplicate exercise the
 * transactional lifecycle.
 */
class ThemeManagerTest extends TestCase
{
    private ThemeManager $mgr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mgr = new ThemeManager();

        foreach (['theme_blocks', 'theme_sections', 'theme_versions', 'themes'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('themes', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug', 120)->unique();
            $t->string('description', 500)->nullable();
            $t->string('preview_image', 2048)->nullable();
            $t->boolean('is_active')->default(false);
            $t->boolean('is_system')->default(false);
            $t->string('created_by_type', 40)->nullable();
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });
        Schema::create('theme_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('theme_id');
            $t->string('label')->nullable();
            $t->string('status', 20)->default('draft');
            $t->json('settings')->nullable();
            $t->unsignedBigInteger('based_on_version_id')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
        Schema::create('theme_sections', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('theme_version_id');
            $t->string('page', 60)->default('home');
            $t->string('type', 80);
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_visible')->default(true);
            $t->json('settings')->nullable();
            $t->timestamps();
        });
        Schema::create('theme_blocks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('theme_section_id');
            $t->string('type', 80);
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_visible')->default(true);
            $t->json('settings')->nullable();
            $t->timestamps();
        });
    }

    private function activeTheme(): Theme
    {
        return Theme::create(['name' => 'Default', 'slug' => 'default', 'is_active' => true, 'is_system' => true]);
    }

    public function test_theme_repository_is_auto_bound_and_queries_work(): void
    {
        $repo = app(\App\Contracts\Repositories\ThemeRepositoryInterface::class);
        $this->assertInstanceOf(\App\Repositories\ThemeRepository::class, $repo);

        $active = $this->activeTheme();
        Theme::create(['name' => 'Other', 'slug' => 'other', 'is_active' => false]);

        $this->assertSame($active->id, $repo->getActiveTheme()?->id);
        $this->assertTrue($repo->slugExists('other'));
        $this->assertFalse($repo->slugExists('other', Theme::where('slug', 'other')->first()->id));
        $this->assertFalse($repo->slugExists('nope'));
    }

    public function test_resolve_settings_returns_defaults_for_null_version(): void
    {
        $s = $this->mgr->resolveSettings(null);
        $this->assertSame('#0f766e', $s['colors']['primary']);
        $this->assertSame(1200, $s['layout']['container_width']);
    }

    public function test_resolve_settings_deep_merges_partial_overrides(): void
    {
        $version = new ThemeVersion(['settings' => ['colors' => ['primary' => '#000000']]]);
        $s = $this->mgr->resolveSettings($version);

        $this->assertSame('#000000', $s['colors']['primary']);   // overridden
        $this->assertSame('#334155', $s['colors']['secondary']); // sibling default intact
        $this->assertSame(16, $s['typography']['base_font_size']); // untouched group intact
    }

    public function test_publish_archives_previous_published_version(): void
    {
        $theme = $this->activeTheme();
        $v1 = ThemeVersion::create(['theme_id' => $theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED, 'published_at' => now()]);
        $v2 = ThemeVersion::create(['theme_id' => $theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);

        $this->mgr->publish($v2);

        $this->assertSame(ThemeVersion::STATUS_PUBLISHED, $v2->fresh()->status);
        $this->assertSame(ThemeVersion::STATUS_ARCHIVED, $v1->fresh()->status);
        $this->assertNotNull($v2->fresh()->published_at);
    }

    public function test_active_theme_published_version_is_resolved(): void
    {
        $theme = $this->activeTheme();
        $v = ThemeVersion::create(['theme_id' => $theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED]);

        $this->assertSame($v->id, $this->mgr->activeThemePublishedVersion()?->id);
    }

    public function test_create_theme_makes_an_initial_draft(): void
    {
        $theme = $this->mgr->createTheme(['name' => 'New', 'slug' => 'new-theme'], ['colors' => ['primary' => '#abcdef']]);

        $this->assertDatabaseHas('themes', ['id' => $theme->id, 'slug' => 'new-theme']);
        $draft = $theme->versions()->first();
        $this->assertSame(ThemeVersion::STATUS_DRAFT, $draft->status);
        $this->assertSame(['colors' => ['primary' => '#abcdef']], $draft->settings);
    }

    public function test_activate_deactivates_all_other_themes(): void
    {
        $a = $this->activeTheme(); // is_active = true
        $b = Theme::create(['name' => 'B', 'slug' => 'b-theme', 'is_active' => false]);

        $this->mgr->activate($b);

        $this->assertTrue($b->fresh()->is_active);
        $this->assertFalse($a->fresh()->is_active);
        $this->assertSame(1, Theme::where('is_active', true)->count());
    }

    public function test_duplicate_copies_sections_and_blocks_into_a_draft(): void
    {
        $theme = $this->activeTheme();
        $src = ThemeVersion::create(['theme_id' => $theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED, 'settings' => ['colors' => ['primary' => '#111111']]]);
        $section = ThemeSection::create(['theme_version_id' => $src->id, 'page' => 'home', 'type' => 'hero', 'sort_order' => 0, 'is_visible' => true, 'settings' => ['title' => 'Hi']]);
        ThemeBlock::create(['theme_section_id' => $section->id, 'type' => 'slide', 'sort_order' => 0, 'is_visible' => true, 'settings' => ['img' => 'a.jpg']]);

        $draft = $this->mgr->createDraftFrom($src, 'My Draft');

        $this->assertSame(ThemeVersion::STATUS_DRAFT, $draft->status);
        $this->assertSame($src->id, $draft->based_on_version_id);
        $this->assertSame(['colors' => ['primary' => '#111111']], $draft->settings);
        $this->assertCount(1, $draft->sections);
        $this->assertSame('hero', $draft->sections->first()->type);
        $this->assertCount(1, $draft->sections->first()->blocks);
        $this->assertSame('slide', $draft->sections->first()->blocks->first()->type);
    }
}
