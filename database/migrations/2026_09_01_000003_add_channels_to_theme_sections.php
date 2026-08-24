<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which surfaces a section is for.
 *
 * `platforms` already answers "web or app", and it stays exactly as it is — it is what every
 * published section uses today and what the builder's Visibility tab writes. This column answers a
 * different question that platform cannot: WHICH app. A section meant for the seller app and not
 * the shopping app is not expressible as a platform, because both are `app`.
 *
 * Null means every channel, like every other targeting column here, so nothing changes for a
 * section nobody restricted — which is all of them.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('theme_sections') && !Schema::hasColumn('theme_sections', 'channels')) {
            Schema::table('theme_sections', function (Blueprint $table) {
                $table->json('channels')->nullable()->after('platforms');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('theme_sections', 'channels')) {
            Schema::table('theme_sections', function (Blueprint $table) {
                $table->dropColumn('channels');
            });
        }
    }
};
