<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\Marketplace\PurchaseOrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Procurement — purchase orders and receiving (Phase 3, Stage C).
 *
 * The property that makes this the supply side, asserted here: receiving a line increments the
 * catalogue stock the storefront sells. Around it: the header status is derived from the lines (so it
 * never disagrees with them), a line can never be over-received, and a placed/received order stops
 * being editable/cancelable at the right points.
 */
class PurchaseOrderTest extends TestCase
{
    private PurchaseOrderService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['purchase_order_items', 'purchase_orders', 'suppliers', 'products'] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('suppliers', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('seller_id')->nullable(); $t->string('name'); $t->string('status')->default('active'); $t->timestamps();
        });
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id(); $t->string('reference')->unique(); $t->unsignedBigInteger('supplier_id'); $t->unsignedBigInteger('seller_id')->nullable();
            $t->string('status')->default('draft'); $t->string('currency')->nullable();
            $t->decimal('subtotal', 24, 2)->default(0); $t->decimal('total', 24, 2)->default(0);
            $t->date('expected_at')->nullable(); $t->timestamp('ordered_at')->nullable(); $t->timestamp('received_at')->nullable();
            $t->string('notes')->nullable(); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
        });
        Schema::create('purchase_order_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('purchase_order_id'); $t->unsignedBigInteger('product_id')->nullable();
            $t->string('description')->nullable(); $t->string('sku')->nullable();
            $t->unsignedInteger('qty_ordered')->default(0); $t->unsignedInteger('qty_received')->default(0);
            $t->decimal('unit_cost', 24, 2)->default(0); $t->decimal('line_total', 24, 2)->default(0); $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id(); $t->integer('current_stock')->default(0); $t->timestamps();
        });

        $this->svc = new PurchaseOrderService();
    }

    private function supplier(): Supplier
    {
        return Supplier::create(['name' => 'Acme Supply', 'status' => 'active']);
    }

    private function draftFor(int $productId, int $qty = 10, float $cost = 2.5): PurchaseOrder
    {
        return $this->svc->create($this->supplier()->id, null, [], [
            ['product_id' => $productId, 'qty_ordered' => $qty, 'unit_cost' => $cost],
        ]);
    }

    public function test_create_makes_a_draft_and_computes_totals(): void
    {
        DB::table('products')->insert(['id' => 1, 'current_stock' => 0]);
        $po = $this->svc->create($this->supplier()->id, null, ['notes' => 'restock'], [
            ['product_id' => 1, 'qty_ordered' => 10, 'unit_cost' => 2.5],
            ['product_id' => 1, 'qty_ordered' => 4, 'unit_cost' => 5.0],
        ]);

        $this->assertSame('draft', $po->status);
        $this->assertMatchesRegularExpression('/^PO-\d{6}-\d{4}$/', $po->reference);
        $this->assertEqualsWithDelta(45.0, (float) $po->total, 0.001, '10*2.5 + 4*5');
        $this->assertSame(2, $po->items()->count());
    }

    public function test_a_draft_cannot_be_received_until_it_is_placed(): void
    {
        DB::table('products')->insert(['id' => 1, 'current_stock' => 100]);
        $po = $this->draftFor(1, 10);
        $item = $po->items()->first();

        $res = $this->svc->receiveItem($item, 5);

        $this->assertFalse($res['ok']);
        $this->assertSame('order_is_not_in_a_receivable_state', $res['reason']);
        $this->assertSame(100, (int) DB::table('products')->where('id', 1)->value('current_stock'), 'stock untouched');
    }

    public function test_an_empty_order_cannot_be_placed(): void
    {
        $po = $this->svc->create($this->supplier()->id, null, [], []);
        $res = $this->svc->place($po);

        $this->assertFalse($res['ok']);
        $this->assertSame('cannot_place_an_empty_order', $res['reason']);
    }

    public function test_receiving_increments_the_product_stock(): void
    {
        DB::table('products')->insert(['id' => 1, 'current_stock' => 100]);
        $po = $this->draftFor(1, 10);
        $this->svc->place($po->fresh());

        $res = $this->svc->receiveItem($po->items()->first(), 6);

        $this->assertTrue($res['ok']);
        $this->assertSame(106, (int) DB::table('products')->where('id', 1)->value('current_stock'));
        $this->assertSame('partially_received', $po->fresh()->status);
    }

    public function test_a_line_cannot_be_over_received(): void
    {
        DB::table('products')->insert(['id' => 1, 'current_stock' => 0]);
        $po = $this->draftFor(1, 10);
        $this->svc->place($po->fresh());

        $res = $this->svc->receiveItem($po->items()->first(), 11);

        $this->assertFalse($res['ok']);
        $this->assertSame('cannot_receive_more_than_was_ordered', $res['reason']);
        $this->assertSame(0, (int) DB::table('products')->where('id', 1)->value('current_stock'));
    }

    public function test_receiving_everything_marks_the_order_received(): void
    {
        DB::table('products')->insert(['id' => 1, 'current_stock' => 0]);
        $po = $this->draftFor(1, 10);
        $this->svc->place($po->fresh());

        $this->svc->receiveItem($po->items()->first(), 4);
        $this->assertSame('partially_received', $po->fresh()->status);

        $this->svc->receiveItem($po->items()->first()->fresh(), 6);
        $fresh = $po->fresh();
        $this->assertSame('received', $fresh->status);
        $this->assertNotNull($fresh->received_at);
        $this->assertSame(10, (int) DB::table('products')->where('id', 1)->value('current_stock'));
    }

    public function test_receive_all_clears_every_outstanding_line(): void
    {
        DB::table('products')->insert([['id' => 1, 'current_stock' => 0], ['id' => 2, 'current_stock' => 5]]);
        $po = $this->svc->create($this->supplier()->id, null, [], [
            ['product_id' => 1, 'qty_ordered' => 3, 'unit_cost' => 1],
            ['product_id' => 2, 'qty_ordered' => 7, 'unit_cost' => 1],
        ]);
        $this->svc->place($po->fresh());

        $this->svc->receiveAll($po->fresh());

        $this->assertSame('received', $po->fresh()->status);
        $this->assertSame(3, (int) DB::table('products')->where('id', 1)->value('current_stock'));
        $this->assertSame(12, (int) DB::table('products')->where('id', 2)->value('current_stock'));
    }

    public function test_a_fully_received_order_cannot_be_canceled(): void
    {
        DB::table('products')->insert(['id' => 1, 'current_stock' => 0]);
        $po = $this->draftFor(1, 2);
        $this->svc->place($po->fresh());
        $this->svc->receiveAll($po->fresh());

        $res = $this->svc->cancel($po->fresh());

        $this->assertFalse($res['ok']);
        $this->assertSame('a_fully_received_order_cannot_be_canceled', $res['reason']);
    }

    public function test_a_line_with_no_product_receives_without_touching_stock(): void
    {
        // A purchase for something not yet in the catalogue: qty tracked, no stock row to bump.
        $po = $this->svc->create($this->supplier()->id, null, [], [
            ['description' => 'New gadget, not catalogued', 'qty_ordered' => 5, 'unit_cost' => 3],
        ]);
        $this->svc->place($po->fresh());

        $res = $this->svc->receiveItem($po->items()->first(), 5);

        $this->assertTrue($res['ok']);
        $this->assertSame('received', $po->fresh()->status);
        $this->assertSame(5, (int) $po->items()->first()->qty_received);
    }
}
