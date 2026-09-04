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
- **Auth0 walkthrough** — deferred to the end of Phase B by owner decision (2026-09-02). Needs the owner's tenant; document callback URLs, audience and screenshots once created. The code is provider-agnostic; nothing changes except environment variables.
- **API entrypoint auto-migration on a cold start** — the container migrates an empty database as soon as it can reach it, so the README's `migrate:fresh` has to wait for that to finish; the CI browser job now waits for the container's start marker (a run hung for 45 minutes on the race, 2026-09-03). Consider making the entrypoint's migration opt-in for development and CI.
- **OpenAPI drift guard** — a CI step that regenerates `api/public/federation_openapi.json` and fails when it differs from the committed file.
- **Object storage for documents** — pre-signed uploads to S3-compatible storage with checksum verification against the recorded SHA-256; retention rules with the audit trail (ADR-0008 follow-up).
- **Registration windows in a federation-defined time zone** — dates are displayed in UTC so that server and browser render the same day (cold-clone finding, 2026-09-02); a federation would rather define windows in its own zone and show them in it. Needs a zone on the federation and a formatter that uses it on both sides.
- **History pagination** — the `history` attribute grows with every transition; cap or paginate before long-lived applications exist.
- **Review queue as its own endpoint** — today the queue is the scoped applications index filtered by status; a dedicated resource could add assignment, ageing and counts.
- **Registration side effects** — approval does not yet create a registration record or link the applicant to upstream's `members`; decide with the Learning Center contract.
- **Reconciliation scheduling and retry** — `federation:reconcile-credentials` runs by hand; schedule it (B5) and alert on its non-zero exit; add retry with jitter per user (B3, with the outbox for `credentials.changed`).
- **Log file shared across containers** — the api and tooling containers write the same bind-mounted `storage/logs/laravel.log`; a root-owned file made every logged request answer 500 during INCIDENT-001's setup. Tests now log to the null channel; give each process its own log path or log to stderr in development.
- **Service token cache across processes** — the client-credentials token is cached in Laravel's cache store (file in Compose); document the shared store for production in B8.
- **Credential contract v2 candidates** — a `changed_since` or webhook path so the federation is told about hold and credential changes instead of polling; the provider's `credentials.changed` event would feed the outbox.
- **Notifications surface** — `federation_notifications` rows exist for every published fact; a member page or a mailer that reads them is the next step (B5 or B9).
- **Per-consumer attempts** — attempts are counted per outbox row across consumers; a per-consumer view if consumer counts grow.
- **Broker adapter** — the relay dispatches to Laravel's queue; a SQS, RabbitMQ or Kafka adapter behind the same relay when one is chosen (B8), per the table in ADR-0010.
- **Worker as a Compose service** — the worker is started by hand in the api container as the PHP-FPM user; a dedicated service on the api image with the entrypoint's migration made opt-in would remove the manual step.
- **Money columns on PostgreSQL** — `unsignedInteger` maps to a plain integer there; a `CHECK (amount >= 0)` per engine would restore the constraint (B4 finding).
- **Default engine** — MariaDB remains the development default; switching the Compose stack to PostgreSQL is a documented option once B8 chooses a production engine.
- **Scheduling and alert delivery** — the worker, the reconciliation, `health:check` and `outbox-status` run by hand; B8 defines the scheduler and where a non-zero exit code goes.
- **Auto-instrumentation** — the OpenTelemetry Laravel extension would add database and HTTP client spans without hand-written code; the four hand-written spans are the ones that matter for the incidents.
- **Upstream health checks in development** — `EnvironmentCheck` and `DebugModeCheck` expect production and the two pings target upstream's public URLs from inside the container; a development profile for the checks, or none, is upstream's call.
- **Accessibility improvements deferred to B9** — a skip link, per-page titles, and `aria-describedby` on the transition buttons (`docs/ACCESSIBILITY.md`).
- **Production frontend measurement** — the slow-3G number from the development server is not the product; measure a built image once B8 serves one.
- **Composite indexes** — if a listing's filter and sort show in a plan, `(club_id, status)` and friends; the single-column baseline is in place.
- **Building next to a running dev server** — `next build` writes into the `.next` directory the dev server serves from and breaks it; a separate `distDir` for builds, or stop the dev server first.
- **Advisories that need a major** (B7 audit, `docs/baseline/security_audit_2026-09-03.txt`; after the B8 fixes, `security_audit_after_b8_2026-09-04.txt`) — `next` 14.2 (four advisories; fix is Next 16), `postcss` nested under it, `sharp` 0.33 (fix 0.35), `swiper` 9 (critical prototype pollution; fix 14); none used by the federation pages beyond `next` itself. The within-major fixes were taken in B8 (Composer: 13 → 0 advisories; npm: 8 → 4 in the frontend, 1 → 0 in the API tooling). The four majors are re-evaluated at each release per the policy in `docs/THREAT_MODEL.md`.
- **Infrastructure as code once an account exists** — Terraform or CDK for `docs/DEPLOYMENT.md`, written against a real account so `plan` is evidence; not committed untested (ADR-0015).
- **Pipeline hardening** — image scanning in CI, digest-pinned base images, SHA-pinned actions, the images pushed to a registry under the git SHA (the release checklist assumes a registry).
- **SQS behind the relay** — the first change after a deployment carries real volume (ADR-0010 mapping; `docs/DEPLOYMENT.md`).
- **Write-once audit table by database role** — revoke `UPDATE` and `DELETE` on `audit_entries` for the application role, per engine, once RDS exists.
- **A hosted case study page** — `docs/CASE_STUDY.md` reads on GitHub; serving it as a page needs GitHub Pages on the fork (a visibility setting the owner enables) or a host (B9, approvals list).
- **A hosted demo** — the recording in `docs/assets/demo.webm` stands in; a running demo is a cost decision (B9, approvals list).
- **The upstream offer** — drafted in `docs/UPSTREAM_OFFER.md`; sent on the owner's word (decision 7); the four items would be re-cut onto upstream `main` as separate branches once the maintainers say what they want.
- **The Auth0 walkthrough** — `docs/AUTH0_WALKTHROUGH.md` is planned until the owner's tenant exists; the screenshots it lists turn it validated.
- **A screen reader by ear** — the accessibility review is a keyboard walk, per-criterion record and axe; NVDA or VoiceOver through the whole journey has not been done.
- **Upstream's release workflows on the fork** — `release.yml` and `publish.yml` would tag and publish releases; running them is the owner's call (`docs/RELEASE.md`).
- **Write-once audit trail at the database** — `audit_entries` is append-only in code; a trigger or a revoked `UPDATE`/`DELETE` privilege for the application role would make it so below PHP (B8, per engine).
- **Pinning** — actions by SHA, images by digest, a tag for upstream's Swagger UI image (B8 release checklist).
- **Upstream findings for the B9 offer** (threat model, marked Upstream) — the public apply form's super-admin Sanctum token with no expiry, `apply` mapping every `Throwable` to a 422 with its message, the CORS wildcard.
- **Optimistic concurrency without `test`** — a client that sends no `test` operation writes last-wins; an `ETag`/`If-Match` on the resource would cover the JSON:API update as well.
