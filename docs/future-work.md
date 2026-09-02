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
- **Upstream dependency advisories** — `composer audit` reports 13 advisories across `filament/filament`, `league/commonmark` and `livewire/livewire` in upstream's lock file (`docs/baseline/composer_audit.txt`). A bounded dependency update with the test suite as the gate; candidate upstream contribution.
- **API entrypoint caching in development** — the container caches config, routes and events on every start; consider caching only when `APP_ENV=production` (INCIDENT-000 follow-up).
- **OIDC session lifetime** — no refresh-token handling yet; the member page sends visitors back to sign-in when the access token has expired.
- **Auth0 walkthrough** — needs the owner's tenant; document callback URLs, audience and screenshots once created.
- **OpenAPI drift guard** — a CI step that regenerates `api/public/federation_openapi.json` and fails when it differs from the committed file.
- **Object storage for documents** — pre-signed uploads to S3-compatible storage with checksum verification against the recorded SHA-256; retention rules with the audit trail (ADR-0008 follow-up).
- **History pagination** — the `history` attribute grows with every transition; cap or paginate before long-lived applications exist.
- **Review queue as its own endpoint** — today the queue is the scoped applications index filtered by status; a dedicated resource could add assignment, ageing and counts.
- **Registration side effects** — approval does not yet create a registration record or link the applicant to upstream's `members`; decide with the Learning Center contract.
