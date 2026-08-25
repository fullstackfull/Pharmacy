<?php

namespace Tests\Feature;

use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\SellerLedgerStatementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Wave 5's definition of done: the seller's money, on the same numbers the app reads.
 *
 * Six finance destinations the navigation has named since Wave 1 and none of them resolved, so every
 * finance question a seller had ended at the classic wallet page — one balance, no account of how it
 * got there, and no way to see what the marketplace had taken.
 *
 * The rule these tests exist to hold is the one that would be easiest to get wrong while looking
 * right: the buckets above the table are the WHOLE account and are not narrowed by the filter under
 * them. A seller reading last week still needs to know what they can withdraw today, and an
 * "available" figure that silently meant "available, of last week's entries" would be worse than no
 * figure at all — it would be a number they would act on.
 */
class SellerCenterWave5Test extends TestCase
{
    private const SELLER = 1;
    private const RIVAL = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['vendor_ledger_entries', 'business_settings', 'settings', 'order_details', 'vendor_payout_requests', 'seller_settlements'] as $table) {
            Schema::dropIfExists($table);
        }

        foreach (['business_settings', 'settings'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('type')->nullable();
                $t->string('key')->nullable();
                $t->text('value')->nullable();
                $t->timestamps();
            });
        }

        Schema::create('vendor_ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('seller_id');
            $t->string('seller_is', 12)->default('seller');
            $t->string('entry_type', 32);
            $t->decimal('debit', 24, 2)->default(0);
            $t->decimal('credit', 24, 2)->default(0);
            $t->decimal('balance_after', 24, 2)->default(0);
            $t->string('status', 16)->default('available');
            $t->timestamp('available_at')->nullable();
            $t->string('currency', 8)->nullable();
            $t->string('reference_type', 32)->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('description')->nullable();
            $t->unsignedBigInteger('settlement_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('created_by_type', 16)->nullable();
            $t->timestamps();
        });
    }

    private function entry(array $attributes = []): VendorLedgerEntry
    {
        return VendorLedgerEntry::create(array_merge([
            'seller_id' => self::SELLER,
            'seller_is' => 'seller',
            'entry_type' => VendorLedgerEntry::TYPE_ORDER_EARNING,
            'credit' => 100,
            'debit' => 0,
            'balance_after' => 100,
            'status' => VendorLedgerEntry::STATUS_AVAILABLE,
        ], $attributes));
    }

    private function statements(): SellerLedgerStatementService
    {
        return app(SellerLedgerStatementService::class);
    }

    /**
     * The rule the whole screen hangs on. Narrowing the table must not narrow the account.
     */
    public function test_the_withdrawable_figure_is_the_whole_account_not_the_filtered_range(): void
    {
        $old = $this->entry(['credit' => 500]);
        $old->forceFill(['created_at' => now()->subYear()])->save();
        $this->entry(['credit' => 40]);

        $filtered = $this->statements()->summary(self::SELLER, ['from' => now()->toDateString()]);

        $this->assertSame(40.0, $filtered['range']['credits'], 'the range reflects the filter');
        $this->assertSame(540.0, $filtered['withdrawable'], 'the account does not');
    }

    public function test_the_range_totals_follow_the_filter_in_both_directions(): void
    {
        $this->entry(['credit' => 100]);
        $this->entry(['credit' => 0, 'debit' => 30, 'entry_type' => VendorLedgerEntry::TYPE_PAYOUT]);

        $all = $this->statements()->summary(self::SELLER);
        $this->assertSame(100.0, $all['range']['credits']);
        $this->assertSame(30.0, $all['range']['debits']);
        $this->assertSame(70.0, $all['range']['net']);

        $payoutsOnly = $this->statements()->summary(self::SELLER, ['entry_type' => VendorLedgerEntry::TYPE_PAYOUT]);
        $this->assertSame(0.0, $payoutsOnly['range']['credits']);
        $this->assertSame(30.0, $payoutsOnly['range']['debits']);
    }

    /** A filter the ledger does not know is ignored, never applied as an unknown column. */
    public function test_an_entry_type_outside_the_ledgers_own_vocabulary_is_ignored(): void
    {
        $this->entry(['credit' => 100]);

        $this->assertSame(1, $this->statements()->statement(self::SELLER, ['entry_type' => 'not_a_type'])->total());
    }

    public function test_a_rival_shops_ledger_is_never_read(): void
    {
        $this->entry(['credit' => 100]);
        $this->entry(['credit' => 999, 'seller_id' => self::RIVAL]);

        $this->assertSame(100.0, $this->statements()->summary(self::SELLER)['range']['credits']);
        $this->assertSame(1, $this->statements()->statement(self::SELLER)->total());
    }

    /**
     * The balance is READ, never recomputed. It is what the balance actually was when the line was
     * written, and it is the only version of it a dispute can be settled on.
     */
    public function test_a_row_reports_the_balance_the_line_recorded_rather_than_a_running_total(): void
    {
        $this->entry(['credit' => 100, 'balance_after' => 4242.42]);

        $rows = $this->statements()->rows($this->statements()->statement(self::SELLER)->items());

        $this->assertSame(4242.42, $rows[0]['balance_after']);
    }

    public function test_a_date_range_narrows_the_statement(): void
    {
        $old = $this->entry();
        $old->forceFill(['created_at' => now()->subDays(10)])->save();
        $this->entry();

        $this->assertSame(1, $this->statements()->statement(self::SELLER, ['from' => now()->toDateString()])->total());
        $this->assertSame(2, $this->statements()->statement(self::SELLER)->total());
    }
}
