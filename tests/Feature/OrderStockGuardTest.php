<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Refusing to sell stock that is not there.
 *
 * v2.4 made the decrement atomic, which stopped the arithmetic being wrong but did nothing to stop
 * stock going negative — nothing refused the write. The guard here is the `where('current_stock',
 * '>=', $qty)` clause: the database itself declines to take stock it does not have, and an
 * affected-row count of zero is the refusal.
 *
 * These tests cover the mechanism on SQLite. The behaviour that actually matters — two simultaneous
 * checkouts for the last unit — was verified separately against the real MariaDB with two parallel
 * processes and a shared start barrier, five consecutive races, each ending with exactly one order
 * and stock at 0. That evidence is recorded in docs/audit/phase-2-order-transaction.md; it cannot
 * live here because SQLite serialises writers and so cannot reproduce the race at all.
 */
class OrderStockGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('products');
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('product_type')->default('physical');
            $t->integer('current_stock')->default(0);
            $t->timestamps();
        });
        foreach (['translations' => function (Blueprint $t) {
            $t->id(); $t->string('translationable_type'); $t->unsignedBigInteger('translationable_id');
            $t->string('locale', 20); $t->string('key'); $t->text('value')->nullable();
        }, 'reviews' => function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('delivery_man_id')->nullable();
            $t->integer('rating')->default(0); $t->boolean('status')->default(1); $t->timestamps();
        }, 'business_settings' => function (Blueprint $t) {
            $t->id(); $t->string('type')->nullable(); $t->text('value')->nullable(); $t->timestamps();
        }] as $table => $definition) {
            if (!Schema::hasTable($table)) {
                Schema::create($table, $definition);
            }
        }
    }

    /** The conditional decrement as `addOrderDetailsData()` issues it. */
    private function takeStock(int $productId, int $quantity): int
    {
        return Product::where(['id' => $productId])
            ->where('current_stock', '>=', $quantity)
            ->decrement('current_stock', $quantity);
    }

    public function test_the_last_unit_can_be_taken_once_and_only_once(): void
    {
        $product = Product::create(['name' => 'Serum', 'current_stock' => 1]);

        $this->assertSame(1, $this->takeStock($product->id, 1), 'the first order takes it');
        $this->assertSame(0, $this->takeStock($product->id, 1), 'the second is refused by the database');
        $this->assertSame(0, Product::find($product->id)->current_stock, 'and stock never goes negative');
    }

    public function test_an_order_larger_than_stock_is_refused_outright(): void
    {
        $product = Product::create(['name' => 'Serum', 'current_stock' => 3]);

        $this->assertSame(0, $this->takeStock($product->id, 5));
        $this->assertSame(3, Product::find($product->id)->current_stock, 'a refused order takes nothing at all');
    }

    public function test_an_order_exactly_matching_stock_is_allowed(): void
    {
        $product = Product::create(['name' => 'Serum', 'current_stock' => 4]);

        $this->assertSame(1, $this->takeStock($product->id, 4), '>= must not be >');
        $this->assertSame(0, Product::find($product->id)->current_stock);
    }

    /**
     * The exception carries the product so the customer can be told which item ran out rather than
     * receiving a generic apology.
     */
    public function test_the_exception_names_the_product_that_ran_out(): void
    {
        $exception = new InsufficientStockException(productId: 42, productName: 'Vitamin C Serum', requested: 3);

        $this->assertSame(42, $exception->productId);
        $this->assertSame('Vitamin C Serum', $exception->productName);
        $this->assertSame(3, $exception->requested);
        $this->assertStringContainsString('Vitamin C Serum', $exception->getMessage());
        $this->assertStringContainsString('3', $exception->getMessage());
    }

    /**
     * The whole point of the transaction: a multi-item order that fails on its second line must not
     * leave the first line's stock taken. Verified end to end against MariaDB through the real
     * generateOrder() — this asserts the same property at the transaction level.
     */
    public function test_a_failed_line_rolls_back_the_line_that_already_succeeded(): void
    {
        $first = Product::create(['name' => 'In stock', 'current_stock' => 10]);
        $second = Product::create(['name' => 'Nearly gone', 'current_stock' => 2]);

        try {
            DB::transaction(function () use ($first, $second) {
                $this->assertSame(1, $this->takeStock($first->id, 1), 'the first line succeeds');

                if ($this->takeStock($second->id, 5) === 0) {
                    throw new InsufficientStockException(productId: $second->id, requested: 5);
                }
            });
            $this->fail('the transaction should not have committed');
        } catch (InsufficientStockException) {
            // expected
        }

        $this->assertSame(10, Product::find($first->id)->current_stock, 'the successful line was rolled back too');
        $this->assertSame(2, Product::find($second->id)->current_stock);
    }

    /**
     * After a gateway has taken the money, refusing the order would leave the customer paid with
     * nothing to show for it — strictly worse than an oversold line a human can reconcile against a
     * real payment record. That path keeps the unconditional decrement, and stock may go negative.
     */
    public function test_the_post_payment_path_still_takes_stock_it_does_not_have(): void
    {
        $product = Product::create(['name' => 'Serum', 'current_stock' => 1]);

        Product::where(['id' => $product->id])->decrement('current_stock', 3);

        $this->assertSame(-2, Product::find($product->id)->current_stock, 'visible as negative stock, by design');
    }
}
