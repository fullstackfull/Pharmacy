<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Developer Portal's own storage.
 *
 * Almost nothing about the API is stored — the manifest is derived from the live route table on
 * demand precisely so it cannot drift. What has to be stored is HISTORY, because history is the
 * one thing the current code cannot tell you: what the API looked like before this release, and
 * therefore what changed and whether it broke somebody.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        | A frozen copy of the API surface at a point in time. Taken at deployment, or by hand
        | before a risky change; diffing two of them is what makes breaking-change detection
        | possible rather than aspirational.
        */
        if (!Schema::hasTable('api_snapshots')) {
            Schema::create('api_snapshots', function (Blueprint $table) {
                $table->id();
                $table->string('label', 96);                      // release name, tag, or a note
                $table->string('app_version', 32)->nullable();
                $table->string('fingerprint', 64)->index();       // the route table's hash
                $table->unsignedInteger('endpoint_count')->default(0);
                // The comparable shape of every endpoint. Stored rather than recomputed because
                // the whole point is to remember what the code no longer says.
                $table->longText('payload');
                $table->unsignedBigInteger('captured_by')->nullable();
                $table->timestamp('captured_at')->index();

                $table->index(['label', 'captured_at'], 'api_snapshot_history');
            });
        }

        /*
        | The changelog, one row per detected change.
        |
        | Generated from snapshot diffs rather than written by hand — a changelog somebody has to
        | remember to update is a changelog that is wrong after the second busy week. Entries can
        | be annotated afterwards, but they exist whether or not anybody annotates them.
        */
        if (!Schema::hasTable('api_changes')) {
            Schema::create('api_changes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('snapshot_id')->index();
                $table->string('endpoint_id', 32)->index();
                $table->string('endpoint', 191);                  // "POST /api/v2/orders"
                // added | removed | changed
                $table->string('change_type', 16);
                // What specifically changed: method_removed, required_param_added, auth_changed…
                $table->string('detail_type', 48);
                $table->text('detail')->nullable();
                // none | warning | breaking
                $table->string('severity', 12)->default('none');
                $table->string('audience', 24)->nullable();
                $table->string('version', 12)->nullable();
                $table->text('note')->nullable();                 // an operator's annotation
                $table->timestamp('detected_at')->index();

                $table->index(['severity', 'detected_at'], 'api_change_severity');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_changes');
        Schema::dropIfExists('api_snapshots');
    }
};
