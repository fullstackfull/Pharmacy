<?php

namespace App\Services\Marketplace;

use App\Models\OrderItemCommission;
use App\Models\VendorLedgerEntry;
use App\Models\VendorSettlement;
use Illuminate\Support\Facades\Schema;

/**
 * Financial reconciliation (Phase 3, Stage E).
 *
 * A read-only integrity check over the financial core built in Stage B — the vendor ledger, the
 * commission snapshots, and the settlements. It asserts the invariants those pieces are supposed to
 * hold and reports anything that drifts. It writes nothing; a green report is the expected result and
 * a valuable one — it is the monitor that would catch a future bug the moment it corrupts a balance.
 *
 * Each check returns its scope, how many things it examined, and a list of discrepancies (empty when
 * everything reconciles). Every comparison is derived in PHP from portable aggregate queries, so it
 * runs identically on MariaDB and the SQLite test database.
 */
class ReconciliationService
{
    /** A cent of slack absorbs rounding noise without hiding a real discrepancy. */
    private const EPSILON = 0.01;

    /**
     * Run every check.
     *
     * @return array<int, array{key: string, label: string, scope: string, checked: int, discrepancies: array}>
     */
    public function run(): array
    {
        return [
            $this->ledgerRunningBalanceIntegrity(),
            $this->commissionSnapshotVsLedger(),
            $this->settlementIntegrity(),
        ];
    }

    /**
     * Every seller's last running balance must equal the net of all their entries. This is the core
     * invariant of the running-balance ledger: if `balance_after` was ever maintained wrongly, the
     * cumulative figure and the independent sum diverge here.
     */
    public function ledgerRunningBalanceIntegrity(): array
    {
        $discrepancies = [];
        $checked = 0;

        if (Schema::hasTable('vendor_ledger_entries')) {
            $keys = VendorLedgerEntry::query()
                ->selectRaw('seller_id, seller_is, SUM(credit) as credits, SUM(debit) as debits, MAX(id) as last_id')
                ->groupBy('seller_id', 'seller_is')->get();

            foreach ($keys as $k) {
                $checked++;
                $net = round((float) $k->credits - (float) $k->debits, 2);
                $lastBalance = round((float) VendorLedgerEntry::where('id', $k->last_id)->value('balance_after'), 2);

                if (abs($net - $lastBalance) > self::EPSILON) {
                    $discrepancies[] = [
                        'ref' => "seller {$k->seller_id} ({$k->seller_is})",
                        'expected' => $net,
                        'actual' => $lastBalance,
                        'delta' => round($lastBalance - $net, 2),
                    ];
                }
            }
        }

        return [
            'key' => 'ledger_running_balance',
            'label' => 'Ledger running balance matches the sum of entries',
            'scope' => 'per seller',
            'checked' => $checked,
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * Every commission snapshot has a matching ledger `commission_charge`. At order time each line
     * writes both, so the totals must agree; a divergence means a charge was recorded without its
     * snapshot or vice versa.
     */
    public function commissionSnapshotVsLedger(): array
    {
        $discrepancies = [];
        $checked = 0;

        if (Schema::hasTable('order_item_commissions') && Schema::hasTable('vendor_ledger_entries')) {
            $checked = 1;
            $snapshotTotal = round((float) OrderItemCommission::sum('commission_amount'), 2);
            $ledgerTotal = round((float) VendorLedgerEntry::where('entry_type', VendorLedgerEntry::TYPE_COMMISSION_CHARGE)->sum('debit'), 2);

            if (abs($snapshotTotal - $ledgerTotal) > self::EPSILON) {
                $discrepancies[] = [
                    'ref' => 'all commission',
                    'expected' => $snapshotTotal,
                    'actual' => $ledgerTotal,
                    'delta' => round($ledgerTotal - $snapshotTotal, 2),
                ];
            }
        }

        return [
            'key' => 'commission_snapshot_vs_ledger',
            'label' => 'Commission snapshots equal the ledger commission charges',
            'scope' => 'global total',
            'checked' => $checked,
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * Each settlement's claimed entries must sum to its stored net, and their count must match. A
     * settlement is meant to be an immutable roll-up of the entries it claimed via settlement_id, so
     * the roll-up and the entries can never disagree.
     */
    public function settlementIntegrity(): array
    {
        $discrepancies = [];
        $checked = 0;

        if (Schema::hasTable('vendor_settlements') && Schema::hasTable('vendor_ledger_entries')) {
            foreach (VendorSettlement::query()->cursor() as $settlement) {
                $checked++;
                $rows = VendorLedgerEntry::where('settlement_id', $settlement->id)
                    ->selectRaw('SUM(credit) as credits, SUM(debit) as debits, COUNT(*) as n')->first();

                $net = round((float) ($rows->credits ?? 0) - (float) ($rows->debits ?? 0), 2);
                $storedNet = round((float) $settlement->net_amount, 2);
                $count = (int) ($rows->n ?? 0);

                if (abs($net - $storedNet) > self::EPSILON) {
                    $discrepancies[] = [
                        'ref' => "settlement {$settlement->reference}",
                        'expected' => $storedNet,
                        'actual' => $net,
                        'delta' => round($net - $storedNet, 2),
                    ];
                } elseif ($settlement->entry_count !== null && (int) $settlement->entry_count !== $count) {
                    $discrepancies[] = [
                        'ref' => "settlement {$settlement->reference} (entry count)",
                        'expected' => (int) $settlement->entry_count,
                        'actual' => $count,
                        'delta' => $count - (int) $settlement->entry_count,
                    ];
                }
            }
        }

        return [
            'key' => 'settlement_integrity',
            'label' => 'Settlements match the entries they claimed',
            'scope' => 'per settlement',
            'checked' => $checked,
            'discrepancies' => $discrepancies,
        ];
    }

    /** True when every check reconciles. */
    public function isClean(array $results): bool
    {
        foreach ($results as $r) {
            if (!empty($r['discrepancies'])) {
                return false;
            }
        }

        return true;
    }
}
