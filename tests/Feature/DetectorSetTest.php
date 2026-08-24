<?php

namespace Tests\Feature;

use App\Models\ProductPriceChange;
use App\Models\ReturnShipment;
use App\Models\SellerInsight;
use App\Services\SellerIntelligence\InsightDraft;
use App\Services\SellerIntelligence\Producers\CatalogIntegrityProducer;
use App\Services\SellerIntelligence\Producers\FinanceIntegrityProducer;
use App\Services\SellerIntelligence\Producers\OrderStateProducer;
use App\Services\SellerIntelligence\Producers\OrderStuckProducer;
use App\Services\SellerIntelligence\Producers\PricingRiskProducer;
use App\Services\SellerIntelligence\Producers\ReturnsRiskProducer;
use App\Services\SellerIntelligence\Producers\ShippingExceptionProducer;
use App\Services\SellerIntelligence\Producers\StaleInventoryProducer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The detectors, each pinned by the thing that makes it worth having.
 *
 * Two properties run through all of them and both are about restraint. A detector must not fire on
 * a healthy shop — a list that is never empty is a list nobody reads — and it must not report on
 * another seller's business, which is the same requirement every query in this codebase carries.
 *
 * The third, particular to Phase 3, is aggregation: a finding that is true of two hundred products
 * is one issue with a count, not two hundred issues. That is the management-by-exception rule the
 * brief asks for, and it has to hold at the point the issue is made rather than being papered over
 * by a screen later.
 */
class DetectorSetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'orders', 'order_details', 'order_status_histories', 'products', 'product_price_changes',
            'refund_requests', 'return_shipments', 'delivery_syria_parcels', 'vendor_ledger_entries',
            'category_governance', 'business_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('seller_is', 20)->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('order_status', 30)->nullable();
            $table->string('payment_status', 30)->nullable();
            $table->string('payment_method', 60)->nullable();
            $table->decimal('order_amount', 24, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('delivery_status', 30)->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 24, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('status', 30)->nullable();
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('added_by', 20)->default('seller');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('product_type', 20)->default('physical');
            $table->string('name')->nullable();
            $table->string('barcode')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->text('attributes')->nullable();
            $table->text('choice_options')->nullable();
            $table->integer('status')->default(1);
            $table->decimal('unit_price', 24, 3)->default(0);
            $table->decimal('purchase_price', 24, 3)->default(0);
            $table->integer('current_stock')->default(0);
            $table->timestamps();
        });
        Schema::create('product_price_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->decimal('previous_price', 24, 3)->nullable();
            $table->decimal('new_price', 24, 3);
            $table->decimal('previous_discount', 24, 3)->nullable();
            $table->decimal('new_discount', 24, 3)->nullable();
            $table->string('previous_discount_type', 20)->nullable();
            $table->string('new_discount_type', 20)->nullable();
            $table->string('source', 30)->default('seller_ui');
            $table->string('reason', 191)->nullable();
            $table->string('actor_type', 30)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 191)->nullable();
            $table->timestamps();
        });
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->string('status', 30)->default('pending');
            $table->decimal('amount', 24, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('return_shipments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->integer('qty')->default(1);
            $table->string('status', 30)->default('authorized');
            $table->boolean('restock')->default(true);
            $table->timestamps();
        });
        Schema::create('delivery_syria_parcels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('tracking_number')->nullable();
            $table->string('courier_status')->nullable();
            $table->timestamp('status_updated_at')->nullable();
            $table->timestamps();
        });
        Schema::create('vendor_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('seller_is', 20)->default('seller');
            $table->string('entry_type', 40);
            $table->decimal('debit', 24, 4)->default(0);
            $table->decimal('credit', 24, 4)->default(0);
            $table->decimal('balance_after', 24, 4)->default(0);
            $table->string('status', 20)->default('pending');
            $table->string('reference_type', 60)->nullable();
            $table->string('reference_id', 60)->nullable();
            $table->timestamps();
        });
        Schema::create('category_governance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->text('required_attributes')->nullable();
            $table->timestamps();
        });
    }

    /** @return array<int, InsightDraft> */
    private function drafts(string $producer, int $sellerId = 1): array
    {
        return iterator_to_array(app($producer)->produce($sellerId), false);
    }

    private function order(array $attributes = []): int
    {
        return DB::table('orders')->insertGetId(array_merge([
            'seller_is' => 'seller', 'seller_id' => 1, 'order_status' => 'processing',
            'payment_status' => 'paid', 'payment_method' => 'card', 'order_amount' => 100,
            'created_at' => now()->subDays(5), 'updated_at' => now(),
        ], $attributes));
    }

    private function product(array $attributes = []): int
    {
        return DB::table('products')->insertGetId(array_merge([
            'added_by' => 'seller', 'user_id' => 1, 'product_type' => 'physical', 'name' => 'Widget',
            'status' => 1, 'unit_price' => 100, 'purchase_price' => 60, 'current_stock' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    // ------------------------------------------------------------- orders

    public function test_an_order_that_has_not_moved_in_days_is_raised(): void
    {
        $orderId = $this->order(['created_at' => now()->subDays(5)]);
        DB::table('order_status_histories')->insert([
            'order_id' => $orderId, 'status' => 'processing',
            'created_at' => now()->subDays(4), 'updated_at' => now()->subDays(4),
        ]);

        $drafts = $this->drafts(OrderStuckProducer::class);

        $this->assertCount(1, $drafts);
        $this->assertSame('insight_order_stuck', $drafts[0]->title);
        $this->assertSame(SellerInsight::CATEGORY_ORDERS, $drafts[0]->category);
    }

    public function test_an_order_that_moved_this_morning_is_not_stuck(): void
    {
        $orderId = $this->order(['created_at' => now()->subDays(10)]);
        DB::table('order_status_histories')->insert([
            'order_id' => $orderId, 'status' => 'processing',
            'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2),
        ]);

        // Old is not the same as stuck. An order placed last week and worked on this morning is
        // being handled.
        $this->assertSame([], $this->drafts(OrderStuckProducer::class));
    }

    public function test_an_order_with_no_history_is_measured_from_when_it_was_placed(): void
    {
        $this->order(['created_at' => now()->subDays(5)]);

        // No history row means nothing has moved it, which is the answer the question is asking —
        // not a reason to skip it.
        $this->assertCount(1, $this->drafts(OrderStuckProducer::class));
    }

    public function test_orders_that_contradict_themselves_are_grouped_by_kind(): void
    {
        foreach (range(1, 4) as $ignored) {
            $this->order(['order_status' => 'delivered', 'payment_status' => 'unpaid', 'payment_method' => 'card']);
        }
        $this->order(['order_status' => 'canceled', 'payment_status' => 'paid']);

        $drafts = $this->drafts(OrderStateProducer::class);

        // Five broken orders, two kinds of brokenness, two issues. Not five.
        $this->assertCount(2, $drafts);
        $byKind = collect($drafts)->keyBy(fn (InsightDraft $draft) => $draft->entityId);
        $this->assertSame(4, $byKind['delivered_unpaid']->affectedCount);
        $this->assertSame(1, $byKind['canceled_paid']->affectedCount);
    }

    public function test_cash_on_delivery_being_unpaid_before_delivery_is_not_a_contradiction(): void
    {
        $this->order(['order_status' => 'processing', 'payment_status' => 'unpaid', 'payment_method' => 'cash_on_delivery']);

        // That is what cash on delivery is. Flagging it would flag the payment method working.
        $this->assertSame([], $this->drafts(OrderStateProducer::class));
    }

    // ---------------------------------------------------------- inventory

    public function test_stock_that_has_not_sold_in_months_is_raised_once_with_a_count(): void
    {
        foreach (range(1, 30) as $ignored) {
            $this->product(['current_stock' => 8, 'purchase_price' => 25]);
        }

        $drafts = collect($this->drafts(StaleInventoryProducer::class))
            ->keyBy(fn (InsightDraft $draft) => $draft->entityId);

        // Thirty dead products are one problem with a number on it. Thirty rows would be the screen
        // the brief is written against.
        $this->assertCount(1, $drafts);
        $this->assertSame(30, $drafts['not_moving']->affectedCount);
        // And what it is tying up, from the recorded cost rather than the selling price.
        $this->assertEqualsWithDelta(30 * 8 * 25, $drafts['not_moving']->impact, 0.01);
    }

    public function test_a_live_listing_with_no_stock_and_a_hidden_one_with_stock_are_different_findings(): void
    {
        $this->product(['status' => 1, 'current_stock' => 0]);
        $this->product(['status' => 0, 'current_stock' => 40]);

        $kinds = collect($this->drafts(StaleInventoryProducer::class))
            ->pluck('entityId')->sort()->values()->all();

        $this->assertSame(['hidden_with_stock', 'live_without_stock'], $kinds);
    }

    public function test_a_product_that_sold_recently_is_not_called_stale(): void
    {
        $productId = $this->product(['current_stock' => 20]);
        DB::table('order_details')->insert([
            'order_id' => 1, 'product_id' => $productId, 'seller_id' => 1,
            'delivery_status' => 'delivered', 'qty' => 1, 'price' => 100,
            'created_at' => now()->subDays(3), 'updated_at' => now(),
        ]);

        $this->assertSame([], $this->drafts(StaleInventoryProducer::class));
    }

    public function test_a_cancelled_sale_does_not_keep_dead_stock_off_the_list(): void
    {
        $productId = $this->product(['current_stock' => 20]);
        DB::table('order_details')->insert([
            'order_id' => 1, 'product_id' => $productId, 'seller_id' => 1,
            'delivery_status' => 'canceled', 'qty' => 1, 'price' => 100,
            'created_at' => now()->subDays(3), 'updated_at' => now(),
        ]);

        // A product whose orders were all cancelled has not sold. Counting them would keep dead
        // stock off this list forever.
        $this->assertCount(1, $this->drafts(StaleInventoryProducer::class));
    }

    // ------------------------------------------------------------ catalog

    public function test_two_of_a_sellers_own_products_sharing_a_barcode_is_raised(): void
    {
        $this->product(['barcode' => '5901234123457', 'current_stock' => 0]);
        $this->product(['barcode' => '5901234123457', 'current_stock' => 0]);

        $drafts = collect($this->drafts(CatalogIntegrityProducer::class))
            ->firstWhere('entityId', 'duplicate_barcode');

        $this->assertNotNull($drafts);
        $this->assertSame(2, $drafts->affectedCount);
    }

    public function test_two_different_shops_sharing_a_barcode_is_the_barcode_system_working(): void
    {
        $this->product(['barcode' => '5901234123457', 'current_stock' => 0]);
        $this->product(['barcode' => '5901234123457', 'user_id' => 2, 'current_stock' => 0]);

        $this->assertNull(
            collect($this->drafts(CatalogIntegrityProducer::class))->firstWhere('entityId', 'duplicate_barcode'),
            'A manufactured item legitimately sold by two shops was reported as a problem.',
        );
    }

    public function test_a_listing_missing_what_its_category_requires_is_raised_before_it_is_rejected(): void
    {
        DB::table('category_governance')->insert([
            'category_id' => 9, 'required_attributes' => json_encode(['expiry_date', 'origin']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->product(['category_id' => 9, 'attributes' => json_encode(['origin' => 'SY']), 'current_stock' => 0]);

        $draft = collect($this->drafts(CatalogIntegrityProducer::class))->firstWhere('entityId', 'missing_attributes');

        // The same information the moderator would refuse it for, moved to where it is still cheap
        // to act on.
        $this->assertNotNull($draft);
        $this->assertSame(1, $draft->affectedCount);
    }

    // ------------------------------------------------------------ pricing

    public function test_a_price_that_halved_overnight_is_raised_for_confirmation(): void
    {
        $productId = $this->product();
        ProductPriceChange::create([
            'product_id' => $productId, 'seller_id' => 1,
            'previous_price' => 100, 'new_price' => 40, 'source' => 'seller_ui',
        ]);

        $draft = collect($this->drafts(PricingRiskProducer::class))->firstWhere('title', 'insight_price_dropped_sharply');

        $this->assertNotNull($draft);
        $this->assertEqualsWithDelta(60.0, $draft->metric, 0.1);
        // Who moved it, which is most of the answer to whether it was deliberate.
        $this->assertSame('seller_ui', $draft->actionParams['source']);
    }

    public function test_an_ordinary_price_adjustment_is_not_raised(): void
    {
        $productId = $this->product();
        ProductPriceChange::create([
            'product_id' => $productId, 'seller_id' => 1, 'previous_price' => 100, 'new_price' => 110,
        ]);

        $this->assertNull(collect($this->drafts(PricingRiskProducer::class))->firstWhere('entityType', 'product'));
    }

    public function test_selling_below_cost_is_raised_with_the_exposure(): void
    {
        $this->product(['unit_price' => 40, 'purchase_price' => 60, 'current_stock' => 10]);

        $draft = collect($this->drafts(PricingRiskProducer::class))->firstWhere('entityId', 'below_cost');

        $this->assertNotNull($draft);
        // Twenty a unit, ten units on hand.
        $this->assertEqualsWithDelta(200.0, $draft->impact, 0.01);
    }

    public function test_a_product_with_no_recorded_cost_says_nothing_rather_than_guessing(): void
    {
        $this->product(['unit_price' => 5, 'purchase_price' => 0, 'current_stock' => 10]);

        // Without a recorded cost there is no margin to be wrong about, and inventing one would
        // invent the loss too.
        $this->assertNull(collect($this->drafts(PricingRiskProducer::class))->firstWhere('entityId', 'below_cost'));
    }

    // ------------------------------------------------------------ returns

    public function test_a_refund_request_nobody_answered_is_raised(): void
    {
        $orderId = $this->order();
        DB::table('refund_requests')->insert([
            'order_id' => $orderId, 'order_details_id' => 1, 'status' => 'pending', 'amount' => 80,
            'created_at' => now()->subDays(4), 'updated_at' => now()->subDays(4),
        ]);

        $draft = collect($this->drafts(ReturnsRiskProducer::class))->firstWhere('title', 'insight_refund_response_overdue');

        $this->assertNotNull($draft);
        $this->assertEqualsWithDelta(80.0, $draft->impact, 0.01);
        // Doing nothing is worse than either decision, so this never falls to advisory.
        $this->assertSame(SellerInsight::SEVERITY_HIGH, $draft->signals->severityFloor);
    }

    public function test_a_refund_request_from_this_morning_is_not_overdue(): void
    {
        $orderId = $this->order();
        DB::table('refund_requests')->insert([
            'order_id' => $orderId, 'status' => 'pending', 'amount' => 80,
            'created_at' => now()->subHours(2), 'updated_at' => now(),
        ]);

        $this->assertSame([], $this->drafts(ReturnsRiskProducer::class));
    }

    public function test_another_sellers_refund_request_is_not_this_sellers_problem(): void
    {
        $orderId = $this->order(['seller_id' => 2]);
        DB::table('refund_requests')->insert([
            'order_id' => $orderId, 'status' => 'pending', 'amount' => 80,
            'created_at' => now()->subDays(4), 'updated_at' => now()->subDays(4),
        ]);

        $this->assertSame([], $this->drafts(ReturnsRiskProducer::class));
    }

    public function test_returns_that_arrived_and_stopped_are_grouped(): void
    {
        foreach (range(1, 3) as $index) {
            // Aged after creation: `created_at` is not fillable, so passing it to create() is
            // silently ignored and the row lands with today's date.
            ReturnShipment::create([
                'reference' => "RMA-$index", 'seller_id' => 1, 'qty' => 1,
                'status' => ReturnShipment::STATUS_IN_TRANSIT,
            ])->forceFill(['created_at' => now()->subDays(5)])->save();
        }

        $draft = collect($this->drafts(ReturnsRiskProducer::class))->firstWhere('entityId', 'awaiting_processing');

        $this->assertNotNull($draft);
        $this->assertSame(3, $draft->affectedCount);
    }

    // ----------------------------------------------------------- shipping

    public function test_a_courier_that_has_gone_quiet_is_raised_once_with_a_count(): void
    {
        foreach (range(1, 6) as $index) {
            $orderId = $this->order(['order_status' => 'out_for_delivery']);
            DB::table('delivery_syria_parcels')->insert([
                'order_id' => $orderId, 'tracking_number' => "T$index", 'courier_status' => 'in_transit',
                'status_updated_at' => now()->subDays(5),
                'created_at' => now()->subDays(6), 'updated_at' => now(),
            ]);
        }

        $drafts = $this->drafts(ShippingExceptionProducer::class);

        // A courier having a bad week is one problem however many parcels it touches.
        $this->assertCount(1, $drafts);
        $this->assertSame(6, $drafts[0]->affectedCount);
        $this->assertSame('in_transit', $drafts[0]->metadata['last_known_status']);
    }

    public function test_a_delivered_parcel_is_not_a_stalled_one(): void
    {
        $orderId = $this->order(['order_status' => 'delivered']);
        DB::table('delivery_syria_parcels')->insert([
            'order_id' => $orderId, 'courier_status' => 'delivered',
            'status_updated_at' => now()->subDays(9),
            'created_at' => now()->subDays(10), 'updated_at' => now(),
        ]);

        $this->assertSame([], $this->drafts(ShippingExceptionProducer::class));
    }

    // ------------------------------------------------------------ finance

    public function test_a_delivered_line_with_no_earning_recorded_is_raised(): void
    {
        DB::table('order_details')->insert([
            'order_id' => 1, 'product_id' => 1, 'seller_id' => 1, 'delivery_status' => 'delivered',
            'qty' => 2, 'price' => 150, 'created_at' => now()->subDays(2), 'updated_at' => now(),
        ]);

        $drafts = $this->drafts(FinanceIntegrityProducer::class);

        $this->assertCount(1, $drafts);
        // Work done and not credited — 2 x 150.
        $this->assertEqualsWithDelta(300.0, $drafts[0]->impact, 0.01);
        $this->assertSame(SellerInsight::CATEGORY_FINANCE, $drafts[0]->category);
    }

    public function test_a_line_that_was_credited_is_not_raised(): void
    {
        $lineId = DB::table('order_details')->insertGetId([
            'order_id' => 1, 'product_id' => 1, 'seller_id' => 1, 'delivery_status' => 'delivered',
            'qty' => 2, 'price' => 150, 'created_at' => now()->subDays(2), 'updated_at' => now(),
        ]);
        DB::table('vendor_ledger_entries')->insert([
            'seller_id' => 1, 'entry_type' => 'order_earning', 'credit' => 300, 'debit' => 0,
            'balance_after' => 300, 'reference_type' => 'order_details', 'reference_id' => $lineId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([], $this->drafts(FinanceIntegrityProducer::class));
    }

    public function test_a_line_delivered_minutes_ago_is_given_time_to_catch_up(): void
    {
        DB::table('order_details')->insert([
            'order_id' => 1, 'product_id' => 1, 'seller_id' => 1, 'delivery_status' => 'delivered',
            'qty' => 1, 'price' => 150, 'created_at' => now()->subMinutes(5), 'updated_at' => now(),
        ]);

        // Raising it immediately would produce a finding that resolves itself while the seller is
        // reading it, which teaches them the whole list is noise.
        $this->assertSame([], $this->drafts(FinanceIntegrityProducer::class));
    }

    public function test_no_detector_fires_on_a_healthy_shop(): void
    {
        // A shop doing everything right: a product with stock that sold last week, an order that
        // moved this morning, and an earning recorded against it.
        $productId = $this->product(['current_stock' => 20, 'barcode' => 'UNIQUE-1']);
        $orderId = $this->order(['order_status' => 'delivered', 'payment_status' => 'paid']);
        DB::table('order_status_histories')->insert([
            'order_id' => $orderId, 'status' => 'delivered',
            'created_at' => now()->subHours(1), 'updated_at' => now(),
        ]);
        $lineId = DB::table('order_details')->insertGetId([
            'order_id' => $orderId, 'product_id' => $productId, 'seller_id' => 1,
            'delivery_status' => 'delivered', 'qty' => 1, 'price' => 100,
            'created_at' => now()->subDays(4), 'updated_at' => now(),
        ]);
        DB::table('vendor_ledger_entries')->insert([
            'seller_id' => 1, 'entry_type' => 'order_earning', 'credit' => 100, 'debit' => 0,
            'balance_after' => 100, 'reference_type' => 'order_details', 'reference_id' => $lineId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A list that is never empty is a list nobody reads.
        foreach ([
            OrderStuckProducer::class, OrderStateProducer::class, StaleInventoryProducer::class,
            CatalogIntegrityProducer::class, PricingRiskProducer::class, ReturnsRiskProducer::class,
            ShippingExceptionProducer::class, FinanceIntegrityProducer::class,
        ] as $producer) {
            $this->assertSame([], $this->drafts($producer), $producer . ' fired on a healthy shop.');
        }
    }
}
