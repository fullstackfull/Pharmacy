<?php

namespace App\Services\Platform;

use App\Models\FeatureFlag;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Turning something on for some of the shop rather than all of it.
 *
 * The platform had no flag table, no config and no per-seller or per-percentage switch: the only
 * lever was publishing or unpublishing a whole addon module, so every change was all-or-nothing for
 * everyone at once. A change that goes wrong then goes wrong for every seller and every shopper
 * simultaneously, and the only way back is a deployment.
 *
 * Three rules make the answer trustworthy:
 *
 * **A flag that does not exist is off.** Not an error and not on — a rollout is opt-in, and an
 * unknown key is a typo or a flag that was removed. Either way the safe reading is the old
 * behaviour.
 *
 * **The same subject always gets the same answer.** The bucket is a hash of the flag key and the
 * subject, so a seller at 30% stays in or out across every request, every page and every device
 * until somebody moves the percentage. A random draw per request would show one seller two versions
 * of the product in the same session, which is worse than not rolling out at all.
 *
 * **The pilot list beats the percentage, and the master switch beats both.** A seller named on the
 * list is always in; a flag whose master switch is off is off for everyone including them, because
 * an off switch that some people are exempt from is not an off switch.
 */
class FeatureFlags
{
    private const CACHE_KEY = 'platform.feature_flags';

    /** Short, because the point of a flag is being able to turn it off during an incident. */
    private const CACHE_SECONDS = 30;

    public function __construct(private readonly ?AuditLogger $audit = null)
    {
    }

    /**
     * Is this flag on for this subject?
     *
     * The subject is normally a seller id. Pass null for a decision with no subject — a shop-wide
     * switch — where only the master switch and a 100% rollout can turn it on. A percentage with no
     * subject to bucket cannot be honoured, and guessing would make the same request answer
     * differently on every reload.
     */
    public function enabled(string $key, int|string|null $subject = null): bool
    {
        $flag = $this->all()[$key] ?? null;

        if ($flag === null || !$flag['enabled']) {
            return false;
        }

        if ($subject !== null && in_array((int) $subject, $flag['seller_ids'], true)) {
            return true;
        }

        $percent = $flag['rollout_percent'];

        if ($percent >= 100) {
            return true;
        }

        if ($percent <= 0 || $subject === null) {
            return false;
        }

        return $this->bucket($key, (string) $subject) < $percent;
    }

    /**
     * Which bucket, 0-99, this subject falls into for this flag.
     *
     * The flag key is part of the hash so two flags at 30% do not pick the same 30% of sellers —
     * otherwise every experiment would run on the same population and none of them would generalise.
     */
    public function bucket(string $key, string $subject): int
    {
        return hexdec(substr(sha1($key . '|' . $subject), 0, 8)) % 100;
    }

    /** @return array<string, array{key: string, description: ?string, enabled: bool, rollout_percent: int, seller_ids: array<int, int>}> */
    public function all(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function () {
                if (!Schema::hasTable('feature_flags')) {
                    return [];
                }

                $flags = [];
                foreach (FeatureFlag::all() as $flag) {
                    $flags[$flag->key] = [
                        'key' => $flag->key,
                        'description' => $flag->description,
                        'enabled' => (bool) $flag->enabled,
                        'rollout_percent' => max(0, min(100, (int) $flag->rollout_percent)),
                        'seller_ids' => array_map('intval', (array) ($flag->seller_ids ?? [])),
                    ];
                }

                return $flags;
            });
        } catch (Throwable) {
            // An unreadable flag table means the old behaviour, everywhere. A rollout that cannot be
            // read must never fail open.
            return [];
        }
    }

    /**
     * Create or update one flag.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, error?: string}
     */
    public function save(string $key, array $input): array
    {
        $key = trim($key);

        if ($key === '' || !preg_match('/^[a-z0-9_.\-]{2,96}$/', $key)) {
            return ['ok' => false, 'error' => 'a_flag_key_is_lowercase_letters_numbers_dots_dashes_and_underscores'];
        }

        if (!Schema::hasTable('feature_flags')) {
            return ['ok' => false, 'error' => 'the_feature_flag_table_has_not_been_created_on_this_installation'];
        }

        $existing = FeatureFlag::where('key', $key)->first();
        $before = $existing === null ? null : [
            'enabled' => (bool) $existing->enabled,
            'rollout_percent' => (int) $existing->rollout_percent,
            'seller_ids' => (array) ($existing->seller_ids ?? []),
        ];

        $after = [
            'enabled' => (bool) ($input['enabled'] ?? false),
            // Clamped rather than validated away: 140% is a typo for 100, not a reason to lose the
            // rest of the edit.
            'rollout_percent' => max(0, min(100, (int) ($input['rollout_percent'] ?? 0))),
            'seller_ids' => $this->sellerIds($input['seller_ids'] ?? null),
        ];

        FeatureFlag::updateOrCreate(
            ['key' => $key],
            $after + [
                'description' => is_string($input['description'] ?? null) ? mb_substr($input['description'], 0, 500) : ($existing->description ?? null),
                'updated_by' => auth('admin')->id(),
            ],
        );

        $this->forget();

        $this->audit?->record(
            action: $existing === null ? 'platform.feature_flag_created' : 'platform.feature_flag_updated',
            subject: ['type' => 'feature_flag', 'id' => $key],
            before: $before,
            after: $after,
        );

        return ['ok' => true];
    }

    public function delete(string $key): bool
    {
        if (!Schema::hasTable('feature_flags')) {
            return false;
        }

        $deleted = FeatureFlag::where('key', $key)->delete() > 0;

        if ($deleted) {
            $this->forget();
            // Recorded, because deleting a flag silently returns everyone to the old behaviour and
            // that is indistinguishable from the new code having been reverted.
            $this->audit?->record(action: 'platform.feature_flag_deleted', subject: ['type' => 'feature_flag', 'id' => $key]);
        }

        return $deleted;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The pilot list, from whatever the form sent.
     *
     * @return array<int, int>
     */
    private function sellerIds(mixed $value): array
    {
        if (is_array($value)) {
            $ids = $value;
        } elseif (is_string($value)) {
            $ids = preg_split('/[\s,]+/', $value) ?: [];
        } else {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0)));

        // Bounded: a pilot list is a handful of shops somebody is watching, and one with two
        // thousand entries in it is a rollout percentage written the hard way.
        return array_slice($ids, 0, 200);
    }
}
