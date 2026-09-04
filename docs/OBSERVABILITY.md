# Observability

What the federation module tells an operator without anyone reading code (ADR-0012). Four signals, one question each.

| Signal | Answers | Where |
|---|---|---|
| Logs | what happened, in which request | JSON lines on the api container's stderr (`docker compose logs api`) |
| Traces | where the time went, across web, worker and provider | Jaeger at http://localhost:3006 |
| Probes | may traffic go here | `GET /api/health/live`, `GET /api/health/ready` (open); `GET /api/health/checks` (scrape token) |
| Metrics | how much, how old, how many | `GET /api/metrics` (Prometheus text format, scrape token) |

Nothing here leaves the machine. Nothing here contains a token, a password or an e-mail address.

## Logs

The `json` log channel writes one JSON object per line to stderr; the container runtime collects it. PHP-FPM forwards worker output undecorated (`catch_workers_output`, set in the api image). Every federation request writes one access line at the end:

```json
{"message":"request","context":{"request_id":"obs-probe-7","user_id":20,"method":"GET","route":"api/v1/federation/registration-applications","status":200,"duration_ms":168},"level_name":"INFO","extra":{"trace_id":"cd750e2f87241741c3c81b9c2c2a0b23","span_id":"425b47b0ddc09a29","service":"federation-api"}}
```

Fields and where they come from:

- `context.request_id`: the `X-Request-Id` the caller sent, or the one the API minted (`AssignRequestId`, M4). Echoed in the response header and stored on every audit entry and outbox row.
- `context.user_id`: the acting user's id, attached by `TraceRequest` through Laravel's shared log context for the length of the request. Never the e-mail, never the token.
- `extra.trace_id`, `extra.span_id`: the active OpenTelemetry span, stamped by `LogContextProcessor` on every record, in the web process and in the worker.
- Worker lines carry `event_id` and `consumer` as well (`ProcessOutboxEvent` shares them while it runs a consumer).
- The scheduler writes `{"message":"scheduled_task_failed","context":{"task":"federation:outbox-status"}}` (or the other two federation tasks) when a scheduled command exits non-zero (ADR-0015). It is the line an alarm attaches to; `docs/DEPLOYMENT.md` designs the metric filter.

Finding everything one request did:

```sh
docker compose logs --no-log-prefix api | grep '"request_id":"<id>"'      # web and worker lines
```

Tests log to the `null` channel; the file logger in `storage/logs` is upstream's and is not written by federation code (INCIDENT-001 found two containers fighting over it).

## Traces

OpenTelemetry PHP SDK, OTLP over HTTP to `jaeger:4318`, one provider per process, flushed when the process ends (`OTEL_EXPORTER=otlp`; `memory` in tests; `none` disables tracing without touching instrumented code).

Spans:

| Span | Kind | Where | Attributes |
|---|---|---|---|
| `GET api/v1/federation/…` | server | `TraceRequest`, every federation request | `http.request.method`, `http.route`, `http.response.status_code`, `federation.request_id` |
| `application.transition` | internal | `TransitionApplication::execute` | `federation.application_id`, `federation.to` |
| `outbox.process` | consumer | `ProcessOutboxEvent::handle`, in the worker | `federation.event_type`, `federation.event_id`, `federation.consumer`, `federation.attempt` |
| `learning-center.credentials` | client | `HttpCredentialsClient::fetch` | `http.request.method`, `server.address`, `http.response.status_code` |

The worker's span continues the trace of the request that wrote the fact: the outbox row stores the request's W3C `traceparent`, and the job uses it as the parent. The provider call sends `traceparent` to the Learning Center, so a provider that traces can join the same trace. `TracingTest` asserts the whole chain with the in-memory exporter.

Find a trace by request id in Jaeger: service `federation-api`, tag `federation.request_id=<id>`.

## Probes

- `GET /api/health/live` answers `200 {"status":"ok"}` without touching anything. A restart signal for an orchestrator.
- `GET /api/health/ready` answers `200 ready` or `503 not_ready` with per-check detail. Required: the database answers; the outbox's oldest unrelayed fact is younger than `READINESS_OUTBOX_MAX_AGE_SECONDS` (default 300), which is how a dead worker shows up. Reported, never required: the Learning Center's `/health` within `READINESS_LEARNING_CENTER_TIMEOUT_MS` (default 300), because every page answers from stored snapshots without it (ADR-0009). A slow provider makes readiness say `degraded` for that check and stay `ready`.
- `GET /api/health/checks` exposes upstream's spatie/health results, which `php artisan health:check` refreshes (scheduled in production, by hand here). Until it has run, the answer is `unknown`. It requires the scrape token (below) whenever one is configured: it names failing dependencies, which is operator information, not public information (ADR-0014). Two of upstream's checks expect production (`EnvironmentCheck`, `DebugModeCheck`) and fail in development by design.

## Metrics

`GET /api/metrics` computes every value from the tables on each scrape; no metrics server is needed for the numbers to be true. `METRICS_TOKEN` is the bearer token a scraper presents to this endpoint and to `/api/health/checks`; the shipped `.env.example` sets one (`dev-only-scrape-token`, to be replaced per environment), so both are gated unless an operator empties the variable, which is only defensible where the network restricts them (ADR-0014). Liveness and readiness never require it: a platform has to probe before it holds secrets.

| Metric | Meaning | Alert on |
|---|---|---|
| `federation_outbox_unpublished` | facts not yet relayed | rising with the next one |
| `federation_outbox_oldest_unpublished_seconds` | age of the oldest unrelayed fact | > 300: the worker is not running |
| `federation_outbox_parked` | facts whose consumer exhausted its retries | > 0: run the runbook's replay |
| `federation_events_processed` | consumer ledger rows | flat while `unpublished` rises: the worker relays but does not drain |
| `federation_jobs_queued`, `federation_jobs_failed` | Laravel's queue tables | `failed` > 0 |
| `federation_credential_snapshots{status}` | snapshots by eligibility status | `not_found` growing: identities the provider does not know |
| `federation_credential_snapshots_stale` | snapshots older than the limit | > 0 after the reconciliation should have run |
| `federation_applications{status}` | applications by status | `under_review` growing without `approved`: reviewers are behind |
| `federation_notifications` | notification rows | flat while approvals rise: the notification consumer is parked |

## Running it

```sh
docker compose up -d jaeger                                          # traces UI on http://localhost:3006
docker compose exec -d -u verein api php artisan federation:work     # worker, as the PHP-FPM user (ADR-0010)
docker compose exec -u verein api php artisan health:check           # upstream's checks into the result store
curl -s http://localhost:3001/api/health/ready | jq .
curl -s -H "Authorization: Bearer dev-only-scrape-token" http://localhost:3001/api/health/checks
curl -s -H "Authorization: Bearer dev-only-scrape-token" http://localhost:3001/api/metrics
```

The rehearsed incidents against these signals are in [`docs/RUNBOOK.md`](RUNBOOK.md) and `docs/baseline/operability_2026-09-03.txt`.
