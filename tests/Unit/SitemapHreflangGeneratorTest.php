<?php

namespace Tests\Unit;

use App\Services\Seo\SitemapHreflangGenerator;
use PHPUnit\Framework\TestCase;

class SitemapHreflangGeneratorTest extends TestCase
{
    private SitemapHreflangGenerator $gen;

    protected function setUp(): void
    {
        $this->gen = new SitemapHreflangGenerator();
    }

    public function test_produces_well_formed_xml(): void
    {
        $xml = $this->gen->generate([
            ['loc' => 'https://shop.test/en/p/1', 'alternates' => ['en' => 'https://shop.test/en/p/1', 'ar' => 'https://shop.test/ar/p/1']],
        ]);

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml), 'generated sitemap is not valid XML');
        $this->assertStringContainsString('xmlns:xhtml="http://www.w3.org/1999/xhtml"', $xml);
    }

    public function test_emits_an_alternate_per_language_plus_x_default(): void
    {
        $xml = $this->gen->generate([
            ['loc' => 'https://shop.test/en/p/1', 'alternates' => ['en' => 'https://shop.test/en/p/1', 'ar' => 'https://shop.test/ar/p/1']],
        ]);

        $this->assertStringContainsString('hreflang="en" href="https://shop.test/en/p/1"', $xml);
        $this->assertStringContainsString('hreflang="ar" href="https://shop.test/ar/p/1"', $xml);
        $this->assertStringContainsString('hreflang="x-default"', $xml);
    }

    public function test_alternate_set_includes_the_page_itself(): void
    {
        // Google ignores hreflang groups that are not self-referential.
        $xml = $this->gen->generate([
            ['loc' => 'https://shop.test/ar/p/1', 'alternates' => ['en' => 'https://shop.test/en/p/1', 'ar' => 'https://shop.test/ar/p/1']],
        ]);

        $this->assertStringContainsString('href="https://shop.test/ar/p/1"', $xml);
    }

    public function test_skips_entries_without_an_absolute_url(): void
    {
        $xml = $this->gen->generate([
            ['loc' => '/relative/path'],
            ['loc' => 'javascript:alert(1)'],
            ['loc' => 'https://shop.test/ok'],
        ]);

        $this->assertSame(1, substr_count($xml, '<loc>'));
        $this->assertStringContainsString('https://shop.test/ok', $xml);
        $this->assertStringNotContainsString('javascript:', $xml);
    }

    public function test_escapes_special_characters(): void
    {
        $xml = $this->gen->generate([['loc' => 'https://shop.test/search?a=1&b=2']]);

        $this->assertStringContainsString('&amp;', $xml);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml));
    }

    public function test_optional_metadata_is_rendered(): void
    {
        $xml = $this->gen->generate([
            ['loc' => 'https://shop.test/p', 'lastmod' => '2026-08-09', 'changefreq' => 'daily', 'priority' => 0.8],
        ]);

        $this->assertStringContainsString('<lastmod>2026-08-09</lastmod>', $xml);
        $this->assertStringContainsString('<changefreq>daily</changefreq>', $xml);
        $this->assertStringContainsString('<priority>0.8</priority>', $xml);
    }

    public function test_language_code_normalization(): void
    {
        $this->assertSame('ar', $this->gen->languageCode('AR'));
        $this->assertSame('ar-KW', $this->gen->languageCode('ar_kw'));
        $this->assertSame('en-GB', $this->gen->languageCode('en-gb'));
        $this->assertNull($this->gen->languageCode(''));
        $this->assertNull($this->gen->languageCode('not a code'));
    }

    public function test_invalid_language_codes_are_skipped_not_emitted(): void
    {
        $xml = $this->gen->generate([
            ['loc' => 'https://shop.test/p', 'alternates' => ['' => 'https://shop.test/x', 'en' => 'https://shop.test/p']],
        ]);

        $this->assertStringNotContainsString('hreflang=""', $xml);
        $this->assertStringContainsString('hreflang="en"', $xml);
    }

    public function test_duplicate_languages_are_emitted_once(): void
    {
        $xml = $this->gen->generate([
            ['loc' => 'https://shop.test/p', 'alternates' => ['en' => 'https://shop.test/p', 'EN' => 'https://shop.test/dupe']],
        ]);

        $this->assertSame(1, substr_count($xml, 'hreflang="en"'));
    }

    public function test_empty_input_still_produces_a_valid_empty_sitemap(): void
    {
        $xml = $this->gen->generate([]);

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml));
        $this->assertStringNotContainsString('<url>', $xml);
    }
}
