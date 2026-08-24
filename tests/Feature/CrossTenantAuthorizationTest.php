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

        // The api guard is Passport's, and resolving it loads a signing key. A checkout of this
        // repository has none — they are generated per environment and never committed — so these
        // two tests failed everywhere except a machine that happened to have run the installer.
        // Generated here, once, only when absent: what is under test is who a request belongs to,
        // and a missing key says nothing about that.
        if (!file_exists(storage_path('oauth-private.key'))) {
            \Illuminate\Support\Facades\Artisan::call('passport:keys', ['--no-interaction' => true]);
        }

        foreach (['users', 'orders', 'reviews', 'business_settings', 'order_expected_delivery_histories'] as $t) {
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
            $t->unsignedBigInteger('seller_id')->nullable();
            $t->string('seller_is')->nullable();
            $t->string('order_status')->nullable();
            $t->string('payment_status')->nullable();
            $t->string('payment_method')->nullable();
            $t->decimal('order_amount', 24, 2)->default(0);
            $t->decimal('deliveryman_charge', 24, 2)->default(0);
            $t->date('expected_delivery_date')->nullable();
            $t->timestamps();
        });
        Schema::create('order_expected_delivery_histories', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('user_type')->nullable();
            $t->date('expected_delivery_date')->nullable();
            $t->string('cause')->nullable();
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

    // ---- OrderRepository::updateAmountDate: arbitrary-column write + cross-seller order (P0-adjacent) ----

    private function orderRepo(): \App\Contracts\Repositories\OrderRepositoryInterface
    {
        return app(\App\Contracts\Repositories\OrderRepositoryInterface::class);
    }

    private function amountDateRequest(array $data): Request
    {
        return Request::create('/vendor/orders/amount-date-update', 'POST', $data);
    }

    public function test_amount_date_update_rejects_a_non_whitelisted_field(): void
    {
        Order::create(['id' => 8001, 'seller_id' => 100, 'seller_is' => 'seller', 'payment_status' => 'unpaid', 'order_amount' => 500]);

        // field_name is attacker-controlled: try to flip payment_status to paid via the amount/date hook.
        $ok = $this->orderRepo()->updateAmountDate(
            $this->amountDateRequest(['order_id' => 8001, 'field_name' => 'payment_status', 'field_val' => 'paid']),
            userId: 100, userType: 'seller'
        );

        $this->assertFalse($ok, 'a column outside the whitelist must be refused');
        $this->assertSame('unpaid', Order::find(8001)->payment_status, 'payment_status must be untouched');
    }

    public function test_amount_date_update_refuses_another_sellers_order(): void
    {
        // Order belongs to seller 200; seller 100 tries to set its expected_delivery_date.
        Order::create(['id' => 8002, 'seller_id' => 200, 'seller_is' => 'seller', 'order_amount' => 500]);

        $ok = $this->orderRepo()->updateAmountDate(
            $this->amountDateRequest(['order_id' => 8002, 'field_name' => 'expected_delivery_date', 'field_val' => '2030-01-01']),
            userId: 100, userType: 'seller'
        );

        $this->assertFalse($ok, 'a seller cannot edit an order that is not theirs');
        $this->assertNull(Order::find(8002)->expected_delivery_date, 'victim order must be untouched');
    }

    public function test_amount_date_update_allows_a_seller_to_edit_their_own_order(): void
    {
        Order::create(['id' => 8003, 'seller_id' => 100, 'seller_is' => 'seller', 'order_amount' => 500]);

        $ok = $this->orderRepo()->updateAmountDate(
            $this->amountDateRequest(['order_id' => 8003, 'field_name' => 'expected_delivery_date', 'field_val' => '2030-01-01', 'cause' => 'restock']),
            userId: 100, userType: 'seller'
        );

        $this->assertTrue($ok, 'a seller may edit their own order');
        $this->assertStringContainsString('2030-01-01', (string) Order::find(8003)->expected_delivery_date);
    }
}
