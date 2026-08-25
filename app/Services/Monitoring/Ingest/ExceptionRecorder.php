<?php

namespace App\Services\Monitoring\Ingest;

use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Redactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The writer `monitoring_error_groups` and `monitoring_errors` never had.
 *
 * Both tables are created by the shipped migration, read by eight panels and two services, pruned by
 * the rollup — and nothing anywhere put a row in either of them. Every error screen in the console
 * was permanently empty on every installation, the health score counted zero new error groups
 * forever, and the request debugger could look up an id that no code path had ever recorded. The
 * single largest hole in the platform, and it was one missing seam.
 *
 * Two rules shape what is written.
 *
 * **Grouped, not listed.** "14 errors in the last hour" tells nobody anything. The fingerprint is
 * the exception class, its message with the variable parts stripped, and the topmost application
 * frame — so the same bug from two customers lands in one group and two different bugs never merge.
 *
 * **Redacted before it is stored, not before it is shown.** A stack trace and a request payload are
 * the two most reliable places in an application to find a token, an address or a password, and this
 * data is read on a page an operator screenshots. Redaction at write time means a leak cannot be
 * un-leaked by fixing a view later.
 *
 * Recording an exception must never be the reason a request fails, so everything here is inside a
 * try/catch that gives up silently. An error the console did not see is a bad day; an error handler
 * that throws is an outage.
 */
class ExceptionRecorder
{
    /** How much of a trace is worth keeping. Past this it is framework frames nobody reads. */
    private const MAX_TRACE_BYTES = 12000;

    /** Exceptions that are ordinary traffic rather than faults. */
    private const IGNORED = [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Illuminate\Validation\ValidationException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        \Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException::class,
    ];

    public function __construct(private readonly Redactor $redactor)
    {
    }

    public function record(Throwable $exception, ?Request $request = null): void
    {
        try {
            if (!$this->shouldRecord($exception)) {
                return;
            }

            $connection = DB::connection(config('monitoring.connection'));

            if (!Schema::connection(config('monitoring.connection'))->hasTable('monitoring_error_groups')) {
                return;
            }

            $request ??= request();
            $frame = $this->applicationFrame($exception);
            $fingerprint = $this->fingerprint($exception, $frame);
            $now = Clock::stamp();

            $groupId = $this->upsertGroup($connection, $fingerprint, $exception, $frame, $request, $now);

            if ($groupId === null) {
                return;
            }

            $userId = $this->userId();

            $connection->table('monitoring_errors')->insert([
                'group_id' => $groupId,
                'trace_id' => $this->contextValue('traceId'),
                'request_id' => $this->contextValue('requestId'),
                'route' => $this->routeName($request),
                'method' => $request?->method(),
                'status' => $this->status($exception),
                'channel' => $this->channel($request),
                'user_type' => $this->userType(),
                'user_id' => $userId,
                'platform' => $this->platform($request),
                'app_version' => $request?->header('X-App-Version'),
                'release' => app_release_version(),
                // Masked by the redactor, not stored raw: an IP is the one field on this table that
                // identifies a person on its own.
                'ip' => $request === null ? null : $this->redactor->text((string) $request->ip()),
                'context' => json_encode($this->context($request)),
                'stack_trace' => $this->trace($exception),
                'created_at' => $now,
            ]);

            $this->countAffectedUser($connection, $groupId, $userId);
        } catch (Throwable) {
            // An error the console did not see is a bad day. An error handler that throws is an
            // outage, so this one never does.
        }
    }

    /**
     * Open or update the group this exception belongs to.
     *
     * `occurrences` is incremented in SQL rather than read-then-written: two requests failing the
     * same way at the same moment is the normal case for an error worth looking at, and a
     * read-modify-write would lose one of them.
     */
    private function upsertGroup(
        \Illuminate\Database\Connection $connection,
        string $fingerprint,
        Throwable $exception,
        ?array $frame,
        ?Request $request,
        string $now,
    ): ?int {
        $release = app_release_version();

        $updated = $connection->table('monitoring_error_groups')
            ->where('fingerprint', $fingerprint)
            ->update([
                'occurrences' => DB::raw('occurrences + 1'),
                'last_seen_at' => $now,
                'last_release' => $release,
                // A group somebody marked resolved that fires again is open again. Silently leaving
                // it resolved is how a regression stays invisible to the person who fixed it.
                //
                // `resolved_at` is cleared BEFORE `status` is reassigned, and the order matters:
                // MySQL evaluates UPDATE assignments left to right, so a CASE placed after the
                // status assignment would read the new value and never clear anything.
                'resolved_at' => DB::raw("CASE WHEN status = 'resolved' THEN NULL ELSE resolved_at END"),
                'resolved_by' => DB::raw("CASE WHEN status = 'resolved' THEN NULL ELSE resolved_by END"),
                'status' => DB::raw("CASE WHEN status = 'resolved' THEN 'open' ELSE status END"),
                'updated_at' => $now,
            ]);

        if ($updated === 0) {
            $connection->table('monitoring_error_groups')->insertOrIgnore([
                'fingerprint' => $fingerprint,
                'exception_class' => mb_substr(get_class($exception), 0, 191),
                'message' => $this->redactor->text(mb_substr($exception->getMessage(), 0, 1000)),
                'file' => $frame === null ? null : mb_substr((string) $frame['file'], 0, 191),
                'line' => $frame === null ? null : (int) $frame['line'],
                'route' => $this->routeName($request),
                'channel' => $this->channel($request),
                'severity' => 'error',
                'status' => 'open',
                'release' => $release,
                'last_release' => $release,
                'occurrences' => 1,
                'affected_users' => 0,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $connection->table('monitoring_error_groups')->where('fingerprint', $fingerprint)->value('id');
    }

    /**
     * How many distinct people this bug has reached.
     *
     * "One developer hit it twice" and "sixty customers hit it once" are the same occurrence count
     * and completely different decisions, so the count only moves the first time a given signed-in
     * user appears in the group. Anonymous traffic is not counted rather than counted as one
     * person: an inflated figure is worse than an absent one when it drives triage.
     */
    private function countAffectedUser(\Illuminate\Database\Connection $connection, int $groupId, ?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        $seenBefore = $connection->table('monitoring_errors')
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->count();

        if ($seenBefore > 1) {
            return;
        }

        $connection->table('monitoring_error_groups')
            ->where('id', $groupId)
            ->update(['affected_users' => DB::raw('affected_users + 1')]);
    }

    /**
     * The identity of a bug.
     *
     * The message has its variable parts removed first — ids, quoted values, hex — so "No query
     * results for model [Product] 41" and the same failure for product 42 are one bug rather than
     * two thousand.
     */
    private function fingerprint(Throwable $exception, ?array $frame): string
    {
        $message = preg_replace(
            ['/\b\d+\b/', '/0x[0-9a-f]+/i', '/"[^"]*"/', "/'[^']*'/"],
            ['{n}', '{hex}', '{str}', '{str}'],
            $exception->getMessage(),
        );

        return sha1(implode('|', [
            get_class($exception),
            mb_substr((string) $message, 0, 500),
            $frame === null ? '' : $frame['file'] . ':' . $frame['line'],
        ]));
    }

    /**
     * The topmost frame inside this application.
     *
     * A framework frame is where the exception surfaced; an application frame is where the bug is.
     * Grouping on the former merges every unrelated failure that happens to exit through the same
     * router line.
     */
    private function applicationFrame(Throwable $exception): ?array
    {
        $base = base_path();

        foreach (array_merge([['file' => $exception->getFile(), 'line' => $exception->getLine()]], $exception->getTrace()) as $frame) {
            $file = $frame['file'] ?? null;

            if (!is_string($file) || !str_starts_with($file, $base) || str_contains($file, '/vendor/')) {
                continue;
            }

            return ['file' => str_replace($base . '/', '', $file), 'line' => (int) ($frame['line'] ?? 0)];
        }

        return null;
    }

    private function shouldRecord(Throwable $exception): bool
    {
        if (!config('monitoring.enabled', true)) {
            return false;
        }

        foreach (self::IGNORED as $class) {
            if ($exception instanceof $class) {
                return false;
            }
        }

        // A 4xx the application raised deliberately is the app working, not failing.
        $status = $this->status($exception);

        return $status === null || $status >= 500;
    }

    private function status(Throwable $exception): ?int
    {
        return method_exists($exception, 'getStatusCode') ? (int) $exception->getStatusCode() : null;
    }

    private function trace(Throwable $exception): string
    {
        return $this->redactor->text(mb_strcut($exception->getTraceAsString(), 0, self::MAX_TRACE_BYTES));
    }

    /** @return array<string, mixed> */
    private function context(?Request $request): array
    {
        if ($request === null) {
            return [];
        }

        return [
            'url' => $this->redactor->text($request->fullUrl()),
            'referer' => $this->redactor->text((string) $request->header('referer')),
            'agent' => mb_substr((string) $request->userAgent(), 0, 300),
            // Keys only. The values on a checkout or a login are exactly what must never be stored,
            // and the key list is what makes a failure reproducible.
            'input_keys' => array_slice(array_keys($request->all()), 0, 40),
        ];
    }

    private function routeName(?Request $request): ?string
    {
        $route = $request?->route();

        return $route === null ? null : mb_substr((string) ($route->getName() ?: $route->uri()), 0, 191);
    }

    private function channel(?Request $request): ?string
    {
        if ($request === null) {
            return 'console';
        }

        return $request->is('api/*') ? 'api' : ($request->is('admin/*') ? 'admin' : ($request->is('vendor/*') ? 'vendor' : 'web'));
    }

    private function platform(?Request $request): ?string
    {
        $platform = $request?->header('X-Platform');

        return is_string($platform) && $platform !== '' ? mb_substr($platform, 0, 16) : null;
    }

    private function userType(): ?string
    {
        foreach (['admin', 'seller', 'customer'] as $guard) {
            if (auth($guard)->check()) {
                return $guard;
            }
        }

        return null;
    }

    private function userId(): ?int
    {
        foreach (['admin', 'seller', 'customer'] as $guard) {
            if (auth($guard)->check()) {
                return (int) auth($guard)->id();
            }
        }

        return null;
    }

    /** The correlation ids monitoring already mints, when a request context exists. */
    private function contextValue(string $property): ?string
    {
        try {
            return app()->bound(RequestContext::class) ? app(RequestContext::class)->{$property} : null;
        } catch (Throwable) {
            return null;
        }
    }
}
