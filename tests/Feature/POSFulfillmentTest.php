<?php

namespace Tests\Feature;

use App\Models\ShippingAddress;
use App\Services\OrderDetailsService;
use App\Services\OrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The POS fulfillment contract (Phase 12).
 *
 * A POS sale is either an instant sale — delivered and paid on the spot,
 * exactly the pre-feature behavior — or a home delivery, which enters the
 * standard order lifecycle: created pending, cash stored as
 * cash_on_delivery/unpaid and settled by the regular status machinery.
 *
 * verification_code is pinned by a production incident: orders.verification_code
 * is NOT NULL DEFAULT '0', and an explicit null for instant sales made every
 * instant order-place 500 (caught by the browser regression battery). Instant
 * sales must therefore produce the column default '0', never null, while
 * delivery orders mint a real six-digit code for the delivery-man app.
 */
class POSFulfillmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $table) {
                $table->id();
                $table->string('type')->nullable();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }
        \Illuminate\Support\Facades\DB::table('business_settings')->updateOrInsert(
            ['type' => 'shipping_method'],
            ['value' => 'inhouse_shipping', 'updated_at' => now(), 'created_at' => now()]
        );

        // getTaxSystemType() (VatTaxManagement) looks up the active tax setup.
        if (!Schema::hasTable('system_tax_setups')) {
            Schema::create('system_tax_setups', function (Blueprint $table) {
                $table->id();
                $table->string('tax_payer')->nullable();
                $table->boolean('is_active')->default(0);
                $table->boolean('is_default')->default(0);
                $table->string('tax_type')->nullable();
                $table->boolean('is_included')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('shipping_addresses')) {
            Schema::create('shipping_addresses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('contact_person_name')->nullable();
                $table->string('address_type')->nullable();
                $table->text('address')->nullable();
                $table->string('city')->nullable();
                $table->string('zip')->nullable();
                $table->string('phone')->nullable();
                $table->string('country')->nullable();
                $table->boolean('is_billing')->default(0);
                $table->timestamps();
            });
        }
        ShippingAddress::query()->delete();
    }

    private function makeOrder(string $fulfillment, string $paymentType, int $userId = 21): array
    {
        return app(OrderService::class)->getPOSOrderData(
            orderId: 900001,
            cart: [],
            amount: 100.0,
            totalTaxAmount: 0.0,
            paidAmount: $paymentType === 'cash' && $fulfillment === 'instant' ? 100.0 : 0.0,
            paymentType: $paymentType,
            addedBy: 'seller',
            userId: $userId,
            fulfillment: $fulfillment,
        );
    }

    public function test_instant_sale_keeps_the_pre_feature_contract(): void
    {
        $order = $this->makeOrder('instant', 'cash');

        $this->assertSame('delivered', $order['order_status']);
        $this->assertSame('paid', $order['payment_status']);
        $this->assertSame('cash', $order['payment_method']);
        $this->assertNull($order['shipping_address_data']);
        $this->assertNull($order['shipping_responsibility']);
    }

    public function test_instant_sale_verification_code_is_the_column_default_not_null(): void
    {
        $order = $this->makeOrder('instant', 'cash');

        $this->assertNotNull($order['verification_code']);
        $this->assertSame('0', $order['verification_code']);
    }

    public function test_delivery_with_cash_becomes_a_pending_cod_order(): void
    {
        $order = $this->makeOrder('delivery', 'cash');

        $this->assertSame('pending', $order['order_status']);
        $this->assertSame('unpaid', $order['payment_status']);
        $this->assertSame('cash_on_delivery', $order['payment_method']);
        $this->assertSame('inhouse_shipping', $order['shipping_responsibility']);
    }

    public function test_delivery_mints_a_six_digit_verification_code(): void
    {
        $order = $this->makeOrder('delivery', 'cash');

        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $order['verification_code']);
    }

    public function test_delivery_paid_by_card_or_wallet_stays_paid_with_its_method(): void
    {
        foreach (['card', 'wallet'] as $paymentType) {
            $order = $this->makeOrder('delivery', $paymentType);

            $this->assertSame('pending', $order['order_status']);
            $this->assertSame('paid', $order['payment_status']);
            $this->assertSame($paymentType, $order['payment_method']);
        }
    }

    public function test_delivery_captures_the_customers_latest_shipping_address(): void
    {
        ShippingAddress::create(['customer_id' => 21, 'address' => 'Old address']);
        $latest = ShippingAddress::create(['customer_id' => 21, 'address' => 'Salmiya Block 10']);
        ShippingAddress::create(['customer_id' => 99, 'address' => 'Someone else']);

        $order = $this->makeOrder('delivery', 'cash');

        $this->assertNotNull($order['shipping_address_data']);
        $this->assertSame($latest->id, $order['shipping_address_data']->id);
        $this->assertSame('Salmiya Block 10', $order['shipping_address_data']->address);
    }

    public function test_delivery_without_a_stored_address_stays_null_without_crashing(): void
    {
        $order = $this->makeOrder('delivery', 'cash');

        $this->assertNull($order['shipping_address_data']);
    }

    public function test_order_detail_rows_follow_the_fulfillment(): void
    {
        $service = app(OrderDetailsService::class);
        $item = ['id' => 20, 'quantity' => 2, 'discount' => 0, 'variant' => '', 'variations' => []];
        $product = ['id' => 20, 'user_id' => 1, 'name' => 'QA'];

        $instant = $service->getPOSOrderDetailsData(
            orderId: 900001, item: $item, product: $product, price: 50.0, tax: 0.0,
        );
        $this->assertSame('delivered', $instant['delivery_status']);
        $this->assertSame('paid', $instant['payment_status']);

        $deliveryCash = $service->getPOSOrderDetailsData(
            orderId: 900001, item: $item, product: $product, price: 50.0, tax: 0.0,
            fulfillment: 'delivery', paymentType: 'cash',
        );
        $this->assertSame('pending', $deliveryCash['delivery_status']);
        $this->assertSame('unpaid', $deliveryCash['payment_status']);

        $deliveryCard = $service->getPOSOrderDetailsData(
            orderId: 900001, item: $item, product: $product, price: 50.0, tax: 0.0,
            fulfillment: 'delivery', paymentType: 'card',
        );
        $this->assertSame('pending', $deliveryCard['delivery_status']);
        $this->assertSame('paid', $deliveryCard['payment_status']);
    }
}
