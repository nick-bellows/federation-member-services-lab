# Database compatibility matrix

What "runs on" means here: the whole PHP suite and the demo seeder pass on that engine in CI, on the real engine, not on a lookalike. Decided in [ADR-0011](adr/0011-postgresql-compatibility-matrix.md).

| Engine | Role | Evidence |
|---|---|---|
| SQLite (in memory) | Upstream's test configuration; fastest | CI job `backend-sqlite` |
| MariaDB 11.8 | Development and runtime engine in Compose; upstream parity | CI job `backend-mariadb` |
| PostgreSQL 16 | Third engine of the matrix; the one that rejects what SQLite tolerates | CI job `backend-postgres` (suite plus `NorthgateDemoSeeder`) |

MariaDB stays the default. Nothing in this repository is deployed; the matrix is a statement about the code, verified by CI.

## Constructs found and what was done

| Where | Construct | Engines it worked on | Treatment |
|---|---|---|---|
| `api/app/Actions/Export/Export*Resource.php` (seven files) | `ORDER BY FIELD(id, …)` | MySQL, MariaDB; SQLite has no `FIELD`, PostgreSQL neither | **Fixed in place**: `App\Support\OrderByIdList` builds `CASE id WHEN ? THEN n … END` with bindings. Regression test `tests/Feature/Export/ExportOrderTest.php` runs on all three engines. Upstream candidate. |
| `api/app/Models/Membership.php`, `scopeWithMembersDivisionsFee` | `CAST(SUM(…) AS UNSIGNED INT)` | MySQL, MariaDB; SQLite by affinity; PostgreSQL rejects `UNSIGNED` | **Fixed in place**: `CAST(… AS INTEGER)`, accepted by all three (probed on MariaDB 11.8). Covered by `tests/Unit/MembershipTest.php`. |
| `api/database/migrations/2025_02_05_125657_…` | `UPDATE clubs SET … = "daily"` (double-quoted string literal) | MySQL, MariaDB, SQLite; PostgreSQL reads `"daily"` as an identifier | **Fixed in place**: the query builder with a bound value. Already-migrated MariaDB databases are unaffected; the migration only runs on fresh engines. |
| `api/tests/Feature/ClubAdmin/DivisionTest.php`, `MembershipTypeTest.php` | `assertDatabaseHas(…, ['title' => json_encode(…)])`: a JSON column compared to a string | MySQL, MariaDB, SQLite compare as text; PostgreSQL's `json` type has no equality operator (`jsonb` does) | **Fixed in place** in the tests: translations compared through the model (`getTranslations()` on a fresh instance); the numeric columns keep their database assertion. Found by the local PostgreSQL run before CI. |
| `api/app/JsonApi/Filters/StatusFilter.php` | String-interpolated table and column names in raw `EXISTS` and `SUM` subqueries | All three: identifiers are unquoted lowercase, `COALESCE`, `SUM`, `!=` are standard | **Verified as written** by the PostgreSQL job; left untouched. |
| `api/app/JsonApi/Sorting/FullNameSort.php` | `CONCAT_WS(' ', first_name, last_name)` | All three (PostgreSQL has `CONCAT_WS`; SQLite since 3.44 via `concat_ws`, and Laravel's SQLite driver in the suite) | Verified by the matrix. |
| `api/app/JsonApi/Sorting/ReceiptAmountSort.php` | `CASE WHEN receipt_type = 'expense' THEN -amount ELSE amount END` | All three | Verified by the matrix. |
| `api/app/JsonApi/Filters/MembershipFilter.php`, federation schemas | `whereRaw('1 = 0')` | All three | Verified. |
| Federation module | `insertOrIgnore` (`ON CONFLICT DO NOTHING` on PostgreSQL), `lockForUpdate` (`FOR UPDATE`), JSON columns (`json` on both), nullable unique `active_key` | All three | Written portable from the start (ADR-0006, ADR-0010). |
| Money columns | `unsignedInteger` | MySQL, MariaDB; PostgreSQL has no unsigned integers and Laravel maps them to plain integers | Verified by the matrix; the application casts and validates amounts (`App\Casts\MoneyCast`). A `CHECK (amount >= 0)` is future work per engine. |

## Differences that are not bugs

- **Transactional DDL.** PostgreSQL rolls a failed migration back; MariaDB does not. INCIDENT-000's half-migrated database was possible only on MariaDB.
- **Strict `GROUP BY`.** PostgreSQL requires every non-aggregated selected column in the group; MariaDB and SQLite do not by default. None of the current queries trip it; a new one might.
- **`LIKE` is case-sensitive** on PostgreSQL (`ILIKE` is not); MariaDB's default collation is case-insensitive. No search in the federation module uses `LIKE` yet.
- **`NULL` sorts last** ascending on PostgreSQL and first on MariaDB. Sorts on nullable columns (`submittedAt`, `decidedAt`) may order differently across engines; the pages do not depend on it.
- **Identifier quoting.** Laravel quotes identifiers per driver; raw SQL must use unquoted lowercase names or single-quoted string literals.

## Running the matrix locally

```sh
docker compose --profile postgres up -d postgres          # one-off PostgreSQL 16 on the stack network
docker compose exec tooling bash -lc 'cd api && DB_CONNECTION=pgsql DB_HOST=postgres DB_PORT=5432 DB_DATABASE=verein_test DB_USERNAME=verein DB_PASSWORD=verein php artisan test'
```

The tooling and api images carry `pdo_pgsql` since B4. The process environment wins over `.env.testing`, exactly as in the CI job.

## Status

- SQLite and MariaDB: `validated` (CI green since 2026-09-03).
- PostgreSQL: `validated` when the `backend-postgres` job is green on `main`; until then `planned`. The learning log records the first run.
