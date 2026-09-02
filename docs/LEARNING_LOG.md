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
