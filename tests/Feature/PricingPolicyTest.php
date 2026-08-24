<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Seller;
use App\Models\SellerPricingPolicy;
use App\Services\Marketplace\PricingPolicyService;
use App\Services\Marketplace\SellerPrincipal;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The floor a seller sets under their own prices.
 *
 * The below-cost detector already existed and was not enough: by the time it reports, the price has
 * been live and orders may have been taken at it. This is the same knowledge one step earlier.
 *
 * Two properties carry the whole design. The floor is measured against what a customer would
 * actually pay, not the list price — a floor that only read `unit_price` would be cleared by every
 * product with a large enough discount, which is exactly the case it exists to catch. And a margin
 * floor over a product with no recorded cost computes nothing rather than treating the missing cost
 * as zero, which would produce a floor of zero: enforcement that clears everything and looks like
 * enforcement is worse than none.
 */
class PricingPolicyTest extends TestCase
{
    private const SELLER = 1;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['seller_pricing_policies', 'products', 'sellers', 'audit_logs', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('status', 20)->default('approved');
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('added_by', 20)->default('seller');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name')->nullable();
            $table->decimal('unit_price', 24, 3)->default(0);
            $table->decimal('purchase_price', 24, 3)->default(0);
            $table->decimal('discount', 24, 3)->default(0);
            $table->string('discount_type', 20)->default('flat');
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 30)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 191)->nullable();
            $table->string('action', 60);
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 60)->nullable();
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->text('context')->nullable();
            $table->string('ip_address', 60)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        (require base_path('database/migrations/2026_09_15_000001_create_seller_pricing_policies_table.php'))->up();

        Seller::insert([['id' => self::SELLER, 'f_name' => 'Owner', 'l_name' => 'One', 'status' => 'approved']]);
    }

    private function pricing(): PricingPolicyService
    {
        return app(PricingPolicyService::class);
    }

    private function product(array $attributes = []): Product
    {
        $product = new Product();

        $product->forceFill(array_merge([
            'added_by' => 'seller',
            'user_id' => self::SELLER,
            'name' => 'A product',
            'unit_price' => 100,
            'purchase_price' => 60,
            'discount' => 0,
            'discount_type' => 'flat',
        ], $attributes))->save();

        return $product;
    }

    private function policy(array $attributes = []): SellerPricingPolicy
    {
        return SellerPricingPolicy::create(array_merge([
            'seller_id' => self::SELLER,
            'enforce' => true,
        ], $attributes));
    }

    public function test_a_shop_with_no_policy_is_not_constrained(): void
    {
        $result = $this->pricing()->check($this->product(), 1, 0, 'flat');

        $this->assertTrue($result['ok']);
    }

    public function test_a_policy_that_is_not_enforced_refuses_nothing(): void
    {
        $this->policy(['min_margin_percent' => 50, 'enforce' => false]);

        // Off until the seller turns it on: a floor that started refusing prices the day it shipped
        // would block whatever the shop is already doing on purpose.
        $this->assertTrue($this->pricing()->check($this->product(), 10, 0, 'flat')['ok']);
    }

    public function test_an_enforced_policy_with_nothing_in_it_refuses_nothing(): void
    {
        $this->policy();

        // Not the same as a floor of zero. Nothing was set, so nothing is claimed.
        $this->assertTrue($this->pricing()->check($this->product(), 1, 0, 'flat')['ok']);
    }

    public function test_a_margin_floor_is_measured_over_the_recorded_cost(): void
    {
        $this->policy(['min_margin_percent' => 20]);
        $product = $this->product(['purchase_price' => 60]);

        // 60 + 20% = 72.
        $this->assertTrue($this->pricing()->check($product, 72, 0, 'flat')['ok']);

        $refused = $this->pricing()->check($product, 71.99, 0, 'flat');
        $this->assertFalse($refused['ok']);
        $this->assertSame(72.0, $refused['floor']);
    }

    public function test_a_margin_floor_says_nothing_about_a_product_with_no_recorded_cost(): void
    {
        $this->policy(['min_margin_percent' => 50]);
        $product = $this->product(['purchase_price' => 0]);

        // Treating a missing cost as zero would produce a floor of zero: enforcement that clears
        // everything and looks like enforcement.
        $this->assertTrue($this->pricing()->check($product, 0.01, 0, 'flat')['ok']);
        $this->assertNull($this->pricing()->floorFor($product, $this->pricing()->forSeller(self::SELLER)));
    }

    public function test_an_absolute_floor_applies_whether_or_not_a_cost_is_recorded(): void
    {
        $this->policy(['min_price' => 50]);
        $product = $this->product(['purchase_price' => 0]);

        $this->assertTrue($this->pricing()->check($product, 50, 0, 'flat')['ok']);
        $this->assertFalse($this->pricing()->check($product, 49.99, 0, 'flat')['ok']);
    }

    public function test_the_higher_of_the_two_floors_wins(): void
    {
        $this->policy(['min_margin_percent' => 20, 'min_price' => 90]);
        $product = $this->product(['purchase_price' => 60]);

        // Margin says 72, the absolute floor says 90. A seller who set both meant both.
        $this->assertSame(90.0, $this->pricing()->floorFor($product, $this->pricing()->forSeller(self::SELLER)));
        $this->assertFalse($this->pricing()->check($product, 80, 0, 'flat')['ok']);
    }

    public function test_the_floor_is_measured_against_what_a_customer_would_pay(): void
    {
        $this->policy(['min_price' => 90]);
        $product = $this->product();

        // A list price of 100 clears a floor of 90 — until the discount is applied. A floor that
        // only read unit_price would be cleared by every product with a large enough discount,
        // which is exactly the case it exists to catch.
        $this->assertTrue($this->pricing()->check($product, 100, 0, 'flat')['ok']);
        $this->assertFalse($this->pricing()->check($product, 100, 20, 'flat')['ok']);
        $this->assertFalse($this->pricing()->check($product, 100, 20, 'percent')['ok']);
        $this->assertTrue($this->pricing()->check($product, 100, 10, 'percent')['ok']);
    }

    public function test_a_refusal_says_which_floor_and_what_the_price_would_have_been(): void
    {
        $this->policy(['min_price' => 90]);

        $refused = $this->pricing()->check($this->product(), 100, 30, 'flat');

        // A seller reading a failure list needs the numbers, not "no".
        $this->assertSame(90.0, $refused['floor']);
        $this->assertSame(70.0, $refused['price']);
        $this->assertSame('pricing_reason_below_your_floor', $refused['reason']);
    }

    public function test_one_shops_floor_says_nothing_about_another_shops_product(): void
    {
        $this->policy(['min_price' => 90]);
        $theirs = $this->product(['user_id' => 2]);

        $this->assertTrue($this->pricing()->check($theirs, 1, 0, 'flat')['ok']);
    }

    public function test_a_marketplace_owned_product_is_not_governed_by_a_sellers_floor(): void
    {
        $this->policy(['min_price' => 90]);
        $inhouse = $this->product(['added_by' => 'admin', 'user_id' => self::SELLER]);

        $this->assertTrue($this->pricing()->check($inhouse, 1, 0, 'flat')['ok']);
    }

    public function test_changing_the_floor_takes_effect_immediately_rather_than_next_request(): void
    {
        $principal = SellerPrincipal::owner(Seller::find(self::SELLER));
        $product = $this->product();

        // One instance throughout, which is how it is used in production now that it is a
        // singleton — and the only arrangement in which the cache can be wrong.
        $service = $this->pricing();
        $this->assertTrue($service->check($product, 10, 0, 'flat')['ok']);

        $service->save($principal, ['min_price' => 90, 'enforce' => true]);

        // The per-request cache has to be dropped on write, or a bulk job that set a floor and then
        // ran would run against the floor that existed before it.
        $this->assertFalse($service->check($product, 10, 0, 'flat')['ok']);
    }

    public function test_changing_the_floor_is_recorded(): void
    {
        $principal = SellerPrincipal::owner(Seller::find(self::SELLER));

        $this->pricing()->save($principal, ['min_price' => 90, 'enforce' => true]);
        $this->pricing()->save($principal, ['min_price' => 10, 'enforce' => true]);

        // A floor that quietly moved explains nothing when a price refused yesterday goes through
        // today.
        $entries = \DB::table('audit_logs')->where('action', 'seller.pricing_policy_changed')->get();
        $this->assertCount(2, $entries);
        $this->assertNull($entries[0]->before);
        $this->assertStringContainsString('90', (string) $entries[1]->before);
    }

    public function test_saving_replaces_rather_than_accumulates(): void
    {
        $principal = SellerPrincipal::owner(Seller::find(self::SELLER));

        $this->pricing()->save($principal, ['min_margin_percent' => 20, 'min_price' => 90, 'enforce' => true]);
        $this->pricing()->save($principal, ['min_price' => 90, 'enforce' => true]);

        // A field left out of the request is cleared, not kept: this is the seller's whole policy,
        // and a margin they removed must actually be gone.
        $this->assertSame(1, SellerPricingPolicy::count());
        $this->assertNull(SellerPricingPolicy::first()->min_margin_percent);
    }
}
