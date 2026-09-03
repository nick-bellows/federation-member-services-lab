# INCIDENT-000 — Development database wiped by the test suite

- **Date:** 2026-09-02, during Milestone 3
- **Environment:** local development stack (Docker Desktop, compose project `vereinfacht`)
- **Impact:** the seeded MariaDB development database, including the Northgate demo data and every issued API token, was dropped and partially re-migrated. No production or shared system involved; rebuild took one seed run.
- **Severity for the project:** medium. Nothing was lost that cannot be regenerated, but the same mechanism in a shared environment would have been a data-loss incident.

## Symptoms

The API container's start-up log showed an upstream migration from 2023 failing with "table already exists" a few seconds after a restart. Moments later the development database had 27 tables instead of 42, the `federations` table did not exist, and the background test run was still executing.

## Detection

Manual: the failing migration in `docker compose logs api` was the trigger; a row-count query confirmed the loss. Nothing automated would have caught it; the test run itself reported nothing wrong because each test creates the schema it expects.

## Timeline

1. `php artisan test` was started in the `tooling` container in the background, after `config:clear`.
2. Seconds later the `api` container was restarted to load new environment variables. Its entrypoint (`docker/api/entrypoint.sh`) ran `config:cache`, `route:cache` and `event:cache`, writing `bootstrap/cache/*.php` onto `./api`, which both containers bind-mount.
3. The next test to boot found `bootstrap/cache/config.php`. Laravel skips loading `.env.testing` when a config cache exists, so that test's configuration said `DB_CONNECTION=mysql`, database `verein`.
4. `tests/TestCase.php` uses `DatabaseMigrations`, which runs `migrate:fresh` before every test. It dropped every table in the development database and started re-migrating.
5. The API container's own `migrate --force`, running concurrently in its entrypoint, collided with the re-migration and failed. The test run was killed by hand; the database was left half-migrated.

## Root cause

Two processes shared one filesystem and one of them wrote build-time caches that change how the other boots. The hazard was known and documented since Milestone 0 (`docs/UPSTREAM_ANALYSIS.md` §11, "config-cache trap") and mitigated only by a manual rule, "run `config:clear` before tests". A manual rule does not survive concurrency: the cache appeared *after* the rule had been followed.

## Mitigation (immediate)

Killed the test runner, cleared the caches, rebuilt the database with `migrate:fresh --seeder=NorthgateDemoSeeder`, re-issued the super-admin token for the Next app, restarted the API with no tests running.

## Permanent fix

`api/phpunit.xml` now sets `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE`, `APP_EVENTS_CACHE`, `APP_SERVICES_CACHE` and `APP_PACKAGES_CACHE` to testing-specific paths that never exist. Laravel resolves every cache lookup through these variables, so a test process boots from source files and `.env.testing` no matter what the API container has written. The manual rule is no longer needed.

## Regression test

`api/tests/Unit/TestEnvironmentIsolationTest.php` asserts that the application under test reports the testing cache paths, that no cache is considered present, and that the connection is SQLite in memory. If someone removes the `phpunit.xml` lines, the first assertion fails.

## What would have prevented it earlier

Treating the Milestone 0 finding as a defect to fix rather than a rule to remember. The roadmap listed it as the second candidate for the Milestone 1 fix; it should have been done together with the first.

## Follow-ups

- Upstream candidate: the same `phpunit.xml` change is generic and harmless; offer it with the `env()` fix (ADR-0004).
- Consider making the API entrypoint write caches only when `APP_ENV=production`, so development containers never cache at all.
