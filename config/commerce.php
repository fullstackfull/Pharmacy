<?php

/*
 * Commerce Experience Engine (Phase 3).
 *
 * One master switch, read at the enhancement seams only. Off, every storefront and API path
 * behaves byte-identically to App Builder V2: sections sourced from a collection fall back to
 * their catalogue fallback, and no campaign, segment or experiment logic runs. This is the
 * rollback path (§79) — no migration reversal, no data loss, one env line.
 */
return [
    'enabled' => (bool) env('COMMERCE_EXPERIENCE_ENABLED', true),
];
