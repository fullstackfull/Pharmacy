<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\ComponentCapabilityRegistry;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\ThemeBuilderService;
use App\Services\Theme\ThemeCompatibilityReport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The builder-facing half of delivery rules: what the merchant saves, and what the pre-publish
 * report tells them about it.
 */
class ThemeDeliveryRulesBuilderTest extends TestCase
{
    private ThemeBuilderService $builder;
    private Theme $theme;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        foreach (['theme_blocks', 'theme_sections', 'theme_versions', 'themes'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('themes', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(false); $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
        Schema::create('theme_versions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_id'); $table->string('label')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('revision')->default(0); $table->string('checksum', 64)->nullable();
            $table->json('settings')->nullable(); $table->unsignedBigInteger('based_on_version_id')->nullable();
            $table->timestamp('published_at')->nullable(); $table->timestamps();
        });
        Schema::create('theme_sections', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->nullable();
            $table->unsignedBigInteger('theme_version_id'); $table->string('page', 60)->default('home');
            $table->string('type', 80); $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamp('starts_at')->nullable(); $table->timestamp('ends_at')->nullable();
            $table->json('platforms')->nullable(); $table->json('audience')->nullable();
            $table->json('settings')->nullable(); $table->timestamps();
        });
        Schema::create('theme_blocks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_section_id'); $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable(); $table->timestamps();
        });

        $this->builder = app(ThemeBuilderService::class);
        $this->theme = Theme::create(['name' => 'Pharmacy', 'slug' => 'pharmacy', 'is_active' => true]);
    }

    private function draftSection(string $type = 'spacer', array $extra = []): ThemeSection
    {
        $version = ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);

        return ThemeSection::create(array_merge([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => $type, 'settings' => [],
        ], $extra));
    }

    public function test_rules_save_and_come_back_in_the_summary(): void
    {
        $section = $this->draftSection();

        $saved = $this->builder->setDeliveryRules($section, [
            'starts_at' => '2026-09-01T09:00',
            'ends_at'   => '2026-09-30T23:59',
            'platforms' => ['app', 'mobile'],
            'audience'  => ['customer'],
        ]);

        $this->assertTrue($saved);

        $summary = $this->builder->deliverySummary($section->fresh());
        $this->assertSame('2026-09-01T09:00', $summary['starts_at']);
        $this->assertSame(['app', 'mobile'], $summary['platforms']);
        $this->assertSame(['customer'], $summary['audience']);
        $this->assertTrue($summary['scheduled']);
        $this->assertTrue($summary['targeted']);
    }

    public function test_empty_rules_clear_to_no_restriction(): void
    {
        $section = $this->draftSection(extra: [
            'starts_at' => now(), 'platforms' => ['web'], 'audience' => ['guest'],
        ]);

        $this->builder->setDeliveryRules($section, [
            'starts_at' => '', 'ends_at' => null, 'platforms' => [], 'audience' => [],
        ]);

        $fresh = $section->fresh();
        $this->assertNull($fresh->starts_at);
        $this->assertNull($fresh->platforms, 'an untouched section and a cleared one must be the same row');
        $this->assertNull($fresh->audience);
    }

    public function test_a_window_that_can_never_open_is_cleared_not_saved(): void
    {
        $section = $this->draftSection();

        $this->builder->setDeliveryRules($section, [
            'starts_at' => '2026-09-30T00:00',
            'ends_at'   => '2026-09-01T00:00',
        ]);

        $fresh = $section->fresh();
        $this->assertNull($fresh->starts_at, 'end before start would hide the section forever');
        $this->assertNull($fresh->ends_at);
    }

    public function test_unknown_tokens_and_garbage_are_dropped(): void
    {
        $section = $this->draftSection();

        $this->builder->setDeliveryRules($section, [
            'starts_at' => 'not-a-date',
            'platforms' => ['app', 'smartwatch', 42, null],
            'audience'  => 'customer,alien',
        ]);

        $fresh = $section->fresh();
        $this->assertNull($fresh->starts_at);
        $this->assertSame(['app'], $fresh->platforms);
        $this->assertSame(['customer'], $fresh->audience);
    }

    public function test_published_versions_refuse_rule_edits(): void
    {
        $section = $this->draftSection();
        $section->version->update(['status' => ThemeVersion::STATUS_PUBLISHED]);

        $this->assertFalse(
            $this->builder->setDeliveryRules($section->fresh(), ['platforms' => ['app']])
        );
    }

    public function test_compatibility_report_counts_what_the_app_will_and_will_not_show(): void
    {
        $version = ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);

        foreach ([
            ['type' => 'product_slider'],
            ['type' => 'spacer'],
            ['type' => 'custom_html'],
            ['type' => 'blog_posts'],
            ['type' => 'blog_posts'],
            ['type' => 'hero_banner', 'is_visible' => false],       // hidden -> not counted at all
            ['type' => 'usp_strip', 'starts_at' => now()->addWeek()], // supported but waiting
        ] as $order => $attributes) {
            ThemeSection::create(array_merge(
                ['theme_version_id' => $version->id, 'page' => 'home', 'sort_order' => $order, 'settings' => []],
                $attributes,
            ));
        }

        $report = app(ThemeCompatibilityReport::class)->for($version);

        $this->assertSame(6, $report['sections']);
        $this->assertSame(4, $report['app_supported'], 'custom_html gained a native renderer');
        $this->assertSame(1, $report['scheduled_waiting']);

        $withheldTypes = array_column($report['withheld'], 'count', 'type');
        $this->assertArrayNotHasKey('custom_html', $withheldTypes);
        $this->assertSame(2, $withheldTypes['blog_posts'], 'repeated types aggregate with a count');

        foreach ($report['withheld'] as $gap) {
            $this->assertNotSame('', $gap['reason'], 'every gap must explain itself');
        }
    }

    public function test_report_and_capability_registry_agree_with_delivery(): void
    {
        // The report promises the ceiling the delivery pipeline enforces; if the two ever count
        // from different lists, the merchant is warned about the wrong sections.
        $registry = new ComponentCapabilityRegistry();

        foreach (array_keys(app(SectionRegistry::class)->types()) as $type) {
            $version = ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);
            ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => $type, 'settings' => []]);

            $report = app(ThemeCompatibilityReport::class)->for($version);

            $this->assertSame(
                $registry->isAppSafe($type) ? 1 : 0,
                $report['app_supported'],
                "report disagrees with capability registry about '$type'"
            );
        }
    }
}
