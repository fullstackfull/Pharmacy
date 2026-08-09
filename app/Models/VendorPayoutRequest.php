<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A seller's request to be paid, over the ledger (Phase 3, Stage B).
 */
class VendorPayoutRequest extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    /** Statuses in which the amount is still reserved and can be released back. */
    public const OPEN_STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_UNDER_REVIEW, self::STATUS_APPROVED, self::STATUS_PROCESSING,
    ];

    protected $fillable = [
        'reference', 'seller_id', 'seller_is', 'amount', 'currency', 'status',
        'method', 'method_details', 'reserve_entry_id', 'payout_entry_id',
        'reviewed_by', 'reviewed_at', 'review_note', 'paid_at', 'payment_reference',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'amount' => 'float',
        'method_details' => 'array',
        'reviewed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
