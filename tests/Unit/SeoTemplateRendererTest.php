<?php

namespace Tests\Unit;

use App\Services\Seo\SeoTemplateRenderer;
use PHPUnit\Framework\TestCase;

class SeoTemplateRendererTest extends TestCase
{
    private SeoTemplateRenderer $r;

    protected function setUp(): void
    {
        $this->r = new SeoTemplateRenderer();
    }

    public function test_substitutes_placeholders(): void
    {
        $out = $this->r->render('{product_name} | {brand_name} | {store_name}', [
            'product_name' => 'Panadol', 'brand_name' => 'GSK', 'store_name' => 'My Pharmacy',
        ]);
        $this->assertSame('Panadol | GSK | My Pharmacy', $out);
    }

    public function test_missing_placeholder_does_not_leave_dangling_separators(): void
    {
        $out = $this->r->render('{product_name} | {brand_name} | {store_name}', [
            'product_name' => 'Panadol', 'store_name' => 'My Pharmacy', // brand missing
        ]);
        $this->assertSame('Panadol | My Pharmacy', $out);
    }

    public function test_trailing_separator_is_trimmed(): void
    {
        $out = $this->r->render('{product_name} | {brand_name}', ['product_name' => 'Panadol']);
        $this->assertSame('Panadol', $out);
    }

    public function test_arabic_template_renders_correctly(): void
    {
        $out = $this->r->render('منتجات {category_name} | {store_name}', [
            'category_name' => 'العناية', 'store_name' => 'صيدليتي',
        ]);
        $this->assertSame('منتجات العناية | صيدليتي', $out);
    }

    public function test_empty_or_null_template_returns_empty_string(): void
    {
        $this->assertSame('', $this->r->render(null, []));
        $this->assertSame('', $this->r->render('   ', []));
    }

    public function test_length_advice_for_title(): void
    {
        $this->assertSame('short', $this->r->lengthAdvice('Too short', 'title')['status']);
        $this->assertSame('ok', $this->r->lengthAdvice(str_repeat('a', 45), 'title')['status']);
        $this->assertSame('long', $this->r->lengthAdvice(str_repeat('a', 75), 'title')['status']);
    }

    public function test_length_advice_counts_arabic_characters_correctly(): void
    {
        // mb_strlen, not strlen: Arabic is multi-byte and must not be over-counted.
        $arabic = str_repeat('ا', 40);
        $this->assertSame(40, $this->r->lengthAdvice($arabic, 'title')['length']);
    }

    public function test_length_advice_for_description(): void
    {
        $this->assertSame('long', $this->r->lengthAdvice(str_repeat('a', 200), 'description')['status']);
        $this->assertSame('ok', $this->r->lengthAdvice(str_repeat('a', 120), 'description')['status']);
    }
}
