<?php

namespace Tests\Unit;

use App\Services\Seo\SeoTagRenderer;
use PHPUnit\Framework\TestCase;

class SeoTagRendererTest extends TestCase
{
    private SeoTagRenderer $r;

    protected function setUp(): void
    {
        $this->r = new SeoTagRenderer();
    }

    private function meta(array $overrides = []): array
    {
        return array_merge([
            'title' => 'T', 'description' => 'D', 'keywords' => null, 'canonical_url' => null,
            'og_title' => 'T', 'og_description' => 'D', 'og_image' => null,
            'is_indexable' => true, 'is_followable' => true,
        ], $overrides);
    }

    public function test_renders_description_and_og_tags(): void
    {
        $out = $this->r->render($this->meta());
        $this->assertStringContainsString('<meta name="description" content="D">', $out);
        $this->assertStringContainsString('<meta property="og:title" content="T">', $out);
    }

    public function test_omits_empty_fields_instead_of_emitting_blank_tags(): void
    {
        $out = $this->r->render($this->meta(['description' => '', 'og_description' => '']));
        $this->assertStringNotContainsString('name="description"', $out);
        $this->assertStringNotContainsString('og:description', $out);
    }

    public function test_robots_tag_only_emitted_when_restrictive(): void
    {
        $permissive = $this->r->render($this->meta());
        $this->assertStringNotContainsString('name="robots"', $permissive);

        $restricted = $this->r->render($this->meta(['is_indexable' => false, 'is_followable' => false]));
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $restricted);
    }

    public function test_canonical_is_rendered_when_present(): void
    {
        $out = $this->r->render($this->meta(['canonical_url' => 'https://x.test/p/1']));
        $this->assertStringContainsString('<link rel="canonical" href="https://x.test/p/1">', $out);
    }

    public function test_hreflang_alternates_include_x_default(): void
    {
        $out = $this->r->render($this->meta(), ['en' => 'https://x.test/en', 'ar' => 'https://x.test/ar']);
        $this->assertStringContainsString('hreflang="en" href="https://x.test/en"', $out);
        $this->assertStringContainsString('hreflang="ar" href="https://x.test/ar"', $out);
        $this->assertStringContainsString('hreflang="x-default"', $out);
    }

    public function test_output_is_escaped_against_injection(): void
    {
        $out = $this->r->render($this->meta(['description' => 'Bad " onload=x <script>']));
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&quot;', $out);
    }

    public function test_arabic_content_is_preserved(): void
    {
        $out = $this->r->render($this->meta(['description' => 'وصف عربي']));
        $this->assertStringContainsString('وصف عربي', $out);
    }

    public function test_twitter_card_switches_on_image_presence(): void
    {
        $this->assertStringContainsString('content="summary"', $this->r->render($this->meta()));
        $this->assertStringContainsString('summary_large_image', $this->r->render($this->meta(['og_image' => 'https://x.test/i.jpg'])));
    }

    public function test_json_ld_is_rendered_and_script_safe(): void
    {
        $out = $this->r->renderJsonLd(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => '</script>x']);
        $this->assertStringContainsString('application/ld+json', $out);
        $this->assertStringNotContainsString('</script>x', $out);
    }

    public function test_json_ld_empty_returns_empty_string(): void
    {
        $this->assertSame('', $this->r->renderJsonLd([]));
    }
}
