<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\Settings\SeoTranslationController;
use App\Models\Redirect;
use App\Models\SeoMetaTranslation;
use App\Models\SeoTemplate;
use App\Services\Seo\RedirectResolverService;
use App\Services\Seo\SeoMetaResolver;
use App\Services\Seo\SeoTemplateRenderer;
use App\Services\Seo\SlugRedirectSuggester;
use App\Services\Seo\StructuredDataBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * END-TO-END SEO workflow, as the Phase 1 completion gate specifies:
 *
 *   Configure AR/EN -> Save -> Render storefront -> verify metadata / schema / canonical /
 *   hreflang / redirect behaviour.
 *
 * Runs through the real admin controller and the real storefront partial, not isolated services.
 */
class SeoEndToEndWorkflowTest extends TestCase
{
    private SeoTranslationController $admin;
    private const PRODUCT = \App\Models\Product::class;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['seo_meta_translations', 'seo_templates', 'seo_meta_info', 'redirects'] as $t) {
            Schema::dropIfExists($t);
        }
        if (!Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $t) {
                $t->id(); $t->string('type')->nullable(); $t->text('value')->nullable(); $t->timestamps();
            });
        }
        Schema::create('seo_meta_info', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('seoable_type'); $t->unsignedBigInteger('seoable_id');
            $t->string('title')->nullable(); $t->string('description')->nullable();
            $t->string('image')->nullable(); $t->timestamps();
        });
        Schema::create('seo_meta_translations', function (Blueprint $t) {
            $t->id(); $t->string('seoable_type'); $t->unsignedBigInteger('seoable_id');
            $t->string('language_code', 10); $t->string('title')->nullable();
            $t->string('description', 1000)->nullable(); $t->string('keywords', 500)->nullable();
            $t->string('canonical_url', 2048)->nullable(); $t->string('og_title')->nullable();
            $t->string('og_description', 1000)->nullable(); $t->string('og_image', 2048)->nullable();
            $t->boolean('is_indexable')->default(true); $t->boolean('is_followable')->default(true);
            $t->timestamps();
        });
        Schema::create('seo_templates', function (Blueprint $t) {
            $t->id(); $t->string('entity_type', 60); $t->string('language_code', 10);
            $t->string('title_template')->nullable(); $t->string('description_template', 1000)->nullable();
            $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('redirects', function (Blueprint $t) {
            $t->id(); $t->string('from_path', 191); $t->string('to_path', 2048);
            $t->unsignedSmallInteger('status_code')->default(301); $t->string('match_type', 20)->default('exact');
            $t->boolean('is_active')->default(true); $t->unsignedBigInteger('hits')->default(0);
            $t->timestamp('last_hit_at')->nullable(); $t->string('note', 500)->nullable();
            $t->string('created_by_type', 40)->nullable(); $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });

        $renderer = new SeoTemplateRenderer();
        $this->admin = new SeoTranslationController(new SeoMetaResolver($renderer), $renderer);
    }

    private function saveSeo(array $data): void
    {
        $this->admin->save(Request::create('/', 'POST', $data));
    }

    public function test_configure_ar_and_en_then_render_storefront_for_both(): void
    {
        // 1. CONFIGURE English through the real admin controller
        $this->saveSeo([
            'entity_type' => 'product', 'entity_id' => 42, 'language_code' => 'en',
            'title' => 'Panadol Extra 500mg', 'description' => 'Fast, effective relief from headache and fever.',
            'canonical_url' => 'https://shop.test/en/product/panadol', 'is_indexable' => 1, 'is_followable' => 1,
        ]);

        // 2. CONFIGURE Arabic for the same product
        $this->saveSeo([
            'entity_type' => 'product', 'entity_id' => 42, 'language_code' => 'ar',
            'title' => 'بانادول إكسترا ٥٠٠ ملغ', 'description' => 'تسكين سريع وفعّال للصداع والحرارة.',
            'canonical_url' => 'https://shop.test/ar/product/panadol', 'is_indexable' => 1, 'is_followable' => 1,
        ]);

        // saved as two independent rows
        $this->assertSame(2, SeoMetaTranslation::where('seoable_id', 42)->count());

        // 3. RENDER the storefront head for English
        $en = view('seo.head', [
            'seoableType' => self::PRODUCT, 'seoableId' => 42, 'entityType' => 'product', 'language' => 'en',
            'alternates' => ['en' => 'https://shop.test/en/product/panadol', 'ar' => 'https://shop.test/ar/product/panadol'],
            'schema' => (new StructuredDataBuilder())->product([
                'name' => 'Panadol Extra 500mg', 'price' => 2.5, 'currency' => 'KWD', 'in_stock' => true,
            ]),
        ])->render();

        $this->assertStringContainsString('Fast, effective relief', $en);
        $this->assertStringContainsString('rel="canonical" href="https://shop.test/en/product/panadol"', $en);
        $this->assertStringContainsString('hreflang="ar"', $en);
        $this->assertStringContainsString('hreflang="x-default"', $en);
        $this->assertStringContainsString('"@type":"Product"', $en);
        $this->assertStringContainsString('InStock', $en);
        $this->assertStringNotContainsString('name="robots"', $en); // indexable -> no restrictive tag

        // 4. RENDER Arabic — same product, different language, correct content
        $ar = view('seo.head', [
            'seoableType' => self::PRODUCT, 'seoableId' => 42, 'entityType' => 'product', 'language' => 'ar',
        ])->render();

        $this->assertStringContainsString('تسكين سريع', $ar);
        $this->assertStringContainsString('بانادول', $ar);
        $this->assertStringNotContainsString('Fast, effective relief', $ar); // no language bleed
    }

    public function test_template_fills_a_language_that_has_no_explicit_entry(): void
    {
        SeoTemplate::create([
            'entity_type' => 'product', 'language_code' => 'ar',
            'title_template' => '{product_name} | {store_name}', 'is_active' => true,
        ]);

        $html = view('seo.head', [
            'seoableType' => self::PRODUCT, 'seoableId' => 77, 'entityType' => 'product', 'language' => 'ar',
            'templateData' => ['product_name' => 'شامبو', 'store_name' => 'صيدليتي'],
        ])->render();

        $this->assertStringContainsString('شامبو | صيدليتي', $html);
    }

    public function test_noindex_configured_in_admin_reaches_the_storefront(): void
    {
        $this->saveSeo([
            'entity_type' => 'product', 'entity_id' => 43, 'language_code' => 'en',
            'title' => 'Hidden',
            // The form pairs each checkbox with a hidden 0, so un-ticking posts "0" rather than
            // omitting the field (omission is indistinguishable from "not supplied").
            'is_indexable' => 0, 'is_followable' => 0,
        ]);

        $html = view('seo.head', [
            'seoableType' => self::PRODUCT, 'seoableId' => 43, 'entityType' => 'product', 'language' => 'en',
        ])->render();

        $this->assertStringContainsString('name="robots" content="noindex, nofollow"', $html);
    }

    public function test_saving_twice_updates_rather_than_duplicating(): void
    {
        $payload = ['entity_type' => 'product', 'entity_id' => 44, 'language_code' => 'en', 'title' => 'First'];
        $this->saveSeo($payload);
        $this->saveSeo(array_merge($payload, ['title' => 'Second']));

        $rows = SeoMetaTranslation::where('seoable_id', 44)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Second', $rows->first()->title);
    }

    /** Entity type comes from a whitelist, so seoable_type can't be set to an arbitrary class. */
    public function test_unsupported_entity_type_is_rejected(): void
    {
        $this->saveSeo([
            'entity_type' => 'App\\Models\\Admin', 'entity_id' => 1, 'language_code' => 'en', 'title' => 'x',
        ]);

        $this->assertSame(0, SeoMetaTranslation::count());
    }

    public function test_slug_change_creates_a_redirect_that_resolves(): void
    {
        (new SlugRedirectSuggester())->suggest('product', 'panadol-old', 'panadol-new');

        $rules = Redirect::where('is_active', true)->get()->toArray();
        $match = (new RedirectResolverService())->resolve('/product/panadol-old', $rules);

        $this->assertSame('/product/panadol-new', $match['to']);
        $this->assertSame(301, $match['status']);
    }
}
