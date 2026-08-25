<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which model writes seller content, and how creative it is allowed to be.
 *
 * Both were hardcoded in OpenAIProvider, so an operator could switch AI vendors from the admin
 * screen and could not change the model or the cost per call. Nullable, and the provider falls back
 * to the values the constants held, so an install that never touches the field is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_settings')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_settings', 'model')) {
                $table->string('model', 96)->nullable()->after('organization_id');
            }
            if (!Schema::hasColumn('ai_settings', 'temperature')) {
                $table->decimal('temperature', 3, 2)->nullable()->after('model');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ai_settings')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table) {
            foreach (['model', 'temperature'] as $column) {
                if (Schema::hasColumn('ai_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
