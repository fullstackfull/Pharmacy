<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemePreviewToken;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Permission to see one unpublished version, on one phone, for one hour.
 *
 * The builder's phone frame is a browser drawing an approximation. Whether the artwork crops right
 * on a real screen, whether the Arabic wraps, whether the rail is reachable with a thumb — only a
 * phone answers those, and a phone has no admin session. The choice used to be publish and look,
 * or do not look.
 *
 * A token in a URL was rejected once for good reasons, written into the renderer's own comment: a
 * `?preview_version=N` is guessable, shareable and crawlable. These hold the answers to each of
 * them, because the feature is only acceptable while they all stay true.
 */
class ThemePreviewTokenTest extends TestCase
{
    private ThemeVersion $draft;
    private ThemeVersion $published;

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
            $table->json('settings')->nullable(); $table->timestamps();
        });
        Schema::create('theme_blocks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_section_id'); $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable(); $table->timestamps();
        });

        $theme = Theme::create(['name' => 'Storefront', 'slug' => 'storefront', 'is_active' => true]);

        $this->published = ThemeVersion::create([
            'theme_id' => $theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED, 'revision' => 3,
        ]);
        $this->draft = ThemeVersion::create(['theme_id' => $theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);

        $this->section($this->published, 'faq');
        $this->section($this->draft, 'spacer');
    }

    public function test_a_minted_token_names_the_version_it_was_minted_for(): void
    {
        $tokens = app(ThemePreviewToken::class);

        $this->assertSame($this->draft->id, $tokens->version($tokens->mint($this->draft))?->id);
    }

    public function test_the_version_id_cannot_be_edited_into_another_one(): void
    {
        // The whole reason this is signed rather than a plain id: without the signature, anyone
        // holding one preview link holds every version in the shop.
        $tokens = app(ThemePreviewToken::class);
        $token = $tokens->mint($this->draft);

        [$id, $expires, $signature] = explode('.', $token);
        $forged = $this->published->id . '.' . $expires . '.' . $signature;

        $this->assertNull($tokens->version($forged));
    }

    public function test_the_expiry_cannot_be_pushed_out(): void
    {
        $tokens = app(ThemePreviewToken::class);
        [$id, $expires, $signature] = explode('.', $tokens->mint($this->draft));

        $this->assertNull($tokens->version($id . '.' . ($expires + 86400) . '.' . $signature));
    }

    public function test_a_token_stops_working_on_its_own(): void
    {
        // A preview link ends up in a chat message. A layout the merchant decided against must not
        // stay readable to whoever scrolls back to it next month.
        $tokens = app(ThemePreviewToken::class);
        $token = $tokens->mint($this->draft, 5);

        Carbon::setTestNow(Carbon::now()->addMinutes(6));
        $this->assertNull($tokens->version($token));
        Carbon::setTestNow();
    }

    public function test_nonsense_is_refused_without_explaining_itself(): void
    {
        $tokens = app(ThemePreviewToken::class);

        foreach ([null, '', 'x', '1.2', '1.2.3.4', 'a.b.c', '../../etc/passwd'] as $rubbish) {
            $this->assertNull($tokens->version($rubbish), var_export($rubbish, true));
        }
    }

    public function test_a_link_cannot_be_made_effectively_permanent(): void
    {
        $tokens = app(ThemePreviewToken::class);

        $this->assertSame(ThemePreviewToken::MAX_MINUTES * 60, $tokens->expiresIn(99999));
        $this->assertSame(5 * 60, $tokens->expiresIn(1), 'and not so short it is useless either');
    }

    public function test_the_storefront_renders_the_draft_for_a_valid_token_and_the_published_page_otherwise(): void
    {
        $renderer = app(StorefrontThemeRenderer::class);

        $this->assertSame('faq', $renderer->sectionsFor('home')[0]['type'],
            'with no token the shop is the shop');

        request()->merge([
            StorefrontThemeRenderer::PREVIEW_TOKEN_KEY => app(ThemePreviewToken::class)->mint($this->draft),
        ]);

        $this->assertSame('spacer', app(StorefrontThemeRenderer::class)->sectionsFor('home')[0]['type']);
    }

    public function test_a_stale_link_degrades_into_the_ordinary_shop(): void
    {
        // A link that has expired must not error or show an empty page: the shop is the correct
        // answer to "this preview is over".
        request()->merge([StorefrontThemeRenderer::PREVIEW_TOKEN_KEY => 'expired.0.deadbeef']);

        $this->assertSame('faq', app(StorefrontThemeRenderer::class)->sectionsFor('home')[0]['type']);
    }

    public function test_the_api_serves_the_draft_only_to_a_token_holder(): void
    {
        $published = $this->getJson('/api/v1/theme/home?page=home')->assertOk()->json();
        $this->assertArrayNotHasKey('preview', $published);
        $this->assertSame('faq', $published['sections'][0]['type']);

        $token = app(ThemePreviewToken::class)->mint($this->draft);
        $preview = $this->getJson('/api/v1/theme/home?page=home&preview=' . $token)->assertOk();

        $this->assertTrue($preview->json('preview'));
        $this->assertSame('spacer', $preview->json('sections.0.type'));
        $this->assertStringContainsString('no-store', $preview->headers->get('Cache-Control'));
        $this->assertNull($preview->headers->get('ETag'), 'a draft must never share a validator with the shop');
    }

    private function section(ThemeVersion $version, string $type): ThemeSection
    {
        return ThemeSection::create([
            'theme_version_id' => $version->id,
            'page' => 'home', 'type' => $type, 'sort_order' => 1, 'is_visible' => true,
            'settings' => [],
        ]);
    }
}
