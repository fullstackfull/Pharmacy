<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The table four separate consumers already read and nothing ever created.
 *
 * `config/queue.php` names `failed_jobs` as the failed-job store, and `SystemHealthService`,
 * `QueueCollector`, `OrderIntegrityPanel` and `DatabaseSettingController` all query it. No migration
 * created it. So a queued job that exhausted its retries did not land anywhere at all: the framework
 * tried to record the failure, could not, and the work vanished with no row, no alert and nothing for
 * the health checks to find. Every one of those checks has been reporting on a table that does not
 * exist.
 *
 * This matters more than a missing dashboard number. Retry policy, dead-lettering and any
 * self-healing built on top of the queue are meaningless while a final failure leaves no trace —
 * "it was retried three times and then gave up" and "it never ran" are indistinguishable without
 * this row.
 *
 * The shape is Laravel's own, so `queue:failed`, `queue:retry` and `queue:flush` work against it
 * without configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('failed_jobs')) {
            return;
        }

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
    }
};
