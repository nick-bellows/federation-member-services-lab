<?php

/*
|--------------------------------------------------------------------------
| Learning Center credentials contract (consumer side)
|--------------------------------------------------------------------------
|
| docs/contracts/learning-center-credentials-v1.md and ADR-0009. The
| federation reads credential facts and derived eligibility over HTTP with
| its own service token; participation is derived from stored snapshots.
|
*/

return [

    'contract' => 'learning-center.credentials.v1',

    'base_url' => env('LEARNING_CENTER_BASE_URL', 'http://learning-center:3005'),

    // Milliseconds. A slow credential service must never hold a page or an approval.
    'connect_timeout_ms' => (int) env('LEARNING_CENTER_CONNECT_TIMEOUT_MS', 300),
    'timeout_ms' => (int) env('LEARNING_CENTER_TIMEOUT_MS', 800),

    // A snapshot older than this is shown as stale and picked up by reconciliation.
    'snapshot_ttl_minutes' => (int) env('LEARNING_CENTER_SNAPSHOT_TTL_MINUTES', 720),

    // OAuth2 client-credentials token for calling the Learning Center as a service.
    'token' => [
        'endpoint' => env('LEARNING_CENTER_TOKEN_ENDPOINT', 'http://oidc:8080/default/token'),
        'client_id' => env('LEARNING_CENTER_CLIENT_ID', 'federation-api'),
        'client_secret' => env('LEARNING_CENTER_CLIENT_SECRET'),
        'audience' => env('LEARNING_CENTER_AUDIENCE', 'https://learning-center.northgate.example'),
        'scope' => 'credentials:read',
        // Seconds subtracted from the token's lifetime before it is refreshed.
        'refresh_margin' => 60,
    ],

];
