<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exchange-rate change history (Phase 3, Stage E).
 *
 * Measured: the platform already does multi-currency — `currencies` carries an `exchange_rate`, there
 * is a `currency_model` setting, and `usd_to_currency` / `currency_to_usd` helpers convert prices. What
 * it lacks is **governance of the rates themselves**: `exchange_rate` is a single mutable number with
 * no record of who changed it, when, or from what — and no way to update several at once.
 *
 * This does NOT re-implement currencies or conversion. It adds an append-only log of every rate change
 * on top of the existing table, so an FX move is auditable, plus the storage a bulk-update tool writes
 * through. Nothing here changes how prices are converted.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exchange_rate_logs')) {
            return;
        }

        Schema::create('exchange_rate_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('currency_id');
            $table->string('code', 10)->nullable();
            $table->decimal('old_rate', 18, 6)->nullable();
            $table->decimal('new_rate', 18, 6);
            $table->string('source', 20)->default('manual');   // manual | bulk | api
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->index(['currency_id', 'created_at'], 'erl_currency_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rate_logs');
    }
};
