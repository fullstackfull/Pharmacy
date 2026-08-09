<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller identity / business verification — KYC (Phase 3, Stage A).
 *
 * Measured: `sellers` carries a single coarse `status` (approved/pending/denied) for the whole
 * account and some bank fields — but nothing structured about identity or business documents: no
 * document type, no per-document review state, no reviewer, no expiry. Approving a seller is one
 * boolean decision with no record of what was checked. There is no KYC table anywhere in the schema.
 *
 * This is the missing structure: one row per submitted document, each with its own review lifecycle
 * (pending → approved / rejected, and approved documents can carry an expiry). The seller's overall
 * KYC standing is *derived* from these rows against a configurable required-document set, so the
 * account `status` is left exactly as it is — this table adds a dimension, it does not migrate or
 * replace anything.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_verification_documents')) {
            return;
        }

        Schema::create('seller_verification_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');

            // What kind of document this is — 'identity', 'business_license', 'tax_certificate',
            // 'bank_proof', etc. Kept as free-ish text keyed against a configurable required set,
            // because the required documents differ by market and this platform is not tied to one.
            $table->string('document_type', 60);
            $table->string('document_number', 120)->nullable();

            // The uploaded file's stored name (via the platform's ImageManager). Nullable so a
            // number-only document (e.g. a tax id with no scan) is still representable.
            $table->string('file_path', 255)->nullable();

            // pending on submit; a reviewer moves it to approved or rejected. 'expired' is a derived
            // state the resolver computes from expires_at, but is also storable for a sweep job.
            $table->string('status', 20)->default('pending');
            $table->string('rejection_reason', 512)->nullable();

            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            // Some documents expire (a licence, a certificate). Null = never expires. The resolver
            // treats an approved-but-past-expiry document as not satisfying its requirement.
            $table->date('expires_at')->nullable();

            $table->string('notes', 512)->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'document_type'], 'svd_seller_type_idx');
            $table->index('status', 'svd_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_verification_documents');
    }
};
