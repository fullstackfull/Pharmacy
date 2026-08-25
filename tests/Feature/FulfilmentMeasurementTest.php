<?php

namespace Tests\Feature;

use App\Services\Analytics\Reporting\FulfilmentAnalytics;
use App\Services\Analytics\Reporting\Window;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Shipping, returns and refunds as measured quantities.
 *
 * The platform has stamped `picked_at`, `packed_at`, `shipped_at` and `received_at` on every row
 * since the first order, and nothing anywhere ever subtracted two of them — so a marketplace that
 * enforces a dispatch SLA, opens breaches against it and suspends sellers for breaching it could
 * not measure how late anything actually was.
 *
 * These tests hold the four claims that make the report trustworthy rather than merely present:
 *
 *   1. **A median, not a mean.** One order that sat over a public holiday must not move the figure
 *      an operator reads.
 *   2. **Open is not slow.** A fulfilment that has not shipped has no dispatch time — not a zero,
 *      which would read as instant, and not a large one, which would read as late.
 *   3. **The restock rate is of what arrived**, never of what was opened.
 *   4. **Nothing measured reads null**, and null renders as an em dash. A zero here is a claim.
 */
class FulfilmentMeasurementTest extends TestCase
{
    private const SELLER = 1;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['order_fulfillments', 'return_shipments', 'refund_requests', 'orders', 'order_status_histories', 'business_settings', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }

        foreach (['business_settings', 'settings'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('type')->nullable();
                $t->string('key')->nullable();
                $t->text('value')->nullable();
                $t->timestamps();
            });
        }

        Schema::create('order_fulfillments', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 64)->nullable();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->unsignedBigInteger('seller_id')->nullable();
            $t->unsignedBigInteger('warehouse_id')->nullable();
            $t->string('status', 24)->default('picking');
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->string('carrier')->nullable();
            $t->string('tracking_number')->nullable();
            $t->timestamp('picked_at')->nullable();
            $t->timestamp('packed_at')->nullable();
            $t->timestamp('shipped_at')->nullable();
            $t->text('note')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('return_shipments', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 64)->nullable();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->unsignedBigInteger('order_details_id')->nullable();
            $t->unsignedBigInteger('refund_request_id')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('seller_id')->nullable();
            $t->unsignedInteger('qty')->default(1);
            $t->string('reason')->nullable();
            $t->string('status', 24)->default('authorized');
            $t->boolean('restock')->default(true);
            $t->string('carrier')->nullable();
            $t->string('tracking_number')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->text('note')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('created_by_type', 16)->nullable();
            $t->timestamps();
        });

        Schema::create('refund_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_details_id')->nullable();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('status', 24)->default('pending');
            $t->decimal('amount', 24, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->decimal('shipping_cost', 24, 2)->default(0);
            $t->boolean('is_shipping_free')->default(false);
            $t->string('delivery_type', 40)->nullable();
            $t->timestamps();
        });

        Schema::create('order_status_histories', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->string('status', 40);
            $t->timestamps();
        });
    }

    private function analytics(): FulfilmentAnalytics
    {
        return app(FulfilmentAnalytics::class);
    }

    private function window(): Window
    {
        return Window::make('30d');
    }

    private function fulfilment(array $attributes = []): void
    {
        DB::table('order_fulfillments')->insert(array_merge([
            'reference' => 'F-' . uniqid(),
            'order_id' => 1,
            'seller_id' => self::SELLER,
            'status' => 'shipped',
            'created_at' => Carbon::now()->subDays(2),
            'updated_at' => Carbon::now(),
        ], $attributes));
    }

    public function test_dispatch_time_is_the_gap_between_opening_and_shipping(): void
    {
        $opened = Carbon::now()->subDays(2);
        $this->fulfilment(['created_at' => $opened, 'shipped_at' => $opened->copy()->addHours(5)]);

        $this->assertSame(5.0, $this->analytics()->dispatch($this->window())['median_hours']);
    }

    public function test_one_order_that_sat_over_a_holiday_does_not_move_the_figure_an_operator_reads(): void
    {
        $opened = Carbon::now()->subDays(3);

        foreach ([2, 2, 2, 2, 400] as $hours) {
            $this->fulfilment(['created_at' => $opened, 'shipped_at' => $opened->copy()->addHours($hours)]);
        }

        $dispatch = $this->analytics()->dispatch($this->window());

        // The mean of these is 81.6 hours, which describes nothing that happened. The median is 2.
        $this->assertSame(2.0, $dispatch['median_hours']);
        // And the outlier is not hidden either: the p90 is where an operator finds it.
        $this->assertSame(400.0, $dispatch['p90_hours']);
    }

    public function test_a_fulfilment_that_has_not_shipped_has_no_dispatch_time_rather_than_a_zero(): void
    {
        $this->fulfilment(['status' => 'picking', 'shipped_at' => null, 'created_at' => Carbon::now()->subHour()]);

        $dispatch = $this->analytics()->dispatch($this->window());

        $this->assertSame(0, $dispatch['measured']);
        $this->assertNull($dispatch['median_hours']);
        // Counted as open, which is a different fact from slow and from fast.
        $this->assertSame(1, $dispatch['open']);
    }

    public function test_lateness_is_measured_against_the_marketplaces_own_threshold(): void
    {
        $threshold = $this->analytics()->dispatch($this->window())['threshold_hours'];
        $opened = Carbon::now()->subDays(20);

        $this->fulfilment(['created_at' => $opened, 'shipped_at' => $opened->copy()->addHours($threshold + 1)]);
        $this->fulfilment(['created_at' => $opened, 'shipped_at' => $opened->copy()->addHours(max(1, $threshold - 1))]);

        // The same key the shipping exception detector raises issues from, so the report and the
        // issue cannot disagree about what late means.
        $this->assertSame(1, $this->analytics()->dispatch($this->window())['late']);
    }

    public function test_a_negative_duration_is_not_a_measurement(): void
    {
        $opened = Carbon::now()->subDay();
        $this->fulfilment(['created_at' => $opened, 'shipped_at' => $opened->copy()->subHours(3)]);

        // A clock or a backfill, not a dispatch. Averaging it in would quietly pull every figure
        // down and there would be nothing on the screen to explain why.
        $this->assertSame(0, $this->analytics()->dispatch($this->window())['measured']);
        $this->assertNull($this->analytics()->dispatch($this->window())['median_hours']);
    }

    public function test_delivery_time_is_read_from_the_status_history(): void
    {
        $placed = Carbon::now()->subDays(4);
        DB::table('orders')->insert(['id' => 7, 'created_at' => $placed, 'updated_at' => $placed]);
        DB::table('order_status_histories')->insert([
            'order_id' => 7,
            'status' => 'delivered',
            'created_at' => $placed->copy()->addHours(30),
            'updated_at' => $placed->copy()->addHours(30),
        ]);

        $delivery = $this->analytics()->delivery($this->window());

        $this->assertSame(1, $delivery['measured']);
        $this->assertSame(30.0, $delivery['median_hours']);
    }

    public function test_the_restock_rate_is_of_what_arrived_not_of_what_was_opened(): void
    {
        $this->returnShipment(['status' => 'restocked', 'received_at' => Carbon::now()->subDay()]);
        $this->returnShipment(['status' => 'received', 'received_at' => Carbon::now()->subDay()]);
        // Still in the post. It has not failed to be restocked, and counting it as a failure would
        // make the rate fall every busy week.
        $this->returnShipment(['status' => 'in_transit', 'received_at' => null]);

        $returns = $this->analytics()->returns($this->window());

        $this->assertSame(3, $returns['opened']);
        $this->assertSame(2, $returns['received']);
        $this->assertSame(0.5, $returns['restock_rate']);
    }

    public function test_a_period_with_no_returns_reports_no_rate_rather_than_zero(): void
    {
        $returns = $this->analytics()->returns($this->window());

        // Zero percent restocked is a finding. "Nothing came back" is a different one.
        $this->assertNull($returns['restock_rate']);
        $this->assertNull($returns['median_receive_hours']);
    }

    public function test_returns_are_grouped_by_the_reason_the_customer_gave(): void
    {
        $this->returnShipment(['reason' => 'damaged']);
        $this->returnShipment(['reason' => 'damaged']);
        $this->returnShipment(['reason' => 'wrong_item']);
        $this->returnShipment(['reason' => null]);

        $reasons = collect($this->analytics()->returns($this->window())['by_reason'])->keyBy('reason');

        $this->assertSame(2, $reasons['damaged']['count']);
        $this->assertSame(1, $reasons['wrong_item']['count']);
        // A missing reason is named rather than dropped: a fifth of returns with no reason is
        // itself the finding.
        $this->assertSame(1, $reasons['unspecified']['count']);
    }

    public function test_refund_value_counts_what_was_approved_not_what_was_asked_for(): void
    {
        $this->refund(['status' => 'approved', 'amount' => 100]);
        $this->refund(['status' => 'refunded', 'amount' => 50]);
        $this->refund(['status' => 'rejected', 'amount' => 999]);
        $this->refund(['status' => 'pending', 'amount' => 777]);

        $refunds = $this->analytics()->refunds($this->window());

        $this->assertSame(4, $refunds['requested']);
        $this->assertSame(2, $refunds['approved']);
        $this->assertSame(1, $refunds['rejected']);
        $this->assertSame(150.0, $refunds['value']);
    }

    public function test_shipping_has_no_average_on_a_period_with_no_orders(): void
    {
        $shipping = $this->analytics()->shipping($this->window());

        // Dividing by nothing is not an average of zero.
        $this->assertSame(0, $shipping['orders']);
        $this->assertNull($shipping['average']);
        $this->assertSame([], $shipping['by_type']);
    }

    public function test_shipping_is_grouped_only_by_types_that_carried_an_order(): void
    {
        DB::table('orders')->insert([
            ['shipping_cost' => 10, 'delivery_type' => 'home_delivery', 'is_shipping_free' => false, 'created_at' => Carbon::now()->subDay(), 'updated_at' => Carbon::now()],
            ['shipping_cost' => 20, 'delivery_type' => 'home_delivery', 'is_shipping_free' => false, 'created_at' => Carbon::now()->subDay(), 'updated_at' => Carbon::now()],
            ['shipping_cost' => 0, 'delivery_type' => null, 'is_shipping_free' => true, 'created_at' => Carbon::now()->subDay(), 'updated_at' => Carbon::now()],
        ]);

        $shipping = $this->analytics()->shipping($this->window());

        $this->assertSame(30.0, $shipping['total']);
        $this->assertSame(10.0, $shipping['average']);
        $this->assertSame(1, $shipping['free']);
        // A configured zone with nothing in it is a setting, not a measurement — only rows that
        // carried an order appear, and an order with no type is named rather than dropped.
        $this->assertCount(2, $shipping['by_type']);
    }

    public function test_a_missing_table_reports_nothing_measured_rather_than_failing(): void
    {
        Schema::dropIfExists('order_fulfillments');
        Schema::dropIfExists('return_shipments');
        Schema::dropIfExists('refund_requests');

        $this->assertSame(0, $this->analytics()->dispatch($this->window())['measured']);
        $this->assertSame(0, $this->analytics()->returns($this->window())['opened']);
        $this->assertSame(0, $this->analytics()->refunds($this->window())['requested']);
    }

    private function returnShipment(array $attributes = []): void
    {
        DB::table('return_shipments')->insert(array_merge([
            'reference' => 'R-' . uniqid(),
            'seller_id' => self::SELLER,
            'qty' => 1,
            'status' => 'authorized',
            'restock' => true,
            'created_at' => Carbon::now()->subDays(2),
            'updated_at' => Carbon::now(),
        ], $attributes));
    }

    private function refund(array $attributes = []): void
    {
        DB::table('refund_requests')->insert(array_merge([
            'order_id' => 1,
            'status' => 'pending',
            'amount' => 0,
            'created_at' => Carbon::now()->subDays(2),
            'updated_at' => Carbon::now()->subDay(),
        ], $attributes));
    }
}
