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

**Built.** Backend: `config/oidc.php`, the `oidc` request guard in `App\Federation\FederationServiceProvider`, `OidcTokenVerifier` (JWKS discovery, cache, one refresh on an unknown key id, issuer, audience, subject), `OidcUserResolver` (known subject, link by verified e-mail, provision), `FederationScopes`, `GET /api/v1/federation/me`, `users.oidc_issuer`/`oidc_subject`. Frontend: next-auth providers `northgate-id` and `auth0`, the access token kept server-side in the encrypted cookie, `/member/sign-in` and `/member` pages, middleware protection, en/de translations. Stack: `oidc` service (`mock-oauth2-server`) with personas in `docker/oidc/config.json`. Tests: 20 PHPUnit tests for the verifier and guard with an in-test RSA issuer, 3 Playwright tests including axe scans. ADR-0007.

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
