# ADR-0011: PostgreSQL as a third engine, through a compatibility matrix

- Status: accepted (2026-09-03, owner's decisions at the start of B4)
- Milestone: B4 (M7 in the brief)
- Related: ADR-0002 (configuration), ADR-0004 (upstream contributions), [`docs/DATABASE_COMPATIBILITY.md`](../DATABASE_COMPATIBILITY.md)

## Context

Upstream tests on SQLite in memory and runs on MariaDB. SQLite accepts most MySQL-only SQL by type affinity, so the suite could not tell which raw queries were portable. The M0 analysis catalogued three constructs PostgreSQL would reject. The brief asks for PostgreSQL support with the differences documented and none hidden, and a production deployment for this kind of system would most likely run on a managed PostgreSQL.

## Decision

1. **A compatibility matrix, not a default switch.** PostgreSQL 16 becomes a third CI job that runs the whole suite and the demo seeder on the real engine. MariaDB stays the development default and the upstream-parity engine. `docs/DATABASE_COMPATIBILITY.md` names every construct, the engines it worked on, the treatment and the evidence; "runs on PostgreSQL" means that job is green.
2. **Fix upstream's MySQL-only SQL in place where a portable form is cheap and behaviour-preserving**, with a regression test that runs on all three engines, and offer the fixes upstream as the second candidate under ADR-0004. Isolate behind a driver switch only where a portable form would change behaviour; none needed so far.
3. **The images carry the driver.** `pdo_pgsql` is installed in the tooling and api images, and an optional `postgres` service exists in Compose under a profile, so the matrix runs locally the way it runs in CI.

## Alternatives considered

1. **Switch the fork's default to PostgreSQL** — a bigger diff against upstream, a second seed-time reality to keep true, and the demo stack would no longer be upstream's. Rejected for now; nothing prevents it later, and the matrix is the prerequisite either way.
2. **PostgreSQL for the federation tables only** — two connections in one process, no cross-database joins, every test world doubled. Rejected.
3. **Isolate every construct behind a driver switch** — upstream's paths untouched, twice the code to keep true, nothing offerable. Rejected.
4. **Mark the affected features MariaDB-only** — honest and small, but the matrix would read as a list of exclusions and the upstream candidate would be lost. Rejected.

## Consequences

- Three engines in CI: the SQLite job stays the fast one, MariaDB the runtime one, PostgreSQL the strict one. A raw query must pass all three.
- Seven export actions, one model scope and one migration changed from MySQL-only SQL to portable forms; the exports gained a test they did not have.
- Engine differences that are not bugs (transactional DDL, strict `GROUP BY`, `LIKE` case, `NULL` ordering, no unsigned integers) are recorded, not papered over.
- Follow-ups: a `CHECK (amount >= 0)` per engine for money columns; the upstream offer at B9 (decision 7) now carries the portability fixes as well; the choice of a production engine belongs to B8.
