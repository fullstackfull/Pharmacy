<?php

namespace Tests\Feature;

use App\Models\ExperiencePage;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\Channel;
use App\Services\Theme\ComponentCapabilityRegistry;
use App\Services\Theme\ExperiencePageService;
use App\Services\Theme\SectionVisibility;
use App\Services\Theme\ThemeBuilderService;
use App\Services\Theme\ThemeManager;
use App\Services\Theme\ViewerContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The two foundations the experience builder is grown on: a page that is a row, and a channel that
 * is a value.
 *
 * Both were shapes before they were things — a page was one of three words in a varchar, and "which
 * surface" was answered in two unrelated places. Neither change is allowed to alter what a live
 * shop renders today, so most of what these tests pin is what did NOT change: the slug still
 * decides, an unrestricted section still shows everywhere, and a database that has not run the
 * migrations still saves.
 */
class ExperienceFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

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
            $table->string('title')->nullable(); $table->string('kind', 20)->default('custom');
            $table->boolean('is_enabled')->default(true); $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['theme_id', 'channel', 'slug']);
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
            $table->unsignedBigInteger('theme_version_id');
            $table->unsignedBigInteger('experience_page_id')->nullable();
            $table->string('page', 60)->default('home');
            $table->string('type', 80); $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamp('starts_at')->nullable(); $table->timestamp('ends_at')->nullable();
            $table->json('platforms')->nullable(); $table->json('audience')->nullable();
            $table->json('channels')->nullable();
            $table->json('settings')->nullable(); $table->timestamps();
        });
        Schema::create('theme_blocks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_section_id'); $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable(); $table->timestamps();
        });
    }

    private function theme(): Theme
    {
        return app(ThemeManager::class)->createTheme(['name' => 'Pharmacy', 'slug' => 'pharmacy-' . uniqid()]);
    }

    // -- pages ---------------------------------------------------------------------------------

    public function test_a_new_theme_owns_the_three_pages_the_clients_ask_for(): void
    {
        $theme = $this->theme();

        $slugs = app(ExperiencePageService::class)->forTheme($theme->id)->pluck('slug')->all();

        $this->assertSame(['home', 'header', 'footer'], $slugs);
        $this->assertTrue(
            app(ExperiencePageService::class)->find($theme->id, 'home')->isSystem(),
            'home is the engine\'s guarantee, not the merchant\'s chore'
        );
    }

    public function test_a_section_added_to_a_page_carries_that_page_and_its_slug(): void
    {
        $theme = $this->theme();
        $version = $theme->versions()->first() ?? ThemeVersion::create(['theme_id' => $theme->id, 'status' => 'draft']);

        $section = app(ThemeBuilderService::class)->addSection($version, 'home', 'spacer');

        $this->assertNotNull($section);
        $this->assertSame('home', $section->page, 'the slug still decides what renders');
        $this->assertSame(
            app(ExperiencePageService::class)->idFor($theme->id, 'home'),
            $section->experience_page_id,
            'and the id is what lets the page be renamed later'
        );
    }

    public function test_a_custom_page_can_be_created_and_a_system_page_cannot_be_deleted(): void
    {
        $theme = $this->theme();
        $pages = app(ExperiencePageService::class);

        $offers = $pages->create($theme, 'Ramadan Offers');
        $this->assertNotNull($offers);
        $this->assertSame('ramadan-offers', $offers->slug);
        $this->assertSame(ExperiencePage::KIND_CUSTOM, $offers->kind);

        $this->assertNull($pages->create($theme, 'Ramadan Offers'), 'one slug per theme');
        $this->assertFalse($pages->delete($pages->find($theme->id, 'home')), 'a shop without a home is broken, not empty');
        $this->assertTrue($pages->delete($offers));
    }

    public function test_a_page_copy_follows_the_section_into_a_new_draft(): void
    {
        $theme = $this->theme();
        $version = ThemeVersion::create(['theme_id' => $theme->id, 'status' => 'draft']);
        $section = app(ThemeBuilderService::class)->addSection($version, 'home', 'spacer');

        $draft = app(ThemeManager::class)->createDraftFrom($version);
        $copy = $draft->sections()->first();

        $this->assertSame($section->experience_page_id, $copy->experience_page_id);
        $this->assertSame($section->uuid, $copy->uuid, 'a draft copy is the same logical section');
    }

    public function test_the_builder_still_saves_when_the_page_table_is_not_there_yet(): void
    {
        // The mid-migration case this whole design is guarded for: a deploy where the code is new
        // and the database is not.
        Schema::dropIfExists('experience_pages');
        $theme = Theme::create(['name' => 'Old', 'slug' => 'old-' . uniqid()]);
        $version = ThemeVersion::create(['theme_id' => $theme->id, 'status' => 'draft']);

        $section = app(ThemeBuilderService::class)->addSection($version, 'home', 'spacer');

        $this->assertNotNull($section, 'a missing page table must not stop an edit');
        $this->assertSame('home', $section->page);
        $this->assertNull($section->experience_page_id);
    }

    // -- channels ------------------------------------------------------------------------------

    public function test_a_section_with_no_channel_rule_shows_on_every_surface(): void
    {
        $visibility = app(SectionVisibility::class);
        $section = ['is_visible' => true, 'settings' => []];

        foreach ([Channel::WEB, Channel::CUSTOMER_APP, Channel::VENDOR_APP] as $channel) {
            $this->assertTrue(
                $visibility->passes($section, new ViewerContext(channel: $channel)),
                "an unrestricted section must still render on {$channel}"
            );
        }
    }

    public function test_a_section_can_be_kept_to_one_app(): void
    {
        $visibility = app(SectionVisibility::class);
        $section = ['is_visible' => true, 'settings' => [], 'channels' => [Channel::CUSTOMER_APP]];

        $this->assertTrue($visibility->passes($section, new ViewerContext(
            platform: ViewerContext::PLATFORM_APP, channel: Channel::CUSTOMER_APP,
        )));
        $this->assertSame(SectionVisibility::REASON_CHANNEL, $visibility->reasonFor($section, new ViewerContext(
            platform: ViewerContext::PLATFORM_APP, channel: Channel::VENDOR_APP,
        )), 'both apps are platform "app" — only the channel can tell them apart');
        $this->assertSame(SectionVisibility::REASON_CHANNEL, $visibility->reasonFor($section, new ViewerContext()));
    }

    public function test_a_viewer_that_names_no_channel_is_placed_by_its_platform(): void
    {
        $this->assertSame(Channel::WEB, (new ViewerContext())->channel());
        $this->assertSame(
            Channel::CUSTOMER_APP,
            (new ViewerContext(platform: ViewerContext::PLATFORM_APP))->channel(),
            'every installed app keeps landing on customer_app without sending anything new'
        );
    }

    public function test_each_channel_is_asked_what_it_can_draw(): void
    {
        $capabilities = app(ComponentCapabilityRegistry::class);

        $web = $capabilities->servableToChannel(Channel::WEB);
        $app = $capabilities->servableToChannel(Channel::CUSTOMER_APP);

        $this->assertContains('blog_posts', $web, 'the web ships with the server and draws everything');
        $this->assertNotContains('blog_posts', $app, 'the app has no renderer for it');
        $this->assertSame([], $capabilities->servableToChannel(Channel::VENDOR_APP),
            'a channel with no renderers renders nothing rather than being sent what it cannot draw');

        $this->assertSame([Channel::WEB, Channel::CUSTOMER_APP], $capabilities->channelsFor('hero_banner'));
        $this->assertSame([Channel::WEB], $capabilities->channelsFor('blog_posts'));
    }

    public function test_channel_rules_survive_a_duplicate_and_reach_the_summary(): void
    {
        $theme = $this->theme();
        $version = ThemeVersion::create(['theme_id' => $theme->id, 'status' => 'draft']);
        $builder = app(ThemeBuilderService::class);
        $section = $builder->addSection($version, 'home', 'spacer');

        $this->assertTrue($builder->setDeliveryRules($section, ['channels' => ['customer_app', 'nonsense']]));

        $summary = $builder->deliverySummary($section->fresh());
        $this->assertSame(['customer_app'], $summary['channels'], 'unknown tokens are dropped, not stored');
        $this->assertTrue($summary['targeted']);

        $copy = $builder->duplicateSection($section->fresh());
        $this->assertSame(['customer_app'], $copy->channels);
        $this->assertNotSame($section->uuid, $copy->uuid, 'a duplicate is a new section');
    }

    public function test_an_unchanged_section_is_still_an_unrestricted_row(): void
    {
        $theme = $this->theme();
        $version = ThemeVersion::create(['theme_id' => $theme->id, 'status' => 'draft']);
        $section = app(ThemeBuilderService::class)->addSection($version, 'home', 'spacer');

        $this->assertNull(ThemeSection::find($section->id)->channels,
            'a section nobody restricted must be null, not an empty list — that is what every published section is');
    }
}
