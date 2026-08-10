<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which admin marked a settlement paid, so the (opt-in) approver≠payer separation-of-duties
 * control can compare it against approved_by. Additive and guarded; the settlement flow works unchanged
 * when the column is absent.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('vendor_settlements') && !Schema::hasColumn('vendor_settlements', 'paid_by')) {
            Schema::table('vendor_settlements', function (Blueprint $table) {
                $table->unsignedBigInteger('paid_by')->nullable()->after('approved_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vendor_settlements') && Schema::hasColumn('vendor_settlements', 'paid_by')) {
            Schema::table('vendor_settlements', function (Blueprint $table) {
                $table->dropColumn('paid_by');
            });
        }
    }
};
