<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller staff roles (Phase 3, Stage A — Seller Center).
 *
 * Measured: the platform has no seller-staff concept at all — a shop is operated by the single seller
 * account, with no way to grant a colleague scoped access. This is the foundation for that: a named
 * role, owned by a seller, carrying a set of permission keys.
 *
 * This commit builds the role/permission MODEL and its management. It deliberately does not yet add a
 * staff login guard or enforce these permissions on vendor routes — that authentication surface is a
 * larger change, named as the explicit next step. The permission resolver here is the seam that
 * enforcement will call.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_roles')) {
            return;
        }

        Schema::create('seller_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('name', 120);
            $table->json('permissions')->nullable();   // list of permission keys
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status'], 'sr_seller_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_roles');
    }
};
