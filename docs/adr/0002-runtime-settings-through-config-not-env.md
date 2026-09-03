# ADR-0002: Read runtime settings through config(), never env() outside config/

- **Status:** accepted
- **Date:** 2026-09-02
- **Milestone:** M1

## Context

Four places in the inherited API read environment variables directly with `env()` at runtime: `App\Models\Club::applyUrl`, `App\Providers\HealthCheckServiceProvider`, `App\Providers\AppServiceProvider` (password-reset link) and `App\Mail\WelcomeClubAdminMailable`. Laravel's `env()` only sees the `.env` file while configuration is **not** cached; once `php artisan config:cache` has run, the `.env` file is no longer loaded and `env()` returns `null` for anything not exported by the process. The API container caches configuration on every start (`docker/api/entrypoint.sh`).

The consequence was reproduced on the running container before the change (`docs/baseline/env_bug_before_fix.txt`): with `WEB_APPLICATION_URL=http://localhost:3000` in `.env`, a club's public apply URL, the welcome mail's login link, the password-reset link and both health-check pings resolved to the hard-coded fallback, the upstream company's production domain. The four fallbacks also disagreed with each other (`http://` versus `https://`).

For this fork the bug matters twice: every environment the federation lab runs in will cache configuration, and the same pattern would otherwise be copied into the new federation code.

## Decision

- Two configuration keys are added to `config/app.php`: `app.web_application_url` (from `WEB_APPLICATION_URL`, default `https://app.vereinfacht.digital`) and `app.club_admin_login_path` (from `CLUB_ADMIN_LOGIN_PATH`, default `/admin/login`). The mail sender address is read from the existing `mail.from.address`.
- The four call sites read `config()` only. No behaviour changes when configuration is not cached; when it is cached, the values now follow `.env` as intended.
- Rule for all future code in this repository: `env()` is used only inside `config/*.php`. Pint cannot enforce this; a grep in CI will (`grep -rn "env(" api/app api/routes api/database api/resources` must return nothing).
- Regression tests in `api/tests/Unit/WebApplicationUrlConfigTest.php` set the config keys and assert the produced URLs; they failed before the change (`docs/baseline/env_fix_tests_before.txt`) and pass after it (`docs/baseline/env_fix_tests_after.txt`).

## Alternatives considered

1. **Leave the code and stop caching configuration in the container** — hides the defect instead of fixing it; production deployments cache configuration for good reasons.
2. **Export the variables into the process environment in the entrypoint** — works around `env()` rather than removing the misuse, and every new variable would need the same treatment.
3. **A single `config/web_application.php` file** — cleaner for many keys; two keys next to `app.url` and `app.asset_url` is the smaller, more conventional change and matches upstream's layout.

## Consequences

- Positive: URLs in mails, health checks and the API follow the environment in every deployment mode; the tests document the contract; the change is five lines of production code and one config block, small enough to offer upstream.
- Negative: two more configuration keys to document in `.env.example` (already present as variables there); none otherwise.
- Follow-ups: offer the change upstream once the CI contribution is discussed with the maintainers (ADR-0004 will record the outcome); add the `env()` grep to CI.
