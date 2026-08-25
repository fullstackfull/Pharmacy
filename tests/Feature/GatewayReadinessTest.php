<?php

namespace Tests\Feature;

use App\Services\Payments\GatewayReadiness;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A gateway that is switched on and cannot take a payment.
 *
 * Credentials live as two separate blobs, `live_values` and `test_values`, and every controller
 * reads only the one matching the row's `mode`. So a shop can show a green, fully filled-in gateway
 * on the admin screen and refuse every payment at checkout — the keys were typed into the mode that
 * is switched off, and nothing on the form could ever say so.
 */
class GatewayReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('addon_settings');
        Schema::create('addon_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key_name', 191);
            $table->string('settings_type', 191);
            $table->string('mode', 20)->nullable();
            $table->boolean('is_active')->default(false);
            $table->text('live_values')->nullable();
            $table->text('test_values')->nullable();
            $table->timestamps();
        });
    }

    private function gateway(array $attributes): void
    {
        DB::table('addon_settings')->insert($attributes + [
            'settings_type' => 'payment_config',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_fully_configured_live_gateway_is_ready(): void
    {
        $this->gateway([
            'key_name' => 'stripe', 'mode' => 'live', 'is_active' => true,
            'live_values' => json_encode(['gateway' => 'stripe', 'mode' => 'live', 'api_key' => 'k', 'secret' => 's']),
        ]);

        $this->assertSame([], app(GatewayReadiness::class)->broken());
    }

    /** The whole reason this check exists. */
    public function test_credentials_saved_under_the_mode_that_is_switched_off_are_named_as_the_fault(): void
    {
        $this->gateway([
            'key_name' => 'paymera', 'mode' => 'live', 'is_active' => true,
            'live_values' => json_encode(['gateway' => 'paymera', 'mode' => 'live', 'api_key' => '', 'secret' => '']),
            'test_values' => json_encode(['gateway' => 'paymera', 'mode' => 'test', 'api_key' => 'k', 'secret' => 's']),
        ]);

        $broken = app(GatewayReadiness::class)->broken();

        $this->assertCount(1, $broken);
        $this->assertSame('paymera', $broken[0]['gateway']);
        $this->assertStringContainsString('test_values does', $broken[0]['verdict']);
    }

    /** A gateway nobody can reach is not an outage. */
    public function test_a_switched_off_gateway_is_not_reported_as_broken(): void
    {
        $this->gateway([
            'key_name' => 'paypal', 'mode' => 'live', 'is_active' => false,
            'live_values' => json_encode(['api_key' => '']),
        ]);

        $this->assertSame([], app(GatewayReadiness::class)->broken());
    }

    /**
     * Not a fault, and worth saying: a shop is legitimately in test mode while being set up, but it
     * is the difference between taking money and rehearsing, and the checkout does not say which.
     */
    public function test_a_correctly_configured_test_gateway_is_reported_as_rehearsing(): void
    {
        $this->gateway([
            'key_name' => 'sslcommerz', 'mode' => 'test', 'is_active' => true,
            'test_values' => json_encode(['store_id' => 'x', 'store_password' => 'y']),
        ]);

        $this->assertSame([], app(GatewayReadiness::class)->broken());
        $this->assertSame(['sslcommerz'], app(GatewayReadiness::class)->rehearsing());
    }

    public function test_a_mode_no_column_matches_is_named_rather_than_passed(): void
    {
        $this->gateway([
            'key_name' => 'broken', 'mode' => '', 'is_active' => true,
            'live_values' => json_encode(['api_key' => 'k']),
        ]);

        $broken = app(GatewayReadiness::class)->broken();

        $this->assertCount(1, $broken);
        $this->assertStringContainsString('no column is read', $broken[0]['verdict']);
    }
}
