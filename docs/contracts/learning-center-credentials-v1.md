# Contract: Learning Center credentials, version 1

`learning-center.credentials.v1` — how this repository (the consumer) reads a person's credential facts and the Learning Center's derived eligibility from the Learning Center (the provider). Decided in [ADR-0009](../adr/0009-learning-center-credentials-contract.md); roadmap decision 6 places the endpoint in the Learning Center.

The contract is executable on both sides. The fixture files under [`api/tests/Fixtures/learning-center/credentials/`](../../api/tests/Fixtures/learning-center/credentials/) are the reference responses: the provider's tests assert that its handler produces this shape for its seeded members, this repository's tests are fed from these files, and the mock service in Docker Compose serves them verbatim. A change to the shape changes the fixtures, and the other side's tests fail before a deployment would.

## Request

```
GET /v1/members/{subject}/credentials
Authorization: Bearer <service token>
Accept: application/json
```

- `{subject}` is the OIDC subject of the person as issued by the shared identity provider, URL-encoded (`mock%7Calex`). Both systems store it as a unique identity column; no foreign id is stored or synchronised. Subjects are provider-scoped: the two demo stacks match only when both use the same provider.
- The service token is an OAuth2 client-credentials token from the shared provider with audience `https://learning-center.northgate.example` and scope `credentials:read`. The provider verifies signature, issuer, audience and expiry with its existing verifier and authorises on the scope. No member row is involved: the caller is a service, not a person.
- Enumeration is bounded by authentication and scope, not by how guessable a subject is.

## Response `200`

| Field | Type | Meaning |
|---|---|---|
| `contract` | string | Always `learning-center.credentials.v1`. The consumer rejects any other value. |
| `member.id` | uuid | The provider's member id, for support and reconciliation. Never used as a key by the consumer. |
| `member.subject` | string | The subject that was asked for. |
| `member.roles` | string[] | The provider's roles for the person (`learner`, `instructor`, `admin`, `coach`, `referee`). |
| `as_of` | RFC 3339 instant | When the provider evaluated the answer. The consumer stores it next to its own fetch time. |
| `eligibility.status` | enum | `eligible`, `suspended`, `ineligible_lapsed`, exactly the provider's public eligibility vocabulary. The consumer branches on this field only. |
| `eligibility.reason` | string | Free text for people. Never parsed. |
| `holds[]` | list | Active holds: `source` and `active: true`. The hold's reason text is deliberately not part of the contract. |
| `safeguarding.safesport_training` | object | `expires_at` (date) and `valid` (bool, evaluated by the provider at `as_of`). |
| `safeguarding.background_check` | object | Same shape. |
| `role_credentials[]` | list | One per role credential: `role`, `credential_type`, `issued_at`, `expires_at`, `valid` (provider-evaluated). |

The consumer never re-derives `valid` or `eligibility.status` from the dates: expiry semantics (inclusive day, grace period) belong to the provider and are tested there.

## Errors

| Status | Body | When |
|---|---|---|
| `401` | `{"error":"unauthorized"}` | Missing or invalid token. |
| `403` | `{"error":"forbidden"}` | Valid token without the `credentials:read` scope. |
| `404` | `{"error":"member not found"}` | No member with that subject. The consumer records "no Learning Center record" and does not retry before its reconciliation interval. |

## What the consumer does with it

Participation for an approved application is derived on read from the stored snapshot, never from a live call:

- `may_participate` — the application is approved, `eligibility.status` is `eligible`, and for a coach or referee application a `role_credentials` entry for that role is `valid`.
- `blocked` — any of those fails; the reasons are listed (`not_approved`, `hold_active`, `credential_lapsed`, `role_credential_missing`).
- `unknown` — there is no snapshot yet, or the last answer was `404`.

Every answer carries the snapshot's `as_of`, the consumer's `fetched_at`, and `stale: true` when the snapshot is older than the configured limit. Snapshots are refreshed after an approval (best effort, bounded by the timeout), by a reviewer's explicit refresh, and by the reconciliation command; a change in `eligibility.status` is audited as `credentials.changed`.

## Timeouts and failure

The consumer uses a connect timeout of 300 ms and a total timeout of 800 ms. A timeout, a connection failure or a `5xx` is "unavailable": the approval still succeeds, the page shows the last snapshot marked stale or `unknown`, the explicit refresh answers `503 learning_center_unavailable`, and reconciliation repairs it later. This is the scenario rehearsed in [INCIDENT-001](../incidents/INCIDENT-001-slow-credential-service.md).

## Versioning

The version is in the `contract` field and in this document's name. Adding a field is compatible. Removing or renaming a field, or changing an enum, is a new version with a new fixture set; the old version keeps being served until every consumer has moved.

## Privacy

The contract carries the minimum the federation needs to decide participation: statuses, dates and roles. It carries no date of birth, no hold reasons, no course or assessment detail. Both systems' seed data is synthetic.

## Provider notes (learning-center-reference, pull request #1, 2026-09-03)

The provider implements this document with three differences from the fixture values, all within the contract:

- `eligibility.reason` carries the provider's own sentences (`all safeguarding requirements current`, `active disciplinary hold (safesport)`, `expired role credential`), not the fixture strings. Free text, never parsed.
- `safeguarding.*.expires_at` is `null` with `valid: false` when nothing is on file. Consumers must accept a null date.
- `member.roles` is in database order (`["coach","learner"]` for the seeded Alex). Order carries no meaning.

The provider authorises on the `scope` claim (space-separated) or an `scp` array, and in demo mode on `DEMO_SERVICE_TOKEN`. Its OpenAPI describes the token as an HTTP bearer scheme with the scope in the description. Its seeded subjects live in its own namespace (`demo|learner`, `demo|referee-sam`, `demo|referee-riley`); the shared-provider case is exercised only when both stacks use the same issuer.
