<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\LocalisedSettings;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeDelivery;
use App\Services\Theme\ViewerContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * One section, two languages.
 *
 * The convention under test: a text setting may carry `title_ar` beside `title`, exactly the way
 * it already carries `title_mobile`. Everything else in the product is downstream of two promises —
 * that a client asking in Arabic receives Arabic STRINGS in the same keys it always read, and that
 * no override key ever leaks into a payload or a page. Break the first and installed apps show the
 * wrong language; break the second and they show `{title_ar: …}` to a shopper.
 */
class SectionLocalisationTest extends TestCase
{
    private Theme $theme;
    private ThemeVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        session(['local' => 'en']);
        app()->setLocale('en');

        foreach (['theme_blocks', 'theme_sections', 'theme_versions', 'themes', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id(); $table->string('type')->nullable(); $table->longText('value')->nullable();
            $table->timestamps();
        });
        Schema::create('themes', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(false); $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
        Schema::create('theme_versions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_id'); $table->string('label')->nullable();
            $table->string('status', 20)->default('draft'); $table->json('settings')->nullable();
            $table->unsignedBigInteger('based_on_version_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('revision')->default(0); $table->string('checksum', 64)->nullable();
            $table->timestamps();
        });
        Schema::create('theme_sections', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_version_id'); $table->uuid('uuid')->nullable();
            $table->string('page', 60)->default('home'); $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('starts_at')->nullable(); $table->timestamp('ends_at')->nullable();
            $table->json('platforms')->nullable(); $table->json('audience')->nullable();
            $table->timestamps();
        });
        Schema::create('theme_blocks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_section_id'); $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable(); $table->timestamps();
        });

        BusinessSetting::create(['type' => 'language', 'value' => json_encode([
            ['id' => 1, 'name' => 'english', 'code' => 'en', 'status' => 1, 'default' => true],
            ['id' => 2, 'name' => 'arabic',  'code' => 'ar', 'status' => 1, 'default' => false],
            ['id' => 3, 'name' => 'french',  'code' => 'fr', 'status' => 0, 'default' => false],
        ])]);
        Cache::flush();
        LocalisedSettings::forget();

        $this->theme = Theme::create(['name' => 'Storefront', 'slug' => 'storefront', 'is_active' => true]);
        $this->version = ThemeVersion::create([
            'theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED, 'revision' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        LocalisedSettings::forget();
        app()->setLocale('en');
        parent::tearDown();
    }

    public function test_collapse_folds_the_requested_language_in_and_strips_every_override(): void
    {
        $settings = ['title' => 'Ramadan Offers', 'title_ar' => 'عروض رمضان', 'limit' => 8];

        $arabic = LocalisedSettings::collapse($settings, 'ar');
        $this->assertSame('عروض رمضان', $arabic['title']);
        $this->assertArrayNotHasKey('title_ar', $arabic);

        $english = LocalisedSettings::collapse($settings, 'en');
        $this->assertSame('Ramadan Offers', $english['title']);
        $this->assertArrayNotHasKey('title_ar', $english, 'another language\'s text is payload noise');
    }

    public function test_a_blank_override_inherits_the_base_text(): void
    {
        $collapsed = LocalisedSettings::collapse(['title' => 'Offers', 'title_ar' => '  '], 'ar');

        $this->assertSame('Offers', $collapsed['title']);
        $this->assertArrayNotHasKey('title_ar', $collapsed);
    }

    public function test_a_key_that_merely_ends_in_a_language_code_is_nobodys_override(): void
    {
        // No `some` base key exists, so `some_ar` is somebody's setting, not our convention.
        $collapsed = LocalisedSettings::collapse(['some_ar' => 'value'], 'ar');

        $this->assertSame(['some_ar' => 'value'], $collapsed);
    }

    public function test_only_live_non_default_languages_are_offered_for_overrides(): void
    {
        $this->assertSame(['ar' => 'arabic'], LocalisedSettings::overridable());
        $this->assertSame('en', LocalisedSettings::defaultLocale());
    }

    public function test_normalisation_keeps_text_overrides_and_refuses_the_rest(): void
    {
        $clean = app(SectionRegistry::class)->normalizeSettings('product_slider', [
            'title'    => 'Best sellers',
            'title_ar' => 'الأكثر مبيعاً',
            'limit'    => 8,
            'limit_ar' => 99,
        ]);

        $this->assertSame('الأكثر مبيعاً', $clean['title_ar'], 'a text override survives saving');
        $this->assertArrayNotHasKey('limit_ar', $clean, 'a translated number is not a thing');
    }

    public function test_the_api_speaks_the_language_of_the_lang_header(): void
    {
        ThemeSection::create([
            'theme_version_id' => $this->version->id, 'page' => 'home', 'type' => 'product_slider',
            'sort_order' => 1, 'is_visible' => true,
            'settings' => ['title' => 'Best sellers', 'title_ar' => 'الأكثر مبيعاً', 'source' => 'best_selling'],
        ]);

        $delivery = app(ThemeDelivery::class);
        $viewerFor = fn (string $locale) => new ViewerContext(
            platform: ViewerContext::PLATFORM_APP,
            device: ViewerContext::DEVICE_MOBILE,
            locale: $locale,
        );

        $arabic = $delivery->payload('home', $viewerFor('ar'));
        $this->assertSame('الأكثر مبيعاً', $arabic['sections'][0]['settings']['title']);
        $this->assertArrayNotHasKey('title_ar', $arabic['sections'][0]['settings']);

        $english = $delivery->payload('home', $viewerFor('en'));
        $this->assertSame('Best sellers', $english['sections'][0]['settings']['title'],
            'the second language must not have been cached over the first');

        $this->assertNotSame($arabic['checksum'], $english['checksum'],
            'two languages are two different pages to a syncing client');
    }

    public function test_the_storefront_renders_the_sessions_language(): void
    {
        ThemeSection::create([
            'theme_version_id' => $this->version->id, 'page' => 'home', 'type' => 'faq',
            'sort_order' => 1, 'is_visible' => true,
            'settings' => ['title' => 'Common questions', 'title_ar' => 'الأسئلة الشائعة'],
        ]);
        $section = ThemeSection::where('type', 'faq')->first();
        \App\Models\ThemeBlock::create([
            'theme_section_id' => $section->id, 'type' => 'qa', 'sort_order' => 1,
            'is_visible' => true,
            'settings' => ['question' => 'Do you deliver?', 'question_ar' => 'هل توصلون؟', 'answer' => 'Yes.'],
        ]);

        app()->setLocale('ar');
        app(StorefrontThemeRenderer::class)->flush();
        $html = view('theme-sections.home')->render();

        $this->assertStringContainsString('الأسئلة الشائعة', $html);
        $this->assertStringContainsString('هل توصلون؟', $html, 'block text localises like section text');
        $this->assertStringNotContainsString('title_ar', $html);

        app()->setLocale('en');
        app(StorefrontThemeRenderer::class)->flush();
        Cache::flush();
        LocalisedSettings::forget();
        $this->assertStringContainsString('Common questions', view('theme-sections.home')->render());
    }
}
