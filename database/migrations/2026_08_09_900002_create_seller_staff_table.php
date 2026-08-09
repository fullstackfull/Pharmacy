<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller staff members (Phase 3, Stage A — Seller Center).
 *
 * A staff member belongs to a seller and is assigned a role, whose permissions define what they may
 * do. Credentials are stored (email + hashed password) so the deferred login step needs only the guard,
 * not a data change. Until that guard exists these are records the seller manages, not yet accounts
 * that can sign in — stated plainly rather than implied.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_staff')) {
            return;
        }

        Schema::create('seller_staff', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('seller_role_id')->nullable();
            $table->string('name', 120);
            $table->string('email', 191);
            $table->string('password', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['seller_id', 'email'], 'ss_seller_email_unique');
            $table->index(['seller_id', 'status'], 'ss_seller_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_staff');
    }
};
