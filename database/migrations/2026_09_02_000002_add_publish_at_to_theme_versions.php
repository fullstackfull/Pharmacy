<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a draft should become the shop, without anyone being awake for it.
 *
 * A seasonal home page is prepared days ahead and has to go live at a particular hour — a sale
 * opening at midnight, a Ramadan layout on the first evening. Until now that meant a person at a
 * keyboard at that hour, and the cost of missing it is the campaign running against last week's
 * page.
 *
 * Indexed because the only query that reads it asks "is anything due" every five minutes, forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('theme_versions') || Schema::hasColumn('theme_versions', 'publish_at')) {
            return;
        }

        Schema::table('theme_versions', function (Blueprint $table) {
            $table->timestamp('publish_at')->nullable()->after('published_at')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('theme_versions') || !Schema::hasColumn('theme_versions', 'publish_at')) {
            return;
        }

        Schema::table('theme_versions', function (Blueprint $table) {
            $table->dropIndex(['publish_at']);
            $table->dropColumn('publish_at');
        });
    }
};
