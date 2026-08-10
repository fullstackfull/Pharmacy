<?php

namespace Tests\Feature;

use App\Models\ReturnShipment;
use App\Models\StockMovement;
use App\Services\Marketplace\ReturnLogisticsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Returns logistics (Phase 3, Stage C).
 *
 * The property that closes the loop: receiving a restockable return puts the unit back into sellable
 * stock and records a `return` movement. Around it: the state machine (authorize → in_transit →
 * received/restocked → or rejected), an unsellable return received but NOT restocked, and the guards.
 */
class ReturnLogisticsTest extends TestCase
{
    private ReturnLogisticsService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['return_shipments', 'stock_movements', 'products'] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('products', function (Blueprint $t) {
            $t->id(); $t->integer('current_stock')->default(0); $t->timestamps();
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('seller_id')->nullable();
            $t->string('type', 20); $t->integer('qty_change'); $t->integer('balance_after')->nullable();
            $t->string('reason', 60)->nullable(); $t->string('reference_type', 40)->nullable(); $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('note', 512)->nullable(); $t->unsignedBigInteger('created_by')->nullable(); $t->string('created_by_type', 20)->nullable(); $t->timestamps();
        });
        Schema::create('return_shipments', function (Blueprint $t) {
            $t->id(); $t->string('reference')->unique();
            $t->unsignedBigInteger('order_id')->nullable(); $t->unsignedBigInteger('order_details_id')->nullable();
            $t->unsignedBigInteger('refund_request_id')->nullable(); $t->unsignedBigInteger('product_id')->nullable(); $t->unsignedBigInteger('seller_id')->nullable();
            $t->unsignedInteger('qty')->default(1); $t->string('reason')->nullable(); $t->string('status')->default('authorized');
            $t->boolean('restock')->default(true); $t->string('carrier')->nullable(); $t->string('tracking_number')->nullable();
            $t->timestamp('received_at')->nullable(); $t->string('note')->nullable(); $t->unsignedBigInteger('created_by')->nullable(); $t->string('created_by_type')->nullable(); $t->timestamps();
        });

        $this->svc = new ReturnLogisticsService();
    }

    private function product(int $stock = 100): int
    {
        return DB::table('products')->insertGetId(['current_stock' => $stock]);
    }

    public function test_authorize_opens_a_return_with_a_reference(): void
    {
        $id = $this->product();
        $rma = $this->svc->authorize(['product_id' => $id, 'qty' => 2, 'reason' => 'wrong_item', 'refund_request_id' => 55]);

        $this->assertSame('authorized', $rma->status);
        $this->assertMatchesRegularExpression('/^RMA-\d{6}-\d{4}$/', $rma->reference);
        $this->assertSame(55, $rma->refund_request_id);
    }

    public function test_mark_in_transit_requires_an_authorized_return(): void
    {
        $rma = $this->svc->authorize(['product_id' => $this->product(), 'qty' => 1]);

        $ok = $this->svc->markInTransit($rma, 'DHL', 'TRACK123');
        $this->assertTrue($ok['ok']);
        $this->assertSame('in_transit', $rma->fresh()->status);
        $this->assertSame('DHL', $rma->fresh()->carrier);

        // Doing it again is refused — it is no longer authorized.
        $again = $this->svc->markInTransit($rma->fresh(), 'DHL', 'X');
        $this->assertFalse($again['ok']);
    }

    public function test_receiving_a_restockable_return_puts_stock_back_and_logs_a_return_movement(): void
    {
        $id = $this->product(100);
        $rma = $this->svc->authorize(['product_id' => $id, 'seller_id' => 7, 'qty' => 3, 'reason' => 'changed_mind']);
        $this->svc->markInTransit($rma);

        $res = $this->svc->receive($rma->fresh(), 42);

        $this->assertTrue($res['ok']);
        $this->assertTrue($res['restocked']);
        $this->assertSame(103, (int) DB::table('products')->where('id', $id)->value('current_stock'));
        $this->assertSame('restocked', $rma->fresh()->status);

        $m = StockMovement::where('product_id', $id)->where('type', 'return')->first();
        $this->assertNotNull($m);
        $this->assertSame(3, $m->qty_change);
        $this->assertSame(103, $m->balance_after);
        $this->assertSame('return_shipment', $m->reference_type);
        $this->assertSame($rma->id, $m->reference_id);
    }

    /** A minimal order_details table for the legacy-coordination tests. */
    private function orderLine(int $qty, int $isStockDecreased): int
    {
        Schema::dropIfExists('order_details');
        Schema::create('order_details', function (Blueprint $t) {
            $t->id(); $t->integer('qty')->default(0); $t->boolean('is_stock_decreased')->default(1); $t->timestamps();
        });

        return DB::table('order_details')->insertGetId(['qty' => $qty, 'is_stock_decreased' => $isStockDecreased]);
    }

    private function linkedRma(int $productId, int $qty, int $detailId)
    {
        $rma = $this->svc->authorize(['product_id' => $productId, 'qty' => $qty, 'reason' => 'x']);
        DB::table('return_shipments')->where('id', $rma->id)->update(['order_details_id' => $detailId]);
        $this->svc->markInTransit($rma->fresh());

        return $rma->fresh();
    }

    public function test_a_full_return_claims_the_line_so_the_legacy_path_cannot_double_restock(): void
    {
        $detailId = $this->orderLine(qty: 3, isStockDecreased: 1);
        $productId = $this->product(100);

        $res = $this->svc->receive($this->linkedRma($productId, 3, $detailId), 42);

        $this->assertTrue($res['restocked']);
        $this->assertSame(103, (int) DB::table('products')->where('id', $productId)->value('current_stock'), 'restocked once');
        $this->assertSame(0, (int) DB::table('order_details')->where('id', $detailId)->value('is_stock_decreased'), 'claimed, so the legacy restore will skip');

        Schema::dropIfExists('order_details');
    }

    public function test_a_full_return_skips_restock_when_the_line_was_already_restored(): void
    {
        $detailId = $this->orderLine(qty: 3, isStockDecreased: 0);   // legacy already restored it
        $productId = $this->product(100);

        $res = $this->svc->receive($this->linkedRma($productId, 3, $detailId), 42);

        $this->assertFalse($res['restocked'], 'no second restock');
        $this->assertSame(100, (int) DB::table('products')->where('id', $productId)->value('current_stock'), 'stock unchanged');
        $this->assertSame('received', $res['return']->status, 'received, not restocked');

        Schema::dropIfExists('order_details');
    }

    public function test_a_partial_return_restocks_without_touching_the_marker(): void
    {
        $detailId = $this->orderLine(qty: 5, isStockDecreased: 1);
        $productId = $this->product(100);

        $res = $this->svc->receive($this->linkedRma($productId, 2, $detailId), 42);   // partial: 2 of 5

        $this->assertTrue($res['restocked']);
        $this->assertSame(102, (int) DB::table('products')->where('id', $productId)->value('current_stock'), 'partial restock');
        $this->assertSame(1, (int) DB::table('order_details')->where('id', $detailId)->value('is_stock_decreased'), 'marker untouched so the legacy path can restore the rest');

        Schema::dropIfExists('order_details');
    }

    public function test_an_unsellable_return_is_received_but_not_restocked(): void
    {
        $id = $this->product(100);
        $rma = $this->svc->authorize(['product_id' => $id, 'qty' => 1, 'restock' => false, 'reason' => 'damaged']);

        $res = $this->svc->receive($rma->fresh());

        $this->assertTrue($res['ok']);
        $this->assertFalse($res['restocked']);
        $this->assertSame('received', $rma->fresh()->status);
        $this->assertSame(100, (int) DB::table('products')->where('id', $id)->value('current_stock'), 'stock untouched');
        $this->assertSame(0, StockMovement::count());
    }

    public function test_a_return_without_a_product_is_received_without_restock(): void
    {
        $rma = $this->svc->authorize(['order_id' => 10, 'qty' => 1, 'reason' => 'not_catalogued']);

        $res = $this->svc->receive($rma->fresh());

        $this->assertTrue($res['ok']);
        $this->assertFalse($res['restocked']);
        $this->assertSame('received', $rma->fresh()->status);
    }

    public function test_a_received_return_cannot_be_received_again(): void
    {
        $rma = $this->svc->authorize(['product_id' => $this->product(), 'qty' => 1]);
        $this->svc->receive($rma->fresh());

        $res = $this->svc->receive($rma->fresh());
        $this->assertFalse($res['ok']);
        $this->assertSame('return_is_not_in_a_receivable_state', $res['reason']);
    }

    public function test_reject_closes_an_open_return(): void
    {
        $rma = $this->svc->authorize(['product_id' => $this->product(), 'qty' => 1]);

        $res = $this->svc->reject($rma->fresh(), 'never arrived');
        $this->assertTrue($res['ok']);
        $this->assertSame('rejected', $rma->fresh()->status);

        // A rejected return is closed — it cannot then be received.
        $this->assertFalse($this->svc->receive($rma->fresh())['ok']);
    }
}
