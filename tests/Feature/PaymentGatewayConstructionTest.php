<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A gateway controller must not fatal because its configuration is malformed.
 *
 * These constructors read the credential blob matching the row's `mode`, and a row whose mode is
 * neither "live" nor "test" left the blob unread — so the next line threw. In a constructor, which
 * Laravel runs whenever it gathers a route's middleware: one malformed settings row took down
 * `route:list`, every page that enumerates routes, and the gateway's own endpoints.
 *
 * An unusable configuration is a gateway that cannot take a payment, which is what the readiness
 * check reports. It is not a 500 on unrelated pages.
 */
class PaymentGatewayConstructionTest extends TestCase
{
    /** @var array<int, class-string> every gateway that reads a mode-specific credential blob. */
    private const GATEWAYS = [
        \App\Http\Controllers\Payment_Methods\BkashPaymentController::class,
        \App\Http\Controllers\Payment_Methods\FlutterwaveV3Controller::class,
        \App\Http\Controllers\Payment_Methods\LiqPayController::class,
        \App\Http\Controllers\Payment_Methods\MercadoPagoController::class,
        \App\Http\Controllers\Payment_Methods\PaymobController::class,
        \App\Http\Controllers\Payment_Methods\PaypalPaymentController::class,
        \App\Http\Controllers\Payment_Methods\PaystackController::class,
        \App\Http\Controllers\Payment_Methods\PaytabsController::class,
        \App\Http\Controllers\Payment_Methods\PaytmController::class,
        \App\Http\Controllers\Payment_Methods\RazorPayController::class,
        \App\Http\Controllers\Payment_Methods\SenangPayController::class,
        \App\Http\Controllers\Payment_Methods\SslCommerzPaymentController::class,
        \App\Http\Controllers\Payment_Methods\StripePaymentController::class,
    ];

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

    public function test_a_gateway_with_no_configuration_row_still_constructs(): void
    {
        $this->assertEveryGatewayConstructs();
    }

    /** The row that broke route:list: present, switched on, and pointed at no column at all. */
    public function test_a_gateway_whose_mode_matches_no_column_still_constructs(): void
    {
        foreach (self::GATEWAYS as $gateway) {
            DB::table('addon_settings')->insert([
                'key_name' => class_basename($gateway),
                'settings_type' => 'payment_config',
                'mode' => '',
                'is_active' => true,
                'live_values' => json_encode(['api_key' => 'k']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertEveryGatewayConstructs();
    }

    private function assertEveryGatewayConstructs(): void
    {
        foreach (self::GATEWAYS as $gateway) {
            try {
                $this->assertNotNull(app($gateway));
            } catch (\Throwable $exception) {
                $this->fail(class_basename($gateway) . ' fatals on construction: ' . $exception->getMessage());
            }
        }
    }
}
