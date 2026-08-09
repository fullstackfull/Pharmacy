<?php

namespace Tests\Feature;

use App\Models\PaymentRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Settlement guards on the payment gateways.
 *
 * All three gateways shared one flaw: the gateway's transaction id and the local payment id both
 * arrive as request parameters, and nothing checked that they belonged together. A genuine small
 * payment could therefore settle any other, more expensive order — repeatedly, since nothing
 * checked whether the record was already paid.
 *
 * RazorPay's callback was the worst of them: it never contacted RazorPay at all. It marked an order
 * paid from request parameters alone, so any logged-in customer could receive goods without paying
 * anything.
 *
 * The verification methods are private by design — they are not API — so they are exercised through
 * reflection. Testing them matters more than their visibility: this is the code standing between
 * the store and free orders.
 */
class PaymentSettlementGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('payment_requests');
        Schema::create('payment_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->decimal('payment_amount', 24, 2)->default(0);
            $t->string('currency_code', 10)->nullable();
            $t->boolean('is_paid')->default(0);
            $t->string('transaction_id')->nullable();
            $t->string('payment_method')->nullable();
            $t->timestamps();
        });
    }

    private function paymentRecord(float $amount = 100.00, string $currency = 'BDT', bool $paid = false): PaymentRequest
    {
        return PaymentRequest::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'payment_amount' => $amount,
            'currency_code' => $currency,
            'is_paid' => $paid,
        ]);
    }

    private function callGuard(object $controller, string $method, ...$args): bool
    {
        $reflection = new ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);

        return (bool) $reflection->invoke($controller, ...$args);
    }

    // ---- bKash ----

    private function bkash(): object
    {
        // The constructor reads gateway config from the DB; build without running it, since these
        // tests exercise the verification logic rather than any network call.
        return (new \ReflectionClass(\App\Http\Controllers\Payment_Methods\BkashPaymentController::class))
            ->newInstanceWithoutConstructor();
    }

    public function test_bkash_accepts_a_payment_matching_the_record(): void
    {
        $payment = $this->paymentRecord(100.00, 'BDT');
        $executed = (object) ['amount' => '100.00', 'currency' => 'BDT', 'trxID' => 'TX1'];

        $this->assertTrue($this->callGuard($this->bkash(), 'executedPaymentMatches', $executed, $payment));
    }

    /** The core attack: pay a little, settle a lot. */
    public function test_bkash_refuses_a_cheaper_payment_against_an_expensive_order(): void
    {
        $payment = $this->paymentRecord(10000.00, 'BDT');
        $executed = (object) ['amount' => '1.00', 'currency' => 'BDT', 'trxID' => 'TX1'];

        $this->assertFalse($this->callGuard($this->bkash(), 'executedPaymentMatches', $executed, $payment));
    }

    public function test_bkash_refuses_a_different_currency(): void
    {
        $payment = $this->paymentRecord(100.00, 'BDT');
        $executed = (object) ['amount' => '100.00', 'currency' => 'USD', 'trxID' => 'TX1'];

        $this->assertFalse($this->callGuard($this->bkash(), 'executedPaymentMatches', $executed, $payment));
    }

    /** Replay: the same genuine payment must not settle an already-paid record again. */
    public function test_bkash_refuses_an_already_paid_record(): void
    {
        $payment = $this->paymentRecord(100.00, 'BDT', paid: true);
        $executed = (object) ['amount' => '100.00', 'currency' => 'BDT', 'trxID' => 'TX1'];

        $this->assertFalse($this->callGuard($this->bkash(), 'executedPaymentMatches', $executed, $payment));
    }

    public function test_bkash_refuses_a_response_with_no_amount(): void
    {
        $payment = $this->paymentRecord(100.00, 'BDT');
        $executed = (object) ['currency' => 'BDT', 'trxID' => 'TX1'];

        $this->assertFalse($this->callGuard($this->bkash(), 'executedPaymentMatches', $executed, $payment));
    }

    /** Minor-unit comparison, so float representation cannot make near-misses equal. */
    public function test_bkash_refuses_an_amount_that_is_close_but_not_equal(): void
    {
        $payment = $this->paymentRecord(100.00, 'BDT');
        $executed = (object) ['amount' => '99.99', 'currency' => 'BDT'];

        $this->assertFalse($this->callGuard($this->bkash(), 'executedPaymentMatches', $executed, $payment));
    }

    // ---- RazorPay ----

    private function razorpay(): object
    {
        return (new \ReflectionClass(\App\Http\Controllers\Payment_Methods\RazorPayController::class))
            ->newInstanceWithoutConstructor();
    }

    /**
     * An already-paid record is refused before any network call, so this is assertable without
     * reaching RazorPay. It is also the check that closes the replay: RazorPay's callback had no
     * is_paid guard at all.
     */
    public function test_razorpay_refuses_an_already_paid_record(): void
    {
        $payment = $this->paymentRecord(100.00, 'INR', paid: true);

        $this->assertFalse($this->callGuard(
            $this->razorpay(),
            'verifiedRazorpayPayment',
            ['razorpay_payment_id' => 'pay_123'],
            $payment
        ));
    }

    public function test_razorpay_refuses_a_request_with_no_payment_id(): void
    {
        $payment = $this->paymentRecord(100.00, 'INR');

        $this->assertFalse($this->callGuard($this->razorpay(), 'verifiedRazorpayPayment', [], $payment));
        $this->assertFalse($this->callGuard(
            $this->razorpay(),
            'verifiedRazorpayPayment',
            ['razorpay_payment_id' => ''],
            $payment
        ));
    }

    /**
     * Fails closed. With no usable API credentials the call to RazorPay throws, and an exception
     * must mean "not verified" rather than falling through to settlement.
     */
    public function test_razorpay_fails_closed_when_the_gateway_cannot_be_reached(): void
    {
        config()->set('razor_config', ['api_key' => 'rzp_test_invalid', 'api_secret' => 'invalid']);
        $payment = $this->paymentRecord(100.00, 'INR');

        $this->assertFalse($this->callGuard(
            $this->razorpay(),
            'verifiedRazorpayPayment',
            ['razorpay_payment_id' => 'pay_nonexistent'],
            $payment
        ));
    }
}
