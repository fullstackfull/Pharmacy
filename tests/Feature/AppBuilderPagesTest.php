<?php

namespace Tests\Feature;

use App\Models\ExperiencePage;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\Channel;
use App\Services\Theme\ExperiencePageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Home as one page among several, and a page as something a merchant can actually make.
 *
 * The page table and its service were built earlier and then read by nothing: the builder listed
 * three page names written into a template, the API validated against three names written into a
 * controller, and a page created through the service could be composed by nobody and fetched by
 * nothing. This is that half — the wiring — and what it has to keep true.
 *
 * The rule that matters most is the shared one. Home, header and footer belong to every channel,
 * because they are one arrangement the web and the app both read; duplicating them per channel
 * would be duplicating the engine, which is the thing this architecture exists to avoid. A page
 * made FOR the app is the app's alone.
 */
class AppBuilderPagesTest extends TestCase
{
    private Theme $theme;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        session(['local' => 'en']);

        foreach (['theme_blocks', 'theme_sections', 'theme_versions', 'experience_pages', 'themes'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('themes', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(false); $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
        Schema::create('experience_pages', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_id');
            $table->string('channel', 40)->default('shared'); $table->string('slug', 60);
            $table->string('title', 120); $table->string('kind', 20)->default('custom');
            $table->boolean('is_enabled')->default(true); $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['theme_id', 'channel', 'slug']);
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
            $table->id(); $table->unsignedBigInteger('theme_version_id');
            $table->unsignedBigInteger('experience_page_id')->nullable();
            $table->uuid('uuid')->nullable();
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
        app(ExperiencePageService::class)->ensureSystemPages($this->theme);
    }

    public function test_the_pages_the_engine_guarantees_exist_without_anybody_creating_them(): void
    {
        $slugs = array_column(
            app(ExperiencePageService::class)->forChannel($this->theme->id, Channel::CUSTOMER_APP),
            'slug',
        );

        $this->assertSame(ExperiencePage::SYSTEM_SLUGS, $slugs);
    }

    public function test_the_built_in_pages_belong_to_every_channel(): void
    {
        // One arrangement the web and the app both read. A per-channel copy of the home page would
        // be a second engine wearing the first one's name.
        $pages = app(ExperiencePageService::class);

        foreach ([Channel::WEB, Channel::CUSTOMER_APP] as $channel) {
            $this->assertContains('home', array_column($pages->forChannel($this->theme->id, $channel), 'slug'));
        }
    }

    public function test_a_page_made_for_the_app_is_the_apps_alone(): void
    {
        $pages = app(ExperiencePageService::class);
        $pages->create($this->theme, 'Offers', channel: Channel::CUSTOMER_APP);

        $this->assertContains('offers',
            array_column($pages->forChannel($this->theme->id, Channel::CUSTOMER_APP), 'slug'));
        $this->assertNotContains('offers',
            array_column($pages->forChannel($this->theme->id, Channel::WEB), 'slug'),
            'a page the merchant made for the app has no business on the website');
    }

    public function test_a_page_the_merchant_turned_off_is_not_served(): void
    {
        // Turning a page off is how it leaves the app without being destroyed — the sections on it
        // are still there, and turning it back on brings them back.
        $pages = app(ExperiencePageService::class);
        $offers = $pages->create($this->theme, 'Offers', channel: Channel::CUSTOMER_APP);

        $this->assertContains('offers', $pages->servableSlugs($this->theme->id, Channel::CUSTOMER_APP));

        $pages->update($offers, enabled: false);

        $this->assertNotContains('offers', $pages->servableSlugs($this->theme->id, Channel::CUSTOMER_APP));
        $this->assertContains('offers',
            array_column($pages->forChannel($this->theme->id, Channel::CUSTOMER_APP), 'slug'),
            'and it is still listed for its owner, who turned it off rather than losing it');
    }

    public function test_a_built_in_page_cannot_be_turned_off_or_deleted(): void
    {
        // The storefront and the app look for these by name. A merchant who could remove `home`
        // would take the shop's front page away with one click.
        $pages = app(ExperiencePageService::class);
        $home = $pages->find($this->theme->id, 'home');

        $this->assertFalse($pages->update($home, enabled: false));
        $this->assertFalse($pages->delete($home));
        $this->assertTrue($pages->find($this->theme->id, 'home')->is_enabled);
    }

    public function test_a_built_in_page_can_still_be_renamed(): void
    {
        // The slug is the contract; the title is the merchant's word for it.
        $pages = app(ExperiencePageService::class);
        $home = $pages->find($this->theme->id, 'home');

        $this->assertTrue($pages->update($home, title: 'Front page'));
        $this->assertSame('Front page', $pages->find($this->theme->id, 'home')->title);
        $this->assertSame('home', $pages->find($this->theme->id, 'home')->slug);
    }

    public function test_the_api_serves_a_page_the_merchant_created(): void
    {
        // The whole point of the wiring: a page nobody could fetch is a page that does not exist.
        $pages = app(ExperiencePageService::class);
        $pages->create($this->theme, 'Offers', channel: Channel::CUSTOMER_APP);

        $version = ThemeVersion::create([
            'theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED, 'revision' => 1,
        ]);
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'offers', 'type' => 'spacer',
            'sort_order' => 1, 'is_visible' => true, 'settings' => ['height' => 24],
        ]);

        $payload = $this->getJson('/api/v1/theme/home?page=offers')->assertOk()->json();

        $this->assertSame('offers', $payload['page']);
        $this->assertSame('spacer', $payload['sections'][0]['type'] ?? null);
    }

    public function test_a_page_that_does_not_exist_falls_back_to_home(): void
    {
        // A public endpoint: a typo, a stale app build asking for a page that was removed, or
        // `?page[]=x` must be an ordinary home page rather than an error.
        $version = ThemeVersion::create([
            'theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED, 'revision' => 1,
        ]);
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'spacer',
            'sort_order' => 1, 'is_visible' => true, 'settings' => ['height' => 24],
        ]);

        $this->assertSame('home', $this->getJson('/api/v1/theme/home?page=nowhere')->assertOk()->json('page'));
        $this->assertSame('home', $this->getJson('/api/v1/theme/home?page[]=x')->assertOk()->json('page'));
    }

    public function test_a_disabled_page_is_not_reachable_from_the_app(): void
    {
        $pages = app(ExperiencePageService::class);
        $offers = $pages->create($this->theme, 'Offers', channel: Channel::CUSTOMER_APP);
        $pages->update($offers, enabled: false);

        $version = ThemeVersion::create([
            'theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED, 'revision' => 1,
        ]);
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'offers', 'type' => 'spacer',
            'sort_order' => 1, 'is_visible' => true, 'settings' => ['height' => 24],
        ]);

        $this->assertSame(
            'home',
            $this->getJson('/api/v1/theme/home?page=offers')->assertOk()->json('page'),
            'a page turned off is a page the app cannot ask for',
        );
    }
}
