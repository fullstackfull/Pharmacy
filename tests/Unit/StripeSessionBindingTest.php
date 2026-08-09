<?php

namespace Tests\Unit;

use App\Http\Controllers\Payment_Methods\StripePaymentController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression tests for the Stripe payment-settlement bypass.
 *
 * Before the fix, success() verified that the session in `payment_session_id` was paid, then marked
 * whatever record `payment_id` pointed at as paid — with no link between the two. Both are
 * attacker-supplied query parameters, so one genuinely-paid session could settle any other payment
 * (e.g. a $1 session settling a $5,000 order) and could be replayed indefinitely.
 *
 * These exercise the binding check directly, so no Stripe API or database is involved.
 */
class StripeSessionBindingTest extends TestCase
{
    private function check(object $session, object $payment): bool
    {
        $controller = (new ReflectionClass(StripePaymentController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(StripePaymentController::class, 'sessionBelongsToPayment');
        $method->setAccessible(true);

        return $method->invoke($controller, $session, $payment);
    }

    private function session(array $overrides = []): object
    {
        return (object) array_merge([
            'amount_total' => 5000,          // $50.00 in minor units
            'currency'     => 'usd',
            'metadata'     => (object) ['payment_id' => 'pay-1'],
        ], $overrides);
    }

    private function payment(array $overrides = []): object
    {
        return (object) array_merge([
            'id'             => 'pay-1',
            'payment_amount' => 50.00,
            'currency_code'  => 'USD',
        ], $overrides);
    }

    public function test_matching_session_and_payment_is_accepted(): void
    {
        $this->assertTrue($this->check($this->session(), $this->payment()));
    }

    /** The core attack: a valid cheap session must not settle an expensive payment. */
    public function test_cheap_session_cannot_settle_an_expensive_payment(): void
    {
        $cheapSession = $this->session(['amount_total' => 100, 'metadata' => (object) ['payment_id' => 'pay-999']]);
        $expensivePayment = $this->payment(['id' => 'pay-999', 'payment_amount' => 5000.00]);

        $this->assertFalse($this->check($cheapSession, $expensivePayment));
    }

    /** Replay: a session bound to one payment must not settle a different payment. */
    public function test_session_bound_to_another_payment_is_rejected(): void
    {
        $session = $this->session(['metadata' => (object) ['payment_id' => 'pay-1']]);
        $otherPayment = $this->payment(['id' => 'pay-2']);

        $this->assertFalse($this->check($session, $otherPayment));
    }

    public function test_currency_mismatch_is_rejected(): void
    {
        $session = $this->session(['currency' => 'usd']);
        $payment = $this->payment(['currency_code' => 'KWD']);

        $this->assertFalse($this->check($session, $payment));
    }

    /**
     * Sessions created before the fix carry no metadata; they must still be accepted when the
     * amount matches, so in-flight checkouts are not broken by the deploy.
     */
    public function test_legacy_session_without_metadata_is_accepted_when_amount_matches(): void
    {
        $legacy = $this->session(['metadata' => (object) []]);

        $this->assertTrue($this->check($legacy, $this->payment()));
    }

    /** ...but a legacy session with the wrong amount is still refused. */
    public function test_legacy_session_with_wrong_amount_is_still_rejected(): void
    {
        $legacy = $this->session(['metadata' => (object) [], 'amount_total' => 100]);

        $this->assertFalse($this->check($legacy, $this->payment()));
    }

    public function test_cent_rounding_is_handled_exactly(): void
    {
        $session = $this->session(['amount_total' => 1999]);
        $payment = $this->payment(['payment_amount' => 19.99]);

        $this->assertTrue($this->check($session, $payment));
    }

    public function test_currency_case_difference_is_not_a_mismatch(): void
    {
        $session = $this->session(['currency' => 'USD']);
        $payment = $this->payment(['currency_code' => 'usd']);

        $this->assertTrue($this->check($session, $payment));
    }
}
