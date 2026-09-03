# ADR-0010: Transactional outbox, consumers and the queue

- Status: accepted (2026-09-03, owner's decisions at the start of B3)
- Milestone: B3 (M6 in the brief)
- Related: ADR-0006 (state machine and audit), ADR-0009 (credentials contract), [INCIDENT-003](../incidents/INCIDENT-003-worker-fails-after-approval.md)

## Context

Until B3, every side effect of a state change ran in the request: upstream's mailables and listeners declare `ShouldQueue` but the queue is `sync`, and B2's credential refresh after an approval was a synchronous listener whose failure was a log line. The transition action already dispatched its event only after commit, which narrows but does not close the dual-write window: a process that dies between commit and publish loses the event, and nothing retries. B3 needed facts that survive the process, delivery that survives a failing consumer, and an operator's view of both.

## Decision

1. **A transactional outbox.** `outbox_events` rows are written by `OutboxRecorder` inside the transaction that changes state; recording outside a transaction is a programming error. Facts published: `application.submitted`, `application.approved`, `application.rejected` (from `TransitionApplication`) and `credentials.changed` (from `CredentialSnapshots`). Each row carries the aggregate, a payload, the request id and `occurred_at`.
2. **Laravel's database queue as the delivery mechanism.** `federation:outbox-relay` takes unpublished rows in insertion order under a row lock and dispatches one `ProcessOutboxEvent` job per subscribed consumer, then sets `published_at`. With the database queue the job rows and the update commit together; with a remote broker the same loop is at-least-once and the ledger below absorbs duplicates.
3. **One job per event and consumer.** Consumers fail, retry and park independently; one consumer's outage never holds another's effect. Jobs retry four times with backoff of 2, 10 and 60 seconds, mirror `attempts` and `last_error` onto the outbox row, and park the row with `failed_at` on the final failure.
4. **A processed-events ledger per consumer.** `ProcessOutboxEvent` inserts `(consumer, event_id)` with insert-or-ignore inside the consumer's transaction and skips the event when the row already exists. The consumer's writes and its ledger row commit together, so a crash between them leaves neither.
5. **Two consumers.** `notifications` writes a `federation_notifications` row for the person concerned (a mailer would read the same rows; the stack has no mail service); `credential-refresh` performs B2's Learning Center refresh after an approval. The synchronous listener is gone.
6. **One worker process for development and CI.** `federation:work` relays, drains the queue with `queue:work --stop-when-empty`, and repeats; it runs in the api container as the PHP-FPM user. `federation:outbox-status` reports counts and exits non-zero when anything failed; `federation:outbox-replay` re-dispatches parked events.

## Mapping to a broker (documented, not implemented)

| Concern | Database queue (this repository) | SQS | RabbitMQ | Kafka |
|---|---|---|---|---|
| Relay | one transaction: jobs rows + `published_at` | publish, then mark; at-least-once | publish with confirms, then mark | produce with acks=all, then mark |
| Ordering | relay drains by id | FIFO queue with message group = aggregate id | one queue, one consumer per key | partition key = aggregate id |
| Retry and parking | `tries`, `backoff`, `failed_jobs`, outbox `failed_at` | visibility timeout, redrive to a dead-letter queue | nack with requeue, dead-letter exchange | consumer-side retry topic, dead-letter topic |
| Idempotency | processed-events ledger | same ledger; SQS deduplication ids help only within five minutes | same ledger | same ledger; offsets are not effects |
| Fan-out | one job per consumer | one queue per consumer subscribed to a topic (SNS) | one queue per consumer bound to an exchange | one consumer group per consumer |

Nothing above is provisioned. The relay and the ledger are the parts that carry over unchanged.

## Alternatives considered

1. **Queued listeners with `afterCommit` and no outbox** — smallest, Laravel-native; loses the event if the process dies between commit and dispatch, and the ADR would have had to say so. Rejected.
2. **An outbox polled by a bespoke worker without the jobs table** — fewer moving parts; retries, backoff and the failed state hand-rolled. Rejected in favour of the framework's tested machinery.
3. **One job per event fanning out to every consumer** — half the jobs; one failing consumer blocks the others and retries re-run the successful ones. Rejected.
4. **Natural unique keys only for idempotency** — works for notifications (unique per person and event) but every new consumer must find its own key or double-act. Kept as the second line, not the first.
5. **Mark-before-process** — no duplicates, but a crash mid-handle loses the effect. Rejected: the incident must be a retried effect, not a lost one.

## Consequences

- `QUEUE_CONNECTION=database` in the development environment; upstream's queued mailables and listeners now run in the worker instead of the request, which is closer to production and changes nothing they do.
- Reads never wait on consumers; participation after an approval is "unknown" until the worker runs, which in Compose and CI is within a second or two.
- The worker must run as the same user as the web process on the shared storage mount, or file-cache and log writes fail with a 500 on the web side. Found during the INCIDENT-003 rehearsal; recorded with INCIDENT-000's config-cache trap and INCIDENT-001's log-file trap as one family.
- Attempts are counted per row across consumers; a parked row names the consumer in `last_error`.
- Follow-ups: schedule the relay and the reconciliation (B5); a per-consumer attempts view if consumer counts grow; a broker adapter behind the relay when one is chosen (B8).
