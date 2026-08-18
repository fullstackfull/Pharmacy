<?php

return [
    /*
     | Request telemetry: powers the admin Monitoring and Analytics areas.
     | Recording happens after the response is sent (terminable middleware) and
     | every failure is swallowed — telemetry must never break a request.
     */
    'enabled' => env('TELEMETRY_ENABLED', true),

    // Raw request rows are pruned after this many days; daily rollups are kept.
    'retention_days' => env('TELEMETRY_RETENTION_DAYS', 30),

    // A web session that has been quiet this long starts a new visit.
    'session_gap_minutes' => 30,

    // Path prefixes that would only record noise about the panel itself.
    'ignore_prefixes' => [
        '_debugbar',
        'telemetry',
        'admin/monitoring/pulse',
    ],
];
