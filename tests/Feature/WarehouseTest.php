<?php

namespace Tests\Feature;

use App\Models\Warehouse;
use App\Services\Marketplace\WarehouseService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Multi-warehouse stock allocation and transfers (Phase 3, Stage C).
 *
 * The invariant that keeps this connected and safe: every operation preserves `current_stock` — it
 * only partitions it. Allocation is capped by the unallocated remainder, transfers relocate without
 * changing the sellable total, and the guards refuse the impossible.
 */
class WarehouseTest extends TestCase
{
    private WarehouseService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['warehouse_stock', 'warehouses', 'products'] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('products', function (Blueprint $t) {
            $t->id(); $t->integer('current_stock')->default(0); $t->timestamps();
        });
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('seller_id')->nullable(); $t->string('name'); $t->string('code')->nullable();
            $t->string('address')->nullable(); $t->boolean('is_default')->default(false); $t->string('status')->default('active');
            $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
        });
        Schema::create('warehouse_stock', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('warehouse_id'); $t->unsignedBigInteger('product_id'); $t->unsignedInteger('quantity')->default(0); $t->timestamps();
            $t->unique(['warehouse_id', 'product_id']);
        });

        $this->svc = new WarehouseService();
    }

    private function product(int $stock = 100): int
    {
        return DB::table('products')->insertGetId(['current_stock' => $stock]);
    }

    private function warehouse(string $name): int
    {
        return Warehouse::create(['name' => $name, 'status' => 'active'])->id;
    }

    public function test_unallocated_is_current_stock_minus_what_is_placed(): void
    {
        $p = $this->product(100);
        $w = $this->warehouse('A');
        $this->assertSame(100, $this->svc->unallocated($p));

        $this->svc->place($w, $p, 30);
        $this->assertSame(30, $this->svc->allocated($p));
        $this->assertSame(70, $this->svc->unallocated($p));
    }

    public function test_placing_cannot_exceed_the_unallocated_remainder(): void
    {
        $p = $this->product(50);
        $w = $this->warehouse('A');

        $res = $this->svc->place($w, $p, 60);

        $this->assertFalse($res['ok']);
        $this->assertSame('not_enough_unallocated_stock', $res['reason']);
        $this->assertSame(0, $this->svc->allocated($p));
    }

    public function test_removing_returns_stock_to_the_unallocated_pool(): void
    {
        $p = $this->product(100);
        $w = $this->warehouse('A');
        $this->svc->place($w, $p, 40);

        $this->svc->remove($w, $p, 15);

        $this->assertSame(25, $this->svc->allocated($p));
        $this->assertSame(75, $this->svc->unallocated($p));
    }

    public function test_a_transfer_relocates_without_changing_sellable_stock(): void
    {
        $p = $this->product(100);
        $a = $this->warehouse('A');
        $b = $this->warehouse('B');
        $this->svc->place($a, $p, 50);

        $res = $this->svc->transfer($a, $b, $p, 20);

        $this->assertTrue($res['ok']);
        $this->assertSame(30, $res['from_quantity']);
        $this->assertSame(20, $res['to_quantity']);
        // current_stock and total allocated are both unchanged — only the location moved.
        $this->assertSame(100, (int) DB::table('products')->where('id', $p)->value('current_stock'));
        $this->assertSame(50, $this->svc->allocated($p));
    }

    public function test_a_transfer_cannot_move_more_than_the_source_holds(): void
    {
        $p = $this->product(100);
        $a = $this->warehouse('A');
        $b = $this->warehouse('B');
        $this->svc->place($a, $p, 10);

        $res = $this->svc->transfer($a, $b, $p, 25);

        $this->assertFalse($res['ok']);
        $this->assertSame('source_warehouse_does_not_hold_that_many', $res['reason']);
        $this->assertSame(10, $this->svc->allocated($p), 'nothing moved');
    }

    public function test_a_transfer_to_the_same_warehouse_is_refused(): void
    {
        $p = $this->product(100);
        $a = $this->warehouse('A');
        $this->svc->place($a, $p, 10);

        $res = $this->svc->transfer($a, $a, $p, 5);
        $this->assertFalse($res['ok']);
        $this->assertSame('source_and_destination_must_differ', $res['reason']);
    }

    public function test_breakdown_shows_per_warehouse_and_unallocated(): void
    {
        $p = $this->product(100);
        $a = $this->warehouse('A');
        $b = $this->warehouse('B');
        $this->svc->place($a, $p, 30);
        $this->svc->place($b, $p, 25);

        $breakdown = $this->svc->breakdown($p);

        $this->assertSame(55, $breakdown['allocated']);
        $this->assertSame(45, $breakdown['unallocated']);
        $this->assertSame([$a => 30, $b => 25], $breakdown['by_warehouse']);
    }
}
