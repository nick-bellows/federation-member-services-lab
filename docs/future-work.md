# Future work

The single home for deferred ideas. Items move out of here into a milestone or an ADR; they do not accumulate in the README. Reduced on 2026-09-10 (C4) to what is still open: everything done since M0 was removed and duplicates were merged; the history of each item is in `docs/LEARNING_LOG.md`.

## Waits for the owner (ROADMAP O1 to O6)

- **The Auth0 walkthrough** — `docs/AUTH0_WALKTHROUGH.md` is planned until the owner's tenant exists; the six screenshots it lists turn it validated. The code is provider-agnostic; only environment variables change.
- **The AWS proof** — `deploy/terraform/` is validated, priced (about $2.50 a day) and not applied; the first `plan` and `apply` against the owner's account, recorded under `docs/baseline/`, are what turn it validated in fact. The production shape (private subnets, NAT or endpoints, Multi-AZ, WAF, a domain and certificate, a customer-managed key) stays a follow-up of that.
- **A hosted demo** — the recording in `docs/assets/demo.webm` stands in until the proof runs.
- **Upstream's release workflows on the fork** — `release.yml` and `publish.yml` would tag and publish releases; whether the fork carries releases at all is the owner's call (`docs/RELEASE.md`).
- **The upstream offer** — drafted in `docs/UPSTREAM_OFFER.md` (six items); sent on the owner's word, last; the items would be re-cut onto upstream `main` as separate branches once the maintainers say what they want.
- **Upstream findings that are a private conversation** (threat model, marked Upstream) — the public apply form's super-admin Sanctum token with no expiry, `apply` mapping every `Throwable` to a 422 with its message, the CORS wildcard. Not for a public issue; the owner decides whether to write to the maintainers separately.
- **Upstream contribution candidates beyond the offer** — ranked in `docs/UPSTREAM_ANALYSIS.md` §10 (issue #7 CI, #125 locale header, the transaction around the apply action, a configurable demo-club check plus Mailpit); one at a time, after the offer has an answer.

## Domain and API

- **Optimistic concurrency without `test`** — a JSON Patch client that sends no `test` operation writes last-wins; an `ETag`/`If-Match` on the resource would cover the JSON:API update as well (ADR-0016 alternative 5).
- **History pagination** — the `history` attribute grows with every transition; cap or paginate before long-lived applications exist.
- **Review queue as its own endpoint** — today the queue is the scoped applications index filtered by status; a dedicated resource could add assignment, ageing and counts.
- **Registration side effects** — approval does not yet create a registration record or link the applicant to upstream's `members`; decide with the Learning Center contract.
- **Registration windows in a federation-defined time zone** — dates are displayed in UTC so that server and browser render the same day (cold-clone finding, 2026-09-02); a federation would rather define windows in its own zone and show them in it. Needs a zone on the federation and a formatter that uses it on both sides.
- **Object storage for documents** — pre-signed uploads to S3-compatible storage with checksum verification against the recorded SHA-256; retention rules with the audit trail (ADR-0008 follow-up).
- **Credential contract v2 candidates** — a `changed_since` or webhook path so the federation is told about hold and credential changes instead of polling; the provider's `credentials.changed` event would feed the outbox.
- **Notifications surface** — `federation_notifications` rows exist for every published fact; a member page or a mailer that reads them is the next step.
- **OIDC session lifetime** — no refresh-token handling yet; the member page sends visitors back to sign-in when the access token has expired.
- **Two parallel tenant-isolation mechanisms** in upstream (`ClubScope` for the API, `ApplyTenantScopes` for Filament) — a candidate for consolidation only if the federation hierarchy forces a change; otherwise leave as is.

## Database

- **Write-once audit trail at the database** — `audit_entries` is append-only in code; a trigger or a revoked `UPDATE`/`DELETE` privilege for the application role would make it so below PHP, per engine, once a managed database exists.
- **Audit retention** — `audit_entries` grows without bound; define retention before any production-like deployment.
- **Database `CHECK` constraints** — on the application status (MariaDB and PostgreSQL syntax differ and Laravel's builder has no portable `check()`), and on money columns, which map to plain integers on PostgreSQL (`CHECK (amount >= 0)`, B4 finding).
- **Composite indexes** — if a listing's filter and sort show in a plan, `(club_id, status)` and friends; the single-column baseline is in place.
- **Default engine** — MariaDB remains the development default; switching the Compose stack to PostgreSQL is a documented option (the release proof already chooses it).
- **Seeder idempotency** — `NorthgateDemoSeeder` guards on the federation's existence only; a partial run has to be cleaned by hand. Make each entity `firstOrCreate` or wrap the seed in a transaction if it is reused beyond demos.

## Operations and delivery

- **Broker adapter** — the relay dispatches to Laravel's database queue; a SQS, RabbitMQ or Kafka adapter behind the same relay when one is chosen, per the table in ADR-0010; SQS is the first change after a deployment carries real volume (`docs/DEPLOYMENT.md`).
- **Per-consumer attempts** — attempts are counted per outbox row across consumers; a per-consumer view if consumer counts grow.
- **Service token cache across processes** — the client-credentials token is cached in Laravel's cache store (the file store per container in Compose and in the proof, so every task fetches its own); a shared store when tasks scale.
- **Auto-instrumentation** — the OpenTelemetry Laravel extension would add database and HTTP client spans without hand-written code; the four hand-written spans are the ones that matter for the incidents.
- **Pipeline hardening** — image scanning in CI, base images pinned by digest (the actions are pinned by commit since 2026-09-12; the pins move only by hand until a dependency bot is enabled, an owner decision), a tag for upstream's Swagger UI image, the images pushed to a registry under the git SHA (the release checklist assumes a registry).
- **Advisories that need a major** (B7 audit; after the B8 fixes, `docs/baseline/security_audit_after_b8_2026-09-04.txt`) — `next` 14.2 (four advisories; fix is Next 16), `postcss` nested under it, `sharp` 0.33 (fix 0.35), `swiper` 9 (critical prototype pollution; fix 14); none used by the federation pages beyond `next` itself. Re-evaluated at each release per the policy in `docs/THREAT_MODEL.md`; never silently ignored.
- **OpenAPI drift guard** — a CI step that regenerates `api/public/federation_openapi.json` and fails when it differs from the committed file.
- **MariaDB test job runtime** — `DatabaseMigrations` re-runs all migrations per test; consider `RefreshDatabase` (transactions) for the fork's own tests if the MariaDB job (about five minutes) becomes the bottleneck.
- **Production frontend measurement** — the slow-3G number in `docs/PERFORMANCE.md` is from the development server; measure the release web image once it serves somewhere.
- **A screen reader by ear** — the accessibility review is a keyboard walk, per-criterion record and axe; NVDA or VoiceOver through the whole journey has not been done.

## Development stack

- **API entrypoint caching and auto-migration in development** — the container caches config, routes and events on every start (INCIDENT-000) and migrates an empty database as soon as it can reach it (a CI run hung 45 minutes on the race, 2026-09-03; the browser job now waits for the start marker). Cache only when `APP_ENV=production`, and make the migration opt-in, as the release entrypoint already does.
- **Worker as a Compose service** — in development the worker is started by hand in the api container as the PHP-FPM user; a dedicated service on the api image, as the release Compose file has, would remove the manual step.
- **Log file shared across containers** — the api and tooling containers write the same bind-mounted `storage/logs/laravel.log`; a root-owned file made every logged request answer 500 during INCIDENT-001's setup. Tests log to the null channel; give each process its own log path or log to stderr in development (the release image does).
- **Upstream health checks in development** — `EnvironmentCheck` and `DebugModeCheck` expect production and the two pings target upstream's public URLs from inside the container; a development profile for the checks, or none, is upstream's call.
- **Building next to a running dev server** — `next build` writes into the `.next` directory the dev server serves from and breaks it; a separate `distDir` for builds, or stop the dev server first.
