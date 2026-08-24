<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a version exists, in the merchant's own words.
 *
 * The version list answers what changed only by inviting a comparison: version 14 is published,
 * 13 is archived, and which one had the Ramadan hero is a question nobody can answer three months
 * later. Rolling back is the moment that costs — a merchant restoring "the one before the mistake"
 * is picking from a list of numbers and timestamps.
 *
 * `change_note` is that missing sentence, written when the draft is published and shown wherever
 * the version is listed. Nullable, because every version that exists today has none and a version
 * published without one is still perfectly valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('theme_versions') || Schema::hasColumn('theme_versions', 'change_note')) {
            return;
        }

        Schema::table('theme_versions', function (Blueprint $table) {
            $table->string('change_note', 300)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('theme_versions') || !Schema::hasColumn('theme_versions', 'change_note')) {
            return;
        }

        Schema::table('theme_versions', function (Blueprint $table) {
            $table->dropColumn('change_note');
        });
    }
};
