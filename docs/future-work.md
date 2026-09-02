# Future work

The single home for deferred ideas. Items move out of here into a milestone or an ADR; they do not accumulate in the README.

## Deferred from Milestone 0 (2026-09-01)

- **Upstream contribution candidates** are ranked in `docs/UPSTREAM_ANALYSIS.md` section 10 and will be picked one at a time from Milestone 1.
- **Cypress / end-to-end suite** does not exist upstream (issue #197). The fork will decide in M1 whether to add Playwright or Cypress for its own critical paths.
- **Config-cache trap in the dev container** (see `docs/UPSTREAM_ANALYSIS.md` section 11): worth a small upstream fix to the entrypoint or a documented `config:clear` step.
- **Two parallel tenant-isolation mechanisms** (`ClubScope` for the API, `ApplyTenantScopes` for Filament) — a candidate for consolidation only if the federation hierarchy in M2 forces a change; otherwise leave as is.
- **`env()` calls outside `config/`** — fixed in M1 (ADR-0002); offering upstream is planned in ADR-0004.
- **Pint enforcement** — report-only in CI; decide whether to run Pint on the federation namespaces only, or propose a one-time formatting commit upstream.
- **MariaDB test job runtime** — `DatabaseMigrations` re-runs all migrations per test; consider `RefreshDatabase` (transactions) for the fork's own tests if the MariaDB job proves slow on GitHub runners.
- **Seeder idempotency** — `NorthgateDemoSeeder` guards on the federation's existence only; a partial run has to be cleaned by hand. Make each entity `firstOrCreate` or wrap the seed in a transaction if it is reused beyond demos.
- **Database `CHECK` constraint on application status** — MariaDB and PostgreSQL syntax differ and Laravel's builder has no portable `check()`; decide per engine in the PostgreSQL milestone.
- **Audit retention** — `audit_entries` grows without bound; define retention before any production-like deployment.
