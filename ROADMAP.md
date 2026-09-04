# Roadmap

Last verified: 2026-09-03. Supersedes the 2026-09-02 versions. Governed by the workspace `AGENTS.md` and the central portfolio roadmap; where they conflict, the central roadmap wins.

## Handoff snapshot

| Field | Current state |
| --- | --- |
| Lifecycle | `VALIDATED` in CI, not deployed — Phase A complete: M0 to M4 (archaeology, baseline fix, domain and state machine, OIDC identity, registration-review slice with browser tests), README, cold clone, public fork with CI green on `main` (2026-09-03). Phase B: B2 Learning Center contract (ADR-0009, INCIDENT-001) and B3 events and reliability (ADR-0010, INCIDENT-003) and B4 PostgreSQL (ADR-0011, compatibility matrix) and B5 operability (ADR-0012, runbook) and B6 accessibility and performance (ADR-0013) and B7 security review (ADR-0014, `docs/THREAT_MODEL.md`) done 2026-09-03; B8 release engineering (ADR-0015, design only, release rehearsal) and B9 case study and demo done 2026-09-04 for everything that does not need the owner. **Open, on the owner:** provisioning (money), the upstream offer (decision 7), the Auth0 tenant, a hosted case study or demo, upstream's release workflows |
| Branch | `main` of the fork, merged through pull requests (#1 Phase A, #2 to #4 docs, #5 B2, #6 B3, #7 B4, #8 B5, #9 B6, #10 B7); 50+ commits ahead of upstream `main` (`dca9be3`) |
| Remotes | `origin` = nick-bellows/federation-member-services-lab (public fork, created 2026-09-03 on the owner's instruction); `upstream` = vereinfacht/vereinfacht (read-only) |
| Runs locally | Yes: compose stack `vereinfacht` (MariaDB 11.8, Laravel 13 API, Swagger, mock OIDC provider, tooling with `next dev`). Setup and deviations in `docs/UPSTREAM_ANALYSIS.md` §11; seed with `NorthgateDemoSeeder` |
| Upstream baseline | PHPUnit 91/91 (338 assertions, 58 s, SQLite), now 95/95 with the fork's tests; Pint 215 issues; `tsc` 0 errors; ESLint 71 warnings; no frontend or E2E tests; upstream has no CI that runs tests, the fork's workflow runs six jobs on GitHub (green on `main` since 2026-09-03) |
| Original product claim | One complete registration-review workflow: windows, applications with details and document metadata, review with decisions and reasons, audit history, over an OIDC-authenticated JSON:API and accessible member and reviewer pages, plus participation derived from the Learning Center credentials contract (B2); verified by PHPUnit and Playwright in compose and in CI |
| Purpose in the application | Evidence of **existing-system engineering**: reading, testing and extending a Laravel/Next.js codebase without a rewrite. The sibling `learning-center-reference` remains the flagship |

Start with `docs/UPSTREAM_ANALYSIS.md`, then `docs/adr/`. The sibling Learning Center owns education, certification and safeguarding-derived eligibility. This repository owns organizations, membership, registration applications, document review and audit, and consumes credentials over an HTTP contract only. The two never share a database.

## The goal this roadmap serves

Get the repository in front of a technical recruiter and the federation's engineers as early as it can be **credible**, then deepen it. Credible means: a reviewer can tell in one minute what is upstream and what was added, in five minutes see one real feature with tests and a decision record, and at any depth find nothing claimed that is not implemented.

That fixes the order. Everything that makes the repository readable and truthful comes first; everything that makes it impressive at depth comes after the public gate, one bounded milestone at a time.

Sizes below are planning estimates, not commitments: **S** = one working session, **M** = two to three, **L** = four or more.

## Phase A — reach the public gate

Public visibility was allowed only when A1–A5 were done and the acceptance checklist at the end of Phase A passed. The fork was created on the owner's explicit instruction on 2026-09-03 (`nick-bellows/federation-member-services-lab`, `origin`; `upstream` kept); Phase A merged through pull request #1.

### A1 — Baseline quality and one defensible fix (M1) — size M

Goal: prove the inherited system can be tested and improved safely before any federation code exists.

1. CI workflow in the fork: PHPUnit on SQLite **and** on MariaDB (so MySQL-only SQL is exercised on the real engine), Pint, `tsc --noEmit`, ESLint, `next build`. Watch each gate fail once before trusting it.
2. One bounded fix from `docs/UPSTREAM_ANALYSIS.md` §8, with a regression test that fails before and passes after. Candidates, in recommended order: (a) `env()` calls outside `config/` that return production defaults once configuration is cached; (b) the development entrypoint's config-cache trap.
3. `.gitattributes` with LF normalisation, so Windows checkouts do not break the API container.
4. Decide and record the end-to-end tool for the fork's critical path (recommendation: Playwright, because upstream's Cypress project never existed, Playwright carries axe accessibility checks well, and the Learning Center already uses it).
5. Record in an ADR whether the fix and the CI workflow will be offered upstream. Offer at most one, after reading the issue thread; never imply acceptance.

Acceptance: CI green in the fork on the milestone branch; the fix explained line by line in `docs/LEARNING_LOG.md`; no unrelated upstream behaviour changed.

**Status 2026-09-02: done except the CI run.** Fix landed with fail-then-pass evidence (ADR-0002, `docs/baseline/env_*`), full suite 95/95 on SQLite, regression tests also pass on MariaDB; `.gitattributes` in place; workflow written and its commands validated locally but **DRAFT until it runs on GitHub**, which requires the fork (A5). Playwright recorded in ADR-0003; upstream offer policy in ADR-0004. CI ran on GitHub on 2026-09-03 (A5). The upstream offer is deferred to B9 (decision 7).

### A2 — Federation domain and the application state machine (M2) — size L

Goal: the one high-value backend feature a senior reviewer opens first.

1. Model Federation → Member Organization → Club → Member, plus member roles (participant, coach, referee, club admin, organization admin, federation admin). Keep upstream's `clubs` and `members` tables as the club and member; add the two levels above them rather than replacing anything.
2. `registration_applications` with explicit states `DRAFT`, `SUBMITTED`, `UNDER_REVIEW`, `NEEDS_INFORMATION`, `APPROVED`, `REJECTED`, `CANCELLED`; transitions governed by a single transition table in code, checked by the model, never set by controllers; every transition writes an audit row with actor, action, previous and new state, reason, timestamp and request id.
3. Proper foreign keys and indexes on every new table, and an ADR explaining why the upstream tables are left as they are for now.
4. Duplicate-submission protection at the database (unique constraint per member, organization and season) and at the API (idempotency key on submit). This is Incident 2 from the brief, done as a feature with its regression test.
5. Domain unit tests for every legal and illegal transition; feature tests for authorization of each transition by role.

Acceptance: state machine and audit trail covered by tests; `docs/DOMAIN_MODEL.md` and `docs/DATABASE_BASELINE.md` completed; ADRs for the hierarchy attachment and the state machine written.

**Status 2026-09-02: done.** `App\Federation` namespace with enums, models, transition table, `StartApplication` and `TransitionApplication`, actor resolver, audit recorder, event; eight migrations with full referential integrity; two nullable columns on upstream tables; `NorthgateDemoSeeder`; 30 federation tests (all 49 transition pairs pinned, actors, reasons, audit, duplicates at both layers, idempotency, hierarchy, schema identifier lengths); ADR-0005 and ADR-0006; `docs/DOMAIN_MODEL.md`. Verified on the development MariaDB (`docs/baseline/northgate_seed_rows.txt`). The HTTP idempotency header and request-id plumbing belong to A4. Derived participation status stays planned for the Learning Center milestone.

### A3 — Identity boundary (M3) — size L

Goal: separate "who are you" from "what may you do" before the federation workflow exists, so the review slice is built on the identity model it will keep.

1. OIDC-compatible login for the new member and reviewer surfaces: authorization-code flow with PKCE in the Next.js app, ID token for the session, access token validated by Laravel (issuer, audience, signature via JWKS, expiry). No custom cryptography; a maintained JWT library.
2. Provider: an **Auth0 free-tier tenant** created by the owner for the documented walkthrough and screenshots; a **self-hosted OIDC provider in compose and CI** so tests never depend on an external account. The validation code is provider-agnostic.
3. Authorization stays in the application: roles and scopes such as `member:read:self`, `application:create`, `application:review`, `organization:manage` resolved from the database, never trusted from token claims alone; policies extended for the federation roles.
4. Upstream's Sanctum login for club admins and the Filament session keep working unchanged; the new boundary is additive.
5. Adapter tests against fixture tokens and the self-hosted provider; privilege-boundary tests for every role pair; ADR-0005 (identity provider separated from application authorization).

Acceptance: a member can sign in through OIDC in compose and in CI; token validation failures are tested; no upstream login path regressed.

**Status 2026-09-02: done in compose; CI job written, not run.** `oidc` guard on `firebase/php-jwt` 7.1 with JWKS discovery, cache and rotation; subject-to-user resolution with verified-e-mail linking and optional provisioning; scopes from database roles; `GET /api/v1/federation-identity/me`; next-auth providers for the mock issuer and Auth0 with the access token server-side only; `/member` pages; `mock-oauth2-server` in compose with personas. 20 PHPUnit tests, 3 Playwright tests with axe (`docs/baseline/playwright_m3.txt`), full suite 147/147. ADR-0007. INCIDENT-000 (development database wiped by the config-cache trap) fixed permanently in `phpunit.xml` with a regression test. CI green on GitHub since 2026-09-03. Open: the Auth0 tenant walkthrough (deferred to B9), refresh-token handling.

### A4 — One complete registration-review slice (M4 in the brief) — size L

Goal: the workflow a product reviewer can follow end to end.

```text
organization admin opens a registration window
→ participant creates a profile and starts an application
→ participant provides required information and document metadata (synthetic)
→ participant submits
→ reviewer sees the queue, opens the application, requests information or approves
→ status is derived and shown to the participant with an explanation
→ audit history visible to authorized roles
```

1. Backend: JSON:API resources for applications, requirements and document metadata in the upstream style (schemas, requests, policies), reusing upstream's tenancy pattern and extending it with the organization level.
2. Frontend: member pages (start, fill, submit, status) and administrator pages (queue, detail, decision, history) in the upstream Next.js conventions, with server actions and Zod schemas; keyboard-navigable, labelled forms, error announcements.
3. Authentication uses the OIDC boundary from A3 for the member and reviewer surfaces; upstream's Sanctum flows for club admins keep working unchanged.
4. Playwright critical-path test for the whole slice; axe checks on every new page.
5. Screenshots and a short GIF of the slice, generated from the running application and checked into `docs/assets/`.

Acceptance: the slice runs from a cold clone by following the README; E2E and accessibility checks green in CI; `docs/incidents/INCIDENT-002.md` (duplicate submission) written from the actual regression test.

**Status 2026-09-02: done in compose; CI unrun; README rewrite pending (A5).** Backend: registration windows, application details, document metadata with required types per role, second JSON:API server `federation` (seven schemas, six transition actions, stable error codes, request ids, HTTP idempotency), `php artisan federation:openapi`. Frontend: generated typed client, server actions, member pages (list, start, detail with local file hashing, submit and withdraw, history), reviewer pages (queue, detail with document decisions), window page, navigation by capability, en and de. Tests: 168 PHPUnit (19 HTTP tests for the slice), 7 Playwright journeys with axe (`docs/baseline/playwright_m4.txt`), screenshots in `docs/assets/`. ADR-0008, INCIDENT-002. CI green on GitHub since 2026-09-03. Open: a GIF or short demo. Cold-clone verification done under A5.

### A5 — Publication (M11 brought forward) — size M

1. README rewritten for the five-minute path: what upstream is and what was added, the modernization problem, architecture diagram (before and after), the one feature to open, the tests to open, the ADRs, the incident report, the upstream contribution status, explicit attribution, test instructions.
2. Upstream-versus-fork manifest generated in CI and linked from the README.
3. Publish pre-flight per the portfolio house rules: clean tree, tracked-file audit, gitleaks over history, no seeded credentials described as production-safe, logged-out README review.
4. **Only on explicit approval:** create the GitHub fork, add `origin`, push, merge the milestone branches through pull requests, enable Actions. Pins and profile changes are separate manual approvals.

**Status 2026-09-02: README rewritten for the five-minute path; upstream's README preserved at `docs/UPSTREAM_README.md`; cold-clone verification done.** The README instructions were followed from a fresh clone in a separate Compose project with fresh volumes (`docs/baseline/cold_clone_2026-09-02.txt`, lesson in `docs/LEARNING_LOG.md`). The first run found a React hydration defect in date formatting (server in UTC, browser in the local zone); it is fixed, guarded by the browser spec, and the rerun passed 7 of 7. Pre-flight run once on the earlier tree (clean tree, tracked-file audit, gitleaks over 24 commits: nothing found) and to be repeated on the final commit. **2026-09-03: fork created on the owner's word, branch pushed, Actions enabled, pull request #1 opened.** The first two CI runs failed on three findings (an engine assumption in the INCIDENT-000 test; the runner's browser could not resolve the OIDC issuer host; a migration race between the API container's start-up and the seed, which hung one run for 45 minutes); fixed in three commits, green on both runs of `99aca82`, merged as `0bb07f3`, green on `main`. Lesson in `docs/LEARNING_LOG.md`. Anonymous README view checked (fork banner, attribution, disclaimer; no secrets; nothing claimed). Open: a short demo. The upstream offer is deferred to B9 (decision 7, 2026-09-03).

### Phase A acceptance checklist

- [x] CI green on `main` of the fork for backend, frontend, E2E and accessibility jobs (2026-09-03, merge commit `0bb07f3`)
- [x] Cold-clone verification recorded in `docs/LEARNING_LOG.md` (2026-09-02; log in `docs/baseline/cold_clone_2026-09-02.txt`)
- [x] One feature (state machine + review slice) with unit, feature, authorization and E2E tests
- [ ] OIDC sign-in working against the self-hosted provider in CI (done) and against the Auth0 tenant in the documented walkthrough (deferred to B9 by owner decision, 2026-09-02); privilege-boundary tests (done)
- [x] ADR-0000 to ADR-0005 (through ADR-0008), `docs/DOMAIN_MODEL.md`, `docs/DATABASE_BASELINE.md`, `docs/incidents/INCIDENT-002.md`
- [ ] README passes the one-minute and five-minute reads (owner's read pending; anonymous view checked 2026-09-03); attribution and license unchanged (verified)
- [ ] One upstream contribution offered, status recorded truthfully — **deferred to B9 by owner decision, 2026-09-03 (decision 7).** Nothing has been offered. The offer is made once, at the end of Phase B, with the best candidate then: the `env()` fix with `.gitattributes` (ADR-0004), and possibly the PostgreSQL compatibility work from B4. The gate stays open until then; it is not waived.
- [x] Explicit approval for visibility received (the owner's "Proceed with the fork", 2026-09-03; the fork has been public since; recorded here 2026-09-04)

## Phase B — depth, after the public gate

Each milestone is bounded, starts with its lesson and decision, and updates this roadmap on completion. Order is a recommendation; each is independently valuable.

| Milestone | Delivers | Size | Notes |
| --- | --- | --- | --- |
| B1 Identity boundary (M3) | Moved to Phase A as A3 by decision 1 on 2026-09-02 | — | — |
| B2 Learning Center contract (M5) | `GET /v1/members/{id}/credentials` contract with fixtures and a mock server; derived participation status from approval + credentials + holds; timeout, stale-data and reconciliation behaviour; Incident 1 (slow credential service) | M | **Done 2026-09-03.** Decisions at the start: subject-keyed contract, client-credentials service token, snapshot with age (ADR-0009). Provider side merged in `learning-center-reference` pull request #1 (CI green). Consumer: contract doc and fixtures, mock in Compose, snapshots, participation attribute, reviewer refresh, reconciliation command, 34 new tests, browser journeys extended, INCIDENT-001 rehearsed (`docs/baseline/incident_001_2026-09-03.txt`). Open: scheduling the reconciliation (B5), retry with jitter (B3). |
| B3 Events and reliability (M6) | Transactional outbox, worker, retries, idempotent consumers; domain events for submit, approve, reject, credential change; Incident 3 (worker fails after approval) | M | **Done 2026-09-03.** Decisions at the start: outbox plus the database queue, a processed-events ledger per consumer, four events and two consumers (ADR-0010 with the SQS, RabbitMQ and Kafka mapping). `outbox_events`, `processed_events`, `federation_notifications`; relay, worker loop, status and replay commands; the credential refresh moved from a synchronous listener into a consumer; INCIDENT-003 rehearsed (`docs/baseline/incident_003_2026-09-03.txt`); 8 new tests, 210 in total. Found on the way: an M3 provisioning race under parallel requests, fixed. Open: scheduling and alerting (B5). |
| B4 PostgreSQL (M7) | CI matrix on MariaDB and PostgreSQL; the MySQL-only constructs fixed or isolated; differences documented, none hidden | M | **Done 2026-09-03.** Decisions at the start: compatibility matrix, fix in place where cheap (ADR-0011). `backend-postgres` CI job (suite + seeder), `pdo_pgsql` in both images, optional `postgres` Compose service; `FIELD()` in seven exports → `App\Support\OrderByIdList` with a test, `CAST … AS UNSIGNED` → `INTEGER`, a double-quoted migration literal → the builder; `docs/DATABASE_COMPATIBILITY.md`. PostgreSQL run: CI job `backend-postgres` green on pull request #7 (212 tests and the seeder, about four minutes); the first run found three upstream tests comparing JSON columns as text, fixed in place. The portability fixes join the upstream offer at B9 (decision 7). |
| B5 Operability (M8) | Structured logs with correlation ids, health and readiness endpoints, basic metrics, OpenTelemetry traces to a local Jaeger, `docs/OBSERVABILITY.md`, `docs/RUNBOOK.md`; the three incident exercises run against it | M | **Done 2026-09-03.** Decisions at the start: OpenTelemetry to a local Jaeger, JSON logs to stderr with context, live/ready/metrics endpoints (ADR-0012). `TraceRequest` (server span, shared log context, one access line per request), spans around the transition, each outbox job (continuing the request's trace via `traceparent` on the row) and the provider call (header propagated); `json` log channel with a trace-id processor, PHP-FPM forwarding worker output; `/api/health/live`, `/api/health/ready` (database, outbox age; Learning Center reported, never required), `/api/health/checks` (upstream's spatie checks, result store moved from memory to the file cache), `/api/metrics`; `docs/OBSERVABILITY.md`, `docs/RUNBOOK.md`; the three incidents re-run against the signals (`docs/baseline/operability_2026-09-03.txt`); 10 new tests, 220 in total. Open: scheduling and alert delivery (B8). |
| B6 Accessibility and performance (M9) | Manual WCAG 2.1 AA review of the slice, fixes, low-bandwidth review of the member flow, synthetic load on three endpoints with before/after measurements | M | **Done 2026-09-03.** Decisions at the start: k6 in Docker, single-column indexes measured, a manual review of the slice (ADR-0013). Five indexes on upstream's tenant tables (`perf_explain_before/after`: full scans of 10,613 and 32,020 rows became index lookups of 510, 1 and 1,510), the memberships listing eager-loaded (89 → 11 queries per page, guarded), k6 before and after (listing p95 663 → 482 → 333 ms, `docs/PERFORMANCE.md`); `docs/ACCESSIBILITY.md` with the keyboard walk and best-practice scan clean on all seven pages, three improvements deferred to B9; the slow-3G pass recorded with the production bundle sizes. The rate limit became configuration for measurement windows. 222 tests. |
| B7 Security review | `docs/THREAT_MODEL.md` covering the brief's list; JSON Patch on one resource with field-level authorization | S–M | **Done 2026-09-03.** Decisions at the start: attack trees rather than a STRIDE table, a dedicated `-actions/fields` route for RFC 6902 rather than overloading the JSON:API update, the scrape token on by default for checks and metrics (ADR-0014). `docs/THREAT_MODEL.md`: six trees (alter an application, read another organization, obtain or forge a token, disrupt the queue or provider, learn from public surfaces, supply chain) with every leaf tied to a control and its test or to a recorded gap; the dependency audits re-run (13 Composer advisories in three upstream packages, 8 npm advisories, none patched here, an update policy for B8, `docs/baseline/security_audit_2026-09-03.txt`). Code: `JsonPatch` parser, `PatchApplicationFields` (authorise every operation before applying any; one refusal refuses the patch; `test` guards a stale view; one audit entry with previous and new values), `reviewer_notes` visible to reviewers only, `/api/health/checks` behind the token with `METRICS_TOKEN` shipped in `.env.example`, `SecretsNeverLoggedTest` over logs and spans; OpenAPI and generated types regenerated. 14 new tests, 236 in total. Nothing patched in the lock files: the policy says what B8 takes. |
| B8 Release engineering (M10) | Production images, deployment architecture document (CloudFront, load balancer, containers, RDS PostgreSQL, S3, queue, worker, CloudWatch), rollback plan, release checklist; Terraform only if labelled untested | M | **Done 2026-09-04 (design only; provisioning on the approvals list).** Owner's instruction 2026-09-04: B8 and B9 without a pause for decisions, approvals collected at the end; the opening decision defaulted to design-only (ADR-0015). Release images for the API (`docker/api/api.release.Dockerfile`: dependencies and admin assets in stages, no toolchain, no `.env`, a health check) and the web app (upstream's standalone image with an ignore list that keeps `.env.local` out); a release entrypoint that waits for the configured database through PDO and migrates only as a one-off task; the worker and the scheduler as services, the federation schedule registered in code with failure hooks and a test; `deploy/compose.release.yml` rehearsed (`docs/baseline/release_rehearsal_2026-09-04.txt`); `docs/DEPLOYMENT.md` (planned, priced at nothing), `docs/RELEASE.md` (checklist, rollback, the migrations that cannot be reversed without loss); a report-only `dependency-audit` CI job; the within-major fixes applied (Composer 13 → 0, npm 8 → 4 frontend, 1 → 0 API tooling; `security_audit_after_b8_2026-09-04.txt`). No Terraform: untested modules would be a claim without evidence. Kubernetes not attempted. |
| B9 Case study and demo | Static modernization case study page, demo video, final README pass, interview guide complete; **the one upstream offer per ADR-0004 (decision 7, 2026-09-03)**; **the Auth0 tenant walkthrough with screenshots (owner decision 2026-09-02: deferred to the very end; the mock provider covers development and CI until then)** | S–M | **Done 2026-09-04 for everything that does not need the owner; the rest is on the approvals list.** `docs/CASE_STUDY.md` (the argument from the inherited system through eleven milestones, every number linked to a retained run, a "what was not done" section); `docs/assets/demo.webm` recorded by `e2e/tests/demo.spec.ts` against the release images (29 s, reproducible, skipped in CI); the three accessibility improvements deferred since B6 in place and asserted by the review spec (`docs/baseline/a11y_review_2026-09-04.txt`); the interview guide complete through B9; the README's final pass. **Not done, by the rules:** the upstream offer is drafted as one issue in `docs/UPSTREAM_OFFER.md` and not sent; `docs/AUTH0_WALKTHROUGH.md` is written and planned until the owner's tenant exists; a hosted case study page (GitHub Pages) and a hosted demo are cost or visibility decisions. |

## Continuous tracks

- **Upstream contributions:** one at a time, generic only, after reading the issue and asking the maintainers. Queue from `docs/UPSTREAM_ANALYSIS.md` §10: CI workflow (#7), locale header matching (#125), `.gitattributes`, transaction around the apply action, configurable demo-club check plus Mailpit.
- **Teaching record:** every milestone opens with its lesson and closes with quiz, exercises and interview questions recorded in the internal review file kept outside this repository; `docs/LEARNING_LOG.md` records what was done and observed; `docs/INTERVIEW_GUIDE.md` grows one section per milestone.
- **Evidence discipline:** every number in the docs comes from a retained run under `docs/baseline/` or a CI artifact; lifecycle vocabulary `draft / planned / validated`; "CI" until an automated deployment exists.

## What a reviewer sees at each gate

| When | Recruiter, one minute | Engineer, five minutes | Product reviewer |
| --- | --- | --- | --- |
| After Phase A | Fork of a real open-source system, modernized; Laravel, Next.js, TypeScript, Auth0/OIDC, PostgreSQL planned, CI badges backed by real jobs; screenshots of the review slice | State machine with transition tests, audit trail, idempotent submit, token validation and privilege-boundary tests, ADRs, one incident report, the upstream PR | A registration flow with sign-in, roles, review, explanations and an audit history |
| After B2–B3 | Integration contract, event-driven processing | Contract tests with a mock, outbox with failure exercises, three incident reports | Degraded behaviour when the credential service is slow; nothing lost when a worker dies |
| After B4–B9 | PostgreSQL, observability, accessibility, deployment architecture | Traces, metrics, load measurements, threat model, runbook | Accessibility review, performance budgets, case study |

## Decisions taken (owner's review, 2026-09-02 onward)

1. **Sequencing: identity before the review slice**, as in the original brief. The public gate moves one milestone later; the first public state shows OIDC.
2. **M1 fix: the `env()` calls outside `config/`**, with a regression test that fails before and passes after.
3. **E2E tool: Playwright.**
4. **Fictional federation: Northgate Soccer Federation (NSF).** Name and any branding are invented for this project and must not resemble a real federation's marks.
5. **Identity provider: Auth0 free tier**, tenant created by the owner when A3 starts; a self-hosted OIDC provider in compose and CI so nothing depends on the external account.
6. **Learning Center contract: add `GET /v1/members/{id}/credentials` to the Learning Center** as a bounded milestone there; this repository consumes it through fixtures and contract tests first.
7. **Upstream offer timing (2026-09-03): one offer, at the end of Phase B (B9).** The owner prefers to offer when the fork has more to give than a two-line fix; nothing is offered piecemeal. Candidates then: the `env()` fix with `.gitattributes` (ADR-0004) and, if B4 produces it, the PostgreSQL compatibility work. The README says "none offered" until that happens.
8. **B7 security review (2026-09-03): attack trees, a dedicated JSON Patch route, the scrape token on by default.** The threat model is written as attack trees per attacker goal with STRIDE as the checklist, not as a STRIDE table; RFC 6902 gets its own `-actions/fields` route with its own media type rather than overloading the JSON:API update; `/api/health/checks` and `/api/metrics` require `METRICS_TOKEN`, which the shipped `.env.example` sets, while liveness and readiness stay open (ADR-0014).

## Stop conditions

- No fork, remote, push, deployment, pin, account or paid resource without explicit approval.
- Do not claim original authorship of upstream code, a completed federation platform, production usage, or upstream acceptance.
- Do not duplicate Learning Center domains or read its database.
- Do not broaden Phase A beyond the one registration-review path.
- Do not present seeded credentials or the super-admin bearer token as production-safe.

## Verification before changing status

Run the fork's CI jobs locally through the tooling container (PHPUnit on both engines, Pint, `tsc`, ESLint, `next build`, Playwright, axe), the dependency and secret scans, and the cold-clone check. A local run establishes local behaviour only; CI on the fork establishes reproducibility; neither establishes a deployable or publicly secure system. Deferred ideas go to `docs/future-work.md`; completed lessons go to `docs/LEARNING_LOG.md`; this file is updated when a gate or status changes.
