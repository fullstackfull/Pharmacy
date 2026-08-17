<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\ThemeAssetService;
use App\Services\Theme\ThemeBuilderService;
use App\Services\Theme\ThemeManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Contract tests for the Theme Builder controller: the endpoints must refuse to mutate a published
 * version and must reject unknown sections, independently of the UI. Exercised through the
 * controller class directly (no HTTP route/auth stack needed, and no core DB schema).
 */
class ThemeBuilderApiContractTest extends TestCase
{
    private \App\Http\Controllers\Admin\Settings\ThemeBuilderController $controller;
    private ThemeVersion $draft;
    private ThemeVersion $published;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['theme_blocks', 'theme_sections', 'theme_versions', 'themes'] as $t) {
            Schema::dropIfExists($t);
        }

        // translate() (used in controller error messages, per house convention) reads
        // business_settings via getWebConfig(); provide the table so it resolves.
        if (!Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $t) {
                $t->id();
                $t->string('type')->nullable();
                $t->text('value')->nullable();
                $t->timestamps();
            });
        }
        Schema::create('themes', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('slug', 120)->unique();
            $t->string('description', 500)->nullable(); $t->string('preview_image', 2048)->nullable();
            $t->boolean('is_active')->default(false); $t->boolean('is_system')->default(false);
            $t->string('created_by_type', 40)->nullable(); $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });
        Schema::create('theme_versions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('theme_id'); $t->string('label')->nullable();
            $t->string('status', 20)->default('draft'); $t->json('settings')->nullable();
            $t->unsignedBigInteger('based_on_version_id')->nullable(); $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
        Schema::create('theme_sections', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('theme_version_id'); $t->string('page', 60)->default('home');
            $t->string('type', 80); $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_visible')->default(true); $t->json('settings')->nullable(); $t->timestamps();
        });
        Schema::create('theme_blocks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('theme_section_id'); $t->string('type', 80);
            $t->unsignedInteger('sort_order')->default(0); $t->boolean('is_visible')->default(true);
            $t->json('settings')->nullable(); $t->timestamps();
        });

        $registry = new SectionRegistry();
        $this->controller = new \App\Http\Controllers\Admin\Settings\ThemeBuilderController(
            new ThemeBuilderService($registry), $registry, new ThemeManager(), new ThemeAssetService()
        );

        $theme = Theme::create(['name' => 'T', 'slug' => 't', 'is_active' => true]);
        $this->draft = ThemeVersion::create(['theme_id' => $theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);
        $this->published = ThemeVersion::create(['theme_id' => $theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED]);
    }

    private function request(array $data): \Illuminate\Http\Request
    {
        return \Illuminate\Http\Request::create('/', 'POST', $data);
    }

    public function test_add_section_succeeds_on_a_draft(): void
    {
        $res = $this->controller->addSection($this->request([
            'version_id' => $this->draft->id, 'page' => 'home', 'type' => 'hero_banner',
        ]));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, ThemeSection::where('theme_version_id', $this->draft->id)->count());
    }

    public function test_add_section_is_refused_on_a_published_version(): void
    {
        $res = $this->controller->addSection($this->request([
            'version_id' => $this->published->id, 'page' => 'home', 'type' => 'hero_banner',
        ]));

        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(0, ThemeSection::where('theme_version_id', $this->published->id)->count());
    }

    public function test_unknown_section_type_is_refused(): void
    {
        $res = $this->controller->addSection($this->request([
            'version_id' => $this->draft->id, 'page' => 'home', 'type' => 'evil_section',
        ]));

        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(0, ThemeSection::count());
    }

    public function test_missing_version_returns_error_not_exception(): void
    {
        $res = $this->controller->addSection($this->request([
            'version_id' => 999999, 'page' => 'home', 'type' => 'hero_banner',
        ]));

        $this->assertSame(422, $res->getStatusCode());
    }

    public function test_update_section_on_published_version_is_refused(): void
    {
        $section = ThemeSection::create([
            'theme_version_id' => $this->published->id, 'page' => 'home',
            'type' => 'hero_banner', 'sort_order' => 1, 'is_visible' => true, 'settings' => ['height' => 100],
        ]);

        $res = $this->controller->updateSection($this->request([
            'section_id' => $section->id, 'settings' => ['height' => 999],
        ]));

        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(100, $section->fresh()->settings['height']); // unchanged
    }

    public function test_section_schema_endpoint_returns_fields(): void
    {
        $res = $this->controller->sectionSchema(\Illuminate\Http\Request::create('/', 'GET', ['type' => 'product_slider']));
        $payload = json_decode($res->getContent(), true);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertArrayHasKey('source', $payload['schema']);
        $this->assertArrayHasKey('padding_top', $payload['schema']); // common settings merged in
    }

    public function test_section_schema_rejects_unknown_type(): void
    {
        $res = $this->controller->sectionSchema(\Illuminate\Http\Request::create('/', 'GET', ['type' => 'nope']));
        $this->assertSame(422, $res->getStatusCode());
    }

    /**
     * Regression, and a destructive one: the endpoint used to return the schema only, so the
     * builder's settings form fell back to defaults for every field. Because the autosave posts the
     * WHOLE form, editing one field wrote defaults over every other setting on that section.
     * Confirmed in a browser before fixing — a section saved with columns=2, padding_top=99 showed
     * columns=6, padding_top=40 in the form.
     */
    public function test_section_schema_returns_the_sections_saved_settings(): void
    {
        $section = ThemeSection::create([
            'theme_version_id' => $this->draft->id, 'page' => 'home', 'type' => 'category_grid',
            'sort_order' => 1, 'is_visible' => true,
            'settings' => ['title' => 'Saved title', 'columns' => 2, 'padding_top' => 99],
        ]);

        $res = $this->controller->sectionSchema(\Illuminate\Http\Request::create('/', 'GET', [
            'type' => 'category_grid', 'section_id' => $section->id,
        ]));
        $payload = json_decode($res->getContent(), true);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('Saved title', $payload['settings']['title']);
        $this->assertSame(2, $payload['settings']['columns']);
        $this->assertSame(99, $payload['settings']['padding_top']);
    }

    public function test_section_schema_without_a_section_id_returns_no_settings(): void
    {
        $res = $this->controller->sectionSchema(\Illuminate\Http\Request::create('/', 'GET', ['type' => 'category_grid']));
        $payload = json_decode($res->getContent(), true);

        $this->assertSame([], $payload['settings']); // adding a new section starts from defaults
    }

    /** A section_id whose type does not match must not leak another section's settings. */
    public function test_section_schema_ignores_a_mismatched_section(): void
    {
        $section = ThemeSection::create([
            'theme_version_id' => $this->draft->id, 'page' => 'home', 'type' => 'hero_banner',
            'sort_order' => 1, 'is_visible' => true, 'settings' => ['height' => 777],
        ]);

        $res = $this->controller->sectionSchema(\Illuminate\Http\Request::create('/', 'GET', [
            'type' => 'category_grid', 'section_id' => $section->id,
        ]));
        $payload = json_decode($res->getContent(), true);

        $this->assertSame([], $payload['settings']);
    }

    public function test_delete_section_on_draft_works(): void
    {
        $section = ThemeSection::create([
            'theme_version_id' => $this->draft->id, 'page' => 'home',
            'type' => 'hero_banner', 'sort_order' => 1, 'is_visible' => true,
        ]);

        $res = $this->controller->deleteSection($this->request(['section_id' => $section->id]));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertNull(ThemeSection::find($section->id));
    }
}
