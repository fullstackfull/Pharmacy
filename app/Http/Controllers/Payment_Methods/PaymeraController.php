<?php

namespace App\Http\Controllers\Payment_Methods;

use App\Models\PaymentRequest;
use App\Services\Monitoring\Support\Redactor;
use App\Traits\Processor;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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

    /** Why the credentials could not be read, when they could not. Never carries a value. */
    private ?string $configurationGap = null;

    public function __construct(PaymentRequest $payment)
    {
        $config = $this->payment_config('paymera', 'payment_config');

        if (is_null($config)) {
            $this->configurationGap = 'no addon_settings row for key_name=paymera settings_type=payment_config';
        } elseif ($config->mode === 'live') {
            $this->values = json_decode($config->live_values);
            $this->baseUrl = self::LIVE_BASE_URL;
            $this->configurationGap = $this->gapIn($this->values, 'live_values');
        } elseif ($config->mode === 'test') {
            $this->values = json_decode($config->test_values);
            $this->baseUrl = self::TEST_BASE_URL;
            $this->configurationGap = $this->gapIn($this->values, 'test_values');
        } else {
            // The row exists and neither branch matched, so nothing was ever loaded. This is the
            // one an operator cannot guess at: the credentials ARE saved, under the mode that is
            // not switched on.
            $this->configurationGap = 'mode is ' . var_export($config->mode, true) . ', expected "live" or "test"';
        }

        $this->payment = $payment;
    }

    /**
     * Which credential is missing from the mode in force — by NAME, never by value.
     *
     * "Not configured" was one reason covering four faults: no row at all, a mode that matches
     * neither branch, unreadable JSON, and a blank field. They send an operator to four different
     * places, and the log has to be able to tell them apart without ever printing a token.
     */
    private function gapIn(?object $values, string $column): ?string
    {
        if (!is_object($values)) {
            return $column . ' is empty or not valid JSON';
        }

        $missing = array_values(array_filter(
            ['terminal_id', 'username', 'token'],
            static fn (string $field): bool => empty($values->$field ?? null),
        ));

        return $missing === [] ? null : $column . ' has no ' . implode(', ', $missing);
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
            return $this->paymentFailed($data, 'gateway_not_configured: ' . $this->configurationGap);
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
            return $this->paymentFailed($data, 'create_payment_unreachable: ' . class_basename($e));
        }

        $json = $response->json();
        if (is_array($json) && (int) ($json['ErrorCode'] ?? -1) === 0 && !empty($json['Data']['url'])) {
            // Remember eGate's own payment id so the callback can query its status.
            $additional = json_decode($data->additional_data, true) ?: [];
            $additional['paymera_payment_id'] = $json['Data']['paymentId'] ?? null;
            $this->payment::where(['id' => $data->id])->update(['additional_data' => json_encode($additional)]);

            return redirect($json['Data']['url']);
        }

        return $this->paymentFailed($data, $this->refusal('create_payment', $response->status(), $json));
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
            return $this->paymentFailed($data, empty($paymeraId)
                ? 'no_paymera_payment_id'
                : 'gateway_not_configured: ' . $this->configurationGap);
        }

        try {
            $response = Http::withBasicAuth((string) $this->values->username, (string) $this->values->token)
                ->acceptJson()->timeout(30)
                ->get($this->baseUrl . '/api/get-payment-status/' . $paymeraId);
        } catch (\Throwable $e) {
            // Not a decline: the status could not be read, so the payment's fate is unknown and the
            // hook stays unfired. Still said out loud, because an unknown fate needs chasing.
            return $this->paymentFailed($data, 'status_unreachable: ' . class_basename($e), fireHook: false);
        }

        $json = $response->json();
        $status = is_array($json) ? ($json['Data']['status'] ?? null) : null;

        if ($status === 'A') {   // Accepted — the only success state
            $rrn = $json['Data']['rrn'] ?? null;

            // Atomic finalize. callbackURL == triggerURL, so Paymera's server trigger and the customer's
            // browser return both hit this GET, and the get-payment-status call above (up to 30s) widens
            // the window where both entrants have read is_paid=0. A plain update()+hook would then fire
            // the money-moving success hook (wallet credit / order create) twice. The conditional
            // where('is_paid', 0) update is atomic at the row level: exactly one concurrent caller gets
            // affected===1 and fires the hook; the loser sees 0 and returns success without re-firing.
            $affected = $this->payment::where(['id' => $data->id])->where('is_paid', 0)->update([
                'payment_method' => 'paymera',
                'is_paid' => 1,
                'transaction_id' => $rrn,
            ]);

            $paid = $this->payment::where(['id' => $data->id])->first();
            if ($affected === 1 && function_exists($paid->success_hook)) {
                call_user_func($paid->success_hook, $paid);
            }

            return $this->payment_response($paid, 'success');
        }

        // 'F' failed / 'C' canceled are terminal failures; 'P' pending (or anything else) is left open
        // (not marked, no hook) so a later status check could still complete it.
        return $this->paymentFailed(
            $data,
            $this->refusal('get_payment_status', $response->status(), $json, $status),
            fireHook: in_array($status, ['F', 'C'], true),
        );
    }

    private function isConfigured(): bool
    {
        return $this->configurationGap === null;
    }

    private function lang(): string
    {
        return in_array(app()->getLocale(), ['ar', 'sy'], true) ? 'ar' : 'en';
    }

    /**
     * What eGate actually said, in a form that is safe to keep.
     *
     * Every field here is remote text: ErrorCode and Message come straight off the wire, so they go
     * through the same Redactor the rest of this system stores strings through, and are bounded.
     * The status letter is eGate's own single-character verdict.
     */
    private function refusal(string $call, int $httpStatus, mixed $json, ?string $status = null): string
    {
        $parts = [$call, 'http=' . $httpStatus];

        if ($status !== null) {
            $parts[] = 'status=' . $status;
        }
        if (is_array($json)) {
            $parts[] = 'code=' . (string) ($json['ErrorCode'] ?? '?');
            if (!empty($json['Message'])) {
                $parts[] = 'msg=' . (string) $json['Message'];
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Fail the payment, and say why.
     *
     * The reason used to be discarded on all four failure paths, so a merchant saw "payment failed"
     * and nothing in the system could tell a missing terminal id from a declined card from an
     * unreachable gateway — three faults with three different people to call. It now reaches two
     * places: the log, for whoever is looking now, and the failure hook, which carries it into the
     * payments section as the attempt's failure_reason.
     *
     * failure_reason is set on the in-memory model only. payment_requests has no such column, and
     * this controller finalizes with an explicit update() rather than save(), so nothing tries to
     * persist it.
     */
    private function paymentFailed(PaymentRequest $data, string $reason, bool $fireHook = true): Redirector|RedirectResponse|Application
    {
        $reason = Str::limit(app(Redactor::class)->text($reason), 180, '');

        Log::warning('Paymera payment failed', [
            'payment_id' => $data->id,
            'reason' => $reason,
            'hook_fired' => $fireHook,
        ]);

        $data->failure_reason = $reason;

        // payment_method is only stamped on the SUCCESS path, so a refusal reached the failure hook
        // with whatever the row was created with — usually nothing — and every failed Paymera
        // payment was recorded against the gateway "unknown". In memory only: the row is not marked
        // paid and must not be made to look finalized.
        $data->payment_method = 'paymera';

        if ($fireHook && function_exists($data->failure_hook)) {
            call_user_func($data->failure_hook, $data);
        }

        return $this->payment_response($data, 'fail');
    }
}
