<?php

namespace Tests\Feature;

use App\Http\Middleware\NoIndexThemePreview;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\PublishValidator;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeBuilderService;
use App\Services\Theme\ThemeCompatibilityReport;
use App\Services\Theme\ThemeManager;
use App\Services\Theme\ThemePreviewToken;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Theme Editor's preview, driven the way a merchant drives it.
 *
 * The signed link a merchant sends to their own phone is already pinned by ThemePreviewTokenTest.
 * What was never pinned is the preview the merchant actually spends the afternoon in: the phone
 * frame inside the builder, which is an iframe on the ordinary storefront, showing the draft only
 * because the builder put a version id in the admin's session on the way in.
 *
 * That mechanism has no token, no URL and nothing visible about it, so nothing about it fails
 * loudly. If the session key stopped being read, the frame would keep loading — it would simply
 * show the published shop, and the merchant would spend the afternoon editing a page and watching
 * a page that is not it.
 */
class ThemeBuilderPreviewTest extends TestCase
{
    private Theme $theme;
    private ThemeVersion $draft;
    private ThemeVersion $published;

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

        $this->published = ThemeVersion::create([
            'theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED, 'revision' => 4,
        ]);
        $this->draft = ThemeVersion::create([
            'theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_DRAFT,
        ]);

        $this->section($this->published, 'faq');
        $this->section($this->draft, 'spacer');
    }

    // ---- the phone frame ------------------------------------------------------------------

    public function test_the_frame_shows_the_draft_the_builder_opened(): void
    {
        // The one assertion the whole editing loop rests on: what the merchant edits on the left is
        // what the frame on the right draws.
        $this->beAdmin();
        session([StorefrontThemeRenderer::PREVIEW_SESSION_KEY => $this->draft->id]);

        $this->assertSame('spacer', app(StorefrontThemeRenderer::class)->sectionsFor('home')[0]['type']);
    }

    public function test_ending_the_preview_gives_the_admin_the_shop_back(): void
    {
        $this->beAdmin();
        session([StorefrontThemeRenderer::PREVIEW_SESSION_KEY => $this->draft->id]);
        session()->forget(StorefrontThemeRenderer::PREVIEW_SESSION_KEY);

        $this->assertSame('faq', app(StorefrontThemeRenderer::class)->sectionsFor('home')[0]['type']);
    }

    public function test_the_same_session_key_shows_a_customer_nothing(): void
    {
        // Session data is not a secret in the way a signed token is — a session can be inherited by
        // a customer sharing a machine, or survive a logout that failed to clear it. Being an admin
        // is the condition, not merely holding the key.
        session([StorefrontThemeRenderer::PREVIEW_SESSION_KEY => $this->draft->id]);

        $this->assertSame('faq', app(StorefrontThemeRenderer::class)->sectionsFor('home')[0]['type']);
    }

    public function test_an_admin_who_may_not_see_the_theme_sees_the_shop(): void
    {
        // The link endpoint asks this before it will sign a token; the session key was trusted on
        // its own, so an admin whose role denies theme access could still walk into the unpublished
        // draft through the builder. The renderer is the one place every route that sets the key
        // passes through.
        $this->beAdmin(roleId: 7, access: ['themes_and_addons_none']);
        session([StorefrontThemeRenderer::PREVIEW_SESSION_KEY => $this->draft->id]);

        $this->assertSame('faq', app(StorefrontThemeRenderer::class)->sectionsFor('home')[0]['type']);
    }

    public function test_a_role_that_predates_the_capability_keeps_its_preview(): void
    {
        // The coarse module grant is what every existing role holds; taking the preview away from
        // them would be a silent regression dressed as security.
        $this->beAdmin(roleId: 7, access: ['themes_and_addons']);
        session([StorefrontThemeRenderer::PREVIEW_SESSION_KEY => $this->draft->id]);

        $this->assertSame('spacer', app(StorefrontThemeRenderer::class)->sectionsFor('home')[0]['type']);
    }

    public function test_a_version_deleted_underneath_the_preview_degrades_into_the_shop(): void
    {
        // The merchant discards the draft in another tab while the frame is still open. The frame
        // reloads on the next edit; showing the shop is right, a blank page or a 500 is not.
        $this->beAdmin();
        session([StorefrontThemeRenderer::PREVIEW_SESSION_KEY => $this->draft->id]);
        $this->draft->delete();
        Cache::flush();

        $sections = app(StorefrontThemeRenderer::class)->sectionsFor('home');

        $this->assertNotNull($sections);
        $this->assertSame('faq', $sections[0]['type']);
        $this->assertNull(session(StorefrontThemeRenderer::PREVIEW_SESSION_KEY),
            'and the dead key does not survive to confuse the next page');
    }

    public function test_a_token_still_works_for_the_admin_who_minted_it(): void
    {
        // The admin scans their own QR on their own phone; that browser has no admin session, so the
        // token is the only thing carrying the version. Nothing about being logged in may break it.
        request()->merge([
            StorefrontThemeRenderer::PREVIEW_TOKEN_KEY => app(ThemePreviewToken::class)->mint($this->draft),
        ]);

        $this->assertSame('spacer', app(StorefrontThemeRenderer::class)->sectionsFor('home')[0]['type']);
    }

    // ---- what the preview response may not become -----------------------------------------

    public function test_a_previewed_page_is_kept_out_of_search_results_and_caches(): void
    {
        // Registered in the real web group; a preview URL that a crawler or a shared cache is
        // allowed to keep is how a rejected layout becomes the page the shop is known by.
        Route::middleware(['web'])->get('/__preview_probe', fn () => response('ok'));

        $plain = $this->get('/__preview_probe');
        $this->assertNull($plain->headers->get('X-Robots-Tag'));

        $previewed = $this->get('/__preview_probe?' . StorefrontThemeRenderer::PREVIEW_TOKEN_KEY . '=x.y.z');

        $this->assertSame('noindex, nofollow', $previewed->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $previewed->headers->get('Cache-Control'));
    }

    public function test_the_middleware_leaves_an_error_response_intact(): void
    {
        $middleware = new NoIndexThemePreview();
        $request = \Illuminate\Http\Request::create('/?' . StorefrontThemeRenderer::PREVIEW_TOKEN_KEY . '=x.y.z');

        $response = $middleware->handle($request, fn () => response('nope', 404));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }

    // ---- the controls around the frame ------------------------------------------------------

    public function test_the_builder_draws_the_frame_and_the_way_out_of_it(): void
    {
        session([StorefrontThemeRenderer::PREVIEW_SESSION_KEY => $this->draft->id]);

        $html = $this->renderBuilder(previewUrl: '/');

        $this->assertStringContainsString('id="tb-frame"', $html, 'the frame itself');
        $this->assertStringContainsString('data-url-preview-link', $html, 'the link to a real phone');
        $this->assertStringContainsString(route('admin.theme.builder.preview.stop'), $html, 'the way out');
    }

    public function test_the_builder_says_so_rather_than_drawing_an_empty_frame(): void
    {
        // builderPreviewUrl() returns null when the storefront home route is unavailable. An iframe
        // with no src is a white rectangle the merchant reads as a broken theme.
        $html = $this->renderBuilder(previewUrl: null);

        $this->assertStringNotContainsString('id="tb-frame"', $html);
        $this->assertStringContainsString('storefront preview is unavailable', strtolower($html));
    }

    // ---- the link to a real phone -----------------------------------------------------------

    public function test_the_phone_link_carries_a_token_that_names_this_version(): void
    {
        $this->beAdmin();

        $body = $this->previewLink(['version_id' => $this->draft->id])->getData(true);

        $this->assertSame('success', $body['status']);
        parse_str((string) parse_url($body['url'], PHP_URL_QUERY), $query);
        $token = $query[StorefrontThemeRenderer::PREVIEW_TOKEN_KEY] ?? null;

        $this->assertSame($this->draft->id, app(ThemePreviewToken::class)->version($token)?->id);
        $this->assertStringNotContainsString((string) $this->draft->id, (string) parse_url($body['url'], PHP_URL_PATH),
            'the version id is not in the path either');
    }

    public function test_the_link_is_drawn_as_a_code_the_merchant_can_point_a_phone_at(): void
    {
        // Typing a signed token by hand is not a thing anybody will do; the QR is the feature.
        $this->beAdmin();

        $body = $this->previewLink(['version_id' => $this->draft->id])->getData(true);

        $this->assertStringStartsWith('<svg', trim($body['qr']));
        $this->assertSame(3600, $body['expires_in'], 'and it says how long it lasts');
    }

    public function test_the_link_cannot_be_asked_for_by_somebody_who_may_not_see_the_theme(): void
    {
        // The token grants exactly what this screen grants — reading an unpublished version — so an
        // admin without theme access must not be able to mint one and walk it out of the building.
        $body = $this->previewLink(['version_id' => $this->draft->id]);

        $this->assertSame(422, $body->getStatusCode());
        $this->assertSame('error', $body->getData(true)['status']);
    }

    public function test_asking_for_a_version_that_is_gone_is_refused_rather_than_signed(): void
    {
        $this->beAdmin();

        $this->assertSame(404, $this->previewLink(['version_id' => 999999])->getStatusCode());
    }

    public function test_the_link_cannot_be_asked_to_last_a_year(): void
    {
        $this->beAdmin();

        $body = $this->previewLink(['version_id' => $this->draft->id, 'minutes' => 525600])->getData(true);

        $this->assertSame(ThemePreviewToken::MAX_MINUTES * 60, $body['expires_in']);
    }

    // ---- helpers ---------------------------------------------------------------------------

    private function previewLink(array $payload): \Illuminate\Http\JsonResponse
    {
        return app(\App\Http\Controllers\Admin\Settings\ThemeBuilderController::class)
            ->previewLink(\Illuminate\Http\Request::create('/', 'POST', $payload));
    }

    private function beAdmin(int $roleId = 1, array $access = []): void
    {
        $admin = new class($roleId, $access) implements Authenticatable {
            public $admin_role_id;
            public $role;

            public function __construct(int $roleId, array $access)
            {
                $this->admin_role_id = $roleId;
                $this->role = (object) ['module_access' => json_encode($access)];
            }

            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return 1; }
            public function getAuthPassword() { return ''; }
            public function getRememberToken() { return null; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return null; }
            public function getAuthPasswordName() { return 'password'; }
        };

        $this->be($admin, 'admin');
    }

    private function section(ThemeVersion $version, string $type): void
    {
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => $type,
            'sort_order' => 1, 'is_visible' => true, 'settings' => [],
        ]);
    }

    /** The builder body without the admin layout, the way AdminThemeViewsRenderTest renders it. */
    private function renderBuilder(?string $previewUrl): string
    {
        $registry = app(SectionRegistry::class);
        $builder = app(ThemeBuilderService::class);

        $data = [
            'bannerGaps' => [],
            'version' => $this->draft,
            'theme' => $this->theme,
            'page' => 'home',
            'previewUrl' => $previewUrl,
            'structure' => $builder->getPageStructure($this->draft, 'home'),
            'sectionTypes' => $registry->forPage('home'),
            'sectionCatalogue' => $registry->catalogue('home'),
            'blockLabels' => array_map(static fn (array $block) => $block['label'], $registry->blockTypes()),
            'goLive' => ['live' => false, 'active' => true, 'published' => false],
            'compatibility' => app(ThemeCompatibilityReport::class)->for($this->draft),
            'publishCheck' => app(PublishValidator::class)->inspect($this->draft),
            'reach' => [],
            'themeSettings' => app(ThemeManager::class)->resolveSettings($this->draft),
            'pages' => app(\App\Services\Theme\ExperiencePageService::class)->forChannel($this->theme->id, 'web'),
            'channel' => 'web',
            'editable' => true,
            'uploadAccept' => '.png,.jpg',
        ];

        $source = File::get(resource_path('views/admin-views/theme/builder.blade.php'));
        $source = preg_replace('/@extends\([^)]*\)/', '', $source, 1);
        $source = preg_replace("/@section\('title'.*?\)/", '', $source, 1);
        $source = preg_replace('/@push\([^)]*\).*?@endpush/s', '', $source);
        $source = str_replace("@section('content')", '', $source);
        $source = preg_replace('/@endsection\b/', '', $source);

        $probe = resource_path('views/admin-views/theme/__preview_probe.blade.php');
        File::put($probe, $source);

        try {
            return view('admin-views.theme.__preview_probe', $data)->render();
        } finally {
            File::delete($probe);
        }
    }
}
