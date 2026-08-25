<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Seller;
use App\Models\StockMovement;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The seller's own stock, and the history behind it.
 *
 * The marketplace has kept a stock ledger since Phase 3 and no seller has ever been able to see it,
 * so from the app a stock level was a number that changed for no stated reason. Two things have to
 * hold for showing it to be an improvement rather than a new way to leak data:
 *
 * The log is one shop's. A movement log is a record of what a business bought, sold and lost, and
 * scoping it by anything other than the WHERE clause would hand one seller their rival's operations.
 *
 * And a correction goes through the ledger rather than around it: locked, refused below zero, and
 * recorded with a reason. An adjustment with no reason is not offered at all — a ledger of
 * unexplained corrections is barely better than none.
 */
class SellerInventoryTest extends TestCase
{
    private const OWNER_TOKEN = 'owner-token-long-enough-to-clear-the-length-gate';
    private const RIVAL_TOKEN = 'rival-token-long-enough-to-clear-the-length-gate';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['stock_movements', 'products', 'sellers', 'translations', 'warehouses', 'product_batches', 'business_settings', 'audit_logs'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type')->nullable();
            $table->unsignedBigInteger('translationable_id')->nullable();
            $table->string('locale')->nullable();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('status', 20)->default('approved');
            $table->string('auth_token')->nullable();
            // The seller's own reorder level, which decides what "running low" means for them.
            $table->integer('stock_limit')->nullable();
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('added_by', 20)->default('seller');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name')->nullable();
            $table->decimal('unit_price', 24, 3)->default(0);
            $table->integer('current_stock')->default(0);
            $table->timestamps();
        });
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('type', 40)->nullable();
            $table->integer('qty_change')->default(0);
            $table->integer('balance_after')->nullable();
            $table->string('reason')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_type', 30)->nullable();
            $table->timestamps();
        });
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Seller::insert([
            ['id' => 1, 'f_name' => 'Owner', 'l_name' => 'One', 'status' => 'approved', 'auth_token' => self::OWNER_TOKEN],
            ['id' => 2, 'f_name' => 'Rival', 'l_name' => 'Two', 'status' => 'approved', 'auth_token' => self::RIVAL_TOKEN],
        ]);
    }

    private function product(int $sellerId = 1, int $stock = 10): Product
    {
        return Product::forceCreate([
            'added_by' => 'seller', 'user_id' => $sellerId, 'name' => 'Widget',
            'unit_price' => 100, 'current_stock' => $stock,
        ]);
    }

    /** @return array<string, string> */
    private function headers(string $token = self::OWNER_TOKEN): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    private function uri(string $path): string
    {
        return '/api/v3/seller/seller-center/inventory/' . ltrim($path, '/');
    }

    public function test_a_correction_is_written_to_the_ledger_with_its_reason(): void
    {
        $product = $this->product(stock: 10);

        $response = $this->withHeaders($this->headers())->postJson(
            $this->uri("products/{$product->id}/adjust"),
            ['delta' => -3, 'reason' => 'damage', 'note' => 'Broken in transit'],
        );

        $response->assertStatus(200);
        $this->assertSame(7, $response->json('balance_after'));
        $this->assertSame(7, (int) $product->fresh()->current_stock);

        $movement = StockMovement::first();
        $this->assertNotNull($movement, 'A correction left no trace in the ledger.');
        $this->assertSame(-3, (int) $movement->qty_change);
        // The balance the change left behind, so the log can be read without replaying it.
        $this->assertSame(7, (int) $movement->balance_after);
        $this->assertSame('damage', $movement->reason);
        $this->assertSame('Broken in transit', $movement->note);
        $this->assertSame('seller', $movement->created_by_type);
        $this->assertSame(1, (int) $movement->seller_id);
    }

    public function test_a_correction_cannot_drive_the_balance_below_zero(): void
    {
        $product = $this->product(stock: 3);

        $response = $this->withHeaders($this->headers())->postJson(
            $this->uri("products/{$product->id}/adjust"),
            ['delta' => -10, 'reason' => 'loss'],
        );

        // 422, not a silent failure: the client can say why rather than showing an unchanged number.
        $response->assertStatus(422);
        $this->assertSame(3, (int) $product->fresh()->current_stock);
        $this->assertSame(0, StockMovement::count(), 'A refused correction still wrote to the ledger.');
    }

    public function test_a_correction_without_a_reason_is_refused(): void
    {
        $product = $this->product();

        // A ledger of unexplained corrections is barely better than no ledger.
        $this->withHeaders($this->headers())
            ->postJson($this->uri("products/{$product->id}/adjust"), ['delta' => 5])
            ->assertStatus(403);

        $this->assertSame(10, (int) $product->fresh()->current_stock);
    }

    public function test_a_reason_outside_the_catalogue_is_refused(): void
    {
        $product = $this->product();

        $this->withHeaders($this->headers())
            ->postJson($this->uri("products/{$product->id}/adjust"), ['delta' => 5, 'reason' => 'because'])
            ->assertStatus(403);
    }

    public function test_a_correction_of_nothing_is_refused(): void
    {
        $product = $this->product();

        // Zero is not a correction; recording one would put a line in the ledger saying nothing.
        $this->withHeaders($this->headers())
            ->postJson($this->uri("products/{$product->id}/adjust"), ['delta' => 0, 'reason' => 'count_correction'])
            ->assertStatus(403);
    }

    public function test_another_sellers_stock_cannot_be_corrected(): void
    {
        $theirs = $this->product(sellerId: 2, stock: 50);

        $this->withHeaders($this->headers())
            ->postJson($this->uri("products/{$theirs->id}/adjust"), ['delta' => -50, 'reason' => 'loss'])
            ->assertStatus(404);

        // Untouched, and answered as not found rather than refused — so the endpoint cannot be used
        // to learn which product ids belong to somebody else.
        $this->assertSame(50, (int) $theirs->fresh()->current_stock);
    }

    public function test_the_movement_log_is_one_shops(): void
    {
        $mine = $this->product(stock: 10);
        $theirs = $this->product(sellerId: 2, stock: 10);

        $this->withHeaders($this->headers())
            ->postJson($this->uri("products/{$mine->id}/adjust"), ['delta' => -1, 'reason' => 'damage']);
        $this->withHeaders($this->headers(self::RIVAL_TOKEN))
            ->postJson($this->uri("products/{$theirs->id}/adjust"), ['delta' => -7, 'reason' => 'theft']);

        $log = $this->withHeaders($this->headers())->getJson($this->uri('movements'))->json();

        // A movement log is a record of what a business bought, sold and lost.
        $this->assertSame(1, $log['total_size']);
        $this->assertSame($mine->id, $log['movements'][0]['product_id']);
        $this->assertSame(-1, $log['movements'][0]['qty_change']);
    }

    public function test_filtering_by_a_product_that_is_not_theirs_is_not_found_rather_than_ignored(): void
    {
        $this->product();
        $theirs = $this->product(sellerId: 2);

        // Silently dropping the filter would widen the answer to the whole shop, which reads as if
        // the rival's product had no movements rather than as if the question was not allowed.
        $this->withHeaders($this->headers())
            ->getJson($this->uri('movements') . '?product_id=' . $theirs->id)
            ->assertStatus(404);
    }

    public function test_the_log_can_be_narrowed_to_one_product(): void
    {
        $first = $this->product(stock: 10);
        $second = $this->product(stock: 10);

        foreach ([$first, $second] as $product) {
            $this->withHeaders($this->headers())
                ->postJson($this->uri("products/{$product->id}/adjust"), ['delta' => -1, 'reason' => 'damage']);
        }

        $log = $this->withHeaders($this->headers())
            ->getJson($this->uri('movements') . '?product_id=' . $first->id)->json();

        $this->assertSame(1, $log['total_size']);
        $this->assertSame($first->id, $log['movements'][0]['product_id']);
    }

    public function test_the_overview_counts_what_is_actually_there(): void
    {
        // Stated rather than assumed: "running low" is the seller's reorder level, so a test about
        // counting has to say which level it is counting against.
        Seller::where('id', 1)->update(['stock_limit' => 5]);

        $this->product(stock: 0);
        $this->product(stock: 3);
        $this->product(stock: 100);
        $this->product(sellerId: 2, stock: 999);

        $overview = $this->withHeaders($this->headers())->getJson($this->uri('overview'))->json();

        $this->assertSame(3, $overview['products'], "Another seller's catalogue was counted.");
        $this->assertSame(1, $overview['out_of_stock']);
        $this->assertSame(1, $overview['running_low']);
        $this->assertSame(103, $overview['units_on_hand']);
        $this->assertSame(StockMovement::REASONS, $overview['reasons']);
    }

    public function test_running_low_means_the_sellers_own_reorder_level_not_a_number_this_endpoint_invented(): void
    {
        // This endpoint used to call anything at or under five units low, while the panel, the
        // reports, the low-stock detector and the stock webhook all read the seller's configured
        // reorder level. A seller whose level was twenty was told one number on their phone and a
        // different one at their desk, about the same shelf.
        Seller::where('id', 1)->update(['stock_limit' => 20]);

        $this->product(stock: 3);
        $this->product(stock: 15);
        $this->product(stock: 100);

        $overview = $this->withHeaders($this->headers())->getJson($this->uri('overview'))->json();

        $this->assertSame(20, $overview['low_stock_threshold']);
        $this->assertSame(2, $overview['running_low'], 'the 15-unit product is low at a reorder level of 20');
    }

    public function test_a_seller_with_no_reorder_level_of_their_own_falls_back_to_the_marketplaces(): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'stock_limit'], ['value' => '7']);

        $this->product(stock: 6);
        $this->product(stock: 9);

        $overview = $this->withHeaders($this->headers())->getJson($this->uri('overview'))->json();

        $this->assertSame(7, $overview['low_stock_threshold']);
        $this->assertSame(1, $overview['running_low']);
    }

    public function test_a_seller_with_no_warehouses_is_told_there_are_none(): void
    {
        $this->product();

        $overview = $this->withHeaders($this->headers())->getJson($this->uri('overview'))->json();

        // Architecturally present, operationally optional. A seller who has never been given a
        // warehouse should not be shown an empty warehouse screen implying they should have one.
        $this->assertFalse($overview['warehouses_enabled']);
        $this->assertFalse($overview['batches_enabled']);

        $this->assertSame([], $this->withHeaders($this->headers())->getJson($this->uri('warehouses'))->json('warehouses'));
    }

    public function test_warehouses_are_answered_once_the_seller_has_them(): void
    {
        $this->product();
        DB::table('warehouses')->insert([
            ['seller_id' => 1, 'name' => 'Damascus', 'code' => 'DAM', 'is_default' => true, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now()],
            ['seller_id' => 2, 'name' => 'Rival depot', 'code' => 'RIV', 'is_default' => true, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now()],
        ]);

        $overview = $this->withHeaders($this->headers())->getJson($this->uri('overview'))->json();
        $warehouses = $this->withHeaders($this->headers())->getJson($this->uri('warehouses'))->json('warehouses');

        $this->assertTrue($overview['warehouses_enabled']);
        $this->assertCount(1, $warehouses, "Another seller's warehouse was listed.");
        $this->assertSame('Damascus', $warehouses[0]['name']);
    }

    public function test_reading_the_log_needs_no_credential_beyond_signing_in(): void
    {
        $this->product();

        // Seeing your own stock history is not a privileged act; changing a balance is.
        $this->withHeaders($this->headers())->getJson($this->uri('movements'))->assertStatus(200);
        $this->withHeaders($this->headers())->getJson($this->uri('overview'))->assertStatus(200);
    }

    public function test_none_of_it_is_reachable_without_a_credential(): void
    {
        $product = $this->product();

        $this->getJson($this->uri('overview'))->assertStatus(401);
        $this->getJson($this->uri('movements'))->assertStatus(401);
        $this->postJson($this->uri("products/{$product->id}/adjust"), ['delta' => 1, 'reason' => 'found'])
            ->assertStatus(401);
    }
}
