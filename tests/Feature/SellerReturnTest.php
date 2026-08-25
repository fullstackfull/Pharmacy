<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ReturnShipment;
use App\Models\Seller;
use App\Models\StockMovement;
use App\Services\Marketplace\ReturnLogisticsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The goods coming back.
 *
 * A refund has always been visible to sellers as money. The units were not visible at all: nothing
 * recorded that a physical product was on its way back, so nothing restocked it, and a seller who
 * refunded a customer quietly lost the stock as well as the sale.
 *
 * What has to hold for opening this to sellers to be safe: a return is one shop's, receiving it puts
 * the units back through the same stock ledger a purchase receipt writes to rather than as an
 * unexplained jump, and a return already closed cannot be received a second time — which would
 * restock the same units twice.
 */
class SellerReturnTest extends TestCase
{
    private const OWNER_TOKEN = 'owner-token-long-enough-to-clear-the-length-gate';
    private const RIVAL_TOKEN = 'rival-token-long-enough-to-clear-the-length-gate';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'return_shipments', 'stock_movements', 'order_details', 'refund_requests', 'orders',
            'products', 'sellers', 'translations', 'vendor_ledger_entries', 'business_settings',
            'audit_logs',
        ] as $table) {
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
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->integer('qty')->default(1);
            $table->text('product_details')->nullable();
            $table->boolean('is_stock_decreased')->default(1);
            $table->timestamps();
        });
        // The refund observer that decides whether a webhook is owed reads the order behind it.
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is', 20)->default('seller');
            $table->timestamps();
        });
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('status', 30)->default('pending');
            $table->text('refund_reason')->nullable();
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
        Schema::create('return_shipments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('refund_request_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->integer('qty')->default(1);
            $table->string('reason')->nullable();
            $table->string('status', 30)->default('authorized');
            $table->boolean('restock')->default(true);
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_type', 30)->nullable();
            $table->timestamps();
        });
        Schema::create('vendor_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('seller_is', 20)->default('seller');
            $table->string('entry_type', 40);
            $table->decimal('debit', 24, 4)->default(0);
            $table->decimal('credit', 24, 4)->default(0);
            $table->decimal('balance_after', 24, 4)->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamp('available_at')->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('reference_type', 60)->nullable();
            $table->string('reference_id', 60)->nullable();
            $table->text('description')->nullable();
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

    private function rma(array $attributes = []): ReturnShipment
    {
        return ReturnShipment::create(array_merge([
            'reference' => 'RMA-202608-0001',
            'seller_id' => 1,
            'qty' => 2,
            'reason' => 'damaged',
            'status' => ReturnShipment::STATUS_AUTHORIZED,
            'restock' => true,
        ], $attributes));
    }

    /** @return array<string, string> */
    private function headers(string $token = self::OWNER_TOKEN): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    private function uri(string $path = ''): string
    {
        return rtrim('/api/v3/seller/seller-center/returns/' . ltrim($path, '/'), '/');
    }

    public function test_a_seller_sees_only_their_own_returns(): void
    {
        $mine = $this->rma(['reference' => 'RMA-MINE']);
        $this->rma(['seller_id' => 2, 'reference' => 'RMA-THEIRS']);

        $list = $this->withHeaders($this->headers())->getJson($this->uri())->json();

        $this->assertSame(1, $list['total_size']);
        $this->assertSame($mine->id, $list['returns'][0]['id']);
        $this->assertSame('RMA-MINE', $list['returns'][0]['reference']);
    }

    public function test_another_sellers_return_is_not_found_rather_than_actionable(): void
    {
        $theirs = $this->rma(['seller_id' => 2]);

        $this->withHeaders($this->headers())->getJson($this->uri((string) $theirs->id))->assertStatus(404);
        $this->withHeaders($this->headers())
            ->postJson($this->uri("{$theirs->id}/receive"), [])->assertStatus(404);
        $this->withHeaders($this->headers())
            ->postJson($this->uri("{$theirs->id}/reject"), ['reason' => 'no'])->assertStatus(404);

        $this->assertSame(ReturnShipment::STATUS_AUTHORIZED, $theirs->fresh()->status);
    }

    public function test_receiving_a_restockable_return_puts_the_units_back_through_the_ledger(): void
    {
        $product = $this->product(stock: 10);
        $rma = $this->rma(['product_id' => $product->id, 'qty' => 3, 'restock' => true]);

        $response = $this->withHeaders($this->headers())
            ->postJson($this->uri("{$rma->id}/receive"), ['restock' => true]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('restocked'));
        $this->assertSame(13, (int) $product->fresh()->current_stock);
        $this->assertSame(ReturnShipment::STATUS_RESTOCKED, $rma->fresh()->status);

        // Recorded in the same log a purchase receipt writes to, so a restocked return is explained
        // rather than appearing as a number that jumped on its own.
        $movement = StockMovement::first();
        $this->assertNotNull($movement, 'A restock left no trace in the stock ledger.');
        $this->assertSame(StockMovement::TYPE_RETURN, $movement->type);
        $this->assertSame(3, (int) $movement->qty_change);
        $this->assertSame(13, (int) $movement->balance_after);
        // Who actually received the goods — not the marketplace.
        $this->assertSame('seller', $movement->created_by_type);
    }

    public function test_goods_that_cannot_be_sold_again_are_received_without_restocking(): void
    {
        $product = $this->product(stock: 10);
        $rma = $this->rma(['product_id' => $product->id, 'qty' => 3, 'restock' => true]);

        // The decision belongs at receipt: nobody knows whether goods are sellable until they are
        // looked at.
        $response = $this->withHeaders($this->headers())
            ->postJson($this->uri("{$rma->id}/receive"), ['restock' => false]);

        $response->assertStatus(200);
        $this->assertFalse($response->json('restocked'));
        $this->assertSame(10, (int) $product->fresh()->current_stock);
        $this->assertSame(ReturnShipment::STATUS_RECEIVED, $rma->fresh()->status);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_a_return_cannot_be_received_twice(): void
    {
        $product = $this->product(stock: 10);
        $rma = $this->rma(['product_id' => $product->id, 'qty' => 3]);

        $this->withHeaders($this->headers())->postJson($this->uri("{$rma->id}/receive"), []);
        $second = $this->withHeaders($this->headers())->postJson($this->uri("{$rma->id}/receive"), []);

        // The second receipt would restock the same units again.
        $second->assertStatus(422);
        $this->assertSame(13, (int) $product->fresh()->current_stock);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_only_an_authorised_return_can_be_marked_in_transit(): void
    {
        $rma = $this->rma();

        $this->withHeaders($this->headers())
            ->postJson($this->uri("{$rma->id}/in-transit"), ['carrier' => 'DHL', 'tracking_number' => 'X1'])
            ->assertStatus(200);

        $this->assertSame(ReturnShipment::STATUS_IN_TRANSIT, $rma->fresh()->status);
        $this->assertSame('X1', $rma->fresh()->tracking_number);

        // Saying it a second time answers with what was wrong rather than silently doing nothing.
        $this->withHeaders($this->headers())
            ->postJson($this->uri("{$rma->id}/in-transit"), [])->assertStatus(422);
    }

    public function test_a_refusal_needs_grounds(): void
    {
        $rma = $this->rma();

        // A refusal a customer cannot be told the grounds for is not a decision anyone can act on.
        $this->withHeaders($this->headers())
            ->postJson($this->uri("{$rma->id}/reject"), [])->assertStatus(403);
        $this->assertSame(ReturnShipment::STATUS_AUTHORIZED, $rma->fresh()->status);

        $this->withHeaders($this->headers())
            ->postJson($this->uri("{$rma->id}/reject"), ['reason' => 'Not what we sent'])
            ->assertStatus(200);
        $this->assertSame(ReturnShipment::STATUS_REJECTED, $rma->fresh()->status);
        $this->assertSame('Not what we sent', $rma->fresh()->note);
    }

    public function test_the_return_shows_what_the_refund_did_to_the_balance(): void
    {
        $lineId = DB::table('order_details')->insertGetId([
            'order_id' => 1, 'product_id' => 1, 'seller_id' => 1, 'qty' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $rma = $this->rma(['order_details_id' => $lineId]);

        // Both rows carry the same keys: a batch insert takes its column list from the first row,
        // so a second row naming different keys silently loses them.
        DB::table('vendor_ledger_entries')->insert([
            ['seller_id' => 1, 'entry_type' => 'refund', 'debit' => 100, 'credit' => 0, 'balance_after' => -100,
                'reference_type' => 'order_details', 'reference_id' => $lineId,
                'description' => 'Refund', 'created_at' => now(), 'updated_at' => now()],
            ['seller_id' => 1, 'entry_type' => 'commission_charge', 'debit' => 0, 'credit' => 12, 'balance_after' => -88,
                'reference_type' => 'order_details', 'reference_id' => $lineId,
                'description' => 'Commission reversal', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $ledger = $this->withHeaders($this->headers())->getJson($this->uri((string) $rma->id))->json('ledger');

        $this->assertCount(2, $ledger);
        $this->assertEquals(100.0, $ledger[0]['debit']);
        // The line sellers never see: the marketplace giving back its cut of a sale it gave back.
        $this->assertSame('commission_charge', $ledger[1]['entry_type']);
        $this->assertEquals(12.0, $ledger[1]['credit']);
    }

    public function test_the_list_can_be_narrowed_by_status(): void
    {
        $this->rma(['reference' => 'A', 'status' => ReturnShipment::STATUS_AUTHORIZED]);
        $this->rma(['reference' => 'B', 'status' => ReturnShipment::STATUS_REJECTED]);

        $list = $this->withHeaders($this->headers())->getJson($this->uri() . '?status=rejected')->json();

        $this->assertSame(1, $list['total_size']);
        $this->assertSame('B', $list['returns'][0]['reference']);
    }

    public function test_opening_a_return_for_a_refund_happens_once(): void
    {
        $returns = app(ReturnLogisticsService::class);
        $data = ['order_id' => 1, 'order_details_id' => 5, 'product_id' => 7, 'seller_id' => 1, 'qty' => 2];

        $first = $returns->authorizeForRefund(refundRequestId: 42, data: $data);
        $second = $returns->authorizeForRefund(refundRequestId: 42, data: $data);

        // Approving twice — or an admin having opened one already — must not create a second return
        // for the same goods, which would restock them twice on receipt.
        $this->assertNotNull($first);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ReturnShipment::count());
        $this->assertSame(42, (int) $first->refund_request_id);
    }

    public function test_the_same_refund_opens_the_same_return_whichever_surface_approved_it(): void
    {
        // The panel and the app take the same decision, so it must have the same consequence. It
        // did not: the app opened the return and the panel did not, so a refund approved at a desk
        // gave the customer their money and quietly lost the seller their units.
        $returns = app(ReturnLogisticsService::class);

        $line = new \App\Models\OrderDetail();
        $line->forceFill([
            'order_id' => 1, 'product_id' => 7, 'seller_id' => 1, 'qty' => 2,
            'product_details' => json_encode(['product_type' => 'physical']),
        ])->save();

        $refund = new \App\Models\RefundRequest();
        $refund->forceFill([
            'order_id' => 1, 'order_details_id' => $line->id, 'customer_id' => 3,
            'product_id' => 7, 'status' => 'approved', 'refund_reason' => 'Damaged',
        ])->save();

        $fromTheApp = $returns->openForApprovedRefund($refund, $line, sellerId: 1);
        $fromThePanel = $returns->openForApprovedRefund($refund, $line, sellerId: 1);

        $this->assertNotNull($fromTheApp);
        $this->assertSame($fromTheApp->id, $fromThePanel->id, 'the second approval must not open a second return');
        $this->assertSame(1, ReturnShipment::where('refund_request_id', $refund->id)->count());
        $this->assertSame(2, (int) $fromTheApp->qty);
    }

    public function test_a_digital_product_never_opens_a_return(): void
    {
        $line = new \App\Models\OrderDetail();
        $line->forceFill([
            'order_id' => 2, 'product_id' => 9, 'seller_id' => 1, 'qty' => 1,
            'product_details' => json_encode(['product_type' => 'digital']),
        ])->save();

        $refund = new \App\Models\RefundRequest();
        $refund->forceFill(['order_id' => 2, 'order_details_id' => $line->id, 'status' => 'approved'])->save();

        // Nothing is coming back, and an RMA for it would be a return that can never be received.
        $this->assertNull(app(ReturnLogisticsService::class)->openForApprovedRefund($refund, $line, sellerId: 1));
        $this->assertSame(0, ReturnShipment::where('refund_request_id', $refund->id)->count());
    }

    public function test_a_refund_that_is_not_there_is_not_a_crash(): void
    {
        $returns = app(ReturnLogisticsService::class);

        // Both callers hold these as nullable models, and this must never be the thing that fails
        // a refund the customer is already owed.
        $this->assertNull($returns->openForApprovedRefund(null, null, sellerId: 1));
        $this->assertNull($returns->openForApprovedRefund(new \App\Models\RefundRequest(), null, sellerId: 1));
    }

    public function test_none_of_it_is_reachable_without_a_credential(): void
    {
        $rma = $this->rma();

        $this->getJson($this->uri())->assertStatus(401);
        $this->postJson($this->uri("{$rma->id}/receive"), [])->assertStatus(401);
    }
}
