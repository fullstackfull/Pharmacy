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
 * Settlements (Phase 3, Stage B).
 *
 * A settlement is a period drawn across the ledger. It sums entries; it never re-derives from
 * orders, because the ledger is already the record and a second derivation is a second source of
 * truth that can disagree with the first.
 *
 * Three properties carry the whole design, and each is asserted below:
 *
 *  1. **The same earning can never be paid twice.** Calculating claims the entries by stamping
 *     settlement_id; a claimed entry is invisible to the next run.
 *  2. **Pending money is not settleable.** Anything still inside the return window is excluded.
 *  3. **Approval freezes it.** An approved or paid settlement cannot be cancelled or recalculated —
 *     a correction is a new ledger entry the next settlement picks up.
 */
class SettlementEngineTest extends TestCase
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
            $t->timestamp('paid_at')->nullable();
            $t->string('payout_reference', 191)->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
        });

        $this->ledger = new VendorLedger();
        $this->engine = new SettlementEngine();
    }

    /** An earning and its commission, both matured out of the return window. */
    private function settleableSale(int $sellerId, int $reference, float $gross, float $commission): void
    {
        $this->ledger->record($sellerId, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: $gross,
            status: VendorLedgerEntry::STATUS_AVAILABLE, referenceType: 'order_details', referenceId: $reference);
        $this->ledger->record($sellerId, VendorLedgerEntry::TYPE_COMMISSION_CHARGE, debit: $commission,
            status: VendorLedgerEntry::STATUS_AVAILABLE, referenceType: 'order_details', referenceId: $reference);
    }

    // ---- what a settlement is ----

    public function test_it_sums_the_available_entries_into_one_period(): void
    {
        $this->settleableSale(5, 1, gross: 400, commission: 52);
        $this->settleableSale(5, 2, gross: 100, commission: 10);

        $settlement = $this->engine->calculate(5);

        $this->assertSame(500.0, $settlement->total_credits);
        $this->assertSame(62.0, $settlement->total_debits);
        $this->assertSame(438.0, $settlement->net_amount, 'what the marketplace owes for this period');
        $this->assertSame(4, $settlement->entry_count);
        $this->assertSame(VendorSettlement::STATUS_CALCULATED, $settlement->status);
        $this->assertStringStartsWith('STL-', $settlement->reference);
    }

    public function test_nothing_to_settle_is_a_normal_outcome_not_an_error(): void
    {
        $this->assertNull($this->engine->calculate(5), 'a vendor with no sales simply has no settlement');
    }

    // ---- the same earning can never be paid twice ----

    public function test_calculating_claims_its_entries(): void
    {
        $this->settleableSale(5, 1, gross: 400, commission: 52);

        $settlement = $this->engine->calculate(5);

        $this->assertSame(2, VendorLedgerEntry::where('settlement_id', $settlement->id)->count());
    }

    public function test_a_second_run_cannot_take_the_same_money(): void
    {
        $this->settleableSale(5, 1, gross: 400, commission: 52);

        $first = $this->engine->calculate(5);
        $second = $this->engine->calculate(5);

        $this->assertNotNull($first);
        $this->assertNull($second, 'every eligible entry is already claimed');
    }

    public function test_new_sales_after_a_settlement_form_the_next_one(): void
    {
        $this->settleableSale(5, 1, gross: 400, commission: 52);
        $first = $this->engine->calculate(5);

        $this->settleableSale(5, 2, gross: 250, commission: 25);
        $second = $this->engine->calculate(5);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(225.0, $second->net_amount, 'only the new period');
    }

    // ---- pending money is not settleable ----

    public function test_entries_still_inside_the_return_window_are_excluded(): void
    {
        $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 400,
            status: VendorLedgerEntry::STATUS_PENDING, referenceType: 'order_details', referenceId: 1);

        $this->assertNull($this->engine->calculate(5), 'money in the return window is not payable');
    }

    public function test_an_entry_that_matures_is_picked_up_by_the_next_run(): void
    {
        $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 400,
            referenceType: 'order_details', referenceId: 1, availableAt: now()->subDay());

        $this->assertNull($this->engine->calculate(5), 'still pending until released');

        $this->ledger->releaseMatured();

        $this->assertSame(400.0, $this->engine->calculate(5)->net_amount);
    }

    public function test_one_vendors_entries_never_land_in_anothers_settlement(): void
    {
        $this->settleableSale(5, 1, gross: 400, commission: 52);
        $this->settleableSale(9, 2, gross: 999, commission: 99);

        $this->assertSame(348.0, $this->engine->calculate(5)->net_amount);
        $this->assertSame(900.0, $this->engine->calculate(9)->net_amount);
    }

    // ---- approval freezes it ----

    public function test_approval_records_who_and_when(): void
    {
        $this->settleableSale(5, 1, gross: 400, commission: 52);
        $settlement = $this->engine->calculate(5);

        $this->assertTrue($this->engine->approve($settlement, approvedBy: 3));

        $settlement->refresh();
        $this->assertSame(VendorSettlement::STATUS_APPROVED, $settlement->status);
        $this->assertSame(3, (int) $settlement->approved_by);
        $this->assertNotNull($settlement->approved_at);
    }

    public function test_approving_twice_cannot_restamp_who_approved_it(): void
    {
        $this->settleableSale(5, 1, gross: 400, commission: 52);
        $settlement = $this->engine->calculate(5);
        $this->engine->approve($settlement, approvedBy: 3);

        $this->assertFalse($this->engine->approve($settlement->fresh(), approvedBy: 99));
        $this->assertSame(3, (int) $settlement->fresh()->approved_by);
    }

    public function test_an_approved_settlement_cannot_be_cancelled(): void
    {
        $this->settleableSale(5, 1, gross: 400, commission: 52);
        $settlement = $this->engine->calculate(5);
        $this->engine->approve($settlement);

        $this->assertFalse($this->engine->cancel($settlement->fresh()), 'a correction is a new ledger entry');
        $this->assertSame(VendorSettlement::STATUS_APPROVED, $settlement->fresh()->status);
    }

    public function test_cancelling_an_unapproved_settlement_releases_its_claim(): void
    {
        $this->settleableSale(5, 1, gross: 400, commission: 52);
        $settlement = $this->engine->calculate(5);

        $this->assertTrue($this->engine->cancel($settlement, note: 'calculated in error'));

        $this->assertSame(VendorSettlement::STATUS_CANCELLED, $settlement->fresh()->status);
        $this->assertSame(0, VendorLedgerEntry::whereNotNull('settlement_id')->count(), 'entries returned to the pool');
        $this->assertNotNull($this->engine->calculate(5), 'and can be settled again');
    }

    // ---- payment ----

    public function test_paying_moves_the_entries_to_paid(): void
    {
        $this->settleableSale(5, 1, gross: 400, commission: 52);
        $settlement = $this->engine->calculate(5);
        $this->engine->approve($settlement);

        $this->assertTrue($this->engine->markPaid($settlement->fresh(), payoutReference: 'BANK-99'));

        $settlement->refresh();
        $this->assertSame(VendorSettlement::STATUS_PAID, $settlement->status);
        $this->assertSame('BANK-99', $settlement->payout_reference);
        $this->assertSame(2, VendorLedgerEntry::where('status', VendorLedgerEntry::STATUS_PAID)->count());
    }

    /** Paying something nobody approved is exactly what the maker-checker split exists to stop. */
    public function test_an_unapproved_settlement_cannot_be_paid(): void
    {
        $this->settleableSale(5, 1, gross: 400, commission: 52);
        $settlement = $this->engine->calculate(5);

        $this->assertFalse($this->engine->markPaid($settlement));
        $this->assertSame(VendorSettlement::STATUS_CALCULATED, $settlement->fresh()->status);
    }

    // ---- running the whole marketplace ----

    public function test_it_settles_every_vendor_with_money_waiting(): void
    {
        $this->settleableSale(5, 1, gross: 400, commission: 52);
        $this->settleableSale(9, 2, gross: 200, commission: 20);
        $this->ledger->record(11, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 50,
            status: VendorLedgerEntry::STATUS_PENDING, referenceType: 'order_details', referenceId: 3);

        $settlements = $this->engine->calculateForAll();

        $this->assertCount(2, $settlements, 'the vendor whose money is still pending is skipped');
        $this->assertEqualsCanonicalizing([5, 9], array_map(fn ($s) => $s->seller_id, $settlements));
    }

    public function test_the_statement_reads_opening_movements_closing(): void
    {
        // An earlier, already-settled period leaves the ledger with a balance carried forward.
        $this->settleableSale(5, 1, gross: 100, commission: 10);
        $this->engine->calculate(5);

        $this->settleableSale(5, 2, gross: 400, commission: 52);
        $settlement = $this->engine->calculate(5);

        $this->assertSame(90.0, $settlement->opening_balance, 'what the ledger said before this period');
        $this->assertSame(400.0, $settlement->total_credits);
        $this->assertSame(52.0, $settlement->total_debits);
        $this->assertSame(438.0, $settlement->closing_balance, 'opening + movements');
    }
}
