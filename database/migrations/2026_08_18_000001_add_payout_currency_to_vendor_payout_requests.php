<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-currency payouts (Phase 3, Stage E follow-up).
 *
 * The vendor ledger stays denominated in the store base currency — the single unit of account for
 * settlements and reconciliation. These additive, nullable columns only snapshot how much to send in
 * the seller's chosen payout currency and the rate used at request time, so the base-currency ledger
 * math is untouched. Null on every existing row => single-currency behavior, exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('vendor_payout_requests')) {
            return;
        }

        Schema::table('vendor_payout_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('vendor_payout_requests', 'payout_currency')) {
                $table->string('payout_currency', 10)->nullable()->after('currency');
            }
            if (!Schema::hasColumn('vendor_payout_requests', 'payout_amount')) {
                $table->decimal('payout_amount', 24, 4)->nullable()->after('payout_currency');
            }
            if (!Schema::hasColumn('vendor_payout_requests', 'exchange_rate')) {
                $table->decimal('exchange_rate', 20, 8)->nullable()->after('payout_amount');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('vendor_payout_requests')) {
            return;
        }

        Schema::table('vendor_payout_requests', function (Blueprint $table) {
            foreach (['payout_currency', 'payout_amount', 'exchange_rate'] as $column) {
                if (Schema::hasColumn('vendor_payout_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
