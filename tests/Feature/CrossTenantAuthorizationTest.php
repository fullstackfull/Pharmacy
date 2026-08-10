<?php

namespace Tests\Feature;

use App\Http\Controllers\RestAPI\v1\OrderController;
use App\Http\Controllers\RestAPI\v1\ProductController;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression tests for cross-tenant authorization holes found in the stabilization gate.
 *
 * The rule under test: a state-changing endpoint keyed by a client-supplied id must be scoped to the
 * authenticated owner. Before the fix, order_cancel and deleteReviewImage loaded the row by id alone,
 * so any authenticated caller could act on another customer's order/review (IDOR). These pin that the
 * wrong tenant is now refused and the victim's row is left untouched.
 */
class CrossTenantAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['users', 'orders', 'reviews', 'business_settings'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('business_settings', function (Blueprint $t) {
            $t->id();
            $t->string('type')->nullable();
            $t->text('value')->nullable();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('f_name')->nullable();
            $t->string('email')->nullable();
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('order_status')->nullable();
            $t->string('payment_method')->nullable();
            $t->decimal('order_amount', 24, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('reviews', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->text('attachment')->nullable();
            $t->timestamps();
        });
    }

    private function customer(int $id): User
    {
        return User::create(['id' => $id, 'f_name' => 'c' . $id, 'email' => "c{$id}@t.com"]);
    }

    public function test_a_customer_cannot_cancel_another_customers_order(): void
    {
        $victim = $this->customer(11);
        $attacker = $this->customer(22);
        Order::create(['id' => 5001, 'customer_id' => $victim->id, 'order_status' => 'pending', 'payment_method' => 'cash_on_delivery', 'order_amount' => 100]);

        $this->actingAs($attacker, 'api');
        $response = app(OrderController::class)
            ->order_cancel(Request::create('/api/v1/order/cancel-order', 'GET', ['order_id' => 5001]));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('pending', Order::find(5001)->order_status, 'victim order must be untouched');
    }

    public function test_a_customer_cannot_delete_another_customers_review_image(): void
    {
        $victim = $this->customer(11);
        $attacker = $this->customer(22);
        Review::create(['id' => 7001, 'customer_id' => $victim->id, 'product_id' => 1, 'attachment' => json_encode(['keep.png', 'target.png'])]);

        $this->actingAs($attacker, 'api');
        $response = app(ProductController::class)
            ->deleteReviewImage(Request::create('/api/v1/products/review/delete-image', 'DELETE', ['id' => 7001, 'name' => 'target.png']));

        $this->assertSame(403, $response->getStatusCode());
        $rawAttachment = \Illuminate\Support\Facades\DB::table('reviews')->where('id', 7001)->value('attachment');
        $this->assertStringContainsString('target.png', (string) $rawAttachment, 'victim review image must be untouched');
    }
}
