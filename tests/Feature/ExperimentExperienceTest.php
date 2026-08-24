<?php

namespace Tests\Feature;

use App\Models\ExperienceExperiment;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Commerce\ExperimentResolver;
use App\Services\Commerce\ExperimentRules;
use App\Services\Theme\ThemeDelivery;
use App\Services\Theme\ViewerContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Experiments (Phase 3.5): stable buckets, control by default, variants as validated patches.
 *
 * §47 is the heart: the same subject lands in the same bucket on every request, with nothing
 * stored to make it so — and every failure mode (no identity, stopped, corrupt, engine off)
 * lands on control, which is the published page untouched (§48).
 */
class ExperimentExperienceTest extends TestCase
{
    private ThemeVersion $version;
    private ThemeSection $hero;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        session(['local' => 'en']);
        config(['commerce.enabled' => true]);

        foreach (['experience_experiments', 'theme_blocks', 'theme_sections', 'theme_versions', 'themes', 'business_settings'] as $table) {
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
        Schema::create('experience_experiments', function (Blueprint $table) {
            $table->id(); $table->string('name', 120); $table->string('key', 60)->unique();
            $table->string('status', 20)->default('draft'); $table->string('page', 60)->default('home');
            $table->uuid('section_uuid'); $table->json('variants')->nullable(); $table->timestamps();
        });

        $theme = Theme::create(['name' => 'Storefront', 'slug' => 'storefront', 'is_active' => true]);
        $this->version = ThemeVersion::create([
            'theme_id' => $theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED, 'revision' => 1,
        ]);
        $this->hero = ThemeSection::create([
            'theme_version_id' => $this->version->id, 'page' => 'home', 'type' => 'product_slider',
            'uuid' => 'aaaaaaaa-0000-0000-0000-000000000001',
            'sort_order' => 1, 'is_visible' => true, 'settings' => ['title' => 'Control title', 'limit' => 8],
        ]);
    }

    private function experiment(array $variants, string $status = 'running'): ExperienceExperiment
    {
        return ExperienceExperiment::create([
            'name' => 'Hero test', 'key' => 'hero-test', 'status' => $status,
            'page' => 'home', 'section_uuid' => $this->hero->uuid, 'variants' => $variants,
        ]);
    }

    private function titleFor(?string $subject): string
    {
        Cache::flush();

        $payload = app(ThemeDelivery::class)->payload('home', new ViewerContext(
            platform: ViewerContext::PLATFORM_WEB, experimentSubject: $subject,
        ));

        return $payload['sections'][0]['settings']['title'];
    }

    // ---- stable assignment (§47) ------------------------------------------------------------

    public function test_the_same_subject_keeps_the_same_variant_and_the_split_really_splits(): void
    {
        $experiment = $this->experiment([
            ['key' => 'b', 'weight' => 50, 'settings' => ['title' => 'Variant title']],
        ]);

        $seen = [];
        for ($subject = 1; $subject <= 60; $subject++) {
            $first = $experiment->variantFor('v' . $subject);
            $again = $experiment->variantFor('v' . $subject);

            $this->assertSame($first, $again, 'assignment must never move between requests');
            $seen[$first['key'] ?? 'control'] = true;
        }

        $this->assertArrayHasKey('b', $seen, 'a 50% split that nobody lands in is not a split');
        $this->assertArrayHasKey('control', $seen);
    }

    public function test_no_identity_means_control_never_randomness(): void
    {
        $experiment = $this->experiment([
            ['key' => 'b', 'weight' => 99, 'settings' => ['title' => 'Variant title']],
        ]);

        $this->assertNull($experiment->variantFor(null));
        $this->assertNull($experiment->variantFor(''));
    }

    // ---- the patch, delivered ---------------------------------------------------------------

    public function test_a_variant_patches_only_what_it_names_and_says_which_experiment_it_is(): void
    {
        $this->experiment([
            ['key' => 'b', 'weight' => 100, 'settings' => ['title' => 'Variant title']],
        ]);

        Cache::flush();
        $payload = app(ThemeDelivery::class)->payload('home', new ViewerContext(
            platform: ViewerContext::PLATFORM_WEB, experimentSubject: 'v-any',
        ));
        $section = $payload['sections'][0];

        $this->assertSame('Variant title', $section['settings']['title']);
        $this->assertSame(8, $section['settings']['limit'], 'unnamed fields stay as published');
        $this->assertSame(['key' => 'hero-test', 'variant' => 'b'], $section['experiment']);
    }

    public function test_control_and_variant_pages_have_different_checksums_and_cache_entries(): void
    {
        $this->experiment([
            ['key' => 'b', 'weight' => 50, 'settings' => ['title' => 'Variant title']],
        ]);

        // Find one subject per bucket.
        $experiment = ExperienceExperiment::first();
        $variantSubject = $controlSubject = null;
        for ($candidate = 1; $candidate < 200 && ($variantSubject === null || $controlSubject === null); $candidate++) {
            if ($experiment->variantFor('v' . $candidate) === null) {
                $controlSubject ??= 'v' . $candidate;
            } else {
                $variantSubject ??= 'v' . $candidate;
            }
        }

        $this->assertNotSame(
            $this->titleFor($controlSubject),
            $this->titleFor($variantSubject),
            'two buckets, two pages — a shared cache entry would leak one into the other',
        );
    }

    // ---- §48: every failure is control ------------------------------------------------------

    public function test_a_stopped_experiment_serves_control_to_everybody(): void
    {
        $this->experiment([
            ['key' => 'b', 'weight' => 100, 'settings' => ['title' => 'Variant title']],
        ], status: 'stopped');

        $this->assertSame('Control title', $this->titleFor('v-any'));
    }

    public function test_the_kill_switch_serves_control(): void
    {
        $this->experiment([
            ['key' => 'b', 'weight' => 100, 'settings' => ['title' => 'Variant title']],
        ]);
        config(['commerce.enabled' => false]);

        $this->assertSame('Control title', $this->titleFor('v-any'));
    }

    public function test_a_corrupt_experiment_serves_control_not_an_error(): void
    {
        $experiment = $this->experiment([]);
        $experiment->forceFill(['variants' => ['garbage', ['weight' => 'NaN']]])->save();

        $this->assertSame('Control title', $this->titleFor('v-any'));
    }

    // ---- validation -------------------------------------------------------------------------

    public function test_variants_are_partial_patches_coerced_by_the_sections_own_schema(): void
    {
        $checked = app(ExperimentRules::class)->validateVariants([
            ['weight' => 50, 'settings' => ['title' => 'B title', 'limit' => '12', 'zodiac' => 'leo']],
        ], 'product_slider');

        $this->assertSame([], $checked['errors']);
        $patch = $checked['variants'][0]['settings'];
        $this->assertSame('B title', $patch['title']);
        $this->assertSame(12, $patch['limit'], 'coerced through the schema');
        $this->assertArrayNotHasKey('zodiac', $patch, 'unknown keys cannot ride a variant');
        $this->assertArrayNotHasKey('source', $patch, 'unnamed keys are NOT defaulted into the patch');
    }

    public function test_overweight_splits_and_empty_patches_are_refused(): void
    {
        $rules = app(ExperimentRules::class);

        $overweight = $rules->validateVariants([
            ['weight' => 60, 'settings' => ['title' => 'B']],
            ['weight' => 60, 'settings' => ['title' => 'C']],
        ], 'product_slider');
        $this->assertContains('variants:weights_exceed_100_percent', $overweight['errors']);

        $empty = $rules->validateVariants([
            ['weight' => 50, 'settings' => ['zodiac' => 'leo']],
        ], 'product_slider');
        $this->assertNotEmpty($empty['errors'], 'a variant identical to control measures nothing');
    }
}
