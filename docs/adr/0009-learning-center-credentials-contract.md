# ADR-0009: Learning Center credentials contract and derived participation

- Status: accepted (2026-09-03, owner's decisions at the start of B2)
- Milestone: B2 (M5 in the brief)
- Related: ADR-0005 (hierarchy), ADR-0006 (state machine and audit), ADR-0007 (identity boundary), roadmap decision 6, [`docs/contracts/learning-center-credentials-v1.md`](../contracts/learning-center-credentials-v1.md), [INCIDENT-001](../incidents/INCIDENT-001-slow-credential-service.md)

## Context

The Learning Center owns credential facts and derives eligibility from them; this repository owns registration transactions and needs to answer "may this approved person take part" without sharing a database. Roadmap decision 6 places a credentials endpoint in the Learning Center. Three further choices had to be made: what identifies the person across the two services, how a service authenticates to the other, and how a slow or absent provider is handled when a page needs an answer. The Learning Center had only person tokens, member UUIDs on its routes, and a public eligibility endpoint with memorable ids; this repository had OIDC subjects on users and a state machine with one writer.

## Decision

1. **The contract is keyed by the OIDC subject**, URL-encoded in the path: `GET /v1/members/{subject}/credentials`. Both systems already store the subject as a unique identity column; nothing is copied or synchronised. The provider returns its own member id in the body for support. Enumeration is bounded by authentication and scope, not by the key.
2. **The federation calls as a service with an OAuth2 client-credentials token** from the shared identity provider, audience `https://learning-center.northgate.example`, scope `credentials:read`. The provider verifies it with its existing verifier and authorises on scope; no member row is involved. The mock provider issues these tokens in development and CI; Auth0 calls them machine-to-machine.
3. **Participation is derived on read from a stored snapshot, never from a live call.** `credential_snapshots` holds the last answer verbatim with the provider's `as_of` and this side's `fetched_at`. Snapshots are refreshed after an approval (best effort, bounded by an 800 ms timeout, a log line on failure), by a reviewer's explicit action (`refresh-credentials`, 503 with a stable code when the provider is away), and by `federation:reconcile-credentials`. A snapshot older than the configured limit is shown as stale and still answers. A change of eligibility status is audited on the user.
4. **The contract is executable on both sides.** The fixture files are the reference responses; this repository's tests are fed from them, the mock in Compose serves them, and the provider's tests assert shape equality against copies that name this repository as the source of truth.
5. **The consumer never re-derives the provider's rules.** It branches on `eligibility.status` and each credential's `valid` flag; expiry semantics stay in the provider.

## Alternatives considered

1. **Learning Center member UUID as the key** — keeps the provider's route shape, but needs a lookup by subject first and a stored foreign id to keep in sync. Rejected: two ids for one person and a second endpoint.
2. **Forwarding the applicant's own token** — no new auth code, but a reviewer working a queue and a nightly reconciliation have no applicant token. Rejected: the two paths that matter most cannot work.
3. **A static API key** — simplest, but a hand-rotated secret and a new auth mode in a service that has none. Rejected in favour of the provider the services already share.
4. **A materialised `participation_status` column** — fastest reads, but a second writer next to the state machine and no age on the answer. Rejected: the domain model's rule that participation is computed, not stored, stays.
5. **A live call on every read** — honest and simple, but every page inherits the provider's latency, which is Incident 1 unmitigated. Rejected.

## Consequences

- The provider side lives in `learning-center-reference` (its pull request #1 adds the endpoint, scope-checked service tokens, OpenAPI, tests and an e2e step). Its answers differ from the fixtures in free text (`eligibility.reason`), in role order, and in returning `expires_at: null` with `valid: false` when nothing is on file; the contract document records all three as within the contract.
- Subjects are provider-scoped: the two demo stacks share a namespace only once both use the same identity provider. The consumer's fixtures use this repository's demo subjects; the provider's tests use its own.
- Reads are deterministic and fast; freshness is a policy (limit, reconciliation interval) rather than a property of the page.
- The reviewer sees credential findings before approving; the status is "blocked" with `not_approved` first, so a preview never reads as permission.
- Follow-ups: windows in a federation-defined time zone (future work), an outbox for `credentials.changed` (B3), retry and jitter in reconciliation (B3), a refresh token for the service token cache across processes (B5).
