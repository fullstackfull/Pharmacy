<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\ThemeManager;
use App\Services\Theme\ThemePortabilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Theme import / export / presets. Import treats the payload as UNTRUSTED, so most of these assert
 * what the importer refuses to do.
 */
class ThemePortabilityTest extends TestCase
{
    private ThemePortabilityService $svc;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->svc = new ThemePortabilityService(new SectionRegistry(), new ThemeManager());
    }

    // ---- export ----

    public function test_export_captures_settings_sections_and_blocks(): void
    {
        $theme = Theme::create(['name' => 'Src', 'slug' => 'src']);
        $version = ThemeVersion::create(['theme_id' => $theme->id, 'status' => 'draft', 'settings' => ['colors' => ['primary' => '#123456']]]);
        $section = ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'hero_banner', 'sort_order' => 1, 'is_visible' => true, 'settings' => ['height' => 300]]);
        ThemeBlock::create(['theme_section_id' => $section->id, 'type' => 'slide', 'sort_order' => 1, 'is_visible' => true, 'settings' => ['img' => 'a.jpg']]);

        $payload = $this->svc->export($version->fresh());

        $this->assertSame(ThemePortabilityService::FORMAT_VERSION, $payload['format_version']);
        $this->assertSame(['colors' => ['primary' => '#123456']], $payload['settings']);
        $this->assertCount(1, $payload['sections']);
        $this->assertSame('hero_banner', $payload['sections'][0]['type']);
        $this->assertSame('slide', $payload['sections'][0]['blocks'][0]['type']);
    }

    public function test_export_then_import_round_trips(): void
    {
        $theme = Theme::create(['name' => 'RT', 'slug' => 'rt']);
        $version = ThemeVersion::create(['theme_id' => $theme->id, 'status' => 'draft']);
        ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'hero_banner', 'sort_order' => 1, 'is_visible' => true]);
        ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'brand_slider', 'sort_order' => 2, 'is_visible' => true]);

        $imported = $this->svc->import($this->svc->export($version->fresh()), 'Copy');

        $this->assertNotNull($imported['theme']);
        $sections = ThemeSection::where('theme_version_id', $imported['version']->id)->orderBy('sort_order')->get();
        $this->assertCount(2, $sections);
        $this->assertSame('hero_banner', $sections[0]->type);
        $this->assertSame('brand_slider', $sections[1]->type);
    }

    // ---- import: untrusted payload handling ----

    public function test_import_never_activates_or_marks_system(): void
    {
        $payload = $this->svc->presets()['starter']['payload'];
        $payload['theme']['is_active'] = true;   // hostile fields in the file
        $payload['theme']['is_system'] = true;

        $imported = $this->svc->import($payload);

        $this->assertFalse($imported['theme']->is_active);
        $this->assertFalse($imported['theme']->is_system);
    }

    public function test_import_always_creates_a_draft_never_a_published_version(): void
    {
        $payload = $this->svc->presets()['starter']['payload'];
        $payload['status'] = 'published';

        $imported = $this->svc->import($payload);

        $this->assertSame(ThemeVersion::STATUS_DRAFT, $imported['version']->status);
    }

    public function test_unknown_section_types_are_skipped_with_a_warning(): void
    {
        $payload = [
            'format_version' => 1,
            'theme' => ['name' => 'Mixed'],
            'sections' => [
                ['page' => 'home', 'type' => 'hero_banner'],
                ['page' => 'home', 'type' => 'evil_section'],   // not in the registry
            ],
        ];

        $imported = $this->svc->import($payload);

        $this->assertCount(1, ThemeSection::where('theme_version_id', $imported['version']->id)->get());
        $this->assertNotEmpty($imported['result']['warnings']);
    }

    public function test_arbitrary_section_settings_are_normalized_away(): void
    {
        $payload = [
            'format_version' => 1, 'theme' => ['name' => 'S'],
            'sections' => [['page' => 'home', 'type' => 'product_slider', 'settings' => ['limit' => '9', 'evil' => 'x']]],
        ];

        $imported = $this->svc->import($payload);
        $settings = ThemeSection::where('theme_version_id', $imported['version']->id)->first()->settings;

        $this->assertSame(9, $settings['limit']);
        $this->assertArrayNotHasKey('evil', $settings);
    }

    public function test_arbitrary_global_settings_are_stripped(): void
    {
        $payload = [
            'format_version' => 1, 'theme' => ['name' => 'G'],
            'settings' => ['colors' => ['primary' => '#abcdef', 'evil' => 'x'], 'hacked' => ['a' => 'b']],
            'sections' => [['page' => 'home', 'type' => 'hero_banner']],
        ];

        $imported = $this->svc->import($payload);
        $settings = $imported['version']->settings;

        $this->assertSame('#abcdef', $settings['colors']['primary']);
        $this->assertArrayNotHasKey('evil', $settings['colors']);
        $this->assertArrayNotHasKey('hacked', $settings);
    }

    public function test_rejects_non_array_and_missing_format_version(): void
    {
        $this->assertFalse($this->svc->validate('a string')['valid']);
        $this->assertFalse($this->svc->validate(['sections' => []])['valid']);
    }

    public function test_rejects_a_newer_format_version(): void
    {
        $result = $this->svc->validate(['format_version' => 999, 'sections' => [['type' => 'hero_banner']]]);

        $this->assertFalse($result['valid']);
        $this->assertContains('format_version_is_newer_than_this_installation_supports', $result['errors']);
    }

    public function test_rejects_payload_with_no_importable_sections(): void
    {
        $result = $this->svc->validate(['format_version' => 1, 'sections' => [['type' => 'nope']]]);

        $this->assertFalse($result['valid']);
        $this->assertContains('no_importable_sections_found', $result['errors']);
    }

    public function test_failed_import_writes_nothing(): void
    {
        $this->svc->import(['format_version' => 1, 'sections' => []]);

        $this->assertSame(0, Theme::count());
        $this->assertSame(0, ThemeVersion::count());
    }

    public function test_duplicate_names_get_unique_slugs(): void
    {
        $payload = $this->svc->presets()['starter']['payload'];

        $a = $this->svc->import($payload, 'Same Name');
        $b = $this->svc->import($payload, 'Same Name');

        $this->assertNotSame($a['theme']->slug, $b['theme']->slug);
    }

    // ---- presets ----

    public function test_every_preset_imports_cleanly(): void
    {
        foreach ($this->svc->presets() as $key => $preset) {
            $imported = $this->svc->import($preset['payload'], 'Preset ' . $key);

            $this->assertNotNull($imported['theme'], $key);
            $this->assertTrue($imported['result']['valid'], $key);
            $this->assertSame([], $imported['result']['warnings'], "preset {$key} referenced an unknown section type");
            $this->assertGreaterThan(0, ThemeSection::where('theme_version_id', $imported['version']->id)->count(), $key);
        }
    }

    public function test_preset_sections_are_resequenced_per_page(): void
    {
        $imported = $this->svc->import($this->svc->presets()['promotional']['payload']);

        $home = ThemeSection::where('theme_version_id', $imported['version']->id)->where('page', 'home')->orderBy('sort_order')->pluck('sort_order')->all();
        $header = ThemeSection::where('theme_version_id', $imported['version']->id)->where('page', 'header')->pluck('sort_order')->all();

        $this->assertSame([1, 2, 3, 4, 5], $home);
        $this->assertSame([1], $header); // each page numbered independently
    }
}
