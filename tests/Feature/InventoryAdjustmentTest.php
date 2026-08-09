<?php

namespace Tests\Feature;

use App\Models\StockMovement;
use App\Services\Marketplace\InventoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Inventory adjustments and the movement log (Phase 3, Stage C).
 *
 * The properties asserted: an adjustment changes the real stock and leaves a movement row carrying the
 * signed change and the resulting balance; it refuses to drive stock negative or to be a no-op; and
 * the log primitive never throws into its caller (a missing history line must not break a receipt).
 */
class InventoryAdjustmentTest extends TestCase
{
    private InventoryService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['stock_movements', 'products'] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('products', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id')->nullable(); $t->integer('current_stock')->default(0); $t->timestamps();
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('seller_id')->nullable();
            $t->string('type', 20); $t->integer('qty_change'); $t->integer('balance_after')->nullable();
            $t->string('reason', 60)->nullable(); $t->string('reference_type', 40)->nullable(); $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('note', 512)->nullable(); $t->unsignedBigInteger('created_by')->nullable(); $t->string('created_by_type', 20)->nullable(); $t->timestamps();
        });

        $this->svc = new InventoryService();
    }

    private function product(int $stock = 100, ?int $sellerId = null): int
    {
        return DB::table('products')->insertGetId(['current_stock' => $stock, 'user_id' => $sellerId]);
    }

    public function test_an_increase_raises_stock_and_logs_a_movement(): void
    {
        $id = $this->product(100);

        $res = $this->svc->adjust($id, 15, 'found', 'recount after audit', 42);

        $this->assertTrue($res['ok']);
        $this->assertSame(115, $res['balance_after']);
        $this->assertSame(115, (int) DB::table('products')->where('id', $id)->value('current_stock'));

        $m = StockMovement::where('product_id', $id)->first();
        $this->assertSame('adjustment', $m->type);
        $this->assertSame(15, $m->qty_change);
        $this->assertSame(115, $m->balance_after);
        $this->assertSame('found', $m->reason);
        $this->assertSame(42, $m->created_by);
    }

    public function test_a_decrease_lowers_stock(): void
    {
        $id = $this->product(100);

        $res = $this->svc->adjust($id, -30, 'damage');

        $this->assertTrue($res['ok']);
        $this->assertSame(70, $res['balance_after']);
        $this->assertSame(-30, StockMovement::where('product_id', $id)->value('qty_change'));
    }

    public function test_an_adjustment_cannot_drive_stock_negative(): void
    {
        $id = $this->product(10);

        $res = $this->svc->adjust($id, -11, 'loss');

        $this->assertFalse($res['ok']);
        $this->assertSame('adjustment_would_make_stock_negative', $res['reason']);
        $this->assertSame(10, (int) DB::table('products')->where('id', $id)->value('current_stock'), 'stock untouched');
        $this->assertSame(0, StockMovement::count(), 'no movement written on a refused adjustment');
    }

    public function test_a_zero_adjustment_is_refused(): void
    {
        $id = $this->product(10);

        $res = $this->svc->adjust($id, 0, 'count_correction');

        $this->assertFalse($res['ok']);
        $this->assertSame('adjustment_cannot_be_zero', $res['reason']);
    }

    public function test_adjusting_a_missing_product_is_reported_not_fatal(): void
    {
        $res = $this->svc->adjust(999999, 5, 'found');

        $this->assertFalse($res['ok']);
        $this->assertSame('product_not_found', $res['reason']);
    }

    public function test_the_movement_carries_the_products_seller(): void
    {
        $id = $this->product(50, sellerId: 7);

        $this->svc->adjust($id, 5, 'found');

        $this->assertSame(7, StockMovement::where('product_id', $id)->value('seller_id'));
    }

    public function test_record_appends_a_movement_for_an_already_applied_change(): void
    {
        $m = $this->svc->record(productId: 3, type: StockMovement::TYPE_RECEIPT, qtyChange: 20, balanceAfter: 120, referenceType: 'purchase_order', referenceId: 5);

        $this->assertNotNull($m);
        $this->assertSame('receipt', $m->type);
        $this->assertSame('purchase_order', $m->reference_type);
        $this->assertSame(5, $m->reference_id);
    }

    public function test_record_never_throws_when_the_table_is_missing(): void
    {
        Schema::dropIfExists('stock_movements');

        $this->assertNull($this->svc->record(productId: 3, type: 'receipt', qtyChange: 1), 'returns null rather than throwing');
    }
}
