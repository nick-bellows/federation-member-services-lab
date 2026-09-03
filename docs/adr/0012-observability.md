# ADR-0012: Observability: traces, structured logs, probes and metrics

- Status: accepted (2026-09-03, owner's decisions at the start of B5)
- Milestone: B5 (M8 in the brief)
- Related: ADR-0009 (credentials contract), ADR-0010 (outbox), [`docs/OBSERVABILITY.md`](../OBSERVABILITY.md), [`docs/RUNBOOK.md`](../RUNBOOK.md)

## Context

Upstream registered nine spatie/health checks and exposed none of them; logging was a single file with no structure; the request id existed since M4 but no log line carried it; nothing traced; nothing measured. The three rehearsed incidents were each diagnosed by reading tables by hand. B5 had to make the system answer "what is it doing, is it healthy, where did the time go" without code, and to prove the answers against the same three incidents.

## Decision

1. **Traces through OpenTelemetry, exported over OTLP to a local Jaeger.** One tracer provider per process built from configuration (`otlp`, `memory`, `none`); a server span per federation request, a span around the transition, a consumer span per outbox job that continues the request's trace through a `traceparent` stored on the row, and a client span around the credential call that sends `traceparent` to the provider. The exporter is configuration; instrumented code never knows which one is active.
2. **Structured logs as JSON lines on stderr with context.** A `json` channel with a JSON formatter and a processor that stamps the active trace and span ids; the request id and the acting user's id attached through Laravel's shared context for the request, the event id and consumer for the worker; one access line per federation request with route, status and duration. PHP-FPM forwards worker output undecorated. Never a token, never an e-mail.
3. **Liveness, readiness and metrics as endpoints.** `/api/health/live` answers without touching anything; `/api/health/ready` requires the database and an outbox that is not backing up, and reports the Learning Center without requiring it; `/api/health/checks` routes upstream's spatie results; `/api/metrics` renders the federation's numbers in the Prometheus text format computed from the tables on each scrape, optionally behind a bearer token. No metrics server.
4. **A runbook proved by rehearsal.** The three incidents re-run against the signals, with the first command, the good answer and the repair for each, retained as a baseline log.

## Alternatives considered

1. **Logs only with correlation ids** — no new container, no timing across services. Rejected: the outbox's worker and the provider call are exactly where time hides.
2. **Laravel Telescope** — a development debug panel, not an operability signal, never enabled in production. Rejected for the milestone; not excluded for local debugging.
3. **Keep the single log file and prefix the request id** — the shared file across containers is the trap INCIDENT-001 and 003 already hit. Rejected.
4. **A hosted log or trace service** — a cost and an account; out of scope by the workspace rules without approval. Rejected.
5. **Expose spatie's JSON results only** — one route, no readiness distinction, no metrics. Rejected; the route exists anyway as `/api/health/checks`.
6. **Prometheus and Grafana in Compose** — two more containers and dashboards to keep true; the numbers are true without them. Deferred to B8 if a deployment wants them.

## Consequences

- The development stack gains a Jaeger container and a JSON log stream; the api image forwards worker stderr; `LOG_CHANNEL=json` and `OTEL_EXPORTER=otlp` in the example environment; tests use the in-memory exporter and the null log channel.
- A slow Learning Center degrades readiness's report and nothing else, by design (ADR-0009).
- Readiness treats a stale outbox as not ready: a dead worker takes an instance out of rotation, which is the intended pressure.
- Upstream's `EnvironmentCheck` and `DebugModeCheck` fail in development by design; the checks endpoint says so rather than hiding them.
- Follow-ups: scheduling and alert delivery (B8); a broker-side trace context when a broker replaces the database queue; dashboards if a metrics server arrives; the OpenTelemetry Laravel auto-instrumentation extension for database and HTTP spans without hand-written code.
