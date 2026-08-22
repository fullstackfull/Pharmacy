<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Observed response shapes
    |--------------------------------------------------------------------------
    |
    | The Developer Portal derives everything it can from the route table, the controllers and
    | their validation. Responses are the one thing it cannot reach — this API answers with
    | `response()->json(...)` directly and has almost no Resource classes — so their shape is
    | learned from real traffic instead.
    |
    | Only KEYS and TYPES are stored. No value from any response is ever written down, which is
    | what makes it safe to describe an endpoint that answers with a token or an address.
    |
    | Recording happens after the response has been sent, and a shape already seen in the last hour
    | is not looked at again, so a busy endpoint costs one array lookup rather than a write.
    |
    */

    'record_response_shapes' => env('DEVELOPER_PORTAL_RECORD_RESPONSES', true),

    /*
    |--------------------------------------------------------------------------
    | The API console ("try it")
    |--------------------------------------------------------------------------
    |
    | A console on an admin panel sends real requests at the shop that takes the
    | orders. So the defaults are the safe ones and the dangerous setting has to
    | be turned on by hand, by somebody who knows which installation they are on:
    |
    |  - Reads work out of the box. A GET changes nothing.
    |  - WRITES ARE OFF. Turning them on takes an environment variable and, per
    |    request, a typed confirmation. Off by default everywhere — including on
    |    a developer's own copy, because a default that is only safe when someone
    |    remembers to set it is not a default.
    |  - Some endpoints are never sent at any setting: money, identity, removal.
    |    That list is in ConsoleGuard and is deliberately not configurable.
    |
    | The console can only aim at this application's own API routes; there is no
    | URL field anywhere in it.
    |
    */
    'console' => [
        'enabled' => env('DEVELOPER_CONSOLE_ENABLED', true),
        'allow_writes' => env('DEVELOPER_CONSOLE_ALLOW_WRITES', false),
        // Per administrator. A console is for trying an endpoint, not for driving it.
        'rate_limit_per_minute' => env('DEVELOPER_CONSOLE_RATE_LIMIT', 20),
    ],
];
