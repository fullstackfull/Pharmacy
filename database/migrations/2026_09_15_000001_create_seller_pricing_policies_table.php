<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The floor a seller sets under their own prices.
 *
 * The detector already reports selling below cost, which is worth having and is not enough: by the
 * time a finding appears, the price has been live and orders may have been taken at it. A floor is
 * the same knowledge applied one step earlier, at the moment the price is written.
 *
 * One row per shop. A per-product floor would be more precise and far less likely to be maintained,
 * and a floor nobody keeps up to date is a floor that eventually blocks a legitimate sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_pricing_policies')) {
            return;
        }

        Schema::create('seller_pricing_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->unique();
            $table->unsignedBigInteger('updated_by_staff_id')->nullable();

            /**
             * Never sell below cost plus this much.
             *
             * Only meaningful where a purchase price is recorded. A product with no cost has no
             * margin to compute, and the policy says nothing about it rather than guessing one.
             */
            $table->decimal('min_margin_percent', 8, 2)->nullable();

            /** An absolute floor, for shops that think in prices rather than margins. */
            $table->decimal('min_price', 24, 3)->nullable();

            /**
             * Off until the seller turns it on.
             *
             * A floor that starts refusing prices the day it ships would block whatever the shop is
             * already doing, including the things it does on purpose.
             */
            $table->boolean('enforce')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_pricing_policies');
    }
};
