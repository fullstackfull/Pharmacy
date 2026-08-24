<?php

namespace Tests\Feature;

use App\Models\Seller;
use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\SellerLedgerStatementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The seller's account, line by line.
 *
 * Sellers have been shown four totals and never the entries behind them. A total nobody can take
 * apart is a number you either believe or do not, and a seller who cannot reconcile a payout against
 * the orders that produced it has no way to raise a disagreement except to complain about the total.
 *
 * Three things have to hold. The statement is one shop's. The running balance is what the ledger
 * recorded, not a figure recomputed from whatever the seller happened to filter to. And the bucket
 * totals are the whole account even when the list is filtered — "available, of last week's entries"
 * is not a number anybody wants.
 */
class SellerStatementTest extends TestCase
{
    private const OWNER_TOKEN = 'owner-token-long-enough-to-clear-the-length-gate';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['vendor_ledger_entries', 'vendor_payout_requests', 'vendor_settlements', 'order_details', 'sellers', 'business_settings', 'seller_staff', 'seller_roles'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('status', 20)->default('approved');
            $table->string('auth_token')->nullable();
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->timestamps();
        });
        Schema::create('vendor_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('seller_is', 20)->default('seller');
            $table->string('entry_type', 40);
            $table->decimal('debit', 24, 4)->default(0);
            $table->decimal('credit', 24, 4)->default(0);
            $table->decimal('balance_after', 24, 4)->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamp('available_at')->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('reference_type', 60)->nullable();
            $table->string('reference_id', 60)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('settlement_id')->nullable();
            $table->timestamps();
        });
        Schema::create('vendor_payout_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is', 20)->default('seller');
            $table->decimal('amount', 24, 4)->default(0);
            $table->string('status', 20)->default('requested');
            $table->unsignedBigInteger('reserve_entry_id')->nullable();
            $table->unsignedBigInteger('payout_entry_id')->nullable();
            $table->timestamps();
        });
        Schema::create('vendor_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->timestamps();
        });

        Seller::insert([
            ['id' => 1, 'f_name' => 'Owner', 'l_name' => 'One', 'status' => 'approved', 'auth_token' => self::OWNER_TOKEN],
            ['id' => 2, 'f_name' => 'Rival', 'l_name' => 'Two', 'status' => 'approved', 'auth_token' => 'rival-token-long-enough-to-clear-the-gate!!'],
        ]);
    }

    private function entry(array $attributes = []): VendorLedgerEntry
    {
        static $sequence = 0;
        $sequence++;

        return VendorLedgerEntry::create(array_merge([
            'seller_id' => 1,
            'seller_is' => 'seller',
            'entry_type' => VendorLedgerEntry::TYPE_ORDER_EARNING,
            'credit' => 100,
            'debit' => 0,
            'balance_after' => 100 * $sequence,
            'status' => VendorLedgerEntry::STATUS_AVAILABLE,
            'description' => 'Entry ' . $sequence,
        ], $attributes));
    }

    private function statements(): SellerLedgerStatementService
    {
        return app(SellerLedgerStatementService::class);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . self::OWNER_TOKEN, 'Accept' => 'application/json'];
    }

    private function uri(string $path = ''): string
    {
        return rtrim('/api/v3/seller/seller-center/statement/' . ltrim($path, '/'), '/');
    }

    public function test_the_statement_is_one_shops(): void
    {
        $this->entry(['description' => 'Mine']);
        $this->entry(['seller_id' => 2, 'description' => 'Theirs']);

        $body = $this->withHeaders($this->headers())->getJson($this->uri())->json();

        $this->assertSame(1, $body['total_size']);
        $this->assertSame('Mine', $body['entries'][0]['description']);
    }

    public function test_the_running_balance_is_read_rather_than_recomputed(): void
    {
        $this->entry(['credit' => 500, 'balance_after' => 500]);
        $this->entry(['credit' => 0, 'debit' => 200, 'balance_after' => 300]);

        // Filtered to the debit alone. Recomputing from a filtered view would say -200 — a prettier
        // number that never existed. What the ledger recorded is 300.
        $body = $this->withHeaders($this->headers())
            ->getJson($this->uri() . '?entry_type=order_earning&status=available')->json();

        $balances = array_map('floatval', array_column($body['entries'], 'balance_after'));
        $this->assertContains(300.0, $balances);
        $this->assertContains(500.0, $balances);
    }

    public function test_the_buckets_stay_the_whole_account_when_the_list_is_filtered(): void
    {
        $this->entry(['credit' => 500, 'balance_after' => 500, 'status' => VendorLedgerEntry::STATUS_AVAILABLE]);
        $this->entry(['credit' => 300, 'balance_after' => 800, 'status' => VendorLedgerEntry::STATUS_PENDING]);

        $body = $this->withHeaders($this->headers())->getJson($this->uri() . '?status=pending')->json();

        // The list narrowed; the account did not. A seller looking at last week still needs to know
        // what they can withdraw today.
        $this->assertSame(1, $body['total_size']);
        $this->assertEquals(500, $body['summary']['buckets']['available']);
        $this->assertEquals(300, $body['summary']['buckets']['pending']);
        // Balance minus pending: money still inside the return window cannot be drawn.
        $this->assertEquals(500, $body['summary']['withdrawable']);
    }

    public function test_the_range_totals_follow_the_filter(): void
    {
        $this->entry(['credit' => 500, 'debit' => 0]);
        $this->entry(['credit' => 0, 'debit' => 120, 'entry_type' => VendorLedgerEntry::TYPE_COMMISSION_CHARGE]);

        $all = $this->withHeaders($this->headers())->getJson($this->uri())->json('summary.range');
        $charges = $this->withHeaders($this->headers())
            ->getJson($this->uri() . '?entry_type=commission_charge')->json('summary.range');

        $this->assertSame(2, $all['entries']);
        $this->assertEquals(380, $all['net']);

        $this->assertSame(1, $charges['entries']);
        $this->assertEquals(120, $charges['debits']);
        $this->assertEquals(-120, $charges['net']);
    }

    public function test_a_line_traces_back_to_the_order_that_earned_it(): void
    {
        $lineId = DB::table('order_details')->insertGetId([
            'order_id' => 7788, 'seller_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entry(['reference_type' => 'order_details', 'reference_id' => $lineId]);

        $entry = $this->withHeaders($this->headers())->getJson($this->uri())->json('entries.0');

        // The whole point: the balance is not a figure the marketplace asserts, it is the sum of
        // things that happened, each of which can be opened.
        $this->assertSame(7788, $entry['order_id']);
    }

    public function test_a_line_traces_forward_to_the_payout_that_took_it_out(): void
    {
        $reserve = $this->entry([
            'entry_type' => VendorLedgerEntry::TYPE_PAYOUT, 'credit' => 0, 'debit' => 250,
            'status' => VendorLedgerEntry::STATUS_RESERVED, 'reference_type' => 'payout_reserve',
        ]);
        DB::table('vendor_payout_requests')->insert([
            'reference' => 'PO-20260824-ABC123', 'seller_id' => 1, 'amount' => 250,
            'status' => 'requested', 'reserve_entry_id' => $reserve->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $entry = $this->withHeaders($this->headers())->getJson($this->uri())->json('entries.0');

        $this->assertSame('PO-20260824-ABC123', $entry['payout_reference']);
    }

    public function test_a_line_claimed_by_a_settlement_names_it(): void
    {
        $settlementId = DB::table('vendor_settlements')->insertGetId([
            'reference' => 'ST-202608-0004', 'seller_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entry(['settlement_id' => $settlementId]);

        $entry = $this->withHeaders($this->headers())->getJson($this->uri())->json('entries.0');

        $this->assertSame('ST-202608-0004', $entry['settlement_reference']);
    }

    public function test_a_malformed_date_widens_rather_than_matching_nothing(): void
    {
        $this->entry();

        // A range the client got wrong should not silently produce an empty statement that reads as
        // "you earned nothing".
        $body = $this->withHeaders($this->headers())->getJson($this->uri() . '?from=last-tuesday')->json();

        $this->assertSame(1, $body['total_size']);
    }

    public function test_the_range_can_be_narrowed_by_date(): void
    {
        $old = $this->entry(['description' => 'Old']);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();
        $this->entry(['description' => 'Recent']);

        $body = $this->withHeaders($this->headers())
            ->getJson($this->uri() . '?from=' . now()->subDays(7)->toDateString())->json();

        $this->assertSame(1, $body['total_size']);
        $this->assertSame('Recent', $body['entries'][0]['description']);
    }

    public function test_the_export_carries_the_same_lines_the_statement_shows(): void
    {
        $lineId = DB::table('order_details')->insertGetId([
            'order_id' => 4242, 'seller_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entry(['credit' => 640, 'reference_type' => 'order_details', 'reference_id' => $lineId]);

        $response = $this->withHeaders($this->headers())->get($this->uri('export'));

        $response->assertStatus(200);
        $csv = $response->streamedContent();
        $this->assertStringContainsString('4242', $csv);
        $this->assertStringContainsString('640', $csv);
        $this->assertStringContainsString('order_earning', $csv);
    }

    public function test_reading_the_books_needs_the_finance_permission(): void
    {
        // The gate is on the route, not on a hidden menu item, and it is the reading permission
        // rather than the one that moves money.
        $this->getJson($this->uri())->assertStatus(401);
        $this->getJson($this->uri('export'))->assertStatus(401);
    }
}
