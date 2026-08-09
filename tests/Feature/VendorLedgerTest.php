<?php

namespace Tests\Feature;

use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\VendorLedger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The vendor financial ledger (Phase 3, Stage B).
 *
 * What it replaces: `seller_wallets` holds five mutable decimals updated in place with
 * `->update([...])`, and `seller_wallet_histories` is a flat log with no transaction type, no
 * running balance, no status and no currency. From that data you cannot say why a balance is what
 * it is, which part of it is still inside the return window, or what it read before a payout.
 *
 * The properties asserted here are the ones that make a ledger a ledger:
 *
 *  1. The balance is a **consequence of the entries**, never a stored number.
 *  2. Every entry carries the balance **as of itself**, so a statement prints without re-summing.
 *  3. Recording the same event twice credits **once**.
 *  4. Pending and available are **genuinely separate**, not one figure with a label.
 */
class VendorLedgerTest extends TestCase
{
    private VendorLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('vendor_ledger_entries');
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

        $this->ledger = new VendorLedger();
    }

    // ---- the balance is a consequence of the entries ----

    public function test_the_running_balance_follows_the_entries(): void
    {
        $a = $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 400, referenceType: 'order_details', referenceId: 1);
        $b = $this->ledger->record(5, VendorLedgerEntry::TYPE_COMMISSION_CHARGE, debit: 52, referenceType: 'order_details', referenceId: 1);
        $c = $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 100, referenceType: 'order_details', referenceId: 2);

        $this->assertSame(400.0, $a->balance_after);
        $this->assertSame(348.0, $b->balance_after, 'each entry carries the balance as of itself');
        $this->assertSame(448.0, $c->balance_after);
    }

    public function test_a_debit_reduces_and_a_credit_increases(): void
    {
        $earning = $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 200, referenceType: 'order_details', referenceId: 1);
        $penalty = $this->ledger->record(5, VendorLedgerEntry::TYPE_PENALTY, debit: 30, referenceType: 'case', referenceId: 9);

        $this->assertSame(200.0, $earning->signed_amount);
        $this->assertSame(-30.0, $penalty->signed_amount);
        $this->assertSame(170.0, $penalty->balance_after);
    }

    /** The sale and the marketplace's cut are separate events; netting them destroys the question. */
    public function test_the_earning_and_the_commission_stay_separate(): void
    {
        $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 400, referenceType: 'order_details', referenceId: 1);
        $this->ledger->record(5, VendorLedgerEntry::TYPE_COMMISSION_CHARGE, debit: 52, referenceType: 'order_details', referenceId: 1);

        $commissionCharged = VendorLedgerEntry::where('entry_type', VendorLedgerEntry::TYPE_COMMISSION_CHARGE)->sum('debit');

        // Cast rather than compare the raw value: SQLite returns a float here and MariaDB a
        // decimal string, and the point of the assertion is the number, not its storage type.
        $this->assertSame(52.0, (float) $commissionCharged, 'answerable without re-deriving from orders');
        $this->assertSame(2, VendorLedgerEntry::count());
    }

    // ---- idempotency ----

    public function test_recording_the_same_event_twice_credits_once(): void
    {
        $first = $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 400, referenceType: 'order_details', referenceId: 1);
        $second = $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 400, referenceType: 'order_details', referenceId: 1);

        $this->assertSame($first->id, $second->id, 'the same entry comes back, not a second one');
        $this->assertSame(1, VendorLedgerEntry::count());
        $this->assertSame(400.0, $this->ledger->balances(5)['balance']);
    }

    public function test_the_same_reference_under_a_different_type_is_a_different_event(): void
    {
        $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 400, referenceType: 'order_details', referenceId: 1);
        $this->ledger->record(5, VendorLedgerEntry::TYPE_COMMISSION_CHARGE, debit: 52, referenceType: 'order_details', referenceId: 1);

        $this->assertSame(2, VendorLedgerEntry::count());
    }

    // ---- the buckets are genuinely separate ----

    public function test_pending_available_reserved_and_paid_are_kept_apart(): void
    {
        $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 300, status: VendorLedgerEntry::STATUS_PENDING, referenceType: 'order_details', referenceId: 1);
        $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 200, status: VendorLedgerEntry::STATUS_AVAILABLE, referenceType: 'order_details', referenceId: 2);
        $this->ledger->record(5, VendorLedgerEntry::TYPE_PAYOUT, debit: 50, status: VendorLedgerEntry::STATUS_PAID, referenceType: 'payout', referenceId: 1);

        $balances = $this->ledger->balances(5);

        $this->assertSame(300.0, $balances['pending']);
        $this->assertSame(200.0, $balances['available'], 'what the seller can actually be paid');
        $this->assertSame(-50.0, $balances['paid']);
        $this->assertSame(450.0, $balances['balance']);
    }

    public function test_one_sellers_ledger_is_not_another_sellers(): void
    {
        $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 400, referenceType: 'order_details', referenceId: 1);
        $this->ledger->record(9, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 999, referenceType: 'order_details', referenceId: 2);

        $this->assertSame(400.0, $this->ledger->balances(5)['balance']);
        $this->assertSame(999.0, $this->ledger->balances(9)['balance']);
    }

    // ---- maturing out of the return window ----

    public function test_a_matured_entry_becomes_available_and_an_unmatured_one_does_not(): void
    {
        $matured = $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 100, referenceType: 'order_details', referenceId: 1, availableAt: now()->subDay());
        $waiting = $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 100, referenceType: 'order_details', referenceId: 2, availableAt: now()->addWeek());

        $released = $this->ledger->releaseMatured();

        $this->assertSame(1, $released);
        $this->assertSame(VendorLedgerEntry::STATUS_AVAILABLE, $matured->fresh()->status);
        $this->assertSame(VendorLedgerEntry::STATUS_PENDING, $waiting->fresh()->status, 'still inside its window');
    }

    /** Maturing changes status only. The amounts an entry was created with are never rewritten. */
    public function test_maturing_never_touches_the_amounts(): void
    {
        $entry = $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 100, referenceType: 'order_details', referenceId: 1, availableAt: now()->subDay());

        $this->ledger->releaseMatured();

        $this->assertSame(100.0, $entry->fresh()->credit);
        $this->assertSame(100.0, $entry->fresh()->balance_after);
    }

    // ---- corrections are entries, not edits ----

    public function test_a_correction_is_a_new_entry_leaving_the_original_intact(): void
    {
        $original = $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 400, referenceType: 'order_details', referenceId: 1);

        $this->ledger->record(5, VendorLedgerEntry::TYPE_MANUAL_ADJUSTMENT, debit: 400, referenceType: 'correction', referenceId: 1, description: 'entered in error');

        $this->assertSame(400.0, $original->fresh()->credit, 'the original is untouched');
        $this->assertSame(0.0, $this->ledger->balances(5)['balance']);
        $this->assertSame(2, VendorLedgerEntry::count(), 'the history shows both the mistake and the fix');
    }

    public function test_a_missing_table_returns_empty_balances_rather_than_fataling(): void
    {
        Schema::dropIfExists('vendor_ledger_entries');

        $this->assertSame(0.0, $this->ledger->balances(5)['balance']);
        $this->assertNull($this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 100));
    }
}
