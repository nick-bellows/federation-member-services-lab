# INCIDENT-003 — The worker fails after an approval

- Date: 2026-09-03 (rehearsed on the Compose stack, not suffered)
- Severity: designed exercise; no user impact
- Log: [`docs/baseline/incident_003_2026-09-03.txt`](../baseline/incident_003_2026-09-03.txt)
- Related: [ADR-0010](../adr/0010-transactional-outbox-and-consumers.md), [INCIDENT-001](INCIDENT-001-slow-credential-service.md)

## Scenario

A reviewer approves an application. The approval commits and writes two facts to the outbox. The worker delivers them: the notification consumer writes its row, and the credential-refresh consumer calls the Learning Center, which is slow. The refresh must not undo the approval, must not block the notification, must retry, must park visibly, and must be replayable once the provider recovers, with exactly one effect at the end.

## What was done

1. The mock was slowed to two seconds per response; the client timeout is 800 ms.
2. A fresh referee application was started, completed, submitted, reviewed and approved through the real actions inside the API container.
3. Within a second the worker relayed both facts and ran three jobs: the two notification jobs succeeded, the refresh job timed out and was released for retry. The status command showed `queued_jobs=1` through the backoff windows of 2, 10 and 60 seconds.
4. After the fourth attempt the job went to `failed_jobs`, the outbox row was parked with `failed_at`, `attempts=5` (one notification try plus four refresh tries) and a `last_error` naming the consumer and the timeout. The status command exited non-zero and printed the parked event.
5. The approval's notification row existed from the first second; the applicant's participation read "unknown" with "not asked yet" from the stored state, never a guess.
6. The mock was restored and an operator ran `federation:outbox-replay --all`: one event replayed, the worker delivered it, the snapshot arrived as `suspended` (the seeded referee has an active hold), and the parked marker cleared. The consumer ledger meant the notification consumer, delivered again by the replay, did nothing.

## What held

- The approval never waits on or depends on any consumer (covered by `OutboxTest` and `ParticipationHttpTest`).
- One job per event and consumer: the failing consumer did not hold the other one (covered by `OutboxTest::test_incident_003…`).
- Retries with backoff, a terminal parked state with a reason on the outbox row, an exit code for a scheduler, and a replay command.
- Idempotency through the processed-events ledger: the replay re-delivered to both consumers and produced one effect.

## What went wrong while setting it up

- The first two attempts did not exercise the failure at all. The API container still ran the cached `sync` queue driver from before the environment change, so the relay's dispatch executed the consumers inline inside the relay's own transaction; the refresh timed out, the exception unwound the publication and the notification rows, and the worker loop died. Two hardenings came out of it: the relay refuses to run on the sync driver with a clear message, and the worker loop survives an exception in one pass.
- The worker was first started in the tooling container, then as the wrong user in the api container. Each created cache and log files the web process could not write, and the pages failed with a 500 that looked like an application bug. The worker runs in the api container as the PHP-FPM user; the CI job does the same.
- Two parallel server-side requests for a brand-new identity both provisioned the user; one hit the unique email key. Provisioning now uses create-or-first semantics (M3 fix found by B3 load).

## Follow-ups

- Schedule the worker and the status command and alert on the exit code (B5).
- Retry with jitter and a per-consumer attempts view if consumer counts grow (future work).
- A notifications surface for members; today the rows are the deliverable.
