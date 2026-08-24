<?php

namespace Tests\Feature;

use App\Models\ExperienceCampaign;
use App\Models\ExperienceExperiment;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ProductMetric;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Commerce\CampaignResolver;
use App\Services\Commerce\ExperienceHealth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesCatalogueSchema;
use Tests\TestCase;

/**
 * Experience Health (Phase 3.7): the panel that reads the same rows the serve path reads.
 *
 * Each test breaks the live experience in one specific quiet way and asserts the panel names it
 * with the right severity — and that a healthy shop reports NOTHING, because a panel that cries
 * wolf trains merchants to stop reading it.
 */
class ExperienceHealthTest extends TestCase
{
    use CreatesCatalogueSchema;

    private ThemeVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        session(['local' => 'en']);
        config(['commerce.enabled' => true]);
        $this->createCatalogueSchema();

        foreach (['product_collections', 'product_metrics', 'experience_campaigns',
                  'experience_experiments', 'theme_blocks', 'theme_sections', 'theme_versions', 'themes'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('themes', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(false); $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
        Schema::create('theme_versions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_id'); $table->string('status', 20)->default('draft');
            $table->json('settings')->nullable(); $table->unsignedBigInteger('based_on_version_id')->nullable();
            $table->timestamp('published_at')->nullable(); $table->unsignedInteger('revision')->default(0);
            $table->string('checksum', 64)->nullable(); $table->string('label')->nullable(); $table->timestamps();
        });
        Schema::create('theme_sections', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_version_id'); $table->uuid('uuid')->nullable();
            $table->string('page', 60)->default('home'); $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('starts_at')->nullable(); $table->timestamp('ends_at')->nullable();
            $table->json('platforms')->nullable(); $table->json('audience')->nullable(); $table->timestamps();
        });
        Schema::create('product_collections', function (Blueprint $table) {
            $table->id(); $table->string('name', 120); $table->string('slug', 60)->unique();
            $table->boolean('status')->default(true); $table->json('rules')->nullable();
            $table->string('sort_by', 40)->default('sales_30d'); $table->json('merchandising')->nullable();
            $table->timestamps();
        });
        Schema::create('product_metrics', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('product_id')->unique();
            $table->unsignedBigInteger('sales_30d')->default(0); $table->unsignedBigInteger('views_30d')->default(0);
            $table->unsignedBigInteger('carted_30d')->default(0); $table->decimal('rating', 4, 2)->default(0);
            $table->unsignedBigInteger('wishlist_count')->default(0); $table->timestamp('computed_at')->nullable();
        });
        Schema::create('experience_campaigns', function (Blueprint $table) {
            $table->id(); $table->string('name', 120); $table->string('status', 20)->default('draft');
            $table->string('page', 60)->default('home'); $table->unsignedInteger('priority')->default(30);
            $table->timestamp('starts_at')->nullable(); $table->timestamp('ends_at')->nullable();
            $table->json('overrides')->nullable(); $table->timestamps();
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
    }

    private function severities(): array
    {
        return array_column(app(ExperienceHealth::class)->findings(), 'severity', 'key');
    }

    public function test_a_healthy_shop_reports_nothing(): void
    {
        ThemeSection::create([
            'theme_version_id' => $this->version->id, 'page' => 'home', 'type' => 'spacer',
            'sort_order' => 1, 'is_visible' => true, 'settings' => ['height' => 20],
        ]);

        $this->assertSame([], app(ExperienceHealth::class)->findings(),
            'a panel that cries wolf trains merchants to stop reading it');
    }

    public function test_a_live_section_on_a_dead_collection_is_critical(): void
    {
        ThemeSection::create([
            'theme_version_id' => $this->version->id, 'page' => 'home', 'type' => 'product_slider',
            'sort_order' => 1, 'is_visible' => true,
            'settings' => ['source' => 'collection', 'collection_id' => 424242],
        ]);

        $this->assertContains('critical', $this->severities(), 'this is being served wrong right now');
    }

    public function test_an_unavailable_pin_is_a_warning(): void
    {
        $dead = Product::query()->create([
            'name' => 'Dead', 'added_by' => 'admin', 'product_type' => 'physical',
            'status' => 0, 'request_status' => 1, 'current_stock' => 0, 'unit_price' => 5,
        ]);
        ProductCollection::create([
            'name' => 'Pinned', 'slug' => 'pinned', 'status' => true, 'rules' => [],
            'sort_by' => 'sales_30d',
            'merchandising' => ['pins' => [['id' => $dead->id, 'position' => 1]]],
        ]);

        $severities = $this->severities();
        $this->assertSame('warning', $severities['unavailable_pins_' . ProductCollection::first()->id] ?? null);
    }

    public function test_a_collection_below_its_minimum_that_hides_is_a_warning(): void
    {
        ProductCollection::create([
            'name' => 'Thin', 'slug' => 'thin', 'status' => true,
            'rules' => [['field' => 'price', 'operator' => 'less_than', 'value' => -1]],
            'sort_by' => 'sales_30d',
            'merchandising' => ['min_items' => 4, 'fallback' => ['kind' => 'hide']],
        ]);
        // A metrics row keeps the staleness check quiet, isolating THIS finding.
        ProductMetric::create(['product_id' => 1, 'computed_at' => now()]);

        $severities = $this->severities();
        $this->assertSame('warning', $severities['thin_collection_' . ProductCollection::first()->id] ?? null);
    }

    public function test_metrics_never_computed_is_a_warning_only_when_collections_exist(): void
    {
        $this->assertArrayNotHasKey('metrics_never_computed', $this->severities(),
            'no collections means nothing ranks by metrics — nothing to warn about');

        ProductCollection::create(['name' => 'Any', 'slug' => 'any', 'status' => true,
            'rules' => [], 'sort_by' => 'sales_30d']);

        $this->assertSame('warning', $this->severities()['metrics_never_computed'] ?? null);
    }

    public function test_a_running_experiment_on_an_unpublished_section_is_a_warning(): void
    {
        ExperienceExperiment::create([
            'name' => 'Orphan', 'key' => 'orphan', 'status' => 'running', 'page' => 'home',
            'section_uuid' => 'aaaaaaaa-0000-0000-0000-00000000dead',
            'variants' => [['key' => 'b', 'weight' => 50, 'settings' => ['title' => 'X']]],
        ]);

        $this->assertContains('warning', $this->severities(), 'it is measuring nothing');
    }

    public function test_time_travel_evaluates_windows_as_of_the_chosen_moment(): void
    {
        ExperienceCampaign::create([
            'name' => 'Next week', 'status' => ExperienceCampaign::STATUS_SCHEDULED, 'page' => 'home',
            'priority' => 30, 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(10),
            'overrides' => [['slot' => 'top', 'section' => ['type' => 'spacer', 'settings' => ['height' => 1]]]],
        ]);

        $resolver = app(CampaignResolver::class);

        $this->assertSame([], $resolver->overridesFor('home'), 'not live now');
        $this->assertCount(1, $resolver->overridesFor('home', now()->addDays(7)),
            'evaluated as of a simulated moment, with the server clock untouched (§61)');
    }
}
