<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\PublishValidator;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\ThemeBuilderService;
use App\Services\Theme\ThemeCompatibilityReport;
use App\Services\Theme\ThemeManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The two admin screens the theme is built on, actually rendered.
 *
 * The storefront outage taught the lesson these apply: a template that compiles is not a template
 * that renders. A missing variable, a helper that throws on a value it did not expect, a control
 * added to a branch nobody exercises — none of that shows up until somebody opens the page, and
 * for these two pages that somebody is the merchant, in the middle of composing a home page.
 *
 * Both views extend the back-end layout, which drags in the whole admin chrome — sidebar, session,
 * settings, an authenticated user. That is not what is under test here, and mocking it would test
 * the mock. So each view is rendered without its layout: the body is what changes, and the body is
 * what is checked.
 */
class AdminThemeViewsRenderTest extends TestCase
{
    private Theme $theme;
    private ThemeVersion $draft;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        session(['local' => 'en']);

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
            $table->string('change_note', 300)->nullable();
            $table->string('status', 20)->default('draft'); $table->json('settings')->nullable();
            $table->unsignedBigInteger('based_on_version_id')->nullable();
            $table->timestamp('published_at')->nullable(); $table->timestamp('publish_at')->nullable();
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
            $table->json('channels')->nullable(); $table->timestamps();
        });
        Schema::create('theme_blocks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_section_id'); $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable(); $table->timestamps();
        });

        $this->theme = Theme::create(['name' => 'Storefront', 'slug' => 'storefront', 'is_active' => true]);
        $this->draft = ThemeVersion::create([
            'theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_DRAFT,
        ]);
        ThemeSection::create([
            'theme_version_id' => $this->draft->id, 'page' => 'home', 'type' => 'hero_banner',
            'sort_order' => 1, 'is_visible' => true, 'settings' => [],
        ]);
    }

    public function test_the_builder_renders_with_a_draft_open(): void
    {
        $html = $this->renderBody('admin-views.theme.builder', $this->builderData());

        // The three controls added to the header, each of which is a merchant's way into work they
        // could not do from this page before.
        $this->assertStringContainsString('data-url-link-compose', $html, 'the destination picker');
        $this->assertStringContainsString('tb-publish-panel', $html, 'publishing without leaving');
        $this->assertStringContainsString('data-channel="customer_app"', $html, 'the app\'s eyes');
        $this->assertStringContainsString('data-app-safe', $html, 'per-section channel reach');
    }

    public function test_the_builder_renders_a_blocked_publish_without_offering_it(): void
    {
        // A version that cannot go live still has to open — this is the state a merchant is in when
        // they most need the page to work.
        ThemeSection::create([
            'theme_version_id' => $this->draft->id, 'page' => 'home', 'type' => 'category_showcase',
            'sort_order' => 2, 'is_visible' => true, 'settings' => [],
        ]);
        PublishValidator::forget($this->draft->id);

        $html = $this->renderBody('admin-views.theme.builder', $this->builderData());

        $this->assertStringContainsString('tb-compat--stop', $html, 'it says what is blocking');
        $this->assertStringNotContainsString('publish_now', $html, 'and does not offer to publish anyway');
    }

    public function test_the_builder_renders_when_there_is_no_version_at_all(): void
    {
        // A fresh install, or a theme whose versions were all archived.
        $data = $this->builderData();
        $data['version'] = null;
        $data['theme'] = null;
        $data['structure'] = [];
        $data['goLive'] = null;
        $data['compatibility'] = null;
        $data['publishCheck'] = null;
        $data['editable'] = false;

        $this->assertNotSame('', trim($this->renderBody('admin-views.theme.builder', $data)));
    }

    public function test_the_theme_list_renders_with_its_publish_controls(): void
    {
        // A hero with no slides is a section the merchant has not finished, and the gate is right
        // to hold the publish. Give it one so this test is about the controls, not the gate.
        \App\Models\ThemeBlock::create([
            'theme_section_id' => ThemeSection::where('theme_version_id', $this->draft->id)->value('id'),
            'type' => 'slide', 'sort_order' => 1, 'is_visible' => true,
            'settings' => ['title' => 'Ramadan', 'image' => '/storage/banner/a.webp'],
        ]);
        PublishValidator::forget($this->draft->id);

        $html = $this->renderBody('admin-views.theme.index', $this->indexData());

        $this->assertStringContainsString('name="change_note"', $html, 'the note written at publish');
        $this->assertStringContainsString('name="publish_at"', $html, 'and the hour it can be published at');
    }

    public function test_the_theme_list_disables_publishing_for_a_draft_that_is_not_ready(): void
    {
        ThemeSection::create([
            'theme_version_id' => $this->draft->id, 'page' => 'home', 'type' => 'bundle',
            'sort_order' => 2, 'is_visible' => true, 'settings' => [],
        ]);
        PublishValidator::forget($this->draft->id);

        $html = $this->renderBody('admin-views.theme.index', $this->indexData());

        // Through the same helper the view uses: translate() renders a key as readable text, so
        // asserting on the raw key would pass only while the key is missing its translation.
        $this->assertStringContainsString(translate('this_draft_cannot_be_published_yet'), $html);
        $this->assertStringNotContainsString('name="change_note"', $html, 'the publish form is not offered');
    }

    public function test_the_app_builder_pages_screen_renders(): void
    {
        // The screen that makes a page something a merchant can make. It has to render in the state
        // a fresh shop is in — one theme, the three built-in pages, no custom ones yet.
        app(\App\Services\Theme\ExperiencePageService::class)->ensureSystemPages($this->theme);

        $html = $this->renderBody('admin-views.app-builder.pages', [
            'channel' => 'customer_app',
            'theme' => $this->theme,
            'pages' => app(\App\Services\Theme\ExperiencePageService::class)
                ->forChannel($this->theme->id, 'customer_app'),
            'ready' => true,
            'draft' => $this->draft,
            'editable' => true,
            'health' => [
                ['key' => 'store', 'ok' => true, 'label' => 'the_page_and_version_tables_are_migrated', 'why' => null, 'fix' => null],
                ['key' => 'scheduler', 'ok' => false, 'label' => 'the_scheduler_is_running',
                 'why' => 'scheduled_publishes_and_nightly_rollups_will_not_fire_until_the_cron_is_installed',
                 'fix' => '* * * * * php artisan schedule:run'],
            ],
            'allGood' => false,
        ]);

        $this->assertStringContainsString('name="title"', $html, 'a page can be added');
        $this->assertStringContainsString('home', $html, 'and the built-in pages are listed');
        $this->assertStringContainsString('schedule:run', $html, 'a failing check shows its fix');
    }

    public function test_the_app_builder_sections_catalogue_renders(): void
    {
        $html = $this->renderBody('admin-views.app-builder.sections', [
            'channel' => 'customer_app',
            'catalogue' => app(\App\Services\Theme\SectionRegistry::class)->catalogue(null),
            'channels' => \App\Services\Theme\Channel::RENDERABLE,
        ]);

        $this->assertStringContainsString('ab-search', $html, 'the catalogue is searchable');
        $this->assertStringContainsString('data-family', $html, 'and filterable by family');
    }

    // -----------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function builderData(): array
    {
        $registry = app(SectionRegistry::class);
        $builder = app(ThemeBuilderService::class);

        return [
            'bannerGaps' => [],
            'version' => $this->draft,
            'theme' => $this->theme,
            'page' => 'home',
            'previewUrl' => '/',
            'structure' => $builder->getPageStructure($this->draft, 'home'),
            'sectionTypes' => $registry->forPage('home'),
            'sectionCatalogue' => $registry->catalogue('home'),
            'blockLabels' => array_map(static fn (array $block) => $block['label'], $registry->blockTypes()),
            'goLive' => ['live' => false, 'active' => true, 'published' => false],
            'compatibility' => app(ThemeCompatibilityReport::class)->for($this->draft),
            'publishCheck' => app(PublishValidator::class)->inspect($this->draft),
            'reach' => [],
            'themeSettings' => app(ThemeManager::class)->resolveSettings($this->draft),
            // The pages this theme has, in the shape the page service hands over — home is one of
            // them rather than the only one the template knows about.
            'pages' => app(\App\Services\Theme\ExperiencePageService::class)->forChannel($this->theme->id, 'web'),
            'channel' => 'web',
            'editable' => true,
            'uploadAccept' => '.png,.jpg',
        ];
    }

    /** @return array<string, mixed> */
    private function indexData(): array
    {
        $themes = Theme::with('versions')->get();

        return [
            'themes' => new LengthAwarePaginator($themes, $themes->count(), 20),
            'compatibility' => [$this->theme->id => app(ThemeCompatibilityReport::class)->for($this->draft)],
            'readiness' => [$this->theme->id => app(PublishValidator::class)->inspect($this->draft)],
            'schedulerOk' => true,
            'search' => null,
            'presets' => [],
            'assetsReady' => false,
            'maxAssetSize' => 2048,
        ];
    }

    /**
     * One admin view's own body, rendered without the layout it extends.
     *
     * The layout is the admin chrome — sidebar, menu permissions, settings, a signed-in user — and
     * none of it is what these tests are about. Stripping it (and the section markers that would
     * otherwise capture the output into a layout that is never yielded) leaves exactly the markup
     * the view itself produces, which is the part that changes.
     *
     * @param  array<string, mixed>  $data
     */
    public function test_the_app_builder_media_screen_renders_with_an_image_in_it(): void
    {
        Schema::dropIfExists('theme_assets');
        Schema::create('theme_assets', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_id'); $table->string('label')->nullable();
            $table->string('path'); $table->string('disk', 40)->default('public');
            $table->string('mime_type', 100)->nullable(); $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('uploaded_by_type', 40)->nullable(); $table->unsignedBigInteger('uploaded_by_id')->nullable();
            $table->timestamps();
        });
        \App\Models\ThemeAsset::create([
            'theme_id' => $this->theme->id, 'label' => 'Header logo',
            'path' => 'theme-assets/logo.png', 'disk' => 'public',
            'mime_type' => 'image/png', 'size_bytes' => 4096,
        ]);

        $html = $this->renderBody('admin-views.app-builder.media', [
            'channel' => 'customer_app',
            'theme' => $this->theme->load('assets'),
            'assetsReady' => true,
            'maxAssetSize' => \App\Services\Theme\ThemeAssetService::maxBytes(),
            'editable' => true,
        ]);

        $this->assertStringContainsString('Header logo', $html, 'the uploaded image is listed');
        $this->assertStringContainsString('admin/theme/asset/upload', $html, 'uploading uses the theme\'s own action');
    }

    public function test_the_app_builder_templates_screen_renders_every_preset(): void
    {
        $html = $this->renderBody('admin-views.app-builder.templates', [
            'channel' => 'customer_app',
            'theme' => $this->theme,
            'presets' => app(\App\Services\Theme\ThemePortabilityService::class)->presets(),
            'exportable' => $this->draft,
            'editable' => true,
        ]);

        foreach (app(\App\Services\Theme\ThemePortabilityService::class)->presets() as $key => $preset) {
            $this->assertStringContainsString('value="' . $key . '"', $html, $key . ' can be applied');
        }
        $this->assertStringContainsString('admin/theme/import', $html, 'importing uses the existing action');
    }

    public function test_the_collections_screen_renders_with_a_merchandised_collection(): void
    {
        Schema::dropIfExists('product_collections');
        Schema::create('product_collections', function (Blueprint $table) {
            $table->id(); $table->string('name', 120); $table->string('slug', 60)->unique();
            $table->boolean('status')->default(true); $table->json('rules')->nullable();
            $table->string('sort_by', 40)->default('sales_30d');
            $table->json('merchandising')->nullable(); $table->timestamps();
        });
        $collection = \App\Models\ProductCollection::create([
            'name' => 'Winter picks', 'slug' => 'winter-picks', 'status' => true,
            'rules' => [['field' => 'price', 'operator' => 'less_than', 'value' => 50]],
            'sort_by' => 'sales_30d',
            'merchandising' => ['pins' => [['id' => 3, 'position' => 1]], 'min_items' => 4,
                                'fallback' => ['kind' => 'source', 'source' => 'featured']],
        ]);

        $html = $this->renderBody('admin-views.commerce.collections', [
            'ready' => true, 'enabled' => true,
            'collections' => collect([$collection]),
            'fields' => \App\Services\Commerce\CollectionRuleRegistry::FIELDS,
            'sorts' => \App\Services\Commerce\CollectionRuleRegistry::SORTS,
            'boostKinds' => \App\Services\Commerce\MerchandisingRules::BOOST_KINDS,
            'fallbackSources' => \App\Services\Commerce\MerchandisingRules::FALLBACK_SOURCES,
            'editable' => true, 'metricsAge' => now()->toDateTimeString(),
        ]);

        $this->assertStringContainsString('Winter picks', $html);
        $this->assertStringContainsString('cx-edit', $html, 'a collection can be edited in place');
        $this->assertStringContainsString('admin/commerce/collections/store', $html);
    }

    public function test_the_campaigns_screen_renders(): void
    {
        $this->commerceTables();
        $campaign = \App\Models\ExperienceCampaign::create([
            'name' => 'Ramadan Sale', 'status' => 'active', 'page' => 'home', 'priority' => 80,
            'overrides' => [['slot' => 'hero', 'section' => ['type' => 'hero_banner', 'settings' => []]]],
        ]);

        $html = $this->renderBody('admin-views.commerce.campaigns', [
            'ready' => true, 'enabled' => true,
            'campaigns' => collect([$campaign]),
            'reach' => app(\App\Services\Theme\SectionReach::class),
            'pages' => ['home'], 'slots' => \App\Services\Commerce\CampaignRules::SLOTS,
            'editable' => true, 'schedulerOk' => true,
        ]);

        $this->assertStringContainsString('Ramadan Sale', $html);
    }

    public function test_the_segments_screen_renders(): void
    {
        $this->commerceTables();
        \App\Models\CustomerSegment::create([
            'name' => 'Repeat buyer', 'key' => 'repeat-buyer', 'status' => true,
            'rules' => [['field' => 'orders_count', 'operator' => 'greater_than_or_equal', 'value' => 2]],
        ]);

        $html = $this->renderBody('admin-views.commerce.segments', [
            'ready' => true, 'enabled' => true,
            'segments' => \App\Models\CustomerSegment::query()->get(),
            'fields' => \App\Services\Commerce\SegmentRules::FIELDS,
            'operators' => \App\Services\Commerce\SegmentRules::OPERATORS,
            'editable' => true,
        ]);

        $this->assertStringContainsString('Repeat buyer', $html);
    }

    public function test_the_experiments_screen_renders(): void
    {
        $this->commerceTables();
        \App\Models\ExperienceExperiment::create([
            'name' => 'Hero copy', 'key' => 'hero-copy', 'status' => 'running', 'page' => 'home',
            'section_uuid' => 'u-1', 'variants' => [['key' => 'b', 'weight' => 50, 'settings' => []]],
        ]);

        $html = $this->renderBody('admin-views.commerce.experiments', [
            'ready' => true, 'enabled' => true,
            'experiments' => \App\Models\ExperienceExperiment::query()->get(),
            'reach' => app(\App\Services\Commerce\ExperimentReach::class),
            'sections' => [], 'editable' => true,
        ]);

        $this->assertStringContainsString('Hero copy', $html);
    }

    private function commerceTables(): void
    {
        foreach (['experience_campaigns', 'customer_segments', 'experience_experiments'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('experience_campaigns', function (Blueprint $table) {
            $table->id(); $table->string('name', 120); $table->string('status', 20)->default('draft');
            $table->string('page', 60)->default('home'); $table->unsignedInteger('priority')->default(30);
            $table->timestamp('starts_at')->nullable(); $table->timestamp('ends_at')->nullable();
            $table->json('overrides')->nullable(); $table->timestamps();
        });
        Schema::create('customer_segments', function (Blueprint $table) {
            $table->id(); $table->string('name', 120); $table->string('key', 60)->unique();
            $table->boolean('status')->default(true); $table->json('rules')->nullable(); $table->timestamps();
        });
        Schema::create('experience_experiments', function (Blueprint $table) {
            $table->id(); $table->string('name', 120); $table->string('key', 60)->unique();
            $table->string('status', 20)->default('draft'); $table->string('page', 60)->default('home');
            $table->uuid('section_uuid')->nullable(); $table->json('variants')->nullable(); $table->timestamps();
        });
    }

    public function test_the_experience_health_screen_renders_findings_and_previews(): void
    {
        $html = $this->renderBody('admin-views.app-builder.health', [
            'channel' => 'customer_app',
            'findings' => [
                ['key' => 'x', 'severity' => 'critical',
                 'label' => 'a_live_section_is_sourced_from_a_collection_that_was_deleted_or_disabled',
                 'detail' => 'home · product_slider · #2'],
            ],
            'infra' => [
                ['key' => 'scheduler', 'ok' => false, 'label' => 'the_scheduler_is_running',
                 'why' => 'x', 'fix' => '* * * * * php artisan schedule:run'],
            ],
            'overrides' => [
                ['slot' => 'hero', 'section' => ['type' => 'hero_banner', 'settings' => []], 'campaign_id' => 7],
            ],
            'campaignNames' => [7 => 'Ramadan Sale'],
            'at' => null,
            'experiments' => collect(),
            'segments' => ['repeat-buyer' => 'Repeat buyer'],
            'asSegment' => '',
            'segmentPreview' => null,
        ]);

        $this->assertStringContainsString('home · product_slider · #2', $html);
        $this->assertStringContainsString('Ramadan Sale', $html, 'the decision trace names the campaign');
        $this->assertStringContainsString('name="as_segment"', $html, 'segment preview is offered');
    }

    private function renderBody(string $view, array $data): string
    {
        $source = File::get(resource_path('views/' . str_replace('.', '/', $view) . '.blade.php'));

        $source = preg_replace('/@extends\([^)]*\)/', '', $source, 1);
        $source = preg_replace("/@section\('title'.*?\)/", '', $source, 1);
        $source = preg_replace('/@push\([^)]*\).*?@endpush/s', '', $source);
        $source = str_replace("@section('content')", '', $source);
        $source = preg_replace('/@endsection\b/', '', $source);

        $probe = resource_path('views/admin-views/theme/__render_probe.blade.php');
        File::put($probe, $source);

        try {
            $html = view('admin-views.theme.__render_probe', $data)->render();
        } finally {
            File::delete($probe);
        }

        $this->assertNothingRepeats($html, $view);

        return $html;
    }

    /**
     * No screen may offer the same choice twice or collect the same field twice.
     *
     * Run on EVERY render this suite performs, because duplication is exactly the class of bug
     * that arrives through a merge: two branches add the same block at nearby lines, git keeps
     * both, every test that checks "the option is there" still passes — and the merchant sees
     * the option twice. Uniqueness has to be asserted, not implied.
     */
    private function assertNothingRepeats(string $html, string $view): void
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        libxml_clear_errors();

        foreach ($document->getElementsByTagName('select') as $select) {
            $values = [];
            foreach ($select->getElementsByTagName('option') as $option) {
                $value = $option->getAttribute('value') !== '' ? $option->getAttribute('value') : trim($option->textContent);
                if ($value !== '') {
                    $values[] = $value;
                }
            }
            $repeated = array_filter(array_count_values($values), fn (int $count) => $count > 1);
            $this->assertSame([], $repeated,
                $view . ': a select repeats these options: ' . implode(', ', array_keys($repeated)));
        }

        foreach ($document->getElementsByTagName('form') as $form) {
            $names = [];
            foreach (['input', 'select', 'textarea'] as $tag) {
                foreach ($form->getElementsByTagName($tag) as $field) {
                    $name = $field->getAttribute('name');
                    // Array fields repeat by design; checkbox+hidden pairs are the Laravel idiom
                    // for "unchecked still submits".
                    if ($name === '' || $name === '_token' || str_ends_with($name, '[]')) {
                        continue;
                    }
                    if ($field->getAttribute('type') === 'hidden'
                        || ($tag === 'input' && $field->getAttribute('type') === 'checkbox')) {
                        continue;
                    }
                    $names[] = $name;
                }
            }
            $repeated = array_filter(array_count_values($names), fn (int $count) => $count > 1);
            $this->assertSame([], $repeated,
                $view . ': a form collects these fields twice: ' . implode(', ', array_keys($repeated)));
        }
    }
}
