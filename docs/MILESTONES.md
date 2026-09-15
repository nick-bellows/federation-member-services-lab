# Milestones

The full detail behind the milestone table in the [README](https://github.com/nick-bellows/federation-member-services-lab#what-the-fork-added-in-order): one heading per milestone, what exists, and the evidence with every retained run. The README keeps one line per milestone; nothing was dropped when it was shortened on 2026-09-15, it moved here. Retained runs live under [`docs/baseline/`](baseline/); decisions under [`docs/adr/`](adr/); the order and the open items in [`ROADMAP.md`](https://github.com/nick-bellows/federation-member-services-lab/blob/main/ROADMAP.md).

## M0 Archaeology

The upstream system running unchanged, its request path traced end to end, its tests, gaps and MySQL-only SQL catalogued.

Evidence: [`docs/UPSTREAM_ANALYSIS.md`](UPSTREAM_ANALYSIS.md), [`docs/baseline/`](baseline/).

## M1 Baseline quality

One behaviour fix with a fail-then-pass regression test (`env()` read under cached configuration returned the upstream production domain), line-ending safety, the CI workflow (five jobs then, nine now), the test-tool decision.

Evidence: [ADR-0002](adr/0002-runtime-settings-through-config-not-env.md), [`docs/baseline/env_bug_before_fix.txt`](baseline/env_bug_before_fix.txt).

## M2 Federation domain

Federation → member organization → club → member; a seven-state application lifecycle whose only writer is one transition service; an append-only audit trail; duplicate protection at two layers.

Evidence: [`docs/DOMAIN_MODEL.md`](DOMAIN_MODEL.md), [ADR-0005](adr/0005-federation-hierarchy-above-upstream-clubs.md), [ADR-0006](adr/0006-application-state-machine-and-audit-trail.md).

## M3 Identity

OpenID Connect sign-in (authorization code + PKCE) with the access token kept server-side; Laravel validates tokens against the issuer's keys and derives capabilities from the database, never from claims; a mock provider in compose, Auth0 when configured.

Evidence: [ADR-0007](adr/0007-oidc-identity-boundary.md), [INCIDENT-000](incidents/INCIDENT-000-dev-database-wiped-by-config-cache.md).

## M4 Review slice

Registration windows, applications with details and document metadata, a second JSON:API server with generated TypeScript types, idempotent submission, correlation ids on every audit entry, member and reviewer pages, browser tests with accessibility scans.

Evidence: [ADR-0008](adr/0008-document-metadata-without-file-storage.md), [INCIDENT-002](incidents/INCIDENT-002-duplicate-submission.md), [`docs/assets/`](assets/).

## M5 Learning Center contract

A versioned credentials contract keyed by OIDC subject, executable as fixtures on both sides and served by a mock in Compose; the federation calls with its own client-credentials service token; participation derived on read from a stored snapshot with its age, refreshed after approval, on a reviewer's request and by reconciliation; Incident 1 rehearsed against a slowed provider.

Evidence: [`docs/contracts/learning-center-credentials-v1.md`](contracts/learning-center-credentials-v1.md), [ADR-0009](adr/0009-learning-center-credentials-contract.md), [INCIDENT-001](incidents/INCIDENT-001-slow-credential-service.md), `docs/baseline/incident_001_2026-09-03.txt`, [learning-center-reference PR #1](https://github.com/nick-bellows/learning-center-reference/pull/1).

## M6 Events and reliability

A transactional outbox written with every state change, a relay onto Laravel's database queue with one job per event and consumer, a processed-events ledger that makes at-least-once delivery act once, retries with backoff and a parked state an operator can read and replay; two consumers (notification rows, the credential refresh after approval); Incident 3 rehearsed (the worker fails after an approval); the broker mapping documented, none provisioned.

Evidence: [ADR-0010](adr/0010-transactional-outbox-and-consumers.md), [INCIDENT-003](incidents/INCIDENT-003-worker-fails-after-approval.md), `docs/baseline/incident_003_2026-09-03.txt`.

## M7 PostgreSQL

A three-engine compatibility matrix in CI (SQLite, MariaDB, PostgreSQL 16, the last one with the demo seeder); upstream's MySQL-only SQL made portable in place with a regression test (export ordering, a fee cast, a migration literal); engine differences recorded, none hidden; the driver in both images and an optional PostgreSQL service in Compose.

Evidence: [`docs/DATABASE_COMPATIBILITY.md`](DATABASE_COMPATIBILITY.md), [ADR-0011](adr/0011-postgresql-compatibility-matrix.md).

## M8 Operability

JSON logs on stderr with request, user, trace and span ids and one access line per request; OpenTelemetry traces to a local Jaeger from the request through the outbox worker to the Learning Center call; liveness, readiness and metrics endpoints, and upstream's nine health checks routed; a runbook proved by re-running the three incidents against the signals.

Evidence: [`docs/OBSERVABILITY.md`](OBSERVABILITY.md), [`docs/RUNBOOK.md`](RUNBOOK.md), [ADR-0012](adr/0012-observability.md), `docs/baseline/operability_2026-09-03.txt`.

## M9 Accessibility and performance

A manual WCAG 2.1 AA review of the seven slice pages with a keyboard walk and focus-order record, three deferred improvements and no AA failure; a slow-3G pass with the production bundle sizes; synthetic load on three endpoints with k6 before and after two measured fixes: five missing indexes on upstream's tenant tables (full scans of 32,000 rows became index lookups) and a listing that ran 89 queries per page and now runs 11.

Evidence: [`docs/PERFORMANCE.md`](PERFORMANCE.md), [`docs/ACCESSIBILITY.md`](ACCESSIBILITY.md), [ADR-0013](adr/0013-accessibility-and-performance-evidence.md), `docs/baseline/perf_*`, `docs/baseline/a11y_review_2026-09-03.txt`.

## B7 Security review

A threat model as six attack trees (alter an application, read another organization, obtain or forge a token, disrupt the queue or provider, learn from public surfaces, supply chain), every leaf tied to a control and its test or to a recorded gap; RFC 6902 JSON Patch on applications with field-level authorization, where every operation is authorised before any is applied and one refusal refuses the patch; reviewer notes the applicant never sees; the checks and metrics endpoints behind a scrape token by default; a test that no log line or span carries a token; the dependency audits re-run and an update policy written, nothing patched yet.

Evidence: [`docs/THREAT_MODEL.md`](THREAT_MODEL.md), [ADR-0014](adr/0014-security-review.md), `docs/baseline/security_audit_2026-09-03.txt`, `docs/baseline/security_review_2026-09-03.txt`.

## M10 Release engineering

Release images for the API and the web app (dependencies and assets built in stages, no toolchain, no environment file, a health check); a release entrypoint that migrates only as a one-off task; the worker and the scheduler as services with the schedule registered in code and tested; a release rehearsal in Compose with no bind mount; a deployment architecture on managed services, labelled planned and provisioned nowhere; a release checklist and rollback plan; a dependency audit job in CI and the within-major fixes applied.

Evidence: [`docs/DEPLOYMENT.md`](DEPLOYMENT.md), [`docs/RELEASE.md`](RELEASE.md), [ADR-0015](adr/0015-release-engineering.md), [`deploy/compose.release.yml`](https://github.com/nick-bellows/federation-member-services-lab/blob/main/deploy/compose.release.yml), `docs/baseline/release_rehearsal_2026-09-04.txt`, `docs/baseline/security_audit_after_b8_2026-09-04.txt`.

## M11 Case study and demo

A case study that argues from the inherited system through the milestones to what was measured and what was not done; a demo recorded from a browser spec against the release images; the three accessibility improvements deferred since M9 in place and asserted; the interview guide complete; the upstream offer drafted, not sent; the Auth0 walkthrough written for a tenant that does not exist yet.

Evidence: [`docs/CASE_STUDY.md`](CASE_STUDY.md), [`docs/assets/demo.webm`](assets/demo.webm), [`docs/UPSTREAM_OFFER.md`](UPSTREAM_OFFER.md), [`docs/AUTH0_WALKTHROUGH.md`](AUTH0_WALKTHROUGH.md), `docs/baseline/a11y_review_2026-09-04.txt`.

## Phase C Closing (2026-09-10)

Two external reviews of the B9 state found four defects the suite had never caught (an idempotency key without its owner, a Learning Center answer never compared with the subject asked for, a window whose season could belong to another federation, three writes that authorised before they locked); each is fixed with a regression test that fails before and passes after; the release proof routes the identity callback ahead of the API rule and asserts the order, encrypts the database and runs the API image as the application user; a secret scan over the full history fails the build; every number a reviewer meets made current and pinned to a commit, the documentation site serving its evidence; a final verification of the closing state: the whole suite, a cold clone of the run instructions below from the public fork, the site checked clean.

Evidence: [ADR-0016](adr/0016-boundary-rules-from-the-external-reviews.md), `docs/baseline/c1_tests_before.txt` and `c1_tests_after.txt`, `release_rehearsal_2026-09-10.txt`, `terraform_validate_2026-09-10.txt`, `gitleaks_2026-09-10.txt`, `phpunit_after_c_backend.txt`, `cold_clone_2026-09-10.txt`, `pages_site_2026-09-10.txt`.

## Closing review (2026-09-12)

Four independent reviews of the closing tree, each finding verified in the source: the transition actions authorise before the domain speaks (a stranger could read any application's status from the transition messages), an unreadable credential snapshot is reported instead of failing every page, a rejected service token leaves the cache, a duplicate window is a 409, the member pages throw to an error boundary instead of rendering empty lists when the API refuses, the admin gate refuses an OIDC session, every GitHub Action pinned to a commit, the documents corrected where they had drifted.

Evidence: [`docs/THREAT_MODEL.md`](THREAT_MODEL.md) (2.9), `docs/baseline/d1_tests_before.txt` and `d1_tests_after.txt`, `phpunit_after_d1_backend.txt`.

## Roadmap history

The lifecycle label is `VALIDATED` in CI, not deployed. How the project reached its closing state, phase by phase:

- **Phase A complete (2026-09-03):** M0 to M4 (archaeology, baseline fix, domain and state machine, OIDC identity, registration-review slice with browser tests), README, cold clone, public fork with CI green on `main`.
- **Phase B:** B2 Learning Center contract (ADR-0009, INCIDENT-001), B3 events and reliability (ADR-0010, INCIDENT-003), B4 PostgreSQL (ADR-0011, compatibility matrix), B5 operability (ADR-0012, runbook), B6 accessibility and performance (ADR-0013) and B7 security review (ADR-0014, [`docs/THREAT_MODEL.md`](THREAT_MODEL.md)) done 2026-09-03; B8 release engineering (ADR-0015, design only, release rehearsal) and B9 case study and demo done 2026-09-04 for everything that needed no external input.
- **Decided 2026-09-04:** provisioning approved (Terraform proof written and validated; waits for AWS credentials), GitHub Pages approved (live at https://nick-bellows.github.io/federation-member-services-lab/), the Auth0 tenant still to be created (walkthrough ready), the upstream offer moved to the last item and still unsent (decision 7).
- **Phase C planned 2026-09-04 from the external reviews:** C1 to C5 in the repository, O1 to O6 each needing an external input. C1 done 2026-09-10 (ADR-0016; ten regression tests retained failing in [`docs/baseline/c1_tests_before.txt`](baseline/c1_tests_before.txt) and passing in [`c1_tests_after.txt`](baseline/c1_tests_after.txt); the flaky upstream consent test that failed the #14 merge fixed; offer items 5 and 6 added), merged as `9fdbc92`. C3 done the same day (the `secret-scan` job, one fingerprint in `.gitleaksignore`), merged as `98bc84b`. C2 done the same day (the identity-callback rule with its precondition, the database encrypted, the API image rootless on 8080; `terraform validate` and the release rehearsal re-recorded), merged as `169b7dd`. C4 done the same day (every reviewer-facing number current and pinned to a commit, the documentation site serving the evidence with the demo embedded, its axe findings fixed, the screenshots recaptured, future work reduced), merged as `f1f3397`, with the site's last axe finding fixed in #19 (`bffdecd`). C5 done the same day: the whole suite on the closing tree ([`phpunit_after_c_backend.txt`](baseline/phpunit_after_c_backend.txt), 247 passed), the release rehearsal of C2, a cold clone of the README from the public fork ([`cold_clone_2026-09-10.txt`](baseline/cold_clone_2026-09-10.txt): 882 s from nothing to ten passing journeys, torn down), the documentation site checked clean ([`pages_site_2026-09-10.txt`](baseline/pages_site_2026-09-10.txt)), the roadmap, the learning log, the interview guide and the internal record brought to the closing state. Phase C is closed; nothing more is planned for this repository.
- **Closing review D1 (2026-09-12):** four independent reviews of the closing tree, each finding verified in the source and fixed with a regression test ([`d1_tests_before.txt`](baseline/d1_tests_before.txt), [`d1_tests_after.txt`](baseline/d1_tests_after.txt); the suite is 251 tests since, [`phpunit_after_d1_backend.txt`](baseline/phpunit_after_d1_backend.txt)).
- **Open:** O1 to O6 (and the O7 dependency-bot decision), each waiting on an external input, listed in `ROADMAP.md`.
