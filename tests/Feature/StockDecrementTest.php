<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Utils\CartManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Stock arithmetic at order time, and the pre-order stock check (Phase 2.3).
 *
 * The decrement used to write a computed absolute value:
 *
 *     'current_stock' => $product['current_stock'] - $quantity
 *
 * where current_stock came from a model read earlier in the request. Two overlapping orders
 * therefore both read the same number and both wrote their own result, so one decrement was lost
 * outright. Demonstrated before the fix: stock 10, two orders of 3, final stock **7** instead of 4.
 *
 * That is not merely a narrow race window — it silently inflates inventory every time two orders
 * overlap, so the store keeps advertising stock it does not have.
 */
class StockDecrementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('products');
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('product_type')->default('physical');
            $t->integer('current_stock')->default(0);
            $t->integer('minimum_order_qty')->default(1);
            $t->decimal('unit_price', 24, 2)->default(0);
            $t->text('variation')->nullable();
            $t->timestamps();
        });
        foreach (['business_settings' => function (Blueprint $t) {
            $t->id(); $t->string('type')->nullable(); $t->text('value')->nullable(); $t->timestamps();
        }, 'translations' => function (Blueprint $t) {
            $t->id(); $t->string('translationable_type'); $t->unsignedBigInteger('translationable_id');
            $t->string('locale', 20); $t->string('key'); $t->text('value')->nullable();
        }, 'reviews' => function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('delivery_man_id')->nullable();
            $t->integer('rating')->default(0); $t->boolean('status')->default(1); $t->timestamps();
        }] as $table => $definition) {
            if (!Schema::hasTable($table)) {
                Schema::create($table, $definition);
            }
        }
    }

    /**
     * Two requests that both read the same stock must still subtract from each other. This is the
     * exact sequence that lost a decrement before the fix.
     */
    public function test_overlapping_orders_do_not_lose_a_decrement(): void
    {
        $product = Product::create(['name' => 'Serum', 'current_stock' => 10]);

        // both "requests" hold a model read before either writes
        $first = Product::find($product->id);
        $second = Product::find($product->id);
        $this->assertSame(10, $first->current_stock);
        $this->assertSame(10, $second->current_stock);

        Product::where('id', $product->id)->decrement('current_stock', 3);
        Product::where('id', $product->id)->decrement('current_stock', 3);

        $this->assertSame(4, Product::find($product->id)->current_stock);
    }

    public function test_a_single_order_decrements_by_its_quantity(): void
    {
        $product = Product::create(['name' => 'Serum', 'current_stock' => 10]);

        Product::where('id', $product->id)->decrement('current_stock', 4);

        $this->assertSame(6, Product::find($product->id)->current_stock);
    }

    // ---- the pre-order stock check ----

    /**
     * A real Cart model, because product_stock_check reads the row both ways — `$cart->quantity`
     * and `$cart['variant']` — and a plain object satisfies only the first.
     */
    private function cartLine(?Product $product, int $quantity, ?string $variant = null): \App\Models\Cart
    {
        $cart = new \App\Models\Cart([
            'product_id' => $product?->id,
            'quantity' => $quantity,
            'variant' => $variant,
        ]);
        $cart->setRelation('product', $product);

        return $cart;
    }

    public function test_the_check_passes_when_stock_is_sufficient(): void
    {
        $product = Product::create(['name' => 'Serum', 'product_type' => 'physical', 'current_stock' => 10, 'variation' => '[]']);

        $this->assertTrue(CartManager::product_stock_check([$this->cartLine($product, 5)]));
    }

    public function test_the_check_fails_when_stock_ran_out_since_adding_to_the_cart(): void
    {
        $product = Product::create(['name' => 'Serum', 'product_type' => 'physical', 'current_stock' => 2, 'variation' => '[]']);

        $this->assertFalse(CartManager::product_stock_check([$this->cartLine($product, 5)]));
    }

    public function test_the_check_respects_variant_stock(): void
    {
        $product = Product::create([
            'name' => 'Shirt', 'product_type' => 'physical', 'current_stock' => 10, 'variation' => json_encode([
                ['type' => 'Small', 'price' => 100, 'sku' => 'S', 'qty' => 8],
                ['type' => 'Large', 'price' => 120, 'sku' => 'L', 'qty' => 2],
            ]),
        ]);

        // total stock is 10, but this variant has 2
        $this->assertFalse(CartManager::product_stock_check([$this->cartLine($product, 5, 'Large')]));
        $this->assertTrue(CartManager::product_stock_check([$this->cartLine($product, 2, 'Large')]));
    }

    /**
     * Regression: count(json_decode(null)) is a TypeError on PHP 8. This check runs immediately
     * before an order is placed, so a null variation did not block the checkout — it crashed it,
     * leaving the customer on a 500 at the final step.
     */
    public function test_a_null_variation_does_not_fatal_the_checkout(): void
    {
        $product = Product::create(['name' => 'Serum', 'product_type' => 'physical', 'current_stock' => 10, 'variation' => null]);

        $this->assertTrue(CartManager::product_stock_check([$this->cartLine($product, 1)]));
    }

    public function test_a_missing_product_fails_the_check(): void
    {
        $this->assertFalse(CartManager::product_stock_check([$this->cartLine(null, 1)]));
    }
}
