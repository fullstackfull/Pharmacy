<?php

namespace App\Services\SellerAutomation;

use Illuminate\Support\Collection;

/**
 * What a rule watches for.
 *
 * A trigger only selects. It never changes anything, which is what makes a dry run honest: the
 * preview a seller sees is produced by exactly the same code as the run that would act, so the two
 * cannot drift apart.
 */
interface AutomationTrigger
{
    /** Stable key stored on the rule. */
    public function key(): string;

    /** What kind of record this produces — one of SellerAutomationAction::SUBJECT_*. */
    public function subjectType(): string;

    /**
     * Validation for this trigger's settings, keyed without the `trigger_settings.` prefix.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * The records this trigger currently selects, newest first, bounded by $limit.
     *
     * @param  array<string, mixed>  $settings
     * @return Collection<int, object>
     */
    public function match(int $sellerId, array $settings, int $limit): Collection;
}
