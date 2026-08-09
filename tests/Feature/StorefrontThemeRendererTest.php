<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The compatibility shim is the safety-critical part: the storefront must keep rendering its
 * existing templates until a merchant actually publishes a theme.
 */
class StorefrontThemeRendererTest extends TestCase
{
    private StorefrontThemeRenderer $renderer;
    private Theme $theme;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->renderer = new StorefrontThemeRenderer(new SectionRegistry());

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

        $this->theme = Theme::create(['name' => 'T', 'slug' => 't', 'is_active' => true]);
    }

    private function publishedVersion(): ThemeVersion
    {
        return ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED]);
    }

    // ---- the compatibility shim ----

    public function test_returns_null_when_nothing_is_published(): void
    {
        ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);

        $this->assertNull($this->renderer->sectionsFor('home')); // storefront keeps its own blades
    }

    public function test_returns_null_when_published_version_has_no_sections(): void
    {
        $this->publishedVersion();

        $this->assertNull($this->renderer->sectionsFor('home'));
    }

    public function test_returns_null_when_no_theme_is_active(): void
    {
        $version = $this->publishedVersion();
        ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'hero_banner', 'sort_order' => 1, 'is_visible' => true]);
        $this->theme->update(['is_active' => false]);
        Cache::flush();

        $this->assertNull($this->renderer->sectionsFor('home'));
    }

    // ---- rendering ----

    public function test_returns_visible_sections_in_order(): void
    {
        $version = $this->publishedVersion();
        ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'product_slider', 'sort_order' => 2, 'is_visible' => true]);
        ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'hero_banner', 'sort_order' => 1, 'is_visible' => true]);

        $sections = $this->renderer->sectionsFor('home');

        $this->assertCount(2, $sections);
        $this->assertSame('hero_banner', $sections[0]['type']);
        $this->assertSame('product_slider', $sections[1]['type']);
    }

    public function test_hidden_sections_and_blocks_are_excluded(): void
    {
        $version = $this->publishedVersion();
        ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'hero_banner', 'sort_order' => 2, 'is_visible' => false]);
        $visible = ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'brand_slider', 'sort_order' => 1, 'is_visible' => true]);
        ThemeBlock::create(['theme_section_id' => $visible->id, 'type' => 'a', 'sort_order' => 1, 'is_visible' => true]);
        ThemeBlock::create(['theme_section_id' => $visible->id, 'type' => 'b', 'sort_order' => 2, 'is_visible' => false]);

        $sections = $this->renderer->sectionsFor('home');

        $this->assertCount(1, $sections);
        $this->assertCount(1, $sections[0]['blocks']);
        $this->assertSame('a', $sections[0]['blocks'][0]['type']);
    }

    public function test_settings_are_normalized_against_the_registry(): void
    {
        $version = $this->publishedVersion();
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'product_slider',
            'sort_order' => 1, 'is_visible' => true,
            'settings' => ['limit' => '7', 'stale_key' => 'x'],
        ]);

        $settings = $this->renderer->sectionsFor('home')[0]['settings'];

        $this->assertSame(7, $settings['limit']);                 // coerced
        $this->assertArrayNotHasKey('stale_key', $settings);      // dropped
        $this->assertArrayHasKey('padding_top', $settings);       // defaults applied
    }

    public function test_pages_are_isolated(): void
    {
        $version = $this->publishedVersion();
        ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'footer', 'type' => 'footer_columns', 'sort_order' => 1, 'is_visible' => true]);

        $this->assertNull($this->renderer->sectionsFor('home'));
        $this->assertCount(1, $this->renderer->sectionsFor('footer'));
    }

    // ---- responsive resolution ----

    public function test_responsive_value_falls_back_to_the_base_value(): void
    {
        $settings = ['columns' => 5, 'columns_mobile' => 2];

        $this->assertSame(5, $this->renderer->responsiveValue($settings, 'columns'));
        $this->assertSame(5, $this->renderer->responsiveValue($settings, 'columns', 'tablet')); // no override
        $this->assertSame(2, $this->renderer->responsiveValue($settings, 'columns', 'mobile'));
    }

    // ---- caching / invalidation ----

    public function test_publishing_invalidates_the_storefront_cache(): void
    {
        $version = $this->publishedVersion();
        ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'hero_banner', 'sort_order' => 1, 'is_visible' => true]);
        $this->assertCount(1, $this->renderer->sectionsFor('home')); // warms cache

        // publish a new version carrying two sections
        $next = ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);
        ThemeSection::create(['theme_version_id' => $next->id, 'page' => 'home', 'type' => 'hero_banner', 'sort_order' => 1, 'is_visible' => true]);
        ThemeSection::create(['theme_version_id' => $next->id, 'page' => 'home', 'type' => 'brand_slider', 'sort_order' => 2, 'is_visible' => true]);

        (new ThemeManager())->publish($next);

        $this->assertCount(2, $this->renderer->sectionsFor('home')); // cache was dropped on publish
    }

    public function test_missing_tables_degrade_to_null_not_a_fatal(): void
    {
        Schema::dropIfExists('theme_sections');

        $this->assertNull($this->renderer->sectionsFor('home'));
    }

    public function test_global_settings_fall_back_to_defaults_without_a_published_version(): void
    {
        $settings = $this->renderer->globalSettings(new ThemeManager());

        $this->assertSame('#0f766e', $settings['colors']['primary']);
    }

    // ---- draft preview (admin-only) ----

    public function test_preview_is_ignored_for_guests(): void
    {
        // A guest with the preview key in session must still see the PUBLISHED theme, never a draft.
        $published = $this->publishedVersion();
        ThemeSection::create(['theme_version_id' => $published->id, 'page' => 'home', 'type' => 'hero_banner', 'sort_order' => 1, 'is_visible' => true]);

        $draft = ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);
        ThemeSection::create(['theme_version_id' => $draft->id, 'page' => 'home', 'type' => 'brand_slider', 'sort_order' => 1, 'is_visible' => true]);
        ThemeSection::create(['theme_version_id' => $draft->id, 'page' => 'home', 'type' => 'newsletter', 'sort_order' => 2, 'is_visible' => true]);

        session([StorefrontThemeRenderer::PREVIEW_SESSION_KEY => $draft->id]);

        $sections = $this->renderer->sectionsFor('home');
        $this->assertCount(1, $sections);                       // published, not the 2-section draft
        $this->assertSame('hero_banner', $sections[0]['type']);
    }

    public function test_preview_without_a_session_key_renders_published(): void
    {
        $published = $this->publishedVersion();
        ThemeSection::create(['theme_version_id' => $published->id, 'page' => 'home', 'type' => 'hero_banner', 'sort_order' => 1, 'is_visible' => true]);

        $this->assertCount(1, $this->renderer->sectionsFor('home'));
    }
}
