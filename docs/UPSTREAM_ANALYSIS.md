# Upstream analysis — vereinfacht as inherited

Milestone 0 deliverable. This document describes the upstream application **as found**, before any modernization. Every claim cites a file path; numbers in section 5 and section 11 come from running the code on the machine described there. Nothing here has been changed in the code yet.

## 0. Provenance

| | |
|---|---|
| Upstream | https://github.com/vereinfacht/vereinfacht — MIT, © 2025 visuellverstehen GmbH (`LICENSE`) |
| Analysed at | `dca9be3` — 2026-08-13, "fix: password reset tests, refs #269 (#270)", 127 commits on `main` |
| Upstream state | 1 release (`0.1.0`, 2026-02-10); `publiccode.yml` says `developmentStatus: development`; 41 open issues; 7 contributors, all from one company; no external pull request merged to date |
| This fork | created after this document existed (see `docs/adr/0001-fork-strategy.md`); fork base commit = `dca9be3` |

What the fork added is always visible with:

```sh
git log --oneline upstream/main..main      # commits that are not upstream's
git diff --stat upstream/main              # files that differ from upstream
```

## 1. Architecture

### 1.1 Repository layout

Monorepo with two deployable applications and the tooling to run them:

| Path | What it is |
|---|---|
| `api/` | Laravel 13 (PHP ^8.3) JSON:API backend, Filament 5 admin panel, banking-statement import, media handling |
| `web_application/` | Next.js 14.2 App Router frontend (TypeScript, Zod 4, Tailwind 4, shadcn/Radix), club management UI and public application form |
| `docker/` | `api.Dockerfile` (php-fpm + nginx, dev-oriented), `tooling.Dockerfile` (php-cli + node 20 + composer), `web_application.Dockerfile` (multi-stage standalone Next build) |
| `docker-compose.yml` | Development stack only (header comment says so) |
| `.github/workflows/` | Release automation only (section 6) |
| `cliff.toml`, `CHANGELOG.md`, `RELEASE.md`, `publiccode.yml` | Conventional-commit changelog, release process, public-sector software metadata |

Counts at `dca9be3`: 208 PHP files under `api/app`, 69 migrations, 28 PHPUnit test files, 422 TypeScript files under `web_application/src`, zero frontend or end-to-end test files.

### 1.2 Runtime topology (development compose)

| Service | Image / build | Host port | Role |
|---|---|---|---|
| `database` | `mariadb:11.8` | 3306 | single database `verein` |
| `api` | `docker/api/api.Dockerfile`, bind-mounts `./api` over the image copy | 3001 | nginx + php-fpm; Filament at `/admin`, JSON:API at `/api/v1`, OpenAPI document at `/v1_openapi.json` |
| `api-docs` | swagger-ui | 3002 | renders `api/public/v1_openapi.json` |
| `web_application` | `docker/web_application/web_application.Dockerfile` | 3003 | production Next build |
| `tooling` | `docker/tooling/tooling.Dockerfile`, bind-mounts the whole repo | 3000 | where composer, artisan, npm and `next dev` run |

The `api` container's entrypoint (`docker/api/entrypoint.sh`) runs `storage:link`, clears and re-caches config/routes/views/events, waits for MariaDB with `nc`, then runs `migrate --force` on every start, with no `set -e`.

### 1.3 Three user-facing surfaces

1. **Filament admin panel** (`api/app/Providers/Filament/AdminPanelProvider.php`): session (`web` guard), tenant model `Club`, accessible only to super admins (`User::canAccessPanel`). Tenant isolation via `App\Http\Middleware\ApplyTenantScopes`, which adds `whereBelongsTo(tenant)` scopes to `MembershipType`, `Membership`, `Division`.
2. **Club management** (`web_application/src/app/[lang]/admin/(secure)/**`): Next.js pages and server actions for club admins; authentication through next-auth Credentials provider (`web_application/src/utils/auth.ts`) that calls `POST /api/v1/users/-actions/login` and keeps the returned Sanctum token in the next-auth JWT cookie.
3. **Public membership application** (`web_application/src/app/[lang]/[slug]/apply`): anonymous multi-step form per club slug; the Next server talks to the API with a long-lived super-admin token from `API_BEARER_TOKEN` (`web_application/src/services/club-api.ts`).

### 1.4 Backend shape

- **Framework skeleton predates Laravel 11** although the framework is 13: `api/bootstrap/app.php` binds `App\Http\Kernel` and `App\Console\Kernel`; middleware groups live in `api/app/Http/Kernel.php`; routes are registered by `api/app/Providers/RouteServiceProvider.php` (prefix `api`, rate limiters `api` 60/min and `auth` 5/min).
- **JSON:API** through `laravel-json-api/laravel` ^5: one server `App\JsonApi\V1\Server` (`$baseUri = '/api/v1'`), 18 schemas, resources registered in `api/routes/api.php`. Most resources use the package's generic `JsonApiController`; `MembershipController`, `UserController`, `MediaController`, `StatementController` add custom actions.
- **Use-case classes** in `api/app/Actions/**` (`ApplyMembershipAction`, `User\Login`, statement import/export). One repository (`api/app/Repositories/UserRepository.php`). Banking statement parsing under `api/app/Services/Statement` (CAMT, MT940).
- **Authorization** with `spatie/laravel-permission` in teams mode (`api/config/permission.php`: `'teams' => true`, team key `club_id`) and Eloquent policies in `api/app/Policies`.
- **Tenant isolation for the API** via `App\Models\Scopes\ClubScope`, applied as a global scope to 13 models in `Server::addClubScope()`.
- **Events**: one domain event (`App\Events\AppliedForMembership`) with one queued listener (`SendApplicationNotifications`); queue driver `sync` in `.env.example`. Two jobs (`CleanupMedia`, `SendWelcomeEmailToClubAdmin`). Scheduler: `sanctum:prune-expired` daily, spatie health heartbeat/queue checks every minute (`api/app/Console/Kernel.php`).
- **Health**: 11 spatie health checks configured in `api/app/Providers/HealthCheckServiceProvider.php`; **no HTTP route exposes them** (`api/routes/web.php` only redirects `/` to the Filament login).
- **Errors**: `api/app/Exceptions/Handler.php` renders exceptions as JSON:API error documents via the package's `ExceptionParser`; `JsonApiException` is not reported.

### 1.5 Frontend shape

- App Router with a `[lang]` segment (`en`, `de`); `web_application/src/middleware.ts` redirects to a localized path, then applies next-auth session checks for `/admin` paths (`src/middlewares/auth.ts`, `src/middlewares/localization.ts`).
- **Two API client generations coexist.** The newer path: `src/lib/api/server-client.ts` builds an `openapi-fetch` client typed from `src/types/schema_v1.d.ts` (generated by `npm run generate-schema` from `http://api/v1_openapi.json`), used by server actions under `src/actions/**` with per-resource Zod schemas (`*.schema.ts`). The older path: `src/services/json-api.ts` (`jsonapi-fractal` serialize/deserialize, hand-built fetch) with `ApiEndpoints`, `ClubApi`, `AdminApi` subclasses, still used by the public application form and by next-auth login.
- Route handlers under `src/app/api/**` proxy media download/preview, uploads and exports, and host next-auth.
- Styling: Tailwind 4 via `@tailwindcss/postcss` (no `tailwind.config`), shadcn/ui components (`components.json`).

## 2. Request flow — the public membership application

The request traced for the Milestone 0 lesson is the anonymous application at `/{lang}/{club-slug}/apply`, submitted on the summary tab. It was chosen because it is the closest inherited analogue to the future federation registration workflow and because it crosses every layer.

| # | File | What happens there |
|---|---|---|
| 1 | `web_application/src/middleware.ts` | Locale prefix check; `/de/tsv-muster/apply` already has a locale and is not an `/admin` path, so it passes through. |
| 2 | `src/app/[lang]/[slug]/apply/page.tsx` → `src/actions/fetchClubDataOrFail.ts` | Server component loads the club by slug via `clubApi.findClubBySlug` (`GET /api/v1/clubs?filter[slug]=…&include=…`), cached by Next's data cache for 5 minutes (`json-api.ts` default `revalidate`). `notFound()` if absent. |
| 3 | `src/app/[lang]/[slug]/apply/components/SummaryForm.tsx` | Client component posts the accumulated form state to the sibling route `…/create-membership`. |
| 4 | `src/app/[lang]/[slug]/create-membership/route.ts` | **Orchestration in a route handler**: `POST memberships` → `POST members` for every person (`Promise.all`) → `PATCH memberships/{id}` to set the owner → `POST memberships/{id}/-actions/apply`. A `ValidationError` becomes a 422 with the JSON:API errors; any other failure becomes `NextResponse.error()`. There is no compensation for a failure after the first call. |
| 5 | `src/services/club-api.ts` → `api-endpoints.ts` → `json-api.ts` | Bearer `API_BEARER_TOKEN` (super admin), JSON:API content types, `Accept-Language` from a module-level singleton whose locale is mutated per request. Base URL `API_DOMAIN + API_PATH` = `http://api/api/v1` inside compose. |
| 6 | `docker/api/nginx.conf` → `api/public/index.php` → `api/bootstrap/app.php` → `api/app/Http/Kernel.php` | nginx `try_files` to `index.php`; global middleware (`TrustProxies`, `HandleCors`, `TrimStrings`, …); `api` group = `throttle:api`, `ClubPermission`, `SubstituteBindings`. Sanctum's stateful-frontend middleware is commented out, so this is pure bearer-token auth. |
| 7 | `api/app/Providers/RouteServiceProvider.php` → `api/routes/api.php` | `/api` prefix; `JsonApiRoute::server('v1')->prefix('v1')` registers the resources; `memberships` gets the custom action `-actions/apply` with an id. Note: the JSON:API group has **no `auth:sanctum` middleware**; only the custom REST group (`upload/media`, `import/statements`, exports) does. |
| 8 | `api/app/Http/Middleware/ClubPermission.php` | Resolves the token owner via the `sanctum` guard; for a `User`, the permissions team id becomes the user's **first** club (`User::getDefaultClub`); for a `Club` token, the club itself. Aborts 403 if no club. |
| 9 | `api/app/Http/Middleware/ChangeLocaleFromHeader.php` | Exact-match `Accept-Language` against `config('app.supported_locales')` (`['en', 'de']`); `en-US,en;q=0.9` does not match (upstream issue #125). |
| 10 | `api/app/JsonApi/V1/Server.php` | `serving()`: `Auth::shouldUse('sanctum')`, attaches `ClubScope` to 13 models, registers `saving` hooks that call `handleClubAssociation()` for six models and media-attachment hooks. |
| 11 | `api/app/Models/Scopes/ClubScope.php` | Guest → `take(0)` (zero rows, so route-model binding 404s); super admin → unscoped; otherwise filter by the permissions team id. The `instanceof Club` branch assigns `$clubId` and is overwritten on the next line; correct only because step 8 already set the team id for club tokens. |
| 12 | `api/app/JsonApi/V1/Memberships/MembershipSchema.php`, `MembershipRequest.php` | Field definitions (including two computed read-only fields evaluated per row: `monthlyFee`, `membersCount`) and validation for the preceding `POST`/`PATCH`. The IBAN rule is an unanchored regex. `members` min/max come from the membership type at validation time. |
| 13 | `api/app/Policies/MembershipPolicy.php` (auto-discovered by name), `api/app/Providers/AuthServiceProvider.php` | `create` requires the `create memberships` permission; `Gate::before` grants everything to super admins. The custom `apply` action performs no policy check of its own. |
| 14 | `api/app/Http/Controllers/Api/V1/MembershipController.php::apply` | Implicit binding of `{membership}` (through `ClubScope`), then `ApplyMembershipAction`; **every `Throwable` becomes a 422 whose detail includes the exception message**. |
| 15 | `api/app/Actions/Membership/ApplyMembershipAction.php` | Invariants (status must be null, owner must exist, member count within type limits), then three writes — members → `inactive`, owner → `inactive`, membership → `applied` — and `AppliedForMembership::dispatch`. **No database transaction.** `members()->count()` is executed twice. |
| 16 | `api/app/Models/Membership.php`, `api/app/Enums/MembershipStatusEnum.php`, migrations `2023_03_01_103955_create_memberships_table.php`, `2023_06_20_125253_alter_memberships_table_add_status_column.php` | `status` is a nullable string column; the enum (`applied`, `active`, `cancelled`) is enforced only in the request rule, never in the schema or the model. |
| 17 | `api/app/Providers/EventServiceProvider.php` → `api/app/Listeners/SendApplicationNotifications.php` | Listener implements `ShouldQueue`, but the queue is `sync`, so notifications are sent inside the request. It returns early when the club's title is literally `"TSV Muster"` (the demo club). A mail failure here surfaces as the 422 from step 14 *after* the three writes have already been committed. |
| 18 | Response | `DataResponse` (JSON:API document) → route handler returns `''` → `SummaryForm` navigates to `/{lang}/{slug}/success`. |

Test anchor for the same path: `api/tests/Feature/MembershipApplicationTest.php`.

## 3. Authentication and authorization

**Principals.** Three kinds of authenticated party exist: `App\Models\User` (Filament users and club admins; `HasApiTokens`), `App\Models\Club` (`Authenticatable` with `HasApiTokens`, so a club itself can own a Sanctum token), and the anonymous public form acting through a super-admin token.

**Login for the club management UI** (`api/app/Actions/User/Login.php`): validates email/password, loads the user without the club scope, picks the user's first club as the team, requires the role `club admin` in that club, then issues a Sanctum token with all abilities (`['*']`) and a one-week expiry. The response carries `meta.club_id` and `meta.token`; next-auth stores both in its JWT (`web_application/src/utils/auth.ts`). Logout revokes the token via `POST users/-actions/logout` and clears the next-auth cookies.

**Filament** uses the `web` session guard, tenancy by `Club`, and admits only super admins.

**Authorization model.** spatie permissions with teams; role and permission rows are inserted by migrations (18 `insert_*_permissions_*` files plus `2024_03_20_092746_create_roles_and_permissions.php`), which means the permission catalogue is part of schema history rather than seed data. Roles observed: `super admin`, `club admin`, `treasurer`. Policies check a named permission **and** that the resource's `club_id` equals the current team id. `Gate::before` short-circuits for super admins. `ClubScope` (section 2, step 11) hides other clubs' rows for everyone else.

**Observations.**

- The public form's super-admin token means every anonymous applicant is, server-side, the most privileged principal in the system. `Server::handleClubAssociation` allows it to write to any club because the super admin passes `can('view', $club)`. A `club admin` token posting a membership for another club is stopped earlier than that hook: the JSON:API relationship validation looks the club up through `ClubScope`, finds nothing, and answers 404 "The related resource does not exist" without creating a row (verified live, `docs/baseline/e2e_apply.txt` step 8). The hook's fallback branch that associates the current team id is reachable only for requests that omit the club relationship, which the membership and member request rules do not allow.
- JSON:API routes rely on `Auth::shouldUse('sanctum')` plus policies; there is no route-level `auth` middleware to fail fast. Guests reach the controller and are turned away by policy or by the empty scope.
- Tokens are long-lived (one week for admins; the public-form token never expires unless pruned) and carry all abilities.
- Two independent tenant-isolation mechanisms exist (`ClubScope` for the API, `ApplyTenantScopes` for Filament) covering different model sets.

## 4. Database

**Engines.** MariaDB 11.8 in `docker-compose.yml` and `api/.env.example` (`DB_CONNECTION=mysql`). Tests run on SQLite in memory (`api/.env.testing`, loaded because `api/phpunit.xml` sets `APP_ENV=testing`). PostgreSQL is not mentioned anywhere.

**Domain tables** (from `api/database/migrations`): `clubs`, `membership_types`, `members`, `memberships`, `divisions`, `division_member`, `division_membership_type`, `payment_periods`, `club_payment_period`, `finance_accounts`, `finance_contacts`, `statements`, `transactions`, `receipts`, `tax_account_charts`, `tax_accounts`, `media`; framework tables `users`, `password_reset_tokens`, `personal_access_tokens`, `jobs`, `failed_jobs`; spatie permission tables with `club_id` as the team column.

**Observations from the migrations (verified against the live schema in `docs/DATABASE_BASELINE.md`).**

- The original domain tables declare `foreignId('club_id')`, `foreignId('membership_type_id')`, `foreignId('membership_id')` **without** `constrained()`. The live schema confirms it: `members`, `memberships`, `membership_types` and `divisions` have **no foreign key and no index on `club_id`** (or on `members.membership_id`, `memberships.owner_member_id`); the only foreign keys on core tables are `memberships.membership_type_id` (added 2026, `nullOnDelete`) and `clubs.tax_account_chart_id`. Pivot tables (`division_member`, `division_membership_type`) do have constrained, cascading keys.
- Status columns (`memberships.status`, `members.status`) are nullable `string`s with no database-level constraint; the allowed values live in PHP enums and request rules only.
- Money is stored as unsigned integer cents and cast by `App\Casts\MoneyCast`.
- Translatable text (`membership_types.title`, `divisions.title`, `clubs.apply_title`) is JSON handled by `spatie/laravel-translatable`.
- **MySQL-specific constructs** that SQLite tolerates by type affinity and that PostgreSQL would reject: `CAST(… AS UNSIGNED INT)` in `Membership::scopeWithMembersDivisionsFee`; a double-quoted string literal in `DB::statement('UPDATE clubs SET membership_start_cycle_type = "daily"')` (`2025_02_05_125657_…`); string-interpolated table and column names in `api/app/JsonApi/Filters/StatusFilter.php`.
- **No transactions** around multi-step writes: `ApplyMembershipAction` (three updates plus an event), and the four-request orchestration in `create-membership/route.ts`.
- **Per-row queries in listings**: `MembershipSchema` computes `membersCount` with `members()->count()` and `monthlyFee` with `Membership::getMonthlyFee()`, which itself runs `static::where('id', …)->withMembersDivisionsFee()->first()`, for every membership in a page.

## 5. Tests

**Structure.** `api/tests/TestCase.php` uses `DatabaseMigrations` (a fresh migrate per test) and `laravel-json-api/testing`'s `MakesJsonApiRequests`. Suites: `Unit` (4 files) and `Feature` (24 files, including `ClubAdmin/*` for the club-admin role, `Filters/*`, `LoginTest`, `PasswordResetTest`, `MembershipApplicationTest`). `phpunit.xml` sets `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`, `CACHE_DRIVER=array`.

**Frontend and end-to-end.** None. `package.json` has lint and format scripts only. The README's Cypress section describes an `/e2e` project that is not in the repository (upstream issue #197).

**Baseline run on this machine, 2026-09-01** (raw output in `docs/baseline/`):

| Check | Result |
|---|---|
| `php artisan test` (PHPUnit 12.5.33, SQLite `:memory:`) | **91 tests, 338 assertions, 0 failures, 0 errors**; 28 of 28 files pass; 58.3 s (`phpunit.txt`). The tests upstream issue #269 refers to pass at `dca9be3`. |
| `vendor/bin/pint --test` (Laravel preset) | **215 style issues in 400 files**, exit 1 (`pint.txt`) |
| `npx tsc --noEmit` | **0 errors** (`tsc.txt`) |
| `npm run lint` (`next lint`) | **71 warnings, 0 errors**, exit 0 (`eslint.txt`) |
| Resolved versions | Laravel 13.23.0, PHP 8.3.33, Composer 2.10.3; Next 14.2.35, React 18.3.1, Zod 4.4.3, TypeScript 5.9.3, Tailwind 4.3.3, openapi-fetch 0.14.1, openapi-typescript 7.13.0, next-auth 4.24.15; Node 20.20.2 / npm 10.8.2 inside `tooling` (`versions.txt`) |

The suite is green but narrow: it covers the JSON:API surface for the roles it seeds and nothing in Filament or the frontend. `php artisan test --no-interaction` is rejected by PHPUnit 12 ("Unknown option"); run it without flags.

## 6. Continuous integration

`.github/workflows/release.yml` (manual `workflow_dispatch`: git-cliff bumps `CHANGELOG.md` and `publiccode.yml`, opens a `release/*` PR) and `.github/workflows/publish.yml` (tags and creates a GitHub release when that PR merges). **No workflow runs PHPUnit, Pint, ESLint, TypeScript or a build.** Upstream issue #7 (open since 2025-07-15) asks for exactly that. Formatting tooling exists locally: `api/pint.json` (Laravel preset), `next lint`, Prettier.

## 7. Strengths worth preserving

- A real API contract: JSON:API resources with an OpenAPI document that generates the frontend's TypeScript types (`web_application/src/types/schema_v1.d.ts`) and a typed `openapi-fetch` client.
- Use-case classes (`api/app/Actions/**`) keep controllers thin; enums exist for statuses; money is integer cents.
- Tenant isolation is applied systematically (global scope on 13 models, policies that check `club_id`, teams-mode permissions) rather than ad hoc per query.
- Feature tests exercise real JSON:API requests through the package's testing helpers, including role-specific suites under `tests/Feature/ClubAdmin`.
- Internationalisation is end-to-end: translatable model fields, `Accept-Language` handling, locale-prefixed routes, `next-translate` dictionaries.
- Health checks, scheduled token pruning, a Docker development environment, Conventional Commits with automated changelogs, and public-sector metadata (`publiccode.yml`) show operational intent.

## 8. Weaknesses (factual, with locations)

1. No automated verification: no CI for tests or lint; no frontend or end-to-end tests (section 5, section 6).
2. Test/production engine mismatch (SQLite vs MariaDB) with MySQL-specific SQL in the codebase (section 4).
14. No foreign keys and no indexes on the `club_id` columns of `members`, `memberships`, `membership_types`, `divisions` (section 4, `docs/DATABASE_BASELINE.md`): tenant isolation and referential integrity both rest on application code, and every scoped query scans the table.
3. Non-atomic multi-step writes in `ApplyMembershipAction` and `create-membership/route.ts`; a mail failure after approval leaves the data changed but reports failure.
4. Public form runs on a super-admin bearer token held in the Next server's environment (`web_application/src/services/club-api.ts`).
5. Exception messages leak into API responses: `MembershipController::apply`, `UserController::creating/updating/login/logout` all return `$th->getMessage()` in a 422.
6. `ClubScope` dead branch for `Club` tokens; two tenant mechanisms with different model coverage.
7. Health checks configured but unreachable over HTTP (`HealthCheckServiceProvider`, `routes/web.php`).
8. Hard-coded environment specifics in code: `"TSV Muster"` in `SendApplicationNotifications`; `/de/admin/auth/login` and `tsv-muster` in `HealthCheckServiceProvider`; `'en'` in `ApiEndpoints`; `'de'` in `server-client.ts`; `env()` called outside `config/` in `Club::applyUrl`, `HealthCheckServiceProvider`, `AppServiceProvider` (password reset link) and `WelcomeClubAdminMailable` — returns `null` once config is cached, so all four fell back to the upstream production domain. *Fixed in this fork in M1 (ADR-0002, `docs/baseline/env_bug_before_fix.txt`).*
9. Shared mutable singleton in the frontend: `clubApi` is module-level and `fetchClubDataOrFail` mutates its locale per request.
10. OpenAPI document is hand-maintained and version-drifted (`api/public/v1_openapi.json` says 0.0.1; `api/config/openapi.php` says 1.0.0; README says generation is not automated; issue #58).
11. Permissions live in migrations, making them non-idempotent to change and hard to review.
12. Legacy Laravel 10 skeleton on Laravel 13 (`bootstrap/app.php`, `Http/Kernel.php`, `RouteServiceProvider`).
13. Governance: no `CONTRIBUTING.md`, `SECURITY.md`, issue or PR templates; labels unused; `publiccode.yml` says PHP ≥ 8.2 while `composer.json` requires ^8.3.

## 9. Unknowns

- How upstream deploys to production: `.dockerignore` references a `docker-compose.live.yml` and an `ansible` directory that are not in the repository.
- Which queue driver and mailer production uses (`.env.example` says `sync` and Postmark is a dependency).
- Whether upstream's own CI or hosting runs the test suite somewhere outside GitHub.
- Which tests in issue #269 are still failing on upstream's machines (this machine's result is in section 5).
- Whether the Filament resources are covered by any tests (none found under `api/tests`).

## 10. Upstream contribution candidates

Ranked by value to the maintainers × smallness × testability. One at a time, after reading the issue and asking before opening a PR (upstream has not yet merged an external contribution).

1. **Test/lint workflow** (issue #7): PHPUnit on SQLite, `pint --test`, `tsc --noEmit`. Small, non-opinionated, immediately useful.
2. **`ChangeLocaleFromHeader` matching** (issue #125): use `$request->getPreferredLanguage(config('app.supported_locales'))` and apply the middleware to the custom REST group. One feature test.
3. **`.gitattributes`** with `* text=auto eol=lf`: prevents the CRLF conversion that breaks `docker/api/entrypoint.sh` on Windows checkouts. One line.
4. **Transaction around `ApplyMembershipAction`** with the event dispatched after commit. Covered by the existing `MembershipApplicationTest`.
5. **Configurable demo-club check** in `SendApplicationNotifications` plus a Mailpit service in `docker-compose.yml` (the `.env.example` already points at `mailpit`).

Also credible, larger: #126 (locale plumbing for the openapi-fetch client), #58 (OpenAPI generation), #181 (accessibility of club management), exposing the configured health checks over HTTP.

## 11. Running it on this machine — deviations from the upstream README

Host: Windows 11 Home, Docker Desktop 29.6.2 (Compose v5.3.1), no PHP or Composer installed natively, Node 22.19 native. All PHP runs inside the `tooling` container as upstream intends.

| Deviation | Why | Where |
|---|---|---|
| Cloned with `--config core.autocrlf=false` | Git for Windows sets `core.autocrlf=true` system-wide and upstream has no `.gitattributes`; a default clone converts `docker/api/entrypoint.sh` to CRLF and the API container cannot start | clone command |
| Stopped another compose project first | It held host port 3000, which `tooling` maps for `next dev` | host |
| `docker-compose.override.yml` with named volumes for `api/vendor`, `api/node_modules`, `web_application/node_modules` | Dependency installs onto NTFS bind mounts are slow; the `api` and `tooling` services share the vendor volume | `docker-compose.override.example.yml` |
| Root `.env` with `USER_ID=1000`, `GROUP_ID=1000` | Compose warns when the build args are unset (Dockerfiles default to 1001 anyway) | gitignored |
| Started only `database api api-docs tooling` | The `web_application` service builds a production image, unnecessary for development and possibly blocked by upstream's own TypeScript errors (#263) | compose command |
| `MAIL_MAILER=log` in `api/.env` | `.env.example` points at a `mailpit` host that the compose file does not define | `api/.env` (gitignored) |
| `php artisan config:clear` before `php artisan test` | The API entrypoint caches config onto the bind-mounted `api/`; cached config makes the tooling container ignore `.env.testing`, and the suite's `migrate:fresh` would then hit the seeded MariaDB database | tooling |

**Measured wall-clock, 2026-09-01**

| Step | Time |
|---|---|
| `docker compose up -d --build database api api-docs tooling` (pulls + two image builds; imagick compiled from source) | 8 min 15 s |
| `composer install` into the named volume | 21 s |
| `npm ci` + `npm run build` in `api/` | 4 s |
| `npm ci` in `web_application/` | 21 s |
| `migrate:fresh --seeder=FakeDatabaseSeeder`, clean run (69 migrations + seed) | 298 s. DDL was slow on this MariaDB container (single `CREATE TABLE`s took 6–24 s); cause not investigated in M0 |
| `php artisan test` | 58 s |
| `next dev` ready / first compile of `/de/tsv-muster/apply` | 2.2 s / 24.8 s |

Seeded data: 6 clubs (the demo club "TSV Muster", id 1, plus 5 fake), 19 users, 116 members, 60 memberships, 33 divisions, 18 membership types, 3 roles, 56 permissions (`docs/baseline/row_counts.txt`).

**Smoke checks** (all consistent with the code reading): `GET :3001/` → 302 to `/admin/login`; `/admin/login` → 200; `/v1_openapi.json` → 200, 648 KB; Swagger UI on `:3002` → 200; `GET /api/v1/clubs` without a token → 401 JSON:API error document; with the super-admin token → 200, 6 clubs; `POST /api/v1/users/login` as `club-admin-1@example.org` → 200 with `meta.club_id = 1` and a token; the same call as the super admin → 422 "Only club admins are allowed to login."; `POST /api/v1/memberships/1/-actions/apply` without a token → 404 (the empty tenant scope hides the row before any policy runs); `GET /health` → 404. Next dev: `/de/tsv-muster/apply` → 200, `/de/admin/auth/login` → 200. Note the two action-URL conventions: `users` registers its actions without a prefix (`/users/login`), `memberships` with one (`/memberships/{id}/-actions/apply`).

**Application flow replayed against the API** (`docs/baseline/e2e_apply.txt`; the same four calls `create-membership/route.ts` makes, with the super-admin token): `POST memberships` → 201 (id 61, `status: null`) → `POST members` → 201 (id 117, `inactive`) → `PATCH memberships/61` owner → 200 → `POST memberships/61/-actions/apply` → 200, `status: applied`; MariaDB shows the same. A second `apply` → 422 "The membership is not eligable to apply: Membership status must previously been null": the action is not idempotent, and the upstream typo ships in the error body.

**Tenant-isolation probe** (same file): with the token of `club-admin-1` (club 1), `GET /api/v1/memberships/11` (a club-2 membership) → 404 and `POST …/11/-actions/apply` → 404; the membership index returns only club 1's 11 rows. Isolation for reads and for the custom action works as the `ClubScope` reading predicted: the row is invisible, so no policy is ever consulted.

**Traps met on the way, and the rule each produced**

- The `api` container's entrypoint runs `php artisan migrate --force` as soon as MariaDB accepts connections. On first boot MariaDB took about a minute to initialise; `composer install` had finished long before, so the entrypoint's migration ran at the same time as the README's manual `migrate:fresh`, and both failed with "Table 'role_has_permissions' already exists". A later solo run succeeded. Rule: after `docker compose up`, wait until `docker compose logs api` shows the entrypoint's migration result before running migrations by hand.
- The very first manual migration attempt ran before MariaDB was ready and failed with a connection error two seconds in. Same rule.
- `php artisan test --no-interaction` is rejected by PHPUnit 12; use `php artisan test`.
- Sanctum plain-text tokens contain `|`; writing one into `.env.local` with `sed 's|…|…|'` fails. A small PHP one-liner wrote it instead.
- `curl` treats `filter[slug]` as a glob pattern and exits with code 3; pass `-g`.
- `next dev` inside the `tooling` container does not see file changes on a Windows bind mount (no inotify events): new routes and edits appear only after a restart. Start it with `WATCHPACK_POLLING=true npx next dev` (added in M3).
- Running `php artisan test` while the `api` container restarts wiped the development database once (M3, `docs/incidents/INCIDENT-000-dev-database-wiped-by-config-cache.md`). `phpunit.xml` now isolates the test process from the container's caches; the "run `config:clear` before tests" rule above is no longer needed.
- The federation's OIDC issuer is addressed as `http://host.docker.internal:3004/default`, a name both the browser and the containers resolve on Docker Desktop; the compose file adds `extra_hosts` for Linux hosts (M3).
