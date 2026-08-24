<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\ThemeBuilderService;
use App\Services\Theme\ThemeManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The whole job, walked end to end, against the endpoint the app actually calls.
 *
 * The lifecycle was already covered — create, edit, reorder, publish, restore — but only against
 * the storefront renderer. That leaves the half this project is about untested: a merchant does not
 * care that the web renderer saw their change, they care that the phone did. And the two are
 * different paths, with different caches and a capability negotiation in between.
 *
 * So this is the same walkthrough, read through /api/v1/theme/home. Every assertion is a thing a
 * merchant would check by picking up their phone.
 */
class AppBuilderWalkthroughTest extends TestCase
{
    private ThemeManager $manager;
    private ThemeBuilderService $builder;
    private Theme $theme;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        session(['local' => 'en']);

        foreach (['theme_blocks', 'theme_sections', 'theme_versions', 'experience_pages', 'themes', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id(); $table->string('type')->nullable(); $table->longText('value')->nullable();
            $table->timestamps();
        });
        Schema::create('themes', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(false); $table->boolean('is_system')->default(false);
            $table->string('created_by_type', 40)->nullable(); $table->unsignedBigInteger('created_by_id')->nullable();
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
            $table->string('change_note', 300)->nullable();
            $table->string('status', 20)->default('draft'); $table->json('settings')->nullable();
            $table->unsignedBigInteger('based_on_version_id')->nullable();
            $table->timestamp('published_at')->nullable(); $table->timestamp('publish_at')->nullable();
            $table->unsignedInteger('revision')->default(0); $table->string('checksum', 64)->nullable();
            $table->timestamps();
        });
        Schema::create('theme_sections', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_version_id');
            $table->unsignedBigInteger('experience_page_id')->nullable(); $table->uuid('uuid')->nullable();
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

        $this->manager = app(ThemeManager::class);
        $this->builder = app(ThemeBuilderService::class);

        $this->theme = $this->manager->createTheme(['name' => 'Storefront', 'slug' => 'storefront']);
        $this->manager->activate($this->theme);
    }

    public function test_the_whole_job_from_composing_to_rolling_back(): void
    {
        $draft = $this->theme->versions()->first();

        // (1-3) A shop that has published a home page: this is the "existing production sections"
        // the merchant opens the builder to find.
        $spacer = $this->builder->addSection($draft, 'home', 'spacer', ['height' => 24]);
        $this->manager->publish($draft);

        $live = $this->payload();
        $this->assertSame(['spacer'], $this->typesOf($live));
        $publishedRevision = $live['revision'];

        // (4-9) A new draft: add a products rail, point it at a category, make it a carousel, give
        // it a title, change its spacing, and put it first.
        $next = $this->manager->createDraftFrom($draft->fresh(), 'seasonal');
        $rail = $this->builder->addSection($next, 'home', 'product_slider', [
            'title' => 'Best sellers',
            'source' => 'category',
            'source_id' => 11,
            'style' => 'carousel',
            'limit' => 12,
            'padding_top' => 8,
        ]);
        $existing = ThemeSection::where('theme_version_id', $next->id)->where('type', 'spacer')->first();
        $this->builder->reorderSections($next, 'home', [$rail->id, $existing->id]);

        // (11) The app is unchanged while all of that happens. This is the promise draft state
        // exists to make, and the one a merchant is most afraid of.
        $stillLive = $this->payload();
        $this->assertSame(['spacer'], $this->typesOf($stillLive));
        $this->assertSame($publishedRevision, $stillLive['revision'], 'nothing to refetch either');

        // (12-14) Publish, and the phone gets the new arrangement.
        $this->manager->publish($next->fresh());

        $afterPublish = $this->payload();
        $this->assertSame(['product_slider', 'spacer'], $this->typesOf($afterPublish));
        $this->assertGreaterThan($publishedRevision, $afterPublish['revision'], 'the app knows to refetch');

        $railPayload = $afterPublish['sections'][0];
        $this->assertSame('Best sellers', $railPayload['settings']['title']);
        $this->assertSame('carousel', $railPayload['variant'], 'the display style reaches the phone');
        $this->assertSame(
            '/api/v1/categories/products/11',
            $railPayload['source']['endpoint'],
            'and so does the category it was pointed at',
        );

        // (15-17) Another draft, with the rail deleted. The app keeps the published arrangement.
        $third = $this->manager->createDraftFrom($next->fresh(), 'trimmed');
        $this->builder->deleteSection(
            ThemeSection::where('theme_version_id', $third->id)->where('type', 'product_slider')->first(),
        );

        $this->assertSame(['product_slider', 'spacer'], $this->typesOf($this->payload()),
            'deleting affects the draft first — the published section stays live');

        // (18-19) Publish, and it is gone.
        $this->manager->publish($third->fresh());
        $this->assertSame(['spacer'], $this->typesOf($this->payload()));

        // (20-21) Restore the version that had the rail, publish it, and the layout returns.
        $restored = $this->manager->restoreVersion($next->fresh());
        $this->assertSame(['spacer'], $this->typesOf($this->payload()), 'restoring alone changes nothing live');

        $this->manager->publish($restored);
        $back = $this->payload();
        $this->assertSame(['product_slider', 'spacer'], $this->typesOf($back));
        $this->assertSame('Best sellers', $back['sections'][0]['settings']['title']);
    }

    public function test_a_hidden_section_is_not_sent_to_the_app(): void
    {
        // Hiding is how a merchant takes a section off the page without losing it — and the app
        // must not receive it, or "hidden" would mean nothing where it counts.
        $draft = $this->theme->versions()->first();
        $this->builder->addSection($draft, 'home', 'spacer', ['height' => 24]);
        $hidden = $this->builder->addSection($draft, 'home', 'faq', ['title' => 'Questions']);
        $this->builder->setSectionVisibility($hidden, false);
        $this->manager->publish($draft);

        $this->assertSame(['spacer'], $this->typesOf($this->payload()));
    }

    public function test_the_order_the_merchant_dragged_is_the_order_the_app_draws(): void
    {
        $draft = $this->theme->versions()->first();
        $first = $this->builder->addSection($draft, 'home', 'spacer', ['height' => 8]);
        $second = $this->builder->addSection($draft, 'home', 'faq', ['title' => 'Questions']);
        // Three types the app can actually draw: a newsletter signup is deliberately withheld from
        // it, and picking one here would test the withholding rather than the ordering.
        $third = $this->builder->addSection($draft, 'home', 'custom_html', ['content' => 'Hello']);

        $this->builder->reorderSections($draft, 'home', [$third->id, $first->id, $second->id]);
        $this->manager->publish($draft);

        $this->assertSame(['custom_html', 'spacer', 'faq'], $this->typesOf($this->payload()));
    }

    public function test_a_sections_identity_survives_being_reordered_and_republished(): void
    {
        // The id a client keys its state on, and the id an impression is counted against. If it
        // moved when a section moved, every number about that section would move with it.
        $draft = $this->theme->versions()->first();
        $rail = $this->builder->addSection($draft, 'home', 'spacer', ['height' => 24]);
        $other = $this->builder->addSection($draft, 'home', 'faq', ['title' => 'Questions']);
        $this->manager->publish($draft);

        $before = $this->payload()['sections'][0]['uuid'];

        $next = $this->manager->createDraftFrom($draft->fresh(), 'reordered');
        $rows = ThemeSection::where('theme_version_id', $next->id)->orderBy('sort_order')->get();
        $this->builder->reorderSections($next, 'home', [$rows[1]->id, $rows[0]->id]);
        $this->manager->publish($next->fresh());

        $after = collect($this->payload()['sections'])->firstWhere('type', 'spacer')['uuid'];

        $this->assertSame($before, $after, 'the same section, wherever it sits on the page');
    }

    // -----------------------------------------------------------------------------------------

    /** The page as the customer app receives it. */
    private function payload(): array
    {
        Cache::flush();

        return $this->getJson('/api/v1/theme/home?page=home')->assertOk()->json();
    }

    /** @return array<int, string> */
    private function typesOf(array $payload): array
    {
        return array_column($payload['sections'] ?? [], 'type');
    }
}
