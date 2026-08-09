<?php

namespace Tests\Feature;

use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Services\Marketplace\BatchService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Batch & expiry tracking (Phase 3, Stage C).
 *
 * Batches are a tracking overlay: recording one does not touch sellable stock. The one action that
 * does — writing off an expired batch — goes through the inventory adjustment path, so it reduces
 * `current_stock` AND leaves an `expiry` movement, and is refused (leaving the batch intact) when it
 * would drive stock negative.
 */
class BatchExpiryTest extends TestCase
{
    private BatchService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['product_batches', 'stock_movements', 'products'] as $t) {
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
        Schema::create('product_batches', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('seller_id')->nullable();
            $t->string('batch_number')->nullable(); $t->date('expiry_date')->nullable();
            $t->unsignedInteger('quantity')->default(0); $t->unsignedInteger('initial_quantity')->default(0);
            $t->string('status')->default('active'); $t->string('reference_type')->nullable(); $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('note')->nullable(); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
        });

        $this->svc = new BatchService();
    }

    private function product(int $stock = 100): int
    {
        return DB::table('products')->insertGetId(['current_stock' => $stock]);
    }

    public function test_adding_a_batch_records_it_without_touching_stock(): void
    {
        $id = $this->product(100);
        $batch = $this->svc->addBatch($id, 'LOT-1', Carbon::now()->addMonths(6)->toDateString(), 40);

        $this->assertSame(40, $batch->quantity);
        $this->assertSame(40, $batch->initial_quantity);
        $this->assertSame(100, (int) DB::table('products')->where('id', $id)->value('current_stock'), 'stock is the source of truth, untouched');
    }

    public function test_expiring_soon_finds_near_and_past_expiry_but_not_far_future(): void
    {
        $id = $this->product();
        $this->svc->addBatch($id, 'SOON', Carbon::now()->addDays(10)->toDateString(), 5);
        $this->svc->addBatch($id, 'PAST', Carbon::now()->subDays(2)->toDateString(), 5);
        $this->svc->addBatch($id, 'FAR', Carbon::now()->addMonths(8)->toDateString(), 5);

        $soon = $this->svc->expiringSoon(30);

        $this->assertEqualsCanonicalizing(['SOON', 'PAST'], $soon->pluck('batch_number')->all());
    }

    public function test_expired_finds_only_past_expiry(): void
    {
        $id = $this->product();
        $this->svc->addBatch($id, 'PAST', Carbon::now()->subDay()->toDateString(), 5);
        $this->svc->addBatch($id, 'FUTURE', Carbon::now()->addDay()->toDateString(), 5);

        $expired = $this->svc->expired();

        $this->assertSame(['PAST'], $expired->pluck('batch_number')->all());
    }

    public function test_writing_off_a_batch_reduces_stock_and_logs_an_expiry_movement(): void
    {
        $id = $this->product(100);
        $batch = $this->svc->addBatch($id, 'LOT-9', Carbon::now()->subDay()->toDateString(), 30);

        $res = $this->svc->writeOff($batch);

        $this->assertTrue($res['ok']);
        $this->assertSame(70, (int) DB::table('products')->where('id', $id)->value('current_stock'));
        $this->assertSame('depleted', $batch->fresh()->status);
        $this->assertSame(0, $batch->fresh()->quantity);

        $m = StockMovement::where('product_id', $id)->first();
        $this->assertSame('adjustment', $m->type);
        $this->assertSame(-30, $m->qty_change);
        $this->assertSame('expiry', $m->reason);
    }

    public function test_a_write_off_that_would_make_stock_negative_is_refused_and_leaves_the_batch(): void
    {
        $id = $this->product(10);
        $batch = $this->svc->addBatch($id, 'BIG', Carbon::now()->subDay()->toDateString(), 30); // more than on hand

        $res = $this->svc->writeOff($batch);

        $this->assertFalse($res['ok']);
        $this->assertSame('adjustment_would_make_stock_negative', $res['reason']);
        $this->assertSame(10, (int) DB::table('products')->where('id', $id)->value('current_stock'), 'stock untouched');
        $this->assertSame('active', $batch->fresh()->status, 'batch left intact to try again after a recount');
    }

    public function test_an_already_depleted_batch_cannot_be_written_off_again(): void
    {
        $id = $this->product(100);
        $batch = $this->svc->addBatch($id, 'ONCE', Carbon::now()->subDay()->toDateString(), 5);
        $this->svc->writeOff($batch);

        $res = $this->svc->writeOff($batch->fresh());
        $this->assertFalse($res['ok']);
        $this->assertSame('batch_is_not_active', $res['reason']);
    }

    public function test_is_expired_is_derived_from_the_date(): void
    {
        $id = $this->product();
        $past = $this->svc->addBatch($id, 'P', Carbon::now()->subDay()->toDateString(), 1);
        $future = $this->svc->addBatch($id, 'F', Carbon::now()->addDay()->toDateString(), 1);

        $this->assertTrue($past->isExpired());
        $this->assertFalse($future->isExpired());
    }
}
