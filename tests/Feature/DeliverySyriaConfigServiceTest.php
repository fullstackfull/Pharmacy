<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Services\DeliverySyria\DeliverySyriaConfigService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The config service is the only reader/writer of the `delivery_syria` business setting.
 *
 * Two properties matter and are pinned here: the secrets are encrypted at rest (a deliberate deviation
 * from the codebase's plaintext norm, required by the courier's own security note), and a blank secret
 * on save means "keep the current one" — so re-saving the panel without re-typing the token cannot
 * silently wipe a working integration.
 */
class DeliverySyriaConfigServiceTest extends TestCase
{
    private DeliverySyriaConfigService $config;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('business_settings');
        Schema::create('business_settings', function (Blueprint $t) {
            $t->id();
            $t->string('type')->nullable();
            $t->text('value')->nullable();
            $t->timestamps();
        });
        Cache::flush();

        $this->config = app(DeliverySyriaConfigService::class);
    }

    public function test_save_persists_status_and_secret_and_reads_them_back(): void
    {
        $this->config->save(['status' => 1, 'secret_token' => 'sk_live_123', 'webhook_token' => 'wh_abc']);

        $fresh = app(DeliverySyriaConfigService::class);
        $this->assertTrue($fresh->isEnabled());
        $this->assertSame('sk_live_123', $fresh->secretToken());
        $this->assertSame('wh_abc', $fresh->webhookToken());
        $this->assertTrue($fresh->isReadyForOutbound());
        $this->assertTrue($fresh->isReadyForInbound());
    }

    public function test_secrets_are_encrypted_at_rest(): void
    {
        $this->config->save(['status' => 1, 'secret_token' => 'sk_plaintext_should_not_appear', 'webhook_token' => 'wh_plaintext_secret']);

        $stored = BusinessSetting::where('type', 'delivery_syria')->value('value');

        $this->assertStringNotContainsString('sk_plaintext_should_not_appear', $stored, 'the outbound secret must not be stored in clear');
        $this->assertStringNotContainsString('wh_plaintext_secret', $stored, 'the webhook token must not be stored in clear');
        // but it decrypts back correctly
        $this->assertSame('sk_plaintext_should_not_appear', app(DeliverySyriaConfigService::class)->secretToken());
    }

    public function test_a_blank_secret_on_save_keeps_the_existing_one(): void
    {
        $this->config->save(['status' => 1, 'secret_token' => 'sk_keep_me', 'webhook_token' => 'wh_keep']);

        // Re-save (e.g. toggling a default) without re-typing the secrets.
        app(DeliverySyriaConfigService::class)->save(['status' => 1, 'secret_token' => '', 'webhook_token' => null, 'hub_id' => '7']);

        $fresh = app(DeliverySyriaConfigService::class);
        $this->assertSame('sk_keep_me', $fresh->secretToken(), 'a blank secret must not wipe the stored one');
        $this->assertSame('wh_keep', $fresh->webhookToken());
        $this->assertSame('7', $fresh->get('hub_id'));
    }

    public function test_readiness_requires_both_enabled_and_the_relevant_token(): void
    {
        // enabled but no secret -> not ready for outbound
        $this->config->save(['status' => 1]);
        $this->assertFalse(app(DeliverySyriaConfigService::class)->isReadyForOutbound());

        // has secret but disabled -> not ready
        app(DeliverySyriaConfigService::class)->save(['status' => 0, 'secret_token' => 'sk_x', 'webhook_token' => 'wh_x']);
        $ready = app(DeliverySyriaConfigService::class);
        $this->assertFalse($ready->isReadyForOutbound());
        $this->assertFalse($ready->isReadyForInbound());
    }

    public function test_base_url_and_platform_fall_back_to_documented_defaults(): void
    {
        $this->assertSame('https://deliverysyria.com', $this->config->baseUrl());
        $this->assertSame('pharmacyesyria', $this->config->platform());
        $this->assertSame('deliverysyria', DeliverySyriaConfigService::INBOUND_PLATFORM);
    }
}
