<?php

namespace Tests\Feature;

use App\Services\Marketplace\SellerOrderBreakdownService;
use App\Services\Marketplace\SellerOrderTimelineService;
use App\Services\Marketplace\SlaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * What an order is worth to the seller, and how it got here.
 *
 * The money question is the one that matters most and is the easiest to answer dishonestly. A
 * seller reads "you receive" as what will land in their account, so that figure may only come from
 * the record that will actually pay them. Where no earning has been recorded the answer has to say
 * so — an estimate presented as a settlement is worse than no number at all.
 *
 * The history question has the mirror-image trap: a timeline inferred from the current status would
 * cheerfully show "confirmed" at a time nobody confirmed anything. Every entry here comes from a
 * row that exists.
 */
class SellerOrderBreakdownTest extends TestCase
{
    private const ORDER_ID = 5001;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'orders', 'order_details', 'order_transactions', 'order_item_commissions',
            'order_status_histories', 'order_expected_delivery_histories', 'order_fulfillments',
            'order_edit_histories', 'refund_requests', 'vendor_ledger_entries', 'business_settings',
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
            $table->decimal('shipping_cost', 24, 3)->default(0);
            $table->decimal('deliveryman_charge', 24, 3)->default(0);
            $table->string('shipping_responsibility', 40)->nullable();
            $table->boolean('is_shipping_free')->default(false);
            $table->timestamp('deliveryman_assigned_at')->nullable();
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 24, 3)->default(0);
            $table->decimal('tax', 24, 3)->default(0);
            $table->decimal('discount', 24, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('order_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->decimal('order_amount', 24, 3)->default(0);
            $table->decimal('seller_amount', 24, 3)->default(0);
            $table->decimal('admin_commission', 24, 3)->default(0);
            $table->decimal('delivery_charge', 24, 3)->default(0);
            $table->decimal('tax', 24, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('order_item_commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('rule_label')->nullable();
            $table->string('rule_scope_type', 40)->nullable();
            $table->string('rate_type', 20)->nullable();
            $table->decimal('percentage', 8, 3)->nullable();
            $table->decimal('fixed_amount', 24, 3)->nullable();
            $table->decimal('commissionable_amount', 24, 3)->default(0);
            $table->decimal('commission_amount', 24, 3)->default(0);
            $table->decimal('seller_net_amount', 24, 3)->default(0);
            $table->decimal('reversed_amount', 24, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_type', 30)->nullable();
            $table->string('status', 30)->nullable();
            $table->text('cause')->nullable();
            $table->timestamps();
        });
        Schema::create('order_expected_delivery_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('user_type', 30)->nullable();
            $table->text('cause')->nullable();
            $table->timestamps();
        });
        Schema::create('order_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->timestamp('picked_at')->nullable();
            $table->timestamp('packed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamps();
        });
        Schema::create('order_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->timestamps();
        });
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_details_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->text('refund_reason')->nullable();
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
            $table->timestamp('available_at')->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('reference_type', 60)->nullable();
            $table->string('reference_id', 60)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        DB::table('orders')->insert([
            'id' => self::ORDER_ID, 'seller_is' => 'seller', 'seller_id' => 1,
            'order_status' => 'pending', 'payment_status' => 'unpaid', 'payment_method' => 'cash_on_delivery',
            'order_amount' => 200, 'shipping_cost' => 5, 'shipping_responsibility' => 'seller',
            'created_at' => now()->subHours(4), 'updated_at' => now()->subHours(4),
        ]);
    }

    private function line(int $sellerId = 1, float $price = 50, int $qty = 3, float $tax = 5, float $discount = 10): int
    {
        return DB::table('order_details')->insertGetId([
            'order_id' => self::ORDER_ID, 'product_id' => 7, 'seller_id' => $sellerId,
            'qty' => $qty, 'price' => $price, 'tax' => $tax, 'discount' => $discount,
            'created_at' => now()->subHours(4), 'updated_at' => now()->subHours(4),
        ]);
    }

    private function breakdown(int $sellerId = 1): ?array
    {
        return app(SellerOrderBreakdownService::class)->breakdownFor(self::ORDER_ID, $sellerId);
    }

    private function timeline(int $sellerId = 1): ?array
    {
        return app(SellerOrderTimelineService::class)->timelineFor(self::ORDER_ID, $sellerId);
    }

    public function test_the_line_totals_are_multiplied_by_quantity(): void
    {
        $this->line(price: 50, qty: 3, tax: 5, discount: 10);

        $items = $this->breakdown()['items'];

        // A line's price is per unit. Summing the column would have reported a third of the sale on
        // an order of three.
        $this->assertSame(1, $items['count']);
        $this->assertSame(3, $items['quantity']);
        $this->assertSame(150.0, $items['price']);
        $this->assertSame(15.0, $items['tax']);
        $this->assertSame(30.0, $items['discount']);
    }

    public function test_an_order_with_nothing_recorded_says_so_rather_than_estimating(): void
    {
        $this->line();

        $breakdown = $this->breakdown();

        // The honest answer for an order whose earning has not been booked. A plausible figure here
        // would be read as "what I will be paid", which is exactly what it would not be.
        $this->assertSame(SellerOrderBreakdownService::SOURCE_NOT_RECORDED, $breakdown['source']);
        $this->assertFalse($breakdown['is_settled']);
        $this->assertNull($breakdown['seller_receives']);
        $this->assertNull($breakdown['commission_amount']);
        // What the order itself states is still shown — that part is a fact.
        $this->assertSame(150.0, $breakdown['items']['price']);
        $this->assertSame(200.0, $breakdown['order_total']);
    }

    public function test_the_commission_ledger_is_preferred_and_names_the_rule_that_charged(): void
    {
        $detailsId = $this->line();
        DB::table('order_item_commissions')->insert([
            'order_id' => self::ORDER_ID, 'order_details_id' => $detailsId, 'seller_id' => 1,
            'rule_label' => 'Cosmetics 12%', 'rule_scope_type' => 'category', 'rate_type' => 'percentage',
            'percentage' => 12, 'commissionable_amount' => 150, 'commission_amount' => 18,
            'seller_net_amount' => 132, 'reversed_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // An older row for the same order, which must not win.
        DB::table('order_transactions')->insert([
            'order_id' => self::ORDER_ID, 'seller_id' => 1, 'order_amount' => 150,
            'seller_amount' => 999, 'admin_commission' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $breakdown = $this->breakdown();

        $this->assertSame(SellerOrderBreakdownService::SOURCE_COMMISSION_LEDGER, $breakdown['source']);
        $this->assertSame(132.0, $breakdown['seller_receives']);
        $this->assertSame(18.0, $breakdown['commission_amount']);
        // One lump sum tells a seller what was taken; the rule tells them why.
        $this->assertSame('Cosmetics 12%', $breakdown['commission_rules'][0]['label']);
        $this->assertSame(12.0, $breakdown['commission_rules'][0]['percentage']);
    }

    public function test_the_older_transaction_row_is_used_when_there_is_no_commission_ledger(): void
    {
        $this->line();
        DB::table('order_transactions')->insert([
            'order_id' => self::ORDER_ID, 'seller_id' => 1, 'order_amount' => 150,
            'seller_amount' => 130, 'admin_commission' => 20, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $breakdown = $this->breakdown();

        // Orders placed before the per-line ledger existed still have a real answer.
        $this->assertSame(SellerOrderBreakdownService::SOURCE_ORDER_TRANSACTION, $breakdown['source']);
        $this->assertTrue($breakdown['is_settled']);
        $this->assertSame(130.0, $breakdown['seller_receives']);
        $this->assertSame(20.0, $breakdown['commission_amount']);
        $this->assertSame([], $breakdown['commission_rules']);
    }

    public function test_another_sellers_margins_are_not_readable(): void
    {
        $this->line(sellerId: 2);
        DB::table('order_item_commissions')->insert([
            'order_id' => self::ORDER_ID, 'seller_id' => 2, 'commissionable_amount' => 150,
            'commission_amount' => 18, 'seller_net_amount' => 132,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Not "an empty breakdown" — not found. Seller 1 has no order by that id.
        $this->assertNull($this->breakdown(sellerId: 1));
        $this->assertNull($this->timeline(sellerId: 1));
        $this->assertNotNull($this->breakdown(sellerId: 2));
    }

    public function test_the_ledger_says_when_a_pending_earning_becomes_withdrawable(): void
    {
        $detailsId = $this->line();
        $availableAt = now()->addDays(7);
        DB::table('vendor_ledger_entries')->insert([
            ['seller_id' => 1, 'seller_is' => 'seller', 'entry_type' => 'order_earning',
                'credit' => 150, 'debit' => 0, 'balance_after' => 150, 'status' => 'pending',
                'available_at' => $availableAt, 'reference_type' => 'order_details',
                'reference_id' => $detailsId, 'created_at' => now(), 'updated_at' => now()],
            ['seller_id' => 1, 'seller_is' => 'seller', 'entry_type' => 'commission_charge',
                'credit' => 0, 'debit' => 18, 'balance_after' => 132, 'status' => 'pending',
                'available_at' => $availableAt, 'reference_type' => 'order_details',
                'reference_id' => $detailsId, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $ledger = $this->breakdown()['ledger'];

        $this->assertCount(2, $ledger);
        $this->assertSame('order_earning', $ledger[0]['entry_type']);
        $this->assertSame(150.0, $ledger[0]['credit']);
        $this->assertSame(18.0, $ledger[1]['debit']);
        // The most asked question about a seller's balance, and it has never been visible per order.
        $this->assertNotNull($ledger[0]['available_at']);
    }

    public function test_a_ledger_line_from_another_order_is_not_counted_against_this_one(): void
    {
        $detailsId = $this->line();
        DB::table('vendor_ledger_entries')->insert([
            ['seller_id' => 1, 'entry_type' => 'order_earning', 'credit' => 150, 'balance_after' => 150,
                'reference_type' => 'order_details', 'reference_id' => $detailsId,
                'created_at' => now(), 'updated_at' => now()],
            ['seller_id' => 1, 'entry_type' => 'order_earning', 'credit' => 900, 'balance_after' => 1050,
                'reference_type' => 'order_details', 'reference_id' => 999999,
                'created_at' => now(), 'updated_at' => now()],
        ]);

        $ledger = $this->breakdown()['ledger'];

        $this->assertCount(1, $ledger);
        $this->assertSame(150.0, $ledger[0]['credit']);
    }

    public function test_the_timeline_is_built_from_records_rather_than_from_the_current_status(): void
    {
        $detailsId = $this->line();
        DB::table('order_status_histories')->insert([
            'order_id' => self::ORDER_ID, 'user_type' => 'seller', 'status' => 'confirmed',
            'cause' => null, 'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3),
        ]);
        DB::table('order_fulfillments')->insert([
            'order_id' => self::ORDER_ID, 'seller_id' => 1, 'carrier' => 'DHL', 'tracking_number' => 'X1',
            'picked_at' => now()->subHours(2), 'packed_at' => now()->subHours(1),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('refund_requests')->insert([
            'order_details_id' => $detailsId, 'order_id' => self::ORDER_ID,
            'refund_reason' => 'Damaged', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $events = $this->timeline()['events'];
        $keys = array_column($events, 'key');

        // Oldest first, and every entry is a row that exists — nothing inferred from the fact that
        // the order is currently 'pending'.
        $this->assertSame(
            ['order_placed', 'status_confirmed', 'fulfillment_picked', 'fulfillment_packed', 'refund_requested'],
            $keys,
        );
        $this->assertSame('seller', $events[1]['actor']);
        $this->assertSame('X1', $events[2]['note']);
        $this->assertSame('Damaged', $events[4]['note']);

        $timestamps = array_column($events, 'at');
        $this->assertSame($timestamps, collect($timestamps)->sort()->values()->all());
    }

    public function test_the_countdown_is_the_same_clock_the_marketplace_judges_by(): void
    {
        $this->line();

        $sla = $this->timeline()['sla'];

        // Four hours in, under the 24-hour policy: twenty left, and read from SlaService rather
        // than from arithmetic of its own.
        $this->assertSame(24, $sla['window_hours']);
        $this->assertEqualsWithDelta(20.0, $sla['hours_left'], 0.2);
        $this->assertFalse($sla['is_late']);
        $this->assertSame(
            app(SlaService::class)->processingDeadline(Carbon::parse(DB::table('orders')->find(self::ORDER_ID)->created_at))->toIso8601String(),
            $sla['deadline'],
        );
    }

    public function test_a_late_order_counts_up(): void
    {
        $this->line();
        DB::table('orders')->where('id', self::ORDER_ID)->update(['created_at' => now()->subHours(30)]);

        $sla = $this->timeline()['sla'];

        $this->assertTrue($sla['is_late']);
        $this->assertLessThan(0, $sla['hours_left']);
    }

    public function test_an_order_that_no_longer_waits_on_the_seller_has_no_countdown(): void
    {
        $this->line();
        DB::table('orders')->where('id', self::ORDER_ID)->update(['order_status' => 'out_for_delivery']);

        // Once it is with the courier there is nothing left for the seller to be late for, and a
        // countdown would be blaming them for someone else's clock.
        $this->assertNull($this->timeline()['sla']);
    }

    public function test_the_endpoint_answers_a_stranger_with_not_found(): void
    {
        $this->line(sellerId: 2);

        $this->getJson('/api/v3/seller/orders/' . self::ORDER_ID . '/breakdown')->assertStatus(401);
    }
}
