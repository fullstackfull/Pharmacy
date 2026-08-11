<?php

namespace Tests\Feature;

use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\SettlementEngine;
use App\Services\Marketplace\VendorLedger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression for the commission-never-matures P0 (stabilization).
 *
 * OrderManager::recordOrderItemCommission books an order line as two ledger entries: an EARNING credit
 * (commissionable base) and a COMMISSION_CHARGE debit (the marketplace cut). Both are `pending` at
 * placement. releaseMatured() only matures pending entries whose available_at is set
 * (whereNotNull('available_at')). The earning always carried an available_at; the commission debit was
 * booked with a NULL available_at, so it stayed `pending` forever — and then settlement (claims only
 * `available`) settled the GROSS earning and withdrawable (balance − pending) added the pending
 * commission debit back, so the marketplace collected NO commission and a seller could draw the gross.
 *
 * The fix books the commission with the SAME available_at as its earning. This pins that once matured,
 * the settleable/withdrawable value is the NET (gross − commission), and documents the pre-fix behaviour.
 */
class LedgerCommissionMaturationTest extends TestCase
{
    private VendorLedger $ledger;
    private SettlementEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        // Drop orders/order_details so releaseMatured()'s delivered-order gate is skipped and maturation
        // is driven purely by status + available_at (the asymmetry under test).
        foreach (['vendor_ledger_entries', 'vendor_settlements', 'orders', 'order_details'] as $t) {
            Schema::dropIfExists($t);
        }
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
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
        });

        $this->ledger = new VendorLedger();
        $this->engine = new SettlementEngine();
    }

    /** Book an order line the way recordOrderItemCommission does AFTER the fix: both entries share available_at. */
    private function bookLine(int $sellerId, float $base, float $commission, ?\DateTimeInterface $availableAt, int $ref = 1): void
    {
        $this->ledger->record($sellerId, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: $base,
            referenceType: 'order_details', referenceId: $ref, availableAt: $availableAt);
        if ($commission > 0) {
            $this->ledger->record($sellerId, VendorLedgerEntry::TYPE_COMMISSION_CHARGE, debit: $commission,
                referenceType: 'order_details', referenceId: $ref, availableAt: $availableAt);
        }
    }

    public function test_commission_matures_with_its_earning_and_settles_net(): void
    {
        // base 1000, commission 100 -> seller's real take is 900.
        $this->bookLine(5, 1000, 100, now()->subDay());
        $this->ledger->releaseMatured(now());

        $balances = $this->ledger->balances(5);
        $this->assertSame(900.0, $balances['available'], 'the matured line nets to 900 in available');
        $this->assertSame(0.0, $balances['pending'], 'nothing is left pending — the commission matured too');
        $this->assertSame(900.0, $this->ledger->withdrawable(5), 'the seller can draw the NET, not the gross');

        $settlement = $this->engine->calculate(5);
        $this->assertNotNull($settlement);
        $this->assertSame(900.0, (float) $settlement->net_amount, 'the settlement collects commission — net 900, not gross 1000');
    }

    public function test_a_commission_with_no_available_at_would_not_mature_documenting_the_bug(): void
    {
        // The pre-fix booking: earning has an available_at, commission does not.
        $this->ledger->record(6, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 1000,
            referenceType: 'order_details', referenceId: 1, availableAt: now()->subDay());
        $this->ledger->record(6, VendorLedgerEntry::TYPE_COMMISSION_CHARGE, debit: 100,
            referenceType: 'order_details', referenceId: 1, availableAt: null);

        $this->ledger->releaseMatured(now());

        // The commission stays pending -> gross leaks through. This is what the fix prevents.
        $this->assertSame(1000.0, $this->ledger->balances(6)['available'], 'only the earning matured (the bug)');
        $this->assertSame(1000.0, $this->ledger->withdrawable(6), 'balance − pending adds the pending commission back = gross');
    }
}
