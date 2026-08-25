<?php

namespace App\Jobs;

use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Redactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ships one finished trace to an OpenTelemetry collector.
 *
 * config/monitoring.php declared that "when an endpoint is set, finished traces are POSTed as
 * OTLP/HTTP JSON by a queued job" — and there was no such job, so setting
 * OTEL_EXPORTER_OTLP_ENDPOINT configured nothing and the traces stayed in this database while the
 * operator waited for them in Jaeger or Tempo.
 *
 * Queued rather than inline, because export is the one part of tracing that talks to another host:
 * an unreachable collector must slow a queue worker, never a customer's checkout. The trace is read
 * back from the store by id rather than carried in the payload, so a job that waits ten minutes in
 * a backlog does not pin a whole span tree in Redis.
 */
class ExportTraceToOtlp implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A collector that is down stays down for a while; retrying forever just burns the queue. */
    public int $tries = 2;

    public int $timeout = 20;

    public array $backoff = [30, 120];

    public function __construct(private readonly string $traceId)
    {
    }

    public static function endpoint(): ?string
    {
        $endpoint = trim((string) config('monitoring.tracing.otlp_endpoint', ''));

        return $endpoint === '' ? null : rtrim($endpoint, '/');
    }

    public function handle(Redactor $redactor): void
    {
        $endpoint = self::endpoint();

        if ($endpoint === null) {
            return;
        }

        $connection = DB::connection(config('monitoring.connection'));
        $trace = $connection->table('monitoring_traces')->where('trace_id', $this->traceId)->first();

        if ($trace === null) {
            return;
        }

        $spans = $connection->table('monitoring_spans')
            ->where('trace_id', $this->traceId)
            ->orderBy('start_offset_ms')
            ->get();

        if ($spans->isEmpty()) {
            return;
        }

        Http::withHeaders($this->headers())
            ->timeout(10)
            ->post($endpoint . '/v1/traces', $this->payload($trace, $spans, $redactor))
            ->throw();
    }

    /**
     * OTLP/HTTP JSON, hand-built.
     *
     * No SDK is installed and none is wanted for this: the JSON encoding of the protocol is a
     * stable, documented shape, and a transitive dependency tree for one POST is a worse trade on
     * a shop that also has to run on shared hosting.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $spans
     * @return array<string, mixed>
     */
    private function payload(object $trace, $spans, Redactor $redactor): array
    {
        $startedAtNanos = Clock::parse($trace->started_at)->getTimestamp() * 1_000_000_000;

        return [
            'resourceSpans' => [[
                'resource' => [
                    'attributes' => $this->attributes([
                        'service.name' => (string) config('monitoring.tracing.service_name', 'app'),
                        'service.version' => (string) ($trace->release ?? ''),
                        'deployment.environment' => (string) config('app.env'),
                    ]),
                ],
                'scopeSpans' => [[
                    'scope' => ['name' => 'pharmacy.monitoring', 'version' => '1'],
                    'spans' => $spans->map(fn (object $span) => $this->span($span, $trace, $startedAtNanos, $redactor))->all(),
                ]],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function span(object $span, object $trace, int $startedAtNanos, Redactor $redactor): array
    {
        $start = $startedAtNanos + ((int) $span->start_offset_ms * 1_000_000);

        $attributes = json_decode((string) ($span->attributes ?? '{}'), true);
        $attributes = is_array($attributes) ? $redactor->array($attributes) : [];
        $attributes['span.kind_label'] = (string) $span->kind;
        $attributes['http.route'] = (string) ($trace->route ?? '');

        return [
            'traceId' => (string) $trace->trace_id,
            'spanId' => (string) $span->span_id,
            'parentSpanId' => (string) ($span->parent_span_id ?? ''),
            'name' => (string) $span->name,
            'kind' => $this->otlpKind((string) $span->kind),
            'startTimeUnixNano' => (string) $start,
            'endTimeUnixNano' => (string) ($start + ((int) $span->duration_ms * 1_000_000)),
            'attributes' => $this->attributes($attributes),
            // 0 unset, 1 ok, 2 error — the collector shows a failed span in red off this alone.
            'status' => ['code' => $span->failed ? 2 : 1],
        ];
    }

    /** This store's span kinds mapped onto the protocol's five. */
    private function otlpKind(string $kind): int
    {
        return match ($kind) {
            'http', 'queue' => 3,      // CLIENT / PRODUCER, both outbound from here
            'controller', 'middleware' => 2, // SERVER
            'db', 'cache' => 3,
            default => 1,              // INTERNAL
        };
    }

    /**
     * The protocol's key/value list, which has no notion of a nested structure.
     *
     * @param  array<string, mixed>  $values
     * @return array<int, array<string, mixed>>
     */
    private function attributes(array $values): array
    {
        $attributes = [];

        foreach ($values as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $attributes[] = ['key' => (string) $key, 'value' => match (true) {
                is_bool($value) => ['boolValue' => $value],
                is_int($value) => ['intValue' => (string) $value],
                is_float($value) => ['doubleValue' => $value],
                is_array($value) => ['stringValue' => json_encode($value)],
                default => ['stringValue' => (string) $value],
            }];
        }

        return $attributes;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $headers = ['Content-Type' => 'application/json'];

        // The OTEL convention: `key=value,key2=value2` in one environment variable.
        foreach (explode(',', (string) config('monitoring.tracing.otlp_headers', '')) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $pair, 2);
            $key = trim($key);

            if ($key !== '') {
                $headers[$key] = trim($value);
            }
        }

        return $headers;
    }
}
