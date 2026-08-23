<?php

namespace Tests\Feature;

use App\Http\Controllers\Payment_Methods\PaymeraController;
use App\Models\PaymentRequest;
use App\Models\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Paymera hosted-page flow (all eGate HTTP faked).
 *
 * Pinned: create-payment is called server-side with Basic Auth + the terminal id + an INTEGER amount
 * (eGate rejects decimals), and the customer is redirected to the returned hosted URL; the callback
 * reads get-payment-status (the source of truth) and marks the request paid ONLY on status "A", storing
 * the bank rrn as the transaction id; "F"/"C"/"P" never mark it paid; and an already-paid request is
 * idempotent. success/failure hooks fire through the same $data->success_hook seam every gateway uses.
 */
class PaymeraPaymentFlowTest extends TestCase
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
        Schema::dropIfExists('payment_requests');
        Schema::create('payment_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->decimal('payment_amount', 21, 12)->nullable();
            $t->string('currency_code')->nullable();
            $t->string('payment_method')->nullable();
            $t->integer('is_paid')->default(0);
            $t->string('transaction_id')->nullable();
            $t->text('additional_data')->nullable();
            $t->text('payer_information')->nullable();
            $t->text('receiver_information')->nullable();
            $t->string('success_hook')->nullable();
            $t->string('failure_hook')->nullable();
            $t->string('external_redirect_link')->nullable();
            $t->string('payment_platform')->nullable();
            $t->string('attribute')->nullable();
            $t->string('attribute_id')->nullable();
            $t->string('payer_id')->nullable();
            $t->string('receiver_id')->nullable();
            $t->timestamps();
        });
        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('transaction_ref')->nullable();
            $t->timestamps();
        });
        Cache::flush();

        $this->configurePaymera('99990001', 'egate_user', 'egate_token');
    }

    private function configurePaymera(string $terminalId, string $username, string $token): void
    {
        Setting::updateOrCreate(
            ['key_name' => 'paymera', 'settings_type' => 'payment_config'],
            [
                'key_name' => 'paymera',
                'settings_type' => 'payment_config',
                'mode' => 'test',
                'is_active' => 1,
                'live_values' => ['gateway' => 'paymera', 'mode' => 'live', 'status' => '1', 'terminal_id' => $terminalId, 'username' => $username, 'token' => $token],
                'test_values' => ['gateway' => 'paymera', 'mode' => 'test', 'status' => '1', 'terminal_id' => $terminalId, 'username' => $username, 'token' => $token],
                'additional_data' => null,
            ],
        );
    }

    private function newController(): PaymeraController
    {
        // Construct fresh so it re-reads the current addon_settings config.
        return new PaymeraController(new PaymentRequest());
    }

    private function seedPayment(array $overrides = []): PaymentRequest
    {
        return PaymentRequest::create(array_merge([
            'payment_amount' => 1860000,
            'currency_code' => 'SYP',
            'is_paid' => 0,
            'payment_platform' => 'web',
            'external_redirect_link' => 'https://store.test/return',
            'success_hook' => '',
            'failure_hook' => '',
            'additional_data' => json_encode([]),
            'payer_information' => json_encode(['email' => 'c@test.com']),
        ], $overrides));
    }

    public function test_index_creates_the_payment_and_redirects_to_the_hosted_page(): void
    {
        $payment = $this->seedPayment(['payment_amount' => 1860000.4]);   // eGate must receive an integer
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success', 'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.cc/start/pid-1/en', 'paymentId' => 'pid-1'],
            ], 200),
        ]);

        $response = $this->newController()->index(Request::create('/payment/paymera/pay', 'GET', ['payment_id' => (string) $payment->id]));

        $this->assertSame('https://egate-t.paymera.cc/start/pid-1/en', $response->getTargetUrl());
        // eGate payment id is remembered for the callback.
        $this->assertSame('pid-1', json_decode(PaymentRequest::find($payment->id)->additional_data, true)['paymera_payment_id']);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/api/create-payment')
                && $request['terminalId'] === '99990001'
                && $request['amount'] === 1860000               // integer, decimals dropped
                && str_contains($request['callbackURL'], '/payment/paymera/callback')
                && str_starts_with($request->header('Authorization')[0], 'Basic ');
        });
    }

    public function test_index_without_credentials_makes_no_call_and_fails(): void
    {
        $this->configurePaymera('', '', '');   // unconfigured
        $payment = $this->seedPayment();
        Http::fake();

        $response = $this->newController()->index(Request::create('/payment/paymera/pay', 'GET', ['payment_id' => (string) $payment->id]));

        Http::assertNothingSent();
        $this->assertStringContainsString('flag=fail', $response->getTargetUrl());
        $this->assertSame(0, (int) PaymentRequest::find($payment->id)->is_paid);
    }

    public function test_callback_marks_paid_only_on_accepted_status(): void
    {
        $payment = $this->seedPayment(['additional_data' => json_encode(['paymera_payment_id' => 'pid-1'])]);
        Http::fake([
            '*/api/get-payment-status/*' => Http::response([
                'ErrorMessage' => 'Success', 'ErrorCode' => 0,
                'Data' => ['status' => 'A', 'rrn' => '000009876543', 'amount' => 1860000, 'terminalId' => '99990001'],
            ], 200),
        ]);

        $response = $this->newController()->callback(Request::create('/payment/paymera/callback', 'GET', ['payment_id' => (string) $payment->id]));

        $fresh = PaymentRequest::find($payment->id);
        $this->assertSame(1, (int) $fresh->is_paid);
        $this->assertSame('000009876543', $fresh->transaction_id);   // bank rrn
        $this->assertSame('paymera', $fresh->payment_method);
        $this->assertStringContainsString('flag=success', $response->getTargetUrl());
    }

    public function test_callback_does_not_mark_paid_on_failed_status(): void
    {
        $payment = $this->seedPayment(['additional_data' => json_encode(['paymera_payment_id' => 'pid-2'])]);
        Http::fake([
            '*/api/get-payment-status/*' => Http::response([
                'ErrorMessage' => 'Failed', 'ErrorCode' => 100, 'Data' => ['status' => 'F'],
            ], 200),
        ]);

        $response = $this->newController()->callback(Request::create('/payment/paymera/callback', 'GET', ['payment_id' => (string) $payment->id]));

        $this->assertSame(0, (int) PaymentRequest::find($payment->id)->is_paid);
        $this->assertStringContainsString('flag=fail', $response->getTargetUrl());
    }

    public function test_callback_leaves_a_pending_status_unpaid(): void
    {
        $payment = $this->seedPayment(['additional_data' => json_encode(['paymera_payment_id' => 'pid-3'])]);
        Http::fake([
            '*/api/get-payment-status/*' => Http::response([
                'ErrorMessage' => 'Pending', 'ErrorCode' => 0, 'Data' => ['status' => 'P'],
            ], 200),
        ]);

        $this->newController()->callback(Request::create('/payment/paymera/callback', 'GET', ['payment_id' => (string) $payment->id]));

        $this->assertSame(0, (int) PaymentRequest::find($payment->id)->is_paid);
    }

    public function test_callback_is_idempotent_for_an_already_paid_request(): void
    {
        $payment = $this->seedPayment(['is_paid' => 1, 'transaction_id' => 'RRN-1']);
        Http::fake();

        $response = $this->newController()->callback(Request::create('/payment/paymera/callback', 'GET', ['payment_id' => (string) $payment->id]));

        Http::assertNothingSent();   // no second status check
        $this->assertStringContainsString('flag=success', $response->getTargetUrl());
    }

    // ---- the non-atomic finalize -> double credit fix (stabilization P1) ----

    public function test_the_conditional_finalize_claims_the_row_exactly_once(): void
    {
        // The mechanism the fix relies on: callbackURL == triggerURL, so two entrants can both read
        // is_paid=0. The atomic where('is_paid',0)->update() lets exactly one win.
        $payment = $this->seedPayment(['additional_data' => json_encode(['paymera_payment_id' => 'pid-race'])]);

        $first = PaymentRequest::where('id', $payment->id)->where('is_paid', 0)->update(['is_paid' => 1]);
        $second = PaymentRequest::where('id', $payment->id)->where('is_paid', 0)->update(['is_paid' => 1]);

        $this->assertSame(1, $first, 'the first finalize claims the row');
        $this->assertSame(0, $second, 'a concurrent second finalize claims nothing');
    }

    public function test_the_success_hook_fires_only_once_across_repeated_callbacks(): void
    {
        PaymeraHookSpy::$successCalls = 0;
        $payment = $this->seedPayment([
            'additional_data' => json_encode(['paymera_payment_id' => 'pid-hook']),
            'success_hook' => 'Tests\\Feature\\paymera_spy_success_hook',
        ]);
        Http::fake([
            '*/api/get-payment-status/*' => Http::response([
                'ErrorMessage' => 'Success', 'ErrorCode' => 0,
                'Data' => ['status' => 'A', 'rrn' => 'RRN-ONCE'],
            ], 200),
        ]);

        // Two deliveries of the same accepted result (browser return + server trigger / a refresh).
        $this->newController()->callback(Request::create('/payment/paymera/callback', 'GET', ['payment_id' => (string) $payment->id]));
        $this->newController()->callback(Request::create('/payment/paymera/callback', 'GET', ['payment_id' => (string) $payment->id]));

        $this->assertSame(1, (int) PaymentRequest::find($payment->id)->is_paid);
        $this->assertSame(1, PaymeraHookSpy::$successCalls, 'the money-moving success hook must fire exactly once');
    }

    public function test_a_refusal_carries_egates_own_reason_into_the_record(): void
    {
        // The four failure paths used to return the same blank "payment failed", so a missing
        // terminal id, a declined card and an unreachable gateway were indistinguishable — three
        // faults with three different people to call.
        $payment = $this->seedPayment([
            'payment_amount' => 1860000.4,
            'failure_hook' => 'Tests\\Feature\\paymera_spy_failure_hook',
        ]);
        Http::fake([
            '*/api/create-payment' => Http::response(['ErrorCode' => 17, 'Message' => 'Invalid terminal'], 200),
        ]);

        PaymeraHookSpy::$lastFailure = null;
        $this->newController()->index(Request::create('/payment/paymera/pay', 'GET', ['payment_id' => (string) $payment->id]));

        $captured = PaymeraHookSpy::$lastFailure;
        $this->assertNotNull($captured, 'the failure hook did not fire');
        $this->assertStringContainsString('create_payment', $captured['failure_reason']);
        $this->assertStringContainsString('code=17', $captured['failure_reason']);
        $this->assertStringContainsString('Invalid terminal', $captured['failure_reason']);

        // And the attempt is still legible to whoever reads the record. The hook casts what it is
        // handed with (array), which on an Eloquent model drops every attribute — so every failure
        // was being recorded as gateway "unknown" for an amount of 0.
        $this->assertSame('paymera', $captured['payment_method']);
        $this->assertSame(1860000.4, (float) $captured['payment_amount']);
    }

    public function test_a_gateway_with_no_credentials_says_so_rather_than_declining(): void
    {
        $this->configurePaymera('', '', '');
        $payment = $this->seedPayment(['failure_hook' => 'Tests\\Feature\\paymera_spy_failure_hook']);
        Http::fake();

        PaymeraHookSpy::$lastFailure = null;
        $this->newController()->index(Request::create('/payment/paymera/pay', 'GET', ['payment_id' => (string) $payment->id]));

        $this->assertSame('gateway_not_configured', PaymeraHookSpy::$lastFailure['failure_reason']);
        Http::assertNothingSent();
    }

    public function test_an_unreadable_status_is_not_recorded_as_a_decline(): void
    {
        // The customer may well have paid. Calling this a decline would be a claim nobody checked.
        $payment = $this->seedPayment([
            'failure_hook' => 'Tests\\Feature\\paymera_spy_failure_hook',
            'additional_data' => json_encode(['paymera_payment_id' => 'pid-1']),
        ]);
        Http::fake(['*/api/get-payment-status/*' => fn () => throw new \RuntimeException('connection timed out')]);

        PaymeraHookSpy::$lastFailure = null;
        $this->newController()->callback(Request::create('/payment/paymera/callback', 'GET', ['payment_id' => (string) $payment->id]));

        $this->assertNull(PaymeraHookSpy::$lastFailure, 'an unknown fate must not fire the failure hook');
        $this->assertSame(0, (int) PaymentRequest::find($payment->id)->is_paid);
    }
}

/**
 * Observable stand-in for a money-moving success hook (wallet credit / order create), so the test can
 * count how many times it fired.
 */
class PaymeraHookSpy
{
    public static int $successCalls = 0;

    /** @var array<string, mixed>|null what the controller handed the failure hook */
    public static ?array $lastFailure = null;
}

function paymera_spy_success_hook($payment): void
{
    PaymeraHookSpy::$successCalls++;
}

function paymera_spy_failure_hook($paymentData): void
{
    PaymeraHookSpy::$lastFailure = [
        'failure_reason' => $paymentData['failure_reason'] ?? null,
        'payment_method' => $paymentData['payment_method'] ?? null,
        'payment_amount' => $paymentData['payment_amount'] ?? null,
    ];
}
