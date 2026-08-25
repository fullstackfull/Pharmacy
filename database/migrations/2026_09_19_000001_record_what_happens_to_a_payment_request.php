<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a payment failed, and which order it belonged to.
 *
 * The gateway ledger recorded only `is_paid`. A declined card, a gateway timeout and a shopper who
 * closed the tab produced byte-identical rows, so no failure reason could be shown for any of them
 * and every payment reconciliation on the monitoring page was best-effort: `attribute_id` holds a
 * unix timestamp rather than an order id, leaving `orders.transaction_ref = payment_requests
 * .transaction_id` — varchar(30) against varchar(100), nullable on both sides — as the only join.
 *
 * Additive only, and every column nullable: this runs against a live gateway table on a shop that is
 * taking money while it runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_requests')) {
            return;
        }

        Schema::table('payment_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_requests', 'status')) {
                // started | succeeded | failed | abandoned
                $table->string('status', 12)->nullable()->after('is_paid');
            }
            if (!Schema::hasColumn('payment_requests', 'failure_code')) {
                $table->string('failure_code', 64)->nullable()->after('status');
            }
            if (!Schema::hasColumn('payment_requests', 'failure_message')) {
                $table->string('failure_message', 500)->nullable()->after('failure_code');
            }
            if (!Schema::hasColumn('payment_requests', 'finalized_at')) {
                $table->timestamp('finalized_at')->nullable()->after('failure_message');
            }
            if (!Schema::hasColumn('payment_requests', 'attempts')) {
                $table->unsignedSmallInteger('attempts')->default(0)->after('finalized_at');
            }
            if (!Schema::hasColumn('payment_requests', 'order_id')) {
                // The join the reconciliations have never had. Set by the success hook.
                $table->unsignedBigInteger('order_id')->nullable()->after('attribute_id');
            }
        });

        Schema::table('payment_requests', function (Blueprint $table) {
            foreach ([
                'payment_request_status_window' => ['status', 'created_at'],
                'payment_request_method_window' => ['payment_method', 'created_at'],
            ] as $name => $columns) {
                if (!$this->hasIndex($name)) {
                    $table->index($columns, $name);
                }
            }

            if (!$this->hasIndex('payment_request_order')) {
                $table->index('order_id', 'payment_request_order');
            }
        });
    }

    public function down(): void
    {
        // Deliberately not reversed. Dropping columns from a live gateway ledger to undo an additive
        // migration risks the one table whose loss cannot be reconstructed from anywhere else.
    }

    private function hasIndex(string $name): bool
    {
        try {
            foreach (Schema::getConnection()->getSchemaBuilder()->getIndexes('payment_requests') as $index) {
                if (($index['name'] ?? null) === $name) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // An unreadable index list is not a reason to skip the migration; a duplicate-index
            // error would be, so the caller only ever adds when this says no.
        }

        return false;
    }
};
