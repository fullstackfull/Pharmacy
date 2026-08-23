<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The versioned pair the app syncs against, over real HTTP.
 *
 * Two promises are load-bearing: /theme/version must answer without the cost of a page, and
 * /theme/home must honour If-None-Match — every installed app asks these on every cold start and
 * resume, so the difference between "a header" and "a home page" is most of this feature's
 * bandwidth bill.
 */
class ThemeHomeApiTest extends TestCase
{
    private Theme $theme;

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
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('revision')->default(0); $table->string('checksum', 64)->nullable();
            $table->json('settings')->nullable(); $table->unsignedBigInteger('based_on_version_id')->nullable();
            $table->timestamp('published_at')->nullable(); $table->timestamps();
        });
        Schema::create('theme_sections', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->nullable();
            $table->unsignedBigInteger('theme_version_id'); $table->string('page', 60)->default('home');
            $table->string('type', 80); $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamp('starts_at')->nullable(); $table->timestamp('ends_at')->nullable();
            $table->json('platforms')->nullable(); $table->json('audience')->nullable();
            $table->json('settings')->nullable(); $table->timestamps();
        });
        Schema::create('theme_blocks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_section_id'); $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable(); $table->timestamps();
        });

        $this->theme = Theme::create(['name' => 'Pharmacy', 'slug' => 'pharmacy', 'is_active' => true]);
    }

    private function publish(): ThemeVersion
    {
        $version = ThemeVersion::create([
            'theme_id' => $this->theme->id,
            'status' => ThemeVersion::STATUS_PUBLISHED,
            'revision' => 7,
            'checksum' => 'stored-checksum',
            'published_at' => now(),
        ]);

        ThemeSection::create([
            'theme_version_id' => $version->id,
            'page' => 'home',
            'type' => 'spacer',
            'settings' => ['height' => 40],
        ]);

        return $version;
    }

    public function test_version_endpoint_reports_zero_when_nothing_is_published(): void
    {
        $this->getJson('/api/v1/theme/version')
            ->assertOk()
            ->assertJson(['revision' => 0])
            ->assertJsonStructure(['revision', 'checksum', 'schema_version', 'engine_version']);
    }

    public function test_version_endpoint_reports_the_published_revision(): void
    {
        $this->publish();

        $this->getJson('/api/v1/theme/version')
            ->assertOk()
            ->assertJson(['revision' => 7, 'checksum' => 'stored-checksum']);
    }

    public function test_home_serves_the_negotiated_page_with_its_contract_fields(): void
    {
        $this->publish();

        $response = $this->getJson('/api/v1/theme/home?page=home&device=mobile')
            ->assertOk()
            ->assertJsonStructure([
                'page', 'revision', 'schema_version', 'engine_version', 'checksum',
                'tokens' => ['colors', 'typography', 'layout', 'branding'],
                'sections', 'compatibility' => ['delivered', 'withheld'],
            ]);

        $this->assertSame(7, $response->json('revision'));
        $this->assertSame('spacer', $response->json('sections.0.type'));
        $this->assertNotNull($response->json('sections.0.uuid'));
        $this->assertNotEmpty($response->headers->get('ETag'));
    }

    public function test_home_answers_304_to_a_matching_if_none_match(): void
    {
        $this->publish();

        $etag = trim((string) $this->getJson('/api/v1/theme/home')->headers->get('ETag'), '"');

        $this->getJson('/api/v1/theme/home', ['If-None-Match' => '"' . $etag . '"'])
            ->assertStatus(304);
    }

    public function test_home_filters_by_declared_capabilities(): void
    {
        $version = $this->publish();
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home',
            'type' => 'product_slider', 'sort_order' => 2, 'settings' => [],
        ]);

        $response = $this->getJson('/api/v1/theme/home', [
            'X-UI-Components' => 'spacer',
            'X-UI-Engine' => '1',
        ])->assertOk();

        $this->assertSame(['spacer'], array_column($response->json('sections'), 'type'));
        $this->assertArrayHasKey('product_slider', $response->json('compatibility.withheld'));
    }

    public function test_home_never_500s_on_garbage_input(): void
    {
        $this->publish();

        $this->getJson('/api/v1/theme/home?page[]=x&device=fridge&components[]=1')
            ->assertOk()
            ->assertJson(['page' => 'home']);
    }
}
