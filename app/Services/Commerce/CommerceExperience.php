<?php

namespace App\Services\Commerce;

use App\Services\Platform\Policy;

/**
 * Whether the storefront personalisation engine is running at all.
 *
 * This is the documented rollback path for collections, campaigns, segments and experiments — off
 * means every collection falls back to its catalogue ordering and no personalisation logic runs, with
 * no migration reversal and no data loss. It was one environment line and a deploy, while four admin
 * screens displayed its state and none could change it: the rollback an operator most needs at 2am
 * was the one that required a release.
 *
 * Precedence is stored, then environment, then the shipped default, so an install that sets
 * COMMERCE_EXPERIENCE_ENABLED=false stays off.
 */
class CommerceExperience
{
    public function __construct(private readonly Policy $policy)
    {
    }

    public function enabled(): bool
    {
        try {
            if ($this->policy->isSet('commerce_experience_enabled')) {
                return (bool) $this->policy->get('commerce_experience_enabled');
            }
        } catch (\Throwable) {
            // No settings table yet — fall through to configuration.
        }

        return (bool) config('commerce.enabled', true);
    }
}
