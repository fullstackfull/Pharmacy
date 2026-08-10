<?php

namespace App\Http\Controllers\Payment_Methods;

use App\Models\PaymentRequest;
use App\Traits\Processor;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/**
 * Paymera eGate — hosted payment page gateway (built-in gateway, same convention as the others).
 *
 * A redirect gateway exactly like Paystack/SSLCommerz: create-payment returns a hosted URL we send the
 * user to; the user pays (card + OTP) on Paymera's page; Paymera calls our triggerURL server-side and
 * returns the user to our callbackURL; we then read get-payment-status (the source of truth) and mark
 * the PaymentRequest paid/failed, firing the row's own success/failure hook — so this one controller
 * serves order payment, wallet top-up and order-edit-due without knowing which is which.
 *
 * Credentials (8-char Terminal ID + Basic-Auth username + token) live in addon_settings under
 * key_name 'paymera', read via Processor::payment_config(); they are server-side only. eGate infers the
 * currency from the provisioned terminal, so we send an integer amount (no decimals) and no currency
 * field — matching the spec.
 */
class PaymeraController extends Controller
{
    use Processor;

    private const TEST_BASE_URL = 'https://egate-t.paymera.cc';

    private const LIVE_BASE_URL = 'https://egate.paymera.cc';

    private PaymentRequest $payment;

    private ?object $values = null;

    private string $baseUrl = self::TEST_BASE_URL;

    public function __construct(PaymentRequest $payment)
    {
        $config = $this->payment_config('paymera', 'payment_config');
        if (!is_null($config) && $config->mode == 'live') {
            $this->values = json_decode($config->live_values);
            $this->baseUrl = self::LIVE_BASE_URL;
        } elseif (!is_null($config) && $config->mode == 'test') {
            $this->values = json_decode($config->test_values);
            $this->baseUrl = self::TEST_BASE_URL;
        }

        $this->payment = $payment;
    }

    /**
     * Create the payment on eGate and redirect the customer to the hosted card-entry page.
     */
    public function index(Request $request): JsonResponse|Redirector|RedirectResponse|Application
    {
        $validator = Validator::make($request->all(), ['payment_id' => 'required|uuid']);
        if ($validator->fails()) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_400, null, $this->error_processor($validator)), 400);
        }

        $data = $this->payment::where(['id' => $request['payment_id']])->where(['is_paid' => 0])->first();
        if (!isset($data)) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }

        if (!$this->isConfigured()) {
            return $this->paymentFailed($data);
        }

        $callbackUrl = route('paymera.callback', ['payment_id' => $data->id]);
        $body = [
            'lang' => $this->lang(),
            'terminalId' => (string) $this->values->terminal_id,
            'amount' => (int) round((float) $data->payment_amount),   // eGate wants an integer, no decimals
            'callbackURL' => $callbackUrl,
            'triggerURL' => $callbackUrl,
            'notes' => 'Payment ' . $data->id,
        ];

        try {
            $response = Http::withBasicAuth((string) $this->values->username, (string) $this->values->token)
                ->acceptJson()->timeout(30)
                ->post($this->baseUrl . '/api/create-payment', $body);
        } catch (\Throwable $e) {
            return $this->paymentFailed($data);
        }

        $json = $response->json();
        if (is_array($json) && (int) ($json['ErrorCode'] ?? -1) === 0 && !empty($json['Data']['url'])) {
            // Remember eGate's own payment id so the callback can query its status.
            $additional = json_decode($data->additional_data, true) ?: [];
            $additional['paymera_payment_id'] = $json['Data']['paymentId'] ?? null;
            $this->payment::where(['id' => $data->id])->update(['additional_data' => json_encode($additional)]);

            return redirect($json['Data']['url']);
        }

        return $this->paymentFailed($data);
    }

    /**
     * Return point (and server trigger). Reads get-payment-status — the authoritative result — and
     * finalizes. Idempotent: an already-paid request short-circuits to success.
     */
    public function callback(Request $request): Redirector|RedirectResponse|Application
    {
        $data = $this->payment::where(['id' => $request['payment_id']])->first();
        if (!isset($data)) {
            return redirect()->route('payment-fail');
        }
        if ((int) $data->is_paid === 1) {
            return $this->payment_response($data, 'success');
        }

        $additional = json_decode($data->additional_data, true) ?: [];
        $paymeraId = $additional['paymera_payment_id'] ?? null;
        if (empty($paymeraId) || !$this->isConfigured()) {
            return $this->paymentFailed($data);
        }

        try {
            $response = Http::withBasicAuth((string) $this->values->username, (string) $this->values->token)
                ->acceptJson()->timeout(30)
                ->get($this->baseUrl . '/api/get-payment-status/' . $paymeraId);
        } catch (\Throwable $e) {
            return $this->paymentFailed($data, fireHook: false);
        }

        $json = $response->json();
        $status = is_array($json) ? ($json['Data']['status'] ?? null) : null;

        if ($status === 'A') {   // Accepted — the only success state
            $rrn = $json['Data']['rrn'] ?? null;
            $this->payment::where(['id' => $data->id])->update([
                'payment_method' => 'paymera',
                'is_paid' => 1,
                'transaction_id' => $rrn,
            ]);
            $paid = $this->payment::where(['id' => $data->id])->first();
            if (function_exists($paid->success_hook)) {
                call_user_func($paid->success_hook, $paid);
            }

            return $this->payment_response($paid, 'success');
        }

        // 'F' failed / 'C' canceled are terminal failures; 'P' pending (or anything else) is left open
        // (not marked, no hook) so a later status check could still complete it.
        return $this->paymentFailed($data, fireHook: in_array($status, ['F', 'C'], true));
    }

    private function isConfigured(): bool
    {
        return $this->values
            && !empty($this->values->terminal_id)
            && !empty($this->values->username)
            && !empty($this->values->token);
    }

    private function lang(): string
    {
        return in_array(app()->getLocale(), ['ar', 'sy'], true) ? 'ar' : 'en';
    }

    private function paymentFailed(PaymentRequest $data, bool $fireHook = true): Redirector|RedirectResponse|Application
    {
        if ($fireHook && function_exists($data->failure_hook)) {
            call_user_func($data->failure_hook, $data);
        }

        return $this->payment_response($data, 'fail');
    }
}
