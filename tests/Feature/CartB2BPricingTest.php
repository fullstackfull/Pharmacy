<?php

namespace Tests\Feature;

use App\Models\CustomerGroup;
use App\Models\CustomerGroupPrice;
use App\Utils\CartManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * B2B / wholesale pricing wired into the cart (Phase 3, Stage E).
 *
 * These assert the checkout seam — `CartManager::customerGroupPrice()`, the value that lands on
 * `Cart.price` at add time — not the resolver (covered by B2BPricingTest). The non-breaking contract
 * first: a guest and a retail customer both keep the base price, so the cart (and the mobile API, which
 * stores the same price) is unchanged until a customer is placed in a group.
 */
class CartB2BPricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['customer_group_prices', 'customer_group_customer', 'customer_groups'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('customer_groups', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('type')->default('wholesale');
            $t->decimal('default_discount_percent', 6, 2)->default(0); $t->string('status')->default('active');
            $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
        });
        Schema::create('customer_group_customer', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('customer_group_id'); $t->unsignedBigInteger('customer_id'); $t->timestamps();
        });
        Schema::create('customer_group_prices', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('customer_group_id'); $t->unsignedBigInteger('product_id');
            $t->decimal('price', 24, 2)->nullable(); $t->decimal('discount_percent', 6, 2)->nullable(); $t->timestamps();
        });
    }

    private object $product;

    private function product(): object
    {
        return (object) ['id' => 501];
    }

    private function group(float $defaultDiscount, int $customerId): CustomerGroup
    {
        $group = CustomerGroup::create(['name' => 'Wholesale', 'type' => 'wholesale', 'default_discount_percent' => $defaultDiscount, 'status' => 'active']);
        DB::table('customer_group_customer')->insert(['customer_group_id' => $group->id, 'customer_id' => $customerId]);

        return $group;
    }

    public function test_a_guest_keeps_the_base_price(): void
    {
        $this->assertSame(10000.0, CartManager::customerGroupPrice($this->product(), null, 10000));
    }

    public function test_a_retail_customer_in_no_group_keeps_the_base_price(): void
    {
        $this->assertSame(10000.0, CartManager::customerGroupPrice($this->product(), 7, 10000));
    }

    public function test_a_group_customer_gets_the_default_group_discount(): void
    {
        $this->group(10, customerId: 7);

        $this->assertEquals(9000.0, CartManager::customerGroupPrice($this->product(), 7, 10000));
    }

    public function test_a_per_product_fixed_override_wins(): void
    {
        $group = $this->group(10, customerId: 7);
        CustomerGroupPrice::create(['customer_group_id' => $group->id, 'product_id' => 501, 'price' => 8500]);

        $this->assertEquals(8500.0, CartManager::customerGroupPrice($this->product(), 7, 10000));
    }

    public function test_the_price_only_ever_lowers_never_raises(): void
    {
        // A group configured with a 0% discount can never charge more than base.
        $this->group(0, customerId: 7);

        $this->assertLessThanOrEqual(10000.0, CartManager::customerGroupPrice($this->product(), 7, 10000));
    }
}
