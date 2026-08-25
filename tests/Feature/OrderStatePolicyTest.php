<?php

namespace Tests\Feature;

use App\Services\Commerce\OrderStatePolicy;
use App\Services\Platform\Policy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Which order states can still be changed, and by whom.
 *
 * Both rules were inline status arrays repeated across three files — the edit gate, the customer's
 * cancel button on the web and the same cancel over the API — so a marketplace that wanted
 * cancellation to stop at "processing" needed a code change in three places that could disagree
 * without anything failing.
 *
 * The first test is the one that matters most: the shipped defaults must be exactly what those
 * arrays said, because this is a live shop and a settings-backed rule that quietly widens what a
 * customer may cancel is a worse outcome than the duplication it replaced.
 */
class OrderStatePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['business_settings', 'settings'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('type')->nullable();
                $t->string('key')->nullable();
                $t->text('value')->nullable();
                $t->timestamps();
            });
        }
    }

    private function policy(): OrderStatePolicy
    {
        return app(OrderStatePolicy::class);
    }

    public function test_the_shipped_defaults_are_exactly_what_the_inline_arrays_said(): void
    {
        $this->assertSame(['pending', 'confirmed'], $this->policy()->editableStatuses());
        $this->assertSame(['pending'], $this->policy()->customerCancellableStatuses());
    }

    public function test_an_order_past_the_editable_states_is_refused(): void
    {
        $this->assertTrue($this->policy()->isEditable('confirmed'));
        $this->assertFalse($this->policy()->isEditable('out_for_delivery'));
        $this->assertFalse($this->policy()->isEditable(null));
    }

    public function test_a_marketplace_can_move_the_line_without_a_deployment(): void
    {
        app(Policy::class)->save(['order_cancellable_statuses' => ['pending', 'confirmed', 'processing']]);

        $this->assertTrue(app(OrderStatePolicy::class)->customerMayCancel('processing'));
        $this->assertFalse(app(OrderStatePolicy::class)->customerMayCancel('out_for_delivery'));
    }

    /** An unknown status in this list would silently widen what a customer may cancel. */
    public function test_a_stored_status_that_is_not_a_real_one_is_dropped(): void
    {
        app(Policy::class)->save(['order_cancellable_statuses' => ['pending', 'archived', 'whatever']]);

        $this->assertSame(['pending'], app(OrderStatePolicy::class)->customerCancellableStatuses());
    }

    /** The list is read back in lifecycle order however it was stored, so a screen reads sensibly. */
    public function test_the_states_come_back_in_lifecycle_order(): void
    {
        app(Policy::class)->save(['order_editable_statuses' => ['confirmed', 'pending']]);

        $this->assertSame(['pending', 'confirmed'], app(OrderStatePolicy::class)->editableStatuses());
    }
}
