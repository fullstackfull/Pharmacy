<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Seller;
use App\Models\SellerBulkJob;
use App\Services\Marketplace\Bulk\SellerBulkJobService;
use App\Services\Marketplace\SellerPrincipal;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A bulk change, and the receipt it leaves behind.
 *
 * The whole point of the receipt is the partial result. Four hundred price changes will not all
 * land — one product was deleted while the seller was choosing, another is a variant product whose
 * stock lives per variant, a third would be driven below zero — and the tool has to do the rest and
 * then say precisely which ones it did not do. A bulk operation that reported a flat "done" would be
 * misleading its own user, who finds out from a customer instead.
 *
 * So these tests are mostly about what the job refuses and how it says so, not about the happy path.
 */
class SellerBulkOperationTest extends TestCase
{
    private const OWNER_TOKEN = 'owner-token-long-enough-to-clear-the-length-gate';
    private const RIVAL_TOKEN = 'rival-token-long-enough-to-clear-the-length-gate';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['seller_bulk_jobs', 'stock_movements', 'products', 'sellers', 'seller_staff', 'translations', 'restock_products', 'restock_product_customers', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
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
            $table->decimal('discount', 24, 3)->default(0);
            $table->string('discount_type', 20)->default('flat');
            $table->integer('current_stock')->default(0);
            $table->text('variation')->nullable();
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
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type')->nullable();
            $table->unsignedBigInteger('translationable_id')->nullable();
            $table->string('locale')->nullable();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('seller_staff', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('seller_role_id')->nullable();
            $table->string('name', 120)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        Schema::create('seller_bulk_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('created_by_staff_id')->nullable();
            // Which credential queued it: a key-created job must not re-resolve as the owner.
            $table->unsignedBigInteger('created_by_api_key_id')->nullable();
            $table->string('type', 60);
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->text('failures')->nullable();
            $table->text('input')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('restock_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('variant')->nullable();
            $table->timestamps();
        });
        Schema::create('restock_product_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('restock_product_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->timestamps();
        });

        Seller::insert([
            ['id' => 1, 'f_name' => 'Owner', 'l_name' => 'One', 'status' => 'approved', 'auth_token' => self::OWNER_TOKEN],
            ['id' => 2, 'f_name' => 'Rival', 'l_name' => 'Two', 'status' => 'approved', 'auth_token' => self::RIVAL_TOKEN],
        ]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::forceCreate(array_merge([
            'added_by' => 'seller',
            'user_id' => 1,
            'name' => 'Widget',
            'unit_price' => 100,
            'discount' => 0,
            'discount_type' => 'flat',
            'current_stock' => 10,
            'variation' => '[]',
        ], $attributes));
    }

    private function owner(int $sellerId = 1): SellerPrincipal
    {
        return SellerPrincipal::owner(Seller::find($sellerId));
    }

    private function runJob(string $type, array $payload, ?SellerPrincipal $principal = null): SellerBulkJob
    {
        $service = app(SellerBulkJobService::class);
        $job = $service->create($principal ?? $this->owner(), $type, $payload);

        // The queue is synchronous in tests, so the job has already run; re-read it rather than
        // trusting the copy that was returned before the work started.
        return $job->refresh();
    }

    public function test_a_price_change_lands_and_the_receipt_counts_it(): void
    {
        $first = $this->product();
        $second = $this->product(['unit_price' => 200]);

        $job = $this->runJob('price_update', [
            'product_ids' => [$first->id, $second->id],
            'mode' => 'increase_percent',
            'value' => 10,
        ]);

        $this->assertSame(SellerBulkJob::STATUS_COMPLETED, $job->status);
        $this->assertSame(2, $job->succeeded);
        $this->assertSame(0, $job->failed);
        $this->assertSame(100, $job->progress());
        $this->assertEqualsWithDelta(110.0, (float) $first->fresh()->unit_price, 0.01);
        $this->assertEqualsWithDelta(220.0, (float) $second->fresh()->unit_price, 0.01);
    }

    public function test_another_sellers_product_is_refused_and_never_touched(): void
    {
        $mine = $this->product();
        $theirs = $this->product(['user_id' => 2, 'unit_price' => 500]);

        $job = $this->runJob('price_update', [
            'product_ids' => [$mine->id, $theirs->id],
            'mode' => 'set',
            'value' => 1,
        ]);

        // The rival's price is untouched, and — just as important — the refusal is worded as "not
        // found", so the endpoint cannot be used to learn which ids belong to somebody else.
        $this->assertEqualsWithDelta(500.0, (float) $theirs->fresh()->unit_price, 0.01);
        $this->assertSame(SellerBulkJob::STATUS_PARTIAL, $job->status);
        $this->assertSame(1, $job->succeeded);
        $this->assertSame([$theirs->id], array_column($job->failures, 'product_id'));
        $this->assertSame('bulk_reason_product_not_found', $job->failures[0]['reason']);
    }

    public function test_a_refused_row_does_not_stop_the_rest_of_the_job(): void
    {
        $refused = $this->product(['unit_price' => 5]);
        $fine = $this->product(['unit_price' => 100]);

        // A flat 50 off takes the first product below zero, and leaves the second at 50.
        $job = $this->runJob('price_update', [
            'product_ids' => [$refused->id, $fine->id],
            'mode' => 'decrease_amount',
            'value' => 50,
        ]);

        $this->assertSame(SellerBulkJob::STATUS_PARTIAL, $job->status);
        $this->assertSame(1, $job->succeeded);
        $this->assertSame(2, $job->processed, 'The job stopped at the first refusal.');
        $this->assertEqualsWithDelta(5.0, (float) $refused->fresh()->unit_price, 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $fine->fresh()->unit_price, 0.01);
        $this->assertSame('bulk_reason_price_would_be_zero_or_less', $job->failures[0]['reason']);
    }

    public function test_a_price_is_refused_rather_than_floored_at_zero(): void
    {
        $product = $this->product(['unit_price' => 40]);

        $job = $this->runJob('price_update', [
            'product_ids' => [$product->id],
            'mode' => 'decrease_percent',
            'value' => 100,
        ]);

        // Publishing a free product by arithmetic accident is far worse than refusing the row.
        $this->assertSame(SellerBulkJob::STATUS_FAILED, $job->status);
        $this->assertSame(0, $job->succeeded);
        $this->assertEqualsWithDelta(40.0, (float) $product->fresh()->unit_price, 0.01);
    }

    public function test_a_discount_that_would_swallow_the_price_is_refused(): void
    {
        $product = $this->product(['unit_price' => 100]);

        $job = $this->runJob('price_update', [
            'product_ids' => [$product->id],
            'mode' => 'set',
            'value' => 50,
            'discount' => 80,
            'discount_type' => 'flat',
        ]);

        $this->assertSame(SellerBulkJob::STATUS_FAILED, $job->status);
        $this->assertSame('bulk_reason_discount_not_below_price', $job->failures[0]['reason']);
        // Nothing at all was written: the price check and the discount check are one decision.
        $this->assertEqualsWithDelta(100.0, (float) $product->fresh()->unit_price, 0.01);
    }

    public function test_a_product_already_at_the_asked_for_price_counts_as_done(): void
    {
        $product = $this->product(['unit_price' => 100]);

        $job = $this->runJob('price_update', ['product_ids' => [$product->id], 'mode' => 'set', 'value' => 100]);

        // The seller asked for a state and the product is in it. Calling that a failure would be a
        // lie in the other direction.
        $this->assertSame(SellerBulkJob::STATUS_COMPLETED, $job->status);
        $this->assertSame(1, $job->succeeded);
    }

    public function test_stock_moves_through_the_ledger_rather_than_the_column(): void
    {
        $product = $this->product(['current_stock' => 10]);

        $job = $this->runJob('stock_update', ['product_ids' => [$product->id], 'mode' => 'increase', 'value' => 5]);

        $this->assertSame(SellerBulkJob::STATUS_COMPLETED, $job->status);
        $this->assertSame(15, (int) $product->fresh()->current_stock);

        // The number has a history behind it, which is the only thing that makes it defensible later.
        $movement = DB::table('stock_movements')->where('product_id', $product->id)->first();
        $this->assertNotNull($movement, 'A bulk stock change left no movement line.');
        $this->assertSame(5, (int) $movement->qty_change);
        $this->assertSame(15, (int) $movement->balance_after);
    }

    public function test_stock_cannot_be_driven_below_zero_in_bulk_either(): void
    {
        $product = $this->product(['current_stock' => 3]);

        $job = $this->runJob('stock_update', ['product_ids' => [$product->id], 'mode' => 'decrease', 'value' => 10]);

        $this->assertSame(SellerBulkJob::STATUS_FAILED, $job->status);
        $this->assertSame(3, (int) $product->fresh()->current_stock);
        $this->assertSame('bulk_reason_adjustment_would_make_stock_negative', $job->failures[0]['reason']);
    }

    public function test_a_variant_products_stock_is_refused_rather_than_guessed_at(): void
    {
        $product = $this->product([
            'current_stock' => 10,
            'variation' => json_encode([['type' => 'red', 'price' => 100, 'sku' => 'r', 'qty' => 4]]),
        ]);

        $job = $this->runJob('stock_update', ['product_ids' => [$product->id], 'mode' => 'set', 'value' => 50]);

        // Spreading one number across variants the seller did not name would be inventing data, and
        // writing the total while leaving the variants alone would break per-variant availability.
        $this->assertSame(SellerBulkJob::STATUS_FAILED, $job->status);
        $this->assertSame(10, (int) $product->fresh()->current_stock);
        $this->assertSame('bulk_reason_variant_stock_must_be_set_per_variant', $job->failures[0]['reason']);
    }

    public function test_the_same_product_listed_twice_is_changed_once(): void
    {
        $product = $this->product(['current_stock' => 10]);

        $job = $this->runJob('stock_update', [
            'product_ids' => [$product->id, $product->id],
            'mode' => 'increase',
            'value' => 5,
        ]);

        $this->assertSame(1, $job->total, 'A duplicated id inflated the total the seller sees.');
        $this->assertSame(15, (int) $product->fresh()->current_stock, 'The increase was applied twice.');
    }

    public function test_a_job_records_what_was_asked_for_before_any_of_it_runs(): void
    {
        $product = $this->product();

        $job = $this->runJob('price_update', ['product_ids' => [$product->id], 'mode' => 'set', 'value' => 77]);

        // If the worker had died halfway there would still be a row saying what was asked and how
        // far it got, instead of a silence the seller has to interpret.
        $this->assertSame([$product->id], $job->input['product_ids']);
        $this->assertSame('set', $job->input['settings']['mode']);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->finished_at);
    }

    public function test_a_staff_member_without_the_permission_cannot_have_the_work_done_for_them(): void
    {
        $product = $this->product(['unit_price' => 100]);
        $job = SellerBulkJob::create([
            'seller_id' => 1,
            'created_by_staff_id' => 404,
            'type' => 'price_update',
            'status' => SellerBulkJob::STATUS_QUEUED,
            'total' => 1,
            'input' => ['product_ids' => [$product->id], 'settings' => ['mode' => 'set', 'value' => 1]],
        ]);

        // The staff member no longer resolves — deleted, deactivated, or their employer suspended —
        // so the queued work is dropped rather than carried out on a dead authority.
        app(SellerBulkJobService::class)->run($job);

        $this->assertSame(SellerBulkJob::STATUS_FAILED, $job->fresh()->status);
        $this->assertEqualsWithDelta(100.0, (float) $product->fresh()->unit_price, 0.01);
    }

    public function test_an_unknown_operation_is_refused_at_the_door(): void
    {
        $this->expectException(ValidationException::class);

        app(SellerBulkJobService::class)->create($this->owner(), 'delete_everything', ['product_ids' => [1]]);
    }

    public function test_a_request_larger_than_the_cap_is_refused_rather_than_silently_trimmed(): void
    {
        // Silently taking the first thousand would tell the seller the rest were done.
        $this->expectException(ValidationException::class);

        app(SellerBulkJobService::class)->create($this->owner(), 'price_update', [
            'product_ids' => range(1, SellerBulkJobService::MAX_ROWS + 1),
            'mode' => 'set',
            'value' => 5,
        ]);
    }

    public function test_an_invalid_mode_never_creates_a_job(): void
    {
        try {
            app(SellerBulkJobService::class)->create($this->owner(), 'stock_update', [
                'product_ids' => [1],
                'mode' => 'multiply',
                'value' => 2,
            ]);
            $this->fail('An invalid mode was accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, SellerBulkJob::count(), 'A rejected request still left a receipt behind.');
        }
    }

    /** @return array<string, string> */
    private function headers(string $token = self::OWNER_TOKEN): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    public function test_the_endpoint_answers_with_a_job_the_client_can_follow(): void
    {
        $product = $this->product(['unit_price' => 100]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v3/seller/seller-center/bulk-jobs/price', [
                'product_ids' => [$product->id],
                'mode' => 'set',
                'value' => 250,
            ]);

        // 202, not 200: the work is accepted, and the job id is how the client learns the outcome.
        $response->assertStatus(202);
        $this->assertNotNull($response->json('job.id'));
        $this->assertEqualsWithDelta(250.0, (float) $product->fresh()->unit_price, 0.01);
    }

    public function test_another_sellers_receipt_is_not_readable(): void
    {
        $job = SellerBulkJob::create([
            'seller_id' => 2, 'type' => 'price_update', 'status' => SellerBulkJob::STATUS_COMPLETED, 'total' => 1,
        ]);

        // The rival's job answers exactly as a job that was never created would.
        $this->withHeaders($this->headers())
            ->getJson("/api/v3/seller/seller-center/bulk-jobs/{$job->id}")
            ->assertStatus(404);
        $this->withHeaders($this->headers())
            ->getJson("/api/v3/seller/seller-center/bulk-jobs/{$job->id}/failures")
            ->assertStatus(404);
    }

    public function test_the_failure_list_downloads_as_a_file_with_a_reason_per_row(): void
    {
        $theirs = $this->product(['user_id' => 2]);
        $job = $this->runJob('price_update', ['product_ids' => [$theirs->id], 'mode' => 'set', 'value' => 5]);

        $response = $this->withHeaders($this->headers())
            ->get("/api/v3/seller/seller-center/bulk-jobs/{$job->id}/failures");

        $response->assertStatus(200);
        $csv = $response->streamedContent();

        // The reason is in the seller's own language, not the key — this file is read by a person
        // working through the products the job would not change.
        $this->assertStringContainsString((string) $theirs->id, $csv);
        $this->assertStringContainsString(translate('bulk_reason_product_not_found'), $csv);
    }

    public function test_a_request_without_a_credential_is_refused(): void
    {
        $this->postJson('/api/v3/seller/seller-center/bulk-jobs/stock', [
            'product_ids' => [1], 'mode' => 'set', 'value' => 1,
        ])->assertStatus(401);
    }

    public function test_a_job_the_queue_never_picked_up_is_run_by_the_scheduler(): void
    {
        $product = $this->product(['unit_price' => 100]);
        $job = SellerBulkJob::create([
            'seller_id' => 1,
            'type' => 'price_update',
            'status' => SellerBulkJob::STATUS_QUEUED,
            'total' => 1,
            'input' => ['product_ids' => [$product->id], 'settings' => ['mode' => 'set', 'value' => 42]],
        ]);
        // Aged deliberately: `created_at` is not fillable, so it has to be set after the fact.
        $job->forceFill(['created_at' => now()->subMinutes(10)])->save();

        // Without this sweep, a deployment with no queue worker would leave the seller looking at a
        // change that was never going to happen — the exact failure the receipt exists to prevent.
        $this->artisan('seller:run-stuck-bulk-jobs')->assertSuccessful();

        $this->assertSame(SellerBulkJob::STATUS_COMPLETED, $job->fresh()->status);
        $this->assertEqualsWithDelta(42.0, (float) $product->fresh()->unit_price, 0.01);
    }

    public function test_a_job_still_within_its_grace_period_is_left_to_the_worker(): void
    {
        $product = $this->product(['unit_price' => 100]);
        $job = SellerBulkJob::create([
            'seller_id' => 1,
            'type' => 'price_update',
            'status' => SellerBulkJob::STATUS_QUEUED,
            'total' => 1,
            'input' => ['product_ids' => [$product->id], 'settings' => ['mode' => 'set', 'value' => 42]],
        ]);

        // Running it here as well as on the queue would apply an `increase` twice.
        $this->artisan('seller:run-stuck-bulk-jobs')->assertSuccessful();

        $this->assertSame(SellerBulkJob::STATUS_QUEUED, $job->fresh()->status);
        $this->assertEqualsWithDelta(100.0, (float) $product->fresh()->unit_price, 0.01);
    }
}
