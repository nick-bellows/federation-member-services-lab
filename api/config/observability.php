<?php

/*
|--------------------------------------------------------------------------
| Observability (ADR-0012, docs/OBSERVABILITY.md)
|--------------------------------------------------------------------------
|
| Traces through OpenTelemetry, structured logs with request and trace
| context, liveness and readiness probes, and a metrics endpoint computed
| from the federation's own tables.
|
*/

return [

    'tracing' => [
        // otlp: export to the collector below; memory: keep spans in the process (tests);
        // none: a no-op tracer. Instrumented code never checks which one is active.
        'exporter' => env('OTEL_EXPORTER', 'none'),
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://jaeger:4318'),
        'service_name' => env('OTEL_SERVICE_NAME', 'federation-api'),
        'service_version' => env('OTEL_SERVICE_VERSION', 'dev'),
    ],

    'readiness' => [
        // A queue that has not been relayed for this long is a backlog, not a blip.
        'outbox_max_age_seconds' => (int) env('READINESS_OUTBOX_MAX_AGE_SECONDS', 300),
        // The Learning Center is reported, never required: pages answer without it.
        'learning_center_timeout_ms' => (int) env('READINESS_LEARNING_CENTER_TIMEOUT_MS', 300),
    ],

    'metrics' => [
        // Optional bearer token for /api/metrics; empty means the endpoint is open
        // and must be restricted at the network layer.
        'token' => env('METRICS_TOKEN'),
        // Snapshots older than this count as stale in the metrics, as on the pages.
        'snapshot_stale_minutes' => (int) env('LEARNING_CENTER_SNAPSHOT_TTL_MINUTES', 720),
    ],

];
