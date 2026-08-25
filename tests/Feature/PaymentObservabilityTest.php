<?php

namespace Tests\Feature;

use App\Http\Middleware\RecordGatewayCallback;
use App\Models\PaymentRequest;
use App\Services\Monitoring\Collectors\FinanceIntegrityCollector;
use App\Services\Payments\GatewayJournal;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Why a payment failed, and whether the gateway ever called back.
 *
 * The gateway ledger held one fact — is_paid — so a declined card, a gateway timeout and a shopper
 * who closed the tab were byte-identical rows and no failure reason could be shown for any of them.
 * Worse, nothing recorded a callback at all: a callback that never arrived and one that arrived and
 * was rejected were the same absence of a row, which is why the payments page could name the symptom
 * ("money captured with no order") and never the cause.
 *
 * The three distinctions these tests hold are the whole point of the feature: arrived-and-succeeded,
 * arrived-and-failed, and arrived-and-nothing-acted-on are three different incidents with three
 * different fixes, and never-arrived is the fourth — the one with no row.
 */
class PaymentObservabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('payment_requests');
        Schema::create('payment_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->decimal('payment_amount', 24, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->boolean('is_paid')->default(false);
            $t->string('status', 12)->nullable();
            $t->string('failure_code', 64)->nullable();
            $t->string('failure_message', 500)->nullable();
            $t->timestamp('finalized_at')->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->unsignedBigInteger('order_id')->nullable();
            $t->timestamps();
        });

        Schema::dropIfExists('payment_gateway_receipts');
        (require database_path('migrations/2026_09_19_000002_create_payment_gateway_receipts_table.php'))->up();
    }

    private function payment(): PaymentRequest
    {
        $payment = new PaymentRequest();
        $payment->id = '11111111-2222-3333-4444-555555555555';
        $payment->payment_amount = 120.0;
        $payment->payment_method = 'stripe';
        $payment->save();

        return $payment;
    }

    private function receipts()
    {
        return DB::table('payment_gateway_receipts');
    }

    // ────────────────────────────────────────────────────────────── the attempt

    public function test_an_attempt_is_marked_started_and_counted(): void
    {
        $payment = $this->payment();

        app(GatewayJournal::class)->started($payment);

        $fresh = $payment->fresh();
        $this->assertSame(GatewayJournal::STATUS_STARTED, $fresh->status);
        $this->assertSame(1, (int) $fresh->attempts);
    }

    public function test_a_failure_carries_the_reason_the_ledger_never_had(): void
    {
        $payment = $this->payment();

        app(GatewayJournal::class)->finished($payment->id, succeeded: false, failureCode: 'card_declined', failureMessage: 'Issuer refused');

        $fresh = $payment->fresh();
        $this->assertSame(GatewayJournal::STATUS_FAILED, $fresh->status);
        $this->assertSame('card_declined', $fresh->failure_code);
        $this->assertSame('Issuer refused', $fresh->failure_message);
        $this->assertNotNull($fresh->finalized_at);
    }

    /** A gateway that sends the same callback three times must not make one payment look like three. */
    public function test_only_the_first_answer_finalizes_the_attempt(): void
    {
        $payment = $this->payment();
        $journal = app(GatewayJournal::class);

        $journal->finished($payment->id, succeeded: true);
        $journal->finished($payment->id, succeeded: false, failureCode: 'late_reversal');

        $fresh = $payment->fresh();
        $this->assertSame(GatewayJournal::STATUS_SUCCEEDED, $fresh->status);
        $this->assertNull($fresh->failure_code);
    }

    /** The join the reconciliations never had: attribute_id holds a timestamp, not an order id. */
    public function test_a_payment_can_finally_name_the_order_it_paid_for(): void
    {
        $payment = $this->payment();

        app(GatewayJournal::class)->linkToOrder($payment->id, 4210);

        $this->assertSame(4210, (int) $payment->fresh()->order_id);
    }

    // ───────────────────────────────────────────────────────────── the callback

    public function test_a_callback_that_decided_nothing_is_recorded_as_acted_on_by_nothing(): void
    {
        $this->terminateCallback('payment/stripe/success');

        $receipt = $this->receipts()->first();
        $this->assertNotNull($receipt);
        $this->assertSame('stripe', $receipt->gateway);
        $this->assertSame(GatewayJournal::OUTCOME_IGNORED, $receipt->outcome);
    }

    public function test_a_callback_that_decided_carries_that_decision_onto_the_same_receipt(): void
    {
        $payment = $this->payment();
        app(GatewayJournal::class)->finished($payment->id, succeeded: false, failureCode: 'card_declined');

        $this->terminateCallback('payment/stripe/success');

        $receipt = $this->receipts()->first();
        $this->assertSame(GatewayJournal::OUTCOME_FAILURE, $receipt->outcome);
        $this->assertSame((string) $payment->id, $receipt->payment_request_id);
    }

    /** The outbound leg is not a callback; recording it would make every send look like an answer. */
    public function test_sending_a_shopper_to_the_gateway_leaves_no_receipt(): void
    {
        $this->terminateCallback('payment/stripe/pay');

        $this->assertSame(0, $this->receipts()->count());
    }

    public function test_a_request_that_is_not_a_payment_leg_at_all_leaves_no_receipt(): void
    {
        $this->terminateCallback('products/panadol-500');

        $this->assertSame(0, $this->receipts()->count());
    }

    private function terminateCallback(string $path): void
    {
        app(RecordGatewayCallback::class)->terminate(
            Request::create('/' . $path, 'POST'),
            new Response('', 200),
        );
    }

    // ─────────────────────────────────────────────────── the money-losing counts

    /**
     * The payments page detected these and published nothing, so the alert engine — which can only
     * read stored series — could not see them and no rule could be written.
     */
    public function test_a_settlement_written_twice_becomes_a_number_a_rule_can_watch(): void
    {
        $this->seedSettlementTables();

        DB::table('order_transactions')->insert([
            ['order_id' => 1, 'order_amount' => 100, 'seller_amount' => 90, 'admin_commission' => 10, 'status' => 'hold', 'created_at' => now()],
            ['order_id' => 1, 'order_amount' => 100, 'seller_amount' => 90, 'admin_commission' => 10, 'status' => 'disburse', 'created_at' => now()],
            ['order_id' => 2, 'order_amount' => 50, 'seller_amount' => 45, 'admin_commission' => 5, 'status' => 'hold', 'created_at' => now()],
        ]);

        $metrics = app(FinanceIntegrityCollector::class)->collect();

        $this->assertSame(1, $metrics['duplicate_settlements']->value);
        $this->assertSame(0, $metrics['commission_mismatch']->value);
    }

    public function test_a_settlement_that_does_not_add_up_is_counted(): void
    {
        $this->seedSettlementTables();

        DB::table('order_transactions')->insert([
            ['order_id' => 3, 'order_amount' => 100, 'seller_amount' => 90, 'admin_commission' => 4, 'status' => 'hold', 'created_at' => now()],
        ]);

        $this->assertSame(1, app(FinanceIntegrityCollector::class)->collect()['commission_mismatch']->value);
    }

    public function test_a_paid_order_nothing_will_ever_disburse_is_counted(): void
    {
        $this->seedSettlementTables();

        DB::table('orders')->insert([
            ['id' => 9, 'payment_status' => 'paid', 'payment_method' => 'stripe', 'created_at' => now()],
            ['id' => 10, 'payment_status' => 'paid', 'payment_method' => 'cash_on_delivery', 'created_at' => now()],
        ]);

        // Only the gateway order counts: cash on delivery never travels through a settlement at all.
        $this->assertSame(1, app(FinanceIntegrityCollector::class)->collect()['paid_without_settlement']->value);
    }

    /** Zero means "checked and clean". Reporting it for a table that does not exist would be a lie. */
    public function test_a_missing_settlement_table_is_not_reported_as_a_clean_book(): void
    {
        Schema::dropIfExists('order_transactions');

        $metric = app(FinanceIntegrityCollector::class)->collect()['duplicate_settlements'];

        $this->assertSame('not_configured', $metric->state);
        $this->assertNull($metric->value);
    }

    private function seedSettlementTables(): void
    {
        Schema::dropIfExists('order_transactions');
        Schema::create('order_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->decimal('order_amount', 24, 2)->default(0);
            $t->decimal('seller_amount', 24, 2)->default(0);
            $t->decimal('admin_commission', 24, 2)->default(0);
            $t->string('status')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('payment_status')->nullable();
            $t->string('payment_method')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }
}
