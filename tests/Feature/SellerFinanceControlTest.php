<?php

namespace Tests\Feature;

use App\Models\CommissionRule;
use App\Models\ProductPriceChange;
use App\Models\Seller;
use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\FeeSimulatorService;
use App\Services\Marketplace\SellerOrderBreakdownService;
use App\Services\Marketplace\SellerReconciliationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The seller's own view of the marketplace's arithmetic.
 *
 * The reconciliation's job is to be uncomfortable when it should be. A shop whose totals happen to
 * match while a line's earning is missing and an extra credit covers it has not reconciled, and
 * saying so is the entire value of the feature — a check that reports "fine" whenever the sums come
 * out even is a check nobody needs.
 *
 * The simulator's job is to be the same arithmetic the platform will actually run. It calls the
 * commission engine rather than reimplementing the rates, and the tests here pin the parts a
 * reimplementation would get wrong: which rule wins, and what a percentage of nothing is.
 */
class SellerFinanceControlTest extends TestCase
{
    private const SELLER = 1;
    private const RIVAL = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'orders', 'order_details', 'order_item_commissions', 'order_transactions',
            'vendor_ledger_entries', 'commission_rules', 'products', 'product_price_changes',
            'sellers', 'business_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('status', 20)->default('approved');
            $table->decimal('sales_commission_percentage', 8, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('seller_is', 20)->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 24, 3)->default(0);
            $table->decimal('discount', 24, 3)->default(0);
            $table->string('delivery_status', 30)->nullable();
            $table->timestamps();
        });
        Schema::create('order_item_commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is', 20)->default('seller');
            $table->decimal('commission_amount', 24, 3)->default(0);
            $table->decimal('seller_net_amount', 24, 3)->default(0);
            $table->decimal('reversed_amount', 24, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('vendor_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('seller_is', 20)->default('seller');
            $table->string('entry_type', 40);
            $table->decimal('debit', 24, 3)->default(0);
            $table->decimal('credit', 24, 3)->default(0);
            $table->string('reference_type', 40)->nullable();
            $table->string('reference_id', 60)->nullable();
            $table->timestamps();
        });
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 20);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('rate_type', 30)->default('percentage');
            $table->decimal('percentage', 8, 2)->default(0);
            $table->decimal('fixed_amount', 24, 3)->default(0);
            $table->decimal('min_amount', 24, 3)->default(0);
            $table->decimal('max_amount', 24, 3)->default(0);
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('label', 120)->nullable();
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('added_by', 20)->default('seller');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->decimal('unit_price', 24, 3)->default(0);
            $table->decimal('discount', 24, 3)->default(0);
            $table->string('discount_type', 20)->default('flat');
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

        Seller::insert([
            ['id' => self::SELLER, 'f_name' => 'Owner', 'status' => 'approved', 'sales_commission_percentage' => null],
            ['id' => self::RIVAL, 'f_name' => 'Rival', 'status' => 'approved', 'sales_commission_percentage' => null],
        ]);
    }

    private function reconciliation(): SellerReconciliationService
    {
        return app(SellerReconciliationService::class);
    }

    private function simulator(): FeeSimulatorService
    {
        return app(FeeSimulatorService::class);
    }

    private function deliveredLine(array $attributes = []): int
    {
        $orderId = $attributes['order_id'] ?? 500;
        $sellerId = $attributes['seller_id'] ?? self::SELLER;

        DB::table('orders')->updateOrInsert(
            ['id' => $orderId],
            ['seller_is' => 'seller', 'seller_id' => $sellerId, 'created_at' => now(), 'updated_at' => now()],
        );

        return DB::table('order_details')->insertGetId([
            'order_id' => $orderId,
            'qty' => $attributes['qty'] ?? 2,
            'price' => $attributes['price'] ?? 50,
            'discount' => $attributes['discount'] ?? 0,
            'delivery_status' => 'delivered',
            'created_at' => $attributes['created_at'] ?? now()->subDays(2),
            'updated_at' => now(),
        ]);
    }

    private function earning(int $orderId, int $detailId, float $net, float $reversed = 0): void
    {
        DB::table('order_item_commissions')->insert([
            'order_id' => $orderId,
            'order_details_id' => $detailId,
            'seller_id' => self::SELLER,
            'seller_is' => 'seller',
            'commission_amount' => 10,
            'seller_net_amount' => $net,
            'reversed_amount' => $reversed,
            'created_at' => now()->subDays(2),
            'updated_at' => now(),
        ]);
    }

    /**
     * Credit an order line the way the platform actually does it.
     *
     * Two entries, keyed on `order_details.id`: the earning is the commissionable amount and the
     * commission is a separate debit against the same line. The fixture used to write one entry
     * with `reference_type = 'order'`, a shape production never produces — so the suite passed
     * while the screen reported every credited order as unpaid.
     */
    private function credit(int $detailId, float $net, float $commission = 10): void
    {
        DB::table('vendor_ledger_entries')->insert([
            [
                'seller_id' => self::SELLER,
                'seller_is' => 'seller',
                'entry_type' => VendorLedgerEntry::TYPE_ORDER_EARNING,
                'debit' => 0,
                'credit' => $net + $commission,
                'reference_type' => 'order_details',
                'reference_id' => (string) $detailId,
                'created_at' => now()->subDays(2),
                'updated_at' => now(),
            ],
            [
                'seller_id' => self::SELLER,
                'seller_is' => 'seller',
                'entry_type' => VendorLedgerEntry::TYPE_COMMISSION_CHARGE,
                'debit' => $commission,
                'credit' => 0,
                'reference_type' => 'order_details',
                'reference_id' => (string) $detailId,
                'created_at' => now()->subDays(2),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_a_shop_where_every_hand_off_completed_reconciles(): void
    {
        $detailId = $this->deliveredLine();
        $this->earning(500, $detailId, net: 90);
        $this->credit($detailId, net: 90);

        $result = $this->reconciliation()->forSeller(self::SELLER);

        $this->assertTrue($result['reconciles']);
        $this->assertSame(1, $result['delivered']['lines']);
        $this->assertEquals(90, $result['recorded']['net']);
        $this->assertEquals(90, $result['credited']['amount']);
    }

    public function test_a_delivered_line_with_no_earning_recorded_is_named_and_can_be_opened(): void
    {
        $this->deliveredLine(['qty' => 3, 'price' => 40]);

        $result = $this->reconciliation()->forSeller(self::SELLER);

        $this->assertFalse($result['reconciles']);
        $gap = $result['gaps']['lines_without_earning'];
        $this->assertSame(1, $gap['count']);
        $this->assertEquals(120, $gap['amount']);
        // The sample is what makes the number checkable rather than believable.
        $this->assertSame(500, $gap['sample'][0]['order_id']);
        $this->assertSame(3, $gap['sample'][0]['qty']);
    }

    public function test_an_earning_that_never_reached_the_balance_is_named(): void
    {
        $detailId = $this->deliveredLine();
        $this->earning(500, $detailId, net: 90);

        $result = $this->reconciliation()->forSeller(self::SELLER);

        $gap = $result['gaps']['earnings_without_credit'];
        $this->assertSame(1, $gap['count']);
        $this->assertEquals(90, $gap['amount']);
        $this->assertFalse($result['reconciles']);
    }

    public function test_matching_totals_do_not_hide_a_missing_earning(): void
    {
        // One line earned and credited; a second delivered line with no earning at all, and an
        // extra credit that happens to make the totals agree.
        $first = $this->deliveredLine(['order_id' => 500]);
        $this->earning(500, $first, net: 90);
        $this->credit($first, net: 90);
        $this->deliveredLine(['order_id' => 501]);

        $result = $this->reconciliation()->forSeller(self::SELLER);

        $this->assertEquals($result['recorded']['net'], $result['credited']['amount']);
        // The totals agree and the shop still has not reconciled. A check that stopped at the sums
        // would report this as fine.
        $this->assertFalse($result['reconciles']);
        $this->assertSame(1, $result['gaps']['lines_without_earning']['count']);
    }

    public function test_a_reversed_line_is_not_money_the_seller_is_still_owed(): void
    {
        $detailId = $this->deliveredLine();
        $this->earning(500, $detailId, net: 90, reversed: 90);
        $this->credit($detailId, net: 0);

        $result = $this->reconciliation()->forSeller(self::SELLER);

        // Earned and then un-earned. Reporting the gross would have the seller hunting for money
        // they gave back to a customer.
        $this->assertEquals(0, $result['recorded']['net']);
        $this->assertTrue($result['reconciles']);
    }

    public function test_reconciliation_never_reads_another_shops_records(): void
    {
        $this->deliveredLine(['order_id' => 600, 'seller_id' => self::RIVAL]);

        $result = $this->reconciliation()->forSeller(self::SELLER);

        $this->assertSame(0, $result['delivered']['lines']);
        $this->assertSame(0, $result['gaps']['lines_without_earning']['count']);
    }

    public function test_the_window_excludes_what_falls_outside_it(): void
    {
        $this->deliveredLine(['created_at' => now()->subDays(200)]);

        $recent = $this->reconciliation()->forSeller(self::SELLER);
        $wide = $this->reconciliation()->forSeller(self::SELLER, from: now()->subDays(365)->toDateString());

        $this->assertSame(0, $recent['delivered']['lines']);
        $this->assertSame(1, $wide['delivered']['lines']);
    }

    public function test_a_malformed_date_widens_the_window_rather_than_matching_nothing(): void
    {
        $this->deliveredLine();

        $result = $this->reconciliation()->forSeller(self::SELLER, from: 'not a date at all');

        // An unreadable filter that silently matched nothing would read as "everything balances".
        $this->assertSame(1, $result['delivered']['lines']);
    }

    public function test_a_shop_with_nothing_recorded_says_so_rather_than_reporting_zero_earnings(): void
    {
        $result = $this->reconciliation()->forSeller(self::SELLER);

        $this->assertSame(SellerOrderBreakdownService::SOURCE_NOT_RECORDED, $result['recorded']['source']);
    }

    public function test_the_simulator_applies_the_rule_the_marketplace_would_apply(): void
    {
        CommissionRule::create([
            'scope_type' => 'global', 'scope_id' => null, 'rate_type' => 'percentage',
            'percentage' => 12, 'is_active' => true, 'label' => 'House rate',
        ]);

        $result = $this->simulator()->simulate(self::SELLER, ['unit_price' => 100, 'quantity' => 2]);

        $this->assertEquals(200, $result['commissionable_amount']);
        $this->assertEquals(24, $result['commission_amount']);
        $this->assertEquals(176, $result['seller_receives']);
        $this->assertEquals(12, $result['effective_rate_percent']);
        $this->assertSame('House rate', $result['rule']['label']);
    }

    public function test_a_rule_written_for_this_shop_beats_the_house_rate(): void
    {
        CommissionRule::create([
            'scope_type' => 'global', 'scope_id' => null, 'rate_type' => 'percentage',
            'percentage' => 12, 'is_active' => true, 'label' => 'House rate',
        ]);
        CommissionRule::create([
            'scope_type' => 'vendor', 'scope_id' => self::SELLER, 'rate_type' => 'percentage',
            'percentage' => 5, 'is_active' => true, 'label' => 'Negotiated',
        ]);

        $result = $this->simulator()->simulate(self::SELLER, ['unit_price' => 100]);

        $this->assertSame('Negotiated', $result['rule']['label']);
        $this->assertEquals(5, $result['commission_amount']);
    }

    public function test_the_seller_own_discount_comes_off_before_commission(): void
    {
        CommissionRule::create([
            'scope_type' => 'global', 'scope_id' => null, 'rate_type' => 'percentage',
            'percentage' => 10, 'is_active' => true, 'label' => 'House rate',
        ]);

        $result = $this->simulator()->simulate(self::SELLER, [
            'unit_price' => 100, 'quantity' => 1, 'discount' => 20, 'discount_type' => 'flat',
        ]);

        $this->assertEquals(100, $result['gross']);
        $this->assertEquals(80, $result['commissionable_amount']);
        $this->assertEquals(8, $result['commission_amount']);
        $this->assertEquals(72, $result['seller_receives']);
    }

    public function test_a_percentage_discount_follows_the_price_being_simulated(): void
    {
        $product = DB::table('products')->insertGetId([
            'added_by' => 'seller', 'user_id' => self::SELLER, 'name' => 'A product',
            'unit_price' => 100, 'discount' => 10, 'discount_type' => 'percent',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->simulator()->simulate(self::SELLER, [
            'product_id' => $product, 'unit_price' => 200,
        ]);

        // 10% of the price being asked about, not 10 taken from a price nobody is charging.
        $this->assertEquals(20, $result['discount_per_unit']);
        $this->assertEquals(180, $result['commissionable_amount']);
    }

    public function test_the_simulator_cannot_be_pointed_at_another_shops_product(): void
    {
        $product = DB::table('products')->insertGetId([
            'added_by' => 'seller', 'user_id' => self::RIVAL, 'name' => 'Their product',
            'unit_price' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->simulator()->simulate(self::SELLER, ['product_id' => $product, 'unit_price' => 10]);

        // Not found, so no price and no category leak — a vendor-scoped rule is competitive
        // information about somebody else's business.
        $this->assertNull($result['product']);
        $this->assertEquals(10, $result['unit_price']);
    }

    public function test_a_share_of_nothing_is_not_reported_as_nought_per_cent(): void
    {
        $result = $this->simulator()->simulate(self::SELLER, ['unit_price' => 0, 'quantity' => 1]);

        $this->assertNull($result['effective_rate_percent']);
    }

    public function test_the_simulator_names_what_it_does_not_cover(): void
    {
        $result = $this->simulator()->simulate(self::SELLER, ['unit_price' => 100]);

        // Named rather than estimated: none of them exists for an order nobody has placed.
        $this->assertSame(['tax', 'shipping', 'payment_processing'], $result['excludes']);
    }

    public function test_a_discount_larger_than_the_price_never_makes_the_line_negative(): void
    {
        $result = $this->simulator()->simulate(self::SELLER, [
            'unit_price' => 50, 'discount' => 80, 'discount_type' => 'flat',
        ]);

        $this->assertEquals(0, $result['commissionable_amount']);
        $this->assertEquals(0, $result['seller_receives']);
    }

    public function test_the_first_price_a_product_was_given_is_not_a_change_from_nothing(): void
    {
        $first = ProductPriceChange::create([
            'product_id' => 1, 'seller_id' => self::SELLER,
            'previous_price' => null, 'new_price' => 100, 'source' => 'seller_ui',
        ]);
        $second = ProductPriceChange::create([
            'product_id' => 1, 'seller_id' => self::SELLER,
            'previous_price' => 100, 'new_price' => 120, 'source' => 'bulk_job',
        ]);

        $this->assertTrue($first->isFirstPrice());
        $this->assertEquals(0, $first->delta());
        $this->assertFalse($second->isFirstPrice());
        $this->assertEquals(20, $second->delta());
    }
}
