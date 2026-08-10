<?php

namespace Tests\Feature;

use App\Models\OrderItemCommission;
use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\RefundReversalService;
use App\Services\Marketplace\VendorLedger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Unwinding the marketplace's side of a refund (Phase 3, Stage B).
 *
 * The defect this closes: the refund path subtracted the refunded amount from the seller's mutable
 * `total_earning` and said nothing about the commission. **The marketplace kept its cut of a sale
 * it gave back.** The seller lost the full amount; the commission charged on it stayed charged.
 *
 * Three properties are asserted:
 *
 *  1. Both sides reverse — the earning *and* the commission, proportionally.
 *  2. Repeated partial refunds can never reverse more commission than was charged.
 *  3. Nothing is edited. The original entries stay exactly as they were, because the question a
 *     ledger exists to answer must survive a refund.
 */
class RefundReversalTest extends TestCase
{
    private RefundReversalService $reversal;
    private VendorLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('vendor_ledger_entries');
        Schema::dropIfExists('order_item_commissions');

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
        Schema::create('order_item_commissions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('order_details_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('seller_id')->nullable();
            $t->string('seller_is', 20)->default('admin');
            $t->unsignedBigInteger('commission_rule_id')->nullable();
            $t->string('rule_scope_type', 20)->nullable();
            $t->string('rule_label', 191)->nullable();
            $t->string('rate_type', 30)->default('percentage');
            $t->decimal('percentage', 8, 4)->default(0);
            $t->decimal('fixed_amount', 24, 4)->default(0);
            $t->decimal('commissionable_amount', 24, 4)->default(0);
            $t->decimal('commission_amount', 24, 4)->default(0);
            $t->decimal('seller_net_amount', 24, 4)->default(0);
            $t->string('currency', 10)->nullable();
            $t->decimal('reversed_amount', 24, 4)->default(0);
            $t->timestamps();
            $t->unique('order_details_id');
        });

        $this->ledger = new VendorLedger();
        $this->reversal = new RefundReversalService($this->ledger);
    }

    /** A sold line: 400 gross, 52 commission, already on the ledger. */
    private function soldLine(int $orderDetailsId = 17, float $gross = 400, float $commission = 52, string $sellerIs = 'seller'): OrderItemCommission
    {
        $snapshot = OrderItemCommission::create([
            'order_id' => 100002, 'order_details_id' => $orderDetailsId, 'product_id' => 21,
            'seller_id' => 5, 'seller_is' => $sellerIs,
            'rate_type' => 'percentage_plus_fixed', 'percentage' => 12.5, 'fixed_amount' => 2,
            'commissionable_amount' => $gross, 'commission_amount' => $commission,
            'seller_net_amount' => $gross - $commission, 'currency' => 'SYP',
        ]);

        $this->ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: $gross, referenceType: 'order_details', referenceId: $orderDetailsId);
        $this->ledger->record(5, VendorLedgerEntry::TYPE_COMMISSION_CHARGE, debit: $commission, referenceType: 'order_details', referenceId: $orderDetailsId);

        return $snapshot;
    }

    // ---- the defect this closes ----

    public function test_a_full_refund_gives_back_the_commission_too(): void
    {
        $snapshot = $this->soldLine();

        $result = $this->reversal->reverseForOrderLine(17, 400.0, refundReference: 900);

        $this->assertSame(400.0, $result['refund_debit']);
        $this->assertSame(52.0, $result['commission_credit'], 'the marketplace does not keep its cut of a sale it gave back');
        $this->assertSame(52.0, $snapshot->fresh()->reversed_amount);
    }

    public function test_after_a_full_refund_the_seller_is_left_where_they_started(): void
    {
        $this->soldLine();
        $this->assertSame(348.0, $this->ledger->balances(5)['balance'], 'earned 400 less 52 commission');

        $this->reversal->reverseForOrderLine(17, 400.0, refundReference: 900);

        $this->assertSame(0.0, $this->ledger->balances(5)['balance'], 'the sale is fully unwound');
    }

    /**
     * The tax guard: the customer's refund includes tax the seller never earned. Reversing must debit
     * only the ex-tax commissionable base the seller was credited — debiting the tax-inclusive refund
     * dropped the seller's balance below what they were paid, by exactly the tax, on every return.
     */
    public function test_a_tax_inclusive_refund_never_drives_the_seller_below_zero(): void
    {
        $this->soldLine();   // credited 400 ex-tax, commission 52 -> balance 348
        $this->assertSame(348.0, $this->ledger->balances(5)['balance']);

        // The customer is refunded 460 = 400 base + 60 tax (a full-line refund).
        $result = $this->reversal->reverseForOrderLine(17, 460.0, refundReference: 901);

        $this->assertSame(0.0, $this->ledger->balances(5)['balance'], 'unwinds to zero, not minus the tax');
        $this->assertSame(400.0, $result['refund_debit'], 'debited the ex-tax base, not the tax-inclusive refund');
        $this->assertSame(52.0, $result['commission_credit']);
    }

    public function test_a_partial_refund_reverses_the_commission_proportionally(): void
    {
        $snapshot = $this->soldLine();

        // Half the line returned.
        $result = $this->reversal->reverseForOrderLine(17, 200.0, refundReference: 901);

        $this->assertSame(26.0, $result['commission_credit'], 'half the line, half the commission');
        $this->assertSame(26.0, $snapshot->fresh()->reversed_amount);
        $this->assertSame(174.0, $this->ledger->balances(5)['balance'], '348 less 200 plus 26 back');
    }

    // ---- never give back more than was charged ----

    public function test_repeated_partial_refunds_never_reverse_more_than_was_charged(): void
    {
        $snapshot = $this->soldLine();

        $this->reversal->reverseForOrderLine(17, 200.0, refundReference: 901);
        $this->reversal->reverseForOrderLine(17, 200.0, refundReference: 902);
        $this->reversal->reverseForOrderLine(17, 200.0, refundReference: 903);   // more than the line

        $this->assertSame(52.0, $snapshot->fresh()->reversed_amount, 'capped at the commission actually charged');
    }

    public function test_a_refund_larger_than_the_line_still_caps_the_commission(): void
    {
        $snapshot = $this->soldLine();

        $result = $this->reversal->reverseForOrderLine(17, 10000.0, refundReference: 900);

        $this->assertSame(52.0, $result['commission_credit']);
        $this->assertSame(52.0, $snapshot->fresh()->reversed_amount);
    }

    // ---- nothing is edited ----

    public function test_the_original_entries_are_left_exactly_as_they_were(): void
    {
        $snapshot = $this->soldLine();
        $earning = VendorLedgerEntry::where('entry_type', VendorLedgerEntry::TYPE_ORDER_EARNING)->first();
        $charge = VendorLedgerEntry::where('entry_type', VendorLedgerEntry::TYPE_COMMISSION_CHARGE)->first();

        $this->reversal->reverseForOrderLine(17, 400.0, refundReference: 900);

        $this->assertSame(400.0, $earning->fresh()->credit);
        $this->assertSame(52.0, $charge->fresh()->debit);
        $this->assertSame(52.0, $snapshot->fresh()->commission_amount, 'the charge stands; the reversal is separate');
        $this->assertSame(4, VendorLedgerEntry::count(), 'two original entries plus two reversal entries');
    }

    public function test_the_reversal_is_two_entries_that_name_what_they_undo(): void
    {
        $this->soldLine();

        $this->reversal->reverseForOrderLine(17, 400.0, refundReference: 900);

        $refund = VendorLedgerEntry::where('entry_type', VendorLedgerEntry::TYPE_REFUND)->first();
        $adjustment = VendorLedgerEntry::where('entry_type', VendorLedgerEntry::TYPE_RETURN_ADJUSTMENT)->first();

        $this->assertSame(400.0, $refund->debit);
        $this->assertSame(52.0, $adjustment->credit);
        $this->assertStringContainsString('#17', $refund->description);
        $this->assertStringContainsString('Commission reversed', $adjustment->description);
    }

    // ---- the cases that must do nothing ----

    public function test_an_in_house_line_has_no_vendor_side_to_reverse(): void
    {
        $this->soldLine(sellerIs: 'admin');

        $this->assertNull($this->reversal->reverseForOrderLine(17, 400.0, refundReference: 900));
    }

    public function test_a_line_with_no_snapshot_is_skipped_rather_than_guessed_at(): void
    {
        $this->assertNull($this->reversal->reverseForOrderLine(999, 400.0));
    }

    public function test_a_zero_refund_does_nothing(): void
    {
        $this->soldLine();

        $this->assertNull($this->reversal->reverseForOrderLine(17, 0.0));
        $this->assertSame(348.0, $this->ledger->balances(5)['balance']);
    }

    /** Refund accounting must never be the reason a customer fails to be refunded. */
    public function test_a_missing_table_returns_null_rather_than_fataling(): void
    {
        Schema::dropIfExists('order_item_commissions');

        $this->assertNull($this->reversal->reverseForOrderLine(17, 400.0));
    }
}
