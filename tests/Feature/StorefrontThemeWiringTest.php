<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression for the theme-system-never-wired finding (stabilization P1).
 *
 * The Phase-1 theme system existed but nothing on the storefront consulted it — publishing a theme had
 * zero effect. This pins the wiring the storefront <head> now uses: publishedGlobalSettings() returns
 * the active published theme's settings (null when nothing is published, so the storefront is
 * unchanged), and the head partial emits those colours onto the CSS variables the storefront already
 * consumes site-wide.
 */
class StorefrontThemeWiringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['themes', 'theme_versions', 'theme_sections', 'business_settings'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('business_settings', function (Blueprint $t) {
            $t->id();
            $t->string('type')->nullable();
            $t->text('value')->nullable();
            $t->timestamps();
        });
        Schema::create('themes', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->boolean('is_active')->default(false);
            $t->timestamps();
        });
        Schema::create('theme_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('theme_id');
            $t->string('label')->nullable();
            $t->string('status')->default('draft');
            $t->json('settings')->nullable();
            $t->timestamps();
        });
        Schema::create('theme_sections', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('theme_version_id');
            $t->string('page')->nullable();
            $t->string('type')->nullable();
            $t->timestamps();
        });
    }

    public function test_no_published_theme_leaves_the_storefront_unchanged(): void
    {
        // No active/published theme -> null -> the head @if is skipped, legacy colours stand.
        $this->assertNull(app(StorefrontThemeRenderer::class)->publishedGlobalSettings(app(ThemeManager::class)));
    }

    public function test_a_published_theme_drives_the_storefront_css_variables(): void
    {
        $theme = Theme::create(['name' => 'Brand', 'is_active' => true]);
        ThemeVersion::create([
            'theme_id' => $theme->id,
            'status' => ThemeVersion::STATUS_PUBLISHED,
            'settings' => ['colors' => ['primary' => '#ff0000', 'secondary' => '#00ff00']],
        ]);

        $settings = app(StorefrontThemeRenderer::class)->publishedGlobalSettings(app(ThemeManager::class));
        $this->assertNotNull($settings);
        $this->assertSame('#ff0000', $settings['colors']['primary']);

        $html = view('partials.theme-global-tokens', ['themeSettings' => $settings])->render();
        $this->assertStringContainsString('--web-primary: #ff0000', $html);
        $this->assertStringContainsString('--web-secondary: #00ff00', $html);
        $this->assertStringContainsString('--base: #ff0000', $html, 'the storefront-wide base colour is overridden');
    }
}
