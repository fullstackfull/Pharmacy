<?php

namespace Tests\Feature;

use App\Models\DeliverySyriaGovernorateRate;
use App\Services\DeliverySyria\DeliverySyriaClient;
use App\Services\DeliverySyria\DeliverySyriaConfigService;
use App\Services\DeliverySyria\DeliverySyriaRateCalculator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The outbound courier client (all HTTP faked — no real network) and the rate estimator.
 *
 * What is pinned: verification caches the governorate price list and a re-sync updates it in place
 * rather than duplicating; the parcel call carries the Secret as a Bearer header and the platform
 * header, and reads back wallet_status (not just success); a disabled integration makes no outbound
 * call at all; and the courier's own error (a duplicate invoice) is surfaced verbatim.
 */
class DeliverySyriaClientTest extends TestCase
{
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
        Schema::dropIfExists('delivery_syria_governorate_rates');
        Schema::create('delivery_syria_governorate_rates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('code')->unique();
            $t->string('governorate');
            $t->decimal('base_cost', 24, 2)->default(0);
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
        });
        Cache::flush();
    }

    private function enable(): void
    {
        app(DeliverySyriaConfigService::class)->save(['status' => 1, 'secret_token' => 'sk_test', 'webhook_token' => 'wh_test']);
    }

    public function test_verify_syncs_the_governorate_price_list_and_sends_the_secret(): void
    {
        $this->enable();
        Http::fake([
            '*/api/secret/cheack' => Http::response([
                'message' => 'Done',
                'data' => [
                    ['id' => 1, 'governorate' => 'Damascus', 'base_cost' => '10.00', 'code' => 2939],
                    ['id' => 2, 'governorate' => 'Aleppo', 'base_cost' => '15.00', 'code' => 2946],
                ],
            ], 200),
        ]);

        $result = app(DeliverySyriaClient::class)->verifyAndSyncRates();

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['synced']);
        $this->assertDatabaseCount('delivery_syria_governorate_rates', 2);
        $this->assertSame('10.00', DeliverySyriaGovernorateRate::where('code', 2939)->value('base_cost'));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/secret/cheack') && $request['secret'] === 'sk_test');
    }

    public function test_resync_updates_rates_in_place_instead_of_duplicating(): void
    {
        $this->enable();
        DeliverySyriaGovernorateRate::create(['code' => 2939, 'governorate' => 'Damascus', 'base_cost' => 10]);

        Http::fake([
            '*/api/secret/cheack' => Http::response([
                'message' => 'Done',
                'data' => [['id' => 1, 'governorate' => 'Damascus', 'base_cost' => '12.50', 'code' => 2939]],
            ], 200),
        ]);

        app(DeliverySyriaClient::class)->verifyAndSyncRates();

        $this->assertDatabaseCount('delivery_syria_governorate_rates', 1);
        $this->assertSame('12.50', DeliverySyriaGovernorateRate::where('code', 2939)->value('base_cost'));
    }

    public function test_create_parcel_carries_auth_headers_and_reads_wallet_status(): void
    {
        $this->enable();
        Http::fake([
            '*/api/parcel/create-with-security' => Http::response([
                'success' => true,
                'message' => 'Parcel successfully added.',
                'parcel_id' => 26,
                'tracking_number' => 'TRK-1786263220-3552',
                'wallet_status' => 'deducted',
                'calculation' => ['company_type' => 'delivery', 'calculated_delivery_charge' => 270],
            ], 200),
        ]);

        $result = app(DeliverySyriaClient::class)->createParcel(['invoice_no' => '100002', 'weight' => '3']);

        $this->assertTrue($result['success']);
        $this->assertSame('deducted', $result['wallet_status']);
        $this->assertSame(26, $result['parcel_id']);
        $this->assertSame('TRK-1786263220-3552', $result['tracking_number']);
        $this->assertSame(270, $result['calculation']['calculated_delivery_charge']);

        Http::assertSent(fn ($request) => $request->hasHeader('X-Platform', 'pharmacyesyria')
            && $request->hasHeader('Authorization', 'Bearer sk_test'));
    }

    public function test_a_disabled_integration_makes_no_outbound_call(): void
    {
        // not enabled
        Http::fake();

        $verify = app(DeliverySyriaClient::class)->verifyAndSyncRates();
        $create = app(DeliverySyriaClient::class)->createParcel(['invoice_no' => '1']);

        $this->assertFalse($verify['success']);
        $this->assertFalse($create['success']);
        Http::assertNothingSent();
    }

    public function test_a_duplicate_invoice_error_is_surfaced(): void
    {
        $this->enable();
        Http::fake([
            '*/api/parcel/create-with-security' => Http::response([
                'success' => false,
                'message' => 'Duplicate invoice_no: 100002',
            ], 200),
        ]);

        $result = app(DeliverySyriaClient::class)->createParcel(['invoice_no' => '100002']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Duplicate', $result['message']);
    }

    public function test_rate_estimate_is_weight_times_base_cost_and_null_when_unknown(): void
    {
        DeliverySyriaGovernorateRate::create(['code' => 2939, 'governorate' => 'Damascus', 'base_cost' => 10]);
        $calc = app(DeliverySyriaRateCalculator::class);

        $this->assertSame(30.0, $calc->estimate(3.0, 2939));
        $this->assertNull($calc->estimate(3.0, 9999), 'an unsynced governorate has no estimate');
    }
}
