<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductModerationEvent;
use App\Services\Marketplace\ProductModerationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Decisions that used to leave no trace.
 *
 * Three screens made consequential decisions and recorded nothing: the classic product screen
 * approved and denied listings while the audited moderation path sat beside it unused, so whether a
 * listing decision was recorded depended on which screen the operator happened to open; brand CRUD
 * created and deleted the very brands the audited brand registry grants claims over; and refund
 * approval — which debits a seller's earnings, reverses the marketplace's commission and moves a
 * customer's money — wrote only to its own status history.
 *
 * The classic screen's approve control is a toggle, which is why un-approving needed a name of its
 * own here. Taking an approval back is not the same decision as denying a listing, and recording it
 * as a rejection would tell the seller something that is not true.
 */
class ModerationAndMoneyAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['products', 'product_moderation_events', 'audit_logs', 'translations', 'reviews'] as $table) {
            Schema::dropIfExists($table);
        }

        // The product model's global scope and the cache helpers read shop settings on the way past.
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

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('name')->nullable();
            $t->unsignedTinyInteger('request_status')->default(0);
            $t->unsignedTinyInteger('status')->default(1);
            $t->timestamps();
        });

        Schema::create('product_moderation_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->string('action', 30);
            $t->json('reason_codes')->nullable();
            $t->text('note')->nullable();
            $t->unsignedTinyInteger('previous_request_status')->nullable();
            $t->unsignedTinyInteger('new_request_status')->nullable();
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->string('actor_type')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name')->nullable();
            $t->string('action');
            $t->string('subject_type')->nullable();
            $t->string('subject_id')->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->json('context')->nullable();
            $t->string('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamps();
        });

        Schema::create('translations', function (Blueprint $t) {
            $t->id();
            $t->string('translationable_type');
            $t->unsignedBigInteger('translationable_id');
            $t->string('locale');
            $t->string('key');
            $t->text('value')->nullable();
            $t->timestamps();
        });

        Schema::create('reviews', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('delivery_man_id')->nullable();
            $t->unsignedTinyInteger('status')->default(1);
            $t->timestamps();
        });
    }

    private function product(int $requestStatus = 0): Product
    {
        return Product::create(['name' => 'Panadol 500', 'user_id' => 4, 'request_status' => $requestStatus]);
    }

    public function test_taking_an_approval_back_is_its_own_decision_not_a_rejection(): void
    {
        $product = $this->product(requestStatus: 1);

        app(ProductModerationService::class)->returnToReview($product->id);

        $event = ProductModerationEvent::first();
        $this->assertSame(ProductModerationService::ACTION_RETURNED_TO_REVIEW, $event->action);
        $this->assertSame(1, (int) $event->previous_request_status);
        // Back to the queue, not to denied: the seller is not being told no.
        $this->assertSame(0, (int) $event->new_request_status);
        $this->assertSame(0, (int) $product->fresh()->request_status);
    }

    public function test_returning_a_listing_to_review_reaches_the_audit_trail(): void
    {
        $product = $this->product(requestStatus: 1);

        app(ProductModerationService::class)->returnToReview($product->id);

        $this->assertSame(
            'product.' . ProductModerationService::ACTION_RETURNED_TO_REVIEW,
            AuditLog::first()?->action,
        );
    }

    public function test_an_approval_still_records_both_the_history_event_and_the_audit_line(): void
    {
        $product = $this->product(requestStatus: 0);

        app(ProductModerationService::class)->approve($product->id);

        $this->assertSame(1, ProductModerationEvent::count());
        $this->assertSame('product.approved', AuditLog::first()?->action);
        $this->assertSame(1, (int) $product->fresh()->request_status);
    }
}
