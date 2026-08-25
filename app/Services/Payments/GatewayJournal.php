<?php

namespace App\Services\Payments;

use App\Models\PaymentRequest;
use App\Services\Analytics\Analytics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What happened to a payment, written down while it happens.
 *
 * The gateway ledger recorded one fact — `is_paid` — so a declined card, a gateway timeout and a
 * shopper who closed the tab were byte-identical rows. Nothing recorded a callback at all, which is
 * the sharper hole: a callback that never arrived and one that arrived and was rejected are the same
 * absence of a row, so a payment outage was visible only as orders that quietly stopped appearing.
 *
 * Three moments, one place:
 *
 *   `started()`   — the request row exists and the shopper is being sent to the gateway.
 *   `received()`  — a callback landed, whatever it said.
 *   `finished()`  — the attempt reached an outcome, with the gateway's reason when it gave one.
 *
 * Nothing here throws into a payment. A missing observation is a gap in a report; an observer that
 * throws inside a callback is money taken with no order behind it.
 */
class GatewayJournal
{
    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_FAILURE = 'failure';
    /** A callback that was received but decided nothing — a duplicate, or one we could not match. */
    public const OUTCOME_IGNORED = 'ignored';

    public const STATUS_STARTED = 'started';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    /**
     * What this request decided, for the middleware that writes the receipt at the end of it.
     *
     * A callback that arrived and decided nothing, one that arrived and failed, and one that never
     * arrived are three different incidents. Only the first two produce a row at all, and they are
     * only distinguishable if the arrival and the decision end up on the same receipt — which means
     * one of them has to wait for the other.
     *
     * @var array{outcome: string, reference: ?string, payment_request_id: ?string, note: ?string}|null
     */
    private ?array $decision = null;

    public function __construct(private readonly ?Analytics $analytics = null)
    {
    }

    /** @return array{outcome: string, reference: ?string, payment_request_id: ?string, note: ?string}|null */
    public function decision(): ?array
    {
        return $this->decision;
    }

    /**
     * A payment attempt has begun.
     *
     * The analytics event has a mapping for this outcome and no caller ever used it, so a shopper
     * who left the gateway before it answered was invisible and no abandonment rate could exist.
     */
    public function started(PaymentRequest $payment): void
    {
        $this->safely(function () use ($payment) {
            if (Schema::hasColumn('payment_requests', 'status')) {
                PaymentRequest::where('id', $payment->id)->update([
                    'status' => self::STATUS_STARTED,
                    'attempts' => DB::raw('attempts + 1'),
                ]);
            }

            $this->analytics?->paymentAttempted(
                gateway: (string) $payment->payment_method,
                outcome: 'started',
                amount: (float) $payment->payment_amount,
            );
        });
    }

    /**
     * A gateway callback landed.
     *
     * Recorded before the callback is judged, so a callback that arrives and is then rejected is
     * distinguishable from one that never came. The payload itself is never stored: a gateway body
     * carries card fragments, addresses and signing material, and this table is read on screen.
     */
    public function received(
        string $gateway,
        string $outcome,
        ?string $reference = null,
        ?string $paymentRequestId = null,
        ?string $note = null,
    ): void {
        $this->safely(function () use ($gateway, $outcome, $reference, $paymentRequestId, $note) {
            if (!Schema::hasTable('payment_gateway_receipts')) {
                return;
            }

            DB::table('payment_gateway_receipts')->insert([
                'gateway' => mb_substr($gateway, 0, 40),
                'reference' => $reference === null ? null : mb_substr($reference, 0, 100),
                'payment_request_id' => $paymentRequestId,
                'outcome' => $outcome,
                'note' => $note === null ? null : mb_substr($note, 0, 191),
                'ip' => mb_substr((string) request()->ip(), 0, 64),
                'created_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * The attempt reached an outcome.
     *
     * `finalized_at` is written once: a gateway that sends the same callback three times must not
     * make one payment look like three, and the first answer is the one that decided the order.
     */
    public function finished(
        string|int|null $paymentRequestId,
        bool $succeeded,
        ?string $failureCode = null,
        ?string $failureMessage = null,
    ): void {
        $this->decision = [
            'outcome' => $succeeded ? self::OUTCOME_SUCCESS : self::OUTCOME_FAILURE,
            'reference' => $paymentRequestId === null ? null : (string) $paymentRequestId,
            'payment_request_id' => $paymentRequestId === null ? null : (string) $paymentRequestId,
            'note' => $succeeded ? null : (trim(($failureCode ?? '') . ' ' . ($failureMessage ?? '')) ?: null),
        ];

        $this->safely(function () use ($paymentRequestId, $succeeded, $failureCode, $failureMessage) {
            if ($paymentRequestId === null || !Schema::hasColumn('payment_requests', 'status')) {
                return;
            }

            PaymentRequest::where('id', $paymentRequestId)
                ->whereNull('finalized_at')
                ->update([
                    'status' => $succeeded ? self::STATUS_SUCCEEDED : self::STATUS_FAILED,
                    'failure_code' => $succeeded ? null : ($failureCode === null ? null : mb_substr($failureCode, 0, 64)),
                    'failure_message' => $succeeded ? null : ($failureMessage === null ? null : mb_substr($failureMessage, 0, 500)),
                    'finalized_at' => Carbon::now(),
                ]);
        });
    }

    /**
     * Tie a payment request to the order it paid for.
     *
     * `attribute_id` holds a unix timestamp rather than an order id, which left
     * `orders.transaction_ref = payment_requests.transaction_id` — varchar(30) against varchar(100),
     * nullable on both sides — as the only join, and every payment reconciliation best-effort.
     */
    public function linkToOrder(string|int|null $paymentRequestId, int|string|null $orderId): void
    {
        $this->safely(function () use ($paymentRequestId, $orderId) {
            if ($paymentRequestId === null || $orderId === null || !Schema::hasColumn('payment_requests', 'order_id')) {
                return;
            }

            PaymentRequest::where('id', $paymentRequestId)->update(['order_id' => (int) $orderId]);
        });
    }

    private function safely(callable $write): void
    {
        try {
            $write();
        } catch (Throwable) {
            // A missing observation is a gap in a report. An observer that throws inside a payment
            // callback is money taken with no order behind it.
        }
    }
}
