<?php

namespace Tests\Feature;

use App\Models\AbandonedCartReminder;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingAddress;
use App\Models\User;
use App\Services\Retention\AbandonedCartService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Which carts get an email, and — more importantly — which do not (Phase 2.4).
 *
 * Every case here is a message that would otherwise land in a real person's inbox and cannot be
 * recalled. The expensive mistakes are all *false positives*: emailing somebody who has already
 * paid, emailing the same person twice because a cron ran twice, or emailing a cart full of
 * products that no longer exist. So most of these tests assert that nothing is selected.
 */
class AbandonedCartSelectionTest extends TestCase
{
    private AbandonedCartService $service;
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['carts', 'products', 'sellers', 'users', 'shipping_addresses', 'orders', 'abandoned_cart_reminders', 'business_settings', 'translations', 'reviews'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $t) {
            $t->id();
            $t->string('type')->nullable();
            $t->text('value')->nullable();
            $t->timestamps();
        });
        // Product's global scope eager-loads both of these on every query.
        Schema::create('translations', function (Blueprint $t) {
            $t->id();
            $t->string('translationable_type');
            $t->unsignedBigInteger('translationable_id');
            $t->string('locale', 20);
            $t->string('key');
            $t->text('value')->nullable();
        });
        Schema::create('reviews', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('delivery_man_id')->nullable();
            $t->integer('rating')->default(0);
            $t->boolean('status')->default(1);
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('added_by')->default('admin');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('brand_id')->nullable();
            $t->string('product_type')->default('physical');
            $t->boolean('status')->default(1);
            $t->string('request_status')->default('1');
            $t->integer('current_stock')->default(50);
            $t->decimal('unit_price', 24, 2)->default(0);
            $t->timestamps();
        });
        // Product::scopeActive() joins sellers to check the vendor is approved, even for
        // admin-owned products — the exists() clause is evaluated either way.
        Schema::create('sellers', function (Blueprint $t) {
            $t->id();
            $t->string('status')->default('approved');
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('f_name')->nullable();
            $t->string('l_name')->nullable();
            $t->string('email')->nullable();
            $t->timestamps();
        });
        Schema::create('shipping_addresses', function (Blueprint $t) {
            $t->id();
            $t->string('customer_id', 15)->nullable();
            $t->boolean('is_guest')->default(0);
            $t->string('email')->nullable();
            $t->string('contact_person_name')->nullable();
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->boolean('is_guest')->default(0);
            $t->timestamps();
        });
        Schema::create('carts', function (Blueprint $t) {
            $t->id();
            $t->string('customer_id')->nullable();
            $t->string('cart_group_id')->nullable();
            $t->unsignedBigInteger('product_id');
            $t->integer('quantity')->default(1);
            $t->decimal('price', 24, 2)->default(0);
            $t->decimal('discount', 24, 2)->default(0);
            $t->boolean('is_guest')->default(0);
            $t->boolean('is_checked')->default(1);
            $t->timestamps();
        });
        Schema::create('abandoned_cart_reminders', function (Blueprint $t) {
            $t->id();
            $t->string('cart_group_id', 191);
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->boolean('is_guest')->default(0);
            $t->string('email');
            $t->unsignedTinyInteger('stage')->default(1);
            $t->decimal('cart_value', 24, 2)->default(0);
            $t->unsignedInteger('item_count')->default(0);
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('clicked_at')->nullable();
            $t->timestamp('recovered_at')->nullable();
            $t->string('recovered_order_id', 191)->nullable();
            $t->timestamps();
            $t->unique(['cart_group_id', 'stage']);
        });

        $this->enable();
        $this->service = new AbandonedCartService();
        $this->now = Carbon::parse('2026-08-09 12:00:00');
    }

    private function enable(array $overrides = []): void
    {
        $settings = array_merge([
            AbandonedCartService::SETTING_ENABLED => 1,
            AbandonedCartService::SETTING_INCLUDE_GUESTS => 0,
            AbandonedCartService::SETTING_IDLE_HOURS => 6,
            AbandonedCartService::SETTING_MAX_AGE_HOURS => 168,
            AbandonedCartService::SETTING_MIN_VALUE => 0,
        ], $overrides);

        foreach ($settings as $type => $value) {
            \App\Models\BusinessSetting::updateOrCreate(['type' => $type], ['value' => $value]);
        }
        // getWebConfig() caches per request; these tests change settings between calls.
        cache()->flush();
    }

    private function customer(string $email = 'buyer@example.com'): User
    {
        return User::create(['f_name' => 'Sara', 'l_name' => 'K', 'email' => $email]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Serum', 'added_by' => 'admin', 'product_type' => 'physical',
            'status' => 1, 'request_status' => '1', 'unit_price' => 500,
        ], $attributes));
    }

    /** A cart line last touched $hoursAgo hours before the fixed "now". */
    private function cartLine(array $attributes, int $hoursAgo): Cart
    {
        $at = $this->now->copy()->subHours($hoursAgo);

        $cart = Cart::create(array_merge([
            'cart_group_id' => 'grp-1', 'quantity' => 2, 'price' => 500, 'discount' => 0,
            'is_guest' => 0, 'is_checked' => 1,
        ], $attributes));

        // created_at/updated_at are managed by Eloquent, so they are set after the fact.
        Cart::where('id', $cart->id)->update(['created_at' => $at, 'updated_at' => $at]);

        return $cart->fresh();
    }

    // ---- the cart that should be emailed ----

    public function test_an_idle_cart_belonging_to_a_registered_customer_is_selected(): void
    {
        $customer = $this->customer();
        $this->cartLine(['customer_id' => $customer->id, 'product_id' => $this->product()->id], hoursAgo: 10);

        $found = $this->service->findAbandoned(now: $this->now);

        $this->assertCount(1, $found);
        $this->assertSame('grp-1', $found[0]['cart_group_id']);
        $this->assertSame('buyer@example.com', $found[0]['email']);
        $this->assertSame(1000.0, $found[0]['cart_value'], '2 x 500');
        $this->assertSame(2, $found[0]['item_count']);
        $this->assertFalse($found[0]['is_guest']);
    }

    public function test_the_cart_value_subtracts_the_line_discount(): void
    {
        $customer = $this->customer();
        $this->cartLine([
            'customer_id' => $customer->id, 'product_id' => $this->product()->id,
            'price' => 500, 'discount' => 50, 'quantity' => 3,
        ], hoursAgo: 10);

        $this->assertSame(1350.0, $this->service->findAbandoned(now: $this->now)[0]['cart_value']);
    }

    // ---- the carts that must NOT be emailed ----

    public function test_a_cart_still_being_used_is_left_alone(): void
    {
        $customer = $this->customer();
        $this->cartLine(['customer_id' => $customer->id, 'product_id' => $this->product()->id], hoursAgo: 1);

        $this->assertCount(0, $this->service->findAbandoned(now: $this->now), 'one hour idle is not abandonment');
    }

    public function test_a_cart_older_than_the_window_is_left_alone(): void
    {
        $customer = $this->customer();
        $this->cartLine(['customer_id' => $customer->id, 'product_id' => $this->product()->id], hoursAgo: 200);

        $this->assertCount(0, $this->service->findAbandoned(now: $this->now), 'past the window the prices are stale');
    }

    /** The expensive mistake: nagging somebody who has already paid. */
    public function test_a_customer_who_ordered_after_abandoning_is_not_chased(): void
    {
        $customer = $this->customer();
        $this->cartLine(['customer_id' => $customer->id, 'product_id' => $this->product()->id], hoursAgo: 10);

        $order = Order::create(['customer_id' => $customer->id, 'is_guest' => 0]);
        Order::where('id', $order->id)->update(['created_at' => $this->now->copy()->subHours(2)]);

        $this->assertCount(0, $this->service->findAbandoned(now: $this->now));
    }

    /** An order that predates the abandonment says nothing about this cart. */
    public function test_an_older_order_does_not_suppress_the_reminder(): void
    {
        $customer = $this->customer();
        $this->cartLine(['customer_id' => $customer->id, 'product_id' => $this->product()->id], hoursAgo: 10);

        $order = Order::create(['customer_id' => $customer->id, 'is_guest' => 0]);
        Order::where('id', $order->id)->update(['created_at' => $this->now->copy()->subHours(50)]);

        $this->assertCount(1, $this->service->findAbandoned(now: $this->now));
    }

    /** Re-running the command must be a no-op, not a second email. */
    public function test_a_group_already_reminded_at_this_stage_is_skipped(): void
    {
        $customer = $this->customer();
        $this->cartLine(['customer_id' => $customer->id, 'product_id' => $this->product()->id], hoursAgo: 10);

        AbandonedCartReminder::create([
            'cart_group_id' => 'grp-1', 'customer_id' => $customer->id, 'is_guest' => false,
            'email' => 'buyer@example.com', 'stage' => 1, 'sent_at' => $this->now->copy()->subHour(),
        ]);

        $this->assertCount(0, $this->service->findAbandoned(stage: 1, now: $this->now));
        $this->assertCount(1, $this->service->findAbandoned(stage: 2, now: $this->now), 'a later stage is a different message');
    }

    public function test_a_cart_whose_product_was_deactivated_is_skipped(): void
    {
        $customer = $this->customer();
        $this->cartLine(['customer_id' => $customer->id, 'product_id' => $this->product(['status' => 0])->id], hoursAgo: 10);

        $this->assertCount(0, $this->service->findAbandoned(now: $this->now), 'the email would link to a dead product');
    }

    public function test_a_customer_without_a_usable_email_is_skipped(): void
    {
        $customer = $this->customer(email: 'not-an-address');
        $this->cartLine(['customer_id' => $customer->id, 'product_id' => $this->product()->id], hoursAgo: 10);

        $this->assertCount(0, $this->service->findAbandoned(now: $this->now));
    }

    public function test_a_cart_below_the_minimum_value_is_skipped(): void
    {
        $this->enable([AbandonedCartService::SETTING_MIN_VALUE => 5000]);
        $customer = $this->customer();
        $this->cartLine(['customer_id' => $customer->id, 'product_id' => $this->product()->id], hoursAgo: 10);

        $this->assertCount(0, $this->service->findAbandoned(now: $this->now));
    }

    // ---- guests are a separate, explicit decision ----

    public function test_a_guest_cart_is_not_emailed_by_default(): void
    {
        $guestId = 90210;
        ShippingAddress::create(['customer_id' => $guestId, 'is_guest' => 1, 'email' => 'guest@example.com']);
        $this->cartLine([
            'customer_id' => $guestId, 'is_guest' => 1, 'product_id' => $this->product()->id,
        ], hoursAgo: 10);

        $this->assertCount(0, $this->service->findAbandoned(now: $this->now), 'a checkout form is not a subscription');
    }

    public function test_a_guest_cart_is_emailed_once_the_store_owner_opts_in(): void
    {
        $this->enable([AbandonedCartService::SETTING_INCLUDE_GUESTS => 1]);
        $guestId = 90210;
        ShippingAddress::create(['customer_id' => $guestId, 'is_guest' => 1, 'email' => 'guest@example.com']);
        $this->cartLine([
            'customer_id' => $guestId, 'is_guest' => 1, 'product_id' => $this->product()->id,
        ], hoursAgo: 10);

        $found = $this->service->findAbandoned(now: $this->now);

        $this->assertCount(1, $found);
        $this->assertSame('guest@example.com', $found[0]['email']);
        $this->assertTrue($found[0]['is_guest']);
        $this->assertNull($found[0]['customer_id'], 'a guest id is not a user id and must not be stored as one');
    }

    public function test_a_guest_who_never_reached_the_checkout_form_has_nobody_to_email(): void
    {
        $this->enable([AbandonedCartService::SETTING_INCLUDE_GUESTS => 1]);
        $this->cartLine([
            'customer_id' => 90210, 'is_guest' => 1, 'product_id' => $this->product()->id,
        ], hoursAgo: 10);

        $this->assertCount(0, $this->service->findAbandoned(now: $this->now));
    }

    // ---- the feature switch itself ----

    public function test_the_feature_is_off_until_it_is_switched_on(): void
    {
        \App\Models\BusinessSetting::where('type', AbandonedCartService::SETTING_ENABLED)->delete();
        cache()->flush();

        $this->assertFalse((new AbandonedCartService())->isEnabled(), 'an upgrade must never start emailing on its own');
    }

    public function test_the_maximum_age_can_never_fall_below_the_idle_threshold(): void
    {
        $this->enable([
            AbandonedCartService::SETTING_IDLE_HOURS => 48,
            AbandonedCartService::SETTING_MAX_AGE_HOURS => 6,
        ]);
        $service = new AbandonedCartService();

        // Otherwise the window is inverted and the query silently matches nothing at all.
        $this->assertGreaterThan($service->idleHours(), $service->maxAgeHours());
    }

    public function test_each_vendor_group_is_counted_on_its_own(): void
    {
        $customer = $this->customer();
        $this->cartLine(['customer_id' => $customer->id, 'cart_group_id' => 'grp-a', 'product_id' => $this->product()->id], hoursAgo: 10);
        $this->cartLine(['customer_id' => $customer->id, 'cart_group_id' => 'grp-b', 'product_id' => $this->product()->id], hoursAgo: 10);

        $found = $this->service->findAbandoned(now: $this->now);

        $this->assertCount(2, $found, 'a cart split across two vendors is two groups');
        $this->assertEqualsCanonicalizing(['grp-a', 'grp-b'], $found->pluck('cart_group_id')->all());
    }
}
