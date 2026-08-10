<?php

namespace Tests\Feature;

use App\Enums\GlobalConstant;
use App\Http\Requests\Admin\PaymentMethodUpdateRequest;
use App\Models\Setting;
use App\Traits\PaymentGatewayTrait;
use App\Utils\Helpers;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Paymera is registered the built-in way: its key is in the gateway lists (so it shows at checkout on
 * web AND app), it declares a supported currency (so the admin can enable it), and its credential
 * fields have validation rules (so they are actually saved — the store writes only validated() fields,
 * meaning an unruled credential would be silently dropped).
 */
class PaymeraGatewayTest extends TestCase
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
        Schema::dropIfExists('addon_settings');
        Schema::create('addon_settings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key_name')->nullable();
            $t->text('live_values')->nullable();
            $t->text('test_values')->nullable();
            $t->string('settings_type')->nullable();
            $t->string('mode')->nullable();
            $t->integer('is_active')->default(0);
            $t->text('additional_data')->nullable();
            $t->timestamps();
        });

        Setting::create([
            'key_name' => 'paymera',
            'settings_type' => 'payment_config',
            'mode' => 'test',
            'is_active' => 0,
            'live_values' => ['gateway' => 'paymera', 'mode' => 'live', 'status' => '0', 'terminal_id' => '', 'username' => '', 'token' => ''],
            'test_values' => ['gateway' => 'paymera', 'mode' => 'test', 'status' => '0', 'terminal_id' => '', 'username' => '', 'token' => ''],
            'additional_data' => null,
        ]);
    }

    public function test_paymera_is_in_both_default_gateway_lists(): void
    {
        // Helpers list feeds both the web checkout and the Flutter config endpoint.
        $this->assertContains('paymera', Helpers::getDefaultPaymentGateways());
        // GlobalConstant list feeds the admin payment-method screen.
        $this->assertContains('paymera', GlobalConstant::DEFAULT_PAYMENT_GATEWAYS);
    }

    public function test_the_existing_gateways_were_only_appended_to_not_reordered(): void
    {
        // Flutter safety: the pre-existing keys must remain, in order, at the front.
        $expectedPrefix = ['ssl_commerz', 'paypal', 'stripe', 'razor_pay', 'paystack', 'senang_pay',
            'paymob_accept', 'flutterwave', 'paytm', 'paytabs', 'liqpay', 'mercadopago', 'bkash'];
        $actual = Helpers::getDefaultPaymentGateways();
        $this->assertSame($expectedPrefix, array_slice($actual, 0, count($expectedPrefix)));
    }

    public function test_paymera_declares_a_supported_currency_so_it_can_be_enabled(): void
    {
        $trait = new class { use PaymentGatewayTrait; };
        $currencies = $trait->getPaymentGatewaySupportedCurrencies();
        $this->assertArrayHasKey('paymera', $currencies);
        $this->assertArrayHasKey('SYP', $currencies['paymera']);
    }

    public function test_the_admin_config_list_includes_the_seeded_paymera_row(): void
    {
        $keys = Setting::whereIn('settings_type', ['payment_config'])
            ->whereIn('key_name', Helpers::getDefaultPaymentGateways())->pluck('key_name')->toArray();
        $this->assertContains('paymera', $keys);
    }

    public function test_the_admin_save_rules_require_the_paymera_credentials(): void
    {
        // Bind the request before resolving: resolving a FormRequest runs its validation, so the input
        // (with every required field) must be in place first.
        $this->app->instance('request', \Illuminate\Http\Request::create(
            '/admin/third-party/payment-method/addon-payment-set', 'PUT',
            ['gateway' => 'paymera', 'mode' => 'test', 'status' => '1', 'terminal_id' => 'x', 'username' => 'u', 'token' => 't'],
        ));

        $rules = app(PaymentMethodUpdateRequest::class)->rules();

        // Only fields with rules survive validated() → these three MUST have rules or they never save.
        $this->assertArrayHasKey('terminal_id', $rules);
        $this->assertArrayHasKey('username', $rules);
        $this->assertArrayHasKey('token', $rules);
        $this->assertSame('required', $rules['terminal_id']);
    }
}
