<?php

namespace Tests\Feature;

use App\Services\Theme\ContentSource;
use App\Services\Theme\SectionDataResolver;
use App\Services\Theme\SectionDestination;
use App\Services\Theme\ThemeSourceMap;
use Tests\TestCase;

/**
 * One reading of "what should this section show".
 *
 * The question was answered in three places at once: the source map that tells the app which
 * endpoint to fetch, the resolver that runs the query for the web, and the readiness check that
 * decides whether the builder shows a warning. Each dug the same four keys out of the settings bag
 * and each applied its own fallbacks — which is how a rail could name one limit on the phone and a
 * different one on the web from a single published theme.
 *
 * These hold the object that replaced them: the defaults it applies, the bounds it enforces, and
 * the two ways a merchant can leave a section pointing at nothing.
 */
class ContentSourceTest extends TestCase
{
    public function test_an_unset_source_is_the_featured_rail(): void
    {
        $source = ContentSource::fromSettings([]);

        $this->assertSame('featured', $source->kind);
        $this->assertNull($source->id);
        $this->assertSame([], $source->ids);
        $this->assertSame(10, $source->limit);
    }

    public function test_a_source_the_registry_does_not_offer_falls_back_instead_of_reaching_the_query(): void
    {
        // Settings are stored JSON. A hand-edited row, an older export, a renamed source: none of
        // them may reach a match arm as an unknown string.
        foreach (['', 'trending', 'DROP TABLE products', '1'] as $stored) {
            $this->assertSame('featured', ContentSource::fromSettings(['source' => $stored])->kind);
        }

        foreach (ContentSource::KINDS as $kind) {
            $this->assertSame($kind, ContentSource::fromSettings(['source' => $kind])->kind);
        }
    }

    public function test_each_caller_keeps_its_own_item_count_when_the_merchant_set_none(): void
    {
        // The web rail has always shown eight and the app hint has always asked for ten. Neither
        // number moved when the reading was shared; only the place it is written down did.
        $this->assertSame(8, ContentSource::fromSettings([], defaultLimit: 8)->limit);
        $this->assertSame(10, ContentSource::fromSettings([])->limit);
        $this->assertSame(6, ContentSource::fromSettings(['limit' => 6], defaultLimit: 8)->limit);
    }

    public function test_the_web_and_the_app_are_bounded_by_the_same_number(): void
    {
        // The builder's item count is a free-text number. The storefront query capped it at 24 and
        // the app's endpoint hint capped it nowhere, so a merchant who typed 100 got a rail of 24
        // in the browser and a longer one on the phone, from one published section.
        $hundred = ['source' => 'best_selling', 'limit' => 100];

        $this->assertSame(
            ContentSource::MAX_LIMIT,
            app(ThemeSourceMap::class)->for('product_slider', $hundred, [])['params']['limit'],
        );
        $this->assertSame(ContentSource::MAX_LIMIT, ContentSource::fromSettings($hundred)->limit);
    }

    public function test_a_bundle_names_the_products_it_will_actually_show(): void
    {
        // by-ids has no limit parameter, so an over-long pick list had to be cut where it is read
        // rather than counted where it is used: the web drew twelve and the app drew all of them.
        $ids = implode(',', range(1, 20));

        $delivered = app(ThemeSourceMap::class)->for('bundle', ['product_ids' => $ids], [])['params']['ids'];

        $this->assertCount(SectionDataResolver::BUNDLE_LIMIT, explode(',', $delivered));
        $this->assertSame('1', explode(',', $delivered)[0], 'and in the merchant\'s order');
    }

    public function test_no_configuration_can_ask_for_the_whole_catalogue(): void
    {
        $this->assertSame(ContentSource::MAX_LIMIT, ContentSource::fromSettings(['limit' => 5000])->limit);
        $this->assertSame(1, ContentSource::fromSettings(['limit' => 0])->limit);
        $this->assertSame(1, ContentSource::fromSettings(['limit' => -20])->limit);
        $this->assertSame(10, ContentSource::fromSettings(['limit' => 'ten'])->limit, 'a non-number is no number');
    }

    public function test_picked_ids_survive_both_shapes_a_picker_stores_them_in(): void
    {
        // The form posts "7,3,9"; the cast hands back [7, 3, 9]. Both reach this code.
        $this->assertSame([7, 3, 9], ContentSource::fromSettings(['product_ids' => '7,3,9'])->ids);
        $this->assertSame([7, 3, 9], ContentSource::fromSettings(['product_ids' => [7, 3, 9]])->ids);
        $this->assertSame([], ContentSource::fromSettings(['product_ids' => '0,,-4'])->ids);
        $this->assertSame([], ContentSource::fromSettings([])->ids);
    }

    public function test_a_showcase_cannot_be_redirected_by_a_stray_source_key(): void
    {
        // A showcase's subject IS its identity — it has no source dropdown. Building it explicitly
        // is what stops a leftover key in a stored settings bag from turning the merchant's
        // category showcase into a featured rail.
        $source = ContentSource::scoped('category', 11, 6);

        $this->assertSame('category', $source->kind);
        $this->assertSame(11, $source->id);
        $this->assertSame(6, $source->limit);

        $this->assertSame(
            '/api/v1/categories/products/11',
            app(ThemeSourceMap::class)->for('category_showcase', ['category_id' => 11, 'source' => 'best_selling'], [])['endpoint'],
        );
    }

    public function test_the_two_ways_a_section_ends_up_pointing_at_nothing_are_told_apart(): void
    {
        $this->assertTrue(ContentSource::fromSettings(['source' => 'category'])->needsSubject());
        $this->assertFalse(ContentSource::fromSettings(['source' => 'category', 'source_id' => 3])->needsSubject());
        $this->assertFalse(ContentSource::fromSettings(['source' => 'best_selling'])->needsSubject(),
            'an ordering needs no subject');

        $empty = ContentSource::picked(null);
        $this->assertTrue($empty->isManual());
        $this->assertSame([], $empty->ids);
    }

    public function test_a_scoped_rail_leads_to_what_it_is_scoped_to(): void
    {
        // Both clients drew the heading's "view all" and both sent the shopper to the entire
        // catalogue, however the rail was scoped — a rail of one category's products offering
        // "view all" and opening everything the shop sells.
        $where = app(SectionDestination::class);

        $this->assertStringContainsString(
            'category_id=11',
            (string) $where->urlFor('product_slider', ['source' => 'category', 'source_id' => 11]),
        );
        $this->assertStringContainsString(
            'brand_id=4',
            (string) $where->urlFor('product_slider', ['source' => 'brand', 'source_id' => 4]),
        );
        $this->assertStringContainsString(
            'best-selling-products',
            (string) $where->urlFor('product_slider', ['source' => 'best_selling']),
        );

        // A hand-picked set IS the whole set; "more of these" would open a list nobody asked for.
        $this->assertNull($where->urlFor('product_slider', ['source' => 'manual', 'product_ids' => '7,3']));

        // And a rail scoped to a category nobody chose leads nowhere rather than to /products/0.
        $this->assertNull($where->urlFor('product_slider', ['source' => 'category']));
    }

    public function test_the_app_is_told_the_destination_the_web_links_to(): void
    {
        // The web takes the URL and the app takes the typed action parsed out of the same URL, so
        // the two cannot drift: one is derived from the other.
        $where = app(SectionDestination::class);
        $settings = ['source' => 'category', 'source_id' => 11];

        $action = $where->actionFor('product_slider', $settings);

        $this->assertSame('category', $action['type']);
        $this->assertSame(11, $action['id'], 'the app opens a category list by id, not by slug');
        $this->assertSame($where->urlFor('product_slider', $settings), $action['url']);

        $this->assertSame('collection', $where->actionFor('product_slider', ['source' => 'top_rated'])['type']);
        $this->assertSame('none', $where->actionFor('spacer', [])['type'], 'a section that leads nowhere says so');
    }

    public function test_a_tabbed_section_carries_one_source_per_tab(): void
    {
        $tabs = app(ThemeSourceMap::class)->for('product_tabs', [], [
            ['type' => 'tab', 'settings' => ['source' => 'new_arrival', 'limit' => 4]],
            ['type' => 'tab', 'settings' => ['source' => 'manual', 'product_ids' => [5]]],
        ])['tabs'];

        $this->assertSame('/api/v1/products/new-arrival', $tabs[0]['endpoint']);
        $this->assertSame(4, $tabs[0]['params']['limit'], 'each tab keeps its own limit');
        $this->assertSame('/api/v1/products/by-ids', $tabs[1]['endpoint']);
        $this->assertSame('5', $tabs[1]['params']['ids']);
    }
}
