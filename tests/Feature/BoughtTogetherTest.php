<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Services\Storefront\BoughtTogetherService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesCatalogueSchema;
use Tests\TestCase;

/**
 * "Frequently bought together": the merchant's picks first, real co-purchases second, nothing
 * invented third.
 *
 * The panel sits under the buy buttons, so what it shows is a merchandising promise: a hand-picked
 * list must survive in the merchant's own order, an unpicked product must fall back to what
 * customers actually order alongside it, and a product with neither must render nothing rather
 * than padding itself with unrelated items.
 */
class BoughtTogetherTest extends TestCase
{
    use CreatesCatalogueSchema;

    private BoughtTogetherService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->createCatalogueSchema();

        if (!Schema::hasTable('order_details')) {
            Schema::create('order_details', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->timestamps();
            });
        }

        $this->enable();
        $this->service = app(BoughtTogetherService::class);
    }

    private function enable(bool $autoFill = true): void
    {
        BusinessSetting::updateOrCreate(['type' => 'bought_together_status'], ['value' => 1]);
        BusinessSetting::updateOrCreate(['type' => 'bought_together_limit'], ['value' => 6]);
        BusinessSetting::updateOrCreate(['type' => 'bought_together_auto_fill'], ['value' => $autoFill ? 1 : 0]);
        Cache::flush();
    }

    private function product(string $name, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => $name, 'status' => 1, 'request_status' => 1,
            'product_type' => 'physical', 'current_stock' => 5, 'unit_price' => 100,
        ], $overrides));
    }

    public function test_the_panel_stays_hidden_until_the_admin_turns_it_on(): void
    {
        BusinessSetting::updateOrCreate(['type' => 'bought_together_status'], ['value' => 0]);
        Cache::flush();

        $product = $this->product('Serum');
        $this->product('Cleanser');

        $this->assertTrue(app(BoughtTogetherService::class)->for($product)->isEmpty());
    }

    public function test_hand_picked_companions_render_in_the_order_they_were_picked(): void
    {
        $first = $this->product('Cleanser');
        $second = $this->product('Toner');
        $product = $this->product('Serum', ['bought_together_ids' => $second->id . ',' . $first->id]);

        $this->assertSame(
            [$second->id, $first->id],
            $this->service->for($product)->pluck('id')->all(),
        );
    }

    public function test_a_product_never_recommends_itself(): void
    {
        $other = $this->product('Cleanser');
        $product = $this->product('Serum');
        $product->bought_together_ids = $product->id . ',' . $other->id;

        $this->assertSame([$other->id], $this->service->for($product)->pluck('id')->all());
    }

    public function test_with_no_picks_it_uses_what_customers_actually_bought_together(): void
    {
        $product = $this->product('Serum');
        $withIt = $this->product('Cleanser');
        $alsoWithIt = $this->product('Toner');
        $unrelated = $this->product('Shampoo');

        // Two orders that contain the serum; the cleanser rides along in both.
        OrderDetail::create(['order_id' => 1, 'product_id' => $product->id]);
        OrderDetail::create(['order_id' => 1, 'product_id' => $withIt->id]);
        OrderDetail::create(['order_id' => 2, 'product_id' => $product->id]);
        OrderDetail::create(['order_id' => 2, 'product_id' => $withIt->id]);
        OrderDetail::create(['order_id' => 2, 'product_id' => $alsoWithIt->id]);
        // An order the serum is not part of.
        OrderDetail::create(['order_id' => 3, 'product_id' => $unrelated->id]);

        $companions = $this->service->for($product)->pluck('id')->all();

        $this->assertSame($withIt->id, $companions[0], 'the most co-purchased product comes first');
        $this->assertContains($alsoWithIt->id, $companions);
        $this->assertNotContains($unrelated->id, $companions);
    }

    public function test_auto_fill_can_be_switched_off(): void
    {
        $this->enable(autoFill: false);
        $product = $this->product('Serum');
        $withIt = $this->product('Cleanser');
        OrderDetail::create(['order_id' => 1, 'product_id' => $product->id]);
        OrderDetail::create(['order_id' => 1, 'product_id' => $withIt->id]);

        $this->assertTrue(app(BoughtTogetherService::class)->for($product)->isEmpty());
    }

    public function test_a_product_with_no_picks_and_no_shared_orders_shows_nothing(): void
    {
        $product = $this->product('Serum');
        $this->product('Cleanser');

        $this->assertTrue($this->service->for($product)->isEmpty());
    }

    public function test_the_limit_is_the_admins_and_stays_sane(): void
    {
        BusinessSetting::updateOrCreate(['type' => 'bought_together_limit'], ['value' => 99]);
        Cache::flush();

        $this->assertLessThanOrEqual(12, app(BoughtTogetherService::class)->limit());
    }
}
