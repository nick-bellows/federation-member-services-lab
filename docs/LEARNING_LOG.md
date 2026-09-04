# Learning log

Running record of the hands-on exercises completed while building this project. Entries are dated, name the commands run, and record what was actually observed, including surprises and open questions. Nothing here is a claim about the system that the code or a test does not back.

## 2026-09-01 — Milestone 0: engineering archaeology

**Goal.** Get the inherited application running unchanged, run its test suite, and trace one real request end to end before modifying anything.

**Commands run (host, PowerShell/Git Bash, from `D:\PortfolioProjects`).**

```sh
git clone --config core.autocrlf=false --origin upstream https://github.com/vereinfacht/vereinfacht.git federation-member-services-lab
docker compose -p learning-center-reference stop        # another project held port 3000
docker compose up -d --build database api api-docs tooling
docker compose exec tooling bash
```

**Commands run (inside the `tooling` container).** See `docs/UPSTREAM_ANALYSIS.md` section 11 for the exact sequence, timings, and every deviation from the upstream README.

**What was measured.** Images built in 8 min 15 s; dependency installs took under a minute in total; the seeded database took 298 s because every `CREATE TABLE` was slow on this container; the PHPUnit suite ran green (91 tests, 338 assertions) in 58 s on SQLite; the public form's first compile took 24.8 s under `next dev`. The four API calls behind the public application were replayed by hand and produced membership 61 in state `applied` with one `inactive` member.

**What went wrong first.** Two migration attempts collided with the API container's own start-up migration; PHPUnit 12 rejected a flag artisan passed through; a `sed` with `|` as delimiter silently failed to write a Sanctum token that itself contains `|`; `curl` globbed the `[slug]` in a JSON:API filter. Each is recorded with its rule in `docs/UPSTREAM_ANALYSIS.md` section 11.

**Three surprises.**

1. The README documents a Cypress end-to-end suite in `/e2e` that does not exist anywhere in the repository (upstream issue #197). The only automated tests are 28 PHPUnit files under `api/tests`.
2. The JSON:API routes in `api/routes/api.php` carry no `auth:sanctum` middleware. Authentication is selected inside `App\JsonApi\V1\Server::serving()` and enforced by policies plus a global scope that returns zero rows for guests.
3. The API container's entrypoint caches configuration onto the bind-mounted `api/` directory, which makes a later `php artisan test` in the tooling container ignore `.env.testing`. Without `php artisan config:clear` first, the test suite's `migrate:fresh` would target the seeded MariaDB development database.

**Open questions carried into the lesson.**

- Why does `ClubScope::apply` compute a club id for `Club`-model tokens and then overwrite it two lines later?
- What actually happens when a `club admin` token posts a membership for a different club — a 403, or a silent re-association?
- Which of the MySQL-specific constructs found in migrations and filters will PostgreSQL reject?

**Exercises.** Recorded below once completed.

### Exercise E1 — count the queries behind one test

_Pending; recorded in the internal review file._

### Exercise E2 — watch the tenant context during a public application

_Pending; recorded in the internal review file._

## 2026-09-02 — Milestone 1: baseline quality and one defensible fix

**Goal.** Prove the inherited system can be tested and improved without a rewrite: one bounded fix with a test that fails before and passes after, line-ending safety for Windows checkouts, a CI workflow, and the end-to-end tool decision.

**The fix, line by line.** Four call sites read `env()` at runtime: `Club::applyUrl`, `HealthCheckServiceProvider::boot`, the password-reset link in `AppServiceProvider::boot`, and `WelcomeClubAdminMailable::__construct`. Two keys were added to `config/app.php` (`web_application_url`, `club_admin_login_path`), and each call site now reads `config()`. The mail sender comes from the existing `mail.from.address`. Five production lines changed.

**Why it is a bug and not a style preference.** `Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables` skips loading `.env` when `bootstrap/cache/config.php` exists. From then on `env()` sees only the real process environment. The API container runs `config:cache` in its entrypoint on every start, so in the running container `env('WEB_APPLICATION_URL')` was `NULL` and every URL fell back to the upstream company's production domain, including the health checks that were supposed to ping this deployment.

**Evidence, in order.**

| Step | File | Result |
|---|---|---|
| Reproduce on the live container, config cached | `docs/baseline/env_bug_before_fix.txt` | apply URL, mail link and both health pings on `app.vereinfacht.digital` |
| Write the tests, run before the change | `docs/baseline/env_fix_tests_before.txt` | 3 of 3 failed, each assertion showing the production domain |
| Apply the change, run the tests | `docs/baseline/env_fix_tests_after.txt` | 4 of 4 passed (a fourth test for the reset link was added with the fix) |
| Full suite on SQLite | `docs/baseline/phpunit_after_env_fix.txt` | 95 passed, 345 assertions |
| Re-probe the live container, config cached | `docs/baseline/env_bug_after_fix.txt` | every URL follows `.env` |
| Regression tests on MariaDB | `docs/baseline/phpunit_mariadb_subset.txt` | 4 passed in 383 s; full suite not run on MariaDB locally, see note in the file |

**Line endings.** Git for Windows sets `core.autocrlf=true` system-wide; the M0 clone had to disable it by hand. `.gitattributes` now normalises to LF. `LICENSE` was the only tracked file stored with CRLF and was renormalised without content change.

**CI.** `.github/workflows/ci.yml` is marked DRAFT: the YAML parses, every command was run in the tooling container, and the `env()` guard was exercised, but the workflow has not run on a GitHub runner because the fork has no remote yet (visibility is gated by the roadmap). Pint is a report, not a gate, until upstream's 215 pre-existing issues are handled deliberately.

**Surprises.** A fourth `env()` call site (password reset) hid behind a grep filter that excluded lines containing `//`, which every `https://` default contains. The four fallbacks disagreed on `http` versus `https`. `next build` succeeds with no API reachable, so the frontend job needs no services.

**Decisions recorded.** ADR-0002 (config over env), ADR-0003 (Playwright), ADR-0004 (what is offered upstream: the fix plus `.gitattributes`, not the workflow yet).

## 2026-09-02 — Milestone 2: federation domain and application state machine

**Goal.** The federation hierarchy above upstream's clubs, and a registration application whose lifecycle is a state machine with an audit trail, all without changing upstream behaviour.

**Built.** Eight migrations (`api/database/migrations/2026_09_02_1000*.php`); the `App\Federation` namespace: enums, models, the transition table, two actions (`StartApplication`, `TransitionApplication`), the actor resolver, the audit recorder, one event; four factories; the `NorthgateDemoSeeder`; two nullable columns and three relation methods on upstream models. The shape is explained in `docs/DOMAIN_MODEL.md`, the decisions in ADR-0005 and ADR-0006.

**Tests.** 30 federation tests (6 pure unit tests pinning all 49 transition pairs, 23 feature tests for actors, reasons, audit, duplicates, idempotency, hierarchy, plus the schema identifier-length test). Full suite: see `docs/baseline/phpunit_after_m2.txt`.

**What went wrong, in order.**

1. Eloquent fires `saving` before `creating`; the default status set in `creating` was not there when the `saving` hook computed `active_key`. Nineteen tests failed on one null. Fixed by defaulting in `saving` for new rows.
2. The audit relation is ordered ascending; adding `latest()` in a test appended a second `ORDER BY` and still returned the oldest row. The tests take the last entry of the loaded relation instead.
3. **The migrations passed on SQLite and failed on the development MariaDB**: a generated unique-index name was 65 characters. MariaDB DDL is not transactional, so the retry hit "table already exists". Named the index, dropped the partial tables, added a test that checks every identifier length on SQLite. This is the concrete case for running the suite on the runtime engine in CI.
4. The seeder's guard is coarse (it checks the federation exists), so a partial first run had to be cleaned by hand before the second. Noted in `docs/future-work.md`.

**Evidence.** `docs/baseline/northgate_seed_run.txt` (migration and seed timings on MariaDB), `docs/baseline/northgate_seed_rows.txt` (the seeded applications and the 15-row audit trail).

## 2026-09-02 — Milestone 3: the identity boundary

**Goal.** Sign in with OpenID Connect, validate access tokens in Laravel, keep authorization in the database, touch none of upstream's login paths.

**Built.** Backend: `config/oidc.php`, the `oidc` request guard in `App\Federation\FederationServiceProvider`, `OidcTokenVerifier` (JWKS discovery, cache, one refresh on an unknown key id, issuer, audience, subject), `OidcUserResolver` (known subject, link by verified e-mail, provision), `FederationScopes`, `GET /api/v1/federation-identity/me`, `users.oidc_issuer`/`oidc_subject`. Frontend: next-auth providers `northgate-id` and `auth0`, the access token kept server-side in the encrypted cookie, `/member/sign-in` and `/member` pages, middleware protection, en/de translations. Stack: `oidc` service (`mock-oauth2-server`) with personas in `docker/oidc/config.json`. Tests: 20 PHPUnit tests for the verifier and guard with an in-test RSA issuer, 3 Playwright tests including axe scans. ADR-0007.

**Evidence.**

| What | Where | Result |
|---|---|---|
| Full PHP suite with the API's config cache deliberately present | `docs/baseline/phpunit_after_m3.txt` | 147 passed, 538 assertions; development database rows unchanged afterwards |
| Browser flow: redirect, mock sign-in, member page, sign-out, anonymous API 401 | `docs/baseline/playwright_m3.txt` | 3 passed, no serious or critical axe violations |
| Persona token straight at the API | Laravel log, audit entry 16 `user.identity_linked` | 200; Alex's identity linked to the seeded user |
| Dependency advisories in upstream's lock file | `docs/baseline/composer_audit.txt` | 13 across filament, league/commonmark, livewire; not touched in this milestone |

**What went wrong, in order.**

1. Composer refused `firebase/php-jwt` 6.x because of a published advisory. Installed 7.1.0 instead of ignoring the advisory.
2. The key-rotation test kept failing: Laravel's `Http::fake` answers with the *first* matching stub, so re-registering the issuer never served the new keys. The stub now reads the key set from a property at request time.
3. **INCIDENT-000.** Restarting the API container while the suite ran in the background wrote a config cache onto the shared bind mount; the next test booted with `DB_CONNECTION=mysql` and its `migrate:fresh` dropped the development database. Rebuilt from seed. Permanent fix: `phpunit.xml` relocates every Laravel cache path for tests, with a regression test. The first attempt used paths relative to the wrong base directory and made every test fail at boot, which is how the mechanism was proven to work before the paths were corrected. Full write-up in `docs/incidents/INCIDENT-000-dev-database-wiped-by-config-cache.md`.
4. next-auth rejected the mock provider's ID token: the catch-all mapping had stamped the API audience onto it, and an ID token's audience must be the client. Tokens now carry both audiences with `azp`, which both next-auth and Laravel accept.
5. `next dev` inside the container never saw file changes: Windows bind mounts do not deliver file events, so routes and class changes appeared only after restarts. Started with `WATCHPACK_POLLING=true`; recorded as a deviation.
6. The member page bounced back to sign-in after a successful callback: `getToken()` did not find the session cookie in the request shape it was given. It now receives both a cookies object and the header.
7. The axe scan failed the first page on contrast: upstream's Tailwind theme redefines `slate-600` as `#8c9da6`, 2.8:1 on white. Switched to `slate-700`. A default-palette assumption would have shipped.

**Not done in this milestone.** The Auth0 tenant walkthrough (owner action); the CI end-to-end job has been written but not run; refresh tokens and session expiry.

## 2026-09-02 — Milestone 4: the registration-review slice

**Goal.** The first workflow a product reviewer can follow: an organization opens registration, a person applies with details and document metadata, a reviewer decides, the applicant sees the outcome and the history. Everything on top of the M2 state machine and the M3 identity boundary, nothing bypassing either.

**Built.** Backend: registration windows, application details, document metadata with required types per role, `AttachDocumentMetadata` and `ReviewDocument`, completeness and HTTP idempotency in `TransitionApplication`, a second JSON:API server `federation` with seven schemas, three request classes, three controllers with six transition actions, a domain-exception-to-HTTP mapping, policies, a request-id middleware, `php artisan federation:openapi`. Frontend: typed client generated from the merged OpenAPI document, seven server actions, member pages (applications list, start, detail with details form, document panel that hashes files locally, submit and withdraw, history), reviewer pages (queue, detail with document decisions and transitions), the registration-window page, navigation by capability. Playwright: four journeys with axe on every page. ADR-0008; INCIDENT-002.

**Evidence.**

| What | Where | Result |
|---|---|---|
| Full PHP suite | `docs/baseline/phpunit_after_m4_backend.txt` | 168 passed, 674 assertions |
| HTTP tests for windows, applications, documents | `api/tests/Feature/Federation/Http` | 19 passed: idempotent submit with the same key and one audit row, 409 on a new key, scoped queues, actor rules, reasons, read-only fields, validation |
| Browser journeys | `docs/baseline/playwright_m4.txt` | applicant start → documents → submit; reviewer queue → review → accept → approve; applicant sees decision and history; administrator opens a window; identity specs; no serious axe violations |
| Screenshots | `docs/assets/` | captured from the running stack by `e2e/tests/screenshots.spec.ts` |

**What went wrong, in order.**

1. The package calls controller hooks positionally (`creating($request, $query)`); extra typed parameters received the query object. Dependencies are resolved inside the hooks instead.
2. My test helper was named `jsonApi`, which collides with the JSON:API testing trait upstream's base test uses. Renamed.
3. Six HTTP tests failed at once because Laravel's request guard caches its user for the application's lifetime, which in a feature test spans every request the test makes. The test helper now resets guards between requests; production is unaffected because one process serves one request.
4. "Prohibited" validation rules failed on PATCH because the package merges the stored resource into the request before validating. The idiomatic answer is `readOnlyOnUpdate()` in the schema; the package then ignores those fields rather than rejecting the request, and the test asserts the field is unchanged.
5. The OpenAPI generator treats every route whose *name* contains the server's name as a JSON:API action; the identity endpoint moved to `/api/v1/federation-identity/me` under the route name `identity.me`. It also builds examples by querying each schema, so scoped index queries return nothing for a console caller; the scope now applies only when a request exists.
6. The generator does not describe custom actions; `federation:openapi` merges the six action paths from the document's own resource schema.
7. Playwright's `selectOption` takes a string label, not a pattern; reference-data sorts were rejected with 400 because the schemas had not declared those fields sortable, and the page helper swallowed that into an empty list, which hid the window form; the applicant journey collided with its own previous run because an approved application is live, so the spec now signs in a fresh identity per run.

**Decisions recorded.** ADR-0008 (documents as metadata, second JSON:API server, merged OpenAPI). Incident write-up: INCIDENT-002 (duplicate submission), designed and reproduced rather than suffered.

## 2026-09-02 — A5: cold-clone verification of the README

**Goal.** Prove that the README's run instructions work from nothing: a fresh clone into a scratch directory, a separate Compose project with fresh volumes, no reuse of the working stack's database or dependencies. The working stack was stopped first because both use the same ports. Log: `docs/baseline/cold_clone_2026-09-02.txt`.

**Measured.**

| Step | Wall clock |
|---|---|
| `git clone` of the local repository | 5 s |
| `docker compose up -d --build` (images cached from the working stack; a fresh machine builds for minutes) | 60 s |
| `composer install`, `.env`, `key:generate` | 27 s |
| wait for the API container's own migration to finish | 45 s |
| `migrate:fresh --seeder=NorthgateDemoSeeder` | 332 s |
| Filament assets, API `npm ci` and build, web `npm ci` | 25 s |
| sign-in page, identity endpoint with a persona token | 200, 200 |
| Playwright, first run | member sign-in 3 passed; registration review 1 failed, 3 skipped |
| Playwright, after the fix below | 7 passed |

**What the first run found.** The applicant journey failed on its first page with an axe colour-contrast violation. The offending element was not the application: it was the Next.js development overlay's red "2 errors" badge. The Playwright trace held the real error, a React hydration mismatch in the start form: the server had rendered "closes 11/3/2026" and the browser "closes 11/2/2026". Every date in the member pages was formatted with `toLocaleDateString()` and no options, so the server (UTC) and the browser (this machine's zone) disagreed about the calendar day of a window that closes shortly after midnight UTC. The working stack had passed the same test all day because its seed had been written at a time of day where both zones agreed. A cold clone seeded at a different hour exposed it.

**Fix.** `web_application/src/lib/federation/format.ts` formats with `Intl.DateTimeFormat` in the page language and in UTC at all six call sites; a server component's history list now receives the language explicitly. The spec `e2e/tests/registration-review.spec.ts` collects hydration warnings, console errors about server and client mismatch, and uncaught page errors, and fails on any of them, so the guard no longer depends on the overlay tripping a contrast rule. Prettier, `tsc` and ESLint clean; both specs rerun green on the clone, then the clone and its volumes were removed and the working stack restored.

**Three lessons.**

1. A cold clone is not a formality. Same code, same tests, different clock: the working stack could not have found this.
2. Anything rendered on both the server and in the browser must be deterministic across machines: locale, time zone, random values and the current time are all inputs. Format with explicit options, or format on the server and pass strings down.
3. When an accessibility scan fails on tooling rather than on the page, read the trace before touching colours. The contrast rule was the messenger.

**Deferred.** Registration windows in a federation-defined time zone rather than UTC display, recorded in `docs/future-work.md`.

## 2026-09-03 — A5: the fork, the first CI run and what it found

**Goal.** Make the repository public as a fork with upstream's history intact, run the CI workflow for the first time, and merge Phase A into `main` through a pull request.

**Done.** `nick-bellows/federation-member-services-lab` is a GitHub fork of `vereinfacht/vereinfacht` (the API reports `fork`, the parent and MIT); `origin` added, `upstream` kept, `main` at the fork point. The milestone branch was pushed, Actions enabled, pull request #1 opened. Two runs failed, three commits fixed them, the third was green on both the push run and the pull-request run, and the merge commit `0bb07f3` is green on `main`.

**Measured on GitHub-hosted runners.**

| Job | Result | Time |
|---|---|---|
| Backend tests, SQLite | 168 passed, 682 assertions | 45 s |
| Backend tests, MariaDB 11.8 | 168 passed, 681 assertions | 184 s |
| Browser journeys with axe | 7 passed, 1 skipped (screenshots, by design) | wait for the API container 9 to 13 s, seed 100 s, Playwright about 70 s |
| Whole workflow, six jobs in parallel | green | 6.6 and 7.1 (the two runs of `99aca82`) minutes wall clock |

**What the first runs found, in order.**

1. The INCIDENT-000 regression test asserted that the test database is SQLite in memory. That is the local configuration, not the invariant; the MariaDB job runs the suite on MariaDB by design and failed on that one assertion (167 of 168). The test now asserts what the incident requires: the connection and database are the ones the testing environment names, never the development database, on any engine.
2. The browser journeys failed at the redirect to the mock OIDC provider with a browser error page. Chromium runs on the runner, not in a container; `extra_hosts` in Compose resolves `host.docker.internal` for containers only. One hosts-file line on the runner fixed it.
3. The same commit then passed its pull-request run and hung its push run for the job's full 45 minutes, right after `composer install`. The API container migrates the empty database at start-up; a `migrate:fresh` from the tooling container at the same moment waits on MariaDB metadata locks, whose default timeout is a day. The pull-request run had won the race. The job now waits for the container's "Starting the app" line before any tooling-side database command, which is the README's own ordering, the silent unbounded readiness loop is gone, and container logs are captured on cancellation as well as failure.

**Three lessons.**

1. A test must assert the invariant, not the local configuration that happens to satisfy it. "SQLite in memory" was a symptom of isolation, not its definition.
2. "It works in Docker" has two sides. Anything that runs on the host, a browser in a CI job included, sees none of Compose's networking.
3. One green run proves nothing about ordering. Two runs of the same commit are the cheapest race detector available, and a silent unbounded loop turns a race into a timeout with no diagnosis. Bound the wait, print what it saw, and capture logs when the job is cancelled, not only when it fails.

**Deferred.** Making the entrypoint's migration opt-in for development and CI (`docs/future-work.md`); the upstream issue and pull request (ADR-0004), which are outward-facing and wait for the owner's go; a short demo.

## 2026-09-03 — B2 (M5): the Learning Center contract

**Goal.** Answer "may this approved person take part" from two systems that share no database: credential facts and eligibility in the Learning Center, applications here. Do it with an executable contract, a service identity, and an honest answer under a slow provider.

**Decisions (owner, at the start).** The contract is keyed by the OIDC subject; the federation calls with a client-credentials service token; participation is derived on read from a stored snapshot with its age. ADR-0009 records the alternatives.

**Built, provider side (`learning-center-reference`, pull request #1, merged).** `GET /v1/members/{subject}/credentials` for tokens carrying the `credentials:read` scope, a service-token verifier path beside the person path, a store query by subject, the expiry rule exposed once (`safeguarding.Current`) and used by both eligibility and the new endpoint, OpenAPI, handler and contract-shape tests, an e2e step, and subjects for the two seeded referees. Built by a delegated agent from the contract document and the fixtures; its four CI jobs were green before the merge.

**Built, consumer side (this repository).** The contract document and four fixtures; a Node mock in Compose that serves the fixtures verbatim and takes a delay; a service-token provider with a cached client-credentials token; an HTTP client with 300 ms connect and 800 ms total timeouts; `credential_snapshots` with one writer; `ParticipationResolver`; the `participation` attribute on registration applications; a `refresh-credentials` action for reviewers (503 with a stable code when the provider is away); a listener that refreshes after approval, best effort; `federation:reconcile-credentials`; seeded personas with their mock subjects; the participation panel on the member and reviewer pages with the reviewer's refresh button, in English and German; two browser journeys extended.

**Evidence.**

| What | Where | Result |
|---|---|---|
| Backend suite | `docs/baseline/phpunit_after_b2_backend.txt` | 202 passed, 861 assertions (34 new) |
| Browser journeys | `docs/baseline/playwright_b2.txt` | 7 passed, participation panel and refresh included |
| Incident 1 rehearsal | `docs/baseline/incident_001_2026-09-03.txt` | refresh under a slow provider: 503 `learning_center_unavailable`; page answers from the snapshot; reconciliation counts the unavailable and repairs after recovery; changes audited |
| Client timeout | same log, measured from both containers | cut at 802 ms |
| Provider | `learning-center-reference` pull request #1 | api, e2e, oidc-e2e, web green; merged |

**What went wrong, in order.**

1. The HTTP fake keeps the first stub registered for a URL, so a second fake in the same test never applied. Same trap as in M3. One fake per test now reads its scenario from properties.
2. Counting "requests sent" counted the test issuer's discovery and key-set fetches for the personas' own tokens. The assertion now counts provider requests only.
3. The working stack's untracked env file had no client secret, so the token endpoint answered 401 and the approval listener logged "unavailable" on every approval. The example env had it; the running stack did not.
4. A root-owned log file shared across the api and tooling containers made every request that tried to log answer 500, which looked like an authorisation bug until the error body was read. Tests log to the null channel now; the file was made writable; a per-process log path is future work.
5. A rehearsal script measured the timeout through the API and saw two seconds; measured at the client it was 802 ms. The difference was this development stack's own latency.
6. A probe used a persona the mock provider does not know, and its 401 was hidden behind the log failure above. Two faults, one symptom.

**Three lessons.**

1. A contract is fixtures both sides execute, not a document both sides read. The provider's tests assert shape equality against copies that name this repository as the source of truth; a renamed field fails there first.
2. A service needs its own identity. The reviewer's queue and the nightly job have no applicant behind them, so the applicant's token was never an option.
3. Under a slow dependency the honest answer is the last one, with its age. Reads never wait; the one path that does wait says so with a code and a sentence.

**Deferred.** Scheduling and alerting for reconciliation (B5), retry with jitter and the outbox for `credentials.changed` (B3), a per-process log path, the shared-issuer case across both stacks (the demo namespaces differ until Auth0 at B9).

## 2026-09-03 — B3 (M6): events and reliability

**Goal.** Make side effects survive the process that caused them: facts durable with the state change, delivered at least once, acted on exactly once, retried, parked visibly, replayable. Turn B2's best-effort credential refresh into the real failing path of Incident 3.

**Decisions (owner, at the start).** Outbox plus Laravel's database queue; a processed-events ledger per consumer; four facts and two consumers. ADR-0010 records the alternatives and the mapping to SQS, RabbitMQ and Kafka.

**Built.** `outbox_events`, `processed_events` and `federation_notifications`; `OutboxRecorder` (refuses to run outside a transaction); facts written by `TransitionApplication` and `CredentialSnapshots`; `federation:outbox-relay` (one job per event and consumer, row lock, refuses the sync driver); `ProcessOutboxEvent` (insert-or-ignore ledger inside the consumer's transaction, four tries, backoff 2/10/60 s, attempts and errors mirrored on the row, parked on final failure); consumers `notifications` and `credential-refresh`; `federation:work` (relay + drain, survives a bad pass), `federation:outbox-status` (exit code for schedulers), `federation:outbox-replay`. The synchronous approval listener is gone. The development queue is the database driver; the worker runs in the api container as the PHP-FPM user, in Compose and in the CI browser job.

**Evidence.**

| What | Where | Result |
|---|---|---|
| Backend suite | `docs/baseline/phpunit_after_b3_backend.txt` | 210 passed, 918 assertions (8 new) |
| Browser journeys | `docs/baseline/playwright_b3.txt` | 7 passed with the worker delivering |
| Incident 3 rehearsal | `docs/baseline/incident_003_2026-09-03.txt` | notification written 1 s after approval; refresh retried through 2/10/60 s and parked at attempt four with the consumer named; status exit non-zero; one replay after recovery delivered it; the ledger kept the notification single |

**What went wrong, in order.**

1. The first rehearsal exercised nothing: the API container still ran the cached `sync` driver, so the relay's dispatch ran the consumers inside the relay's transaction; the refresh timed out, the exception unwound the publication, and the worker loop died. Hardened: the relay refuses the sync driver; the loop survives one bad pass; the container was restarted so the environment applied.
2. The worker started in the tooling container, then as `www-data` in the api container. Each left cache and log files the web process could not write; the pages answered 500 and looked broken for application reasons. The FPM user is `verein`; the worker runs as it. INCIDENT-000, INCIDENT-001 and this are one family: two writers on one bind-mounted storage directory.
3. The detached worker died silently because its log file in the container's temp directory belonged to the earlier attempt; it logs under the storage directory now.
4. A brand-new identity's first page fans out into parallel API requests; both provisioned the user and one hit the unique email key. Provisioning is create-or-first now, audited once.
5. Two `expectsOutputToContain` on one output line fail in Laravel's command tests, which match expectations in order against separate writes. One expectation per line.
6. Attempts on the outbox row count every consumer's try; the assertion expected only the failing consumer's four.

**Three lessons.**

1. "After commit" is not "durable". The process is part of the failure model; the outbox takes it out.
2. Idempotency is a table, not an intention. The ledger row commits with the effect; everything else is hope.
3. A daemon's environment is part of its correctness: the queue driver it was started with, the user it runs as, the file it logs to. Each of those failed once today before the design did.

**Deferred.** Scheduling and alerting (B5); a notifications surface; per-consumer attempts; a broker adapter behind the relay (B8); the worker as a Compose service once the entrypoint's migration is opt-in.

## 2026-09-03 — B4 (M7): PostgreSQL through a compatibility matrix

**Goal.** Make "runs on PostgreSQL" a tested claim: the whole suite and the demo seeder on PostgreSQL 16 in CI, upstream's MySQL-only SQL made portable where cheap, every difference written down.

**Decisions (owner, at the start).** A compatibility matrix with MariaDB still the default; fix in place where a portable form is cheap and behaviour-preserving, isolate only where it is not. ADR-0011 records the alternatives.

**Built.** `backend-postgres` CI job (suite plus `NorthgateDemoSeeder`, `pdo_pgsql` added to the extension list); `pdo_pgsql` in the tooling and api images; an optional `postgres` Compose service under a profile; `App\Support\OrderByIdList` replacing `FIELD(id, …)` in seven export actions, with `ExportOrderTest` on all engines; `CAST(… AS INTEGER)` in the membership fee scope (probed on MariaDB 11.8 first); the double-quoted literal in a 2025 migration replaced by the query builder; `docs/DATABASE_COMPATIBILITY.md` with the constructs, treatments, evidence and the differences that are not bugs.

**Evidence.**

| What | Where | Result |
|---|---|---|
| SQLite suite after the fixes | `docs/baseline/phpunit_after_b4_backend.txt` | 212 passed, 921 assertions (2 new) |
| PostgreSQL suite, local | `docs/baseline/phpunit_after_b4_postgres.txt` | in progress at commit time: 35 tests in 11 minutes, about 19 s each, because upstream's test base runs `migrate:fresh` with 81 migrations per test and PostgreSQL DDL in Docker Desktop is not free; the file is retained when the run ends |
| PostgreSQL suite, CI | `backend-postgres` job on pull request #7 | the matrix's evidence; recorded below when the run completes |

**What went wrong, in order.**

1. The export-order test failed at teardown, not in the assertion: the per-test rollback runs an upstream migration's `down()` that makes `membership_type_id` NOT NULL again, and the membership factory sets no type. Upstream's own tests always create a type; the fixture now does too.
2. The local PostgreSQL run is slow for the same reason the MariaDB run is: `DatabaseMigrations` per test. The CI runner does it in minutes; the laptop in an hour. Recorded, not hidden; `RefreshDatabase` for the fork's tests stays in future work.
3. The first PostgreSQL job on GitHub: 209 passed, 3 failed, all three upstream club-admin tests comparing a JSON column to an encoded string in `assertDatabaseHas`. MariaDB and SQLite compare as text; PostgreSQL's `json` type has no equality operator. The assertions now compare translations through the model, which is what they meant. Four minutes on the runner for the whole suite, against an hour locally.

**Three lessons.**

1. A dialect is a set of things the other engines let you get away with. Only the strict engine finds them, so it has to be in CI, not in a document.
2. Fix in place is a contribution; isolate is a maintenance debt. Choose per construct and write down which.
3. A test that fails at teardown is still telling you about your fixture.

**Deferred.** `CHECK (amount >= 0)` per engine for money columns; the default engine question until B8; the portability fixes travel with the upstream offer at B9.

## 2026-09-03 — B5 (M8): operability

**Goal.** Make the system answer "what is it doing, is it healthy, where did the time go" without reading code, and prove the answers against the three rehearsed incidents.

**Decisions (owner, at the start).** OpenTelemetry traces to a local Jaeger; JSON logs to stderr with request, user, trace and span context; liveness, readiness and metrics endpoints. ADR-0012 records the alternatives.

**Built.** `TraceRequest` (server span, shared log context, one access line per request); spans around the transition, each outbox job (continuing the request's trace through `traceparent` stored on the row) and the provider call (header propagated); the tracer provider built from configuration (`otlp`, `memory`, `none`); the `json` log channel with a trace-id processor and PHP-FPM forwarding worker output; `Readiness` (database and outbox age required, the Learning Center reported), `Metrics` (nine gauges from the tables), `/api/health/live`, `/api/health/ready`, `/api/health/checks` (upstream's spatie checks, result store moved from memory to the file cache), `/api/metrics` with an optional token; a Jaeger container; `docs/OBSERVABILITY.md` and `docs/RUNBOOK.md`.

**Evidence.**

| What | Where | Result |
|---|---|---|
| Backend suite | `docs/baseline/phpunit_after_b5_backend.txt` | 220 passed, 961 assertions (10 new, including one trace across approval, worker and provider) |
| Browser journeys | `docs/baseline/playwright_b5.txt` | 7 passed |
| Incidents against the signals | `docs/baseline/operability_2026-09-03.txt` | slow provider: readiness `ready` with `learning_center: degraded`, the refresh's 503 as one access line and a two-span error trace; worker failure: `federation_jobs_queued 1` through the backoff, then `federation_outbox_parked 1` with the consumer named, cleared by one replay; upstream's eleven checks readable through the endpoint with the development failures explained |

**What went wrong, in order.**

1. An unqualified `PsrLogMessageProcessor::class` in the logging config resolved to a global-namespace string, the `json` channel could not be built, and Laravel fell back to the emergency file logger without a visible error. Found by looking for the access line and reading `laravel.log`.
2. PHP-FPM does not forward worker output to the container log unless `catch_workers_output` is set; the api image sets it now, undecorated.
3. The api image would not rebuild: the storage symlink that `storage:link` creates in the public directory is unreadable to BuildKit on this filesystem. It is excluded from the build context.
4. Upstream's health result store is in memory, so a routed endpoint could never see results; the store is the file cache now, which works because the checks and PHP-FPM run as the same user.
5. The probe test for "unknown" results read the running stack's results through the shared file cache; the test environment uses the array store.
6. My Dockerfile edit was written with real newlines by the scripting shell and broke the parse; the Edit tool wrote it literally.

**Three lessons.**

1. A log channel that fails to build fails silently into a file nobody reads. The first thing to verify about structured logging is that one line actually arrives where it should.
2. A trace across a queue is a stored parent context, not magic; the outbox row was the right place for it because the row already outlives the request.
3. Readiness is a routing decision, not a health opinion. It fails on what the pages need and reports the rest.

**Deferred.** Scheduling and alert delivery (B8); auto-instrumentation; a development profile for upstream's checks.

## 2026-09-03 — B6 (M9): accessibility and performance

**Goal.** A manual WCAG 2.1 AA review of the slice, a low-bandwidth pass, and synthetic load on three endpoints with retained before-and-after numbers; the missing indexes as the first finding.

**Decisions (owner, at the start).** k6 in Docker; single-column indexes, measured; a manual review of the slice. ADR-0013 records the alternatives.

**Built.** `PerformanceSeeder` (30,000 synthetic members in 19 s); `perf/k6/federation.js` with three scenarios and retained JSON summaries; a migration adding five indexes (`club_id` on four upstream tables, `membership_id` on members) with a guard test on every engine; the memberships listing eager-loaded (`withCount`, the fee subselect, two relations) with a query-count guard; the API rate limit as configuration; `docs/PERFORMANCE.md`; `e2e/tests/accessibility-review.spec.ts` (keyboard walk, focus visibility, best-practice axe, slow-3G timing); `docs/ACCESSIBILITY.md` per criterion.

**Evidence.**

| What | Where | Result |
|---|---|---|
| Query plans | `docs/baseline/perf_explain_before.txt`, `perf_explain_after.txt` | full scans of 10,613 and 32,020 rows → index lookups of 510, 1 and 1,510 |
| Query count, page of 20 memberships | `perf_query_count_before.txt`, `perf_query_count_after.txt` | 89 → 11 |
| k6, memberships listing (10 users, 30 s) | `perf_before.json`, `perf_after_indexes.json`, `perf_after_eager.json` | p50 504 → 340 → 211 ms; p95 663 → 482 → 333 ms; 0 % failures |
| k6, federation endpoints | same files | unchanged within noise (p95 about 320 to 370 ms), as expected |
| Accessibility walk | `a11y_review_2026-09-03.txt` | every page reachable, zero focus stops without an indicator, best-practice scan clean |
| Slow 3G | same file | development server 42.6 s and 2.06 MiB; production first-load JavaScript 92 to 100 kB per page |
| Suite | `phpunit_after_b6_backend.txt`, `playwright_b6.txt` | 222 passed; 7 passed |

**What went wrong, in order.**

1. The first load run failed four requests in five: upstream's 60-per-minute-per-user limit, applied to the federation routes as well. The limit is a config key now, raised for the window and restored; the discarded run is noted in the document.
2. The k6 container received Git-Bash-style volume paths and wrote nothing; Windows paths with path conversion disabled fixed it.
3. The login token lives in the response's `meta`, not in the resource attributes; read the response before scripting against it.
4. The API container applies pending migrations on every restart, so the index migration had to step aside while the baseline was captured.
5. `next build`, run for the bundle sizes, wrote into the `.next` directory the running dev server serves from and broke sign-in for every journey until the directory was cleaned and the server restarted. Recorded in future work.
6. The dev server's slow-3G number (42 s) is not the product's; the production build's sizes are, and the document says which is which.

**Three lessons.**

1. Measure through the same path users take and read the failure rate first; a load run with a 79 % failure rate measures the rate limiter, not the code.
2. A fix without a guard is a rumour; the index test and the query-count test are what make the numbers durable.
3. Automated accessibility is the floor; the walk and the per-criterion record are the review. Say what was not done (a screen reader by ear).

**Deferred.** A skip link, per-page titles and described transition buttons (B9); a production frontend measurement (B8); composite indexes if a plan asks for them.

## 2026-09-03 — B7: security review

**Goal.** A threat model covering the brief's list, JSON Patch on one resource with field-level authorization, and the operator surfaces reviewed as part of the model.

**Decisions (owner, at the start).** Attack trees rather than a STRIDE table; a dedicated `-actions/fields` route for RFC 6902 rather than overloading the JSON:API update; the scrape token on by default for checks and metrics. ADR-0014 records the alternatives.

**Built.** `docs/THREAT_MODEL.md` (assets, actors, entry points; six trees; a legend that separates mitigated, partly, open, upstream and dev-only; the two audits classified by reachability; an update policy for B8); `JsonPatch` (four operations, one-level paths, whole-document refusal); `PatchApplicationFields` (authorise every operation before applying any, one transaction with the row locked, `test` as the stale-view guard, one audit entry with previous and new values); `reviewer_notes` with a reviewer-only attribute; `/api/health/checks` behind `METRICS_TOKEN`, shipped set in `.env.example`; `SecretsNeverLoggedTest`; the OpenAPI document and the generated types regenerated (they had not been since M4, so `participation` and `history` arrived with `reviewerNotes`).

**Evidence.**

| What | Where | Result |
|---|---|---|
| Field-level authorization over HTTP | `ApplicationFieldsPatchHttpTest` | 11 tests: allowed paths, atomic refusal with the path in `meta`, reviewer notes hidden from the applicant, 409 after submission, `test` mismatch, `remove`, validation, 415, four malformed documents, the JSON:API update cannot write reviewer notes |
| No token in logs or spans | `SecretsNeverLoggedTest` | success, 401 and provider paths: no person token, service token, client secret or JWT shape in any log line or span attribute |
| Token gate | `ObservabilityHttpTest` | checks and metrics 401 without the token, 200 with it; live and ready open; `.env.example` sets one |
| Live walk | `docs/baseline/security_review_2026-09-03.txt` | the same on the running stack, plus the access line for the refused request and zero token hits over 4,800 access lines and the recorded traces |
| Dependency audits | `docs/baseline/security_audit_2026-09-03.txt` | Composer: 13 advisories in 3 upstream packages; npm: 8 (7 high, 1 critical) in the frontend, 1 in the API's build tooling; none patched here |
| Suite | `phpunit_after_b7_backend.txt`, `playwright_b7.txt` | backend: see the file; browser: 10 of 11 passed, the sign-out redirect in the sign-in journey timed out at 10 s while the backend suite ran on the same machine and passed on an immediate rerun (both recorded) |

**What went wrong, in order.**

1. The first draft of the patch action passed the application's status enum to an exception whose constructor takes a message string; a type error waiting for the first locked applicant. Caught on reading the constructor, not by a test: the lesson is to read the signature before the call, and the test for the 409 now exists.
2. A test helper named `patch()` collided with Laravel's own `TestCase::patch()` and PHP refused the narrower visibility; renamed. Frameworks own more names than one remembers.
3. The mock provider's token endpoint pretty-prints its JSON and signs the issuer with the host the request used, so a `curl` to `localhost` yields a token the API rejects and a one-line `sed` matched nothing; the walk asks `host.docker.internal` and matches the spaced key. Both are the kind of detail a runbook has to carry.
4. `npm audit` hung for five minutes against the registry on the first try and answered in ninety seconds on the second; the audit file records the second run. A dependency audit needs the network, so a release checklist has to allow for that.
5. The generated OpenAPI document showed a four-hundred-line diff for a one-path change: the generator samples examples from the live seed, so ids and timestamps churn on every run. Accepted, and noted so the next reviewer does not look for a regression in it.

**Three lessons.**

1. Authorise the whole patch before applying any of it, and make the refusal name the path; a partially applied patch is the worst outcome for both the client and the audit trail.
2. A threat model earns its keep when each leaf points at a test; the trees are only as honest as the evidence column, and "partly" is a legitimate marker.
3. Public surfaces are chosen, not discovered: the probes stay open because a platform needs them, the checks close because they describe the system. Write down the reason for each.

**Deferred.** The major upgrades the audits ask for (Next 16, `sharp`, `swiper`), a write-once audit table at the database, SHA-pinned actions and digest-pinned images, a tag for the Swagger UI image, and the upstream findings for the B9 offer.
