<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a client needs in order to ask "has anything changed?" without downloading the answer.
 *
 * `revision` is a number that only ever goes up, per theme, one step per publish. A phone that
 * holds revision 41 and is told the server is on 42 knows to refetch; told 41, it knows not to.
 * The row id cannot do this job — publishing a restored draft mints a HIGHER id for OLDER content,
 * and duplicating a draft mints ids that never go live at all.
 *
 * `checksum` is the ETag: a hash of the delivered structure, so a republish that changed nothing
 * a client can see (a relabelled draft, a re-publish of identical content) does not cost every
 * installed app a full download.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('theme_versions')) {
            return;
        }

        Schema::table('theme_versions', function (Blueprint $table) {
            if (!Schema::hasColumn('theme_versions', 'revision')) {
                $table->unsignedInteger('revision')->default(0)->after('status');
            }
            if (!Schema::hasColumn('theme_versions', 'checksum')) {
                $table->string('checksum', 64)->nullable()->after('revision');
            }
        });

        // Anything already published predates the counter, so it becomes revision 1 — a client
        // syncing for the first time after this deploy sees a real number rather than 0.
        if (Schema::hasColumn('theme_versions', 'revision')) {
            \App\Models\ThemeVersion::query()
                ->where('status', \App\Models\ThemeVersion::STATUS_PUBLISHED)
                ->where('revision', 0)
                ->update(['revision' => 1]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('theme_versions')) {
            return;
        }

        Schema::table('theme_versions', function (Blueprint $table) {
            foreach (['revision', 'checksum'] as $column) {
                if (Schema::hasColumn('theme_versions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
