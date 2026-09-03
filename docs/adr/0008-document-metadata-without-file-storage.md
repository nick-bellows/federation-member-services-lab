# ADR-0008: Document metadata without file storage, and a second JSON:API server

- **Status:** accepted
- **Date:** 2026-09-02
- **Milestone:** M4

## Context

The review slice needs applicants to "provide required documents" and reviewers to judge them, and it needs an API contract for windows, applications, documents and decisions. The portfolio's roadmap forbids general-purpose uploads before the workflow exists, forbids real personal documents at any point, and asks that the API keep upstream's contract style. Upstream ships `spatie/laravel-medialibrary` and a `media` resource on its own server, tied to Sanctum and club scoping.

## Decision

- **Documents are metadata rows** (`application_documents`: type, file name, MIME type, size, SHA-256 checksum, review status, note, reviewer, timestamps). No bytes are stored anywhere. The browser hashes the chosen file locally with WebCrypto and sends only the metadata (`web_application/src/app/[lang]/member/applications/[id]/DocumentsPanel.tsx`); the API validates type, size and checksum shape. Required document types per role live in one enum (`DocumentType::requiredFor`), and submission is refused until every required type has metadata and a date of birth is present.
- **A second JSON:API server, `federation`,** under `/api/v1/federation` with its own schemas, requests, policies and the `oidc` guard (`App\Federation\JsonApi\Server`). Upstream's `v1` server is untouched. Index queries are scoped per user in the schema (`indexQuery`), object-level rights in policies, and the transition rules in the domain actions, so a controller cannot bypass any of them.
- **Custom actions over HTTP** (`-actions/submit`, `cancel`, `start-review`, `request-information`, `approve`, `reject`) call `TransitionApplication` with the request's user, an optional reason from `meta.reason`, the `Idempotency-Key` header and the request id. Domain exceptions map to 403, 409 or 422 with stable `code`s in one trait (`RendersDomainExceptions`).
- **OpenAPI for the typed client:** the package generator does not describe custom actions, so `php artisan federation:openapi` runs it and merges the six action paths from the document's own resource schema into `api/public/federation_openapi.json`; the frontend generates `schema_federation.d.ts` from that file.
- **Correlation ids:** `AssignRequestId` accepts a well-formed `X-Request-Id` or mints one, echoes it, and the actions store it on every audit entry.

## Alternatives considered

1. **Real uploads through spatie/medialibrary on local disk** — upstream already has it, but files on a bind mount are neither the production shape nor allowed by the roadmap at this stage.
2. **S3-compatible storage with signed URLs now** — the production shape, but a bucket, credentials and a pre-signed upload flow are a milestone of their own; nothing in the review workflow needs the bytes.
3. **Extend upstream's `v1` server** — fewer files, but that server selects Sanctum and applies club scoping in `serving()`; mixing guards there is fragile and would touch upstream behaviour.
4. **Plain JSON controllers instead of JSON:API** — fastest, but it abandons the contract style and the generated TypeScript types that make the frontend honest.

## Consequences

- Positive: the workflow is complete and demonstrable with synthetic files; reviewers see checksums so a later storage layer can prove integrity; the federation API has a real, generated contract; no personal document can leak because none is stored.
- Negative: a "document" is a promise until object storage exists; the merged OpenAPI document is partly hand-described and must be regenerated when the API changes (CI can diff it); the `history` attribute is computed per resource and will need a limit or pagination for long-lived applications.
- Follow-ups: object storage with signed upload URLs and checksum verification (release engineering); a CI step that fails when `federation_openapi.json` is stale; document retention rules together with audit retention.
