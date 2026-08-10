<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DeliverySyriaWebhookController;
use App\Http\Middleware\DeliverySyriaWebhookAuthMiddleware;
use App\Models\DeliverySyriaParcel;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Services\DeliverySyria\DeliverySyriaConfigService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The inbound status webhook — authentication and the safety of what it does to an order.
 *
 * The overriding rule under test: an external courier call can record status and move an order through
 * the side-effect-free intermediate states, but it can NEVER drive the financially-loaded transitions
 * (delivered/canceled) nor resurrect an already-terminal order. If any of those guards regress, an
 * outside caller could mature earnings, restock inventory, or reopen a closed order — so each is pinned.
 */
class DeliverySyriaWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('business_settings');
        Schema::create('business_settings', function (Blueprint $t) {
            $t->id();
            $t->string('type')->nullable();
            $t->text('value')->nullable();
            $t->timestamps();
        });
        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('order_status')->nullable();
            $t->string('payment_status')->nullable();
            $t->decimal('order_amount', 24, 2)->default(0);
            $t->string('delivery_type')->nullable();
            $t->string('delivery_service_name')->nullable();
            $t->string('third_party_delivery_tracking_id')->nullable();
            $t->unsignedBigInteger('delivery_man_id')->nullable();
            $t->timestamps();
        });
        Schema::dropIfExists('order_status_histories');
        Schema::create('order_status_histories', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('user_type')->nullable();
            $t->string('status')->nullable();
            $t->string('cause')->nullable();
            $t->timestamps();
        });
        Schema::dropIfExists('delivery_syria_parcels');
        Schema::create('delivery_syria_parcels', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id')->index();
            $t->string('invoice_no')->unique();
            $t->string('parcel_id')->nullable();
            $t->string('tracking_number')->nullable();
            $t->string('wallet_status')->nullable();
            $t->decimal('delivery_charge', 24, 2)->nullable();
            $t->string('company_type')->nullable();
            $t->string('governorate')->nullable();
            $t->decimal('weight', 12, 3)->nullable();
            $t->unsignedTinyInteger('courier_status')->nullable();
            $t->string('courier_status_label')->nullable();
            $t->text('note')->nullable();
            $t->timestamp('status_updated_at')->nullable();
            $t->timestamps();
        });
        Cache::flush();

        app(DeliverySyriaConfigService::class)->save(['status' => 1, 'secret_token' => 'sk_test', 'webhook_token' => 'wh_test']);
    }

    private function order(int $id, string $status): Order
    {
        return Order::create(['id' => $id, 'order_status' => $status, 'payment_status' => 'unpaid', 'order_amount' => 500]);
    }

    private function webhookRequest(array $body, string $platform = 'deliverysyria', string $token = 'wh_test'): Request
    {
        $request = Request::create('/api/delivery-syria/orders/update-status', 'POST', $body);
        $request->headers->set('X-Platform', $platform);
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $request;
    }

    private function auth(Request $request): int
    {
        $mw = new DeliverySyriaWebhookAuthMiddleware(app(DeliverySyriaConfigService::class));

        return $mw->handle($request, fn ($r) => response('ok', 200))->getStatusCode();
    }

    // ---- authentication ----

    public function test_auth_rejects_wrong_platform_header(): void
    {
        $this->assertSame(401, $this->auth($this->webhookRequest(['product_order_id' => '1', 'status' => 0], platform: 'wrong')));
    }

    public function test_auth_rejects_wrong_token(): void
    {
        $this->assertSame(401, $this->auth($this->webhookRequest(['product_order_id' => '1', 'status' => 0], token: 'bad')));
    }

    public function test_auth_rejects_when_integration_disabled(): void
    {
        app(DeliverySyriaConfigService::class)->save(['status' => 0]);
        $this->assertSame(401, $this->auth($this->webhookRequest(['product_order_id' => '1', 'status' => 0])));
    }

    public function test_auth_accepts_valid_platform_and_token(): void
    {
        $this->assertSame(200, $this->auth($this->webhookRequest(['product_order_id' => '1', 'status' => 0])));
    }

    // ---- controller behaviour ----

    public function test_intermediate_status_reflects_into_order_status_and_is_recorded(): void
    {
        $this->order(100002, 'confirmed');

        $response = (new DeliverySyriaWebhookController())->updateStatus(
            $this->webhookRequest(['product_order_id' => '100002', 'status' => 6, 'note' => 'left the hub'])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->order_status_applied);
        $this->assertSame('out_for_delivery', Order::find(100002)->order_status);

        $parcel = DeliverySyriaParcel::where('invoice_no', '100002')->first();
        $this->assertSame(6, (int) $parcel->courier_status);
        $this->assertSame('Shipped', $parcel->courier_status_label);
        $this->assertSame('left the hub', $parcel->note);
        $this->assertSame(1, OrderStatusHistory::where('order_id', 100002)->where('user_type', 'delivery_syria')->count());
    }

    public function test_delivered_is_recorded_but_never_applied_to_order_status(): void
    {
        $this->order(100003, 'confirmed');

        $response = (new DeliverySyriaWebhookController())->updateStatus(
            $this->webhookRequest(['product_order_id' => '100003', 'status' => 1])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->getData()->order_status_applied);
        // order_status is untouched — finalizing (and maturing earnings) stays with the platform flow.
        $this->assertSame('confirmed', Order::find(100003)->order_status);
        $this->assertSame(1, (int) DeliverySyriaParcel::where('invoice_no', '100003')->value('courier_status'));
    }

    public function test_canceled_does_not_change_order_status(): void
    {
        $this->order(100004, 'processing');

        (new DeliverySyriaWebhookController())->updateStatus(
            $this->webhookRequest(['product_order_id' => '100004', 'status' => 2])
        );

        $this->assertSame('processing', Order::find(100004)->order_status);
    }

    public function test_a_terminal_order_is_not_resurrected_by_a_webhook(): void
    {
        $this->order(100005, 'delivered');

        $response = (new DeliverySyriaWebhookController())->updateStatus(
            $this->webhookRequest(['product_order_id' => '100005', 'status' => 3])
        );

        $this->assertFalse($response->getData()->order_status_applied);
        $this->assertSame('delivered', Order::find(100005)->order_status);
    }

    public function test_unknown_order_returns_404(): void
    {
        $response = (new DeliverySyriaWebhookController())->updateStatus(
            $this->webhookRequest(['product_order_id' => '999999', 'status' => 0])
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_invalid_status_code_is_rejected(): void
    {
        $this->order(100006, 'confirmed');

        $response = (new DeliverySyriaWebhookController())->updateStatus(
            $this->webhookRequest(['product_order_id' => '100006', 'status' => 8])
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_matches_the_order_by_third_party_tracking_id(): void
    {
        $order = $this->order(100007, 'confirmed');
        $order->update(['third_party_delivery_tracking_id' => 'TRK-XYZ']);

        $response = (new DeliverySyriaWebhookController())->updateStatus(
            $this->webhookRequest(['product_order_id' => 'TRK-XYZ', 'status' => 5])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('processing', Order::find(100007)->order_status);
    }

    public function test_matches_an_existing_parcel_by_invoice_no_and_updates_it_in_place(): void
    {
        $this->order(100008, 'confirmed');
        DeliverySyriaParcel::create(['order_id' => 100008, 'invoice_no' => 'INV-55', 'parcel_id' => 'p-1', 'tracking_number' => 'TRK-1']);

        (new DeliverySyriaWebhookController())->updateStatus(
            $this->webhookRequest(['product_order_id' => 'INV-55', 'status' => 6])
        );

        // updated in place — no second parcel row
        $this->assertSame(1, DeliverySyriaParcel::where('order_id', 100008)->count());
        $this->assertSame(6, (int) DeliverySyriaParcel::where('invoice_no', 'INV-55')->value('courier_status'));
        $this->assertSame('out_for_delivery', Order::find(100008)->order_status);
    }
}
