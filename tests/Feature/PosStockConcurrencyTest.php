<?php

namespace Tests\Feature;

use App\Http\Controllers\RestAPI\v3\seller\POSController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression for the seller-app (v3) POS oversell (stabilization P1).
 *
 * The old code wrote stock as an absolute value (current_stock = stale_value - qty) with no lock and no
 * guard, so concurrent sales lost decrements and stock could go negative. The fix uses the same atomic
 * conditional decrement the Admin/Vendor POS already uses: a `>= qty` guard that can never take stock
 * below zero. This pins that a decrement larger than the remaining stock floors at zero rather than
 * going negative.
 */
class PosStockConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('products');
        Schema::create('products', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->integer('current_stock')->default(0);
            $t->string('product_type')->default('physical');
            $t->text('variation')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('seller_id')->nullable();
            $t->timestamps();
        });
        // stock_movements intentionally absent — InventoryService::record guards on it, so the POS path
        // must still work without a movement table.
        Schema::dropIfExists('stock_movements');
    }

    private function stock(): int
    {
        return (int) DB::table('products')->where('id', 1)->value('current_stock');
    }

    public function test_stock_decrements_atomically_and_never_goes_negative(): void
    {
        DB::table('products')->insert(['id' => 1, 'current_stock' => 5, 'product_type' => 'physical', 'variation' => '[]']);
        $pos = app(POSController::class);
        $product = ['id' => 1, 'current_stock' => 5, 'product_type' => 'physical', 'variation' => '[]', 'user_id' => 9];

        // Sell 3 of 5 -> 2 left.
        $pos->getProductStockCalculate(['variant' => null, 'quantity' => 3], $product, 999);
        $this->assertSame(2, $this->stock());

        // A second sale of 3 exceeds the remaining 2. The >= guard refuses the decrement and floors to 0
        // — it must NOT go to -1 (which the old absolute write would have done).
        $product['current_stock'] = 2;   // stale snapshot, exactly the concurrency hazard
        $pos->getProductStockCalculate(['variant' => null, 'quantity' => 3], $product, 999);
        $this->assertSame(0, $this->stock(), 'stock must floor at zero, never go negative');
    }
}
