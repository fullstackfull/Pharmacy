<?php

namespace Tests\Feature;

use App\Models\StockMovement;
use App\Repositories\ProductRepository;
use App\Services\Marketplace\InventoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The quick stock box, and the trail it never left.
 *
 * Both product lists — admin and vendor — and both v3 seller endpoints wrote `current_stock`
 * straight through the query builder: no reason, no movement row, no audit line. That is a second
 * stock-writing path that disagrees with the first about whether a change is traceable, and two such
 * paths do not stay consistent: they drive `current_stock` and the movement ledger apart, and the
 * trail can then say the shelf moved but never why.
 *
 * The second half of the same problem is the builder update underneath it. A builder update fires no
 * model events, so a price written through that repository method would skip the price-change
 * history, the audit row and the seller-visible price history in one step, on a path nobody would
 * think to check.
 */
class StockEditTrailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['products', 'stock_movements', 'audit_logs'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('added_by')->default('seller');
            $t->integer('current_stock')->default(0);
            $t->text('variation')->nullable();
            $t->decimal('unit_price', 24, 2)->default(0);
            $t->decimal('discount', 24, 2)->default(0);
            $t->string('discount_type')->nullable();
            $t->timestamps();
        });

        // The price observer chain reads shop settings on its way to the history table; without
        // these the observed-column test fails on a missing table rather than on the behaviour.
        foreach (['business_settings', 'settings'] as $settingsTable) {
            Schema::dropIfExists($settingsTable);
            Schema::create($settingsTable, function (Blueprint $t) {
                $t->id();
                $t->string('type')->nullable();
                $t->string('key')->nullable();
                $t->text('value')->nullable();
                $t->timestamps();
            });
        }

        // The Product model carries a global scope that eager-loads its translations and reviews, so
        // hydrating one — which the observed-column path must do — needs both tables to exist.
        Schema::dropIfExists('translations');
        Schema::create('translations', function (Blueprint $t) {
            $t->id();
            $t->string('translationable_type');
            $t->unsignedBigInteger('translationable_id');
            $t->string('locale');
            $t->string('key');
            $t->text('value')->nullable();
            $t->timestamps();
        });

        Schema::dropIfExists('reviews');
        Schema::create('reviews', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('delivery_man_id')->nullable();
            $t->unsignedTinyInteger('status')->default(1);
            $t->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('seller_id')->nullable();
            $t->string('type');
            $t->integer('qty_change');
            $t->integer('balance_after')->nullable();
            $t->string('reason')->nullable();
            $t->string('reference_type')->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->text('note')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('created_by_type')->nullable();
            $t->timestamps();
        });
    }

    private function product(int $stock = 10, int $sellerId = 3): int
    {
        return DB::table('products')->insertGetId([
            'user_id' => $sellerId,
            'added_by' => 'seller',
            'current_stock' => $stock,
        ]);
    }

    public function test_setting_stock_records_the_movement_that_explains_it(): void
    {
        $id = $this->product(10);

        $result = app(InventoryService::class)->setStock(
            productId: $id,
            newStock: 4,
            reason: 'manual_stock_edit',
            by: 3,
            byType: 'seller',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(-6, $result['delta']);
        $this->assertSame(4, (int) DB::table('products')->where('id', $id)->value('current_stock'));

        $movement = StockMovement::first();
        $this->assertNotNull($movement, 'a stock change with no movement row is a change the trail cannot explain');
        $this->assertSame(-6, (int) $movement->qty_change);
        $this->assertSame(4, (int) $movement->balance_after);
        $this->assertSame('manual_stock_edit', $movement->reason);
        $this->assertSame(3, (int) $movement->seller_id);
    }

    /** The delta is derived under the lock, so a concurrent adjustment cannot make the line wrong. */
    public function test_the_movement_is_the_difference_not_the_number_that_was_typed(): void
    {
        $id = $this->product(2);

        app(InventoryService::class)->setStock(productId: $id, newStock: 9, reason: 'manual_stock_edit');

        $this->assertSame(7, (int) StockMovement::first()->qty_change);
    }

    /** A recount that found what was expected is not a movement; inventing one would be a lie. */
    public function test_a_recount_that_changed_nothing_writes_no_movement(): void
    {
        $id = $this->product(10);

        $result = app(InventoryService::class)->setStock(productId: $id, newStock: 10, reason: 'manual_stock_edit');

        $this->assertTrue($result['ok']);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_the_variation_blob_moves_in_the_same_transaction_as_the_stock(): void
    {
        $id = $this->product(10);

        app(InventoryService::class)->setStock(
            productId: $id,
            newStock: 5,
            reason: 'manual_stock_edit',
            alongside: ['variation' => '[{"type":"red","qty":5}]'],
        );

        $row = DB::table('products')->where('id', $id)->first();
        $this->assertSame(5, (int) $row->current_stock);
        $this->assertStringContainsString('red', (string) $row->variation);
    }

    /** A seller reaching another shop's product must find nothing, not another shop's stock. */
    public function test_an_owner_scope_is_enforced_inside_the_lock(): void
    {
        $id = $this->product(10, sellerId: 3);

        $result = app(InventoryService::class)->setStock(
            productId: $id,
            newStock: 0,
            reason: 'manual_stock_edit',
            scope: ['user_id' => 99, 'added_by' => 'seller'],
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('product_not_found', $result['reason']);
        $this->assertSame(10, (int) DB::table('products')->where('id', $id)->value('current_stock'));
    }

    public function test_stock_cannot_be_set_below_zero(): void
    {
        $id = $this->product(10);

        $result = app(InventoryService::class)->setStock(productId: $id, newStock: -1, reason: 'manual_stock_edit');

        $this->assertFalse($result['ok']);
        $this->assertSame(10, (int) DB::table('products')->where('id', $id)->value('current_stock'));
    }

    // ─────────────────────────────────────────────── the builder hole underneath

    /**
     * A price written through the repository's mass update must still reach the observer, because
     * skipping it drops the price history, the audit row and the seller-visible history at once.
     */
    public function test_a_price_written_through_the_mass_update_still_fires_model_events(): void
    {
        $id = $this->product(10);
        $seen = [];

        \App\Models\Product::updated(function ($product) use (&$seen) {
            $seen[] = (float) $product->unit_price;
        });

        app(ProductRepository::class)->updateByParams(params: ['id' => $id], data: ['unit_price' => 42.5]);

        $this->assertSame([42.5], $seen, 'a price change through the builder path would never reach the price observer');
    }

    /** Stock-only writes keep the fast path: nothing observes them and a bulk write should not pay for it. */
    public function test_a_stock_only_mass_update_stays_on_the_builder_path(): void
    {
        $id = $this->product(10);
        $seen = 0;

        \App\Models\Product::updated(function () use (&$seen) {
            $seen++;
        });

        app(ProductRepository::class)->updateByParams(params: ['id' => $id], data: ['current_stock' => 3]);

        $this->assertSame(0, $seen);
        $this->assertSame(3, (int) DB::table('products')->where('id', $id)->value('current_stock'));
    }
}
