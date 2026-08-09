<?php

namespace Tests\Feature;

use App\Mail\AbandonedCartReminderMail;
use App\Models\AbandonedCartReminder;
use App\Models\BusinessSetting;
use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use App\Services\Retention\AbandonedCartService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The sending half: the command, the ledger, and the recovery link (Phase 2.4).
 *
 * Mail::fake() throughout — the point of these tests is that the *right* number of messages are
 * attempted, to the right addresses, exactly once.
 */
class AbandonedCartSendingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['carts', 'products', 'sellers', 'currencies', 'users', 'shipping_addresses', 'orders', 'abandoned_cart_reminders', 'business_settings', 'translations', 'reviews'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $t) {
            $t->id(); $t->string('type')->nullable(); $t->text('value')->nullable(); $t->timestamps();
        });
        Schema::create('translations', function (Blueprint $t) {
            $t->id(); $t->string('translationable_type'); $t->unsignedBigInteger('translationable_id');
            $t->string('locale', 20); $t->string('key'); $t->text('value')->nullable();
        });
        Schema::create('reviews', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('delivery_man_id')->nullable();
            $t->integer('rating')->default(0); $t->boolean('status')->default(1); $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('added_by')->default('admin');
            $t->unsignedBigInteger('user_id')->nullable(); $t->unsignedBigInteger('brand_id')->nullable();
            $t->string('product_type')->default('physical'); $t->boolean('status')->default(1);
            $t->string('request_status')->default('1'); $t->decimal('unit_price', 24, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('sellers', function (Blueprint $t) {
            $t->id(); $t->string('status')->default('approved'); $t->timestamps();
        });
        // The message prints money, so rendering it reaches webCurrencyConverter() -> loadCurrency().
        Schema::create('currencies', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('symbol', 20)->default('$');
            $t->string('code', 20)->default('USD'); $t->decimal('exchange_rate', 24, 8)->default(1);
            $t->boolean('status')->default(1); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('f_name')->nullable(); $t->string('l_name')->nullable();
            $t->string('email')->nullable(); $t->timestamps();
        });
        Schema::create('shipping_addresses', function (Blueprint $t) {
            $t->id(); $t->string('customer_id', 15)->nullable(); $t->boolean('is_guest')->default(0);
            $t->string('email')->nullable(); $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('customer_id')->nullable();
            $t->boolean('is_guest')->default(0); $t->timestamps();
        });
        Schema::create('carts', function (Blueprint $t) {
            $t->id(); $t->string('customer_id')->nullable(); $t->string('cart_group_id')->nullable();
            $t->unsignedBigInteger('product_id'); $t->integer('quantity')->default(1);
            $t->decimal('price', 24, 2)->default(0); $t->decimal('discount', 24, 2)->default(0);
            $t->string('name')->nullable(); $t->string('thumbnail')->nullable();
            $t->boolean('is_guest')->default(0); $t->boolean('is_checked')->default(1); $t->timestamps();
        });
        Schema::create('abandoned_cart_reminders', function (Blueprint $t) {
            $t->id(); $t->string('cart_group_id', 191); $t->unsignedBigInteger('customer_id')->nullable();
            $t->boolean('is_guest')->default(0); $t->string('email');
            $t->unsignedTinyInteger('stage')->default(1); $t->decimal('cart_value', 24, 2)->default(0);
            $t->unsignedInteger('item_count')->default(0); $t->timestamp('sent_at')->nullable();
            $t->timestamp('clicked_at')->nullable(); $t->timestamp('recovered_at')->nullable();
            $t->string('recovered_order_id', 191)->nullable(); $t->timestamps();
            $t->unique(['cart_group_id', 'stage']);
        });

        Mail::fake();
        $this->switchOn();
    }

    private function switchOn(int $status = 1): void
    {
        foreach ([
            AbandonedCartService::SETTING_ENABLED => $status,
            AbandonedCartService::SETTING_INCLUDE_GUESTS => 0,
            AbandonedCartService::SETTING_IDLE_HOURS => 6,
            AbandonedCartService::SETTING_MAX_AGE_HOURS => 168,
            AbandonedCartService::SETTING_MIN_VALUE => 0,
        ] as $type => $value) {
            BusinessSetting::updateOrCreate(['type' => $type], ['value' => $value]);
        }
        cache()->flush();
    }

    private function defaultCurrency(): void
    {
        $currency = \App\Models\Currency::create([
            'name' => 'Syrian Pound', 'symbol' => 'S', 'code' => 'SYP', 'exchange_rate' => 1, 'status' => 1,
        ]);
        BusinessSetting::updateOrCreate(['type' => 'system_default_currency'], ['value' => $currency->id]);
        session()->forget('system_default_currency_info');
    }

    /** An abandoned cart belonging to a real customer, idle for a day. */
    private function abandonedCart(string $groupId = 'grp-1', string $email = 'buyer@example.com'): User
    {
        $customer = User::create(['f_name' => 'Sara', 'l_name' => 'K', 'email' => $email]);
        $product = Product::create([
            'name' => 'Serum', 'added_by' => 'admin', 'product_type' => 'physical',
            'status' => 1, 'request_status' => '1', 'unit_price' => 500,
        ]);
        $cart = Cart::create([
            'customer_id' => $customer->id, 'cart_group_id' => $groupId, 'product_id' => $product->id,
            'quantity' => 2, 'price' => 500, 'discount' => 0, 'name' => 'Serum', 'is_guest' => 0,
        ]);
        $at = Carbon::now()->subDay();
        Cart::where('id', $cart->id)->update(['created_at' => $at, 'updated_at' => $at]);

        return $customer;
    }

    // ---- the safety rails ----

    public function test_the_command_sends_nothing_while_the_feature_is_off(): void
    {
        $this->switchOn(status: 0);
        $this->abandonedCart();

        $this->artisan('cart:remind-abandoned')
            ->expectsOutputToContain('switched off')
            ->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(0, AbandonedCartReminder::count());
    }

    public function test_a_dry_run_lists_the_recipients_and_sends_nothing(): void
    {
        $this->abandonedCart();

        $this->artisan('cart:remind-abandoned --dry-run')
            ->expectsOutputToContain('Nothing was emailed')
            ->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(0, AbandonedCartReminder::count(), 'a dry run must not write to the ledger either');
    }

    /** The console output is logged and screen-shared; addresses do not belong in it in full. */
    public function test_the_dry_run_masks_the_address(): void
    {
        $this->abandonedCart(email: 'buyer@example.com');

        $this->artisan('cart:remind-abandoned --dry-run')
            ->doesntExpectOutputToContain('buyer@example.com')
            ->assertSuccessful();
    }

    // ---- sending for real ----

    public function test_it_sends_one_message_and_records_it(): void
    {
        $this->abandonedCart();

        $this->artisan('cart:remind-abandoned')->assertSuccessful();

        Mail::assertSent(AbandonedCartReminderMail::class, 1);
        Mail::assertSent(AbandonedCartReminderMail::class, fn ($mail) => $mail->hasTo('buyer@example.com'));

        $reminder = AbandonedCartReminder::sole();
        $this->assertSame('grp-1', $reminder->cart_group_id);
        $this->assertSame(1, $reminder->stage);
        $this->assertSame(1000.0, $reminder->cart_value);
        $this->assertSame(2, $reminder->item_count);
        $this->assertNotNull($reminder->sent_at);
    }

    /** A cron that fires twice, or a retried run, must not mean two emails. */
    public function test_running_it_again_sends_nothing_further(): void
    {
        $this->abandonedCart();

        $this->artisan('cart:remind-abandoned')->assertSuccessful();
        $this->artisan('cart:remind-abandoned')->assertSuccessful();

        Mail::assertSent(AbandonedCartReminderMail::class, 1);
        $this->assertSame(1, AbandonedCartReminder::count());
    }

    public function test_the_limit_caps_the_run_and_says_so(): void
    {
        $this->abandonedCart(groupId: 'grp-a', email: 'a@example.com');
        $this->abandonedCart(groupId: 'grp-b', email: 'b@example.com');
        $this->abandonedCart(groupId: 'grp-c', email: 'c@example.com');

        $this->artisan('cart:remind-abandoned --limit=2')
            ->expectsOutputToContain('3 carts matched')
            ->assertSuccessful();

        Mail::assertSent(AbandonedCartReminderMail::class, 2);
    }

    public function test_a_later_stage_is_a_separate_message(): void
    {
        $this->abandonedCart();

        $this->artisan('cart:remind-abandoned --stage=1')->assertSuccessful();
        $this->artisan('cart:remind-abandoned --stage=2')->assertSuccessful();

        Mail::assertSent(AbandonedCartReminderMail::class, 2);
        $this->assertEqualsCanonicalizing([1, 2], AbandonedCartReminder::pluck('stage')->all());
    }

    // ---- the message itself ----

    /**
     * Mail::fake() never renders the view, so every test above would pass with a template that
     * throws. It did throw: `company_web_logo` is an array ({key, path, status}), and concatenating
     * it into a path — which is what one of 6Valley's own email templates does — is an "Array to
     * string conversion" that takes the whole message down. This test is why that was found.
     */
    public function test_the_message_actually_renders(): void
    {
        $this->abandonedCart();
        BusinessSetting::updateOrCreate(['type' => 'company_web_logo'], ['value' => json_encode(['key' => 'logo.png', 'path' => null, 'status' => 404])]);
        BusinessSetting::updateOrCreate(['type' => 'company_name'], ['value' => 'Pharmacy']);
        $this->defaultCurrency();
        cache()->flush();

        $reminder = new AbandonedCartReminder([
            'cart_group_id' => 'grp-1', 'customer_id' => 1, 'is_guest' => false,
            'email' => 'buyer@example.com', 'stage' => 1, 'cart_value' => 1000, 'item_count' => 2,
        ]);
        $reminder->id = 1;

        $html = (new AbandonedCartReminderMail($reminder, 'https://example.test/cart/recover/1?signature=x'))->render();

        $this->assertStringContainsString('cart/recover/1', $html, 'the recovery link must survive into the markup');
        $this->assertStringContainsString('Serum', $html, 'the cart lines must be listed');
    }

    /**
     * The direction must come from the store's language configuration, not a guessed list of
     * language codes. This store registers Arabic under the code "sy", so `in_array($lang, ['ar'])`
     * would lay an Arabic message out left-to-right.
     */
    public function test_the_message_is_rtl_when_the_default_language_is(): void
    {
        BusinessSetting::updateOrCreate(['type' => 'language'], ['value' => json_encode([
            ['id' => '1', 'name' => 'english', 'direction' => 'ltr', 'code' => 'en', 'status' => 1, 'default' => false],
            ['id' => 2, 'name' => 'Arabic', 'direction' => 'rtl', 'code' => 'sy', 'status' => 1, 'default' => true],
        ])]);
        $this->defaultCurrency();
        cache()->flush();

        $reminder = new AbandonedCartReminder([
            'cart_group_id' => 'grp-1', 'email' => 'buyer@example.com', 'cart_value' => 1000, 'item_count' => 1,
        ]);
        $reminder->id = 1;

        $html = (new AbandonedCartReminderMail($reminder, 'https://example.test/x'))->render();

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('lang="sy"', $html);
    }

    // ---- the recovery link ----

    public function test_the_recovery_link_records_the_click_once(): void
    {
        $this->abandonedCart();
        $this->artisan('cart:remind-abandoned')->assertSuccessful();
        $reminder = AbandonedCartReminder::sole();

        $url = URL::signedRoute('cart.recover', ['reminder' => $reminder->id]);

        $this->get($url)->assertRedirect();
        $firstClick = $reminder->fresh()->clicked_at;
        $this->assertNotNull($firstClick);

        $this->travel(2)->minutes();
        $this->get($url)->assertRedirect();

        $this->assertEquals(
            $firstClick->timestamp,
            $reminder->fresh()->clicked_at->timestamp,
            'opening the mail twice is not two recoveries'
        );
    }

    /** Without the signature the id in the URL would be walkable. */
    public function test_an_unsigned_recovery_link_is_refused(): void
    {
        $this->abandonedCart();
        $this->artisan('cart:remind-abandoned')->assertSuccessful();
        $reminder = AbandonedCartReminder::sole();

        $this->get('/cart/recover/' . $reminder->id)->assertForbidden();
        $this->assertNull($reminder->fresh()->clicked_at);
    }

    public function test_a_tampered_signature_is_refused(): void
    {
        $this->abandonedCart();
        $this->artisan('cart:remind-abandoned')->assertSuccessful();
        $reminder = AbandonedCartReminder::sole();

        $url = URL::signedRoute('cart.recover', ['reminder' => $reminder->id]);

        $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();
        $this->assertNull($reminder->fresh()->clicked_at);
    }

    /**
     * The conversion has to be recorded at the moment the cart rows are deleted — after that the
     * link between the reminder and the order cannot be reconstructed.
     */
    public function test_placing_the_order_marks_the_reminder_recovered(): void
    {
        $this->abandonedCart();
        $this->artisan('cart:remind-abandoned')->assertSuccessful();
        $reminder = AbandonedCartReminder::sole();
        $this->assertNull($reminder->recovered_at);

        Schema::create('cart_shippings', function (Blueprint $t) {
            $t->id(); $t->string('cart_group_id')->nullable(); $t->timestamps();
        });
        session(['order_id' => '100123']);

        \App\Utils\CartManager::cartCleanByCartGroupIds(['grp-1']);

        $reminder->refresh();
        $this->assertNotNull($reminder->recovered_at);
        $this->assertSame('100123', $reminder->recovered_order_id);
    }

    /** Reporting must never be able to fail an order. */
    public function test_a_missing_ledger_table_does_not_break_order_placement(): void
    {
        Schema::dropIfExists('abandoned_cart_reminders');
        Schema::create('cart_shippings', function (Blueprint $t) {
            $t->id(); $t->string('cart_group_id')->nullable(); $t->timestamps();
        });

        \App\Utils\CartManager::cartCleanByCartGroupIds(['grp-1']);

        $this->assertTrue(true, 'clearing the cart completed without the reminders table');
    }

    public function test_a_link_for_a_deleted_reminder_lands_on_the_home_page(): void
    {
        $url = URL::signedRoute('cart.recover', ['reminder' => 999999]);

        $this->get($url)->assertRedirect(route('home'));
    }
}
