# Interview guide

One section per system area. Each section is written so the project can be explained from understanding, not from a script: what the area does, why it was built that way, what else was considered, how it fails, what the tradeoffs were, which files to open immediately, and the questions an interviewer is likely to ask. Sections are added as milestones complete.

## M0 — The inherited system

### What it does

vereinfacht is a club-management platform: clubs publish a public membership application form, applicants submit it, club admins manage memberships, members, divisions and finances in a Next.js UI, and a Filament panel gives a super admin cross-club access. Laravel exposes everything as JSON:API under `/api/v1`.

### Why start by reading instead of building

The project's thesis is incremental modernization of a system with existing users. The first risk in such work is misunderstanding how the inherited code enforces its invariants (tenancy, authorization, status rules). Milestone 0 exists to find those mechanisms and their gaps before touching them.

### Alternatives considered

- Greenfield rewrite: rejected by the brief; it would demonstrate nothing about working in an existing codebase.
- Wrapping upstream unchanged behind a new service: keeps hands clean but defers the real problem.

### Failure modes found in the inherited code

- Non-atomic application: three writes and a synchronous notification with no transaction (`api/app/Actions/Membership/ApplyMembershipAction.php`).
- Super-admin token for anonymous traffic (`web_application/src/services/club-api.ts`); tenant writes by club admins are blocked by relationship validation through the scope, not by the club-association hook in `api/app/JsonApi/V1/Server.php`, whose fallback branch is dead for memberships and members.
- Exception messages returned to clients (`api/app/Http/Controllers/Api/V1/MembershipController.php`).
- Test engine (SQLite) differs from runtime engine (MariaDB), hiding MySQL-only SQL.
- Development container caches configuration onto the shared bind mount, so tests can point at the wrong database.

### Tradeoffs upstream made

- Global scopes for tenancy: simple and pervasive, but mixes read authorization with query construction and needs a bypass for super admins.
- `sync` queue with `ShouldQueue` listeners: no worker to run, but side effects happen inside the request.
- Permissions in migrations: versioned with the schema, but non-idempotent and noisy.
- Two frontend API clients: the typed `openapi-fetch` path is newer and better, the `jsonapi-fractal` path still carries the public form and login.

### Code to locate immediately

`api/routes/api.php` · `api/app/Http/Kernel.php` · `api/app/JsonApi/V1/Server.php` · `api/app/Models/Scopes/ClubScope.php` · `api/app/Http/Middleware/ClubPermission.php` · `api/app/Actions/User/Login.php` · `api/app/Actions/Membership/ApplyMembershipAction.php` · `api/app/Http/Controllers/Api/V1/MembershipController.php` · `web_application/src/app/[lang]/[slug]/create-membership/route.ts` · `web_application/src/utils/auth.ts` · `api/tests/Feature/MembershipApplicationTest.php`

### Likely interviewer questions

1. How is a request authenticated and tenant-scoped in this system, and where would a tenant-isolation bug most likely hide?
2. The public form runs on a long-lived super-admin token held by the Next server. What are the risks, and how would a least-privilege redesign look?
3. API tests exist but there is no CI and no frontend testing. Which pipeline would be added first, and what would be refused for merge without it?
4. What was wrong with the upstream implementation, and what was right?
5. Why does a state stored as a nullable string with rules only in PHP become a problem as the domain grows?

## M1 — Baseline quality and one defensible fix

### What it does

Reads the web application URL, the admin login path and the sender address through Laravel's configuration instead of `env()`, so they survive configuration caching; normalises line endings; adds a CI workflow (first run on GitHub on 2026-09-03; its three first-run findings are in the learning log) and records the end-to-end tool and the upstream-contribution policy.

### Why we built it

The first change to an inherited system should be small, provably correct and behaviour-preserving in the common path. This one was reproduced on the running container before touching code, tested red-then-green, and re-verified live.

### Alternatives considered

Leave `env()` and stop caching configuration (hides the defect); export variables in the entrypoint (works around the misuse); a dedicated config file (larger than needed). Cypress instead of Playwright. Offering all three M1 changes upstream at once.

### Failure modes

Configuration caching in any environment; a fifth `env()` call added later (guarded by a grep in CI); Pint enforced blindly would reformat 400 upstream files.

### Tradeoffs

Two extra config keys versus four hard-coded fallbacks; running the suite twice in CI (SQLite for speed, MariaDB for fidelity) versus once; Pint as a report versus a gate.

### Code to locate immediately

`api/config/app.php` (the two keys) · `api/app/Models/Club.php::applyUrl` · `api/app/Providers/HealthCheckServiceProvider.php` · `api/app/Providers/AppServiceProvider.php` · `api/app/Mail/WelcomeClubAdminMailable.php` · `api/tests/Unit/WebApplicationUrlConfigTest.php` · `.github/workflows/ci.yml` · `.gitattributes` · `docs/adr/0002` to `0004`

### Likely interviewer questions

1. How does configuration reach your code in a production Laravel deployment, and what changes when it is cached?
2. How do you know a fix is real? Walk through fail-then-pass for this one.
3. Your CI runs the suite on SQLite and on MariaDB. Why both, and what would you do about the cost?
4. The same commit passed its pull-request run and hung its push run for 45 minutes. What was racing, how did you find it without a log line, and what made the job deterministic?
4. Why is Pint a report and not a gate, and how would you enforce style on new code without reformatting upstream?

## M2 — Federation domain and application state machine

### What it does

Adds federation, member organization, season, administrator roles and registration applications above upstream's clubs, with a seven-state application lifecycle whose only writer is one transition service, an append-only audit trail, and two-layer duplicate protection.

### Why we built it this way

Upstream's nullable-string status works for three states and one transition; a federation application has seven states, two actor kinds and reasons. A transition table that executes, a single writer inside a transaction, and an audit row per change make the rules impossible to bypass from a controller and every change attributable.

### Alternatives considered

A generic organizations table replacing clubs; a pivot instead of a nullable column on clubs; a separate persons table; the OIDC subject on members; `spatie/laravel-model-states`; per-transition command classes; `spatie/laravel-activitylog`; event sourcing; a database `CHECK` on status (ADR-0005, ADR-0006).

### Failure modes

Status assigned outside the service (throws); two live applications (unique `active_key` as backstop); retried "start" calls (idempotency key); a season from another federation (domain exception); a listener failing after commit (event fires after commit but has no retry until the outbox milestone); identifier names over 64 characters on MariaDB (tested).

### Tradeoffs

Two vocabularies for "member" coexist; clubs may have no organization; the transition table must be edited on purpose; the audit table grows unbounded; `restrict` on delete for organizations with applications means cleanup is deliberate.

### Code to locate immediately

`api/app/Federation/StateMachine/ApplicationTransitions.php` · `api/app/Federation/Actions/TransitionApplication.php` · `api/app/Federation/Actions/StartApplication.php` · `api/app/Federation/Models/RegistrationApplication.php` (the `saving` guard and `active_key`) · `api/app/Federation/Support/ApplicationActorResolver.php` · `api/app/Federation/Models/AuditEntry.php` · `api/database/migrations/2026_09_02_100006_create_registration_applications_table.php` · `api/tests/Unit/Federation/ApplicationTransitionsTest.php` · `api/tests/Feature/Federation/TransitionApplicationTest.php` · `api/database/seeders/NorthgateDemoSeeder.php`

### Likely interviewer questions

1. Walk through what happens when a reviewer approves an application, from the method call to the committed row and the event.
2. How do you prevent duplicate registrations, and why two layers?
3. Why not a package for the state machine or the activity log?
4. Why should eligibility never be a boolean column, and where will it be computed?
5. What did running the migrations on MariaDB teach you that SQLite could not?

## M3 — The identity boundary

### What it does

Federation users sign in through OpenID Connect (a mock provider in compose and CI, Auth0 when configured) with the authorization-code flow and PKCE. Next.js keeps the access token in an encrypted server-side cookie and sends it to Laravel as a bearer credential for federation routes. Laravel validates it itself against the issuer's keys and maps the subject to a user; what the user may do comes from the database.

### Why we built it this way

"Who are you" and "what may you do" are different questions with different owners. The provider answers the first; this application answers the second and must not let a token's claims answer it. Doing the validation in the API rather than exchanging for a Sanctum token keeps the boundary enforced on every request.

### Alternatives considered

Auth0's Laravel SDK; Auth0's Next.js SDK; token exchange for Sanctum; trusting roles from claims; Keycloak or Dex locally (ADR-0007).

### Failure modes

Wrong issuer or audience, expired token, forged signature, algorithm confusion, unknown key id, key rotation, unverified e-mail claims, an e-mail already linked elsewhere, provisioning disabled, provider unreachable (cached keys keep existing tokens valid until the cache expires; new sign-ins fail), token in logs (never logged), ID token audience conflicts (multi-audience with `azp`).

### Tradeoffs

Two identity systems coexist; `host.docker.internal` is a Docker Desktop convention that CI must reproduce; multi-audience tokens must be configured on the provider; verified e-mail linking trades a little friction for closing an account-takeover path.

### Code to locate immediately

`api/app/Federation/Auth/OidcTokenVerifier.php` · `api/app/Federation/Auth/OidcUserResolver.php` · `api/app/Federation/FederationServiceProvider.php` (the guard) · `api/app/Federation/Auth/FederationScopes.php` · `api/config/oidc.php` · `api/tests/Feature/Federation/OidcTokenVerifierTest.php` · `api/tests/Feature/Federation/OidcGuardTest.php` · `web_application/src/lib/federation/providers.ts` · `web_application/src/lib/federation/session.ts` · `web_application/src/utils/auth.ts` · `docker/oidc/config.json` · `e2e/tests/member-sign-in.spec.ts` · `api/phpunit.xml` and `docs/incidents/INCIDENT-000-dev-database-wiped-by-config-cache.md`

### Likely interviewer questions

1. Walk me through the sign-in flow and say where each token lives at every step.
2. Why don't you trust the roles or scopes in the token?
3. What happens if the identity provider is down?
4. How do you handle key rotation, and what could an attacker do with your refresh logic?
5. Tell me about a time you broke your own environment. What did you change so it cannot happen again?

## M4 — The registration-review slice

### What it does

An organization administrator opens a registration window; a signed-in person starts an application inside it, supplies details and document metadata (files hashed in the browser, bytes never stored), and submits; a reviewer works a queue scoped to their organizations, judges documents, requests information, approves or rejects with reasons; the applicant sees the status, the reason and the full history. All of it over a second JSON:API server with generated TypeScript types, idempotent submission and correlation ids on every audit entry.

### Why we built it this way

The workflow is the product; the state machine and identity boundary only earn their keep when a reviewer can click through it. Keeping upstream's contract style (JSON:API, OpenAPI, typed client, server actions with Zod) proves the fork lives inside the inherited conventions rather than beside them.

### Alternatives considered

Extending upstream's `v1` server; plain JSON controllers; real uploads via medialibrary; S3 now; implicit "current season" instead of explicit windows (ADR-0008 and the roadmap decisions).

### Failure modes

Double submission (idempotency key, 409 on a new key, unique `active_key`); submitting an incomplete application (422 with what is missing); a reviewer from another organization (404 through scoping, 403 on the action); a stale route cache in the API container after adding routes; OpenAPI drift between the merged document and the code; a rejected sort silently emptying a page; a date that hydrates with a different calendar day on the server (UTC) and in the browser (local zone), found only by the cold-clone verification.

### Tradeoffs

Documents are promises until object storage exists; the OpenAPI document is partly hand-described; `history` is computed per resource; the review queue relies on server-side scoping rather than a dedicated endpoint.

### Code to locate immediately

`api/app/Federation/JsonApi/Server.php` · `api/routes/federation.php` · `api/app/Federation/Http/Controllers/RegistrationApplicationController.php` · `api/app/Federation/Http/Controllers/Concerns/RendersDomainExceptions.php` · `api/app/Federation/JsonApi/RegistrationApplications/RegistrationApplicationSchema.php` (scoping, history) · `api/app/Federation/Actions/AttachDocumentMetadata.php` · `api/app/Federation/Console/GenerateFederationOpenApi.php` · `api/tests/Feature/Federation/Http/RegistrationApplicationsHttpTest.php` · `web_application/src/actions/federation/actions.ts` · `web_application/src/app/[lang]/member/applications/[id]/DocumentsPanel.tsx` · `web_application/src/lib/federation/format.ts` · `e2e/tests/registration-review.spec.ts` · `docs/incidents/INCIDENT-002-duplicate-submission.md`

### Likely interviewer questions

1. How do you prevent a duplicate registration when the user double-clicks, and when two tabs race?
2. Why does the applicant never upload a file in this version, and what changes when object storage arrives?
3. Where is authorization enforced for the review queue, and how would you test that a reviewer cannot see another organization's applications?
4. Why a second JSON:API server instead of extending the existing one?
5. What did the OpenAPI generator not do for you, and how did you keep the frontend typed anyway?
6. The working stack passed every browser test and a fresh clone failed the first page. What class of bug behaves like that, and what would you check before blaming the environment?

## M5 / B2 — The Learning Center contract

### What it does

The federation asks the Learning Center, with its own service token, for a person's credential facts and derived eligibility, keyed by the OIDC subject both systems already hold. It stores the answer as a snapshot with the provider's evaluation time and its own fetch time, and derives participation on read: approved application, provider says eligible, and a valid role credential where the role needs one. Reviewers see the findings before deciding, can refresh on demand, and the nightly reconciliation repairs whatever the synchronous path missed. The provider side lives in `learning-center-reference` (pull request #1); the contract is a document plus fixture files both sides execute.

### Why we built it this way

Two systems, two databases, one person: the only safe shared thing is a contract. Keying on the subject avoids a stored foreign id; a service token gives the reviewer and the batch job an identity the applicant's token cannot; a snapshot with an age makes reads deterministic and honest under a slow provider, which is Incident 1.

### Alternatives considered

Learning Center member UUID as the key; forwarding the applicant's token; a static API key; a materialised participation column; a live call on every read (ADR-0009).

### Failure modes

Provider slow (timeout, 503 on explicit refresh, page answers from the snapshot); provider down during approval (approval succeeds, participation unknown, reconciliation repairs); provider says not found (recorded, not retried before the interval); contract drift (the consumer refuses another version or an unknown status); a root-owned log file shared across containers turning every logged request into a 500 (found while rehearsing the incident); the HTTP fake keeping the first stub per URL (a test trap, twice now).

### Tradeoffs

Freshness is a policy (limit and reconciliation interval), not a property of the page; the reviewer's refresh is the only user path that waits on the provider; subjects are provider-scoped, so the two demo stacks meet only under a shared issuer; free text in `eligibility.reason` is never parsed.

### Code to locate immediately

`docs/contracts/learning-center-credentials-v1.md` · `api/tests/Fixtures/learning-center/credentials/` · `api/app/Federation/LearningCenter/{HttpCredentialsClient,ServiceTokenProvider,CredentialSnapshots,ParticipationResolver}.php` · `api/app/Federation/Listeners/RefreshCredentialsOnApproval.php` · `api/app/Federation/Console/ReconcileCredentials.php` · `api/tests/Feature/Federation/Http/ParticipationHttpTest.php` · `docker/learning-center-mock/server.js` · `web_application/src/app/[lang]/member/components/ParticipationPanel.tsx` · in the Learning Center: `api/internal/httpapi/router.go` (`authenticateService`), `api/internal/credentials/`, `api/internal/safeguarding/eligibility.go` (`Current`)

### Likely interviewer questions

1. Two services, two databases, one person. How do you keep "may this person participate" correct without sharing tables?
2. How does one service authenticate to another here, and why not forward the user's token?
3. The credential service is slow for an hour. What does the member see, what does the reviewer see, and what repairs it?
4. What does "the contract is executable on both sides" mean in this repository, and what breaks first when the provider renames a field?
5. Why does the consumer never re-derive validity from the expiry dates it receives?

## M6 / B3 — Events and reliability

### What it does

Every state change that other parts of the system care about is written as a fact in the same transaction, in an outbox table. A relay turns facts into one job per consumer on Laravel's database queue; a worker runs them; each consumer records what it has processed so a second delivery does nothing. Failures retry with backoff, then park with a reason an operator can read, and a command replays them. Two consumers exist: notification rows for the person concerned, and the credential refresh after an approval, which used to be a synchronous listener.

### Why we built it this way

The dual-write problem is not solved by dispatching after commit; the process can still die in between. An outbox makes the fact durable with the change, and at-least-once delivery plus an idempotency ledger makes the effect happen once. One job per consumer keeps a slow provider from holding a notification hostage, which is exactly Incident 3.

### Alternatives considered

Queued listeners without an outbox; an outbox polled by a bespoke worker; one job fanning out to all consumers; natural unique keys as the only idempotency; mark-before-process (ADR-0010).

### Failure modes

A consumer that throws (retried, parked, replayed); a relay crash between dispatch and marking (the ledger absorbs the redelivery); the sync queue driver (refused by the relay, because a dispatch would run inside the relay's transaction); a worker running as a different user on the shared storage mount (the web process loses its cache and log writes); parallel first requests for a new identity (provisioning is create-or-first now).

### Tradeoffs

Participation after an approval is unknown for a second or two instead of immediately; attempts are counted per row, not per consumer; the notification rows have no surface yet; the worker loop is one process for development and CI, not the production shape.

### Code to locate immediately

`api/app/Federation/Outbox/{OutboxRecorder,ProcessOutboxEvent,ConsumerRegistry}.php` · `api/app/Federation/Outbox/Consumers/` · `api/app/Federation/Console/{OutboxRelay,FederationWork,OutboxStatus,OutboxReplay}.php` · `api/database/migrations/2026_09_03_110000_create_federation_outbox_tables.php` · `api/tests/Feature/Federation/OutboxTest.php` · `docs/incidents/INCIDENT-003-worker-fails-after-approval.md`

### Likely interviewer questions

1. How do you guarantee that a message is sent when a transaction commits, and only then?
2. Your consumers are idempotent. Show me where, and tell me what breaks if you remove it.
3. You used a database queue. What changes, and what does not, when this moves to SQS or Kafka?
4. The worker fails after an approval. Walk me through what the reviewer sees, what the operator sees, and how it is repaired.
5. Why one job per consumer rather than one job per event?

## M7 / B4 — PostgreSQL

### What it does

The suite runs on three engines in CI: SQLite (upstream's configuration), MariaDB (the runtime) and PostgreSQL 16 (the strict one, with the demo seeder). Upstream's MySQL-only SQL was replaced in place by portable forms with a regression test, and every construct, treatment and known engine difference is written down in one matrix.

### Why we built it this way

SQLite hides dialect bugs by type affinity; only the real engine tells the truth. A matrix with evidence beats a claim; fixing in place beats two code paths and gives upstream something usable.

### Alternatives considered

Switching the default to PostgreSQL; PostgreSQL for the federation tables only; isolating every construct behind a driver switch; marking features MariaDB-only (ADR-0011).

### Failure modes

`FIELD()` and `CAST AS UNSIGNED` rejected outright; a double-quoted literal read as an identifier; strict `GROUP BY` on a future query; `LIKE` case; `NULL` ordering on nullable sort columns; unsigned money columns becoming plain integers.

### Tradeoffs

Three CI jobs instead of two; MariaDB stays the default so the demo stack is still upstream's; a `CHECK` per engine deferred.

### Code to locate immediately

`docs/DATABASE_COMPATIBILITY.md` · `api/app/Support/OrderByIdList.php` · `api/tests/Feature/Export/ExportOrderTest.php` · `api/app/Models/Membership.php` (`scopeWithMembersDivisionsFee`) · `.github/workflows/ci.yml` (`backend-postgres`) · `docker-compose.yml` (`postgres` profile)

### Likely interviewer questions

1. You added PostgreSQL support to an application written for MySQL. What did you find, and how did you decide what to fix and what to isolate?
2. What does "supported on PostgreSQL" mean in your README, and what evidence backs it?
3. Which engine would you run in production for this system, and what would make you change your mind?
4. Why did the export ordering have no test before, and what does the new one prove on each engine?
