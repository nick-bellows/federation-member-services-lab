# Case study: modernizing an inherited system into a federation's member-services platform

A fictional federation, the Northgate Soccer Federation, needed a member-services platform: organizations, seasons, registration windows, applications with documents and review, identity through OpenID Connect, credential facts from a separate learning system, and the operability a federation expects. Rather than start from nothing, this project forked a real open-source club-management system, [vereinfacht](https://github.com/vereinfacht/vereinfacht) (Laravel 13, Next.js 14, MIT), learned it, and extended it in eleven bounded milestones. Every number below links to a retained run; every "works" links to a test or a CI job. The system is validated in CI on three database engines and rehearsed from release images in Compose; it is not deployed.

The federation is invented. Nothing here uses a real federation's marks, data or architecture.

## Starting point

Upstream's `main` at the fork point had 127 commits, one company's contributors, and no external pull request ever merged. Reading it before changing anything ([`docs/UPSTREAM_ANALYSIS.md`](UPSTREAM_ANALYSIS.md)) found what a new engineer would find on the first week of a job:

- A README that documented an end-to-end test setup that did not exist, and no CI running tests or lint.
- Tests on SQLite in memory while development ran MariaDB, with MySQL-only SQL in seven exports, a model scope and a migration.
- A public application form running on a long-lived super-admin token held by the web server; an apply action with three writes and an event and no transaction; every exception mapped to a 422 with its message.
- No index on the tenant column of four tables; a listing that queried per row.
- A configuration read through `env()` that returned the production domain whenever the configuration was cached, which the container's entrypoint always did.

None of these is unusual. They are what an inherited system looks like, and the point of the project was to work inside it rather than around it.

## What was built, in order

| Milestone | What changed | Evidence |
|---|---|---|
| M0 Archaeology | The request path traced end to end; the gaps catalogued; nothing changed | [`docs/UPSTREAM_ANALYSIS.md`](UPSTREAM_ANALYSIS.md), `docs/baseline/phpunit.txt` |
| M1 Baseline | One behaviour fix with a fail-then-pass test (the `env()` read), line-ending safety, a CI workflow | [ADR-0002](adr/0002-runtime-settings-through-config-not-env.md), `docs/baseline/env_bug_before_fix.txt` |
| M2 Domain | Federation, organization, season, window; a seven-state application lifecycle with one writer; an append-only audit trail; duplicate protection at two layers | [`docs/DOMAIN_MODEL.md`](DOMAIN_MODEL.md), [ADR-0006](adr/0006-application-state-machine-and-audit-trail.md) |
| M3 Identity | OpenID Connect with the token kept server-side; the API validates against the issuer's keys and derives capabilities from its own tables; a mock provider in Compose and CI | [ADR-0007](adr/0007-oidc-identity-boundary.md), [INCIDENT-000](incidents/INCIDENT-000-dev-database-wiped-by-config-cache.md) |
| M4 Review slice | Windows, applications, document metadata, a second JSON:API server with generated types, idempotent submission, member and reviewer pages, browser tests with accessibility scans | [ADR-0008](adr/0008-document-metadata-without-file-storage.md), [INCIDENT-002](incidents/INCIDENT-002-duplicate-submission.md) |
| M5 Learning Center | A versioned credentials contract executable as fixtures on both sides; a service token; participation derived on read from a snapshot with its age; the slow-provider incident rehearsed | [`docs/contracts/learning-center-credentials-v1.md`](contracts/learning-center-credentials-v1.md), [ADR-0009](adr/0009-learning-center-credentials-contract.md), [INCIDENT-001](incidents/INCIDENT-001-slow-credential-service.md) |
| M6 Events | A transactional outbox, a worker, a processed-events ledger, retries and a parked state an operator can replay; the worker-dies-after-approval incident rehearsed | [ADR-0010](adr/0010-transactional-outbox-and-consumers.md), [INCIDENT-003](incidents/INCIDENT-003-worker-fails-after-approval.md) |
| M7 PostgreSQL | A three-engine CI matrix; the MySQL-only SQL made portable in place with regression tests | [`docs/DATABASE_COMPATIBILITY.md`](DATABASE_COMPATIBILITY.md), [ADR-0011](adr/0011-postgresql-compatibility-matrix.md) |
| M8 Operability | JSON logs with request, user and trace ids; traces from the request through the worker to the provider; liveness, readiness, checks and metrics; a runbook proved by re-running the three incidents | [`docs/OBSERVABILITY.md`](OBSERVABILITY.md), [`docs/RUNBOOK.md`](RUNBOOK.md), [ADR-0012](adr/0012-observability.md) |
| M9 Accessibility and performance | A manual WCAG 2.1 AA review with a keyboard walk; five indexes and an eager-loaded listing, measured before and after | [`docs/ACCESSIBILITY.md`](ACCESSIBILITY.md), [`docs/PERFORMANCE.md`](PERFORMANCE.md), [ADR-0013](adr/0013-accessibility-and-performance-evidence.md) |
| B7 Security | A threat model as attack trees; JSON Patch with field-level authorization; operator surfaces behind a token; a test that no token is ever logged | [`docs/THREAT_MODEL.md`](THREAT_MODEL.md), [ADR-0014](adr/0014-security-review.md) |
| M10 Release | Release images, a one-off migration task, the worker and scheduler as services, a rehearsal with no bind mount, a designed-not-provisioned deployment, a release checklist and rollback plan | [`docs/DEPLOYMENT.md`](DEPLOYMENT.md), [`docs/RELEASE.md`](RELEASE.md), [ADR-0015](adr/0015-release-engineering.md) |

## Three decisions worth explaining

**Credentials are facts, and this system never owns them.** The Learning Center owns education, credential facts and safeguarding eligibility. The federation calls it with a service token, stores a snapshot with its age, and derives a participation status on read: may participate, blocked, or unknown, with reasons. When the provider is slow (INCIDENT-001) the pages keep answering from the snapshot and readiness reports the provider as degraded without failing. The alternative, sharing a database or copying the eligibility logic, would have coupled two systems that different teams own.

**Side effects go through an outbox, and delivery is at-least-once made to act once.** Every state change writes its facts in the same transaction as the change; a relay publishes them onto a queue; each consumer records what it has processed. When the worker dies after an approval (INCIDENT-003) nothing is lost: the fact waits in the outbox, readiness reports its age, and the runbook says what to run. The broker in Compose is Laravel's database queue; the mapping to SQS, RabbitMQ or Kafka is written down and none is provisioned.

**Field-level authorization lives in a patch handler, not in the resource update.** JSON:API's read-only markers protect a field from everyone at once; they cannot say "this field, for this person, while the application is a draft". A dedicated JSON Patch route parses the whole document, authorises every operation before applying any, refuses the patch if one operation is refused, and writes one audit entry. A status change disguised as a field is impossible because status is in no allow-list, and the model refuses direct status writes anyway.

## Numbers, with their caveats

| Measurement | Before | After | Where | Caveat |
|---|---|---|---|---|
| Rows examined by the memberships listing's member query | 32,020 (full scan) | 1,510 (index lookup) | `docs/baseline/perf_explain_before.txt`, `perf_explain_after.txt` | synthetic seed of 30,000 members |
| Queries per page of twenty memberships | 89 | 11 | `perf_query_count_before.txt`, `perf_query_count_after.txt` | guarded by a test |
| Listing p95, ten virtual users for thirty seconds | 663 ms | 333 ms | `perf_before.json`, `perf_after_eager.json` | a laptop, the rate limit raised for the window |
| Backend tests | 91 (upstream) | 237 | `docs/baseline/phpunit*.txt` | SQLite locally; three engines in CI |
| Composer advisories | 13 in 3 upstream packages | 0 | `security_audit_2026-09-03.txt`, `security_audit_after_b8_2026-09-04.txt` | within-major fixes only |
| npm advisories, frontend | 8 | 4 | same | the four remaining need a major (`next`, `sharp`, `swiper`, `postcss`) and are listed in `future-work.md` |

## What was not done

- Nothing is deployed. The architecture is designed and priced at nothing; provisioning needs the owner's approval and money.
- No penetration test, no fuzzing; the threat model is read from the code and the tests.
- No screen reader was run by ear; the accessibility review is a keyboard walk and a per-criterion record.
- The Auth0 tenant walkthrough is planned; CI and development use a self-hosted mock provider.
- Nothing has been offered upstream yet; one offer is drafted ([`docs/UPSTREAM_OFFER.md`](UPSTREAM_OFFER.md)) and waits for the owner's word.
- Four dependency majors are unapplied, on purpose, with their advisories recorded.

## How to read the repository in five minutes

1. The README's milestone table, one row per milestone with its evidence.
2. One ADR, say [ADR-0010](adr/0010-transactional-outbox-and-consumers.md), for how decisions are recorded.
3. One incident, say [INCIDENT-003](incidents/INCIDENT-003-worker-fails-after-approval.md), for how failure is rehearsed.
4. One test file, say `api/tests/Feature/Federation/Http/ApplicationFieldsPatchHttpTest.php`, for how behaviour is pinned.
5. The [threat model](THREAT_MODEL.md) legend, for how "partly" and "upstream" are used instead of "done".

## How this was built

The owner directed every milestone (its scope, its opening decision among alternatives, and what was deferred) and reviewed the output; an AI coding assistant wrote most of the code and documentation under those decisions, with the constraints recorded in the roadmap: nothing fabricated, synthetic data only, no cost without approval, evidence retained for every number. The learning record (lessons, quizzes, exercises, interview questions per milestone) is kept outside the repository and reviewed at the end.
