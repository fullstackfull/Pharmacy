<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Theme;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\SectionDataResolver;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\ThemeBannerLink;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesCatalogueSchema;
use Tests\TestCase;

/**
 * The smart link between the Theme Builder's banner blocks and Promotion -> Banners.
 *
 * Contract under test: a block with a banner_id renders that banner's data LIVE (so an edit in
 * Banner Setup reaches the storefront), an unpublished linked banner hides its card, and an image
 * uploaded straight in the builder is auto-registered as a "Theme Banner" row so Banner Setup
 * always lists what the theme shows.
 */
class ThemeBannerLinkTest extends TestCase
{
    use CreatesCatalogueSchema;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->createCatalogueSchema();
        Banner::query()->delete();

        foreach (['theme_blocks', 'theme_sections', 'theme_versions', 'themes'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('themes', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug', 120)->unique();
            $t->boolean('is_active')->default(false);
            $t->boolean('is_system')->default(false);
            $t->string('description', 500)->nullable();
            $t->string('preview_image', 2048)->nullable();
            $t->string('created_by_type', 40)->nullable();
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });
        Schema::create('theme_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('theme_id');
            $t->string('label')->nullable();
            $t->string('status', 20)->default('draft');
            $t->json('settings')->nullable();
            $t->unsignedBigInteger('based_on_version_id')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
        Schema::create('theme_sections', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('theme_version_id');
            $t->string('page', 60)->default('home');
            $t->string('type', 80);
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_visible')->default(true);
            $t->json('settings')->nullable();
            $t->timestamps();
        });
        Schema::create('theme_blocks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('theme_section_id');
            $t->string('type', 80);
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_visible')->default(true);
            $t->json('settings')->nullable();
            $t->timestamps();
        });
    }

    private function makeBanner(array $overrides = []): Banner
    {
        return Banner::create(array_merge([
            'banner_type' => 'Theme Banner',
            'theme' => 'default',
            'published' => 1,
            'resource_type' => 'custom',
            'url' => 'https://example.test/campaign',
            'photo' => 'linked.webp',
            'title' => 'Linked title',
        ], $overrides));
    }

    public function test_a_linked_banner_overrides_the_block_card_live(): void
    {
        $banner = $this->makeBanner();

        $cards = app(SectionDataResolver::class)->blockCards([[
            'type' => 'banner',
            'settings' => ['banner_id' => $banner->id, 'image' => 'stale.png', 'link' => 'https://old.example'],
        ]]);

        $this->assertCount(1, $cards);
        $this->assertSame('https://example.test/campaign', $cards[0]['link']);
        $this->assertSame('Linked title', $cards[0]['title']);
        $this->assertIsString($cards[0]['image']);
        $this->assertStringNotContainsString('stale.png', $cards[0]['image']);
    }

    public function test_an_unpublished_linked_banner_hides_its_card(): void
    {
        $banner = $this->makeBanner(['published' => 0]);

        $cards = app(SectionDataResolver::class)->blockCards([
            ['type' => 'banner', 'settings' => ['banner_id' => $banner->id, 'image' => 'x.png']],
            ['type' => 'banner', 'settings' => ['image' => 'still-there.png']],
        ]);

        $this->assertCount(1, $cards);
        $this->assertSame('still-there.png', $cards[0]['image']);
    }

    public function test_a_deleted_linked_banner_hides_its_card_too(): void
    {
        // Deleting the banner in Banner Setup IS removing it from the theme — falling back to a
        // stale copied image would resurrect content the merchant explicitly deleted.
        $cards = app(SectionDataResolver::class)->blockCards([
            ['type' => 'banner', 'settings' => ['banner_id' => 424242, 'image' => 'stale-copy.png']],
            ['type' => 'banner', 'settings' => ['image' => 'unlinked-stays.png']],
        ]);

        $this->assertCount(1, $cards);
        $this->assertSame('unlinked-stays.png', $cards[0]['image']);
    }

    public function test_sync_version_registers_blocks_composed_before_the_smart_link(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('theme-assets/1/old-mosaic.webp', 'bytes');
        $url = Storage::disk('public')->url('theme-assets/1/old-mosaic.webp');

        $theme = Theme::create(['name' => 'Default', 'slug' => 'default', 'is_active' => true]);
        $version = ThemeVersion::create(['theme_id' => $theme->id, 'status' => 'draft']);
        $section = ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'banner_mosaic']);
        $tile = ThemeBlock::create([
            'theme_section_id' => $section->id,
            'type' => 'mosaic_tile',
            'settings' => ['image' => $url, 'title' => 'قديم'],
        ]);

        app(ThemeBannerLink::class)->syncVersion($version);

        $banner = Banner::where('banner_type', ThemeBannerLink::THEME_BANNER_TYPE)->first();
        $this->assertNotNull($banner, 'sweep should register pre-existing mosaic tiles');
        $this->assertSame($banner->id, (int) $tile->fresh()->settings['banner_id']);
    }

    public function test_the_url_match_survives_a_host_mismatch(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('theme-assets/1/x.webp', 'bytes');

        $theme = Theme::create(['name' => 'Default', 'slug' => 'default', 'is_active' => true]);
        $version = ThemeVersion::create(['theme_id' => $theme->id, 'status' => 'draft']);
        $section = ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'promotional_banner']);
        // Same storage path, but the URL carries a different host (www / APP_URL drift).
        $block = ThemeBlock::create([
            'theme_section_id' => $section->id,
            'type' => 'banner',
            'settings' => ['image' => 'https://www.some-other-host.example/storage/theme-assets/1/x.webp'],
        ]);

        app(ThemeBannerLink::class)->syncBlock($block);

        $this->assertSame(1, Banner::where('banner_type', ThemeBannerLink::THEME_BANNER_TYPE)->count());
        $this->assertNotEmpty($block->fresh()->settings['banner_id'] ?? null);
    }

    public function test_banner_id_is_coerced_to_a_positive_int_or_null(): void
    {
        $registry = app(SectionRegistry::class);

        $this->assertSame(7, $registry->normalizeBlockSettings('banner', ['banner_id' => '7'])['banner_id']);
        $this->assertNull($registry->normalizeBlockSettings('banner', ['banner_id' => 'DROP TABLE'])['banner_id']);
        $this->assertNull($registry->normalizeBlockSettings('banner', ['banner_id' => -3])['banner_id']);
        $this->assertNull($registry->normalizeBlockSettings('banner', [])['banner_id']);
    }

    public function test_a_builder_uploaded_image_is_registered_in_banner_setup_and_linked_back(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('theme-assets/1/campaign.webp', 'img-bytes');
        $url = Storage::disk('public')->url('theme-assets/1/campaign.webp');

        $theme = Theme::create(['name' => 'Default', 'slug' => 'default', 'is_active' => true]);
        $version = ThemeVersion::create(['theme_id' => $theme->id, 'status' => 'draft']);
        $section = ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'promotional_banner']);
        $block = ThemeBlock::create([
            'theme_section_id' => $section->id,
            'type' => 'banner',
            'settings' => ['image' => $url, 'link' => 'https://example.test/sale', 'title' => 'Big sale'],
        ]);

        app(ThemeBannerLink::class)->syncBlock($block);

        $banner = Banner::where('banner_type', ThemeBannerLink::THEME_BANNER_TYPE)->first();
        $this->assertNotNull($banner, 'the builder image should be registered as a Theme Banner');
        $this->assertSame('https://example.test/sale', $banner->url);
        $this->assertSame('Big sale', $banner->title);
        $this->assertSame(1, (int) $banner->published);
        $this->assertTrue(Storage::disk('public')->exists('banner/' . $banner->photo));

        $this->assertSame($banner->id, (int) $block->fresh()->settings['banner_id']);
    }

    public function test_a_foreign_image_url_is_not_registered(): void
    {
        Storage::fake('public');

        $theme = Theme::create(['name' => 'Default', 'slug' => 'default', 'is_active' => true]);
        $version = ThemeVersion::create(['theme_id' => $theme->id, 'status' => 'draft']);
        $section = ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'promotional_banner']);
        $block = ThemeBlock::create([
            'theme_section_id' => $section->id,
            'type' => 'banner',
            'settings' => ['image' => 'https://cdn.elsewhere.example/x.png'],
        ]);

        app(ThemeBannerLink::class)->syncBlock($block);

        $this->assertSame(0, Banner::count());
        $this->assertArrayNotHasKey('banner_id', array_filter($block->fresh()->settings ?? []));
    }

    public function test_usage_reports_linked_blocks_and_store_banner_sections(): void
    {
        $banner = $this->makeBanner();

        $theme = Theme::create(['name' => 'Default', 'slug' => 'default', 'is_active' => true]);
        $version = ThemeVersion::create(['theme_id' => $theme->id, 'status' => 'published']);
        $section = ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'hero_banner']);
        ThemeBlock::create([
            'theme_section_id' => $section->id,
            'type' => 'slide',
            'settings' => ['banner_id' => $banner->id],
        ]);
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'store_banner',
            'settings' => ['banner_type' => 'Main Banner'],
        ]);

        $usage = app(ThemeBannerLink::class)->usage();

        $this->assertArrayHasKey($banner->id, $usage['ids']);
        $this->assertArrayHasKey('Main Banner', $usage['types']);
    }
}
