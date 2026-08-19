<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A banner can carry a second, phone-shaped image for the apps. The web keeps
 * using `photo`; where a banner has no mobile image the apps fall back to it,
 * so every existing banner keeps working untouched.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('banners', 'mobile_photo')) {
            Schema::table('banners', function (Blueprint $table) {
                $table->string('mobile_photo')->nullable()->after('photo');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('banners', 'mobile_photo')) {
            Schema::table('banners', function (Blueprint $table) {
                $table->dropColumn('mobile_photo');
            });
        }
    }
};
