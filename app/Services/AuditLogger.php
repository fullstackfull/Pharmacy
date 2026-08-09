<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Schema;

/**
 * The one way anything in the system records who did what (Phase 3 — spec item 84).
 *
 * Every module records through this rather than growing its own log. It resolves the actor from
 * whichever guard is authenticated, captures their name so a later deletion does not erase the
 * trail, and stamps the request's IP — none of which a caller should have to remember.
 *
 * It never throws into the caller. An audit write failing must not fail the action being audited:
 * the worst outcome of a logging problem is a missing line, which is far better than a settlement or
 * a payout rolled back because the note about it could not be saved.
 */
class AuditLogger
{
    /**
     * Record an action.
     *
     * @param string $action dotted name whose prefix is the module, e.g. 'settlement.approved'
     * @param array{type: string, id: int|string}|object|null $subject the record acted on
     */
    public function record(
        string $action,
        array|object|null $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?array $context = null,
    ): ?AuditLog {
        try {
            if (!Schema::hasTable('audit_logs')) {
                return null;
            }

            [$subjectType, $subjectId] = $this->resolveSubject($subject);
            [$actorType, $actorId, $actorName] = $this->resolveActor();

            return AuditLog::create([
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_name' => $actorName,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'before' => $before,
                'after' => $after,
                'context' => $context,
                'ip_address' => $this->clientIp(),
                'user_agent' => $this->userAgent(),
            ]);
        } catch (\Throwable) {
            // A missing audit line is never worth failing the audited action.
            return null;
        }
    }

    /**
     * @return array{0: ?string, 1: int|string|null}
     */
    private function resolveSubject(array|object|null $subject): array
    {
        if ($subject === null) {
            return [null, null];
        }

        if (is_array($subject)) {
            return [$subject['type'] ?? null, $subject['id'] ?? null];
        }

        // An Eloquent model, or anything with a key: record its class and id.
        return [get_class($subject), $subject->getKey() ?? ($subject->id ?? null)];
    }

    /**
     * @return array{0: ?string, 1: int|null, 2: ?string}
     */
    private function resolveActor(): array
    {
        // Checked in order of privilege so an admin acting is recorded as an admin even if another
        // guard also happens to resolve.
        foreach (['admin', 'seller', 'customer'] as $guard) {
            try {
                $user = auth($guard)->user();
            } catch (\Throwable) {
                $user = null;
            }

            if ($user) {
                return [$guard, (int) $user->getKey(), $this->nameOf($user)];
            }
        }

        return ['system', null, 'System'];
    }

    private function nameOf(object $user): ?string
    {
        foreach (['name', 'f_name', 'email'] as $attribute) {
            if (!empty($user->{$attribute})) {
                return (string) $user->{$attribute};
            }
        }

        return null;
    }

    private function clientIp(): ?string
    {
        try {
            return request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }

    private function userAgent(): ?string
    {
        try {
            return substr((string) request()->userAgent(), 0, 255) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
