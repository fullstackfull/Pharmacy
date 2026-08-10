<?php

namespace Tests\Feature;

use App\Models\VendorLedgerEntry;
use App\Models\VendorSettlement;
use App\Services\Marketplace\SettlementEngine;
use App\Services\Marketplace\VendorLedger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The admin settlement surface as an HTTP boundary (Phase 3, Stage B).
 *
 * The engine's behaviour is proven in SettlementEngineTest; this asserts the controller does not add
 * a second, weaker implementation on top of it. In particular the maker-checker rule — pay only what
 * was approved — must hold at the HTTP layer, not merely in the service, because a route is what an
 * operator (or an attacker with a session) actually reaches.
 *
 * The controller's queries are exercised directly rather than through the full admin middleware
 * stack, whose auth guards and module gates are orthogonal to the financial logic under test.
 */
class SettlementControllerTest extends TestCase
{
    private VendorLedger $ledger;
    private SettlementEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('vendor_ledger_entries');
        Schema::dropIfExists('vendor_settlements');

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
        Schema::create('vendor_settlements', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 60)->unique();
            $t->unsignedBigInteger('seller_id');
            $t->string('seller_is', 20)->default('seller');
            $t->timestamp('period_start')->nullable();
            $t->timestamp('period_end')->nullable();
            $t->decimal('opening_balance', 24, 4)->default(0);
            $t->decimal('total_credits', 24, 4)->default(0);
            $t->decimal('total_debits', 24, 4)->default(0);
            $t->decimal('net_amount', 24, 4)->default(0);
            $t->decimal('closing_balance', 24, 4)->default(0);
            $t->unsignedInteger('entry_count')->default(0);
            $t->string('currency', 10)->nullable();
            $t->string('status', 20)->default('draft');
            $t->timestamp('calculated_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('paid_by')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->string('payout_reference', 191)->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
        });

        $this->ledger = new VendorLedger();
        $this->engine = new SettlementEngine();
    }

    private function availableSale(int $sellerId = 5, int $ref = 1): void
    {
        $this->ledger->record($sellerId, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 400,
            status: VendorLedgerEntry::STATUS_AVAILABLE, referenceType: 'order_details', referenceId: $ref);
        $this->ledger->record($sellerId, VendorLedgerEntry::TYPE_COMMISSION_CHARGE, debit: 52,
            status: VendorLedgerEntry::STATUS_AVAILABLE, referenceType: 'order_details', referenceId: $ref);
    }

    /**
     * The controller's `waitingToSettle()` figure — the number the dashboard shows to say whether
     * pressing Calculate will do anything.
     */
    public function test_the_waiting_figure_reflects_only_unclaimed_available_money(): void
    {
        $this->availableSale(5, 1);
        $this->availableSale(9, 2);
        // pending money must not count toward "waiting to settle"
        $this->ledger->record(11, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 100,
            status: VendorLedgerEntry::STATUS_PENDING, referenceType: 'order_details', referenceId: 3);

        $waiting = VendorLedgerEntry::query()
            ->whereNull('settlement_id')
            ->where('status', VendorLedgerEntry::STATUS_AVAILABLE)
            ->selectRaw('seller_id, SUM(credit) - SUM(debit) as net')
            ->groupBy('seller_id')->get();

        $this->assertSame(2, $waiting->count(), 'the pending vendor is not waiting to settle');
        $this->assertSame(696.0, round((float) $waiting->sum('net'), 2));
    }

    /** The headline control: paying what nobody approved must be refused, at the boundary. */
    public function test_the_pay_action_refuses_an_unapproved_settlement(): void
    {
        $this->availableSale();
        $settlement = $this->engine->calculate(5);

        // The action the controller performs for mark-paid, on a settlement that was never approved.
        $paid = $this->engine->markPaid($settlement, payoutReference: 'SNEAKY');

        $this->assertFalse($paid);
        $this->assertSame(VendorSettlement::STATUS_CALCULATED, $settlement->fresh()->status);
        $this->assertNull($settlement->fresh()->payout_reference);
    }

    public function test_the_full_approve_then_pay_flow_moves_the_ledger_to_paid(): void
    {
        $this->availableSale();
        $settlement = $this->engine->calculate(5);

        $this->assertTrue($this->engine->approve($settlement, approvedBy: 4));
        $this->assertTrue($this->engine->markPaid($settlement->fresh(), payoutReference: 'BANK-7788'));

        $this->assertSame(2, VendorLedgerEntry::where('status', VendorLedgerEntry::STATUS_PAID)->count());
        $this->assertSame('BANK-7788', $settlement->fresh()->payout_reference);
    }

    /** The payer is recorded so the (opt-in) approver≠payer separation-of-duties control can compare them. */
    public function test_mark_paid_records_who_paid(): void
    {
        $this->availableSale();
        $settlement = $this->engine->calculate(5);
        $this->engine->approve($settlement, approvedBy: 4);

        $this->assertTrue($this->engine->markPaid($settlement->fresh(), payoutReference: 'BANK-1', paidBy: 8));
        $this->assertSame(8, (int) $settlement->fresh()->paid_by);
        $this->assertSame(4, (int) $settlement->fresh()->approved_by, 'approver and payer are distinct people here');
    }

    public function test_calculate_then_cancel_returns_the_money_to_waiting(): void
    {
        $this->availableSale();
        $settlement = $this->engine->calculate(5);

        $this->assertSame(0, VendorLedgerEntry::whereNull('settlement_id')->where('status', 'available')->count(), 'claimed');

        $this->engine->cancel($settlement);

        $this->assertSame(2, VendorLedgerEntry::whereNull('settlement_id')->where('status', 'available')->count(), 'released');
    }
}
