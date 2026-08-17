<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use App\Services\Marketplace\PayoutService;
use App\Services\Marketplace\SettlementEngine;
use App\Services\Marketplace\VendorLedger;
use App\Models\VendorLedgerEntry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The unified audit log (Phase 3 — spec item 84).
 *
 * Two things are proven: the logger records who/what/when/before/after correctly, and — the point of
 * a *unified* log — the financial actions built earlier this stage actually write to it, rather than
 * each keeping a private trail. A logger nothing calls is not a system of record.
 */
class AuditLoggerTest extends TestCase
{
    private AuditLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('audit_logs');
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->string('actor_type', 40)->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name', 191)->nullable();
            $t->string('action', 100);
            $t->string('subject_type', 100)->nullable();
            $t->string('subject_id', 191)->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->json('context')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamps();
        });

        $this->logger = new AuditLogger();
    }

    // ---- the logger itself ----

    public function test_it_records_the_action_and_context(): void
    {
        $log = $this->logger->record(
            action: 'settlement.approved',
            subject: ['type' => 'settlement', 'id' => 42],
            context: ['reference' => 'STL-1', 'net_amount' => 348],
        );

        $this->assertNotNull($log);
        $this->assertSame('settlement.approved', $log->action);
        $this->assertSame('settlement', $log->subject_type);
        $this->assertSame('42', (string) $log->subject_id);
        $this->assertSame('STL-1', $log->context['reference']);
    }

    public function test_the_module_is_taken_from_the_action_prefix(): void
    {
        $log = $this->logger->record(action: 'payout.paid');

        $this->assertSame('payout', $log->module);
    }

    public function test_before_and_after_capture_a_change(): void
    {
        $log = $this->logger->record(
            action: 'seller.bank_details_changed',
            subject: ['type' => 'seller', 'id' => 5],
            before: ['account_no' => 'OLD'],
            after: ['account_no' => 'NEW'],
        );

        $this->assertSame('OLD', $log->before['account_no']);
        $this->assertSame('NEW', $log->after['account_no']);
    }

    public function test_a_system_action_records_no_user_but_still_logs(): void
    {
        $log = $this->logger->record(action: 'settlement.paid');

        $this->assertSame('system', $log->actor_type);
        $this->assertNull($log->actor_id);
    }

    public function test_it_records_an_eloquent_model_by_class_and_key(): void
    {
        $subject = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'audit_logs';
            public $id = 99;
            public function getKey() { return 99; }
        };

        $log = $this->logger->record(action: 'thing.did', subject: $subject);

        $this->assertSame('99', (string) $log->subject_id);
    }

    public function test_a_missing_table_returns_null_rather_than_throwing(): void
    {
        Schema::dropIfExists('audit_logs');

        $this->assertNull($this->logger->record(action: 'x.y'));
    }

    /**
     * The `module` accessor must survive being hydrated on a row where `action` was not selected
     * (a `select('id')` projection), returning a string rather than fataling on null — this is the
     * shape that produced a 500 for one iteration of the audit centre.
     */
    public function test_the_module_accessor_survives_a_row_without_action(): void
    {
        $this->logger->record(action: 'settlement.approved');

        $projected = AuditLog::query()->select('id')->first();

        $this->assertSame('', $projected->module, 'no action selected -> empty module, not a fatal');
    }

    /**
     * The controller derives its module list in PHP, not with a MariaDB-only SUBSTRING_INDEX, so it
     * works on the SQLite test database too. This is that derivation.
     */
    public function test_the_module_list_is_derived_portably(): void
    {
        $this->logger->record(action: 'settlement.approved');
        $this->logger->record(action: 'payout.paid');
        $this->logger->record(action: 'payout.approved');

        $modules = AuditLog::query()->select('action')->distinct()->pluck('action')
            ->map(fn ($a) => str_contains((string) $a, '.') ? explode('.', $a)[0] : $a)
            ->unique()->filter()->values()->all();

        $this->assertEqualsCanonicalizing(['settlement', 'payout'], $modules);
    }

    // ---- filtering, as the audit centre uses it ----

    public function test_it_filters_by_module(): void
    {
        $this->logger->record(action: 'settlement.approved');
        $this->logger->record(action: 'payout.paid');
        $this->logger->record(action: 'payout.approved');

        $this->assertSame(2, AuditLog::inModule('payout')->count());
        $this->assertSame(1, AuditLog::inModule('settlement')->count());
    }

    public function test_a_subject_timeline_reads_newest_first(): void
    {
        $this->logger->record(action: 'payout.approved', subject: ['type' => 'payout', 'id' => 7]);
        $this->logger->record(action: 'payout.paid', subject: ['type' => 'payout', 'id' => 7]);

        $timeline = AuditLog::forSubject('payout', 7)->get();

        $this->assertCount(2, $timeline);
        $this->assertSame('payout.paid', $timeline->first()->action, 'newest first');
    }

    // ---- the point of a UNIFIED log: the real actions write to it ----

    public function test_approving_a_settlement_writes_an_audit_line(): void
    {
        $this->createFinancialTables();
        $ledger = new VendorLedger();
        $engine = new SettlementEngine();

        $ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 400,
            status: VendorLedgerEntry::STATUS_AVAILABLE, referenceType: 'order_details', referenceId: 1);
        $settlement = $engine->calculate(5);

        $engine->approve($settlement, approvedBy: 3);

        $this->assertTrue(
            AuditLog::where('action', 'settlement.approved')->where('subject_id', $settlement->id)->exists(),
            'the settlement approval is on the unified log, not only on the settlement row'
        );
    }

    public function test_paying_a_payout_writes_an_audit_line(): void
    {
        $this->createFinancialTables();
        $ledger = new VendorLedger();
        $payouts = new PayoutService($ledger);

        $ledger->record(5, VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 400,
            status: VendorLedgerEntry::STATUS_AVAILABLE, referenceType: 'order_details', referenceId: 1);
        $request = $payouts->requestPayout(5, 300)['request'];
        $payouts->review($request, approve: true, reviewer: 3);
        $payouts->markPaid($request->fresh(), paymentReference: 'BANK-9');

        $this->assertTrue(AuditLog::where('action', 'payout.approved')->exists());
        $this->assertTrue(AuditLog::where('action', 'payout.paid')->exists());
        $this->assertSame('BANK-9', AuditLog::where('action', 'payout.paid')->first()->context['payment_reference']);
    }

    public function test_a_bank_change_writes_a_before_and_after_audit_line(): void
    {
        $this->createFinancialTables();
        $payouts = new PayoutService(new VendorLedger());

        $payouts->recordBankChange(5, previous: ['account_no' => '111'], current: ['account_no' => '222'], changedBy: 5);

        $log = AuditLog::where('action', 'seller.bank_details_changed')->first();
        $this->assertNotNull($log);
        $this->assertSame('111', $log->before['account_no']);
        $this->assertSame('222', $log->after['account_no']);
    }

    private function createFinancialTables(): void
    {
        Schema::dropIfExists('vendor_ledger_entries');
        Schema::dropIfExists('vendor_settlements');
        Schema::dropIfExists('vendor_payout_requests');
        Schema::dropIfExists('vendor_bank_change_logs');

        Schema::create('vendor_ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('seller_id'); $t->string('seller_is', 20)->default('seller');
            $t->string('entry_type', 40); $t->decimal('debit', 24, 4)->default(0); $t->decimal('credit', 24, 4)->default(0);
            $t->decimal('balance_after', 24, 4)->default(0); $t->string('status', 20)->default('pending');
            $t->timestamp('available_at')->nullable(); $t->string('currency', 10)->nullable();
            $t->string('reference_type', 40)->nullable(); $t->string('reference_id', 191)->nullable();
            $t->string('description', 512)->nullable(); $t->unsignedBigInteger('created_by')->nullable();
            $t->string('created_by_type', 20)->nullable(); $t->unsignedBigInteger('settlement_id')->nullable();
            $t->timestamps();
            $t->unique(['seller_id', 'entry_type', 'reference_type', 'reference_id'], 'vle_idempotency_unique');
        });
        Schema::create('vendor_settlements', function (Blueprint $t) {
            $t->id(); $t->string('reference', 60)->unique(); $t->unsignedBigInteger('seller_id'); $t->string('seller_is', 20)->default('seller');
            $t->timestamp('period_start')->nullable(); $t->timestamp('period_end')->nullable();
            $t->decimal('opening_balance', 24, 4)->default(0); $t->decimal('total_credits', 24, 4)->default(0);
            $t->decimal('total_debits', 24, 4)->default(0); $t->decimal('net_amount', 24, 4)->default(0);
            $t->decimal('closing_balance', 24, 4)->default(0); $t->unsignedInteger('entry_count')->default(0);
            $t->string('currency', 10)->nullable(); $t->string('status', 20)->default('draft'); $t->timestamp('calculated_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable(); $t->timestamp('approved_at')->nullable();
            $t->timestamp('paid_at')->nullable(); $t->string('payout_reference', 191)->nullable(); $t->text('note')->nullable();
            $t->timestamps();
        });
        Schema::create('vendor_payout_requests', function (Blueprint $t) {
            $t->id(); $t->string('reference', 60)->unique(); $t->unsignedBigInteger('seller_id'); $t->string('seller_is', 20)->default('seller');
            $t->decimal('amount', 24, 4); $t->string('currency', 10)->nullable(); $t->string('status', 20)->default('requested');
            $t->string('payout_currency', 10)->nullable(); $t->decimal('payout_amount', 24, 4)->nullable(); $t->decimal('exchange_rate', 20, 8)->nullable();
            $t->string('method', 40)->nullable(); $t->json('method_details')->nullable();
            $t->unsignedBigInteger('reserve_entry_id')->nullable(); $t->unsignedBigInteger('payout_entry_id')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable(); $t->timestamp('reviewed_at')->nullable(); $t->text('review_note')->nullable();
            $t->timestamp('paid_at')->nullable(); $t->string('payment_reference', 191)->nullable(); $t->timestamps();
        });
        Schema::create('vendor_bank_change_logs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('seller_id'); $t->json('previous')->nullable(); $t->json('current')->nullable();
            $t->timestamp('cooling_until')->nullable(); $t->unsignedBigInteger('changed_by')->nullable();
            $t->string('changed_by_type', 20)->nullable(); $t->string('ip_address', 45)->nullable(); $t->timestamps();
        });
    }
}
