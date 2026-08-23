<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Theme;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The theme API for the mobile app, and the two lies it must never tell.
 *
 * It must never serve anything but the PUBLISHED version — an integrator who hardcodes a version id
 * gets a layout that goes stale on the next publish, which is exactly the failure this endpoint
 * exists to replace. And its cards must agree with the web storefront about every banner-backed
 * block: same live override from Banner Setup, same disappearance when the banner is unpublished.
 */
class ThemeSectionApiTest extends TestCase
{
    private Theme $theme;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        foreach (['translations', 'storages', 'banners', 'theme_blocks', 'theme_sections', 'theme_versions', 'themes', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        // Banner::saved() records which disk each photo lives on; without the table the save throws.
        Schema::create('storages', function (Blueprint $table) {
            $table->id(); $table->string('data_type')->nullable(); $table->unsignedBigInteger('data_id')->nullable();
            $table->string('key')->nullable(); $table->string('value')->nullable(); $table->timestamps();
        });
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id(); $table->string('type')->nullable(); $table->text('value')->nullable(); $table->timestamps();
        });
        Schema::create('themes', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(false); $table->boolean('is_system')->default(false);
            $table->string('description', 500)->nullable(); $table->string('preview_image', 2048)->nullable();
            $table->string('created_by_type', 40)->nullable(); $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
        });
        Schema::create('theme_versions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_id'); $table->string('label')->nullable();
            $table->string('status', 20)->default('draft'); $table->json('settings')->nullable();
            $table->unsignedBigInteger('based_on_version_id')->nullable(); $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('theme_sections', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_version_id'); $table->string('page', 60)->default('home');
            $table->string('type', 80); $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true); $table->json('settings')->nullable(); $table->timestamps();
        });
        Schema::create('theme_blocks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_section_id'); $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable(); $table->timestamps();
        });
        // Category's global scope eager-loads its translations on EVERY query; without the table
        // the target lookup throws (and safely() would turn a real category into a url fallback).
        Schema::create('translations', function (Blueprint $table) {
            $table->id(); $table->string('translationable_type')->nullable(); $table->unsignedBigInteger('translationable_id')->nullable();
            $table->string('locale')->nullable(); $table->string('key')->nullable(); $table->text('value')->nullable(); $table->timestamps();
        });
        Schema::create('categories', function (Blueprint $table) {
            $table->id(); $table->string('name')->nullable(); $table->string('slug')->nullable(); $table->timestamps();
        });
        Schema::create('banners', function (Blueprint $table) {
            $table->id(); $table->string('photo')->nullable(); $table->string('mobile_photo')->nullable();
            $table->string('banner_type')->nullable(); $table->string('layout')->nullable();
            $table->integer('priority')->nullable(); $table->string('theme')->nullable();
            $table->boolean('published')->default(0); $table->string('url', 2048)->nullable();
            $table->string('resource_type')->nullable(); $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('title')->nullable(); $table->string('sub_title')->nullable();
            $table->string('button_text')->nullable(); $table->string('background_color')->nullable();
            $table->timestamps();
        });

        $this->theme = Theme::create(['name' => 'T', 'slug' => 't', 'is_active' => true]);
    }

    private function mosaic(string $versionStatus, array $tileSettings = []): ThemeBlock
    {
        $version = ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => $versionStatus]);
        $section = ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'banner_mosaic',
            'sort_order' => 1, 'is_visible' => true, 'settings' => ['height' => 240, 'gap' => 16],
        ]);

        return ThemeBlock::create([
            'theme_section_id' => $section->id, 'type' => 'mosaic_tile', 'sort_order' => 1, 'is_visible' => true,
            'settings' => array_merge(['image' => '/storage/app/public/theme/tile.webp', 'title' => 'From the block'], $tileSettings),
        ]);
    }

    public function test_only_the_published_version_is_served(): void
    {
        $this->mosaic(ThemeVersion::STATUS_DRAFT);

        $this->getJson('/api/v1/theme/sections?page=home')
            ->assertOk()
            ->assertJson(['page' => 'home', 'sections' => []]);
    }

    public function test_a_mosaic_arrives_with_absolute_image_urls(): void
    {
        $this->mosaic(ThemeVersion::STATUS_PUBLISHED);

        $response = $this->getJson('/api/v1/theme/sections?type=banner_mosaic')->assertOk();
        $card = $response->json('sections.0.cards.0');

        $this->assertSame('From the block', $card['title']);
        $this->assertStringStartsWith('http', $card['image'], 'a phone has no origin to resolve /storage against');
    }

    public function test_a_linked_banner_wins_over_the_block_and_unpublishing_hides_the_tile(): void
    {
        // The whole point of managing every image in Banner Setup: the tile renders the BANNER's
        // data, live, and unpublishing there removes it from the phone without touching the theme.
        $banner = Banner::create([
            'photo' => 'from-setup.png', 'banner_type' => 'Theme Banner', 'published' => 1,
            'url' => '/brands', 'title' => 'From Banner Setup', 'resource_type' => 'custom',
        ]);
        $this->mosaic(ThemeVersion::STATUS_PUBLISHED, ['banner_id' => $banner->id]);

        $card = $this->getJson('/api/v1/theme/sections?type=banner_mosaic')->json('sections.0.cards.0');
        $this->assertSame('From Banner Setup', $card['title']);
        $this->assertSame('/brands', $card['link']);

        $banner->update(['published' => 0]);
        Cache::flush();

        $section = $this->getJson('/api/v1/theme/sections?type=banner_mosaic')->json('sections.0');
        $this->assertCount(1, $section['blocks'], 'the block still exists for the builder');
        $this->assertCount(0, $section['cards'], 'but the phone must not render an unpublished banner');
    }

    public function test_every_section_names_where_its_data_lives(): void
    {
        // The app's home renderer is one loop over sections; `source` is what lets it follow the
        // merchant's arrangement without hardcoding which rail calls which endpoint.
        $version = ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED]);

        $slider = ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'product_slider',
            'sort_order' => 1, 'is_visible' => true,
            'settings' => ['source' => 'best_selling', 'limit' => 6],
        ]);
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'faq',
            'sort_order' => 2, 'is_visible' => true, 'settings' => [],
        ]);
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'blog_posts',
            'sort_order' => 3, 'is_visible' => true, 'settings' => [],
        ]);

        $sections = $this->getJson('/api/v1/theme/sections?page=home')->assertOk()->json('sections');
        $byType = collect($sections)->keyBy('type');

        $this->assertSame('api', $byType['product_slider']['source']['kind']);
        $this->assertSame('/api/v1/products/best-sellings', $byType['product_slider']['source']['endpoint']);
        $this->assertSame(6, $byType['product_slider']['source']['params']['limit'], 'the merchant\'s limit travels');

        $this->assertSame('inline', $byType['faq']['source']['kind']);

        // A section with no API behind it says so, so an absent rail is a known gap, not a mystery.
        $this->assertSame('none', $byType['blog_posts']['source']['kind']);
        $this->assertNotEmpty($byType['blog_posts']['source']['note']);
    }

    public function test_a_hand_picked_rail_and_a_brand_rail_both_name_a_real_endpoint(): void
    {
        // These two were shipped as kind "none" — the brand endpoint existed all along (the route
        // is nested inside the brands prefix, which the survey grepped past), and by-ids exists
        // now. A source hint must carry the merchant's exact picks, order included.
        $version = ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED]);

        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'product_slider',
            'sort_order' => 1, 'is_visible' => true,
            'settings' => ['source' => 'manual', 'product_ids' => [7, 3, 9]],
        ]);
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'product_slider',
            'sort_order' => 2, 'is_visible' => true,
            'settings' => ['source' => 'brand', 'source_id' => 4, 'limit' => 8],
        ]);
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'product_slider',
            'sort_order' => 3, 'is_visible' => true,
            'settings' => ['source' => 'category', 'source_id' => 11, 'limit' => 8],
        ]);

        $sources = array_column($this->getJson('/api/v1/theme/sections?page=home')->json('sections'), 'source');

        $this->assertSame('/api/v1/products/by-ids', $sources[0]['endpoint']);
        $this->assertSame('7,3,9', $sources[0]['params']['ids'], 'the picked order IS the contract');

        $this->assertSame('/api/v1/brands/products/4', $sources[1]['endpoint']);

        // The picker stores the chosen category in source_id; an earlier map read category_id — a
        // key the schema never stores — and pointed every category rail at /products/0.
        $this->assertSame('/api/v1/categories/products/11', $sources[2]['endpoint']);
    }

    public function test_responsive_settings_arrive_resolved_for_the_phone(): void
    {
        $version = ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED]);
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'banner_mosaic',
            'sort_order' => 1, 'is_visible' => true,
            'settings' => ['height' => 240, 'height_mobile' => 140, 'gap' => 16],
        ]);

        $settings = $this->getJson('/api/v1/theme/sections?type=banner_mosaic')->json('sections.0.settings');

        $this->assertSame(140, $settings['height'], 'this endpoint has exactly one breakpoint, and it is not desktop');
        $this->assertSame(140, $settings['height_mobile'], 'the sibling stays for a client that wants the full picture');
    }

    public function test_every_card_says_what_tapping_it_opens(): void
    {
        // "This tile is linked, but to WHAT?" was unanswerable from the API — a card carried only
        // a URL path for the app to reverse-engineer. The target is now structural: from the
        // linked banner's own resource picker first, from the storefront's URL shapes second.
        \Illuminate\Support\Facades\DB::table('categories')->insert(['id' => 9, 'name' => 'Vitamins', 'slug' => 'vitamins']);

        $banner = Banner::create([
            'photo' => 'x.png', 'banner_type' => 'Theme Banner', 'published' => 1,
            'url' => '/category/vitamins', 'resource_type' => 'category', 'resource_id' => 9,
        ]);

        $version = ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED]);
        $section = ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'banner_mosaic',
            'sort_order' => 1, 'is_visible' => true, 'settings' => [],
        ]);

        foreach ([
            ['banner_id' => $banner->id, 'image' => '/storage/a.webp'],                          // banner resource
            ['image' => '/storage/b.webp', 'link' => '/category/vitamins', 'title' => 'ti'],     // parsed from the URL
            ['image' => '/storage/c.webp', 'link' => '/brands', 'title' => 'tj'],                // a list page: plain url
            ['image' => '/storage/d.webp', 'title' => 'tk'],                                     // opens nothing
            ['image' => '/storage/e.webp', 'link' => '/category/deleted-slug', 'title' => 'tl'], // a ghost
        ] as $order => $settings) {
            ThemeBlock::create([
                'theme_section_id' => $section->id, 'type' => 'mosaic_tile',
                'sort_order' => $order, 'is_visible' => true, 'settings' => $settings,
            ]);
        }

        $targets = array_column($this->getJson('/api/v1/theme/sections?type=banner_mosaic')->json('sections.0.cards'), 'target');

        $this->assertSame(['kind' => 'category', 'id' => 9, 'slug' => 'vitamins', 'name' => 'Vitamins'], $targets[0]);
        $this->assertSame('category', $targets[1]['kind']);
        $this->assertSame(9, $targets[1]['id'], 'a slug-shaped link resolves to the same identity');
        $this->assertSame('url', $targets[2]['kind'], 'a list page is honestly a url, not a guessed brand');
        $this->assertSame('none', $targets[3]['kind']);
        $this->assertSame('url', $targets[4]['kind'], 'a link to something deleted must not present a resolvable target');
    }

    public function test_input_nobody_can_spell_falls_back_instead_of_failing(): void
    {
        $this->mosaic(ThemeVersion::STATUS_PUBLISHED);

        $this->getJson('/api/v1/theme/sections?page[]=x&type[]=y')->assertOk()->assertJsonPath('page', 'home');
        $this->getJson('/api/v1/theme/sections?page=nonsense')->assertOk()->assertJsonPath('page', 'home');
    }
}
