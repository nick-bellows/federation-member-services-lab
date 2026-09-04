# ADR-0014: Security review as attack trees, field-level authorization through JSON Patch, and token-gated operator surfaces

- Status: accepted (2026-09-03, owner's decisions at the start of B7)
- Milestone: B7
- Related: ADR-0006 (state machine and audit), ADR-0007 (identity boundary), ADR-0012 (observability), [`docs/THREAT_MODEL.md`](../THREAT_MODEL.md), [`docs/OBSERVABILITY.md`](../OBSERVABILITY.md)

## Context

The brief asks for a threat model covering a stated list (identities, applications, documents, audit, the credential feed, the public surfaces, dependencies) and for JSON Patch on one resource with field-level authorization. Six milestones had added controls without a single document saying which attacker each one stops. The slice had two update paths that authorised at the resource level only: the JSON:API `PATCH`, which the schema's `readOnly` markers protect field by field but only for everyone at once, and the transition actions. A reviewer had nowhere to keep working notes that the applicant would not see. The operator endpoints from B5 were open by default, with the token optional.

## Decision

1. **Attack trees, not a STRIDE table.** One tree per attacker goal (six goals), STRIDE used as the checklist when listing a node's children, every leaf naming the control in the code and the test that exercises it, or the gap and where it is recorded. A table by asset and STRIDE letter was the alternative; it is complete and dull, and it does not say what an attacker would try first. The trees are read top-down by a reviewer and cross-checked against the tests; the legend distinguishes mitigated, partly, open, upstream and dev-only.
2. **A dedicated route for RFC 6902.** `PATCH /registration-applications/{id}/-actions/fields` with the media type `application/json-patch+json` as the contract (415 otherwise). The JSON:API update keeps its document format and its whole-attribute semantics; the patch route adds the per-operation semantics: parse the whole document first (422 `invalid_patch`), authorise every operation against the acting person's allow-list before applying any (403 `field_not_allowed` naming the path and operation; nothing applied), apply inside one transaction with the row locked, honour `test` operations as the client's guard against a stale view (409 `patch_test_failed`), validate every value, write one audit entry with the previous and new value of every field touched. Applicants may touch `dateOfBirth`, `phone` and `applicantNotes` while the application is a draft or needs information (409 `application_not_editable` after that); reviewers may touch `reviewerNotes`, a new column the schema renders only when the actor may review. Status and every other attribute exist to the patch as nothing: refused regardless of who asks.
3. **The scrape token on by default.** `/api/health/checks` joins `/api/metrics` behind `METRICS_TOKEN`; the shipped `.env.example` sets a development value and a test asserts it stays set; liveness and readiness remain open because a platform probes before it holds secrets. Emptying the token is an operator's explicit choice, documented as defensible only where the network restricts the endpoints.
4. **Secrets are proven absent, not assumed.** A test runs a success path, a rejected-token path and a provider call and asserts that no log line and no span attribute contains the person's token, the service token or the client secret, and that nothing shaped like a JWT appears at all.
5. **Dependency advisories are catalogued and policied, not patched here.** Both audits are re-run and retained; each advisory is classified by reachability from the federation slice; an update policy says what B8 takes at each release. Patching upstream's lock files is a release concern with its own tests and notes.

## Alternatives considered

1. **STRIDE table by asset** — complete, but it lists fears rather than paths and buries the one upstream finding that matters (the super-admin token) among thirty cells. Rejected as the deliverable; kept as the checklist.
2. **Field-level authorization inside the JSON:API update** — the library validates a resource document as a whole and its `readOnly` markers cannot vary by actor; teaching it per-actor rules means a custom request class per role and still no `test` operation or per-operation refusal. Rejected.
3. **JSON Merge Patch (RFC 7396)** — simpler documents, but no `test`, no way to distinguish "set to null" from "leave alone" without conventions, and no per-operation error. Rejected for this resource; the JSON:API update already covers the merge case.
4. **A generic JSON Patch library applied to the model's attributes** — would accept paths the resource does not offer and apply before authorising. Rejected; the parser is forty lines and refuses what it does not know.
5. **Leaving checks open** — the checks name the environment and the debug flag; that is operator information. Rejected.
6. **Patching the advisories in this milestone** — the fixes for `next`, `sharp` and `swiper` are majors with their own risks; the Composer ones sit in upstream's panel. Deferred to B8 by policy rather than ignored.

## Consequences

- The federation API has four error codes more (`invalid_patch`, `field_not_allowed`, `patch_test_failed`, `unsupported_media_type`) and one attribute more (`reviewerNotes`, reviewers only); the OpenAPI document and the generated types carry the new path.
- Every deployment must set `METRICS_TOKEN` or consciously empty it; the runbook's hourly check sends it.
- The threat model is a living document: a milestone that adds an entry point adds a branch, and B8's release checklist runs the audits the policy depends on.
- `reviewer_notes` is a new column on `registration_applications`; the migration is idempotent on the three engines.
- Follow-ups recorded in `docs/future-work.md`: the major upgrades the audits ask for, a write-once audit table at the database, SHA-pinned actions and digest-pinned images, a tag for the Swagger UI image, and the upstream findings (super-admin token, `Throwable` to 422, CORS wildcard) that go to the B9 offer.
