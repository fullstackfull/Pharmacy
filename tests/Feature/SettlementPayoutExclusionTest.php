<?php

namespace Tests\Feature;

use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\SettlementEngine;
use App\Services\Marketplace\VendorLedger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression for the settlement/payout double-payment (stabilization P1).
 *
 * Two payout channels draw on one ledger: admin settlements (SettlementEngine) and seller payout
 * requests (PayoutService, bounded by VendorLedger::withdrawable()). SettlementEngine pays an earning by
 * claiming it (settlement_id) and relabelling it 'paid' WITHOUT an offsetting debit, so the credit stays
 * in `balance`. Before the fix, withdrawable() = balance − pending still counted that settled earning,
 * so the same money could be paid again through a payout request. This pins that once a settlement
 * claims the earnings, withdrawable() drops to zero — and returns if the settlement is cancelled.
 */
class SettlementPayoutExclusionTest extends TestCase
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
            $t->unsignedBigInteger('settlement_id')->nullable();
            $t->timestamps();
            $t->unique(['seller_id', 'entry_type', 'reference_type', 'reference_id'], 'vle_idem');
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
            $t->timestamp('paid_at')->nullable();
            $t->string('payout_reference', 191)->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
        });

        $this->ledger = new VendorLedger();
        $this->engine = new SettlementEngine();
    }

    private function availableEarning(int $sellerId = 5): void
    {
        $this->ledger->record($sellerId, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 400,
            status: VendorLedgerEntry::STATUS_AVAILABLE, referenceType: 'order_details', referenceId: 1);
        $this->ledger->record($sellerId, VendorLedgerEntry::TYPE_COMMISSION_CHARGE, debit: 52,
            status: VendorLedgerEntry::STATUS_AVAILABLE, referenceType: 'order_details', referenceId: 1);
    }

    public function test_settlement_claimed_earnings_are_not_withdrawable_via_payout(): void
    {
        $this->availableEarning(5);
        $this->assertSame(348.0, $this->ledger->withdrawable(5), 'net earning is withdrawable before settlement');

        $settlement = $this->engine->calculate(5);
        $this->assertNotNull($settlement);

        // Claimed by the settlement -> no longer withdrawable through the payout channel.
        $this->assertSame(0.0, $this->ledger->withdrawable(5), 'settlement-claimed money must not be double-withdrawable');

        // Paying the settlement (relabel to paid, no offsetting debit) keeps it non-withdrawable.
        $this->engine->approve($settlement, approvedBy: 4);
        $this->engine->markPaid($settlement->fresh(), payoutReference: 'BANK-1', paidBy: 8);
        $this->assertSame(0.0, $this->ledger->withdrawable(5), 'settlement-paid money must stay non-withdrawable');
    }

    public function test_cancelling_a_settlement_returns_the_money_to_withdrawable(): void
    {
        $this->availableEarning(9);
        $settlement = $this->engine->calculate(9);
        $this->assertSame(0.0, $this->ledger->withdrawable(9));

        $this->engine->cancel($settlement);
        $this->assertSame(348.0, $this->ledger->withdrawable(9), 'a released claim returns the money to the payout pool');
    }
}
