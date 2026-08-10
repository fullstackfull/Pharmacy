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

    // ---- reverse direction: a released payout reservation must not be settled as new income ----

    /**
     * Replicate exactly what PayoutService::releaseReservation writes when a seller cancels a payout
     * request: the reserve debit is voided (relabelled `paid`) and a compensating `payout_release`
     * credit is added back as `available`. This is the ledger state the over-pay fed on.
     */
    private function simulateReserveAndCancel(int $sellerId, float $amount, int $cycle): void
    {
        // Reserve (requestPayout) then void it (releaseReservation flips the reserve to `paid`).
        $this->ledger->record($sellerId, VendorLedgerEntry::TYPE_PAYOUT, debit: $amount,
            status: VendorLedgerEntry::STATUS_PAID, referenceType: 'payout_reserve', referenceId: "res-$cycle");
        // ...and the money returns as an `available` `payout_release` credit.
        $this->ledger->record($sellerId, VendorLedgerEntry::TYPE_MANUAL_ADJUSTMENT, credit: $amount,
            status: VendorLedgerEntry::STATUS_AVAILABLE, referenceType: 'payout_release', referenceId: "rel-$cycle");
    }

    public function test_a_released_payout_reservation_is_not_settled_as_new_income(): void
    {
        // A single clean earning: 400 credit, 52 commission -> 348 net settleable.
        $this->availableEarning(11);
        $this->simulateReserveAndCancel(11, 348, 1);

        // The release credit is back in the balance (the seller genuinely has the money)...
        $this->assertSame(348.0, $this->ledger->withdrawable(11), 'a cancelled payout returns the money to withdrawable');

        // ...but the settlement must value the seller at their real earning, NOT earning + release.
        $settlement = $this->engine->calculate(11);
        $this->assertNotNull($settlement);
        $this->assertSame(348.0, (float) $settlement->net_amount, 'settlement counts the earning once, not the payout reversal');

        // And once settled, nothing is left to double-settle or double-withdraw.
        $this->assertSame(0.0, $this->ledger->withdrawable(11));
        $this->assertNull($this->engine->calculate(11), 'no phantom second settlement from the release credit');
    }

    public function test_repeated_reserve_and_cancel_cannot_inflate_a_settlement(): void
    {
        $this->availableEarning(12); // 348 net

        // Seller-controlled abuse: request a payout and cancel it, over and over.
        for ($cycle = 1; $cycle <= 5; $cycle++) {
            $this->simulateReserveAndCancel(12, 348, $cycle);
        }

        // Before the fix this grew to 348 * (5 + 1) = 2088; the seller earned 348 and was paid 0.
        $settlement = $this->engine->calculate(12);
        $this->assertNotNull($settlement);
        $this->assertSame(348.0, (float) $settlement->net_amount, 'settlement stays bounded at the real earning regardless of reserve/cancel cycles');
    }

    public function test_a_manual_adjustment_with_no_reference_still_settles(): void
    {
        // NULL-safety of the payout-machinery exclusion: a genuine settleable credit that carries no
        // reference_type (e.g. a manual bonus) must NOT be dropped by the NOT-IN filter.
        $this->ledger->record(13, VendorLedgerEntry::TYPE_BONUS, credit: 90,
            status: VendorLedgerEntry::STATUS_AVAILABLE, referenceType: null, referenceId: null);

        $settlement = $this->engine->calculate(13);
        $this->assertNotNull($settlement, 'a reference-less earning is still settleable');
        $this->assertSame(90.0, (float) $settlement->net_amount);
    }
}
