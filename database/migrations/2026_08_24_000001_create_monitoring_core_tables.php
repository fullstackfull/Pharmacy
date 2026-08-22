<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The monitoring store: time-bucketed, bounded, and prunable.
 *
 * The shape here is the whole architecture. A store doing a few hundred requests a second cannot
 * afford a row per request — that is tens of millions of rows a week in the same database the
 * checkout writes to. So nothing here is per-request except the samples deliberately kept for
 * investigation (traces, errors, slow queries), and those are sampled and short-lived.
 *
 * Everything else is a BUCKET: one row per (metric, labels, minute). Latency percentiles come out
 * of a fixed histogram stored on the bucket, so p95 costs the same whether the minute held ten
 * requests or ten million. Minutes roll up to hours and hours to days, and each resolution has its
 * own retention — high resolution while an incident is fresh, aggregates for the year-long chart.
 *
 * These tables live on the `monitoring` connection, which by default points at the application's
 * own database and can be moved to another one with environment variables alone.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('monitoring.connection', 'monitoring');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        /*
        | Per-route request buckets. The single most-read table in the system: it answers
        | requests/sec, error rate, every percentile, the slowest routes and the busiest ones.
        */
        if (!$schema->hasTable('monitoring_request_buckets')) {
            $schema->create('monitoring_request_buckets', function (Blueprint $table) {
                $table->id();
                // minute | hour | day — the same row shape at three resolutions.
                $table->string('resolution', 6)->default('minute');
                $table->timestamp('bucket_at');
                // web | api | console — kept low-cardinality on purpose.
                $table->string('channel', 12)->default('web');
                // The ROUTE PATTERN, never the raw URL: /product/{slug}, not /product/panadol-500.
                // This is what keeps the table bounded no matter how big the catalogue gets.
                $table->string('route', 191);
                $table->string('method', 8)->default('GET');

                $table->unsignedBigInteger('hits')->default(0);
                $table->unsignedBigInteger('errors')->default(0);          // 5xx
                $table->unsignedBigInteger('client_errors')->default(0);   // 4xx
                $table->unsignedBigInteger('timeouts')->default(0);
                // Latency histogram: JSON array of counts against config('monitoring.latency_buckets_ms').
                $table->json('duration_buckets')->nullable();
                $table->unsignedBigInteger('duration_sum_ms')->default(0);
                $table->unsignedInteger('duration_min_ms')->nullable();
                $table->unsignedInteger('duration_max_ms')->nullable();
                // Where the time went, so a slow route can be attributed without opening a trace.
                $table->unsignedBigInteger('db_ms_sum')->default(0);
                $table->unsignedBigInteger('db_query_count')->default(0);
                $table->unsignedBigInteger('cache_ms_sum')->default(0);
                $table->unsignedBigInteger('external_ms_sum')->default(0);
                $table->unsignedBigInteger('external_calls')->default(0);
                $table->unsignedBigInteger('queue_dispatches')->default(0);
                $table->unsignedBigInteger('memory_peak_sum_kb')->default(0);
                $table->unsignedBigInteger('response_bytes_sum')->default(0);
                $table->unsignedBigInteger('request_bytes_sum')->default(0);

                $table->unique(['resolution', 'bucket_at', 'channel', 'route', 'method'], 'monitoring_request_bucket_unique');
                $table->index(['resolution', 'bucket_at'], 'monitoring_request_bucket_window');
                $table->index(['route', 'bucket_at'], 'monitoring_request_bucket_route');
            });
        }

        /*
        | Everything that is a number over time but is not a request: CPU, memory, queue depth,
        | Redis hit ratio, payment success rate, energy. One generic table rather than thirty
        | specific ones, because the chart, the alert engine and the retention job all want to
        | treat them identically.
        */
        if (!$schema->hasTable('monitoring_series')) {
            $schema->create('monitoring_series', function (Blueprint $table) {
                $table->id();
                $table->string('resolution', 6)->default('minute');
                $table->timestamp('bucket_at');
                // Dotted name: server.cpu.usage, db.connections.active, queue.lag_seconds.
                $table->string('metric', 96);
                // One low-cardinality dimension (a queue name, a gateway, a disk). NOT an id.
                $table->string('label', 96)->default('');

                $table->unsignedBigInteger('samples')->default(0);
                $table->double('value_sum')->default(0);
                $table->double('value_min')->nullable();
                $table->double('value_max')->nullable();
                // The most recent sample in the bucket — what a gauge actually wants to show.
                $table->double('value_last')->nullable();

                $table->unique(['resolution', 'bucket_at', 'metric', 'label'], 'monitoring_series_unique');
                $table->index(['metric', 'resolution', 'bucket_at'], 'monitoring_series_lookup');
            });
        }

        /*
        | Exceptions, grouped. "14 errors in the last hour" tells nobody anything; one group with
        | 14 occurrences, a first-seen, a last-seen and a stack trace is a bug report.
        */
        if (!$schema->hasTable('monitoring_error_groups')) {
            $schema->create('monitoring_error_groups', function (Blueprint $table) {
                $table->id();
                // sha1 of (exception class + normalised message + top app frame): the same bug
                // from two different customers lands in one group, two different bugs never merge.
                $table->string('fingerprint', 40)->unique();
                $table->string('exception_class', 191);
                $table->text('message')->nullable();            // redacted
                $table->string('file', 191)->nullable();
                $table->unsignedInteger('line')->nullable();
                $table->string('route', 191)->nullable();
                $table->string('channel', 12)->nullable();
                $table->string('severity', 12)->default('error');
                // open | resolved | ignored — an error that has been dealt with stops shouting.
                $table->string('status', 12)->default('open');
                $table->string('release', 40)->nullable();       // app version when first seen
                $table->string('last_release', 40)->nullable();
                $table->unsignedBigInteger('occurrences')->default(0);
                $table->unsignedBigInteger('affected_users')->default(0);
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->timestamps();

                $table->index(['status', 'last_seen_at'], 'monitoring_error_group_triage');
            });
        }

        /*
        | Individual occurrences, capped by retention. Kept because a group without a concrete
        | example — this request, this user, this input — cannot be debugged.
        */
        if (!$schema->hasTable('monitoring_errors')) {
            $schema->create('monitoring_errors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('group_id')->index();
                $table->string('trace_id', 32)->nullable()->index();
                $table->string('request_id', 32)->nullable();
                $table->string('route', 191)->nullable();
                $table->string('method', 8)->nullable();
                $table->unsignedSmallInteger('status')->nullable();
                $table->string('channel', 12)->nullable();
                $table->string('user_type', 16)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('platform', 16)->nullable();      // web | android | ios
                $table->string('app_version', 32)->nullable();
                $table->string('release', 40)->nullable();
                $table->string('ip', 64)->nullable();            // masked by the Redactor
                $table->json('context')->nullable();             // redacted request context
                $table->longText('stack_trace')->nullable();     // redacted
                $table->timestamp('created_at')->index();
            });
        }

        /*
        | Sampled traces. The span tree is what turns "checkout is slow" into "410ms of it is one
        | query in ProductRepository". Sampled at a low rate, but always kept for slow and failed
        | requests, because those are the only ones anyone goes looking for.
        */
        if (!$schema->hasTable('monitoring_traces')) {
            $schema->create('monitoring_traces', function (Blueprint $table) {
                $table->id();
                $table->string('trace_id', 32)->unique();
                $table->string('correlation_id', 40)->nullable()->index();
                $table->string('route', 191)->nullable();
                $table->string('method', 8)->nullable();
                $table->string('channel', 12)->nullable();
                $table->unsignedSmallInteger('status')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->unsignedInteger('db_ms')->nullable();
                $table->unsignedInteger('db_queries')->nullable();
                $table->unsignedInteger('cache_ms')->nullable();
                $table->unsignedInteger('external_ms')->nullable();
                $table->unsignedInteger('memory_peak_kb')->nullable();
                $table->string('user_type', 16)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('platform', 16)->nullable();
                $table->string('app_version', 32)->nullable();
                $table->string('release', 40)->nullable();
                // Why this one was kept: sampled | slow | error — so a chart of "slow traces"
                // is not skewed by the random sample.
                $table->string('captured_because', 12)->default('sampled');
                $table->boolean('has_error')->default(false);
                $table->json('meta')->nullable();
                $table->timestamp('started_at')->index();

                $table->index(['route', 'started_at'], 'monitoring_trace_route');
                $table->index(['duration_ms'], 'monitoring_trace_duration');
            });
        }

        if (!$schema->hasTable('monitoring_spans')) {
            $schema->create('monitoring_spans', function (Blueprint $table) {
                $table->id();
                $table->string('trace_id', 32)->index();
                $table->string('span_id', 16);
                $table->string('parent_span_id', 16)->nullable();
                // db | cache | http | queue | view | middleware | controller | auth | app
                $table->string('kind', 16);
                $table->string('name', 191);
                $table->unsignedInteger('start_offset_ms')->default(0);
                $table->unsignedInteger('duration_ms')->default(0);
                $table->boolean('failed')->default(false);
                // Redacted: SQL fingerprints, sanitised URLs, never parameters.
                $table->json('attributes')->nullable();

                $table->index(['trace_id', 'start_offset_ms'], 'monitoring_span_waterfall');
            });
        }

        /*
        | Slow queries by fingerprint. Independent of tracing so it works even at a 2% sample rate:
        | any query over the threshold is counted here, whether or not its request was traced.
        */
        if (!$schema->hasTable('monitoring_slow_queries')) {
            $schema->create('monitoring_slow_queries', function (Blueprint $table) {
                $table->id();
                $table->string('fingerprint', 40);              // sha1 of the normalised SQL
                $table->timestamp('bucket_at');
                $table->string('resolution', 6)->default('hour');
                $table->text('sql_normalised');                 // literals replaced with ?
                $table->string('primary_table', 96)->nullable();
                $table->string('route', 191)->nullable();       // the route that most often causes it
                $table->unsignedBigInteger('executions')->default(0);
                $table->unsignedBigInteger('total_ms')->default(0);
                $table->unsignedInteger('max_ms')->default(0);
                $table->unsignedBigInteger('rows_examined_sum')->default(0);

                $table->unique(['fingerprint', 'resolution', 'bucket_at'], 'monitoring_slow_query_unique');
                $table->index(['resolution', 'bucket_at'], 'monitoring_slow_query_window');
            });
        }

        /*
        | Outbound dependency calls: payment gateways, shipping, SMS, mail, push, any third party.
        | Bucketed the same way requests are, because the questions are the same ones.
        */
        if (!$schema->hasTable('monitoring_dependency_buckets')) {
            $schema->create('monitoring_dependency_buckets', function (Blueprint $table) {
                $table->id();
                $table->string('resolution', 6)->default('minute');
                $table->timestamp('bucket_at');
                // A stable service key (stripe, sslcommerz, twilio, fcm), not a URL.
                $table->string('service', 64);
                $table->string('operation', 96)->default('');
                $table->unsignedBigInteger('calls')->default(0);
                $table->unsignedBigInteger('failures')->default(0);
                $table->unsignedBigInteger('timeouts')->default(0);
                $table->unsignedBigInteger('client_errors')->default(0);
                $table->unsignedBigInteger('server_errors')->default(0);
                $table->unsignedBigInteger('rate_limited')->default(0);
                $table->json('duration_buckets')->nullable();
                $table->unsignedBigInteger('duration_sum_ms')->default(0);
                $table->unsignedInteger('duration_max_ms')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_failure_at')->nullable();
                $table->string('last_error', 191)->nullable();

                $table->unique(['resolution', 'bucket_at', 'service', 'operation'], 'monitoring_dependency_unique');
                $table->index(['service', 'bucket_at'], 'monitoring_dependency_service');
            });
        }

        /*
        | The timeline. Deploys, scheduler runs, backups, incident transitions, config changes —
        | everything that has a time and might explain a graph moving. One table so the incident
        | view can lay them all on one axis, which is how "the deploy at 02:00" gets connected to
        | "the errors at 02:05".
        */
        if (!$schema->hasTable('monitoring_events')) {
            $schema->create('monitoring_events', function (Blueprint $table) {
                $table->id();
                // deploy | scheduler | backup | incident | alert | config | check | annotation
                $table->string('type', 24)->index();
                $table->string('key', 96)->nullable();          // e.g. the command name
                $table->string('severity', 12)->default('info'); // info|warning|critical|success
                $table->string('title', 191);
                $table->text('description')->nullable();
                $table->json('context')->nullable();
                $table->unsignedBigInteger('related_id')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamp('created_at')->nullable();

                $table->index(['type', 'occurred_at'], 'monitoring_event_timeline');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach ([
            'monitoring_events',
            'monitoring_dependency_buckets',
            'monitoring_slow_queries',
            'monitoring_spans',
            'monitoring_traces',
            'monitoring_errors',
            'monitoring_error_groups',
            'monitoring_series',
            'monitoring_request_buckets',
        ] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
