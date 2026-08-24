<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One rule a seller has written for their own shop.
 *
 * `suspended` is not a third kind of `paused`. A paused rule is one the seller switched off; a
 * suspended one is a rule the platform stopped because it was about to do something the seller
 * almost certainly did not mean. Only a person clears a suspension, and clearing it is a deliberate
 * act with the reason still on screen.
 */
class SellerAutomationRule extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_SUSPENDED = 'suspended';

    public const SELLER_SETTABLE_STATUSES = [self::STATUS_ACTIVE, self::STATUS_PAUSED];

    /** Three in a row is a pattern, not a bad afternoon. */
    public const FAILURE_LIMIT = 3;

    protected $fillable = [
        'seller_id',
        'created_by_staff_id',
        'name',
        'trigger',
        'action',
        'trigger_settings',
        'action_settings',
        'status',
        'max_actions_per_run',
        'cooldown_minutes',
    ];

    protected $casts = [
        'trigger_settings' => 'array',
        'action_settings' => 'array',
        'max_actions_per_run' => 'integer',
        'cooldown_minutes' => 'integer',
        'run_count' => 'integer',
        'applied_count' => 'integer',
        'consecutive_failures' => 'integer',
        'last_run_at' => 'datetime',
        'last_fired_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(SellerAutomationRun::class, 'rule_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(SellerAutomationAction::class, 'rule_id');
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Is this rule allowed to run right now?
     *
     * The cooldown is measured from the last run rather than the last time the rule did something,
     * so a rule that keeps matching nothing still costs one evaluation per cooldown and no more.
     */
    public function isDue(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        if ($this->last_run_at === null) {
            return true;
        }

        return $this->last_run_at->addMinutes(max(1, $this->cooldown_minutes))->isPast();
    }
}
