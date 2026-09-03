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
