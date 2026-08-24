<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\PublishValidator;
use App\Services\Theme\ThemeManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The last check before a draft becomes the shop.
 *
 * Everything needed to catch a broken publish already existed: the readiness rule knew a section
 * would not render, the compatibility report knew the app would not receive it. Nothing asked them
 * at the moment it mattered, so a merchant who added a category showcase and never chose a category
 * published a home page with a section missing from it and found out from the live site.
 *
 * The line these hold is between the two severities. Blocking is "you left something unset, and one
 * click fixes it"; warning is "the configuration is right and the world is not". Getting that line
 * wrong in either direction is worse than having no gate: block on the shop being empty and a
 * merchant can never publish; warn on a missing choice and the gate has said nothing.
 */
class PublishGateTest extends TestCase
{
    private ThemeVersion $draft;

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
            $table->string('change_note', 300)->nullable();
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
            $table->json('channels')->nullable();
            $table->timestamps();
        });
        Schema::create('theme_blocks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_section_id'); $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable(); $table->timestamps();
        });

        $theme = Theme::create(['name' => 'Storefront', 'slug' => 'storefront', 'is_active' => true]);
        $this->draft = ThemeVersion::create(['theme_id' => $theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);
    }

    public function test_a_section_that_was_never_finished_stops_the_publish(): void
    {
        // The merchant added a category showcase and never chose the category. It renders nothing,
        // looks identical to a working section in the builder, and used to go live exactly so.
        $this->section('category_showcase', []);

        $findings = app(PublishValidator::class)->inspect($this->draft);

        $this->assertCount(1, $findings['blocking']);
        $this->assertSame('category_showcase', $findings['blocking'][0]['type']);
        $this->assertNotEmpty($findings['blocking'][0]['fix_key'], 'a finding that names no fix is a complaint');
        $this->assertFalse(app(PublishValidator::class)->passes($this->draft));
    }

    public function test_hiding_the_section_is_a_fix_and_so_is_making_the_choice(): void
    {
        $section = $this->section('category_showcase', []);

        $section->update(['is_visible' => false]);
        $this->assertTrue(app(PublishValidator::class)->passes($this->draft),
            'hiding a section IS the merchant deciding not to publish it');

        $section->update(['is_visible' => true, 'settings' => ['category_id' => 4]]);
        $this->assertTrue(app(PublishValidator::class)->passes($this->draft),
            'and so is choosing the category — the shop having no products for it is not this gate\'s business');
    }

    public function test_a_fix_clears_the_finding_immediately_however_the_answer_is_cached(): void
    {
        // The check is cached — it costs a query per section and two screens ask on every load.
        // A cache that can outlive the fix is worse than no cache: the merchant does the thing the
        // message asked for and is told again that they have not.
        $section = $this->section('category_showcase', []);
        $this->assertNotEmpty(app(PublishValidator::class)->inspect($this->draft)['blocking']);

        $section->update(['settings' => ['category_id' => 4]]);

        $this->assertSame([], app(PublishValidator::class)->inspect($this->draft)['blocking']);
    }

    public function test_editing_a_card_clears_it_too(): void
    {
        // A hero is judged on its slides, which live in blocks — so a block edit has to drop the
        // same cached answer a section edit does.
        $hero = $this->section('hero_banner', []);
        $this->assertNotEmpty(app(PublishValidator::class)->inspect($this->draft)['blocking']);

        \App\Models\ThemeBlock::create([
            'theme_section_id' => $hero->id,
            'type' => 'slide',
            'sort_order' => 1,
            'is_visible' => true,
            'settings' => ['title' => 'Ramadan'],
        ]);

        $this->assertSame([], app(PublishValidator::class)->inspect($this->draft)['blocking']);
    }

    public function test_an_empty_shop_is_a_warning_and_never_a_block(): void
    {
        // A coupon strip with no live coupon is correctly configured and temporarily empty. A gate
        // that blocked on it would make publishing impossible on a quiet week.
        $this->section('coupon_strip', ['limit' => 4]);

        $findings = app(PublishValidator::class)->inspect($this->draft);

        $this->assertSame([], $findings['blocking']);
        $this->assertNotEmpty($findings['warnings']);
    }

    public function test_a_type_this_server_does_not_have_is_never_publishable(): void
    {
        // An import from a build with sections this one lacks. It draws nothing on the web and is
        // withheld from the app, so publishing it ships an invisible section.
        $this->section('holographic_showroom', []);

        $blocking = app(PublishValidator::class)->inspect($this->draft)['blocking'];

        $this->assertCount(1, $blocking);
        $this->assertSame('holographic_showroom', $blocking[0]['type']);
    }

    public function test_a_web_only_section_is_reported_without_blocking_anything(): void
    {
        // The newsletter signup is deliberately withheld from the app — there is no surface for it
        // there. That is a decision to inform, not a fault to fix: the merchant may well want it on
        // the website only, and blocking would make a perfectly good section unpublishable.
        $this->section('newsletter', ['title' => 'Stay in touch']);

        $findings = app(PublishValidator::class)->inspect($this->draft);

        $this->assertSame([], $findings['blocking']);
        $this->assertSame('newsletter', $findings['warnings'][0]['type']);
        $this->assertNotSame('this_section_will_not_appear', $findings['warnings'][0]['reason_key'],
            'and it says WHY the app cannot draw it');
    }

    public function test_a_section_limited_to_a_channel_nothing_renders_is_a_warning(): void
    {
        $this->section('spacer', ['height' => 24])->update(['channels' => ['vendor_app']]);

        $warnings = app(PublishValidator::class)->inspect($this->draft)['warnings'];

        $this->assertNotEmpty($warnings);
        $this->assertSame('spacer', $warnings[0]['type']);
    }

    public function test_hiding_every_section_on_the_home_page_is_worth_saying_out_loud(): void
    {
        $this->section('spacer', ['height' => 24])->update(['is_visible' => false]);

        $findings = app(PublishValidator::class)->inspect($this->draft);

        $this->assertSame([], $findings['blocking'], 'the built-in home page is a working page');
        $this->assertNotEmpty($findings['warnings']);
        $this->assertNull($findings['warnings'][0]['section_id'], 'this is about the page, not a section');
    }

    public function test_a_clean_draft_has_nothing_to_say(): void
    {
        $this->section('spacer', ['height' => 24]);

        $this->assertSame(
            ['blocking' => [], 'warnings' => []],
            app(PublishValidator::class)->inspect($this->draft),
        );
    }

    public function test_the_note_a_merchant_writes_at_publish_is_what_the_version_is_remembered_by(): void
    {
        $this->section('spacer', ['height' => 24]);

        $published = (new ThemeManager())->publish($this->draft, '  Ramadan hero and two new rails  ');

        $this->assertSame('Ramadan hero and two new rails', $published->change_note, 'trimmed, not padded');
    }

    public function test_publishing_without_a_note_is_still_publishing(): void
    {
        $this->section('spacer', ['height' => 24]);

        $published = (new ThemeManager())->publish($this->draft);

        $this->assertSame(ThemeVersion::STATUS_PUBLISHED, $published->status);
        $this->assertNull($published->change_note);
    }

    private function section(string $type, array $settings): ThemeSection
    {
        return ThemeSection::create([
            'theme_version_id' => $this->draft->id,
            'page' => 'home',
            'type' => $type,
            'sort_order' => 1,
            'is_visible' => true,
            'settings' => $settings,
        ]);
    }
}
