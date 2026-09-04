# Threat model

Status: reviewed 2026-09-03 (B7). Method: attack trees (owner's decision, ADR-0014), one tree per attacker goal, STRIDE used as the checklist when listing a node's children. Every leaf names the control in the code that stops it, or the gap and where the gap is recorded. Nothing here is a claim about production: the system is validated in CI and runs in Compose, and the controls are the ones the tests exercise.

Scope: the federation slice this fork adds (`/api/v1/federation*`, the probes and metrics, the outbox worker, the Learning Center call, the member and reviewer pages). Upstream's club-management surface (Filament panel, Sanctum API, public apply form) is in scope only where the fork inherits its exposure; findings there are recorded as upstream's, and go to the upstream offer at B9 (ROADMAP decision 7) or to `docs/future-work.md`.

## Legend

| Marker | Meaning |
|---|---|
| **Mitigated** | a control exists in this repository and a test exercises it |
| **Partly** | a control exists; a stated residual remains |
| **Open** | no control; recorded in `docs/future-work.md` or a milestone |
| **Upstream** | upstream's surface, unchanged by the fork; recorded for the offer at B9 |
| **Dev only** | exists only in the Compose stack and must not exist in a deployment (B8 gate) |

## Assets

| Asset | Where it lives | Why it matters |
|---|---|---|
| Identities | `users` (OIDC issuer + subject), `member_organization_administrators`, `federation_administrators` | who may act, and as whom |
| Registration applications | `registration_applications`, `application_documents` (metadata only, ADR-0008) | the transaction the federation exists for; personal data (date of birth, phone, notes) |
| Reviewer notes | `registration_applications.reviewer_notes` (B7) | a reviewer's working notes; must never reach the applicant |
| Audit trail | `audit_entries` (append-only, ADR-0006) | the record of who did what; its integrity is the review's integrity |
| Credential snapshots | `credential_snapshots` (ADR-0009) | derived safeguarding facts about a person; read on every page |
| Outbox and ledger | `outbox_events`, `processed_events`, `federation_notifications`, `jobs`, `failed_jobs` (ADR-0010) | side effects that must happen once |
| Secrets | `APP_KEY`, `LEARNING_CENTER_CLIENT_SECRET`, `METRICS_TOKEN`, the OIDC provider's signing keys, upstream's `API_BEARER_TOKEN` | any of them turns an outsider into a principal |

## Actors

| Actor | Trust | How they present |
|---|---|---|
| Applicant | signed in, acts on their own applications | OIDC bearer token from the provider, held server-side by the Next app (ADR-0007) |
| Organization administrator (reviewer) | signed in, acts on their organization's applications | same |
| Federation administrator | signed in, acts on every organization in the federation | same |
| Learning Center | a service the federation calls, never the reverse | the federation presents a client-credentials token with scope `credentials:read` (ADR-0009) |
| Anonymous caller | untrusted | whatever reaches the API |
| Operator | trusted; runs the worker, the reconciliation, the scrapes | shell access to the container; the scrape token |
| Upstream super admin | trusted by upstream's design; the most privileged principal | a Sanctum token that never expires (`config/sanctum.php`, `expiration => null`) |

## Entry points

| Entry point | Auth | Notes |
|---|---|---|
| `/api/v1/federation/*`, `/api/v1/federation-identity/me` | `auth:oidc` guard | JSON:API with actions; every request carries a request id and a server span |
| `/api/health/live`, `/api/health/ready` | none, by design | the platform probes before it holds secrets |
| `/api/health/checks`, `/api/metrics` | scrape token (B7) | operator information |
| `/api/v1/*` (upstream) | Sanctum | club management, public apply form on the super-admin token |
| Filament panel (`:3001/admin`) | session | upstream's admin surface |
| Outbox worker (`federation:work`) | none; operator-started | reads rows, calls consumers |
| Learning Center client | outbound | timeouts, service token, traceparent |
| Mock OIDC provider, mock Learning Center, Jaeger | none | **Dev only** |

## Tree 1 — Alter an application the attacker may not alter

Goal: change another person's application, or one's own past what the rules allow (a status change disguised as a field, a document the role does not require, a duplicate).

- 1.1 Send a JSON:API `PATCH` for an application that is not mine
  - 1.1.1 by guessing an id → **Mitigated.** `RegistrationApplicationPolicy::update` checks the actor through `ApplicationActorResolver`; a foreign id answers 403 (`RegistrationApplicationsHttpTest`). The listing is scoped in `RegistrationApplicationSchema::indexQuery`, so ids are not enumerable through the API either.
  - 1.1.2 by writing `status` as an attribute → **Mitigated.** `status` is `readOnly()` in the schema, and the model's `saving` hook throws on any status assignment outside `applyTransition` (ADR-0006, `ApplicationTransitionsTest`).
- 1.2 Send a JSON Patch (B7) with an operation on a field I may not touch
  - 1.2.1 `replace /reviewerNotes` as the applicant, hidden between allowed operations → **Mitigated.** `PatchApplicationFields` authorises every operation before applying any; one refusal refuses the patch with 403 `field_not_allowed` naming the path, and no row and no audit entry change (`ApplicationFieldsPatchHttpTest::test_one_forbidden_operation_refuses_the_whole_patch`).
  - 1.2.2 `replace /status` or `/applicantUserId` as anyone → **Mitigated.** Only the fields in the two allow-lists exist to the patch; everything else is 403 regardless of who asks.
  - 1.2.3 a nested path, an unknown operation, a `move` from a hidden field → **Mitigated.** `JsonPatch::parse` accepts four operations on one-level paths only; anything else is 422 `invalid_patch` before authorization runs.
  - 1.2.4 change a detail after submission → **Mitigated.** 409 `application_not_editable` once the application has left the applicant's hands.
  - 1.2.5 overwrite a concurrent change → **Partly.** A `test` operation lets the client guard its view (409 `patch_test_failed`); the row is locked for the transaction. Residual: a client that sends no `test` writes last-wins, which is the JSON:API update's behaviour too.
  - 1.2.6 a reviewer edits the applicant's details → **Mitigated.** Reviewer fields and applicant fields are separate allow-lists (`test_a_reviewer_may_not_touch_the_applicants_fields`).
- 1.3 Perform a transition I may not perform
  - 1.3.1 approve my own application → **Mitigated.** `TransitionApplication` asks the actor resolver which role the actor holds for this application and the transition table which role may perform the transition (403 `transition_not_allowed_for_actor`).
  - 1.3.2 approve from a status that does not allow it → **Mitigated.** 409 `illegal_transition` from the table; the audit entry records from and to.
  - 1.3.3 replay a submission to create a second one → **Mitigated.** Idempotency key on the action, `active_key` uniqueness in the database (INCIDENT-002).
- 1.4 Attach a document type the role does not require, or a payload → **Mitigated.** `DocumentType::requiredFor` and `DocumentNotAllowedException`; metadata only, no bytes accepted (ADR-0008), size and MIME validated as metadata.
- 1.5 Alter the audit trail → **Mitigated** in code: `AuditEntry` is written through `AuditRecorder` only and no route updates or deletes it. **Partly** at the database: nothing below PHP forbids an `UPDATE` by an operator with database access; a trigger or a write-once table is a deployment decision (B8).

## Tree 2 — Read another organization's data

Goal: list or fetch applications, documents, reviewer notes or snapshots of an organization the attacker does not administer.

- 2.1 List applications with a filter for another organization → **Mitigated.** `indexQuery` restricts to the actor's own applications, the organizations they administer, and the federations they administer; the filter narrows within that set, never widens it (`RegistrationApplicationsHttpTest`).
- 2.2 Fetch by id → **Mitigated.** `view` policy, 403 (`test_an_administrator_of_another_organization_cannot_reach_the_application`).
- 2.3 Read reviewer notes as the applicant → **Mitigated.** The `reviewerNotes` attribute is rendered only when `Gate::allows('review')` for the actor (`test_a_reviewer_writes_reviewer_notes_that_the_applicant_never_sees`).
- 2.4 Include a relationship that crosses the boundary (`?include=applicant`) → **Partly.** Include paths are limited to the application's own relations; the applicant resource exposes name and e-mail to a reviewer of that application, which is the review's purpose. Residual: no field-level filtering on the included `federation-users` resource beyond what the schema lists.
- 2.5 Read credential facts → **Mitigated** for the raw facts (the snapshot stores facts; the page renders the derived participation status and its age) and **Partly** for the derived status, which a reviewer of the application sees by design.
- 2.6 Read through the audit `history` attribute → **Mitigated.** The history renders action, time, actor name, from, to, reason; never the request id, internal ids or the previous state of fields (`RegistrationApplicationSchema`).
- 2.7 Read through the probes and metrics → see Tree 5.
- 2.8 Read through upstream's tenancy → **Upstream.** `ClubScope` and the super-admin bypass (M0 quiz Q1 and Q2); the fork does not change them and the federation routes do not use them.

## Tree 3 — Obtain or forge a token

Goal: act as a person, as the federation service, or as upstream's super admin.

- 3.1 Forge a person's OIDC token
  - 3.1.1 sign with my own key, or with a symmetric algorithm and the public key as the secret → **Mitigated.** `OidcTokenVerifier` verifies RS256 against the issuer's published key set (one refresh on an unknown key id, then rejection), then issuer, audience, subject and expiry (`OidcTokenVerifierTest`: unknown key, rotation, symmetric algorithm, other issuer, other audience, expired).
  - 3.1.2 change the `sub` in a valid token → **Mitigated.** Signature covers the claims.
  - 3.1.3 present a token to make myself an administrator → **Mitigated.** Capabilities come from the database tables, never from claims (ADR-0007).
- 3.2 Steal a person's token
  - 3.2.1 from the browser → **Mitigated.** The token stays in the Next server's session; the browser holds a session cookie (ADR-0007).
  - 3.2.2 from the logs or traces → **Mitigated.** `SecretsNeverLoggedTest` asserts no log line and no span attribute contains the presented token, the service token or the client secret, over a success path, a 401 path and a provider call. The access line carries request id, user id, route, status.
  - 3.2.3 from the audit table → **Mitigated.** Audit entries hold actor ids and field values, never headers.
- 3.3 Obtain the federation's service token → **Partly.** The client secret lives in `.env` (gitignored; gitleaks in history mode before every push); the token is cached in Laravel's cache store for its lifetime minus a margin. Residual: the cache store is the file store in Compose, readable by anyone with container access; the production store is B8's decision (`docs/future-work.md`).
- 3.4 Use the service token against the federation → **Mitigated by design.** The federation never accepts it: its guard verifies person tokens with the person audience; the service token's audience is the Learning Center's.
- 3.5 Obtain upstream's super-admin token → **Upstream, Open.** `web_application/src/services/club-api.ts` reads `API_BEARER_TOKEN`, a Sanctum token with every ability for every club and no expiry, held by the Next server for the public apply form (M0 finding, interview guide I2). The federation slice does not use it. Recorded for the upstream offer; the fork's `.env.local` is gitignored and never in docs.
- 3.6 Use the mock provider's "any user" login → **Dev only.** `docker/oidc` accepts any subject with a mapped claim set; a deployment must point `OIDC_ISSUER` at a real provider, which the B8 release checklist asserts.

## Tree 4 — Disrupt the queue or the provider

Goal: stop approvals from taking effect, hide a change, or exhaust the service.

- 4.1 Make the Learning Center slow or absent
  - 4.1.1 during a page load → **Mitigated.** Pages never call the provider; they read the snapshot (ADR-0009, INCIDENT-001).
  - 4.1.2 during a reviewer's refresh → **Mitigated.** Bounded timeouts, 503 `learning_center_unavailable`, the last snapshot still shown.
  - 4.1.3 during the worker's refresh → **Mitigated.** Retries with backoff, then parked; `federation:outbox-replay` (INCIDENT-003).
- 4.2 Kill the worker → **Mitigated for detection.** Readiness turns 503 once the oldest unpublished fact exceeds the age limit; `federation_outbox_oldest_unpublished_seconds` rises (ADR-0012). **Open** for recovery: no supervisor restarts it in Compose (`docs/future-work.md`, B8).
- 4.3 Deliver an event twice → **Mitigated.** `processed_events` ledger with `insertOrIgnore` per consumer (`OutboxTest`).
- 4.4 Poison an event so a consumer throws forever → **Mitigated.** Attempts are counted, the row parks, the worker loop survives the exception; the other events continue.
- 4.5 Flood the API → **Partly.** Upstream's throttle, 60 requests per minute per user and per IP address when anonymous, applies to the federation routes (`app.api_rate_limit_per_minute`, `RouteServiceProvider`). Residual: an anonymous flood from many addresses is bounded only by the 401 path's cost, which includes one cached key-set lookup; a network-level limit is a deployment concern (B8).
- 4.6 Flood the outbox with a large patch or document → **Mitigated.** Field lengths are validated (`PatchApplicationFields::RULES`, document metadata rules); one audit entry per patch.
- 4.7 Deny service through a dependency's parser → see Tree 6 (the commonmark advisories).

## Tree 5 — Learn from public surfaces

Goal: learn the environment, the dependencies, the counts or the errors without a token.

- 5.1 `/api/health/live` → **Accepted.** Answers `ok` and the time; nothing else.
- 5.2 `/api/health/ready` → **Accepted, Partly.** Names three dependencies and whether they answer, including the outbox age. This is what a load balancer needs; it also tells an attacker when the worker is down. Residual accepted: the alternative is a probe the platform cannot use.
- 5.3 `/api/health/checks` → **Mitigated (B7).** Upstream's checks name the environment, the debug flag, disk and database state; the endpoint now requires the scrape token, which the shipped `.env.example` sets (`ObservabilityHttpTest::test_checks_require_the_same_token_while_the_probes_stay_open`).
- 5.4 `/api/metrics` → **Mitigated (B7).** Counts of applications by status, outbox depth and age, failed jobs, stale snapshots: no personal data, but a picture of the operation. Behind the same token by default; `docs/OBSERVABILITY.md` says when leaving it open is defensible.
- 5.5 Error messages
  - 5.5.1 federation errors → **Mitigated.** Domain exceptions carry the domain's message and a stable code; everything else is a 500 with the request id and no message (`RendersDomainExceptions`).
  - 5.5.2 upstream's `apply` → **Upstream.** Maps every `Throwable` to 422 with the exception message (M0 quiz Q3).
- 5.6 OpenAPI documents (`:3002`, `api/public/*_openapi.json`) → **Accepted.** They describe the contract, which is public by intent; the examples are sampled from the development seed and contain no real person.
- 5.7 Logs and traces → **Mitigated.** No tokens (3.2.2); request ids are validated against a pattern before they are logged, so a caller cannot inject line breaks or markup (`AssignRequestId`).
- 5.8 CORS → **Partly, Upstream.** `allowed_origins => ['*']` with `supports_credentials => false`: a browser on any origin may call the API, but without cookies, and bearer tokens never sit in the browser for the federation slice (3.2.1). Residual: upstream's Sanctum tokens are sent by its own frontend; the wildcard is upstream's choice.

## Tree 6 — Supply chain

Goal: run code the maintainers did not write, through a dependency, an image or a mock.

- 6.1 A vulnerable PHP dependency → **Partly.** `composer audit` on 2026-09-03 (`docs/baseline/security_audit_2026-09-03.txt`): **13 advisories in 3 packages**, all upstream's: `filament/filament` 5.7.3 (2: MFA code reuse, password-validity disclosure on the panel login; fixed in 5.7.5 and 5.7.6), `league/commonmark` 2.8.3 (10: denial of service and attribute-filter bypasses in extensions; fixed by 2.9.1 and 2.10.0), `livewire/livewire` 4.3.3 (1: DOM-based XSS; fixed after 4.3.3). Reachability: the federation slice renders no Markdown and serves no Livewire component; the Filament panel and Laravel's Markdown mail are upstream's surfaces. See the update policy below.
- 6.2 A vulnerable JavaScript dependency → **Partly.** `npm audit` on 2026-09-03: **8 advisories (7 high, 1 critical)**: `next` 14.2 (four advisories; the fix is Next 16, a major), `postcss` nested under `next`, `sharp` (image optimisation; fix is a major), `swiper` (critical prototype pollution; used by upstream's public pages; fix is a major), and three build-time packages (`brace-expansion`, `js-yaml` under `@redocly/openapi-core`, `nanoid`) with non-breaking fixes. The federation pages use none of `sharp` or `swiper`; they run on `next`. The API's own `package.json` (build tooling for upstream's assets) carries one more: `nanoid`, high, with a non-breaking fix.
- 6.3 A dependency added by the fork → **Mitigated.** The fork added `firebase/php-jwt`, the OpenTelemetry SDK and exporter, `spatie/health` was upstream's; each is pinned in the lock file and none appears in either audit.
- 6.4 Images → **Partly.** Base images are pinned by tag, not digest: `php:8.3-fpm-alpine3.23` and `php:8.3-cli-alpine3.23`, `node:20-alpine`, `mariadb:11.8`, `postgres:16-alpine`, `jaegertracing/all-in-one:1.60`, `ghcr.io/navikt/mock-oauth2-server:2.1.10`, and upstream's Swagger UI image with no tag at all. Digest pinning, a tag for the Swagger image and an image scan belong to B8's release checklist.
- 6.5 The mocks → **Dev only.** The mock OIDC provider and the mock Learning Center are Compose services with no authentication; a deployment that still points at them has no identity boundary. The B8 checklist asserts `OIDC_ISSUER` and `LEARNING_CENTER_BASE_URL` are not the Compose hostnames.
- 6.6 CI → **Partly.** Actions are pinned by major tag; the workflow runs on the fork's own runners with no secrets beyond `GITHUB_TOKEN`; pull requests from forks of the fork would run the same workflow (no secrets to leak). Residual: SHA pinning of actions (B8).

### Dependency update policy (planned, applied from B8)

1. **Reachable from the federation request path**: fix in the milestone that finds it, with the suite green on the three engines.
2. **Reachable only through upstream's surfaces** (Filament, Livewire, Markdown mail, the public pages): take the patch when it is within the same major and the suite stays green; record it in the release notes; offer it upstream with the B9 offer if upstream has not moved.
3. **Build-time only**: `npm audit fix` without `--force` at each release.
4. **Fix requires a major** (Next 16, `sharp` 0.35, `swiper` 14): record in `docs/future-work.md` with the advisory and the reason it waits; re-evaluate at each release; never silently ignore.
5. The B8 release checklist runs both audits and blocks a release on a high or critical advisory in a runtime package that rule 1 or 2 covers.

None of the advisories above was patched in B7: the review's job was to know what is reachable and to write the policy. B8 applied rules 2 and 3 (`docs/baseline/security_audit_after_b8_2026-09-04.txt`): Composer 13 → 0 advisories (Filament 5.7.8, commonmark 2.10.0, Livewire 4.4.3, the framework following to 13.30.1 within the major); npm 8 → 4 in the frontend (the four that need a major: `next`, `postcss` under it, `sharp`, `swiper`) and 1 → 0 in the API's build tooling. Rule 4 holds the four majors in `docs/future-work.md`; rule 5 is the `dependency-audit` job in CI plus the release checklist (`docs/RELEASE.md`).

## What this review did not do

- No penetration test, no fuzzing, no dynamic scanner. The trees are read from the code and the tests; the exercises E21 and E22 in the internal record are manual.
- No review of upstream's Filament panel or public apply form beyond what M0 found.
- No deployment exists, so network controls, TLS, secret storage and image scanning are B8's design, labelled planned there.

## Evidence

| Claim | Where |
|---|---|
| Field-level authorization, atomic refusal, reviewer notes hidden, media type, malformed documents, stale test | `api/tests/Feature/Federation/Http/ApplicationFieldsPatchHttpTest.php` (11 tests) |
| No token in logs or spans | `api/tests/Feature/Federation/Http/SecretsNeverLoggedTest.php` |
| Checks and metrics behind the token, probes open, token shipped | `api/tests/Feature/Federation/Http/ObservabilityHttpTest.php` |
| Dependency audits | `docs/baseline/security_audit_2026-09-03.txt` |
| Manual walk of the token gate and a refused patch on the running stack | `docs/baseline/security_review_2026-09-03.txt` |
| Earlier controls | M3 identity tests, M4 authorization tests, `OutboxTest`, INCIDENT-001 to 003 |
