<?php

namespace Tests\Feature;

use App\Models\OrderFulfillment;
use App\Models\ReturnShipment;
use App\Services\SellerCenter\Lists\FulfilmentList;
use App\Services\SellerCenter\Lists\ReturnList;
use App\Services\Platform\Policy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Wave 4's definition of done: the fulfilment half of the Seller Center.
 *
 * Nine destinations the navigation registry has named since Wave 1 resolved to no route, so the rail
 * dropped them silently — a seller saw a menu that omitted every capability their own phone app
 * already had, and the omission was invisible from inside the product because a missing route
 * removes an item rather than erroring.
 *
 * These tests hold the two figures that were not merely missing but unobtainable. "Received and not
 * yet restocked" is stock the shop has paid for and cannot sell, and it had no home on any screen.
 * Lateness is the sharper one: FulfillmentService has stamped picked, packed and shipped timestamps
 * on every fulfilment since it was built and nothing ever subtracted them, so a marketplace that
 * suspends sellers for breaching an SLA could not show a seller which of their orders was late.
 */
class SellerCenterWave4Test extends TestCase
{
    private const SELLER = 1;
    private const RIVAL = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['return_shipments', 'order_fulfillments', 'business_settings', 'settings', 'products', 'orders'] as $table) {
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

        Schema::create('return_shipments', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->nullable();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->unsignedBigInteger('order_details_id')->nullable();
            $t->unsignedBigInteger('refund_request_id')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('seller_id')->nullable();
            $t->integer('qty')->default(1);
            $t->string('reason')->nullable();
            $t->string('status', 20)->default('authorized');
            $t->boolean('restock')->default(true);
            $t->string('carrier')->nullable();
            $t->string('tracking_number')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
        });

        Schema::create('order_fulfillments', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->nullable();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->unsignedBigInteger('seller_id')->nullable();
            $t->unsignedBigInteger('warehouse_id')->nullable();
            $t->string('status', 20)->default('pending');
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->string('carrier')->nullable();
            $t->string('tracking_number')->nullable();
            $t->timestamp('picked_at')->nullable();
            $t->timestamp('packed_at')->nullable();
            $t->timestamp('shipped_at')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
        });
    }

    private function rma(array $attributes = []): ReturnShipment
    {
        return ReturnShipment::create(array_merge([
            'reference' => 'RMA-' . uniqid(),
            'order_id' => 10,
            'order_details_id' => 100,
            'product_id' => 5,
            'seller_id' => self::SELLER,
            'qty' => 2,
            'status' => 'authorized',
        ], $attributes));
    }

    private function fulfilment(array $attributes = []): OrderFulfillment
    {
        return OrderFulfillment::create(array_merge([
            'reference' => 'FUL-' . uniqid(),
            'order_id' => 10,
            'seller_id' => self::SELLER,
            'status' => OrderFulfillment::STATUS_PENDING,
        ], $attributes));
    }

    // ────────────────────────────────────────────────────────────────── returns

    /**
     * The figure that costs money while it waits: units the shop has paid for, on a shelf, not
     * sellable until somebody decides whether they can be sold again.
     */
    public function test_returns_that_arrived_and_await_a_decision_are_counted_apart_from_the_rest(): void
    {
        $this->rma(['status' => 'authorized']);
        $this->rma(['status' => 'in_transit']);
        $this->rma(['status' => 'received']);
        $this->rma(['status' => 'received']);
        $this->rma(['status' => 'restocked', 'qty' => 3]);

        $summary = app(ReturnList::class)->summary(self::SELLER);

        $this->assertSame(4, $summary['open'], 'authorized, in transit and arrived are all still open');
        $this->assertSame(2, $summary['awaiting_decision']);
        $this->assertSame(3, $summary['units_back']);
    }

    public function test_a_rival_shops_returns_are_never_counted_or_listed(): void
    {
        $this->rma(['status' => 'received']);
        $this->rma(['status' => 'received', 'seller_id' => self::RIVAL]);

        $list = app(ReturnList::class);

        $this->assertSame(1, $list->summary(self::SELLER)['awaiting_decision']);
        $this->assertSame(1, $list->paginate(self::SELLER, Request::create('/'))->total());
    }

    public function test_a_view_narrows_the_list_and_an_unknown_one_falls_back_to_all(): void
    {
        $this->rma(['status' => 'received']);
        $this->rma(['status' => 'restocked']);

        $list = app(ReturnList::class);

        $this->assertSame(1, $list->paginate(self::SELLER, Request::create('/?view=received'))->total());
        $this->assertSame('all', $list->view(Request::create('/?view=whatever')));
        $this->assertSame(2, $list->paginate(self::SELLER, Request::create('/?view=whatever'))->total());
    }

    /** A missing table is a missing feature, never an empty shop. */
    public function test_returns_report_zero_rather_than_failing_when_the_table_is_absent(): void
    {
        Schema::dropIfExists('return_shipments');

        $list = app(ReturnList::class);

        $this->assertFalse($list->available());
        $this->assertSame(0, $list->summary(self::SELLER)['open']);
        $this->assertSame(0, $list->paginate(self::SELLER, Request::create('/'))->total());
    }

    // ─────────────────────────────────────────────────────────────── fulfilment

    /**
     * Lateness measured from the last thing that happened, not from when the order was placed.
     *
     * A fulfilment packed an hour ago is not late because the order is three days old, and treating
     * it as late is how a seller stops believing the screen.
     */
    public function test_a_fulfilment_that_has_not_moved_for_longer_than_the_marketplace_allows_is_late(): void
    {
        app(Policy::class)->save(['shipping_silent_hours' => 24]);

        $stalled = $this->fulfilment(['status' => OrderFulfillment::STATUS_PICKING]);
        $stalled->forceFill(['updated_at' => Carbon::now()->subHours(30)])->save();

        $moving = $this->fulfilment(['status' => OrderFulfillment::STATUS_PICKING]);
        $moving->forceFill(['updated_at' => Carbon::now()->subHour()])->save();

        $list = app(FulfilmentList::class);

        $this->assertTrue($list->isLate($stalled->fresh()));
        $this->assertFalse($list->isLate($moving->fresh()));
        $this->assertSame(1, $list->summary(self::SELLER)['late']);
    }

    /** A closed fulfilment cannot be stalled: it has arrived where it was going. */
    public function test_a_shipped_fulfilment_is_never_counted_as_late(): void
    {
        app(Policy::class)->save(['shipping_silent_hours' => 24]);

        $shipped = $this->fulfilment(['status' => OrderFulfillment::STATUS_SHIPPED, 'shipped_at' => Carbon::now()->subDays(5)]);
        $shipped->forceFill(['updated_at' => Carbon::now()->subDays(5)])->save();

        $this->assertFalse(app(FulfilmentList::class)->isLate($shipped->fresh()));
        $this->assertSame(0, app(FulfilmentList::class)->summary(self::SELLER)['late']);
    }

    /**
     * Dispatch time is the figure a seller is judged on, and it is the subtraction nothing in this
     * platform had ever performed.
     */
    public function test_dispatch_time_is_the_gap_between_opening_a_fulfilment_and_shipping_it(): void
    {
        $fulfilment = $this->fulfilment(['status' => OrderFulfillment::STATUS_SHIPPED, 'shipped_at' => Carbon::now()]);
        $fulfilment->forceFill(['created_at' => Carbon::now()->subHours(6)])->save();

        $this->assertSame(6.0, app(FulfilmentList::class)->dispatchHours($fulfilment->fresh()));
    }

    /** An open fulfilment has no dispatch time. Zero would read as instant, which is the opposite. */
    public function test_a_fulfilment_that_has_not_shipped_has_no_dispatch_time_rather_than_zero(): void
    {
        $this->assertNull(app(FulfilmentList::class)->dispatchHours($this->fulfilment()));
    }

    public function test_each_stage_lists_only_its_own_part_of_the_workflow(): void
    {
        $this->fulfilment(['status' => OrderFulfillment::STATUS_PENDING]);
        $this->fulfilment(['status' => OrderFulfillment::STATUS_PICKING]);
        $this->fulfilment(['status' => OrderFulfillment::STATUS_PACKED]);
        $this->fulfilment(['status' => OrderFulfillment::STATUS_READY]);
        $this->fulfilment(['status' => OrderFulfillment::STATUS_SHIPPED]);

        $list = app(FulfilmentList::class);
        $request = Request::create('/');

        $this->assertSame(2, $list->paginate(self::SELLER, $request, FulfilmentList::STAGES['picking'])->total());
        $this->assertSame(2, $list->paginate(self::SELLER, $request, FulfilmentList::STAGES['packing'])->total());
        $this->assertSame(5, $list->paginate(self::SELLER, $request)->total());
    }

    public function test_a_rival_shops_fulfilments_are_never_listed(): void
    {
        $this->fulfilment();
        $this->fulfilment(['seller_id' => self::RIVAL]);

        $this->assertSame(1, app(FulfilmentList::class)->paginate(self::SELLER, Request::create('/'))->total());
    }

    /**
     * The screen and the Control Tower must mean the same thing by "stuck": a screen with its own
     * private threshold is how a seller is told two different things about one order.
     */
    public function test_the_screen_reads_the_same_threshold_the_issue_detector_raises_issues_from(): void
    {
        app(Policy::class)->save(['shipping_silent_hours' => 36]);

        $this->assertSame(36, app(FulfilmentList::class)->silenceHours());
        $this->assertSame(app(Policy::class)->int('shipping_silent_hours'), app(FulfilmentList::class)->silenceHours());
    }
}
