# INCIDENT-001 — The credential service is slow

- Date: 2026-09-03 (rehearsed on the Compose stack, not suffered)
- Severity: designed exercise; no user impact
- Log: [`docs/baseline/incident_001_2026-09-03.txt`](../baseline/incident_001_2026-09-03.txt)
- Related: [ADR-0009](../adr/0009-learning-center-credentials-contract.md), [contract v1](../contracts/learning-center-credentials-v1.md)

## Scenario

The Learning Center answers every credentials request after two seconds. The federation has approved applicants, a reviewer wants a fresh answer, members open their pages, and the nightly reconciliation runs. Nothing may hang, nothing may show a guess as a fact, and the system must repair itself once the provider recovers.

## What was done

1. Baseline with a healthy mock: a reviewer's refresh answered in about half a second.
2. The mock was restarted with a two-second delay on every response.
3. The reviewer clicked refresh: the API answered `503` with code `learning_center_unavailable` and a human sentence. The credential call itself was cut off by the client at 802 ms, measured from both the API container and the tooling container; the rest of the two seconds on the wire was this development stack's own request latency.
4. The application page still answered from the stored snapshot, with its fetch time and no stale marker because the snapshot was younger than the limit.
5. Reconciliation ran while the provider was slow: six applicants reported unavailable, one refreshed without a call (no linked identity), the command exited non-zero so a scheduler would notice.
6. The mock was restored.
7. Reconciliation ran again: seven refreshed, none unavailable. A later run, after the seeded personas received their subjects, recorded one change (a referee's credentials went from "no record" to lapsed), audited as `credentials.changed`.
8. The reviewer's refresh and the page both answered normally.

## What held

- The approval path never depends on the provider: an approval during the outage would have succeeded with participation "unknown" until reconciliation (covered by `ParticipationHttpTest`).
- Reads never call the provider (covered by `ParticipationHttpTest`, which counts provider requests around a queue read).
- The timeout is a property of the client, not of the page: 800 ms total, 300 ms to connect, both configurable.
- The answer always carries its age; a snapshot older than the limit is marked stale but still shown.

## What was learned

- A failure signal is only useful if something listens: the command's non-zero exit is the hook for a scheduler alert (B5 operability).
- The API's own latency on this bind-mounted Docker Desktop stack is 0.3 to 1.2 s per request. Measuring a timeout through it is misleading; measure the client directly, then the path.
- Two containers sharing one log file over a bind mount is the same class of trap as INCIDENT-000's shared config cache: a root-owned log file made every request that tried to log fail with a 500 while this exercise was being set up. Tests now log to the null channel; the file was made writable; a per-process log path is recorded as future work.

## Follow-ups

- Schedule `federation:reconcile-credentials` (B5) and alert on its exit code.
- Add retry with jitter for the reconciliation's per-user call (B3).
- Cache the service token in a store shared across PHP processes (already the file cache in Compose; document for production in B8).
