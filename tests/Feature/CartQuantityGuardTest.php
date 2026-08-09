<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Product;
use App\Utils\CartManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Quantity validation on the cart update path (Phase 2.3).
 *
 * The stock checks only asked "is the requested quantity larger than stock?", which a negative
 * number passes trivially. So a quantity of -50 was stored verbatim and cart_grand_total()
 * multiplied it by the unit price. Measured on a real cart before the fix: two items totalling
 * 6,100 became **-276,950** once one line was set to -50. The customer controls the sign, and
 * therefore the total.
 *
 * add_to_cart() was never exposed — its minimum_order_qty check (1 > -50) already refused these.
 * Only the update path was missing the guard, and both the storefront and the v1 API reach it
 * through this one function.
 */
class CartQuantityGuardTest extends TestCase
{
    private const GUEST_ID = 424242;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('carts');
        Schema::dropIfExists('products');

        // The cart path reaches getWebConfig() (shipping settings), which reads business_settings,
        // and Product eager-loads its translations. Providing both lets the real code run rather
        // than mocking around it.
        if (!Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $t) {
                $t->id();
                $t->string('type')->nullable();
                $t->text('value')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('translations')) {
            Schema::create('translations', function (Blueprint $t) {
                $t->id();
                $t->string('translationable_type');
                $t->unsignedBigInteger('translationable_id');
                $t->string('locale', 20);
                $t->string('key');
                $t->text('value')->nullable();
            });
        }
        // A successful update recalculates shipping, which reads these.
        if (!Schema::hasTable('shipping_types')) {
            Schema::create('shipping_types', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('seller_id')->nullable();
                $t->string('shipping_type')->default('order_wise');
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('category_shipping_costs')) {
            Schema::create('category_shipping_costs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('category_id')->nullable();
                $t->unsignedBigInteger('seller_id')->nullable();
                $t->decimal('cost', 24, 2)->default(0);
                $t->boolean('multiply_qty')->default(0);
                $t->timestamps();
            });
        }

        // Product carries a global scope that eager-loads translations AND reviews on every query,
        // so even Product::find() needs this table present.
        if (!Schema::hasTable('reviews')) {
            Schema::create('reviews', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('product_id')->nullable();
                $t->unsignedBigInteger('delivery_man_id')->nullable();
                $t->integer('rating')->default(0);
                $t->boolean('status')->default(1);
                $t->timestamps();
            });
        }

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->string('product_type')->default('physical');
            $t->integer('current_stock')->default(0);
            $t->integer('minimum_order_qty')->default(1);
            $t->decimal('unit_price', 24, 2)->default(0);
            $t->text('variation')->nullable();
            $t->timestamps();
        });
        Schema::create('carts', function (Blueprint $t) {
            $t->id();
            $t->string('customer_id')->nullable();
            $t->string('cart_group_id')->nullable();
            $t->unsignedBigInteger('product_id');
            $t->string('variant')->nullable();
            $t->integer('quantity')->default(1);
            $t->decimal('price', 24, 2)->default(0);
            $t->decimal('shipping_cost', 24, 2)->default(0);
            $t->boolean('is_guest')->default(1);
            $t->boolean('is_checked')->default(1);
            $t->timestamps();
        });

        session(['guest_id' => self::GUEST_ID]);
    }

    private function cartWith(array $productAttributes = [], int $quantity = 2): Cart
    {
        $product = Product::create(array_merge([
            'name' => 'Serum', 'product_type' => 'physical',
            'current_stock' => 10, 'minimum_order_qty' => 1,
            'unit_price' => 550, 'variation' => '[]',
        ], $productAttributes));

        return Cart::create([
            'customer_id' => self::GUEST_ID,
            'cart_group_id' => 'grp-1',
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->unit_price,
        ]);
    }

    private function update(Cart $cart, mixed $quantity): array
    {
        return CartManager::update_cart_qty(Request::create('/cart/update', 'POST', [
            'key' => $cart->id,
            'quantity' => $quantity,
            'guest_id' => self::GUEST_ID,
        ]));
    }

    /** The headline bug: a negative quantity produced a negative line total. */
    public function test_a_negative_quantity_is_refused_and_the_line_is_left_alone(): void
    {
        $cart = $this->cartWith();

        $result = $this->update($cart, -50);

        $this->assertSame(0, $result['status']);
        $this->assertSame(2, $cart->fresh()->quantity, 'the stored quantity must not change');
    }

    public function test_zero_is_refused(): void
    {
        $cart = $this->cartWith();

        $this->assertSame(0, $this->update($cart, 0)['status']);
        $this->assertSame(2, $cart->fresh()->quantity);
    }

    /** "3abc" must not slip through as 3 — it is not a quantity a client should be sending. */
    public function test_a_non_integer_quantity_is_refused(): void
    {
        $cart = $this->cartWith();

        foreach (['3abc', 'abc', '2.5', ''] as $value) {
            $this->assertSame(0, $this->update($cart, $value)['status'], var_export($value, true));
        }
        $this->assertSame(2, $cart->fresh()->quantity);
    }

    /**
     * The minimum is enforced when adding to the cart; without it here a customer could add the
     * required 5 and then edit the line down to 1.
     */
    public function test_a_quantity_below_the_minimum_order_is_refused(): void
    {
        $cart = $this->cartWith(['minimum_order_qty' => 5], quantity: 5);

        $this->assertSame(0, $this->update($cart, 1)['status']);
        $this->assertSame(5, $cart->fresh()->quantity);
    }

    // ---- the behaviour that must survive the fix ----

    public function test_a_valid_quantity_is_still_accepted(): void
    {
        $cart = $this->cartWith();

        $result = $this->update($cart, 4);

        $this->assertSame(1, $result['status']);
        $this->assertSame(4, $cart->fresh()->quantity);
    }

    public function test_a_quantity_beyond_stock_is_still_refused(): void
    {
        $cart = $this->cartWith(['current_stock' => 3]);

        $this->assertSame(0, $this->update($cart, 99)['status']);
        $this->assertSame(2, $cart->fresh()->quantity);
    }

    public function test_variant_stock_is_still_respected(): void
    {
        $product = Product::create([
            'name' => 'Shirt', 'product_type' => 'physical', 'current_stock' => 10,
            'minimum_order_qty' => 1, 'unit_price' => 100,
            'variation' => json_encode([
                ['type' => 'Small', 'price' => 100, 'sku' => 'S', 'qty' => 8],
                ['type' => 'Large', 'price' => 120, 'sku' => 'L', 'qty' => 2],
            ]),
        ]);
        $cart = Cart::create([
            'customer_id' => self::GUEST_ID, 'cart_group_id' => 'grp-1',
            'product_id' => $product->id, 'variant' => 'Large', 'quantity' => 1, 'price' => 120,
        ]);

        // total stock is 10, but this variant only has 2
        $this->assertSame(0, $this->update($cart, 5)['status']);
        $this->assertSame(1, $this->update($cart, 2)['status']);
    }

    /**
     * json_decode(null) returns null and count(null) is a TypeError on PHP 8 — the same shape as
     * the product-save crash fixed in Phase 1. A null variation must update, not fatal.
     */
    public function test_a_null_variation_does_not_fatal(): void
    {
        $cart = $this->cartWith(['variation' => null]);

        $this->assertSame(1, $this->update($cart, 3)['status']);
        $this->assertSame(3, $cart->fresh()->quantity);
    }

    /** The refusal must keep the shape the storefront JS and the v1 API already parse. */
    public function test_a_refusal_keeps_the_published_response_shape(): void
    {
        $cart = $this->cartWith();

        $result = $this->update($cart, -1);

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('qty', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertSame(2, $result['qty'], 'qty reports the quantity still in the cart');
    }
}
