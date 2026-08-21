<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\Settings\ThemeSettingsController;
use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Global theme settings editor (Phase 1.2 gap): the settings schema existed but merchants had no
 * way to edit branding/colors/typography/layout. These cover the editor's behaviour and its
 * draft-only safety rule, and render the view through the real Blade pipeline.
 */
class ThemeSettingsTest extends TestCase
{
    private ThemeSettingsController $controller;
    private Theme $theme;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['theme_blocks', 'theme_sections', 'theme_versions', 'themes'] as $t) {
            Schema::dropIfExists($t);
        }
        if (!Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $t) {
                $t->id(); $t->string('type')->nullable(); $t->text('value')->nullable(); $t->timestamps();
            });
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

        $this->controller = new ThemeSettingsController(
            new ThemeManager(),
            new StorefrontThemeRenderer(new SectionRegistry())
        );
        $this->theme = Theme::create(['name' => 'T', 'slug' => 't', 'is_active' => true]);
    }

    private function draft(): ThemeVersion
    {
        return ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);
    }

    private function submit(array $data): \Illuminate\Http\RedirectResponse
    {
        return $this->controller->update(Request::create('/', 'POST', $data));
    }

    public function test_saves_settings_onto_the_draft(): void
    {
        $draft = $this->draft();

        $this->submit(['version_id' => $draft->id, 'settings' => [
            'colors'     => ['primary' => '#123456'],
            'typography' => ['base_font_size' => '18'],
            'layout'     => ['button_style' => 'pill'],
        ]]);

        $saved = $draft->fresh()->settings;
        $this->assertSame('#123456', $saved['colors']['primary']);
        $this->assertSame(18, $saved['typography']['base_font_size']); // coerced to int
        $this->assertSame('pill', $saved['layout']['button_style']);
    }

    public function test_unknown_keys_are_stripped(): void
    {
        $draft = $this->draft();

        $this->submit(['version_id' => $draft->id, 'settings' => [
            'colors'  => ['primary' => '#000000', 'evil' => 'x'],
            'hacked'  => ['a' => 'b'],
        ]]);

        $saved = $draft->fresh()->settings;
        $this->assertArrayNotHasKey('evil', $saved['colors']);
        $this->assertArrayNotHasKey('hacked', $saved);
    }

    public function test_published_version_cannot_be_edited(): void
    {
        $published = ThemeVersion::create([
            'theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED,
            'settings' => ['colors' => ['primary' => '#aaaaaa']],
        ]);

        $this->submit(['version_id' => $published->id, 'settings' => ['colors' => ['primary' => '#ffffff']]]);

        $this->assertSame('#aaaaaa', $published->fresh()->settings['colors']['primary']); // unchanged
    }

    public function test_missing_version_does_not_throw(): void
    {
        $response = $this->submit(['version_id' => 999999, 'settings' => []]);
        $this->assertNotNull($response);
    }

    public function test_partial_settings_merge_over_defaults_when_resolved(): void
    {
        $draft = $this->draft();
        $this->submit(['version_id' => $draft->id, 'settings' => ['colors' => ['primary' => '#abcdef']]]);

        $resolved = (new ThemeManager())->resolveSettings($draft->fresh());

        $this->assertSame('#abcdef', $resolved['colors']['primary']);      // override
        $this->assertSame(
            app(\App\Services\Theme\ThemeManager::class)->defaultSettings()['colors']['secondary'],
            $resolved['colors']['secondary']
        ); // default preserved
        $this->assertSame(1200, $resolved['layout']['container_width']);   // untouched group intact
    }

    /**
     * The full admin layout needs $web_config from the DB-backed view composer, which this
     * environment has no schema for. Compiling the template inside the booted app still proves the
     * Blade syntax is valid AND that every <x-ui.*> component it references resolves.
     */
    public function test_settings_view_compiles_and_resolves_its_components(): void
    {
        $source = file_get_contents(resource_path('views/admin-views/theme/settings.blade.php'));
        $compiled = \Illuminate\Support\Facades\Blade::compileString($source);

        $this->assertNotEmpty($compiled);
        // Colour/font/layout inputs are generated inside @foreach loops, so their names compile to
        // interpolated PHP; assert on the literal prefixes plus the explicitly-written fields.
        $this->assertStringContainsString('settings[branding][', $compiled);
        $this->assertStringContainsString('settings[colors][', $compiled);
        $this->assertStringContainsString('settings[typography][base_font_size]', $compiled);
        $this->assertStringContainsString('settings[typography][line_height]', $compiled);
        $this->assertStringContainsString('settings[layout][', $compiled);

        // components resolved rather than being left as raw <x-ui.*> tags
        $this->assertStringNotContainsString('<x-ui.', $compiled);

        $tmp = tempnam(sys_get_temp_dir(), 'blade') . '.php';
        file_put_contents($tmp, $compiled);
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
        @unlink($tmp);
        $this->assertSame(0, $code, 'Compiled Blade is not valid PHP: ' . implode("\n", $out));
    }

    public function test_view_hides_the_save_button_for_a_published_version(): void
    {
        $source = file_get_contents(resource_path('views/admin-views/theme/settings.blade.php'));

        // the save action and the disabled state are both gated on $editable
        $this->assertStringContainsString('@if($editable)', $source);
        $this->assertStringContainsString('@disabled(!$editable)', $source);
    }
}
