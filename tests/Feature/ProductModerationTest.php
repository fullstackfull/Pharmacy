<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductModerationEvent;
use App\Services\AuditLogger;
use App\Services\Marketplace\ProductModerationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Product moderation with a history (Phase 3, Stage A — spec item 7).
 *
 * What it adds over the one request_status flag and one overwritten denied_note the platform had:
 *
 *  1. A per-decision history — who, what, why — that is never overwritten.
 *  2. The six states the spec names, mapped onto request_status so the storefront/app contract holds.
 *  3. Structured reason codes, so "why was this rejected" is data, not prose.
 *  4. Every decision on the unified audit log.
 */
class ProductModerationTest extends TestCase
{
    private ProductModerationService $moderation;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['products', 'product_moderation_events', 'audit_logs', 'translations', 'reviews', 'business_settings'] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->boolean('status')->default(1);
            $t->tinyInteger('request_status')->default(0);
            $t->text('denied_note')->nullable();
            $t->timestamps();
        });
        Schema::create('product_moderation_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->string('action', 30);
            $t->json('reason_codes')->nullable();
            $t->text('note')->nullable();
            $t->tinyInteger('previous_request_status')->nullable();
            $t->tinyInteger('new_request_status')->nullable();
            $t->string('moderator_type', 20)->nullable();
            $t->unsignedBigInteger('moderator_id')->nullable();
            $t->string('moderator_name', 191)->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id(); $t->string('actor_type', 40)->nullable(); $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name', 191)->nullable(); $t->string('action', 100);
            $t->string('subject_type', 100)->nullable(); $t->string('subject_id', 191)->nullable();
            $t->json('before')->nullable(); $t->json('after')->nullable(); $t->json('context')->nullable();
            $t->string('ip_address', 45)->nullable(); $t->string('user_agent', 255)->nullable(); $t->timestamps();
        });
        // Product carries a global scope eager-loading these on every query.
        Schema::create('translations', function (Blueprint $t) {
            $t->id(); $t->string('translationable_type'); $t->unsignedBigInteger('translationable_id');
            $t->string('locale', 20); $t->string('key'); $t->text('value')->nullable();
        });
        Schema::create('reviews', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('product_id')->nullable(); $t->unsignedBigInteger('delivery_man_id')->nullable();
            $t->integer('rating')->default(0); $t->boolean('status')->default(1); $t->timestamps();
        });
        Schema::create('business_settings', function (Blueprint $t) {
            $t->id(); $t->string('type')->nullable(); $t->text('value')->nullable(); $t->timestamps();
        });

        $this->moderation = new ProductModerationService(new AuditLogger());
    }

    private function pendingProduct(): Product
    {
        return Product::create(['name' => 'Serum', 'status' => 1, 'request_status' => 0]);
    }

    // ---- the states, mapped onto request_status ----

    public function test_approving_sets_request_status_to_one(): void
    {
        $product = $this->pendingProduct();

        $event = $this->moderation->approve($product->id, note: 'looks good');

        $this->assertSame(1, Product::find($product->id)->request_status);
        $this->assertSame('approved', $event->action);
        $this->assertSame(0, $event->previous_request_status);
        $this->assertSame(1, $event->new_request_status);
    }

    public function test_rejecting_sets_request_status_to_two_and_records_the_reasons(): void
    {
        $product = $this->pendingProduct();

        $event = $this->moderation->reject($product->id, ['missing_image', 'price_issue'], note: 'blurry photo, price looks wrong');

        $this->assertSame(2, Product::find($product->id)->request_status);
        $this->assertSame(['missing_image', 'price_issue'], $event->reason_codes);
    }

    public function test_needs_changes_is_distinct_from_rejected_in_the_history(): void
    {
        $product = $this->pendingProduct();

        $this->moderation->needsChanges($product->id, ['incorrect_category'], note: 'move to skincare');

        $event = ProductModerationEvent::forProduct($product->id)->first();
        $this->assertSame('needs_changes', $event->action, 'the history distinguishes it even though the legacy column cannot');
        $this->assertSame(2, Product::find($product->id)->request_status);
    }

    public function test_suspending_takes_an_approved_product_offline_without_denying_it(): void
    {
        $product = Product::create(['name' => 'Serum', 'status' => 1, 'request_status' => 1]);

        $this->moderation->suspend($product->id, ['prohibited_item'], note: 'reported');

        $fresh = Product::find($product->id);
        $this->assertSame(0, (int) $fresh->status, 'taken offline');
        $this->assertSame(1, $fresh->request_status, 'but not denied — request_status is untouched');
    }

    // ---- the history is never overwritten ----

    public function test_the_full_back_and_forth_is_preserved(): void
    {
        $product = $this->pendingProduct();

        $this->moderation->needsChanges($product->id, ['missing_image'], note: 'add a photo');
        $this->moderation->reject($product->id, ['duplicate_product'], note: 'already listed');
        $this->moderation->approve($product->id, note: 'resolved');

        $history = ProductModerationEvent::forProduct($product->id)->get();

        $this->assertCount(3, $history, 'every decision kept, not overwritten');
        $this->assertSame('approved', $history[0]->action, 'newest first');
        $this->assertSame('reject'.'ed', $history[1]->action);
        $this->assertSame('needs_changes', $history[2]->action);
    }

    public function test_an_unknown_reason_code_is_dropped_rather_than_stored(): void
    {
        $product = $this->pendingProduct();

        $event = $this->moderation->reject($product->id, ['missing_image', 'made_up_reason'], note: null);

        $this->assertSame(['missing_image'], $event->reason_codes, 'only known reasons survive');
    }

    // ---- every decision is audited ----

    public function test_each_decision_writes_to_the_audit_log(): void
    {
        $product = $this->pendingProduct();

        $this->moderation->reject($product->id, ['price_issue']);

        $this->assertTrue(
            AuditLog::where('action', 'product.rejected')->where('subject_id', $product->id)->exists(),
            'product governance shares the unified trail'
        );
    }

    // ---- bulk ----

    public function test_bulk_approve_processes_every_product_and_logs_each(): void
    {
        $ids = [$this->pendingProduct()->id, $this->pendingProduct()->id, $this->pendingProduct()->id];

        $result = $this->moderation->bulk(ProductModerationService::ACTION_APPROVED, $ids);

        $this->assertSame(3, $result['processed']);
        $this->assertSame(3, Product::where('request_status', 1)->count());
        $this->assertSame(3, AuditLog::where('action', 'product.approved')->count());
    }

    public function test_bulk_skips_missing_ids_without_aborting_the_batch(): void
    {
        $real = $this->pendingProduct()->id;

        $result = $this->moderation->bulk(ProductModerationService::ACTION_APPROVED, [$real, 999999]);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['skipped'], 'the missing id is skipped, the real one still processed');
    }

    public function test_moderating_a_missing_product_returns_null(): void
    {
        $this->assertNull($this->moderation->approve(999999));
    }
}
