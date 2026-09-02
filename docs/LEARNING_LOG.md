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

_Pending._

### Exercise E2 — watch the tenant context during a public application

_Pending._
