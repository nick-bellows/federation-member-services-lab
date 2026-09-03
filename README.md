# Federation Member Services Lab

An engineering modernization lab: an existing open-source club-management platform, extended step by step into a member-services system for a fictional national soccer federation, without a rewrite.

> **Fork notice.** This repository is a fork of [vereinfacht/vereinfacht](https://github.com/vereinfacht/vereinfacht) (MIT, © visuellverstehen GmbH). Upstream's history, license and attribution are preserved; upstream's own README is kept verbatim at [`docs/UPSTREAM_README.md`](docs/UPSTREAM_README.md). Everything the fork added is listed below and is visible with `git log upstream/main..main` and `git diff --stat upstream/main`.
>
> **Status: `draft`.** Everything described here runs in the local Docker Compose stack and is verified by the test suites named below. The CI workflow is written but has not yet run on GitHub. Nothing is deployed. The federation, its organizations and every person in the seed data are invented; this project is not affiliated with, endorsed by, or based on the internal systems of any real federation.

## The problem this fork works on

A national federation inherits a club-management platform that hundreds of clubs already use for membership applications, member records and club finances. The federation needs registration above the club level: organizations that group clubs, seasons, people who sign in with an identity the federation does not store passwords for, applications that move through review with reasons and an audit trail, and eligibility that is derived from facts rather than typed into a checkbox. A rewrite would break the clubs. The question the repository answers, milestone by milestone, is **how to add all of that to a running system without destabilizing what already works**.

The sibling project [learning-center-reference](https://github.com/nick-bellows/learning-center-reference) owns education, certification and safeguarding-derived eligibility. This repository owns organizations, membership, registration applications, document review and audit, and will consume credentials from the Learning Center over an HTTP contract only. The two never share a database.

## What the fork added, in order

| Milestone | What exists | Evidence |
|---|---|---|
| M0 Archaeology | The upstream system running unchanged, its request path traced end to end, its tests, gaps and MySQL-only SQL catalogued | [`docs/UPSTREAM_ANALYSIS.md`](docs/UPSTREAM_ANALYSIS.md), [`docs/baseline/`](docs/baseline/) |
| M1 Baseline quality | One behaviour fix with a fail-then-pass regression test (`env()` read under cached configuration returned the upstream production domain), line-ending safety, a CI workflow (draft), the test-tool decision | [ADR-0002](docs/adr/0002-runtime-settings-through-config-not-env.md), [`docs/baseline/env_bug_before_fix.txt`](docs/baseline/env_bug_before_fix.txt) |
| M2 Federation domain | Federation → member organization → club → member; a seven-state application lifecycle whose only writer is one transition service; an append-only audit trail; duplicate protection at two layers | [`docs/DOMAIN_MODEL.md`](docs/DOMAIN_MODEL.md), [ADR-0005](docs/adr/0005-federation-hierarchy-above-upstream-clubs.md), [ADR-0006](docs/adr/0006-application-state-machine-and-audit-trail.md) |
| M3 Identity | OpenID Connect sign-in (authorization code + PKCE) with the access token kept server-side; Laravel validates tokens against the issuer's keys and derives capabilities from the database, never from claims; a mock provider in compose, Auth0 when configured | [ADR-0007](docs/adr/0007-oidc-identity-boundary.md), [INCIDENT-000](docs/incidents/INCIDENT-000-dev-database-wiped-by-config-cache.md) |
| M4 Review slice | Registration windows, applications with details and document metadata, a second JSON:API server with generated TypeScript types, idempotent submission, correlation ids on every audit entry, member and reviewer pages, browser tests with accessibility scans | [ADR-0008](docs/adr/0008-document-metadata-without-file-storage.md), [INCIDENT-002](docs/incidents/INCIDENT-002-duplicate-submission.md), [`docs/assets/`](docs/assets/) |

Planned, not built: the Learning Center credential contract and derived participation status, a transactional outbox with a worker, PostgreSQL support, structured logs and traces, the accessibility and performance passes, release engineering. The order and gates are in [`ROADMAP.md`](ROADMAP.md).

## Architecture

**Inherited.** A Laravel 13 JSON:API backend with a Filament admin panel and Sanctum tokens, a Next.js 14 frontend, MariaDB, Docker Compose for development.

```mermaid
flowchart LR
    subgraph before [Upstream, unchanged]
        B1[Browser] --> N1[Next.js club management and public apply form]
        N1 -->|Sanctum bearer token| L1[Laravel JSON:API v1 with club scoping]
        L1 --> DB[(MariaDB)]
        F1[Filament admin panel] --> DB
    end
```

**Added.** A second JSON:API server for the federation, an OIDC identity boundary, the domain module, and a mock identity provider for development and CI. Upstream's paths are untouched; the two additive columns on upstream tables are nullable.

```mermaid
flowchart LR
    B[Browser] -->|PKCE code flow| IdP[(OIDC provider: mock in compose, Auth0 when configured)]
    B --> N[Next.js member and reviewer pages]
    N -->|access token from the encrypted session cookie| S[Laravel JSON:API server federation, oidc guard]
    S --> M[Federation module: windows, applications, documents, transition service, policies]
    M --> A[(audit_entries)]
    M --> DB[(MariaDB: federations, seasons, member_organizations, registration_windows, registration_applications, application_documents)]
    S -.->|JWKS| IdP
    N -->|unchanged| L[Laravel JSON:API v1, Sanctum]
```

Decisions behind every box are recorded in [`docs/adr/`](docs/adr/).

## Five-minute path for a reviewer

| Minute | Open | What it shows |
|---|---|---|
| 0–1 | This page, then [`docs/UPSTREAM_ANALYSIS.md` §8](docs/UPSTREAM_ANALYSIS.md) | What was inherited, what was wrong with it, what the fork changed |
| 1–2 | The diagrams above, [`docs/DOMAIN_MODEL.md`](docs/DOMAIN_MODEL.md) | Identity, membership hierarchy, application lifecycle, audit |
| 2–3 | [`api/app/Federation/StateMachine/ApplicationTransitions.php`](api/app/Federation/StateMachine/ApplicationTransitions.php) and [`api/app/Federation/Actions/TransitionApplication.php`](api/app/Federation/Actions/TransitionApplication.php) | The one place status changes: legality, actor, reason, completeness, idempotency, audit, after-commit event |
| 3–4 | [`api/tests/Unit/Federation/ApplicationTransitionsTest.php`](api/tests/Unit/Federation/ApplicationTransitionsTest.php), [`api/tests/Feature/Federation/Http/RegistrationApplicationsHttpTest.php`](api/tests/Feature/Federation/Http/RegistrationApplicationsHttpTest.php), [`api/tests/Feature/Federation/OidcTokenVerifierTest.php`](api/tests/Feature/Federation/OidcTokenVerifierTest.php) | All 49 transition pairs pinned; authorization, idempotency and failure handling over HTTP; signature, issuer, audience, expiry, key rotation |
| 4–5 | [INCIDENT-000](docs/incidents/INCIDENT-000-dev-database-wiped-by-config-cache.md), [ADR-0007](docs/adr/0007-oidc-identity-boundary.md), [ADR-0004](docs/adr/0004-upstream-contribution-policy.md) | A real incident with a permanent fix; a boundary decision; what will be offered upstream and how |

## Screenshots

Captured from the running stack by [`e2e/tests/screenshots.spec.ts`](e2e/tests/screenshots.spec.ts); every name and file is synthetic.

| | |
|---|---|
| ![Sign-in page with the identity providers](docs/assets/member-sign-in.png) | ![Applicant's applications list](docs/assets/member-applications.png) |
| ![An approved application with documents and history](docs/assets/member-application.png) | ![Reviewer queue](docs/assets/reviewer-queue.png) |

## Running it locally

Requirements: Docker Desktop (or Docker Engine with Compose v2) and Node 20+ on the host only for the browser tests. No PHP is needed on the host; everything PHP runs in the `tooling` container, as upstream intends.

```sh
git clone --config core.autocrlf=false --origin upstream https://github.com/vereinfacht/vereinfacht.git   # or clone this fork
cd federation-member-services-lab
cp docker-compose.override.example.yml docker-compose.override.yml   # Windows/NTFS: dependency trees in named volumes
printf 'USER_ID=1000\nGROUP_ID=1000\n' > .env
docker compose up -d --build database api api-docs oidc tooling
docker compose exec tooling bash
```

Inside the container:

```sh
cd api
composer install
cp .env.example .env && php artisan key:generate
sed -i 's/^MAIL_MAILER=smtp/MAIL_MAILER=log/' .env          # no mailpit service in compose
# The api container migrates the empty database on its own first start. Wait until
# `docker compose logs api` shows "Starting the app" (several minutes), then:
php artisan migrate:fresh --seeder=NorthgateDemoSeeder      # upstream's fake clubs + the Northgate federation
php artisan filament:assets && npm ci && npm run build
cd ../web_application
cp .env.local.example .env.local
npm ci
WATCHPACK_POLLING=true npx next dev                          # polling: bind mounts on Windows deliver no file events
```

Then restart the API once so it loads the environment: `docker compose restart api`.

| Where | What |
|---|---|
| http://localhost:3000/en/member/sign-in | Member sign-in through the mock OIDC provider. Type any subject and a claims JSON such as `{"email":"alex.participant@northgate.example","email_verified":true,"name":"Alex Participant"}`. Seeded people: `alex.participant`, `sam.coach`, `riley.referee`, `jordan.newcomer`, `nysa-admin`, `nasl-admin`, `nra-admin`, `federation-admin`, all `@northgate.example`. Administrators see the review queue and the windows page. |
| http://localhost:3001/admin | Upstream's Filament panel, unchanged (`hello@vereinfacht.digital` / `password`) |
| http://localhost:3000/de/admin/auth/login | Upstream's club management, unchanged (`club-admin-1@example.org` / `password`; needs the super-admin token step from [`docs/UPSTREAM_README.md`](docs/UPSTREAM_README.md)) |
| http://localhost:3001/federation_openapi.json | The federation API contract |
| http://localhost:3004/default/.well-known/openid-configuration | The mock identity provider |

Every deviation from upstream's own instructions, and why, is in [`docs/UPSTREAM_ANALYSIS.md` §11](docs/UPSTREAM_ANALYSIS.md).

## Tests

```sh
docker compose exec tooling bash -lc 'cd api && php artisan test'          # 168 tests, SQLite in memory
cd e2e && npm ci && npx playwright install chromium && npx playwright test   # 7 browser journeys with axe, against the running stack
```

Measured on 2026-09-02 and retained under [`docs/baseline/`](docs/baseline/): PHPUnit 168 passed (674 assertions); Playwright 7 passed. The run instructions above were followed from a fresh clone in a separate Compose project on the same day (`docs/baseline/cold_clone_2026-09-02.txt`); the first run found a date that hydrated differently on the server and in the browser, which is fixed and now guarded by the browser spec. Upstream's baseline at the fork point was 91 tests and no frontend or end-to-end tests. [`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs the PHP suite on SQLite and on MariaDB, Pint on the fork's own files, the frontend type-check, lint and build, the browser journeys, and publishes the upstream-versus-fork diff; it is marked draft until its first run.

## Upstream contributions

None offered yet. The policy is in [ADR-0004](docs/adr/0004-upstream-contribution-policy.md): one small, generic, tested change at a time, after reading the issue thread. First candidate: the `env()` fix together with `.gitattributes`, then the locale-header matching for upstream issue #125. Nothing here will be described as merged unless it is.

## Documentation

- [`ROADMAP.md`](ROADMAP.md): phases, gates, decisions taken, what is still open
- [`docs/UPSTREAM_ANALYSIS.md`](docs/UPSTREAM_ANALYSIS.md), [`docs/DATABASE_BASELINE.md`](docs/DATABASE_BASELINE.md): the inherited system as found
- [`docs/DOMAIN_MODEL.md`](docs/DOMAIN_MODEL.md): vocabulary, hierarchy, lifecycle, invariants
- [`docs/adr/`](docs/adr/): eight decision records
- [`docs/incidents/`](docs/incidents/): incident write-ups with detection, root cause, permanent fix and regression test
- [`docs/LEARNING_LOG.md`](docs/LEARNING_LOG.md): what was measured and what went wrong, per milestone
- [`docs/INTERVIEW_GUIDE.md`](docs/INTERVIEW_GUIDE.md): per area, what it does, why, alternatives, failure modes, code to open
- [`docs/future-work.md`](docs/future-work.md): the single home for deferred ideas

## How this was built

Independent open-source software engineering project. The work was done with AI pair-programming assistance (Claude Code) under a teaching protocol: every milestone opens with the relevant concepts, decisions are recorded as ADRs with alternatives, and every number in the documentation comes from a retained run. Failures are documented as they happened, including one that destroyed the development database.

## Attribution and license

Upstream: [vereinfacht](https://github.com/vereinfacht/vereinfacht), developed by [visuellverstehen](https://github.com/visuellverstehen), in part on behalf of the municipality of Süderbrarup. Distributed under the MIT license; see [`LICENSE`](LICENSE). Additions in this fork are contributed under the same license.
