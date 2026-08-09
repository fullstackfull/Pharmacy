<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abandoned-cart reminders (Phase 2.4).
 *
 * The store has no abandoned-cart feature of any kind — measured, not assumed: the string
 * "abandon" appears nowhere in app/, Modules/, database/ or routes/.
 *
 * This table is the *sent* ledger, deliberately separate from `carts`:
 *
 *  - `carts` rows are deleted the moment an order is placed. If the record of having emailed
 *    someone lived on the cart, it would vanish exactly when the conversion happened — the one
 *    outcome worth measuring.
 *  - A cart group can receive more than one reminder in a sequence, so the relationship is
 *    one-to-many, not a column.
 *  - It must be safe to re-run the sending command. The (cart_group_id, stage) unique key is what
 *    makes a second run a no-op rather than a second email to the same person.
 *
 * `cart_group_id` is a string generated per vendor group, not a foreign key, and it intentionally
 * outlives the cart rows it names — that is the whole point of keeping the ledger.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('abandoned_cart_reminders')) {
            return;
        }

        Schema::create('abandoned_cart_reminders', function (Blueprint $table) {
            $table->id();

            $table->string('cart_group_id', 191);

            // Who it went to, captured at send time. Denormalised on purpose: the customer may
            // change their address later, and the ledger has to say where the mail actually went.
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(0);
            $table->string('email', 255);

            // Position in the sequence (1 = first nudge). Unique with the group so a re-run cannot
            // send the same stage twice.
            $table->unsignedTinyInteger('stage')->default(1);

            // What the cart was worth when the reminder went out, so recovery can be reported in
            // money rather than in counts.
            $table->decimal('cart_value', 24, 2)->default(0);
            $table->unsignedInteger('item_count')->default(0);

            $table->timestamp('sent_at')->nullable();

            // Set when the recovery link is followed, and again when an order lands for the group.
            // Kept as two fields because a click is not a conversion.
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->string('recovered_order_id', 191)->nullable();

            $table->timestamps();

            $table->unique(['cart_group_id', 'stage'], 'acr_group_stage_unique');
            $table->index('email', 'acr_email_index');
            $table->index('sent_at', 'acr_sent_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abandoned_cart_reminders');
    }
};
