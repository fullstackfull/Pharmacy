<?php

namespace Tests\Feature;

use App\Models\SeoMetaTranslation;
use App\Services\Seo\StructuredDataBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Renders the opt-in seo.head partial through the real view pipeline, which also proves the
 * SeoServiceProvider composer registration is wired correctly and the app boots with it.
 */
class SeoHeadPartialTest extends TestCase
{
    private const TYPE = 'App\Models\Product';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['seo_meta_translations', 'seo_templates', 'seo_meta_info'] as $t) {
            Schema::dropIfExists($t);
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
    }

    public function test_partial_renders_meta_tags_for_an_entity(): void
    {
        SeoMetaTranslation::create([
            'seoable_type' => self::TYPE, 'seoable_id' => 7, 'language_code' => 'en',
            'title' => 'Panadol Extra', 'description' => 'Fast pain relief',
            'canonical_url' => 'https://x.test/product/panadol',
        ]);

        $html = view('seo.head', [
            'seoableType' => self::TYPE, 'seoableId' => 7,
            'entityType' => 'product', 'language' => 'en',
        ])->render();

        $this->assertStringContainsString('name="description" content="Fast pain relief"', $html);
        $this->assertStringContainsString('rel="canonical" href="https://x.test/product/panadol"', $html);
        $this->assertStringContainsString('property="og:title" content="Panadol Extra"', $html);
        $this->assertStringContainsString('property="og:type" content="product"', $html);
    }

    public function test_partial_renders_arabic_metadata(): void
    {
        SeoMetaTranslation::create([
            'seoable_type' => self::TYPE, 'seoable_id' => 8, 'language_code' => 'ar',
            'title' => 'بانادول إكسترا', 'description' => 'مسكّن سريع للألم',
        ]);

        $html = view('seo.head', [
            'seoableType' => self::TYPE, 'seoableId' => 8,
            'entityType' => 'product', 'language' => 'ar',
        ])->render();

        $this->assertStringContainsString('مسكّن سريع للألم', $html);
        $this->assertStringContainsString('بانادول إكسترا', $html);
    }

    public function test_partial_renders_hreflang_alternates(): void
    {
        SeoMetaTranslation::create([
            'seoable_type' => self::TYPE, 'seoable_id' => 9, 'language_code' => 'en', 'title' => 'T',
        ]);

        $html = view('seo.head', [
            'seoableType' => self::TYPE, 'seoableId' => 9, 'entityType' => 'product', 'language' => 'en',
            'alternates' => ['en' => 'https://x.test/en/p', 'ar' => 'https://x.test/ar/p'],
        ])->render();

        $this->assertStringContainsString('hreflang="ar"', $html);
        $this->assertStringContainsString('hreflang="x-default"', $html);
    }

    public function test_partial_renders_structured_data_when_supplied(): void
    {
        $schema = (new StructuredDataBuilder())->product([
            'name' => 'Panadol', 'price' => 2.5, 'currency' => 'KWD', 'in_stock' => true,
        ]);

        $html = view('seo.head', [
            'seoableType' => self::TYPE, 'seoableId' => 10, 'entityType' => 'product',
            'language' => 'en', 'schema' => $schema,
        ])->render();

        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString('"@type":"Product"', $html);
        $this->assertStringContainsString('InStock', $html);
    }

    public function test_partial_is_safe_without_any_seo_data(): void
    {
        // A page with nothing configured must render without errors and without blank tags.
        $html = view('seo.head', [])->render();

        $this->assertStringNotContainsString('name="description"', $html);
        $this->assertStringNotContainsString('name="robots"', $html); // stays indexable by default
    }

    public function test_noindex_entity_emits_robots_tag(): void
    {
        SeoMetaTranslation::create([
            'seoable_type' => self::TYPE, 'seoable_id' => 11, 'language_code' => 'en',
            'title' => 'Hidden', 'is_indexable' => false, 'is_followable' => false,
        ]);

        $html = view('seo.head', [
            'seoableType' => self::TYPE, 'seoableId' => 11, 'entityType' => 'product', 'language' => 'en',
        ])->render();

        $this->assertStringContainsString('name="robots" content="noindex, nofollow"', $html);
    }
}
