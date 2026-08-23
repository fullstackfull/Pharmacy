<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\ActionResolver;
use App\Services\Theme\ComponentCapabilityRegistry;
use App\Services\Theme\SectionDataResolver;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\SectionVisibility;
use App\Services\Theme\ThemeDelivery;
use App\Services\Theme\ThemeManager;
use App\Services\Theme\ViewerContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The negotiated delivery path: revision identity, capability filtering, scheduling, targeting,
 * and the promise that a client can always ask "anything new?" for the price of a header.
 */
class ThemeDeliveryTest extends TestCase
{
    private ThemeDelivery $delivery;
    private Theme $theme;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        foreach (['theme_blocks', 'theme_sections', 'theme_versions', 'themes'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('theme_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('theme_id');
            $table->string('label')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('revision')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->json('settings')->nullable();
            $table->unsignedBigInteger('based_on_version_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('theme_sections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->unsignedBigInteger('theme_version_id');
            $table->string('page', 60)->default('home');
            $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('platforms')->nullable();
            $table->json('audience')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('theme_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('theme_section_id');
            $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        $this->delivery = new ThemeDelivery(
            new SectionRegistry(),
            app(SectionDataResolver::class),
            new SectionVisibility(),
            new ComponentCapabilityRegistry(),
            new ActionResolver(),
            new ThemeManager(),
        );

        $this->theme = Theme::create(['name' => 'Pharmacy', 'slug' => 'pharmacy', 'is_active' => true]);
    }

    private function publishedVersion(array $attributes = []): ThemeVersion
    {
        return ThemeVersion::create(array_merge([
            'theme_id' => $this->theme->id,
            'status' => ThemeVersion::STATUS_PUBLISHED,
            'revision' => 1,
            'checksum' => 'seed',
            'published_at' => now(),
        ], $attributes));
    }

    private function appViewer(array $components = [], int $engine = 0): ViewerContext
    {
        return new ViewerContext(
            platform: ViewerContext::PLATFORM_APP,
            device: ViewerContext::DEVICE_MOBILE,
            uiEngineVersion: $engine,
            supportedComponents: $components,
        );
    }

    // -- revision identity ---------------------------------------------------------------------

    public function test_revision_is_zero_when_nothing_is_published(): void
    {
        $this->assertSame(0, $this->delivery->revision()['revision']);
    }

    public function test_publish_stamps_a_monotonic_revision_and_checksum(): void
    {
        $manager = new ThemeManager();
        $draft = ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);
        ThemeSection::create(['theme_version_id' => $draft->id, 'page' => 'home', 'type' => 'spacer', 'settings' => ['height' => 40]]);

        $manager->publish($draft->refresh());
        $first = $draft->refresh();
        $this->assertSame(1, $first->revision);
        $this->assertNotNull($first->checksum);

        $second = $manager->createDraftFrom($first);
        $manager->publish($second);

        $this->assertSame(2, $second->refresh()->revision);
        $this->assertSame(
            $first->checksum,
            $second->refresh()->checksum,
            'identical content republished must keep its checksum, so clients holding it get a 304'
        );
    }

    public function test_rollback_gets_a_higher_revision_than_the_version_it_restores(): void
    {
        $manager = new ThemeManager();

        $v1 = ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);
        ThemeSection::create(['theme_version_id' => $v1->id, 'page' => 'home', 'type' => 'spacer', 'settings' => ['height' => 10]]);
        $manager->publish($v1->refresh());

        $v2 = $manager->createDraftFrom($v1->refresh());
        $v2->sections()->first()->update(['settings' => ['height' => 99]]);
        $manager->publish($v2);

        $restored = $manager->restoreVersion($v1->refresh());
        $manager->publish($restored);

        $this->assertSame(3, $restored->refresh()->revision,
            'a client comparing revisions must see the rollback as NEW, not as the old number');
    }

    // -- capability negotiation ----------------------------------------------------------------

    public function test_app_safe_sections_are_delivered_and_unsafe_ones_are_withheld_with_reasons(): void
    {
        $version = $this->publishedVersion();
        ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'product_slider', 'sort_order' => 1, 'settings' => ['source' => 'featured']]);
        ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'custom_html', 'sort_order' => 2, 'settings' => ['html' => '<b>x</b>']]);

        $payload = $this->delivery->payload('home', $this->appViewer());

        $this->assertSame(['product_slider'], array_column($payload['sections'], 'type'));
        $this->assertArrayHasKey('custom_html', $payload['compatibility']['withheld'],
            'a withheld type must be named, with why, so a thin page is explainable');
    }

    public function test_a_client_that_declares_capabilities_only_receives_what_it_lists(): void
    {
        $version = $this->publishedVersion();
        ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'product_slider', 'sort_order' => 1, 'settings' => []]);
        ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'spacer', 'sort_order' => 2, 'settings' => []]);

        $payload = $this->delivery->payload('home', $this->appViewer(components: ['spacer'], engine: 1));

        $this->assertSame(['spacer'], array_column($payload['sections'], 'type'));
        $this->assertArrayHasKey('product_slider', $payload['compatibility']['withheld']);
    }

    public function test_a_legacy_client_that_declares_nothing_gets_every_app_safe_section(): void
    {
        $version = $this->publishedVersion();
        ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'product_slider', 'settings' => []]);

        $payload = $this->delivery->payload('home', $this->appViewer());

        $this->assertCount(1, $payload['sections'],
            'builds predating capability reporting must keep working');
    }

    public function test_different_capability_sets_get_different_checksums(): void
    {
        $version = $this->publishedVersion();
        ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'product_slider', 'sort_order' => 1, 'settings' => []]);
        ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'spacer', 'sort_order' => 2, 'settings' => []]);

        $full = $this->delivery->payload('home', $this->appViewer());
        $narrow = $this->delivery->payload('home', $this->appViewer(components: ['spacer'], engine: 1));

        $this->assertNotSame($full['checksum'], $narrow['checksum'],
            'two clients holding different pages must never share an ETag');
    }

    // -- scheduling and targeting --------------------------------------------------------------

    public function test_a_scheduled_section_outside_its_window_is_not_delivered(): void
    {
        $version = $this->publishedVersion();
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'spacer',
            'settings' => [], 'starts_at' => now()->addDay(),
        ]);
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'spacer',
            'settings' => [], 'ends_at' => now()->subDay(),
        ]);
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'spacer',
            'settings' => [], 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);

        $payload = $this->delivery->payload('home', $this->appViewer());

        $this->assertCount(1, $payload['sections'], 'only the in-window section runs');
    }

    public function test_a_web_only_section_is_not_delivered_to_the_app(): void
    {
        $version = $this->publishedVersion();
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'spacer',
            'settings' => [], 'platforms' => ['web'],
        ]);

        $payload = $this->delivery->payload('home', $this->appViewer());

        $this->assertCount(0, $payload['sections']);
    }

    public function test_a_customer_only_section_is_hidden_from_guests(): void
    {
        $version = $this->publishedVersion();
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'spacer',
            'settings' => [], 'audience' => ['customer'],
        ]);

        $guest = $this->delivery->payload('home', $this->appViewer());
        $this->assertCount(0, $guest['sections']);

        $customer = $this->delivery->payload('home', new ViewerContext(
            platform: ViewerContext::PLATFORM_APP,
            device: ViewerContext::DEVICE_MOBILE,
            authenticated: true,
        ));
        $this->assertCount(1, $customer['sections']);
    }

    // -- shape ---------------------------------------------------------------------------------

    public function test_sections_carry_uuid_source_and_mobile_resolved_settings(): void
    {
        $version = $this->publishedVersion();
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'product_slider',
            'settings' => ['source' => 'best_selling', 'limit' => 6, 'columns' => 5, 'columns_mobile' => 2],
        ]);

        $payload = $this->delivery->payload('home', $this->appViewer());
        $section = $payload['sections'][0];

        $this->assertNotNull($section['uuid'], 'identity must survive publishes');
        $this->assertSame('api', $section['source']['kind']);
        $this->assertSame('/api/v1/products/best-sellings', $section['source']['endpoint']);
        $this->assertSame(2, $section['settings']['columns'], 'the mobile override wins on a phone');
    }

    public function test_links_arrive_as_typed_actions(): void
    {
        config(['app.url' => 'https://shop.test']);

        $version = $this->publishedVersion();
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'announcement_bar',
            'settings' => ['text' => 'Sale', 'link' => 'https://shop.test/product/aspirin-100mg'],
        ]);

        $payload = $this->delivery->payload('home', $this->appViewer());
        $action = $payload['sections'][0]['settings']['action'];

        $this->assertSame('product', $action['type']);
        $this->assertSame('aspirin-100mg', $action['slug']);
    }

    public function test_design_tokens_travel_with_the_page(): void
    {
        $this->publishedVersion(['settings' => ['colors' => ['primary' => '#123456']]]);

        $payload = $this->delivery->payload('home', $this->appViewer());

        $this->assertSame('#123456', $payload['tokens']['colors']['primary']);
        $this->assertArrayHasKey('layout', $payload['tokens']);
    }

    // -- action resolver contract --------------------------------------------------------------

    public function test_action_resolver_maps_storefront_urls(): void
    {
        config(['app.url' => 'https://shop.test']);
        $resolver = new ActionResolver();

        $this->assertSame('product', $resolver->resolve('/product/aspirin')['type']);
        $this->assertSame('category', $resolver->resolve('https://shop.test/category/vitamins')['type']);
        $this->assertSame('vendor', $resolver->resolve('/vendor-shop/main-branch')['type']);
        $this->assertSame('cart', $resolver->resolve('/shop-cart')['type']);
        $this->assertSame('none', $resolver->resolve('')['type']);
        $this->assertSame('none', $resolver->resolve('#')['type']);
        $this->assertSame('url', $resolver->resolve('https://other.example/product/x')['type'],
            'another site\'s product URL is external, never ours');

        $collection = $resolver->resolve('/best-selling-products');
        $this->assertSame('collection', $collection['type']);
        $this->assertSame('best_selling', $collection['collection']);
    }

    public function test_capability_registry_never_marks_custom_html_app_safe(): void
    {
        $registry = new ComponentCapabilityRegistry();

        $this->assertFalse($registry->isAppSafe('custom_html'),
            'arbitrary markup must never be declared renderable by the native client');
        $this->assertNotNull($registry->exclusionReason('custom_html'));
        $this->assertNull($registry->exclusionReason('product_slider'));
    }
}
