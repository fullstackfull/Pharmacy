<?php

namespace Tests\Feature;

use App\Services\Retention\ReviewEligibilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Who is allowed to review what (Phase 2.4).
 *
 * Neither review endpoint checked. `product_id` and `order_id` came from the client and were
 * written straight to the row, so any signed-in customer could post a review for any product,
 * attributed to any order id — including an order that was never theirs. On a pharmacy catalogue
 * that matters more than usual: ratings are what customers use to choose between medicines.
 *
 * The rule is deliberately narrow — owns the order, product is on it — because *when* a store opens
 * reviews is a policy decision, and inventing a delivery-status rule here could silently stop
 * legitimate customers from reviewing.
 */
class ReviewEligibilityTest extends TestCase
{
    private ReviewEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_details');

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->boolean('is_guest')->default(0);
            $t->timestamps();
        });
        Schema::create('order_details', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->timestamps();
        });

        DB::table('orders')->insert([
            ['id' => 100, 'customer_id' => 7, 'is_guest' => 0],
            ['id' => 200, 'customer_id' => 9, 'is_guest' => 0],
            ['id' => 300, 'customer_id' => 55555, 'is_guest' => 1],
        ]);
        DB::table('order_details')->insert([
            ['order_id' => 100, 'product_id' => 42],
            ['order_id' => 200, 'product_id' => 77],
            ['order_id' => 300, 'product_id' => 42],
        ]);

        $this->service = new ReviewEligibilityService();
    }

    public function test_a_customer_may_review_a_product_from_their_own_order(): void
    {
        $this->assertTrue($this->service->customerMayReview(7, 100, 42));
    }

    /** The headline hole: reviewing against somebody else's order id. */
    public function test_a_customer_may_not_review_against_another_customers_order(): void
    {
        $this->assertFalse($this->service->customerMayReview(7, 200, 77));
    }

    public function test_a_customer_may_not_review_a_product_that_was_not_on_the_order(): void
    {
        $this->assertFalse($this->service->customerMayReview(7, 100, 77));
    }

    public function test_an_unknown_order_is_refused(): void
    {
        $this->assertFalse($this->service->customerMayReview(7, 999, 42));
    }

    /** A guest order is not a customer's order, even if the ids happen to collide. */
    public function test_a_guest_order_does_not_qualify(): void
    {
        $this->assertFalse($this->service->customerMayReview(55555, 300, 42));
    }

    public function test_missing_identifiers_are_refused_rather_than_matching_everything(): void
    {
        $this->assertFalse($this->service->customerMayReview(null, 100, 42));
        $this->assertFalse($this->service->customerMayReview(7, null, 42));
        $this->assertFalse($this->service->customerMayReview(7, 100, null));
    }

    public function test_a_missing_table_denies_rather_than_allows(): void
    {
        Schema::dropIfExists('order_details');

        $this->assertFalse($this->service->customerMayReview(7, 100, 42), 'it must fail closed');
    }
}
