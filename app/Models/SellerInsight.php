<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing a seller should look at, produced by one named producer.
 *
 * @property int $id
 * @property int $seller_id
 * @property string $type
 * @property string $severity
 * @property string $title
 */
class SellerInsight extends Model
{
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';

    /** Worst first. Stored as words for readability; ordered by this map, never alphabetically. */
    public const SEVERITY_ORDER = [
        self::SEVERITY_CRITICAL => 0,
        self::SEVERITY_HIGH => 1,
        self::SEVERITY_MEDIUM => 2,
        self::SEVERITY_LOW => 3,
    ];

    protected $fillable = [
        'seller_id', 'type', 'severity', 'title', 'body',
        'entity_type', 'entity_id', 'metric', 'impact',
        'action_key', 'action_params', 'fingerprint',
        'expires_at', 'dismissed_at', 'resolved_at',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'metric' => 'float',
        'impact' => 'float',
        'action_params' => 'array',
        'expires_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** Live: not dismissed by the seller, not resolved by a producer, not past its own expiry. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at')
            ->whereNull('resolved_at')
            ->where(function (Builder $inner) {
                $inner->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function scopeForSeller(Builder $query, int|string $sellerId): Builder
    {
        return $query->where('seller_id', $sellerId);
    }
}
