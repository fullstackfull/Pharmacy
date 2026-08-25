<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Seller;
use App\Models\SellerInsight;
use App\Services\SellerCenter\IssueAction;
use App\Services\SellerCenter\Lists\InventoryList;
use App\Services\SellerCenter\Revenue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsIssueSchema;
use Tests\TestCase;

/**
 * Wave 2's definition of done (handoff 13).
 *
 * The wave is finished when the exception loop works end to end: an issue on the Control Tower
 * drills into a filtered list whose count matches the issue's count, the action resolves it, and
 * the resolution appears in the entity timeline.
 *
 * The count-match is the one these tests guard hardest. A seller told "8 products rejected" who
 * lands on a list of 126 stops believing the number, and after that every other number on the page
 * is worth less too.
 */
class SellerCenterWave2Test extends TestCase
{
    use BuildsIssueSchema;

    private const SELLER = 1;
    private const RIVAL = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['sellers', 'orders', 'order_details', 'products', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createIssueTable();

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('status', 20)->default('approved');
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is', 20)->default('seller');
            $table->string('order_status', 30)->default('pending');
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
            $table->decimal('discount', 24, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('added_by', 20)->default('seller');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('product_type', 20)->default('physical');
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->text('details')->nullable();
            $table->string('thumbnail')->nullable();
            $table->text('images')->nullable();
            $table->text('category_ids')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->integer('status')->default(1);
            $table->integer('request_status')->default(1);
            $table->decimal('unit_price', 24, 3)->default(0);
            $table->integer('current_stock')->default(0);
            $table->timestamps();
        });

        Seller::insert([
            ['id' => self::SELLER, 'f_name' => 'Owner', 'l_name' => 'One', 'status' => 'approved'],
            ['id' => self::RIVAL, 'f_name' => 'Rival', 'l_name' => 'Two', 'status' => 'approved'],
        ]);
    }

    private function product(array $attributes = []): Product
    {
        $product = new Product();
        $product->forceFill(array_merge([
            'added_by' => 'seller',
            'user_id' => self::SELLER,
            'product_type' => 'physical',
            'name' => 'A product',
            'code' => 'SKU-' . uniqid(),
            'status' => 1,
            'request_status' => 1,
            'unit_price' => 100,
            'current_stock' => 10,
        ], $attributes))->save();

        return $product;
    }

    private function deliveredLine(int $productId, int $qty, float $price, float $discount = 0, ?string $at = null): void
    {
        $order = new Order();
        $order->forceFill([
            'seller_id' => self::SELLER, 'seller_is' => 'seller',
            'order_status' => 'delivered', 'order_amount' => $price * $qty - $discount,
            'created_at' => $at ?? now(), 'updated_at' => now(),
        ])->save();

        \Illuminate\Support\Facades\DB::table('order_details')->insert([
            'order_id' => $order->id, 'product_id' => $productId, 'seller_id' => self::SELLER,
            'delivery_status' => 'delivered', 'qty' => $qty, 'price' => $price, 'discount' => $discount,
            'created_at' => $at ?? now(), 'updated_at' => now(),
        ]);
    }

    private function issue(array $attributes = []): SellerInsight
    {
        static $sequence = 0;
        $sequence++;

        return SellerInsight::create(array_merge([
            'seller_id' => self::SELLER,
            'type' => 'PRODUCT_REJECTED',
            'category' => SellerInsight::CATEGORY_CATALOG,
            'severity' => SellerInsight::SEVERITY_HIGH,
            'status' => SellerInsight::STATUS_OPEN,
            'title' => 'products_rejected',
            'fingerprint' => 'fp-' . $sequence,
            'affected_count' => 1,
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ], $attributes));
    }

    // ─────────────────────────────────────────────── the exception loop

    public function test_an_issues_action_carries_the_exact_ids_it_counted(): void
    {
        $issue = $this->issue([
            'affected_count' => 3,
            'action_key' => 'open_products',
            'action_params' => ['product_ids' => [11, 22, 33]],
        ]);

        $action = IssueAction::resolve($issue->action_key, $issue->action_params);

        // The destination is handed the same three subjects the card counted. Without this the
        // seller lands on the whole catalogue and the number stops meaning anything.
        $this->assertSame([11, 22, 33], $action['ids']);
        $this->assertCount(3, $action['ids']);
        $this->assertSame($issue->affected_count, count($action['ids']));
    }

    public function test_an_action_key_the_client_does_not_know_offers_details_rather_than_a_dead_link(): void
    {
        $action = IssueAction::resolve('open_something_invented_next_quarter', ['x' => 1]);

        $this->assertNull($action['href']);
        $this->assertSame(translate('details'), $action['label']);
    }

    public function test_an_issue_with_no_subjects_still_reaches_its_module(): void
    {
        $action = IssueAction::resolve('open_orders', []);

        // No ids to carry, so the link is the module's own list rather than nothing at all.
        $this->assertSame([], $action['ids']);
    }

    // ────────────────────────────────────────────────── revenue truth

    public function test_revenue_is_delivered_lines_net_of_the_line_discount(): void
    {
        $product = $this->product();
        $this->deliveredLine($product->id, 2, 100, 10);

        // 2 × 100 − 10. The briefing, the home KPIs, reconciliation and the payout all read this.
        $this->assertSame(190.0, Revenue::total(self::SELLER));
        $this->assertSame(2, Revenue::units(self::SELLER));
    }

    public function test_revenue_never_counts_another_shops_lines(): void
    {
        $product = $this->product();
        $this->deliveredLine($product->id, 1, 500);

        $this->assertSame(0.0, Revenue::total(self::RIVAL));
    }

    public function test_a_comparison_against_a_period_with_nothing_in_it_is_null_not_infinity(): void
    {
        // Reporting "+∞%" or silently substituting 100 are both lies a seller would act on.
        $this->assertNull(Revenue::change(500.0, 0.0));
        $this->assertSame(100.0, Revenue::change(200.0, 100.0));
        $this->assertSame(-50.0, Revenue::change(50.0, 100.0));
    }

    // ──────────────────────────────────────────────────────── coverage

    public function test_coverage_is_null_when_nothing_is_selling(): void
    {
        $list = app(InventoryList::class);

        // Dividing by zero and printing `∞` would tell a seller their dead stock is their
        // healthiest line.
        $this->assertNull($list->coverage(40, 0.0));
        $this->assertSame(4.0, $list->coverage(40, 10.0));
    }

    public function test_the_stock_state_ladder_matches_the_specified_thresholds(): void
    {
        $list = app(InventoryList::class);

        $this->assertSame('out_of_stock', $list->stateFor(0, null, 5)['state']);
        $this->assertSame('critical', $list->stateFor(0, null, 5)['tone']);

        // One day of cover is critical even with stock on the shelf.
        $this->assertSame('critical', $list->stateFor(10, 0.9, 5)['tone']);
        $this->assertSame('high', $list->stateFor(10, 2.5, 5)['tone']);
        $this->assertSame('good', $list->stateFor(100, 30.0, 5)['tone']);

        // No velocity, but below the marketplace's own low-stock limit: still worth flagging.
        $this->assertSame('high', $list->stateFor(3, null, 5)['tone']);
        $this->assertSame('good', $list->stateFor(50, null, 5)['tone']);
    }

    public function test_reserved_units_are_counted_from_the_orders_that_hold_them(): void
    {
        $product = $this->product();

        $order = new Order();
        $order->forceFill([
            'seller_id' => self::SELLER, 'seller_is' => 'seller',
            'order_status' => 'processing', 'order_amount' => 100,
        ])->save();

        \Illuminate\Support\Facades\DB::table('order_details')->insert([
            'order_id' => $order->id, 'product_id' => $product->id, 'seller_id' => self::SELLER,
            'delivery_status' => 'pending', 'qty' => 4, 'price' => 25, 'discount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $list = app(InventoryList::class);

        // Counted from the lines rather than a cached column, so it cannot drift away from the
        // orders that actually hold the stock.
        $this->assertSame(4, $list->reservedTotal(self::SELLER));
        $this->assertSame([$product->id => 4], $list->reservedFor(self::SELLER, [$product->id]));

        // A delivered order no longer holds anything.
        $order->forceFill(['order_status' => 'delivered'])->save();
        $this->assertSame(0, $list->reservedTotal(self::SELLER));
    }

    // ────────────────────────────────────────────── catalogue reading

    public function test_the_product_status_reads_the_marketplaces_decision_before_the_sellers(): void
    {
        $list = app(\App\Services\SellerCenter\Lists\ProductList::class);

        // A rejected product the seller left switched on is rejected, not active.
        $this->assertSame('rejected', $list->statusOf($this->product(['request_status' => 2, 'status' => 1])));
        $this->assertSame('under_review', $list->statusOf($this->product(['request_status' => 0])));
        $this->assertSame('draft', $list->statusOf($this->product(['status' => 0])));
        $this->assertSame('out_of_stock', $list->statusOf($this->product(['current_stock' => 0])));
        $this->assertSame('active', $list->statusOf($this->product()));
    }

    public function test_listing_quality_counts_only_things_the_seller_can_see_and_fix(): void
    {
        $list = app(\App\Services\SellerCenter\Lists\ProductList::class);

        $bare = $this->product(['name' => 'x', 'details' => null, 'thumbnail' => null, 'images' => '[]', 'code' => null, 'unit_price' => 0, 'category_ids' => null, 'brand_id' => null]);
        $complete = $this->product([
            'name' => 'A complete listing', 'details' => 'Full description', 'thumbnail' => 't.png',
            'images' => '["a.png","b.png"]', 'code' => 'SKU-1', 'unit_price' => 100,
            'category_ids' => '[{"id":1}]', 'brand_id' => 3,
        ]);

        $this->assertLessThan(40, $list->listingQuality($bare));
        $this->assertSame(100, $list->listingQuality($complete));
        $this->assertSame('good', $list->qualityTone(100));
        $this->assertSame('medium', $list->qualityTone(70));
        $this->assertSame('high', $list->qualityTone(30));
    }

    public function test_a_products_issue_is_read_from_the_issue_store_not_guessed_from_the_row(): void
    {
        $product = $this->product(['request_status' => 2]);
        $this->issue([
            'entity_type' => 'product',
            'entity_id' => $product->id,
            'body' => 'Missing required attribute: SPF value',
        ]);

        $issues = app(\App\Services\SellerCenter\Lists\ProductList::class)
            ->issuesFor(self::SELLER, [$product->id]);

        // The precise reason, never the word "Error": the threshold that decides it lives in the
        // detector, and asking the row would mean a second definition of it.
        $this->assertArrayHasKey($product->id, $issues);
        $this->assertSame('Missing required attribute: SPF value', $issues[$product->id]->body);
    }

    public function test_another_shops_issues_never_reach_this_shops_products(): void
    {
        $product = $this->product();
        $this->issue(['seller_id' => self::RIVAL, 'entity_type' => 'product', 'entity_id' => $product->id]);

        $this->assertSame([], app(\App\Services\SellerCenter\Lists\ProductList::class)
            ->issuesFor(self::SELLER, [$product->id]));
    }
}
