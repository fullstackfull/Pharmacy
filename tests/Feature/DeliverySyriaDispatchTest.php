<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\Order\DeliverySyriaDispatchController;
use App\Models\DeliverySyriaGovernorateRate;
use App\Models\DeliverySyriaParcel;
use App\Models\Order;
use App\Services\DeliverySyria\DeliverySyriaClient;
use App\Services\DeliverySyria\DeliverySyriaConfigService;
use App\Services\DeliverySyria\DeliverySyriaRateCalculator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Dispatching an order to the courier (HTTP faked).
 *
 * Pinned: a successful dispatch persists the courier parcel and writes the order's existing
 * third-party-delivery fields (not any invented column); a repeat dispatch of an already-shipped order
 * is refused locally and makes no call; wallet_status=pending_payment is recorded rather than mistaken
 * for a completed payment; and a disabled integration never calls out.
 */
class DeliverySyriaDispatchTest extends TestCase
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
            $t->text('shipping_address_data')->nullable();
            $t->string('order_note')->nullable();
            $t->string('delivery_type')->nullable();
            $t->string('delivery_service_name')->nullable();
            $t->string('third_party_delivery_tracking_id')->nullable();
            $t->unsignedBigInteger('delivery_man_id')->nullable();
            $t->timestamps();
        });
        Schema::dropIfExists('delivery_syria_governorate_rates');
        Schema::create('delivery_syria_governorate_rates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('code')->unique();
            $t->string('governorate');
            $t->decimal('base_cost', 24, 2)->default(0);
            $t->timestamp('synced_at')->nullable();
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

        app(DeliverySyriaConfigService::class)->save(['status' => 1, 'secret_token' => 'sk_test', 'webhook_token' => 'wh_test', 'hub_id' => '2']);
        DeliverySyriaGovernorateRate::create(['code' => 2939, 'governorate' => 'Damascus', 'base_cost' => 10]);
    }

    private function seedOrder(int $id = 100002): void
    {
        Order::create([
            'id' => $id,
            'order_status' => 'confirmed',
            'payment_status' => 'unpaid',
            'order_amount' => 500,
            'shipping_address_data' => ['contact_person_name' => 'Salim', 'phone' => '0988', 'address' => 'Mezzeh', 'city' => 'Damascus', 'latitude' => '33.5', 'longitude' => '36.2'],
            'order_note' => 'call before delivery',
        ]);
    }

    private function controller(): DeliverySyriaDispatchController
    {
        return new DeliverySyriaDispatchController(
            app(DeliverySyriaConfigService::class),
            app(DeliverySyriaClient::class),
            app(DeliverySyriaRateCalculator::class),
        );
    }

    private function dispatchRequest(array $body): Request
    {
        return Request::create('/admin/orders/delivery-syria/dispatch', 'POST', $body);
    }

    public function test_a_successful_dispatch_persists_the_parcel_and_writes_the_order_delivery_fields(): void
    {
        $this->seedOrder();
        Http::fake([
            '*/api/parcel/create-with-security' => Http::response([
                'success' => true,
                'parcel_id' => 26,
                'tracking_number' => 'TRK-9',
                'wallet_status' => 'deducted',
                'calculation' => ['company_type' => 'delivery', 'calculated_delivery_charge' => 270],
            ], 200),
        ]);

        $this->controller()->dispatchOrder($this->dispatchRequest(['order_id' => 100002, 'governorate_code' => 2939, 'weight' => 3]));

        $parcel = DeliverySyriaParcel::where('invoice_no', '100002')->first();
        $this->assertNotNull($parcel);
        $this->assertSame('26', (string) $parcel->parcel_id);
        $this->assertSame('TRK-9', $parcel->tracking_number);
        $this->assertSame('deducted', $parcel->wallet_status);
        $this->assertSame('Damascus', $parcel->governorate);

        $order = Order::find(100002);
        $this->assertSame('third_party_delivery', $order->delivery_type);
        $this->assertSame('Delivery Syria', $order->delivery_service_name);
        $this->assertSame('TRK-9', $order->third_party_delivery_tracking_id);

        Http::assertSent(fn ($request) => $request['invoice_no'] === '100002'
            && $request['weight'] === '3'
            && $request['Governorate'] === 'Damascus');
    }

    public function test_dispatch_is_idempotent_and_makes_no_second_call(): void
    {
        $this->seedOrder();
        DeliverySyriaParcel::create(['order_id' => 100002, 'invoice_no' => '100002', 'parcel_id' => 'already', 'tracking_number' => 'OLD']);
        Http::fake();

        $this->controller()->dispatchOrder($this->dispatchRequest(['order_id' => 100002, 'governorate_code' => 2939, 'weight' => 3]));

        Http::assertNothingSent();
        $this->assertSame(1, DeliverySyriaParcel::where('order_id', 100002)->count());
        $this->assertSame('already', DeliverySyriaParcel::where('invoice_no', '100002')->value('parcel_id'));
    }

    public function test_pending_payment_wallet_status_is_recorded_not_treated_as_paid(): void
    {
        $this->seedOrder();
        Http::fake([
            '*/api/parcel/create-with-security' => Http::response([
                'success' => true,
                'parcel_id' => 27,
                'tracking_number' => 'TRK-P',
                'wallet_status' => 'pending_payment',
                'calculation' => ['company_type' => 'delivery', 'calculated_delivery_charge' => 270],
            ], 200),
        ]);

        $this->controller()->dispatchOrder($this->dispatchRequest(['order_id' => 100002, 'governorate_code' => 2939, 'weight' => 3]));

        $this->assertSame('pending_payment', DeliverySyriaParcel::where('invoice_no', '100002')->value('wallet_status'));
    }

    public function test_a_disabled_integration_does_not_dispatch(): void
    {
        app(DeliverySyriaConfigService::class)->save(['status' => 0]);
        $this->seedOrder();
        Http::fake();

        $this->controller()->dispatchOrder($this->dispatchRequest(['order_id' => 100002, 'governorate_code' => 2939, 'weight' => 3]));

        Http::assertNothingSent();
        $this->assertSame(0, DeliverySyriaParcel::count());
    }
}
