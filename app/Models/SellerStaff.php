<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A seller's staff member, assigned a role (Phase 3, Stage A).
 *
 * Credentials are stored for the deferred login step; until the staff guard exists these are managed
 * records, not sign-in accounts.
 */
class SellerStaff extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'seller_staff';

    protected $fillable = ['seller_id', 'seller_role_id', 'name', 'email', 'password', 'status', 'last_login_at'];

    protected $hidden = ['password'];

    protected $casts = [
        'seller_id' => 'integer',
        'seller_role_id' => 'integer',
        'last_login_at' => 'datetime',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(SellerRole::class, 'seller_role_id');
    }
}
