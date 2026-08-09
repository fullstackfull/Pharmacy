<?php

namespace Tests\Feature;

use App\Services\Marketplace\SellerScorecardService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Seller performance scorecard (Phase 3, Stage A).
 *
 * Two things are asserted: the metrics are computed from the real columns (order_status vocabulary,
 * products.user_id for a product's seller, order_details.refund_request for a refunded line), and the
 * tier is derived consistently — including the honest 'new' when a seller has not traded, so a fresh
 * account is never labelled good or at-risk on no evidence.
 */
class SellerScorecardTest extends TestCase
{
    private SellerScorecardService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['orders', 'order_details', 'reviews', 'products', 'product_moderation_events'] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('seller_id')->nullable(); $t->string('seller_is')->default('seller');
            $t->string('order_status')->default('pending'); $t->timestamps();
        });
        Schema::create('order_details', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('seller_id')->nullable(); $t->integer('refund_request')->default(0); $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id')->nullable(); $t->timestamps();
        });
        Schema::create('reviews', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('product_id'); $t->integer('rating')->default(5); $t->integer('status')->default(1); $t->timestamps();
        });
        Schema::create('product_moderation_events', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('product_id'); $t->string('action'); $t->timestamps();
        });

        $this->svc = new SellerScorecardService();
    }

    private function order(int $sellerId, string $status, string $sellerIs = 'seller'): void
    {
        DB::table('orders')->insert(['seller_id' => $sellerId, 'seller_is' => $sellerIs, 'order_status' => $status]);
    }

    // ---- deriveTier is a pure function ----

    public function test_a_seller_with_no_activity_is_new_not_good(): void
    {
        $tier = $this->svc->deriveTier(['orders_total' => 0, 'review_count' => 0, 'moderation_strikes' => 0]);
        $this->assertSame(SellerScorecardService::TIER_NEW, $tier);
    }

    public function test_a_clean_trading_seller_is_good(): void
    {
        $tier = $this->svc->deriveTier([
            'orders_total' => 50, 'review_count' => 20, 'avg_rating' => 4.7,
            'cancellation_rate' => 0.02, 'return_rate' => 0.01, 'refund_rate' => 0.02, 'moderation_strikes' => 0,
        ]);
        $this->assertSame(SellerScorecardService::TIER_GOOD, $tier);
    }

    public function test_a_high_cancellation_rate_puts_a_seller_at_risk(): void
    {
        $tier = $this->svc->deriveTier([
            'orders_total' => 50, 'review_count' => 10, 'avg_rating' => 4.5,
            'cancellation_rate' => 0.20, 'return_rate' => 0.0, 'refund_rate' => 0.0, 'moderation_strikes' => 0,
        ]);
        $this->assertSame(SellerScorecardService::TIER_AT_RISK, $tier);
    }

    public function test_three_moderation_strikes_puts_a_seller_at_risk(): void
    {
        $tier = $this->svc->deriveTier([
            'orders_total' => 50, 'review_count' => 10, 'avg_rating' => 4.6,
            'cancellation_rate' => 0.0, 'return_rate' => 0.0, 'refund_rate' => 0.0, 'moderation_strikes' => 3,
        ]);
        $this->assertSame(SellerScorecardService::TIER_AT_RISK, $tier);
    }

    public function test_a_single_strike_is_only_a_watch(): void
    {
        $tier = $this->svc->deriveTier([
            'orders_total' => 50, 'review_count' => 10, 'avg_rating' => 4.6,
            'cancellation_rate' => 0.0, 'return_rate' => 0.0, 'refund_rate' => 0.0, 'moderation_strikes' => 1,
        ]);
        $this->assertSame(SellerScorecardService::TIER_WATCH, $tier);
    }

    public function test_one_bad_review_does_not_sink_a_seller_below_the_rating_threshold(): void
    {
        // Only 3 reviews (< 5), average dragged to 2.0 — but too few to be statistically meaningful,
        // so rating must NOT trigger at-risk on its own.
        $tier = $this->svc->deriveTier([
            'orders_total' => 20, 'review_count' => 3, 'avg_rating' => 2.0,
            'cancellation_rate' => 0.0, 'return_rate' => 0.0, 'refund_rate' => 0.0, 'moderation_strikes' => 0,
        ]);
        $this->assertSame(SellerScorecardService::TIER_GOOD, $tier);
    }

    public function test_a_poor_rating_with_enough_reviews_is_at_risk(): void
    {
        $tier = $this->svc->deriveTier([
            'orders_total' => 20, 'review_count' => 12, 'avg_rating' => 2.4,
            'cancellation_rate' => 0.0, 'return_rate' => 0.0, 'refund_rate' => 0.0, 'moderation_strikes' => 0,
        ]);
        $this->assertSame(SellerScorecardService::TIER_AT_RISK, $tier);
    }

    // ---- scorecard() computes from the real columns ----

    public function test_it_counts_orders_by_the_platform_status_vocabulary(): void
    {
        foreach (['delivered', 'delivered', 'delivered', 'canceled', 'returned', 'pending'] as $s) {
            $this->order(7, $s);
        }
        // An in-house (admin) order for the same id must not count against the seller.
        $this->order(7, 'canceled', 'admin');

        $card = $this->svc->scorecard(7);

        $this->assertSame(6, $card['orders_total'], 'only seller_is=seller orders');
        $this->assertSame(3, $card['delivered']);
        $this->assertSame(1, $card['canceled']);
        $this->assertSame(1, $card['returned']);
        $this->assertSame(0.5, $card['fulfillment_rate']);
        $this->assertEqualsWithDelta(0.1667, $card['cancellation_rate'], 0.0001);
    }

    public function test_it_computes_refund_rate_from_order_details(): void
    {
        DB::table('order_details')->insert([
            ['seller_id' => 7, 'refund_request' => 0],
            ['seller_id' => 7, 'refund_request' => 0],
            ['seller_id' => 7, 'refund_request' => 1],
            ['seller_id' => 9, 'refund_request' => 1], // another seller, must not count
        ]);

        $card = $this->svc->scorecard(7);

        $this->assertSame(3, $card['items_total']);
        $this->assertSame(1, $card['items_refunded']);
        $this->assertEqualsWithDelta(0.3333, $card['refund_rate'], 0.0001);
    }

    public function test_it_attributes_reviews_to_a_seller_through_their_products(): void
    {
        DB::table('products')->insert([['id' => 100, 'user_id' => 7], ['id' => 200, 'user_id' => 9]]);
        DB::table('reviews')->insert([
            ['product_id' => 100, 'rating' => 4, 'status' => 1],
            ['product_id' => 100, 'rating' => 2, 'status' => 1],
            ['product_id' => 100, 'rating' => 5, 'status' => 0], // inactive — excluded
            ['product_id' => 200, 'rating' => 1, 'status' => 1], // other seller — excluded
        ]);

        $card = $this->svc->scorecard(7);

        $this->assertSame(2, $card['review_count']);
        $this->assertSame(3.0, $card['avg_rating']);
    }

    public function test_it_counts_only_negative_moderation_events_as_strikes(): void
    {
        DB::table('products')->insert([['id' => 100, 'user_id' => 7]]);
        DB::table('product_moderation_events')->insert([
            ['product_id' => 100, 'action' => 'rejected'],
            ['product_id' => 100, 'action' => 'suspended'],
            ['product_id' => 100, 'action' => 'approved'], // not a strike
        ]);

        $card = $this->svc->scorecard(7);

        $this->assertSame(2, $card['moderation_strikes']);
    }

    public function test_a_missing_table_yields_zero_rather_than_fataling(): void
    {
        Schema::dropIfExists('orders');

        $card = $this->svc->scorecard(7);

        $this->assertSame(0, $card['orders_total']);
        $this->assertSame(SellerScorecardService::TIER_NEW, $card['tier'], 'no evidence -> new');
    }
}
