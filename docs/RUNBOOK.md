# Runbook

The operator's half of the contract (ADR-0012): for each situation, the symptom, the first thing to run, what a good answer looks like, and the repair. Every command runs against the Compose stack; production would run the same commands against the same tables. The signals are described in [`docs/OBSERVABILITY.md`](OBSERVABILITY.md); the rehearsals that proved this file are in `docs/baseline/operability_2026-09-03.txt`.

## Routine

| When | Command | Good answer |
|---|---|---|
| Every deploy, every restart (development stack) | `docker compose exec -d -u verein api php artisan federation:work` | `federation_outbox_oldest_unpublished_seconds` stays near 0 |
| Continuously (release images, ADR-0015) | the `worker` and `scheduler` services in `deploy/compose.release.yml`; `php artisan schedule:list` shows the three federation tasks | both containers up; no `scheduled_task_failed` line in the scheduler's log |
| Hourly, by the scheduler (by hand in development) | `php artisan federation:reconcile-credentials` | `refreshed=n changed=m unavailable=0` |
| Every fifteen minutes, by the scheduler (or on a page) | `php artisan federation:outbox-status` | exit code 0, `failed_events=0 failed_jobs=0`; a non-zero exit writes `scheduled_task_failed` with `task: federation:outbox-status` |
| Every fifteen minutes, by the scheduler | `php artisan health:check` then `GET /api/health/checks` with `Authorization: Bearer $METRICS_TOKEN` | `status: ok` (in development two checks expect production and fail by design) |
| Before trusting anything | `GET /api/health/ready` | `status: ready`; `learning_center` may read `degraded` without affecting readiness |

## Situations

### The Learning Center is slow or down (INCIDENT-001)

- **Symptom.** A reviewer's refresh answers `503 learning_center_unavailable`; `GET /api/health/ready` shows `checks.learning_center.status: degraded` and stays `ready`; the trace of the refresh shows the `learning-center.credentials` client span in error after the timeout.
- **First.** `curl -s http://localhost:3001/api/health/ready`, then the mock's own `curl -s http://localhost:3005/health` (or the real provider's health page).
- **Good answer.** `ready` with `learning_center: degraded`. Members and reviewers keep seeing the last snapshot with its age.
- **Repair.** Nothing on this side. When the provider recovers: `php artisan federation:reconcile-credentials --all` refreshes every approved applicant; `federation_credential_snapshots_stale` returns to 0.

### The worker died or is not running (INCIDENT-003 and its cousin)

- **Symptom.** `federation_outbox_oldest_unpublished_seconds` rises; `GET /api/health/ready` answers `503` with `checks.outbox.detail: "oldest unpublished fact is Ns old; is the worker running?"`; approvals show participation `unknown` with reason `no_snapshot`.
- **First.** `docker compose exec api ps -o user,pid,args | grep federation:work`.
- **Good answer.** One `php artisan federation:work` process owned by `verein`.
- **Repair.** `docker compose exec -d -u verein api php artisan federation:work`. Never start it in another container or as another user: it writes the same cache and log files as the web process (INCIDENT-003 rehearsal). Within a second the backlog drains and readiness returns.

### A consumer keeps failing (INCIDENT-003)

- **Symptom.** `federation_outbox_parked` > 0; `php artisan federation:outbox-status` exits non-zero and prints the parked event with the consumer's name in `last_error`; the job's spans in Jaeger show the same error four times with growing gaps.
- **First.** `php artisan federation:outbox-status`.
- **Good answer.** The parked event names one consumer and one cause (for example `credential-refresh: Learning Center unreachable or too slow`). The other consumer of the same event completed.
- **Repair.** Fix the cause (usually the situation above), then `php artisan federation:outbox-replay --all` (or `<event id>`), then `php artisan queue:flush` once the replay has succeeded. The consumer ledger guarantees the effect happens once even if the replay redelivers to a consumer that already acted.

### A request went wrong and someone has the request id

- **First.** `docker compose logs --no-log-prefix api | grep '"request_id":"<id>"'` for every line the request and its worker jobs wrote; then Jaeger, service `federation-api`, tag `federation.request_id=<id>`, for the timing across the transition, the outbox job and the provider call.
- **Good answer.** One access line with `status` and `duration_ms`, the job lines with `event_id` and `consumer`, and a trace whose spans are all green.

### Tests wiped the development database (INCIDENT-000)

- **Symptom.** The seeded personas are gone after a test run; the test process read the api container's cached configuration.
- **First.** `php artisan test --filter=TestEnvironmentIsolationTest` in the tooling container.
- **Good answer.** Both tests pass: the test process uses its own cache paths and the database the testing environment names.
- **Repair.** `php artisan migrate:fresh --seeder=NorthgateDemoSeeder` then `php artisan federation:reconcile-credentials --all`, and restart the worker.

### Pages answer 500 and the API log shows permission errors

- **Symptom.** `file_put_contents(... storage/framework/cache ...): Permission denied` or `laravel.log could not be opened` in the api container's log.
- **Cause.** Something ran artisan in the api container as root or as another user, or the worker ran in the tooling container.
- **Repair.** `docker compose exec -u root api sh -c 'chown -R verein:verein storage/framework storage/logs'`, then restart the worker as `verein`.

## What is not covered yet

Scheduling (the worker, the reconciliation, `health:check` and `outbox-status` run by hand here), alert delivery, and a production log or trace store: all B8 release-engineering decisions. The runbook names the commands a scheduler would run and the exit codes it would alert on.
