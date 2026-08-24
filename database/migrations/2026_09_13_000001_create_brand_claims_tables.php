<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is allowed to sell under a brand, and on what evidence.
 *
 * A marketplace with no answer to this has two failures waiting. A seller who owns a brand watches
 * somebody else list counterfeits under their name and has nowhere to complain; and a seller who is
 * a legitimate authorised reseller has no way to prove it, so any enforcement the marketplace does
 * introduce would sweep them up with the counterfeiters.
 *
 * Two tables rather than one because a claim and its evidence have different lifetimes. A claim is
 * reviewed once and then stands; documents are added, replaced when they expire, and — being
 * trademark certificates and letters of authority — have to be individually deletable without
 * destroying the claim's history.
 *
 * Nothing here computes a verification. A claim's status is set by a person who looked at the
 * documents, and stays whatever they set it to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('brand_claims')) {
            Schema::create('brand_claims', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('brand_id');
                $table->unsignedBigInteger('seller_id');

                /** owner | authorized_reseller | distributor — what the seller says they are. */
                $table->string('claim_type', 40);

                /** draft | submitted | under_review | approved | rejected | revoked | expired */
                $table->string('status', 20)->default('draft');

                /** The seller's own account of the relationship, in their words. */
                $table->text('statement')->nullable();

                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->string('review_note', 500)->nullable();

                /**
                 * When the authority itself runs out.
                 *
                 * A letter of authority is dated; an approval that outlives it is a claim nobody
                 * checked. Null for an ownership claim, which does not expire on a schedule.
                 */
                $table->timestamp('expires_at')->nullable();

                $table->timestamps();

                // One live claim per seller per brand. A second is an edit of the first, not a
                // second opinion, and two approved claims of different types would leave the gate
                // asking which one counts.
                $table->unique(['brand_id', 'seller_id'], 'brand_claims_brand_seller_unique');
                $table->index(['status', 'submitted_at'], 'brand_claims_queue_idx');
                $table->index(['seller_id', 'status'], 'brand_claims_seller_idx');
            });
        }

        if (!Schema::hasTable('brand_claim_documents')) {
            Schema::create('brand_claim_documents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('brand_claim_id');
                $table->unsignedBigInteger('seller_id');

                /** trademark_certificate | authorization_letter | invoice | other */
                $table->string('document_type', 40);

                /** The stored filename on the private disk. Never a public URL. */
                $table->string('file_path', 191);

                /** What the seller called it, for a list a person can read. */
                $table->string('original_name', 191)->nullable();

                $table->string('reference', 120)->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['brand_claim_id'], 'brand_claim_documents_claim_idx');
                $table->index(['seller_id'], 'brand_claim_documents_seller_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_claim_documents');
        Schema::dropIfExists('brand_claims');
    }
};
