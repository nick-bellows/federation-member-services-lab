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

Reads the web application URL, the admin login path and the sender address through Laravel's configuration instead of `env()`, so they survive configuration caching; normalises line endings; adds a CI workflow (draft until it runs on GitHub) and records the end-to-end tool and the upstream-contribution policy.

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
4. Why is Pint a report and not a gate, and how would you enforce style on new code without reformatting upstream?
