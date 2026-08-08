<?php

namespace Tests\Feature;

use App\Models\SeoMetaTranslation;
use App\Models\SeoTemplate;
use App\Services\Seo\SeoMetaResolver;
use App\Services\Seo\SeoTemplateRenderer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies the bilingual SEO resolution chain:
 *   per-language translation -> existing seo_meta_info row -> template -> empty.
 */
class SeoMetaResolverTest extends TestCase
{
    private SeoMetaResolver $resolver;
    private const TYPE = 'App\Models\Product';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SeoMetaResolver(new SeoTemplateRenderer());

        foreach (['seo_meta_translations', 'seo_templates', 'seo_meta_info'] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('seo_meta_info', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('seoable_type');
            $t->unsignedBigInteger('seoable_id');
            $t->string('title')->nullable();
            $t->string('description')->nullable();
            $t->string('image')->nullable();
            $t->timestamps();
        });
        Schema::create('seo_meta_translations', function (Blueprint $t) {
            $t->id();
            $t->string('seoable_type');
            $t->unsignedBigInteger('seoable_id');
            $t->string('language_code', 10);
            $t->string('title')->nullable();
            $t->string('description', 1000)->nullable();
            $t->string('keywords', 500)->nullable();
            $t->string('canonical_url', 2048)->nullable();
            $t->string('og_title')->nullable();
            $t->string('og_description', 1000)->nullable();
            $t->string('og_image', 2048)->nullable();
            $t->boolean('is_indexable')->default(true);
            $t->boolean('is_followable')->default(true);
            $t->timestamps();
        });
        Schema::create('seo_templates', function (Blueprint $t) {
            $t->id();
            $t->string('entity_type', 60);
            $t->string('language_code', 10);
            $t->string('title_template')->nullable();
            $t->string('description_template', 1000)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    private function baseRow(array $attrs): void
    {
        DB::table('seo_meta_info')->insert($attrs + [
            'seoable_type' => self::TYPE, 'seoable_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_falls_back_to_existing_row_when_no_translation(): void
    {
        $this->baseRow(['title' => 'Legacy Title', 'description' => 'Legacy Desc']);

        $r = $this->resolver->resolve(self::TYPE, 1, 'en');

        $this->assertSame('Legacy Title', $r['title']);
        $this->assertSame('base', $r['source']['title']);
    }

    public function test_translation_overrides_the_base_row(): void
    {
        $this->baseRow(['title' => 'Legacy Title']);
        SeoMetaTranslation::create([
            'seoable_type' => self::TYPE, 'seoable_id' => 1, 'language_code' => 'ar',
            'title' => 'عنوان عربي', 'description' => 'وصف عربي',
        ]);

        $ar = $this->resolver->resolve(self::TYPE, 1, 'ar');
        $en = $this->resolver->resolve(self::TYPE, 1, 'en');

        $this->assertSame('عنوان عربي', $ar['title']);
        $this->assertSame('translation', $ar['source']['title']);
        // English is unaffected by the Arabic override -> still the legacy row
        $this->assertSame('Legacy Title', $en['title']);
    }

    public function test_template_fills_the_gap_when_nothing_else_is_set(): void
    {
        SeoTemplate::create([
            'entity_type' => 'product', 'language_code' => 'en',
            'title_template' => '{product_name} | {store_name}', 'is_active' => true,
        ]);

        $r = $this->resolver->resolve(self::TYPE, 1, 'en', 'product', [
            'product_name' => 'Panadol', 'store_name' => 'My Pharmacy',
        ]);

        $this->assertSame('Panadol | My Pharmacy', $r['title']);
        $this->assertSame('template', $r['source']['title']);
    }

    public function test_returns_empty_and_safe_defaults_when_nothing_configured(): void
    {
        $r = $this->resolver->resolve(self::TYPE, 99, 'en');

        $this->assertSame('', $r['title']);
        $this->assertSame('none', $r['source']['title']);
        $this->assertTrue($r['is_indexable']);   // safe default: indexable
        $this->assertTrue($r['is_followable']);
    }

    public function test_og_fields_fall_back_to_resolved_title_and_description(): void
    {
        SeoMetaTranslation::create([
            'seoable_type' => self::TYPE, 'seoable_id' => 1, 'language_code' => 'en',
            'title' => 'T', 'description' => 'D', // no og_* set
        ]);

        $r = $this->resolver->resolve(self::TYPE, 1, 'en');

        $this->assertSame('T', $r['og_title']);
        $this->assertSame('D', $r['og_description']);
    }

    public function test_noindex_flag_is_respected(): void
    {
        SeoMetaTranslation::create([
            'seoable_type' => self::TYPE, 'seoable_id' => 1, 'language_code' => 'en',
            'title' => 'T', 'is_indexable' => false, 'is_followable' => false,
        ]);

        $r = $this->resolver->resolve(self::TYPE, 1, 'en');

        $this->assertFalse($r['is_indexable']);
        $this->assertFalse($r['is_followable']);
    }
}
