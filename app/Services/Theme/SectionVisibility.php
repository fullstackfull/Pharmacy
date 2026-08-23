<?php

namespace App\Services\Theme;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Whether a section runs right now, for this viewer — the one rule, read by every renderer.
 *
 * There are four independent switches and they were previously spread across three files, which is
 * how a section ends up visible on the web and invisible in the app for reasons nobody can trace:
 *
 *   is_visible     the structure panel's switch (already applied by the query)
 *   settings.visible  the settings panel's switch, plus its per-breakpoint siblings
 *   schedule       starts_at / ends_at, evaluated against the SHOP's clock
 *   targeting      platforms and audience
 *
 * Time is the reason this is server-side. A campaign that ends at midnight has to end at the
 * shop's midnight; a phone with a wrong clock — or a deliberately wrong one — would otherwise
 * decide for itself when a promotion runs.
 *
 * Every method also explains itself: `reasonFor()` returns the switch that stopped a section, which
 * is what the builder shows a merchant who is looking at a section that will not appear.
 */
class SectionVisibility
{
    public const REASON_HIDDEN     = 'hidden';
    public const REASON_NOT_STARTED = 'not_started';
    public const REASON_ENDED      = 'ended';
    public const REASON_PLATFORM   = 'platform';
    public const REASON_DEVICE     = 'device';
    public const REASON_AUDIENCE   = 'audience';

    /**
     * @param  array<string, mixed>  $section  a section row as the renderer holds it
     */
    public function passes(array $section, ViewerContext $viewer, ?CarbonInterface $now = null): bool
    {
        return $this->reasonFor($section, $viewer, $now) === null;
    }

    /**
     * The switch that stops this section, or null when it runs.
     *
     * @param  array<string, mixed>  $section
     */
    public function reasonFor(array $section, ViewerContext $viewer, ?CarbonInterface $now = null): ?string
    {
        if (($section['is_visible'] ?? true) === false) {
            return self::REASON_HIDDEN;
        }

        if ($this->hiddenBySettings($section['settings'] ?? [], $viewer)) {
            return self::REASON_HIDDEN;
        }

        if ($scheduleReason = $this->scheduleReason($section, $now)) {
            return $scheduleReason;
        }

        return $this->targetingReason($section, $viewer);
    }

    /**
     * The settings panel's own visibility toggle, resolved for this viewer's breakpoint.
     *
     * The builder stores per-breakpoint overrides as `visible_tablet` / `visible_mobile` siblings.
     * A missing or empty override means "inherit", not "hide" — an override that was never set
     * must not be able to blank a section.
     *
     * @param  array<string, mixed>  $settings
     */
    private function hiddenBySettings(array $settings, ViewerContext $viewer): bool
    {
        if ($viewer->device !== ViewerContext::DEVICE_DESKTOP) {
            $scoped = $settings['visible_' . $viewer->device] ?? null;
            if ($scoped !== null && $scoped !== '') {
                return !$this->truthy($scoped);
            }
        }

        return array_key_exists('visible', $settings) && !$this->truthy($settings['visible']);
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function scheduleReason(array $section, ?CarbonInterface $now): ?string
    {
        $now ??= Carbon::now();

        $startsAt = $this->asTime($section['starts_at'] ?? null);
        if ($startsAt !== null && $now->lessThan($startsAt)) {
            return self::REASON_NOT_STARTED;
        }

        $endsAt = $this->asTime($section['ends_at'] ?? null);
        if ($endsAt !== null && $now->greaterThan($endsAt)) {
            return self::REASON_ENDED;
        }

        return null;
    }

    /**
     * Platform, device and audience rules. An empty or absent rule means "no restriction", which
     * is what every section written before this existed means.
     *
     * @param  array<string, mixed>  $section
     */
    private function targetingReason(array $section, ViewerContext $viewer): ?string
    {
        $platforms = $this->tokens($section['platforms'] ?? null);
        if ($platforms !== [] && !in_array($viewer->platform, $platforms, true)) {
            return self::REASON_PLATFORM;
        }

        // Devices ride in the same list as platforms: one flat set of tokens the merchant ticks,
        // rather than two lists that can contradict each other ("app only, desktop only").
        $devices = array_values(array_intersect($platforms, ViewerContext::DEVICES));
        if ($devices !== [] && !in_array($viewer->device, $devices, true)) {
            return self::REASON_DEVICE;
        }

        $audience = $this->tokens($section['audience'] ?? null);
        if ($audience !== [] && !in_array($viewer->audience(), $audience, true)) {
            return self::REASON_AUDIENCE;
        }

        return null;
    }

    /**
     * A stored rule list, however it was stored.
     *
     * Accepts an array (the cast), a JSON string (a hand-edited row, or an import) and a comma
     * list (a form post), because all three reach this code and a rule that silently becomes
     * "restricted to nothing" would blank a section with no explanation.
     *
     * @return array<int, string>
     */
    private function tokens(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($token) => is_string($token) ? trim($token) : null,
            $value,
        )));
    }

    private function asTime(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            // An unparseable schedule is not a reason to hide a section a merchant can see in the
            // builder — it is a reason to ignore the schedule.
            return null;
        }
    }

    private function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
}
