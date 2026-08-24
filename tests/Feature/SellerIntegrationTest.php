<?php

namespace Tests\Feature;

use App\Models\Seller;
use App\Models\SellerApiKey;
use App\Models\SellerRole;
use App\Models\SellerStaff;
use App\Models\SellerWebhook;
use App\Models\SellerWebhookDelivery;
use App\Services\Marketplace\SellerApiKeyService;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\Marketplace\SellerWebhookDispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Keys a seller issues, and the endpoints they ask us to call.
 *
 * The key tests are about blast radius. A key exists so that an integration which leaks costs the
 * seller what that integration could do rather than the whole shop, and every property that makes
 * that true is asserted here: it is not an owner, it cannot exceed the scopes of whoever issued it,
 * it cannot mint another key, and it stops working the moment the shop does.
 *
 * The webhook tests are about not lying to the seller. A delivery that failed is kept and says why;
 * an endpoint nothing has ever been sent to does not get a green tick; and an endpoint that has
 * stopped answering is switched off rather than retried until the queue is nothing but corpses.
 */
class SellerIntegrationTest extends TestCase
{
    private const SELLER = 1;
    private const RIVAL = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'seller_webhook_deliveries', 'seller_webhooks', 'seller_api_keys',
            'seller_staff', 'seller_roles', 'sellers', 'audit_logs', 'business_settings',
        ] as $table) {
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
        Schema::create('seller_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('name', 120);
            $table->text('permissions')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        Schema::create('seller_staff', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('seller_role_id')->nullable();
            $table->string('name', 120);
            $table->string('email', 191);
            $table->string('password')->nullable();
            $table->text('auth_token')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 30)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 191)->nullable();
            $table->string('action', 60);
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 60)->nullable();
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->text('context')->nullable();
            $table->string('ip_address', 60)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        (require base_path('database/migrations/2026_09_14_000001_create_seller_integration_tables.php'))->up();

        Seller::insert([
            ['id' => self::SELLER, 'f_name' => 'Owner', 'l_name' => 'One', 'status' => 'approved', 'auth_token' => 'owner-token-long-enough-to-clear-the-gate'],
            ['id' => self::RIVAL, 'f_name' => 'Rival', 'l_name' => 'Two', 'status' => 'approved', 'auth_token' => null],
        ]);
    }

    private function keys(): SellerApiKeyService
    {
        return app(SellerApiKeyService::class);
    }

    private function owner(): SellerPrincipal
    {
        return SellerPrincipal::owner(Seller::find(self::SELLER));
    }

    private function issue(array $scopes = ['orders.view'], ?SellerPrincipal $by = null, ?string $expires = null): array
    {
        return $this->keys()->issue($by ?? $this->owner(), 'ERP', $scopes, $expires);
    }

    private function webhook(array $attributes = []): SellerWebhook
    {
        return SellerWebhook::create(array_merge([
            'seller_id' => self::SELLER,
            'name' => 'ERP',
            // A literal public address, so the SSRF guard the dispatcher runs before every dial
            // reaches its verdict without needing DNS in the test environment.
            'url' => 'https://198.51.100.7/hook',
            'events' => ['order.placed'],
            'secret' => 'a-signing-secret',
            'status' => SellerWebhook::STATUS_ACTIVE,
        ], $attributes));
    }

    private function delivery(SellerWebhook $webhook, array $attributes = []): SellerWebhookDelivery
    {
        return SellerWebhookDelivery::create(array_merge([
            'webhook_id' => $webhook->id,
            'seller_id' => self::SELLER,
            'event' => 'order.placed',
            'payload' => ['order_id' => 7],
            'status' => SellerWebhookDelivery::STATUS_PENDING,
        ], $attributes));
    }

    public function test_the_key_itself_is_never_stored(): void
    {
        $issued = $this->issue();

        $this->assertNotEmpty($issued['plaintext']);
        // The row holds a hash and a prefix. Nothing in it is the key.
        $this->assertNotSame($issued['plaintext'], $issued['key']->token_hash);
        $this->assertStringNotContainsString($issued['plaintext'], json_encode($issued['key']->toArray()));
        $this->assertTrue(Hash::check($issued['plaintext'], $issued['key']->token_hash));
    }

    public function test_a_key_is_not_an_owner(): void
    {
        $principal = $this->keys()->resolve($this->issue(['orders.view'])['plaintext']);

        $this->assertNotNull($principal);
        // The single most important property here. Every `isOwner()` in the codebase grants
        // everything, and a key that answered true to it would make its scopes decorative.
        $this->assertFalse($principal->isOwner());
        $this->assertTrue($principal->can('orders.view'));
        $this->assertFalse($principal->can('payouts.request'));
    }

    public function test_a_key_cannot_be_issued_with_more_than_its_issuer_holds(): void
    {
        $role = SellerRole::create(['seller_id' => self::SELLER, 'name' => 'Clerk', 'permissions' => ['orders.view']]);
        $staff = SellerStaff::create([
            'seller_id' => self::SELLER, 'seller_role_id' => $role->id,
            'name' => 'Clerk', 'email' => 'clerk@example.com', 'password' => Hash::make('x'),
        ]);
        $clerk = SellerPrincipal::staff(Seller::find(self::SELLER), $staff, ['orders.view']);

        $issued = $this->issue(['orders.view', 'payouts.request'], by: $clerk);

        // Otherwise issuing a key would be a way around the permission model rather than an
        // expression of it.
        $this->assertSame(['orders.view'], $issued['key']->scopes);
    }

    public function test_a_key_cannot_claim_a_permission_that_does_not_exist(): void
    {
        $this->assertSame(['orders.view'], $this->issue(['orders.view', 'everything.always'])['key']->scopes);
    }

    public function test_a_revoked_key_stops_working_immediately(): void
    {
        $issued = $this->issue();
        $this->keys()->revoke($issued['key'], $this->owner());

        $this->assertNull($this->keys()->resolve($issued['plaintext']));
    }

    public function test_an_expired_key_stops_working(): void
    {
        $issued = $this->issue(['orders.view'], expires: now()->addMinute()->toDateTimeString());
        $this->assertNotNull($this->keys()->resolve($issued['plaintext']));

        $this->travelTo(now()->addMinutes(2));

        $this->assertNull($this->keys()->resolve($issued['plaintext']));
    }

    public function test_a_key_cannot_outlive_its_shops_standing(): void
    {
        $issued = $this->issue();
        Seller::where('id', self::SELLER)->update(['status' => 'suspended']);

        // Checked every request, exactly as a login token is. A key issued while a shop was approved
        // must stop the moment it is not.
        $this->assertNull($this->keys()->resolve($issued['plaintext']));
    }

    public function test_a_key_that_is_not_ours_resolves_to_nobody(): void
    {
        $this->assertNull($this->keys()->resolve('some-other-systems-token-entirely-long-enough'));
        $this->assertNull($this->keys()->resolve('sk_seller_zzzzzz_not-a-key-we-ever-issued-abcdefghijk'));
    }

    public function test_a_key_with_the_right_prefix_and_the_wrong_secret_resolves_to_nobody(): void
    {
        $issued = $this->issue();
        $prefix = $issued['key']->prefix;

        // The prefix only says which row to check. It is not the credential.
        $this->assertNull($this->keys()->resolve('sk_seller_' . $prefix . '_wrong-secret-but-right-length-aaaaaaaaaaaa'));
    }

    public function test_using_a_key_is_recorded_but_reading_the_list_is_not(): void
    {
        $issued = $this->issue();
        $this->assertNull($issued['key']->fresh()->last_used_at);

        $this->keys()->resolve($issued['plaintext']);
        // Resolving alone does not count — the middleware notes real traffic.
        $this->assertNull($issued['key']->fresh()->last_used_at);

        $this->keys()->touch($issued['key'], '10.0.0.1');
        $this->assertNotNull($issued['key']->fresh()->last_used_at);
    }

    public function test_the_endpoint_refuses_a_key_that_tries_to_mint_a_key(): void
    {
        $issued = $this->issue(['shop_settings.manage']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $issued['plaintext'],
            'Accept' => 'application/json',
        ])->postJson('/api/v3/seller/seller-center/integrations/keys', ['name' => 'escalate']);

        $response->assertStatus(403);
        // Past the route gate — it holds the permission — and stopped by the controller, which is
        // the layer that knows a key is not a person.
        $this->assertSame('api_key', data_get($response->json(), 'errors.0.code'));
        $this->assertSame(1, SellerApiKey::count());
    }

    public function test_a_key_cannot_reach_a_route_that_does_not_say_what_it_needs(): void
    {
        $issued = $this->issue(['orders.view', 'products.view']);
        $headers = ['Authorization' => 'Bearer ' . $issued['plaintext'], 'Accept' => 'application/json'];

        // The whole point of scoping a key is that it costs the seller only what it was issued
        // for. Scope enforcement lives on the route, and most of this API predates it — so a key
        // is refused anywhere the route does not declare what it needs, rather than being handed
        // the shop by default. `seller-update` bcrypts a caller-chosen password onto the owner's
        // row and returns a fresh owner token; `account-delete` is unrecoverable.
        foreach ([
            ['put', '/api/v3/seller/seller-update'],
            ['delete', '/api/v3/seller/account-delete'],
            ['post', '/api/v3/seller/balance-withdraw'],
            ['post', '/api/v3/seller/payment-information/update'],
        ] as [$method, $uri]) {
            $response = $this->withHeaders($headers)->{$method . 'Json'}($uri, ['password' => 'taken']);

            $response->assertStatus(403);
            $this->assertContains(
                data_get($response->json(), 'errors.0.code'),
                ['api_key', 'permission', 'owner_only'],
                $uri,
            );
        }
    }

    public function test_taking_the_account_is_refused_to_everybody_but_the_account_holder(): void
    {
        $role = SellerRole::create([
            'seller_id' => self::SELLER,
            'name' => 'Everything',
            // Every permission the catalogue has. None of them is the account itself.
            'permissions' => app(\App\Services\Marketplace\SellerPermissionService::class)->allKeys(),
        ]);
        SellerStaff::create([
            'seller_id' => self::SELLER, 'seller_role_id' => $role->id, 'name' => 'Manager',
            'email' => 'manager@example.com', 'password' => Hash::make('x'),
            'auth_token' => 'staff-token-long-enough-to-clear-the-gate',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer staff-token-long-enough-to-clear-the-gate',
            'Accept' => 'application/json',
        ])->deleteJson('/api/v3/seller/account-delete');

        // A role that could delete the shop would be a role that can take the shop, and an owner
        // granting "manage everything" is not consenting to that.
        $response->assertStatus(403);
        $this->assertSame('owner_only', data_get($response->json(), 'errors.0.code'));
    }

    public function test_a_key_may_still_read_the_integration_list(): void
    {
        $issued = $this->issue(['shop_settings.manage']);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $issued['plaintext'],
            'Accept' => 'application/json',
        ])->getJson('/api/v3/seller/seller-center/integrations/keys')->assertStatus(200);
    }

    public function test_a_webhook_receives_only_the_events_it_asked_for(): void
    {
        Queue::fake();
        $this->webhook(['events' => ['order.placed']]);

        app(SellerWebhookDispatcher::class)->dispatch(self::SELLER, 'order.placed', ['order_id' => 1]);
        app(SellerWebhookDispatcher::class)->dispatch(self::SELLER, 'payout.status_changed', ['payout_request_id' => 1]);

        $this->assertSame(1, SellerWebhookDelivery::count());
        $this->assertSame('order.placed', SellerWebhookDelivery::first()->event);
    }

    public function test_an_empty_subscription_receives_nothing_rather_than_everything(): void
    {
        Queue::fake();
        $this->webhook(['events' => []]);

        app(SellerWebhookDispatcher::class)->dispatch(self::SELLER, 'order.placed', ['order_id' => 1]);

        $this->assertSame(0, SellerWebhookDelivery::count());
    }

    public function test_a_webhook_never_receives_another_shops_events(): void
    {
        Queue::fake();
        $this->webhook();

        app(SellerWebhookDispatcher::class)->dispatch(self::RIVAL, 'order.placed', ['order_id' => 1]);

        $this->assertSame(0, SellerWebhookDelivery::count());
    }

    public function test_an_event_the_platform_does_not_raise_cannot_be_dispatched(): void
    {
        Queue::fake();
        $this->webhook(['events' => ['order.invented']]);

        $this->assertSame(0, app(SellerWebhookDispatcher::class)->dispatch(self::SELLER, 'order.invented', []));
        $this->assertSame(0, SellerWebhookDelivery::count());
    }

    public function test_a_paused_endpoint_is_not_sent_to(): void
    {
        Queue::fake();
        $this->webhook(['status' => SellerWebhook::STATUS_PAUSED]);

        app(SellerWebhookDispatcher::class)->dispatch(self::SELLER, 'order.placed', ['order_id' => 1]);

        $this->assertSame(0, SellerWebhookDelivery::count());
    }

    public function test_a_delivery_carries_a_signature_of_exactly_what_was_sent(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $webhook = $this->webhook();
        $delivery = $this->delivery($webhook);

        app(SellerWebhookDispatcher::class)->attempt($delivery);

        Http::assertSent(function ($request) use ($webhook) {
            $signature = $request->header('X-Seller-Signature')[0] ?? '';

            // Over the exact bytes, so the receiver verifies what it actually got rather than a
            // re-serialisation of it that may differ by a space.
            return $signature === hash_hmac('sha256', $request->body(), $webhook->secret);
        });
    }

    public function test_a_success_clears_the_failure_run(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $webhook = $this->webhook();
        $webhook->forceFill(['consecutive_failures' => 4])->save();

        app(SellerWebhookDispatcher::class)->attempt($this->delivery($webhook));

        // An endpoint that failed twice last week and works now is not on its way to being disabled.
        $this->assertSame(0, $webhook->fresh()->consecutive_failures);
        $this->assertNotNull($webhook->fresh()->last_success_at);
    }

    public function test_a_failed_delivery_says_what_came_back(): void
    {
        Http::fake(['*' => Http::response('upstream exploded', 500)]);
        $webhook = $this->webhook();
        $delivery = $this->delivery($webhook);

        app(SellerWebhookDispatcher::class)->attempt($delivery);

        $delivery->refresh();
        $this->assertSame(500, $delivery->response_code);
        // The code, never the body: persisting what answered would turn the delivery log into a
        // read-back channel for anything the platform's network can reach.
        $this->assertNull($delivery->response_body);
        // Still pending: it has attempts left, and the next one is scheduled rather than immediate.
        $this->assertSame(SellerWebhookDelivery::STATUS_PENDING, $delivery->status);
        $this->assertNotNull($delivery->next_attempt_at);
    }

    public function test_a_delivery_is_given_up_on_rather_than_retried_for_ever(): void
    {
        Http::fake(['*' => Http::response('no', 500)]);
        $webhook = $this->webhook();
        $delivery = $this->delivery($webhook, ['attempts' => SellerWebhookDispatcher::MAX_ATTEMPTS - 1]);

        app(SellerWebhookDispatcher::class)->attempt($delivery);

        $delivery->refresh();
        $this->assertSame(SellerWebhookDelivery::STATUS_FAILED, $delivery->status);
        $this->assertNull($delivery->next_attempt_at);
    }

    public function test_an_endpoint_that_stopped_answering_is_switched_off(): void
    {
        Http::fake(['*' => Http::response('gone', 500)]);
        $webhook = $this->webhook();
        $webhook->forceFill(['consecutive_failures' => SellerWebhook::FAILURE_LIMIT - 1])->save();

        app(SellerWebhookDispatcher::class)->attempt($this->delivery($webhook));

        $webhook->refresh();
        // A queue full of deliveries to a URL that stopped existing months ago is a queue that stops
        // delivering the ones that would have worked.
        $this->assertSame(SellerWebhook::STATUS_DISABLED, $webhook->status);
        $this->assertSame('webhook_disabled_repeated_failures', $webhook->disabled_reason);
    }

    public function test_a_connection_that_never_answered_is_recorded_as_a_failure_not_a_success(): void
    {
        Http::fake(fn () => throw new \RuntimeException('connection refused'));
        $webhook = $this->webhook();
        $delivery = $this->delivery($webhook);

        $this->assertFalse(app(SellerWebhookDispatcher::class)->attempt($delivery));

        $delivery->refresh();
        $this->assertNull($delivery->response_code);
        $this->assertStringContainsString('connection refused', (string) $delivery->error);
        $this->assertSame(1, $webhook->fresh()->consecutive_failures);
    }

    public function test_an_endpoint_repointed_at_the_internal_network_is_not_dialled(): void
    {
        // A URL that passed the check when it was registered can be re-pointed afterwards, so the
        // destination is judged again immediately before the request leaves.
        Http::fake(['*' => Http::response('secrets', 200)]);
        $webhook = $this->webhook(['url' => 'http://169.254.169.254/latest/meta-data/']);
        $delivery = $this->delivery($webhook);

        $this->assertFalse(app(SellerWebhookDispatcher::class)->attempt($delivery));

        Http::assertNothingSent();
        $delivery->refresh();
        $this->assertSame('destination_refused:destination_is_private_or_reserved', $delivery->error);
        $this->assertSame(1, $webhook->fresh()->consecutive_failures);
    }

    public function test_a_seller_never_loses_their_shop_because_their_own_server_is_down(): void
    {
        // The dispatcher is called from inside an order being placed. It must not be able to throw.
        Schema::dropIfExists('seller_webhooks');

        $this->assertSame(0, app(SellerWebhookDispatcher::class)->dispatch(self::SELLER, 'order.placed', []));
    }
}
