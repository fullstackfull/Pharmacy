<?php

namespace Tests\Feature;

use App\Models\VendorLedgerEntry;
use App\Models\VendorPayoutRequest;
use App\Services\Marketplace\PayoutService;
use App\Services\Marketplace\VendorLedger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Seller-initiated payouts over the ledger (Phase 3, Stage B).
 *
 * The properties that matter, and are asserted:
 *
 *  1. A payout can only ask for what is genuinely withdrawable — available money, net of anything
 *     already reserved for a payout in flight. The old withdraw flow validated nothing of the kind.
 *  2. Requesting reserves the amount, so the same money cannot be requested twice.
 *  3. Rejecting releases the reservation back to available; nothing is lost.
 *  4. Paying requires approval — the maker-checker control.
 *  5. A bank-detail change opens a cooling window during which payouts are refused. This is the
 *     account-takeover defence the specification asks for.
 */
class PayoutServiceTest extends TestCase
{
    private VendorLedger $ledger;
    private PayoutService $payouts;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['vendor_ledger_entries', 'vendor_payout_requests', 'vendor_bank_change_logs', 'approval_requests', 'approval_decisions', 'audit_logs'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('approval_requests', function (Blueprint $t) {
            $t->id(); $t->string('reference', 60)->unique(); $t->string('workflow', 60);
            $t->string('subject_type', 100)->nullable(); $t->string('subject_id', 191)->nullable();
            $t->string('status', 20)->default('pending'); $t->decimal('amount', 24, 4)->nullable();
            $t->string('currency', 10)->nullable(); $t->unsignedTinyInteger('required_approvals')->default(1);
            $t->unsignedTinyInteger('approvals_count')->default(0); $t->string('requested_by_type', 20)->nullable();
            $t->unsignedBigInteger('requested_by')->nullable(); $t->text('request_note')->nullable();
            $t->json('payload')->nullable(); $t->timestamp('decided_at')->nullable(); $t->text('decision_note')->nullable(); $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id(); $t->string('actor_type', 40)->nullable(); $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name', 191)->nullable(); $t->string('action', 100);
            $t->string('subject_type', 100)->nullable(); $t->string('subject_id', 191)->nullable();
            $t->json('before')->nullable(); $t->json('after')->nullable(); $t->json('context')->nullable();
            $t->string('ip_address', 45)->nullable(); $t->string('user_agent', 255)->nullable(); $t->timestamps();
        });

        Schema::create('vendor_ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('seller_id');
            $t->string('seller_is', 20)->default('seller');
            $t->string('entry_type', 40);
            $t->decimal('debit', 24, 4)->default(0);
            $t->decimal('credit', 24, 4)->default(0);
            $t->decimal('balance_after', 24, 4)->default(0);
            $t->string('status', 20)->default('pending');
            $t->timestamp('available_at')->nullable();
            $t->string('currency', 10)->nullable();
            $t->string('reference_type', 40)->nullable();
            $t->string('reference_id', 191)->nullable();
            $t->string('description', 512)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('created_by_type', 20)->nullable();
            $t->unsignedBigInteger('settlement_id')->nullable();
            $t->timestamps();
            $t->unique(['seller_id', 'entry_type', 'reference_type', 'reference_id'], 'vle_idempotency_unique');
        });
        Schema::create('vendor_payout_requests', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 60)->unique();
            $t->unsignedBigInteger('seller_id');
            $t->string('seller_is', 20)->default('seller');
            $t->decimal('amount', 24, 4);
            $t->string('currency', 10)->nullable();
            $t->string('payout_currency', 10)->nullable();
            $t->decimal('payout_amount', 24, 4)->nullable();
            $t->decimal('exchange_rate', 20, 8)->nullable();
            $t->string('status', 20)->default('requested');
            $t->string('method', 40)->nullable();
            $t->json('method_details')->nullable();
            $t->unsignedBigInteger('reserve_entry_id')->nullable();
            $t->unsignedBigInteger('payout_entry_id')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->string('payment_reference', 191)->nullable();
            $t->timestamps();
        });
        Schema::create('vendor_bank_change_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('seller_id');
            $t->json('previous')->nullable();
            $t->json('current')->nullable();
            $t->timestamp('cooling_until')->nullable();
            $t->unsignedBigInteger('changed_by')->nullable();
            $t->string('changed_by_type', 20)->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->timestamps();
        });

        $this->ledger = new VendorLedger();
        $this->payouts = new PayoutService($this->ledger);
    }

    private function giveAvailable(int $sellerId, float $amount, int $ref = 1): void
    {
        $this->ledger->record($sellerId, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: $amount,
            status: VendorLedgerEntry::STATUS_AVAILABLE, referenceType: 'order_details', referenceId: $ref);
    }

    // ---- can only request what is withdrawable ----

    public function test_a_seller_can_request_up_to_their_available_balance(): void
    {
        $this->giveAvailable(5, 400);

        $result = $this->payouts->requestPayout(5, 300);

        $this->assertTrue($result['ok']);
        $this->assertSame(VendorPayoutRequest::STATUS_REQUESTED, $result['request']->status);
        $this->assertStringStartsWith('PO-', $result['request']->reference);
    }

    public function test_a_request_beyond_the_available_balance_is_refused(): void
    {
        $this->giveAvailable(5, 400);

        $result = $this->payouts->requestPayout(5, 500);

        $this->assertFalse($result['ok']);
        $this->assertSame('amount_exceeds_available_balance', $result['reason']);
    }

    public function test_pending_money_in_the_return_window_is_not_requestable(): void
    {
        // Earned but not matured — pending, not available.
        $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 400,
            status: VendorLedgerEntry::STATUS_PENDING, referenceType: 'order_details', referenceId: 1);

        $result = $this->payouts->requestPayout(5, 100);

        $this->assertFalse($result['ok'], 'money in the return window cannot be paid out');
    }

    // ---- reserving stops double-spend ----

    public function test_requesting_reserves_the_amount_so_it_cannot_be_requested_twice(): void
    {
        $this->giveAvailable(5, 400);

        $first = $this->payouts->requestPayout(5, 300);
        $second = $this->payouts->requestPayout(5, 300);

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok'], 'only 100 is left withdrawable after the first reserve');
        $this->assertSame('amount_exceeds_available_balance', $second['reason']);
    }

    public function test_the_reserved_amount_leaves_the_withdrawable_balance(): void
    {
        $this->giveAvailable(5, 400);
        $this->payouts->requestPayout(5, 250);

        $this->assertSame(150.0, $this->ledger->withdrawable(5), '400 available less 250 reserved');
    }

    // ---- rejection releases the money ----

    public function test_rejecting_a_request_releases_the_reservation(): void
    {
        $this->giveAvailable(5, 400);
        $request = $this->payouts->requestPayout(5, 300)['request'];

        $this->assertSame(100.0, $this->ledger->withdrawable(5), 'held while requested');

        $this->assertTrue($this->payouts->releaseReservation($request, VendorPayoutRequest::STATUS_REJECTED));

        $this->assertSame(400.0, $this->ledger->withdrawable(5), 'money back after rejection');
        $this->assertSame(VendorPayoutRequest::STATUS_REJECTED, $request->fresh()->status);
    }

    public function test_the_release_is_recorded_not_erased(): void
    {
        $this->giveAvailable(5, 400);
        $request = $this->payouts->requestPayout(5, 300)['request'];

        $this->payouts->releaseReservation($request, VendorPayoutRequest::STATUS_REJECTED, note: 'suspicious');

        // The ledger still shows the payout was requested and released — history is not rewritten.
        $this->assertTrue(
            VendorLedgerEntry::where('reference_type', 'payout_release')->exists(),
            'the release is its own ledger entry'
        );
    }

    // ---- maker-checker ----

    public function test_paying_requires_approval_first(): void
    {
        $this->giveAvailable(5, 400);
        $request = $this->payouts->requestPayout(5, 300)['request'];

        $this->assertFalse($this->payouts->markPaid($request), 'a requested payout cannot be paid');
        $this->assertSame(VendorPayoutRequest::STATUS_REQUESTED, $request->fresh()->status);
    }

    public function test_the_full_request_approve_pay_flow(): void
    {
        $this->giveAvailable(5, 400);
        $request = $this->payouts->requestPayout(5, 300)['request'];

        $this->assertTrue($this->payouts->review($request, approve: true, reviewer: 7));
        $this->assertTrue($this->payouts->markPaid($request->fresh(), paymentReference: 'BANK-42'));

        $paid = $request->fresh();
        $this->assertSame(VendorPayoutRequest::STATUS_PAID, $paid->status);
        $this->assertSame('BANK-42', $paid->payment_reference);
        // The reserved entry became paid, so the money has genuinely left: 300 of 400 is gone,
        // leaving 100 as the running balance and 100 withdrawable.
        $this->assertSame(100.0, $this->ledger->balances(5)['balance']);
        $this->assertSame(0.0, $this->ledger->balances(5)['reserved']);
        $this->assertSame(100.0, $this->ledger->withdrawable(5));
    }

    public function test_paying_the_same_payout_twice_is_a_noop(): void
    {
        $this->giveAvailable(5, 400);
        $request = $this->payouts->requestPayout(5, 300)['request'];
        $this->payouts->review($request, approve: true, reviewer: 7);

        $this->assertTrue($this->payouts->markPaid($request->fresh(), paymentReference: 'BANK-1'));
        // Second call sees PAID under the row lock and refuses — no second ledger effect.
        $this->assertFalse($this->payouts->markPaid($request->fresh(), paymentReference: 'BANK-2'));
        $this->assertSame(100.0, $this->ledger->withdrawable(5));
    }

    public function test_a_paid_payout_cannot_then_be_released_double_spend_guard(): void
    {
        $this->giveAvailable(5, 400);
        $request = $this->payouts->requestPayout(5, 300)['request'];
        $this->payouts->review($request, approve: true, reviewer: 7);
        $this->assertTrue($this->payouts->markPaid($request->fresh(), paymentReference: 'BANK-1'));

        // The race the reviewer flagged: releasing an already-paid payout would credit the amount back
        // to withdrawable, letting the seller draw money they were already paid. The status re-check
        // under the lock must refuse it.
        $this->assertFalse($this->payouts->releaseReservation($request->fresh()));
        $this->assertSame(VendorPayoutRequest::STATUS_PAID, $request->fresh()->status);
        $this->assertSame(100.0, $this->ledger->withdrawable(5), 'paid money must not return to withdrawable');
    }

    public function test_a_pending_dual_control_approval_blocks_payment(): void
    {
        $this->giveAvailable(5, 4000);
        $request = $this->payouts->requestPayout(5, 3000)['request'];
        $this->payouts->review($request, approve: true, reviewer: 7);

        // A large-payout approval is opened and still pending -> payment is blocked.
        \App\Models\ApprovalRequest::create([
            'reference' => 'APR-TEST-1', 'workflow' => 'payout',
            'subject_type' => VendorPayoutRequest::class, 'subject_id' => $request->id,
            'status' => \App\Models\ApprovalRequest::STATUS_PENDING, 'required_approvals' => 2,
        ]);
        $this->assertFalse($this->payouts->dualControlSatisfied($request->fresh()));
        $this->assertFalse($this->payouts->markPaid($request->fresh()), 'a pending dual-control approval must block payment');
        $this->assertSame(VendorPayoutRequest::STATUS_APPROVED, $request->fresh()->status);

        // Once the approval reaches its required approvers, payment is allowed.
        \App\Models\ApprovalRequest::where('subject_id', $request->id)
            ->update(['status' => \App\Models\ApprovalRequest::STATUS_APPROVED]);
        $this->assertTrue($this->payouts->markPaid($request->fresh(), paymentReference: 'BANK-OK'));
    }

    // ---- the cooling period ----

    public function test_a_bank_change_blocks_payouts_during_the_cooling_window(): void
    {
        $this->giveAvailable(5, 400);
        $this->payouts->recordBankChange(5, previous: ['account_no' => '111'], current: ['account_no' => '222'], changedBy: 5);

        $result = $this->payouts->requestPayout(5, 100);

        $this->assertFalse($result['ok']);
        $this->assertSame('bank_details_recently_changed_payouts_are_temporarily_paused', $result['reason']);
    }

    public function test_payouts_resume_after_the_cooling_window_passes(): void
    {
        $this->giveAvailable(5, 400);
        $log = $this->payouts->recordBankChange(5, previous: [], current: ['account_no' => '222'], changedBy: 5);

        // Move the window into the past.
        $log->forceFill(['cooling_until' => now()->subHour()])->save();

        $this->assertTrue($this->payouts->requestPayout(5, 100)['ok']);
    }

    public function test_the_bank_change_keeps_a_before_and_after_for_a_fraud_review(): void
    {
        $log = $this->payouts->recordBankChange(5, previous: ['account_no' => 'OLD'], current: ['account_no' => 'NEW'], changedBy: 5, ip: '10.0.0.1');

        $this->assertSame('OLD', $log->previous['account_no']);
        $this->assertSame('NEW', $log->current['account_no']);
        $this->assertSame('10.0.0.1', $log->ip_address);
    }

    public function test_a_zero_or_negative_request_is_refused(): void
    {
        $this->giveAvailable(5, 400);

        $this->assertFalse($this->payouts->requestPayout(5, 0)['ok']);
        $this->assertFalse($this->payouts->requestPayout(5, -50)['ok']);
    }

    // ---- the payout workflow USES the reusable approval engine (spec 82/83) ----

    public function test_a_large_payout_opens_a_dual_control_approval(): void
    {
        $this->giveAvailable(5, 5000);
        $request = $this->payouts->requestPayout(5, 4000)['request'];

        // Below threshold: nothing opened, ordinary path.
        $this->assertNull($this->payouts->openApprovalIfLarge($request, threshold: 0));

        // Above threshold: an approval request through the shared engine, needing two approvers.
        $approval = $this->payouts->openApprovalIfLarge($request, threshold: 1000, requiredApprovals: 2);

        $this->assertNotNull($approval);
        $this->assertSame('payout', $approval->workflow);
        $this->assertSame(2, $approval->required_approvals);
        $this->assertSame((string) $request->id, (string) $approval->subject_id);
        // The seller who requested it is recorded as the maker, so they cannot be a checker.
        $this->assertSame('seller', $approval->requested_by_type);
    }

    public function test_a_small_payout_opens_no_approval(): void
    {
        $this->giveAvailable(5, 5000);
        $request = $this->payouts->requestPayout(5, 200)['request'];

        $this->assertNull($this->payouts->openApprovalIfLarge($request, threshold: 1000));
    }
}
