<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer segment for B2B / wholesale pricing (Phase 3, Stage E).
 */
class CustomerGroup extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = ['name', 'type', 'default_discount_percent', 'status', 'created_by'];

    protected $casts = [
        'default_discount_percent' => 'decimal:2',
        'created_by' => 'integer',
    ];

    public function prices(): HasMany
    {
        return $this->hasMany(CustomerGroupPrice::class);
    }
}
