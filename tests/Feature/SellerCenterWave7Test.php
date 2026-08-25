<?php

namespace Tests\Feature;

use App\Models\Seller;
use App\Models\SellerWebhook;
use App\Services\Marketplace\SellerIntegrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Wave 7's definition of done: the decisions about a shop's integrations belong to one service.
 *
 * Keys and webhooks existed only on the phone, which is the wrong device for the work — nobody
 * wires up an ERP on a handset — and the rules that make them safe lived inline in the API
 * controller. Writing a second client is exactly the moment those rules stop being one controller's
 * private business: two implementations of "which destinations may this platform be made to dial"
 * is one implementation and one hole.
 *
 * So these tests assert the rules on the service both clients call, not on either client. If the
 * panel and the API ever disagree, it will be because somebody stopped calling this — and that is
 * a much louder failure than a drifted copy.
 */
class SellerCenterWave7Test extends TestCase
{
    private const SELLER = 1;

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
        ]);
    }

    private function integrations(): SellerIntegrationService
    {
        return app(SellerIntegrationService::class);
    }

    /** @return array<string, mixed> */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'name' => 'ERP',
            // A literal public address, so the guard the dispatcher runs before every dial reaches
            // its verdict without needing DNS in the test environment.
            'url' => 'https://198.51.100.7/hook',
            'events' => ['order.placed'],
        ], $overrides);
    }

    public function test_an_endpoint_over_plain_http_is_refused(): void
    {
        $result = $this->integrations()->createWebhook(self::SELLER, $this->input(['url' => 'http://198.51.100.7/hook']));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('url', $result['errors']);
        $this->assertSame(0, SellerWebhook::count());
    }

    public function test_an_endpoint_pointed_at_the_internal_network_is_refused_before_it_is_stored(): void
    {
        // Validation can only say the string looks like an https URL. Whether it resolves to the
        // metadata service or an internal admin panel is a question about the address, and it is
        // asked here rather than on the first delivery.
        $result = $this->integrations()->createWebhook(self::SELLER, $this->input(['url' => 'https://169.254.169.254/latest/meta-data/']));

        $this->assertFalse($result['ok']);
        $this->assertSame(0, SellerWebhook::count());
    }

    public function test_an_event_the_platform_does_not_raise_cannot_be_subscribed_to(): void
    {
        $result = $this->integrations()->createWebhook(self::SELLER, $this->input(['events' => ['order.teleported']]));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('events.0', $result['errors']);
    }

    public function test_creating_an_endpoint_is_recorded_with_where_it_points(): void
    {
        $result = $this->integrations()->createWebhook(self::SELLER, $this->input());

        $this->assertTrue($result['ok']);

        $entry = DB::table('audit_logs')->where('action', 'integration.webhook_created')->first();

        $this->assertNotNull($entry);
        $this->assertStringContainsString('198.51.100.7', (string) $entry->after);
    }

    public function test_the_signing_secret_is_returned_once_and_never_presented_again(): void
    {
        $result = $this->integrations()->createWebhook(self::SELLER, $this->input());

        $this->assertNotEmpty($result['secret']);
        // Every delivery is signed with it, so a screen that could show it again would make the
        // signature worth nothing.
        $this->assertArrayNotHasKey('secret', $this->integrations()->present($result['webhook']));
    }

    public function test_a_repoint_records_both_addresses(): void
    {
        $webhook = $this->integrations()->createWebhook(self::SELLER, $this->input())['webhook'];

        $this->integrations()->updateWebhook($webhook, $this->input(['url' => 'https://203.0.113.9/hook']));

        $entry = DB::table('audit_logs')->where('action', 'integration.webhook_repointed')->first();

        // A repoint is the one webhook change that matters to a fraud review, and "the endpoint
        // changed" without the two addresses is not a finding.
        $this->assertStringContainsString('198.51.100.7', (string) $entry->before);
        $this->assertStringContainsString('203.0.113.9', (string) $entry->after);
    }

    public function test_a_rewritten_endpoint_starts_clean(): void
    {
        $webhook = $this->integrations()->createWebhook(self::SELLER, $this->input())['webhook'];
        $webhook->forceFill([
            'status' => SellerWebhook::STATUS_DISABLED,
            'consecutive_failures' => 10,
            'disabled_at' => now(),
            'disabled_reason' => 'stopped answering',
        ])->save();

        $this->integrations()->updateWebhook($webhook, $this->input(['url' => 'https://203.0.113.9/hook']));

        // The endpoint that was failing is not the endpoint that now exists.
        $this->assertSame(SellerWebhook::STATUS_ACTIVE, $webhook->fresh()->status);
        $this->assertSame(0, (int) $webhook->fresh()->consecutive_failures);
        $this->assertNull($webhook->fresh()->disabled_reason);
    }

    public function test_only_the_two_states_that_belong_to_the_seller_are_settable(): void
    {
        $webhook = $this->integrations()->createWebhook(self::SELLER, $this->input())['webhook'];

        $this->assertTrue($this->integrations()->setWebhookStatus($webhook, SellerWebhook::STATUS_PAUSED)['ok']);
        // Disabled is the marketplace's answer to an endpoint that stopped answering. A seller
        // setting it directly would be claiming a verdict the platform reached, not a preference.
        $this->assertFalse($this->integrations()->setWebhookStatus($webhook, SellerWebhook::STATUS_DISABLED)['ok']);
        $this->assertSame(SellerWebhook::STATUS_PAUSED, $webhook->fresh()->status);
    }

    public function test_an_endpoint_nothing_has_been_sent_to_does_not_get_a_green_tick(): void
    {
        $webhook = $this->integrations()->createWebhook(self::SELLER, $this->input())['webhook'];

        $this->assertTrue($this->integrations()->present($webhook)['never_called']);

        $webhook->forceFill(['last_success_at' => now()])->save();

        $this->assertFalse($this->integrations()->present($webhook->fresh())['never_called']);
    }

    public function test_a_test_delivery_is_queued_the_same_way_a_real_one_is(): void
    {
        Queue::fake();

        $webhook = $this->integrations()->createWebhook(self::SELLER, $this->input())['webhook'];
        $result = $this->integrations()->queueTest($webhook, 'order.placed');

        $this->assertTrue($result['ok']);
        Queue::assertPushed(\App\Jobs\DeliverSellerWebhook::class);
        $this->assertSame(['test' => true], $result['delivery']->payload);
    }

    public function test_a_test_of_an_event_that_does_not_exist_queues_nothing(): void
    {
        Queue::fake();

        $webhook = $this->integrations()->createWebhook(self::SELLER, $this->input())['webhook'];

        $this->assertFalse($this->integrations()->queueTest($webhook, 'order.teleported')['ok']);
        Queue::assertNothingPushed();
    }

    public function test_removing_an_endpoint_leaves_the_record_of_what_it_was_sent(): void
    {
        $webhook = $this->integrations()->createWebhook(self::SELLER, $this->input())['webhook'];

        DB::table('seller_webhook_deliveries')->insert([
            'webhook_id' => $webhook->id,
            'seller_id' => self::SELLER,
            'event' => 'order.placed',
            'payload' => json_encode(['order_id' => 7]),
            'status' => 'delivered',
            'attempts' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->integrations()->deleteWebhook($webhook);

        $this->assertSame(0, SellerWebhook::count());
        // Removing the endpoint does not un-send what went to it.
        $this->assertSame(1, DB::table('seller_webhook_deliveries')->count());
        $this->assertNotNull(DB::table('audit_logs')->where('action', 'integration.webhook_deleted')->first());
    }
}
