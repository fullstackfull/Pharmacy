<?php

namespace App\Services\Monitoring;

use JsonSerializable;

/**
 * One measured value, or an honest statement of why there is none.
 *
 * This is the backbone of the monitoring system. A dashboard that prints `0` when it could not
 * read a number is worse than one that prints nothing: zero is a legitimate reading — zero errors,
 * zero pending jobs — so a failed probe rendered as zero reads as "all good" at the exact moment
 * something is wrong. Every collector therefore returns one of these rather than a bare scalar,
 * and the UI renders the state, never a placeholder.
 *
 * Every value also carries WHERE it came from (`/proc/stat`, `MySQL SHOW GLOBAL STATUS`, ...) so a
 * number on the screen can always be traced back to the thing that produced it.
 */
final class Metric implements JsonSerializable
{
    /** A real reading. */
    public const OK = 'ok';

    /** The probe works here, but nothing has been recorded yet (a fresh install, an empty window). */
    public const NO_DATA = 'no_data';

    /** Supported here, but switched off or missing its settings — actionable by the merchant. */
    public const NOT_CONFIGURED = 'not_configured';

    /** This environment cannot produce it at all (no such hardware, no such kernel feature). */
    public const NOT_SUPPORTED = 'not_supported';

    /** The source exists but this process may not read it. */
    public const PERMISSION_DENIED = 'permission_denied';

    /** Something that should be feeding us has stopped (cron, worker, agent). */
    public const COLLECTOR_OFFLINE = 'collector_offline';

    /** The probe threw. */
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $state,
        public readonly mixed $value,
        public readonly string $source,
        public readonly ?string $unit,
        public readonly ?string $note,
        public readonly ?string $remedy,
    ) {
    }

    /**
     * @param  string  $source  human-readable origin, e.g. "Linux /proc/stat" or "MySQL SHOW GLOBAL STATUS"
     * @param  string|null  $unit  "ms", "%", "MB", "req/s", ...
     */
    public static function of(mixed $value, string $source, ?string $unit = null, ?string $note = null): self
    {
        if ($value === null) {
            return self::noData($source, $note);
        }

        return new self(self::OK, $value, $source, $unit, $note, null);
    }

    public static function noData(string $source, ?string $note = null): self
    {
        return new self(self::NO_DATA, null, $source, null, $note, null);
    }

    /** @param string $remedy what the operator must do to make this metric real */
    public static function notConfigured(string $source, string $remedy, ?string $note = null): self
    {
        return new self(self::NOT_CONFIGURED, null, $source, null, $note, $remedy);
    }

    public static function notSupported(string $source, string $note, ?string $remedy = null): self
    {
        return new self(self::NOT_SUPPORTED, null, $source, null, $note, $remedy);
    }

    public static function permissionDenied(string $source, string $note, ?string $remedy = null): self
    {
        return new self(self::PERMISSION_DENIED, null, $source, null, $note, $remedy);
    }

    public static function collectorOffline(string $source, string $note, ?string $remedy = null): self
    {
        return new self(self::COLLECTOR_OFFLINE, null, $source, null, $note, $remedy);
    }

    public static function failed(string $source, \Throwable $exception): self
    {
        return new self(self::FAILED, null, $source, null, class_basename($exception) . ': ' . $exception->getMessage(), null);
    }

    /**
     * Run a probe, turning any throw into a FAILED metric.
     *
     * Monitoring must never be able to break the page it is drawn on, let alone the store — so no
     * collector is trusted to be exception-free.
     */
    public static function probe(string $source, callable $probe, ?string $unit = null): self
    {
        try {
            $value = $probe();

            return $value instanceof self ? $value : self::of($value, $source, $unit);
        } catch (\Throwable $exception) {
            return self::failed($source, $exception);
        }
    }

    public function isOk(): bool
    {
        return $this->state === self::OK;
    }

    /** True when the operator could do something to turn this into a real reading. */
    public function isActionable(): bool
    {
        return in_array($this->state, [self::NOT_CONFIGURED, self::PERMISSION_DENIED, self::COLLECTOR_OFFLINE], true);
    }

    /** The reading, or the given fallback when there is no reading. Never invents a value. */
    public function valueOr(mixed $fallback = null): mixed
    {
        return $this->isOk() ? $this->value : $fallback;
    }

    public function asFloat(): ?float
    {
        return $this->isOk() && is_numeric($this->value) ? (float) $this->value : null;
    }

    /** A derived metric that keeps this one's provenance and unavailability. */
    public function map(callable $transform, ?string $unit = null): self
    {
        if (!$this->isOk()) {
            return $this;
        }

        return self::probe($this->source, fn () => $transform($this->value), $unit ?? $this->unit);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'state' => $this->state,
            'value' => $this->value,
            'source' => $this->source,
            'unit' => $this->unit,
            'note' => $this->note,
            'remedy' => $this->remedy,
        ], fn ($item) => $item !== null);
    }
}
