<?php

namespace Tests\Unit;

use App\Services\Seo\StructuredDataBuilder;
use PHPUnit\Framework\TestCase;

class StructuredDataBuilderTest extends TestCase
{
    private StructuredDataBuilder $b;

    protected function setUp(): void
    {
        $this->b = new StructuredDataBuilder();
    }

    public function test_organization_graph(): void
    {
        $g = $this->b->organization([
            'name' => 'My Pharmacy', 'url' => 'https://x.test', 'logo' => 'https://x.test/l.png',
            'social_links' => ['https://fb.test/x', '', null], 'phone' => '+96500000000',
        ]);

        $this->assertSame('Organization', $g['@type']);
        $this->assertSame(['https://fb.test/x'], $g['sameAs']);        // empties filtered
        $this->assertSame('ContactPoint', $g['contactPoint']['@type']);
    }

    public function test_organization_without_name_is_empty(): void
    {
        $this->assertSame([], $this->b->organization(['url' => 'https://x.test']));
    }

    public function test_website_with_search_action(): void
    {
        $g = $this->b->website(['url' => 'https://x.test', 'name' => 'X', 'search_url' => 'https://x.test/s?q={search_term_string}']);

        $this->assertSame('SearchAction', $g['potentialAction']['@type']);
        $this->assertStringContainsString('{search_term_string}', $g['potentialAction']['target']['urlTemplate']);
    }

    public function test_breadcrumbs_are_positioned_sequentially(): void
    {
        $g = $this->b->breadcrumbs([
            ['name' => 'Home', 'url' => 'https://x.test'],
            ['name' => '', 'url' => 'https://x.test/skip'],   // skipped
            ['name' => 'Medicines', 'url' => 'https://x.test/m'],
        ]);

        $this->assertCount(2, $g['itemListElement']);
        $this->assertSame(1, $g['itemListElement'][0]['position']);
        $this->assertSame(2, $g['itemListElement'][1]['position']); // renumbered, no gap
    }

    public function test_breadcrumbs_empty_returns_empty(): void
    {
        $this->assertSame([], $this->b->breadcrumbs([]));
        $this->assertSame([], $this->b->breadcrumbs([['name' => '']]));
    }

    public function test_product_with_offer_and_stock_state(): void
    {
        $inStock = $this->b->product(['name' => 'Panadol', 'price' => 2.5, 'currency' => 'KWD', 'in_stock' => true]);
        $out     = $this->b->product(['name' => 'Panadol', 'price' => 2.5, 'in_stock' => false]);

        $this->assertSame('2.5', $inStock['offers']['price']);
        $this->assertSame('KWD', $inStock['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $inStock['offers']['availability']);
        $this->assertSame('https://schema.org/OutOfStock', $out['offers']['availability']);
    }

    public function test_product_without_price_has_no_offer(): void
    {
        $g = $this->b->product(['name' => 'Panadol']);
        $this->assertArrayNotHasKey('offers', $g);
    }

    public function test_aggregate_rating_requires_a_positive_review_count(): void
    {
        // invalid schema (rating with zero reviews) must be omitted, not emitted
        $none = $this->b->product(['name' => 'P', 'rating' => 4.5, 'review_count' => 0]);
        $this->assertArrayNotHasKey('aggregateRating', $none);

        $ok = $this->b->product(['name' => 'P', 'rating' => 4.5, 'review_count' => 12]);
        $this->assertSame('4.5', $ok['aggregateRating']['ratingValue']);
        $this->assertSame(12, $ok['aggregateRating']['reviewCount']);
    }

    public function test_reviews_are_included_and_blank_ones_skipped(): void
    {
        $g = $this->b->product(['name' => 'P', 'reviews' => [
            ['author' => 'Ali', 'body' => 'Great', 'rating' => 5],
            ['author' => '', 'body' => ''],   // skipped
        ]]);

        $this->assertCount(1, $g['review']);
        $this->assertSame('Ali', $g['review'][0]['author']['name']);
    }

    public function test_product_arabic_content_preserved(): void
    {
        $g = $this->b->product(['name' => 'بانادول', 'description' => 'مسكّن للألم']);
        $this->assertSame('بانادول', $g['name']);
        $this->assertSame('مسكّن للألم', $g['description']);
    }

    public function test_store_graph_with_address(): void
    {
        $g = $this->b->store(['name' => 'Shop', 'url' => 'https://x.test/s', 'address' => 'Street 1', 'city' => 'Kuwait City', 'country' => 'KW']);

        $this->assertSame('Store', $g['@type']);
        $this->assertSame('PostalAddress', $g['address']['@type']);
        $this->assertSame('Kuwait City', $g['address']['addressLocality']);
    }

    public function test_empty_optional_fields_are_omitted_not_blank(): void
    {
        $g = $this->b->product(['name' => 'P', 'description' => '', 'sku' => null, 'images' => ['', null]]);

        $this->assertArrayNotHasKey('description', $g);
        $this->assertArrayNotHasKey('sku', $g);
        $this->assertArrayNotHasKey('image', $g);
    }
}
