<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The receipt for a bulk operation.
 *
 * A seller updating four hundred prices needs to know afterwards which four hundred actually
 * changed. Without a record the operation is a shrug: the request returns, some rows moved, some did
 * not, and nobody can say which. That is worse than not offering the feature — a seller who believes
 * a price change landed and finds out from a customer has been misled by their own tools.
 *
 * So every bulk operation writes one of these first and updates it as it goes, and every row that
 * did not do what was asked is recorded with the reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_bulk_jobs')) {
            return;
        }

        Schema::create('seller_bulk_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');

            /** Who asked, when it was staff rather than the owner — a bulk price change is worth attributing. */
            $table->unsignedBigInteger('created_by_staff_id')->nullable();

            /** What kind of operation, e.g. price_update. */
            $table->string('type', 60);

            /** queued | processing | completed | partial | failed */
            $table->string('status', 20)->default('queued');

            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);

            /**
             * Every row that did not do what was asked, with its reason.
             *
             * Kept on the job rather than in a separate table: a failure list is only ever read with
             * its job, is bounded by the size of the request, and has no life of its own.
             */
            $table->json('failures')->nullable();

            /** What was asked for, so a job can be read back or repeated. */
            $table->json('input')->nullable();

            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // The seller's own job list, newest first.
            $table->index(['seller_id', 'created_at']);
            $table->index(['seller_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_bulk_jobs');
    }
};
