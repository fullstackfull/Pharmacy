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
];
