<?php

namespace Tests\Feature;

use App\Models\ExperienceCampaign;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Commerce\CampaignRules;
use App\Services\Commerce\CampaignResolver;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeDelivery;
use App\Services\Theme\ViewerContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Campaigns (Phase 3.3): overlays that never touch the page they dress.
 *
 * The §33/§34 guarantee carries this whole feature: the published sections are read, spliced
 * over at serve time, and returned — never written. So the tests that matter most are the ones
 * where the campaign is broken, over, or in conflict, and the base page must come back
 * byte-identical. §82's mandatory failure drill lives here too.
 */
class CampaignExperienceTest extends TestCase
{
    private Theme $theme;
    private ThemeVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        session(['local' => 'en']);
        config(['commerce.enabled' => true]);

        foreach (['experience_campaigns', 'theme_blocks', 'theme_sections', 'theme_versions', 'themes', 'business_settings'] as $table) {
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
        Schema::create('experience_campaigns', function (Blueprint $table) {
            $table->id(); $table->string('name', 120); $table->string('status', 20)->default('draft');
            $table->string('page', 60)->default('home'); $table->unsignedInteger('priority')->default(30);
            $table->timestamp('starts_at')->nullable(); $table->timestamp('ends_at')->nullable();
            $table->json('overrides')->nullable(); $table->timestamps();
        });

        $this->theme = Theme::create(['name' => 'Storefront', 'slug' => 'storefront', 'is_active' => true]);
        $this->version = ThemeVersion::create([
            'theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED,
            'revision' => 1, 'checksum' => 'base1234',
        ]);
        ThemeSection::create([
            'theme_version_id' => $this->version->id, 'page' => 'home', 'type' => 'hero_banner',
            'sort_order' => 1, 'is_visible' => true, 'settings' => [],
        ]);
        ThemeSection::create([
            'theme_version_id' => $this->version->id, 'page' => 'home', 'type' => 'spacer',
            'sort_order' => 2, 'is_visible' => true, 'settings' => ['height' => 40],
        ]);
    }

    private function campaign(array $attributes = [], array $overrides = []): ExperienceCampaign
    {
        static $sequence = 0;

        $checked = app(CampaignRules::class)->validateOverrides($overrides);

        return ExperienceCampaign::create($attributes + [
            'name' => 'Campaign ' . (++$sequence), 'status' => ExperienceCampaign::STATUS_ACTIVE,
            'page' => 'home', 'priority' => 30,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
            'overrides' => $checked['overrides'],
        ]);
    }

    private function payloadTypes(): array
    {
        Cache::flush();
        app(ThemeDelivery::class)->flush();

        $payload = app(ThemeDelivery::class)->payload('home', new ViewerContext(
            platform: ViewerContext::PLATFORM_WEB, device: ViewerContext::DEVICE_DESKTOP,
        ));

        return array_column($payload['sections'], 'type');
    }

    private function webTypes(): array
    {
        Cache::flush();
        app(StorefrontThemeRenderer::class)->flush();

        return array_column(app(StorefrontThemeRenderer::class)->sectionsFor('home') ?? [], 'type');
    }

    // ---- validation -------------------------------------------------------------------------

    public function test_overrides_are_allowlisted_and_named_when_refused(): void
    {
        $checked = app(CampaignRules::class)->validateOverrides([
            ['slot' => 'hero', 'section' => ['type' => 'hero_banner', 'settings' => ['title' => 'Sale']]],
            ['slot' => 'basement', 'section' => ['type' => 'hero_banner', 'settings' => []]],
            ['slot' => 'top', 'section' => ['type' => 'newsletter', 'settings' => []]],
            ['slot' => 'hero', 'section' => ['type' => 'spacer', 'settings' => []]],
        ]);

        $this->assertCount(1, $checked['overrides']);
        $this->assertCount(3, $checked['errors']);
    }

    public function test_a_banner_override_carries_the_block_its_renderer_needs(): void
    {
        $checked = app(CampaignRules::class)->validateOverrides([
            ['slot' => 'hero', 'section' => ['type' => 'hero_banner',
                'settings' => ['title' => 'Ramadan', 'image' => '/storage/x.webp']]],
        ]);

        $blocks = $checked['overrides'][0]['section']['blocks'] ?? [];
        $this->assertCount(1, $blocks, 'a hero with no slide renders as an empty band');
        $this->assertSame('Ramadan', $blocks[0]['settings']['title']);
    }

    // ---- the overlay, on both clients -------------------------------------------------------

    public function test_a_live_campaign_hero_replaces_the_base_hero_on_both_paths(): void
    {
        $this->campaign(overrides: [
            ['slot' => 'hero', 'section' => ['type' => 'hero_banner', 'settings' => ['title' => 'Sale hero']]],
            ['slot' => 'bottom', 'section' => ['type' => 'spacer', 'settings' => ['height' => 12]]],
        ]);

        $apiTypes = $this->payloadTypes();
        $this->assertSame(['hero_banner', 'spacer', 'spacer'], $apiTypes,
            'hero replaced in place, spacer appended — never a second hero');

        $this->assertSame(['hero_banner', 'spacer', 'spacer'], $this->webTypes(),
            'the web and the app must dress the same page the same way');
    }

    public function test_when_the_window_closes_the_base_page_is_simply_back(): void
    {
        $campaign = $this->campaign(overrides: [
            ['slot' => 'top', 'section' => ['type' => 'promotional_banner', 'settings' => ['title' => 'Now']]],
        ]);

        $this->assertContains('promotional_banner', $this->payloadTypes());

        $campaign->update(['ends_at' => now()->subMinute()]);

        $this->assertSame(['hero_banner', 'spacer'], $this->payloadTypes(),
            'no restore step exists because none is needed (§33–34)');
    }

    public function test_a_paused_campaign_serves_nothing(): void
    {
        $campaign = $this->campaign(overrides: [
            ['slot' => 'top', 'section' => ['type' => 'spacer', 'settings' => ['height' => 1]]],
        ]);
        $campaign->update(['status' => ExperienceCampaign::STATUS_PAUSED]);

        $this->assertSame(['hero_banner', 'spacer'], $this->payloadTypes());
    }

    public function test_priority_decides_a_contested_slot_deterministically(): void
    {
        $this->campaign(['priority' => 10], [
            ['slot' => 'hero', 'section' => ['type' => 'hero_banner', 'settings' => ['title' => 'Low']]],
        ]);
        $this->campaign(['priority' => 90], [
            ['slot' => 'hero', 'section' => ['type' => 'hero_banner', 'settings' => ['title' => 'High']]],
        ]);

        $overrides = app(CampaignResolver::class)->overridesFor('home');

        $this->assertCount(1, $overrides, 'one winner per slot');
        // The hero's text lives on its synthesised slide, where the renderer reads it.
        $this->assertSame('High', $overrides[0]['section']['blocks'][0]['settings']['title']);
    }

    public function test_equal_priority_on_one_slot_is_refused_at_activation(): void
    {
        $standing = $this->campaign(['priority' => 50], [
            ['slot' => 'hero', 'section' => ['type' => 'hero_banner', 'settings' => ['title' => 'First']]],
        ]);

        $challenger = $this->campaign(['priority' => 50, 'status' => ExperienceCampaign::STATUS_DRAFT], [
            ['slot' => 'hero', 'section' => ['type' => 'hero_banner', 'settings' => ['title' => 'Second']]],
        ]);
        $challenger->status = ExperienceCampaign::STATUS_ACTIVE;

        $conflicts = app(CampaignRules::class)->conflictsFor($challenger);

        $this->assertCount(1, $conflicts);
        $this->assertStringContainsString($standing->name, $conflicts[0]);
    }

    // ---- §82: the mandatory failure drill ---------------------------------------------------

    public function test_a_broken_campaign_serves_the_base_page_not_a_500(): void
    {
        $campaign = $this->campaign();
        // Corrupted past validation: an override whose section type no renderer has.
        $campaign->forceFill(['overrides' => [
            ['slot' => 'hero', 'section' => ['type' => 'exploding_widget', 'settings' => null]],
            'not even a row',
        ]])->save();

        $this->assertSame(['hero_banner', 'spacer'], $this->payloadTypes(),
            'campaign fails -> error contained -> base experience returned (§82)');
        $this->assertSame(['hero_banner', 'spacer'], $this->webTypes());
    }

    public function test_the_kill_switch_turns_every_overlay_off(): void
    {
        $this->campaign(overrides: [
            ['slot' => 'top', 'section' => ['type' => 'spacer', 'settings' => ['height' => 1]]],
        ]);

        config(['commerce.enabled' => false]);

        $this->assertSame(['hero_banner', 'spacer'], $this->payloadTypes(),
            'disable the module and App Builder V2 is exactly what serves (§79, final rule 30)');
    }

    // ---- sync and lifecycle -----------------------------------------------------------------

    public function test_a_campaign_transition_moves_the_version_checksum(): void
    {
        Cache::flush();
        $quiet = app(ThemeDelivery::class)->revision()['checksum'];

        $this->campaign(overrides: [
            ['slot' => 'top', 'section' => ['type' => 'spacer', 'settings' => ['height' => 1]]],
        ]);
        Cache::flush();
        $dressed = app(ThemeDelivery::class)->revision()['checksum'];

        $this->assertNotSame($quiet, $dressed,
            'an installed app decides "did anything change" from this checksum');
        $this->assertStringStartsWith('base1234', $dressed, 'the stored checksum is still the prefix');
    }

    public function test_the_tick_activates_and_ends_on_schedule(): void
    {
        $opening = ExperienceCampaign::create([
            'name' => 'Opens', 'status' => ExperienceCampaign::STATUS_SCHEDULED, 'page' => 'home',
            'priority' => 30, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour(),
            'overrides' => [],
        ]);
        $closing = ExperienceCampaign::create([
            'name' => 'Closes', 'status' => ExperienceCampaign::STATUS_ACTIVE, 'page' => 'home',
            'priority' => 30, 'starts_at' => now()->subHours(2), 'ends_at' => now()->subMinute(),
            'overrides' => [],
        ]);

        Artisan::call('commerce:campaigns-tick');

        $this->assertSame(ExperienceCampaign::STATUS_ACTIVE, $opening->fresh()->status);
        $this->assertSame(ExperienceCampaign::STATUS_ENDED, $closing->fresh()->status);
    }
}
