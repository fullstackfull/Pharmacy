<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Syria — parcel linkage & status ledger (optional courier integration).
 *
 * When an order is dispatched to Delivery Syria the courier returns a `parcel_id`, a `tracking_number`
 * and a `wallet_status` (deducted | pending_payment). The order itself already has homes for the human
 * facing courier name and tracking id (`orders.delivery_service_name`,
 * `orders.third_party_delivery_tracking_id`) — those are reused. This table is the integration's own
 * record: it ties an order to its courier parcel, remembers the exact `invoice_no` we sent (so a
 * retry after a timeout can detect the parcel already exists instead of creating a duplicate — the
 * courier rejects a reused invoice_no), and stores the latest courier status reported back over the
 * webhook, so the courier's delivery progress is auditable without overwriting the platform's own
 * financially-loaded order_status.
 *
 * Additive and reversible: a new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delivery_syria_parcels')) {
            return;
        }

        Schema::create('delivery_syria_parcels', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('order_id')->index();

            // The unique reference we send as the courier's `invoice_no`. Unique here so a dispatch is
            // idempotent: a second attempt for the same order finds this row instead of double-shipping.
            $table->string('invoice_no', 191)->unique();

            // Identifiers returned by the courier on a successful create.
            $table->string('parcel_id', 191)->nullable();
            $table->string('tracking_number', 191)->nullable();

            // deducted = shipping charge taken from the courier wallet; pending_payment = parcel created
            // but wallet balance was insufficient. Never inferred from `success` alone.
            $table->string('wallet_status', 40)->nullable();
            $table->decimal('delivery_charge', 24, 2)->nullable();
            $table->string('company_type', 40)->nullable();

            // What we sent / computed for the shipment.
            $table->string('governorate', 191)->nullable();
            $table->decimal('weight', 12, 3)->nullable();

            // Latest status the courier reported over the inbound webhook (0..6) and its label/note.
            $table->unsignedTinyInteger('courier_status')->nullable();
            $table->string('courier_status_label', 60)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('status_updated_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_syria_parcels');
    }
};
