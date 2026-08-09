<?php

namespace Tests\Feature;

use App\Models\OrderFulfillment;
use App\Services\Marketplace\FulfillmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Order fulfilment workflow (Phase 3, Stage C).
 *
 * The forward-only pick → pack → ready → shipped machine: it opens once per order+seller, moves only
 * forward, stamps packed/shipped as they happen, and closes on ship or cancel. It never touches order
 * status — this table is the whole of its state.
 */
class FulfillmentTest extends TestCase
{
    private FulfillmentService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('order_fulfillments');
        Schema::create('order_fulfillments', function (Blueprint $t) {
            $t->id(); $t->string('reference')->unique(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('seller_id')->nullable();
            $t->unsignedBigInteger('warehouse_id')->nullable(); $t->string('status')->default('pending'); $t->unsignedBigInteger('assigned_to')->nullable();
            $t->string('carrier')->nullable(); $t->string('tracking_number')->nullable();
            $t->timestamp('picked_at')->nullable(); $t->timestamp('packed_at')->nullable(); $t->timestamp('shipped_at')->nullable();
            $t->string('note')->nullable(); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
        });

        $this->svc = new FulfillmentService();
    }

    public function test_open_creates_a_pending_fulfilment(): void
    {
        $res = $this->svc->open(100, 7, 3);

        $this->assertTrue($res['ok']);
        $this->assertSame('pending', $res['fulfillment']->status);
        $this->assertMatchesRegularExpression('/^FF-\d{6}-\d{4}$/', $res['fulfillment']->reference);
        $this->assertSame(3, $res['fulfillment']->warehouse_id);
    }

    public function test_a_second_open_fulfilment_for_the_same_order_seller_is_refused(): void
    {
        $this->svc->open(100, 7);
        $res = $this->svc->open(100, 7);

        $this->assertFalse($res['ok']);
        $this->assertSame('an_open_fulfilment_already_exists_for_this_order', $res['reason']);
    }

    public function test_it_moves_forward_through_the_flow_and_stamps_times(): void
    {
        $f = $this->svc->open(100, 7)['fulfillment'];

        $this->assertTrue($this->svc->advance($f, 'picking')['ok']);
        $this->assertTrue($this->svc->advance($f->fresh(), 'packed')['ok']);
        $this->assertNotNull($f->fresh()->packed_at);
        $this->assertNotNull($f->fresh()->picked_at);

        $this->assertTrue($this->svc->advance($f->fresh(), 'ready')['ok']);
        $res = $this->svc->advance($f->fresh(), 'shipped', ['carrier' => 'DHL', 'tracking_number' => 'T-1']);
        $this->assertTrue($res['ok']);
        $this->assertSame('shipped', $f->fresh()->status);
        $this->assertNotNull($f->fresh()->shipped_at);
        $this->assertSame('DHL', $f->fresh()->carrier);
    }

    public function test_it_can_skip_forward_but_never_backward(): void
    {
        $f = $this->svc->open(100, 7)['fulfillment'];

        // Skipping forward (pending -> packed) is allowed.
        $this->assertTrue($this->svc->advance($f, 'packed')['ok']);

        // Going back (packed -> picking) is refused.
        $back = $this->svc->advance($f->fresh(), 'picking');
        $this->assertFalse($back['ok']);
        $this->assertSame('a_fulfilment_can_only_move_forward', $back['reason']);

        // Repeating the same state is refused too.
        $same = $this->svc->advance($f->fresh(), 'packed');
        $this->assertFalse($same['ok']);
    }

    public function test_a_shipped_fulfilment_is_closed(): void
    {
        $f = $this->svc->open(100, 7)['fulfillment'];
        $this->svc->advance($f, 'shipped');

        $this->assertFalse($f->fresh()->isOpen());
        $res = $this->svc->advance($f->fresh(), 'shipped');
        $this->assertFalse($res['ok']);
        $this->assertSame('fulfilment_is_already_closed', $res['reason']);
    }

    public function test_cancel_closes_it_and_a_new_one_can_then_open(): void
    {
        $f = $this->svc->open(100, 7)['fulfillment'];
        $this->assertTrue($this->svc->cancel($f)['ok']);
        $this->assertSame('canceled', $f->fresh()->status);

        // With the first canceled (closed), a fresh fulfilment for the same order+seller is allowed.
        $this->assertTrue($this->svc->open(100, 7)['ok']);
    }
}
