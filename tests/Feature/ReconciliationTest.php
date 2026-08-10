<?php

namespace Tests\Feature;

use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\ReconciliationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Financial reconciliation (Phase 3, Stage E).
 *
 * Asserts the checks pass on a consistent financial core and — the point of an integrity monitor —
 * that each one actually catches the drift it is meant to: a corrupted running balance, a commission
 * charge without its snapshot, and a settlement whose entries do not sum to its stored net.
 */
class ReconciliationTest extends TestCase
{
    private ReconciliationService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['vendor_ledger_entries', 'order_item_commissions', 'vendor_settlements'] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('vendor_ledger_entries', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('seller_id'); $t->string('seller_is')->default('seller');
            $t->string('entry_type')->nullable(); $t->decimal('credit', 24, 2)->default(0); $t->decimal('debit', 24, 2)->default(0);
            $t->decimal('balance_after', 24, 2)->default(0); $t->string('status')->default('available');
            $t->timestamp('available_at')->nullable();
            $t->unsignedBigInteger('settlement_id')->nullable(); $t->timestamps();
        });
        Schema::create('order_item_commissions', function (Blueprint $t) {
            $t->id(); $t->decimal('commission_amount', 24, 2)->default(0); $t->decimal('reversed_amount', 24, 2)->default(0); $t->timestamps();
        });
        Schema::create('vendor_settlements', function (Blueprint $t) {
            $t->id(); $t->string('reference'); $t->decimal('net_amount', 24, 2)->default(0); $t->unsignedInteger('entry_count')->nullable(); $t->timestamps();
        });

        $this->svc = new ReconciliationService();
    }

    private function entry(int $seller, float $credit, float $debit, float $balanceAfter, ?string $type = null, ?int $settlementId = null): void
    {
        DB::table('vendor_ledger_entries')->insert([
            'seller_id' => $seller, 'seller_is' => 'seller', 'entry_type' => $type,
            'credit' => $credit, 'debit' => $debit, 'balance_after' => $balanceAfter, 'settlement_id' => $settlementId,
        ]);
    }

    /** A consistent core: earning credit + commission charge debit, a matching snapshot, a clean settlement. */
    private function seedConsistent(): void
    {
        $this->entry(7, 100.00, 0, 100.00, VendorLedgerEntry::TYPE_ORDER_EARNING);
        $this->entry(7, 0, 20.00, 80.00, VendorLedgerEntry::TYPE_COMMISSION_CHARGE);
        DB::table('order_item_commissions')->insert(['commission_amount' => 20.00, 'reversed_amount' => 0]);

        // Settlement claiming the two entries above (net 80), then stamp them with its id.
        $sid = DB::table('vendor_settlements')->insertGetId(['reference' => 'ST-1', 'net_amount' => 80.00, 'entry_count' => 2]);
        DB::table('vendor_ledger_entries')->where('seller_id', 7)->update(['settlement_id' => $sid]);
    }

    public function test_a_consistent_core_reconciles_clean(): void
    {
        $this->seedConsistent();

        $results = $this->svc->run();

        $this->assertTrue($this->svc->isClean($results), 'a correctly-maintained core has no discrepancies');
        foreach ($results as $r) {
            $this->assertSame([], $r['discrepancies'], $r['key'] . ' should be clean');
        }
    }

    public function test_it_catches_a_corrupted_running_balance(): void
    {
        $this->entry(7, 100.00, 0, 100.00);
        $this->entry(7, 0, 20.00, 999.00);   // balance_after should be 80, not 999

        $check = $this->svc->ledgerRunningBalanceIntegrity();

        $this->assertCount(1, $check['discrepancies']);
        $this->assertSame(80.0, $check['discrepancies'][0]['expected']);
        $this->assertSame(999.0, $check['discrepancies'][0]['actual']);
    }

    public function test_it_catches_a_commission_charge_without_a_snapshot(): void
    {
        // A ledger charge of 20 but no matching snapshot row.
        $this->entry(7, 0, 20.00, -20.00, VendorLedgerEntry::TYPE_COMMISSION_CHARGE);

        $check = $this->svc->commissionSnapshotVsLedger();

        $this->assertCount(1, $check['discrepancies']);
        $this->assertSame(0.0, $check['discrepancies'][0]['expected'], 'snapshot total');
        $this->assertSame(20.0, $check['discrepancies'][0]['actual'], 'ledger charge total');
    }

    public function test_it_catches_a_settlement_that_does_not_match_its_entries(): void
    {
        $sid = DB::table('vendor_settlements')->insertGetId(['reference' => 'ST-9', 'net_amount' => 500.00, 'entry_count' => 1]);
        // Only 80 of ledger net is actually claimed by this settlement.
        $this->entry(7, 100.00, 20.00, 80.00, null, $sid);

        $check = $this->svc->settlementIntegrity();

        $this->assertCount(1, $check['discrepancies']);
        $this->assertSame('settlement ST-9', $check['discrepancies'][0]['ref']);
        $this->assertSame(500.0, $check['discrepancies'][0]['expected']);
        $this->assertSame(80.0, $check['discrepancies'][0]['actual']);
    }

    public function test_it_flags_a_pending_earning_with_no_maturation_date(): void
    {
        // A stuck earning: pending, no available_at → it can never mature and the seller can never be paid.
        DB::table('vendor_ledger_entries')->insert([
            'seller_id' => 9, 'seller_is' => 'seller', 'entry_type' => VendorLedgerEntry::TYPE_ORDER_EARNING,
            'credit' => 50, 'debit' => 0, 'balance_after' => 50, 'status' => VendorLedgerEntry::STATUS_PENDING, 'available_at' => null,
        ]);

        $result = $this->svc->pendingEarningsCanMature();

        $this->assertNotEmpty($result['discrepancies'], 'a stuck earning is flagged');
        $this->assertSame(1, $result['discrepancies'][0]['actual']);
    }

    public function test_a_pending_earning_with_a_maturation_date_reconciles(): void
    {
        DB::table('vendor_ledger_entries')->insert([
            'seller_id' => 9, 'seller_is' => 'seller', 'entry_type' => VendorLedgerEntry::TYPE_ORDER_EARNING,
            'credit' => 50, 'debit' => 0, 'balance_after' => 50, 'status' => VendorLedgerEntry::STATUS_PENDING, 'available_at' => now(),
        ]);

        $this->assertEmpty($this->svc->pendingEarningsCanMature()['discrepancies']);
    }

    public function test_missing_tables_reconcile_clean_rather_than_fataling(): void
    {
        foreach (['vendor_ledger_entries', 'order_item_commissions', 'vendor_settlements'] as $t) {
            Schema::dropIfExists($t);
        }

        $this->assertTrue($this->svc->isClean($this->svc->run()));
    }
}
