<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A seller's staff member, assigned a role (Phase 3, Stage A).
 *
 * A sign-in account: the member authenticates with these (hashed) credentials via the staff login,
 * which signs them in as their parent seller, and SellerStaffAccessMiddleware scopes what they may do
 * to their role's permissions.
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
