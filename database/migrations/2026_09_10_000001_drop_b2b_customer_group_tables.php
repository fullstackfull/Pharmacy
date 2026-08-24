<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove B2B group pricing.
 *
 * The marketplace is a single-market consumer marketplace and is not going to sell wholesale. The
 * group-price resolver sat in the cart's pricing path on every add-to-cart — a branch that could
 * only ever lower a price, on a feature nobody operates. Code in a pricing path is a liability
 * whether or not it is used, so it goes.
 *
 * The drop refuses to run on a table that holds rows. All three were verified empty before this was
 * written, but a migration is executed on databases the author never saw, and silently destroying a
 * customer's negotiated prices because a schema file assumed they did not exist is not a risk worth
 * taking to tidy up. A populated table is left standing and the operator can decide.
 */
return new class extends Migration
{
    private const TABLES = ['customer_group_prices', 'customer_group_customer', 'customer_groups'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            if (DB::table($table)->exists()) {
                // Left in place deliberately. Nothing reads it any more — the code is gone — so it is
                // inert data rather than a live feature, and dropping it is the operator's call.
                continue;
            }

            Schema::drop($table);
        }
    }

    /**
     * Recreates the structure, not the data — there was none. Present so the migration is reversible
     * in shape, not because the feature is coming back.
     */
    public function down(): void
    {
        if (!Schema::hasTable('customer_groups')) {
            Schema::create('customer_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('code', 60)->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('customer_group_customer')) {
            Schema::create('customer_group_customer', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_group_id');
                $table->unsignedBigInteger('customer_id');
                $table->timestamps();
                $table->unique(['customer_group_id', 'customer_id'], 'cgc_unique');
            });
        }

        if (!Schema::hasTable('customer_group_prices')) {
            Schema::create('customer_group_prices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_group_id');
                $table->unsignedBigInteger('product_id');
                $table->decimal('price', 24, 3)->nullable();
                $table->decimal('discount_percent', 8, 3)->nullable();
                $table->timestamps();
                $table->unique(['customer_group_id', 'product_id'], 'cgp_unique');
            });
        }
    }
};
