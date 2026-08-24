<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\ExperiencePageController;
use App\Models\ExperiencePage;
use App\Models\Theme;
use App\Services\Theme\Channel;
use App\Services\Theme\ExperiencePageService;
use App\Services\Theme\LinkComposer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * A composed page's web address, and who may open it.
 *
 * The builder's link picker writes /p/{slug} onto banners, and the app resolves the same URL into
 * its own screen — so this route is one half of a contract the picker already sold to the
 * merchant. What these pin down is the other half: the address exists, it refuses what it must
 * refuse, and the refusals are honest 404s rather than blank pages.
 */
class ExperiencePageWebTest extends TestCase
{
    private Theme $theme;

    protected function setUp(): void
    {
        parent::setUp();
        session(['local' => 'en']);

        foreach (['experience_pages', 'themes'] as $table) {
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

        $this->theme = Theme::create(['name' => 'Storefront', 'slug' => 'storefront', 'is_active' => true]);
        app(ExperiencePageService::class)->ensureSystemPages($this->theme);
    }

    public function test_a_page_the_web_may_see_is_served(): void
    {
        $pages = app(ExperiencePageService::class);
        $pages->create($this->theme, 'About us');
        $pages->create($this->theme, 'Landing', channel: Channel::WEB);

        foreach (['about-us' => 'About us', 'landing' => 'Landing'] as $slug => $title) {
            $view = app(ExperiencePageController::class)->show($slug);

            $this->assertSame('theme-sections.page', $view->name(), $slug);
            $this->assertSame($slug, $view->getData()['pageSlug']);
            $this->assertSame($title, $view->getData()['pageTitle']);
        }
    }

    public function test_a_page_made_for_the_app_is_the_apps_alone(): void
    {
        app(ExperiencePageService::class)->create($this->theme, 'Offers', channel: Channel::CUSTOMER_APP);

        $this->expectException(NotFoundHttpException::class);
        app(ExperiencePageController::class)->show('offers');
    }

    public function test_a_disabled_page_is_gone_rather_than_empty(): void
    {
        $pages = app(ExperiencePageService::class);
        $about = $pages->create($this->theme, 'About us');
        $pages->update($about, enabled: false);

        $this->expectException(NotFoundHttpException::class);
        app(ExperiencePageController::class)->show('about-us');
    }

    public function test_the_built_in_pages_are_not_second_addresses(): void
    {
        // Home already has an address, and the header and footer are fragments of every page.
        // Serving /p/home would give the same content two URLs; serving /p/header would present
        // half a layout as a page of the shop.
        foreach (ExperiencePage::SYSTEM_SLUGS as $slug) {
            try {
                app(ExperiencePageController::class)->show($slug);
                $this->fail($slug . ' was served as a page of its own');
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_slug_nobody_composed_is_a_404(): void
    {
        $this->expectException(NotFoundHttpException::class);
        app(ExperiencePageController::class)->show('never-made');
    }

    public function test_no_active_theme_is_a_404(): void
    {
        Theme::query()->update(['is_active' => false]);

        $this->expectException(NotFoundHttpException::class);
        app(ExperiencePageController::class)->show('about-us');
    }

    public function test_the_route_answers_to_the_address_the_picker_writes(): void
    {
        // The composer and the route are two spellings of one contract. If they drift, every
        // banner already saved with the old spelling 404s the day the route changes.
        $this->assertTrue(Route::has('experience-page'));
        $this->assertStringEndsWith('/p/ramadan-offers', app(LinkComposer::class)->compose('page', 'ramadan-offers'));
        $this->assertStringEndsWith('/p/ramadan-offers', route('experience-page', ['slug' => 'ramadan-offers']));
    }
}
