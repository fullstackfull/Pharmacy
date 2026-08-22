<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | Turning this off stops all collection. The dashboard still renders and
    | says so, rather than showing stale numbers as if they were current.
    */
    'enabled' => env('MONITORING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Where monitoring data is written
    |--------------------------------------------------------------------------
    | Monitoring writes far more rows than the shop does, so it gets its own
    | connection name. By default that name points at the application's own
    | database — nothing to install, works on a single-server deployment. Set
    | MONITORING_DB_* to move it to a separate database or host the moment the
    | volume starts to matter; no code changes, no migration rewrite.
    |
    | The `separate` flag is derived, not configured: it tells the rest of the
    | system whether it may join monitoring tables against shop tables.
    */
    'connection' => env('MONITORING_DB_CONNECTION', 'monitoring'),

    /*
    |--------------------------------------------------------------------------
    | Ingestion
    |--------------------------------------------------------------------------
    | Per-request rows are never written for high-traffic paths. Requests are
    | folded into one-minute buckets per route, and only the buckets are
    | persisted — bounded cardinality, a handful of writes per minute instead
    | of one per request.
    |
    | `buffer` is where the in-flight minute lives: redis when a Redis server
    | is reachable (atomic, no DB cost on the request path), otherwise the
    | cache store. `auto` picks redis when it is actually usable.
    */
    'buffer' => env('MONITORING_BUFFER', 'auto'),      // auto | redis | cache | none
    'flush_interval_seconds' => 60,

    /*
    | Latency histogram buckets, in milliseconds. Percentiles (p50..p99) are
    | interpolated from these rather than from stored samples, which is what
    | makes real percentiles affordable: fixed memory per route per minute,
    | no matter how many requests land in it.
    */
    'latency_buckets_ms' => [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 30000],

    /*
    | Requests slower than this are considered timeouts for the timeout-rate
    | metric even when they eventually returned.
    */
    'timeout_threshold_ms' => env('MONITORING_TIMEOUT_MS', 30000),

    /*
    | High-cardinality guard. A route table is bounded; a URL with ids in it is
    | not. Paths are normalised to their route pattern, and if more than this
    | many distinct series appear in one minute the rest are folded into
    | "__other__" rather than being allowed to explode the table.
    */
    'max_series_per_minute' => 400,

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | High resolution is expensive and only useful while an incident is fresh;
    | after that, hourly and daily rollups answer every question a chart asks.
    */
    'retention' => [
        'minute_days' => env('MONITORING_RETENTION_MINUTE_DAYS', 7),
        'hour_days' => env('MONITORING_RETENTION_HOUR_DAYS', 90),
        'day_days' => env('MONITORING_RETENTION_DAY_DAYS', 400),
        'trace_days' => env('MONITORING_RETENTION_TRACE_DAYS', 3),
        'error_days' => env('MONITORING_RETENTION_ERROR_DAYS', 60),
        'log_days' => env('MONITORING_RETENTION_LOG_DAYS', 14),
        'incident_days' => env('MONITORING_RETENTION_INCIDENT_DAYS', 400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracing
    |--------------------------------------------------------------------------
    | Full traces are sampled: recording a span tree for every request of a
    | live store would cost more than the store. Slow and failed requests are
    | always kept regardless of the sample rate — those are the ones anyone
    | ever goes looking for.
    */
    'tracing' => [
        'enabled' => env('MONITORING_TRACING', true),
        'sample_rate' => (float) env('MONITORING_TRACE_SAMPLE_RATE', 0.02),
        'always_trace_slower_than_ms' => env('MONITORING_TRACE_SLOW_MS', 1500),
        'always_trace_errors' => true,
        'max_spans_per_trace' => 200,
        'slow_query_ms' => env('MONITORING_SLOW_QUERY_MS', 200),

        /*
        | Optional OTLP export. Nothing is installed for this: when an endpoint
        | is set, finished traces are POSTed as OTLP/HTTP JSON by a queued job.
        | Left empty, tracing stays entirely inside this application.
        */
        'otlp_endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),
        'otlp_headers' => env('OTEL_EXPORTER_OTLP_HEADERS'),
        'service_name' => env('OTEL_SERVICE_NAME', 'pharmacy-web'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    | Monitoring reads live production traffic, so it must never become the
    | place a secret leaks. These are enforced centrally by the Redactor; the
    | list is additive — the built-in secret names cannot be switched off.
    */
    'privacy' => [
        'mask_ip' => env('MONITORING_MASK_IP', true),
        'store_user_id' => env('MONITORING_STORE_USER_ID', true),
        'extra_redacted_keys' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prometheus exposition
    |--------------------------------------------------------------------------
    | Off by default. When a token is set, GET /monitoring/metrics returns the
    | text exposition format for a Prometheus/VictoriaMetrics scrape. No
    | package required — the format is generated here.
    */
    'prometheus' => [
        'enabled' => env('MONITORING_PROMETHEUS', false),
        'token' => env('MONITORING_PROMETHEUS_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Node exporter / external collectors
    |--------------------------------------------------------------------------
    | Host metrics come from /proc directly, which needs nothing installed.
    | These are for the cases /proc cannot answer (another host, hardware
    | sensors behind a BMC). Empty means "not configured", and the dashboard
    | says exactly that instead of guessing.
    */
    'node_exporter_url' => env('MONITORING_NODE_EXPORTER_URL'),
    'nginx_status_url' => env('MONITORING_NGINX_STATUS_URL'),
    'php_fpm_status_url' => env('MONITORING_PHP_FPM_STATUS_URL'),

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    | Defaults only. The live values are edited in Monitoring → Settings and
    | stored in the database; these are what a fresh install starts from.
    */
    'thresholds' => [
        'cpu_warning' => 75,
        'cpu_critical' => 90,
        'memory_warning' => 80,
        'memory_critical' => 92,
        'disk_warning' => 80,
        'disk_critical' => 90,
        'error_rate_warning' => 1.0,
        'error_rate_critical' => 5.0,
        'p95_warning_ms' => 800,
        'p95_critical_ms' => 2000,
        'db_latency_warning_ms' => 50,
        'db_latency_critical_ms' => 250,
        'redis_latency_warning_ms' => 10,
        'redis_latency_critical_ms' => 50,
        'queue_lag_warning_seconds' => 300,
        'queue_lag_critical_seconds' => 900,
        'scheduler_late_minutes' => 10,
        'ssl_expiry_warning_days' => 21,
        'backup_age_warning_hours' => 36,
        'stuck_order_hours' => 6,
        'payment_failure_rate_warning' => 10.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Energy
    |--------------------------------------------------------------------------
    | Real power draw needs RAPL/powercap or a BMC. Where that is absent the
    | dashboard says so; an estimate is only ever shown when it is switched on
    | deliberately here, and it is labelled Estimated, never Measured.
    */
    'energy' => [
        'estimated_mode' => env('MONITORING_ENERGY_ESTIMATED', false),
        'price_per_kwh' => env('MONITORING_ENERGY_PRICE', null),
        'currency' => env('MONITORING_ENERGY_CURRENCY', null),
        // Used only in estimated mode: idle and full-load draw of the machine.
        'estimate_idle_watts' => 45,
        'estimate_max_watts' => 180,
    ],

    /*
    |--------------------------------------------------------------------------
    | Self health
    |--------------------------------------------------------------------------
    | How long the dashboard may go without fresh telemetry before it stops
    | claiming the system is healthy and reports that monitoring itself is
    | blind. A green dashboard fed by a dead collector is the worst outcome.
    */
    'stale_after_seconds' => 180,
];
