<?php

namespace Tests\Feature;

use App\Services\Marketplace\SlaService;
use App\Services\SellerIntelligence\Producers\OrderSlaProducer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The countdown a seller sees on an order.
 *
 * This is the one insight that makes a promise, so it is the one that has to be right. The window
 * is marketplace policy — 24 hours by default, changed on the SLA policy page — and the seller's
 * countdown and the deadline the marketplace judges them by must be the same number. Two clocks is
 * worse than none.
 *
 * Silence matters as much as noise here: an order with most of its window left is not news, and an
 * order late by a month is the breach ledger's business, not a banner.
 */
class OrderSlaInsightTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['orders', 'business_settings'] as $table) {
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
            $table->decimal('order_amount', 24, 3)->default(0);
            $table->timestamps();
        });
    }

    private function order(string $status, Carbon $placedAt, float $amount = 100): int
    {
        return DB::table('orders')->insertGetId([
            'seller_is' => 'seller',
            'seller_id' => 1,
            'order_status' => $status,
            'order_amount' => $amount,
            'created_at' => $placedAt,
            'updated_at' => $placedAt,
        ]);
    }

    private function drafts(): array
    {
        return iterator_to_array(app(OrderSlaProducer::class)->produce(1), false);
    }

    private function setWindow(int $hours): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => 'sla_processing_hours'],
            ['value' => (string) $hours, 'created_at' => now(), 'updated_at' => now()],
        );
        cache()->flush();
    }

    public function test_the_default_policy_is_twenty_four_hours(): void
    {
        // The marketplace's declared window. A seller-facing countdown has to run against a number
        // somebody chose, not one invented per screen.
        $this->assertSame(24, (int) app(SlaService::class)->thresholds()['processing_hours']);
    }

    public function test_an_order_with_most_of_its_window_left_says_nothing(): void
    {
        $this->order('pending', now()->subHours(2));

        $this->assertSame([], $this->drafts(), 'A two-hour-old order interrupted the seller.');
    }

    public function test_an_order_close_to_its_deadline_is_raised_with_the_hours_remaining(): void
    {
        $this->order('pending', now()->subHours(20));

        $drafts = $this->drafts();

        $this->assertCount(1, $drafts);
        $this->assertSame('insight_order_due_soon', $drafts[0]->title);
        $this->assertSame('high', $drafts[0]->severity);
        // Four hours in, twenty out: about one hour left of a twenty-four hour window.
        $this->assertEqualsWithDelta(4.0, $drafts[0]->metric, 0.2);
    }

    public function test_an_order_past_its_deadline_is_critical_and_counts_up(): void
    {
        $this->order('pending', now()->subHours(26));

        $drafts = $this->drafts();

        $this->assertCount(1, $drafts);
        $this->assertSame('insight_order_late', $drafts[0]->title);
        $this->assertSame('critical', $drafts[0]->severity);
        $this->assertLessThan(0, $drafts[0]->metric, 'A late order should report negative time left.');
    }

    public function test_the_countdown_follows_the_policy_rather_than_a_number_of_its_own(): void
    {
        $this->order('pending', now()->subHours(20));

        // Under a 24-hour policy this order is nearly late. Widen the policy and it is not.
        $this->assertCount(1, $this->drafts());

        $this->setWindow(168);

        $this->assertSame([], $this->drafts(), 'The producer kept its own clock instead of the policy.');
    }

    public function test_orders_the_seller_has_already_finished_are_not_chased(): void
    {
        $this->order('delivered', now()->subHours(40));
        $this->order('canceled', now()->subHours(40));
        $this->order('out_for_delivery', now()->subHours(40));

        $this->assertSame([], $this->drafts());
    }

    public function test_another_sellers_late_order_is_not_this_sellers_problem(): void
    {
        DB::table('orders')->insert([
            'seller_is' => 'seller', 'seller_id' => 2, 'order_status' => 'pending',
            'order_amount' => 100, 'created_at' => now()->subHours(30), 'updated_at' => now(),
        ]);

        $this->assertSame([], $this->drafts());
    }

    public function test_an_order_late_by_weeks_stops_being_a_banner(): void
    {
        $this->order('pending', now()->subDays(40));

        $drafts = $this->drafts();

        // Still produced — but with an expiry already behind it, so the Action Center will not show
        // it. Chasing an order six weeks late is the breach ledger's job, not a daily interruption.
        $this->assertCount(1, $drafts);
        $this->assertNotNull($drafts[0]->expiresAt);
        $this->assertTrue($drafts[0]->expiresAt < now());
    }

    public function test_the_insight_carries_what_the_client_needs_to_open_the_order(): void
    {
        $orderId = $this->order('processing', now()->subHours(26), amount: 250);

        $draft = $this->drafts()[0];

        $this->assertSame('order', $draft->entityType);
        $this->assertSame($orderId, $draft->entityId);
        $this->assertSame('open_order', $draft->actionKey);
        $this->assertSame($orderId, $draft->actionParams['order_id']);
        $this->assertArrayHasKey('deadline', $draft->actionParams);
        // What the order is worth, so a list of late orders can be read worst-first by value.
        $this->assertSame(250.0, $draft->impact);
    }
}
