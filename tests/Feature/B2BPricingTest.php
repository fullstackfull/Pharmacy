<?php

namespace Tests\Feature;

use App\Models\CustomerGroup;
use App\Models\CustomerGroupPrice;
use App\Services\Marketplace\B2BPricingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * B2B / wholesale price resolution (Phase 3, Stage E).
 *
 * The non-breaking contract, asserted first: a customer in no group pays the base price. Then the
 * resolution ladder — a group default discount, a per-product discount, a fixed override — and that
 * the best price wins across several groups, and inactive groups are ignored.
 */
class B2BPricingTest extends TestCase
{
    private B2BPricingService $svc;

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

        $this->svc = new B2BPricingService();
    }

    private function group(string $name, float $defaultDiscount = 0, string $status = 'active'): CustomerGroup
    {
        return CustomerGroup::create(['name' => $name, 'default_discount_percent' => $defaultDiscount, 'status' => $status]);
    }

    private function join(int $groupId, int $customerId): void
    {
        DB::table('customer_group_customer')->insert(['customer_group_id' => $groupId, 'customer_id' => $customerId]);
    }

    public function test_a_customer_in_no_group_pays_the_base_price(): void
    {
        $r = $this->svc->priceFor(1, 500, 100.0);

        $this->assertSame(100.0, $r['price']);
        $this->assertSame('base', $r['source']);
        $this->assertNull($r['group_id']);
    }

    public function test_a_group_default_discount_applies(): void
    {
        $g = $this->group('Wholesale', 20);
        $this->join($g->id, 500);

        $r = $this->svc->priceFor(1, 500, 100.0);

        $this->assertSame(80.0, $r['price'], '20% off 100');
        $this->assertSame('discount', $r['source']);
        $this->assertSame($g->id, $r['group_id']);
    }

    public function test_a_per_product_discount_overrides_the_group_default(): void
    {
        $g = $this->group('Wholesale', 10);
        $this->join($g->id, 500);
        CustomerGroupPrice::create(['customer_group_id' => $g->id, 'product_id' => 1, 'discount_percent' => 30]);

        $this->assertSame(70.0, $this->svc->priceFor(1, 500, 100.0)['price'], 'per-product 30% beats the 10% default');
    }

    public function test_a_fixed_price_override_wins_over_any_discount(): void
    {
        $g = $this->group('Contract', 50);
        $this->join($g->id, 500);
        CustomerGroupPrice::create(['customer_group_id' => $g->id, 'product_id' => 1, 'price' => 42.5]);

        $r = $this->svc->priceFor(1, 500, 100.0);
        $this->assertSame(42.5, $r['price']);
        $this->assertSame('fixed', $r['source']);
    }

    public function test_the_best_price_wins_across_several_groups(): void
    {
        $a = $this->group('A', 10);
        $b = $this->group('B', 35);
        $this->join($a->id, 500);
        $this->join($b->id, 500);

        // A gives 90, B gives 65 -> the customer pays 65.
        $r = $this->svc->priceFor(1, 500, 100.0);
        $this->assertSame(65.0, $r['price']);
        $this->assertSame($b->id, $r['group_id']);
    }

    public function test_an_inactive_group_does_not_apply(): void
    {
        $g = $this->group('Old', 40, 'inactive');
        $this->join($g->id, 500);

        $this->assertSame(100.0, $this->svc->priceFor(1, 500, 100.0)['price']);
    }

    public function test_a_zero_base_price_is_returned_untouched(): void
    {
        $g = $this->group('Wholesale', 20);
        $this->join($g->id, 500);

        $this->assertSame(0.0, $this->svc->priceFor(1, 500, 0.0)['price']);
    }
}
