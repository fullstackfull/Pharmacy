<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\FlashDeal;
use App\Models\FlashDealProduct;
use App\Models\Product;
use App\Services\Theme\SectionDataResolver;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesCatalogueSchema;
use Tests\TestCase;

/**
 * A themed section shows what the merchant PICKED.
 *
 * The pickers store ids; these tests hold the resolver to what those ids mean — including the one
 * that bit in production: the dashboard allows only ONE active flash deal (activating one
 * deactivates the rest), so requiring "active" collapsed every flash-deal section onto the same
 * deal no matter what the merchant picked.
 */
class ThemeSectionSelectionTest extends TestCase
{
    use CreatesCatalogueSchema;

    private SectionDataResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->createCatalogueSchema();
        $this->resolver = app(SectionDataResolver::class);
    }

    private function makeDeal(string $title, bool $active, string $ends = '+5 days'): FlashDeal
    {
        return FlashDeal::create([
            'title' => $title,
            'slug' => str($title)->slug()->value(),
            'deal_type' => 'flash_deal',
            'start_date' => date('Y-m-d', strtotime('-1 day')),
            'end_date' => date('Y-m-d', strtotime($ends)),
            'status' => $active,
        ]);
    }

    public function test_a_picked_deal_renders_even_though_only_one_deal_can_be_active(): void
    {
        $running = $this->makeDeal('Running', active: true);
        $picked = $this->makeDeal('Picked', active: false);

        $this->assertSame($picked->id, $this->resolver->flashDeal($picked->id)['id']);
        $this->assertSame($running->id, $this->resolver->flashDeal()['id']);
    }

    public function test_two_sections_can_feature_two_different_deals(): void
    {
        $first = $this->makeDeal('First', active: true);
        $second = $this->makeDeal('Second', active: false);

        $this->assertNotSame(
            $this->resolver->flashDeal($first->id)['id'],
            $this->resolver->flashDeal($second->id)['id'],
        );
    }

    public function test_an_ended_deal_never_renders_a_dead_countdown(): void
    {
        $ended = $this->makeDeal('Ended', active: true, ends: '-1 day');

        $this->assertNull($this->resolver->flashDeal($ended->id));
        $this->assertNull($this->resolver->flashDeal());
    }

    public function test_an_automatic_section_skips_a_deal_already_shown_on_the_page(): void
    {
        $only = $this->makeDeal('Only running', active: true);

        $this->assertSame($only->id, $this->resolver->flashDeal()['id']);
        $this->assertNull($this->resolver->flashDeal(null, [$only->id]));
    }

    public function test_a_deal_carries_its_own_products(): void
    {
        $deal = $this->makeDeal('With products', active: true);
        $product = Product::create(['name' => 'Serum', 'status' => 1, 'request_status' => 1]);
        FlashDealProduct::create(['flash_deal_id' => $deal->id, 'product_id' => $product->id]);

        $products = $this->resolver->flashDealProducts($deal->id, 10);

        $this->assertCount(1, $products);
        $this->assertSame($product->id, $products->first()->id);
    }

    public function test_picked_categories_keep_the_order_they_were_picked_in(): void
    {
        $ids = collect(['Hair', 'Face', 'Body'])
            ->map(fn ($name) => Category::create(['name' => $name, 'position' => 0])->id)
            ->all();
        $picked = [$ids[2], $ids[0]];

        $this->assertSame($picked, $this->resolver->categories(12, implode(',', $picked))->pluck('id')->all());
    }

    public function test_picking_no_category_still_shows_the_top_level_ones(): void
    {
        Category::create(['name' => 'Top', 'position' => 0]);
        Category::create(['name' => 'Sub', 'position' => 1]);

        $this->assertSame(['Top'], $this->resolver->categories(12)->pluck('name')->all());
    }

    public function test_picked_products_keep_the_order_they_were_picked_in(): void
    {
        $ids = collect(['A', 'B', 'C'])
            ->map(fn ($name) => Product::create(['name' => $name, 'status' => 1, 'request_status' => 1])->id)
            ->all();
        $picked = [$ids[1], $ids[2], $ids[0]];

        $products = $this->resolver->products(['source' => 'manual', 'product_ids' => implode(',', $picked), 'limit' => 10]);

        $this->assertSame($picked, $products->pluck('id')->all());
    }

    public function test_a_category_pick_includes_everything_filed_under_it(): void
    {
        $parent = Category::create(['name' => 'Skincare', 'position' => 0]);
        $child = Category::create(['name' => 'Serums', 'position' => 1, 'parent_id' => $parent->id]);

        Product::create(['name' => 'Direct', 'status' => 1, 'request_status' => 1, 'category_id' => $parent->id]);
        Product::create(['name' => 'Filed under the child', 'status' => 1, 'request_status' => 1, 'sub_category_id' => $child->id]);
        Product::create(['name' => 'Elsewhere', 'status' => 1, 'request_status' => 1, 'category_id' => 999]);

        $names = $this->resolver
            ->products(['source' => 'category', 'source_id' => $parent->id, 'limit' => 10])
            ->pluck('name')->all();

        sort($names);
        $this->assertSame(['Direct', 'Filed under the child'], $names);
    }

    public function test_the_shipping_cut_off_counts_forward_to_the_cut_off_and_hides_once_it_passes(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-05-04 09:30:00'));

        $this->assertSame(6 * 3600 + 30 * 60, $this->resolver->shippingCutoff('16:00'));
        $this->assertNull($this->resolver->shippingCutoff('09:00'));

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_blocks_without_the_content_the_section_is_for_are_dropped(): void
    {
        $blocks = [
            ['settings' => ['title' => 'Has a cover', 'image' => 'cover.jpg']],
            ['settings' => ['title' => 'Has a clip', 'video' => 'clip.mp4']],
            ['settings' => ['title' => 'Nothing to show']],
        ];

        $withMedia = $this->resolver->blocksWithContent($blocks, either: ['image', 'video']);
        $this->assertSame(['Has a cover', 'Has a clip'], array_column(array_column($withMedia, 'settings'), 'title'));

        $pairs = $this->resolver->blocksWithContent(
            [['settings' => ['image' => 'a.jpg', 'after' => 'b.jpg']], ['settings' => ['image' => 'a.jpg']]],
            required: ['image', 'after'],
        );
        $this->assertCount(1, $pairs);
    }
}
