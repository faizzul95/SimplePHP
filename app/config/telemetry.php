<?php

/*
|--------------------------------------------------------------------------
| Telemetry / debug bar
|--------------------------------------------------------------------------
|
| Records requests, queries, mail, queue jobs, exceptions and logs, then shows
| them in a bar on any page. AJAX and API calls are recorded too, so calls made
| from a page appear in that page's bar.
|
| Turning it on:
|
|   TELEMETRY_ENABLED=true
|   TELEMETRY_MODE=all            record everyone
|   TELEMETRY_MODE=users          record only TELEMETRY_USER_IDS
|   TELEMETRY_USER_IDS=1,42       comma-separated user ids
|
| Recording captures query text and request payloads, so production is gated
| twice: TELEMETRY_ENABLED is not sufficient there, TELEMETRY_ALLOW_PRODUCTION
| must also be set. Secrets are redacted by key name in every case — see
| `redact` below.
*/

$telemetryUserIds = array_values(array_filter(
    array_map('trim', explode(',', (string) env('TELEMETRY_USER_IDS', ''))),
    static fn($id) => $id !== ''
));

$config['telemetry'] = [
    'enabled' => (bool) env('TELEMETRY_ENABLED', false),

    // off | all | users
    'mode' => (string) env('TELEMETRY_MODE', 'off'),

    'user_ids' => $telemetryUserIds,

    'allow_in_production' => (bool) env('TELEMETRY_ALLOW_PRODUCTION', false),

    // Empty means every type. Otherwise a subset of:
    // request, query, mail, queue, exception, log, cache, http, event
    'types' => [],

    // Injects the floating bar into HTML responses. Turn off to keep the
    // recorder and read entries through the JSON endpoint only.
    'inject_bar' => (bool) env('TELEMETRY_INJECT_BAR', true),

    // Per-request ceilings. A page issuing thousands of queries costs a fixed
    // amount rather than the process.
    'max_entries_per_request' => (int) env('TELEMETRY_MAX_ENTRIES', 300),
    'max_value_length' => (int) env('TELEMETRY_MAX_VALUE_LENGTH', 2000),
    'max_trace_frames' => (int) env('TELEMETRY_MAX_TRACE_FRAMES', 20),

    'slow_query_ms' => (float) env('TELEMETRY_SLOW_QUERY_MS', 100),

    /*
    | Payload keys holding secrets. Matched as substrings, case-insensitively,
    | with -/./space normalised to _ so `api_key`, `X-API-KEY` and `apiKey` are
    | all caught by one entry. Replacing this list replaces the default
    | entirely, so include what you still want covered.
    */
    'redact' => \Core\Telemetry\Recorder::defaultRedactedKeys(),

    'storage' => [
        'path' => (string) env('TELEMETRY_PATH', '') ?: null,
        'max_bytes_per_day' => (int) env('TELEMETRY_MAX_BYTES_PER_DAY', 64 * 1024 * 1024),
        'retention_days' => (int) env('TELEMETRY_RETENTION_DAYS', 3),
    ],
];
