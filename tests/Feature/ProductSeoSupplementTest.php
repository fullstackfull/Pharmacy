<?php

namespace Tests\Feature;

use App\Services\Seo\SeoTagRenderer;
use Tests\TestCase;

/**
 * The canonical / hreflang / structured-data supplement for the product page (Phase 2.2).
 *
 * Phase 1.6 built and tested the SEO renderer and the schema builder, but nothing on the storefront
 * included them, so a live product page carried no canonical, no hreflang and no structured data at
 * all. Confirmed by fetching a real product page and counting zero `application/ld+json` blocks.
 *
 * The failure that motivates most of these tests: StructuredDataBuilder::product() takes a plain
 * array, and the composer first passed it an Eloquent model. The resulting TypeError was caught and
 * turned into an empty string — so the page rendered perfectly while emitting nothing. A silent
 * empty is exactly what a test has to pin down, because nothing about the page looks wrong.
 */
class ProductSeoSupplementTest extends TestCase
{
    private SeoTagRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new SeoTagRenderer();
    }

    // ---- canonical + hreflang, without the tags the theme already writes ----

    public function test_it_renders_a_canonical_link(): void
    {
        $html = $this->renderer->renderCanonicalAndAlternates('https://shop.test/product/serum');

        $this->assertStringContainsString('<link rel="canonical" href="https://shop.test/product/serum"', $html);
    }

    /**
     * The point of a separate method: the theme already emits title, description, og:* and
     * twitter:*, and a duplicated canonical or og tag is worse than none — conflicting signals are
     * treated as untrustworthy rather than averaged.
     */
    public function test_it_emits_nothing_the_theme_already_writes(): void
    {
        $html = $this->renderer->renderCanonicalAndAlternates(
            'https://shop.test/p',
            ['en' => 'https://shop.test/p?language=en', 'ar' => 'https://shop.test/p?language=ar']
        );

        foreach (['og:title', 'og:description', 'twitter:', 'name="description"', 'name="robots"'] as $unwanted) {
            $this->assertStringNotContainsString($unwanted, $html, "must not emit {$unwanted}");
        }
    }

    public function test_it_renders_one_alternate_per_language_plus_x_default(): void
    {
        $html = $this->renderer->renderCanonicalAndAlternates(
            'https://shop.test/p',
            ['en' => 'https://shop.test/p?language=en', 'sy' => 'https://shop.test/p?language=sy']
        );

        $this->assertStringContainsString('hreflang="en"', $html);
        $this->assertStringContainsString('hreflang="sy"', $html);
        $this->assertStringContainsString('hreflang="x-default"', $html);
        $this->assertSame(3, substr_count($html, '<link rel="alternate"'));
    }

    public function test_it_renders_nothing_without_a_canonical_or_alternates(): void
    {
        $this->assertSame('', $this->renderer->renderCanonicalAndAlternates(null));
        $this->assertSame('', $this->renderer->renderCanonicalAndAlternates(''));
    }

    public function test_it_skips_blank_alternate_urls(): void
    {
        $html = $this->renderer->renderCanonicalAndAlternates(null, ['en' => '', 'ar' => null]);

        $this->assertSame('', $html);
    }

    /** A URL carrying a quote must not be able to break out of the attribute. */
    public function test_urls_are_escaped(): void
    {
        $html = $this->renderer->renderCanonicalAndAlternates('https://shop.test/p"><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    // ---- the mapping that silently produced nothing ----

    /**
     * Regression: the composer passed a Product model to a builder that takes an array, and the
     * TypeError became an empty string. This asserts the shape the builder actually needs, so the
     * adapter cannot silently drift from it again.
     */
    public function test_the_builder_produces_a_product_schema_from_the_mapped_array(): void
    {
        $schema = (new \App\Services\Seo\StructuredDataBuilder())->product([
            'name'         => 'Feme Hair Lotion',
            'description'  => 'A hair treatment',
            'sku'          => 'UE943T',
            'url'          => 'https://shop.test/product/feme',
            'brand'        => 'Vitamin Factory',
            'price'        => 25.5,
            'currency'     => 'SYP',
            'in_stock'     => true,
            'review_count' => 0,
        ]);

        $this->assertSame('Product', $schema['@type']);
        $this->assertSame('Feme Hair Lotion', $schema['name']);
        $this->assertSame('UE943T', $schema['sku']);
        $this->assertSame('Vitamin Factory', $schema['brand']['name']);
        $this->assertSame('25.5', $schema['offers']['price']);
        $this->assertSame('SYP', $schema['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $schema['offers']['availability']);
    }

    public function test_out_of_stock_is_reported_honestly(): void
    {
        $schema = (new \App\Services\Seo\StructuredDataBuilder())->product([
            'name' => 'X', 'price' => 10, 'currency' => 'SYP', 'in_stock' => false,
        ]);

        $this->assertSame('https://schema.org/OutOfStock', $schema['offers']['availability']);
    }

    /**
     * An empty payload must render nothing rather than an empty <script> block, so a product with
     * no usable data does not publish a broken rich result.
     */
    public function test_an_empty_schema_renders_no_script_tag(): void
    {
        $this->assertSame('', $this->renderer->renderJsonLd([]));
    }

    /** The partial must exist and be safe to include even with nothing composed into it. */
    public function test_the_partial_renders_empty_without_composed_data(): void
    {
        $html = view('seo.product-supplement', ['seoSupplementTags' => '', 'seoSupplementSchema' => ''])->render();

        $this->assertSame('', trim($html));
    }
}
