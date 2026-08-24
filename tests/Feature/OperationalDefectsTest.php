<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPriceChange;
use App\Models\Seller;
use App\Models\SellerNotificationDelivery;
use App\Services\Marketplace\PriceChangeRecorder;
use App\Services\SellerIntelligence\SellerNotifier;
use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The four defects the Phase 3 audit found, each pinned by the behaviour that was wrong.
 *
 * These are not features. They are things the platform was already doing incorrectly, found by
 * reading the code rather than by anyone reporting them — which is the reason they lasted.
 */
class OperationalDefectsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'product_price_changes', 'order_item_commissions', 'orders', 'products', 'sellers',
            'translations', 'seller_notification_deliveries', 'seller_insights', 'business_settings',
            'audit_logs', 'failed_jobs',
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
            $table->string('cm_firebase_token')->nullable();
            $table->string('cm_firebase_token_web')->nullable();
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('added_by', 20)->default('seller');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name')->nullable();
            $table->decimal('unit_price', 24, 3)->default(0);
            $table->decimal('discount', 24, 3)->default(0);
            $table->string('discount_type', 20)->default('flat');
            $table->integer('current_stock')->default(0);
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->decimal('order_amount', 24, 3)->default(0);
            $table->decimal('admin_commission', 24, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('order_item_commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->decimal('commission_amount', 24, 3)->default(0);
            $table->decimal('seller_net_amount', 24, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('product_price_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->decimal('previous_price', 24, 3)->nullable();
            $table->decimal('new_price', 24, 3);
            $table->decimal('previous_discount', 24, 3)->nullable();
            $table->decimal('new_discount', 24, 3)->nullable();
            $table->string('previous_discount_type', 20)->nullable();
            $table->string('new_discount_type', 20)->nullable();
            $table->string('source', 30)->default('seller_ui');
            $table->string('reason', 191)->nullable();
            $table->string('actor_type', 30)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 191)->nullable();
            $table->timestamps();
        });
        Schema::create('seller_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('topic', 60);
            $table->string('severity', 20)->default('medium');
            $table->string('title', 191);
            $table->text('body')->nullable();
            $table->unsignedInteger('subject_count')->default(1);
            $table->string('action_key', 60)->nullable();
            $table->text('action_params')->nullable();
            $table->string('digest_key', 191);
            $table->string('status', 20)->default('queued');
            $table->text('channels')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique('digest_key');
        });

        Seller::insert([['id' => 1, 'f_name' => 'Owner', 'l_name' => 'One', 'status' => 'approved']]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::forceCreate(array_merge([
            'added_by' => 'seller', 'user_id' => 1, 'name' => 'Widget',
            'unit_price' => 100, 'discount' => 0, 'discount_type' => 'flat', 'current_stock' => 10,
        ], $attributes));
    }

    // ---------------------------------------------------------------- defect 1

    public function test_the_failed_jobs_table_the_health_checks_read_now_exists(): void
    {
        // Four consumers query this table and no migration created it, so a job that exhausted its
        // retries left no row and every check reading it was blind. Asserted against the real
        // schema rather than the test's own, because the migration is the fix.
        $this->assertTrue(
            in_array('2026_09_10_000002_create_failed_jobs_table', $this->migrationNames(), true),
            'The failed_jobs migration is missing again.',
        );
    }

    // ---------------------------------------------------------------- defect 2

    public function test_the_orders_commission_becomes_the_sum_of_its_lines(): void
    {
        DB::table('orders')->insert(['id' => 90, 'seller_id' => 1, 'order_amount' => 1000, 'admin_commission' => 250]);
        DB::table('order_item_commissions')->insert([
            ['order_id' => 90, 'order_details_id' => 1, 'seller_id' => 1, 'commission_amount' => 60, 'seller_net_amount' => 440, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 90, 'order_details_id' => 2, 'seller_id' => 1, 'commission_amount' => 25, 'seller_net_amount' => 475, 'created_at' => now(), 'updated_at' => now()],
        ]);

        OrderManager::syncOrderCommissionFromLines(90);

        // 250 was computed before the order existed, from a flat percentage of the basket. 85 is
        // what the commission rules actually charged, line by line. `order_transactions` derives
        // what the seller is paid from this column, so the two disagreeing meant the seller could be
        // paid a different figure from the one the rules say they are owed.
        $this->assertEquals(85, DB::table('orders')->where('id', 90)->value('admin_commission'));
    }

    public function test_a_missing_snapshot_leaves_the_legacy_figure_alone(): void
    {
        DB::table('orders')->insert(['id' => 91, 'seller_id' => 1, 'order_amount' => 1000, 'admin_commission' => 250]);

        OrderManager::syncOrderCommissionFromLines(91);

        // No snapshot means the engine did not run — its call is deliberately non-fatal. Zeroing a
        // seller's commission because a logging path failed would be far worse than the
        // disagreement this fixes.
        $this->assertEquals(250, DB::table('orders')->where('id', 91)->value('admin_commission'));
    }

    // ---------------------------------------------------------------- defect 3

    public function test_a_price_change_is_recorded_whoever_makes_it(): void
    {
        $product = $this->product(['unit_price' => 100]);
        ProductPriceChange::query()->delete();

        $product->forceFill(['unit_price' => 130])->save();

        $change = ProductPriceChange::where('product_id', $product->id)->latest('id')->first();
        $this->assertNotNull($change, 'A price moved and left no trace.');
        $this->assertEquals(100, $change->previous_price);
        $this->assertEquals(130, $change->new_price);
        $this->assertSame(1, $change->seller_id);
        $this->assertEqualsWithDelta(30.0, $change->delta(), 0.001);
    }

    public function test_a_first_listing_is_distinguishable_from_a_change(): void
    {
        $product = $this->product(['unit_price' => 40]);

        $opening = ProductPriceChange::where('product_id', $product->id)->first();

        // "Listed at 40" and "changed to 40" are different facts, and the first time anyone asks what
        // a product has ever cost, only the null tells them apart.
        $this->assertNotNull($opening);
        $this->assertNull($opening->previous_price);
        $this->assertTrue($opening->isFirstPrice());
    }

    public function test_a_save_that_changes_nothing_records_nothing(): void
    {
        $product = $this->product(['unit_price' => 100]);
        ProductPriceChange::query()->delete();

        $product->forceFill(['name' => 'Renamed', 'unit_price' => 100])->save();

        // A history full of saves that changed no price is a history nobody can read.
        $this->assertSame(0, ProductPriceChange::count());
    }

    public function test_a_discount_change_alone_is_still_a_price_change(): void
    {
        $product = $this->product(['unit_price' => 100, 'discount' => 0]);
        ProductPriceChange::query()->delete();

        $product->forceFill(['discount' => 15, 'discount_type' => 'percent'])->save();

        $change = ProductPriceChange::latest('id')->first();
        $this->assertNotNull($change, 'What a customer pays changed and nothing recorded it.');
        $this->assertEquals(15, $change->new_discount);
        $this->assertSame('percent', $change->new_discount_type);
    }

    public function test_a_declared_source_survives_nesting(): void
    {
        $product = $this->product(['unit_price' => 100]);
        ProductPriceChange::query()->delete();

        PriceChangeRecorder::attributeTo(ProductPriceChange::SOURCE_PROMOTION, 'Autumn sale', function () use ($product) {
            PriceChangeRecorder::attributeTo(ProductPriceChange::SOURCE_BULK_JOB, 'Bulk #7', function () use ($product) {
                $product->forceFill(['unit_price' => 80])->save();
            });

            // Back to the outer attribution rather than to nothing — otherwise a job that calls a
            // bulk operation would lose its own name halfway through.
            $product->forceFill(['unit_price' => 100])->save();
        });

        $changes = ProductPriceChange::orderBy('id')->get();
        $this->assertSame(ProductPriceChange::SOURCE_BULK_JOB, $changes[0]->source);
        $this->assertSame('Bulk #7', $changes[0]->reason);
        $this->assertSame(ProductPriceChange::SOURCE_PROMOTION, $changes[1]->source);
        $this->assertSame('Autumn sale', $changes[1]->reason);
    }

    public function test_an_unattributed_change_with_nobody_signed_in_is_recorded_as_automation(): void
    {
        $product = $this->product(['unit_price' => 100]);
        ProductPriceChange::query()->delete();

        $product->forceFill(['unit_price' => 90])->save();

        // A console command or a queued job. Honest, and distinguishable from a person typing.
        $this->assertSame(ProductPriceChange::SOURCE_AUTOMATION, ProductPriceChange::latest('id')->first()->source);
    }

    // ---------------------------------------------------------------- defect 4

    public function test_a_seller_is_actually_told_what_is_waiting(): void
    {
        $seller = Seller::find(1);

        $delivery = app(SellerNotifier::class)->deliver(
            seller: $seller,
            topic: 'ORDER_SLA',
            severity: 'critical',
            subjectCount: 50,
            title: '50 orders need shipping',
            actionKey: 'open_action_center',
        );

        // Before this, an SLA breach opened a row in a ledger and told nobody.
        $this->assertNotNull($delivery);
        $this->assertSame(SellerNotificationDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame(50, $delivery->subject_count);
        // Recorded in the app even where no device token exists, which is the point of a delivery
        // row rather than a fire-and-forget push.
        $this->assertArrayHasKey('in_app', $delivery->channels);
    }

    public function test_the_same_fact_is_not_announced_twice(): void
    {
        $seller = Seller::find(1);
        $notifier = app(SellerNotifier::class);

        $first = $notifier->deliver($seller, 'ORDER_SLA', 'critical', 50, '50 orders need shipping');
        $second = $notifier->deliver($seller, 'ORDER_SLA', 'critical', 51, '51 orders need shipping');

        // Detection runs on a schedule. Without this, every sweep re-announces the same forty late
        // orders and the seller stops reading any of it.
        $this->assertNotNull($first);
        $this->assertNull($second, 'The same fact was announced twice inside one window.');
        $this->assertSame(1, SellerNotificationDelivery::count());
    }

    public function test_many_insights_of_one_type_become_one_message(): void
    {
        $seller = Seller::find(1);
        $insights = collect(range(1, 12))->map(fn (int $index) => (object) [
            'type' => 'ORDER_SLA',
            'severity' => $index === 1 ? 'critical' : 'high',
        ]);

        $sent = app(SellerNotifier::class)->announceInsights($seller, $this->asInsights($insights));

        $this->assertCount(1, $sent, 'Twelve problems became twelve messages.');
        $this->assertSame(12, $sent[0]->subject_count);
        // The worst one sets the tone: eleven high and one critical is a critical message.
        $this->assertSame('critical', $sent[0]->severity);
    }

    public function test_a_quiet_problem_is_recorded_without_interrupting_anyone(): void
    {
        $seller = Seller::find(1);
        Seller::where('id', 1)->update(['cm_firebase_token' => 'a-device-token']);

        $delivery = app(SellerNotifier::class)->deliver($seller->fresh(), 'LISTING_QUALITY', 'low', 3, 'Three listings could be better');

        // A low-severity insight belongs in the list, not on somebody's phone.
        $this->assertNotNull($delivery);
        $this->assertSame(['in_app' => 'recorded'], $delivery->channels);
    }

    /** @return array<int, string> */
    private function migrationNames(): array
    {
        return array_map(
            fn (string $path) => basename($path, '.php'),
            glob(database_path('migrations/*.php')) ?: [],
        );
    }

    /** @param Collection<int, object> $rows */
    private function asInsights(Collection $rows): Collection
    {
        return $rows->map(function (object $row) {
            $insight = new \App\Models\SellerInsight();
            $insight->type = $row->type;
            $insight->severity = $row->severity;

            return $insight;
        });
    }
}
