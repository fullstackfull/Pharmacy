<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Product;
use App\Services\Storefront\ProductPageSignalsService;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesCatalogueSchema;
use Tests\TestCase;

/**
 * The signals above the add-to-cart button.
 *
 * The viewers line is the one that needs holding to a contract: it is a merchandising widget, so
 * it must stay OFF unless the admin turns it on, stay inside the range they set, stay steady on a
 * refresh (a number that jumps on every reload reads as fake to a customer), and never appear on a
 * product nobody can buy.
 */
class ProductPageSignalsTest extends TestCase
{
    use CreatesCatalogueSchema;

    private ProductPageSignalsService $signals;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->createCatalogueSchema();
        $this->signals = app(ProductPageSignalsService::class);
    }

    private function setting(string $type, string|int $value): void
    {
        BusinessSetting::updateOrCreate(['type' => $type], ['value' => $value]);
        Cache::flush();
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Serum', 'status' => 1, 'request_status' => 1,
            'product_type' => 'physical', 'current_stock' => 10,
            'unit_price' => 100, 'discount' => 0, 'discount_type' => 'flat',
        ], $overrides));
    }

    public function test_viewers_stay_hidden_until_the_admin_turns_them_on(): void
    {
        $this->assertNull($this->signals->liveViewers($this->product()));
    }

    public function test_viewers_land_inside_the_admins_range(): void
    {
        $this->setting('product_live_viewers_status', 1);
        $this->setting('product_live_viewers_min', 20);
        $this->setting('product_live_viewers_max', 25);

        foreach (range(1, 12) as $index) {
            $viewers = $this->signals->liveViewers($this->product(['name' => 'P' . $index]));
            $this->assertGreaterThanOrEqual(20, $viewers);
            $this->assertLessThanOrEqual(25, $viewers);
        }
    }

    public function test_the_same_product_shows_the_same_number_on_a_refresh(): void
    {
        $this->setting('product_live_viewers_status', 1);
        $product = $this->product();

        $this->assertSame($this->signals->liveViewers($product), $this->signals->liveViewers($product));
    }

    public function test_a_sold_out_product_shows_no_viewers(): void
    {
        $this->setting('product_live_viewers_status', 1);

        $this->assertNull($this->signals->liveViewers($this->product(['current_stock' => 0])));
    }

    public function test_a_swapped_range_still_resolves_to_a_usable_one(): void
    {
        $this->setting('product_live_viewers_status', 1);
        $this->setting('product_live_viewers_min', 90);
        $this->setting('product_live_viewers_max', 3);

        [$min, $max] = $this->signals->viewerRange();
        $this->assertLessThan($max, $min);
        $this->assertNotNull($this->signals->liveViewers($this->product()));
    }

    public function test_the_authenticity_badge_follows_its_switch(): void
    {
        $this->assertNull($this->signals->authenticityBadge());

        $this->setting('product_authenticity_badge_status', 1);
        $this->setting('product_authenticity_badge_text', 'Sourced from the lab');

        $this->assertSame('Sourced from the lab', $this->signals->authenticityBadge());
    }

    public function test_a_flat_discount_still_reads_as_a_percentage(): void
    {
        $product = $this->product(['unit_price' => 200, 'discount' => 50, 'discount_type' => 'flat']);

        $this->assertSame(25, $this->signals->discountPercentage($product));
    }

    public function test_no_discount_means_no_percentage(): void
    {
        $this->assertSame(0, $this->signals->discountPercentage($this->product()));
    }

    public function test_the_short_description_prefers_the_meta_description(): void
    {
        $product = $this->product([
            'meta_description' => 'A featherlight serum.',
            'details' => '<p>Something much longer.</p>',
        ]);

        $this->assertSame('A featherlight serum.', $this->signals->shortDescription($product));
    }

    public function test_the_short_description_falls_back_to_the_stripped_details(): void
    {
        $product = $this->product(['details' => '<p>Cleanse,   <b>treat</b>, protect.</p>']);

        $this->assertSame('Cleanse, treat, protect.', $this->signals->shortDescription($product));
    }

    public function test_a_product_with_nothing_written_has_no_short_description(): void
    {
        $this->assertNull($this->signals->shortDescription($this->product()));
    }
}
