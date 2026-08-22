<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | Turning this off stops all collection. Every screen then says so, rather
    | than showing zeros that read as "nothing happened".
    */
    'enabled' => env('ANALYTICS_ENABLED', true),

    /*
    | Where events are written. Null means the application's own connection.
    | Analytics writes far more rows than the shop does, so this exists to move
    | it onto its own database the moment the volume starts to matter.
    */
    'connection' => env('ANALYTICS_DB_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Collection
    |--------------------------------------------------------------------------
    | Events are buffered during the request and written once, after the
    | response has been sent. Nothing on the request path waits for analytics —
    | if the write fails, the shopper never learns of it and the order still
    | goes through.
    */
    'buffer_limit' => 40,

    // A visit that has been quiet this long is a new session.
    'session_gap_minutes' => 30,

    // A session with at least this many seconds or two pageviews is "engaged";
    // anything less is a bounce. Both are measured, not guessed.
    'engaged_after_seconds' => 10,

    /*
    | The same event arriving twice — a double-clicked button, a retried mobile
    | upload, a page restored from the back/forward cache — is counted once.
    | Deliberately seconds, not minutes: the duplicates worth collapsing are
    | accidents moments apart, while four genuine pageviews in one visit are
    | four pageviews. Events that must be unique for longer (an order, a
    | payment, a sign-up) carry their own key and are not affected by this.
    */
    'dedupe_window_seconds' => 5,

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | Raw events answer "what exactly did this visitor do"; the rollups answer
    | every question a chart asks. Raw rows are the expensive half, so they are
    | kept only as long as an investigation plausibly reaches back.
    */
    'retention' => [
        'event_days' => env('ANALYTICS_RETENTION_EVENT_DAYS', 90),
        'session_days' => env('ANALYTICS_RETENTION_SESSION_DAYS', 400),
        'daily_days' => env('ANALYTICS_RETENTION_DAILY_DAYS', 1100),
        'click_days' => env('ANALYTICS_RETENTION_CLICK_DAYS', 400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cardinality
    |--------------------------------------------------------------------------
    | A URL with an id in it is an unbounded dimension. Paths are normalised to
    | their pattern (/product/{slug}) before they are stored, and no rollup
    | keeps more than this many distinct keys per dimension per day; the rest
    | are folded into "__other__" and the fold is reported rather than hidden.
    */
    'max_keys_per_dimension' => 500,

    /*
    |--------------------------------------------------------------------------
    | Who is not a visitor
    |--------------------------------------------------------------------------
    | Bots and staff are recorded but flagged, never silently dropped: an
    | operator has to be able to see how much of their traffic is which, and a
    | filter that quietly deletes data cannot be checked. Every report excludes
    | them by default and says that it does.
    */
    'exclude_bots' => env('ANALYTICS_EXCLUDE_BOTS', true),
    'exclude_internal' => env('ANALYTICS_EXCLUDE_INTERNAL', true),

    // Staff traffic: any logged-in admin or vendor, plus these networks.
    // CIDR or plain addresses; an empty list means "identify staff by login only".
    'internal_ips' => array_filter(explode(',', (string) env('ANALYTICS_INTERNAL_IPS', ''))),

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    | Analytics reads live customer traffic, so the rules are absolute:
    |
    |  - No password, OTP, token, card number or CVV is ever stored. Event
    |    properties pass through the same redactor the monitoring system uses.
    |  - No precise location. Country, at most, and only from a source that
    |    provides it — never from a GPS reading.
    |  - No fingerprinting. Identity is a first-party cookie the visitor can
    |    clear, not a hash of their hardware.
    |  - IP addresses are masked to their network before hashing, and the hash
    |    is salted with the app key so it cannot be reversed into an address.
    */
    'privacy' => [
        'mask_ip' => env('ANALYTICS_MASK_IP', true),
        'store_country' => env('ANALYTICS_STORE_COUNTRY', true),
        // Header a CDN or proxy sets with a resolved country (Cloudflare sets
        // CF-IPCountry). Empty means country is simply not available, which is
        // what the reports will say.
        'country_header' => env('ANALYTICS_COUNTRY_HEADER', 'CF-IPCountry'),
        'respect_do_not_track' => env('ANALYTICS_RESPECT_DNT', false),
        'require_consent' => env('ANALYTICS_REQUIRE_CONSENT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Campaign short links
    |--------------------------------------------------------------------------
    | The campaign builder issues short links that redirect to a destination on
    | this site. The allow-list is what stops it becoming an open redirect: a
    | destination outside these hosts is refused at creation time, not followed
    | at click time. APP_URL's host is always allowed.
    */
    'campaigns' => [
        'path' => env('ANALYTICS_CAMPAIGN_PATH', 'go'),
        'extra_allowed_hosts' => array_filter(explode(',', (string) env('ANALYTICS_CAMPAIGN_HOSTS', ''))),
        // Clicks per minute per IP on the redirect endpoint.
        'click_rate_limit' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser beacon
    |--------------------------------------------------------------------------
    | Client-side events (scroll depth, add-to-cart from a component that never
    | reloads the page, web vitals) POST to this endpoint. It is rate limited
    | per visitor, accepts only known event names, and never trusts a value it
    | is handed for anything money-related.
    */
    'beacon' => [
        'enabled' => env('ANALYTICS_BEACON', true),
        'path' => 'analytics/collect',
        'rate_limit_per_minute' => 60,
        'max_events_per_request' => 20,
    ],
];
