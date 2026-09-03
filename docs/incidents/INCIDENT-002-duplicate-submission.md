# INCIDENT-002 — Duplicate submission of a registration application

- **Type:** controlled incident exercise (the brief's "Incident 2"), designed and reproduced in Milestone 4 rather than suffered in production
- **Date:** 2026-09-02
- **Systems:** federation API (`/api/v1/federation`), member pages

## Scenario

A participant on a slow connection presses "Submit application", sees nothing happen, and presses it again. In the same season another participant, unsure whether the first attempt worked, starts a second application for the same organization and role from a second tab. A naive implementation produces two submitted applications, two audit trails, two queue entries, and a reviewer who approves one and rejects the other.

## Symptoms the system must not show

- Two live applications for the same person, organization, season and role.
- A second "submitted" audit entry for one click.
- A 500 or a misleading 422 on the retried request.

## Detection

- **Automated:** `api/tests/Feature/Federation/Http/RegistrationApplicationsHttpTest.php::test_a_complete_application_submits_and_a_retry_with_the_same_key_is_idempotent` and `…::test_a_closed_window_and_a_duplicate_are_conflicts`; domain-level `StartApplicationTest` (both duplicate layers, idempotent start).
- **Operational:** an alert on `audit_entries` with two `application.submitted` rows for one application inside a minute would have caught the naive behaviour; the correlation id on each entry says which request produced which row.

## Root cause, in the naive design

Submission was a plain state write with no identity for the *attempt*, and uniqueness was checked in application code only, which cannot see a concurrent request.

## How the system behaves now

| Layer | Mechanism | Where |
|---|---|---|
| Attempt identity | The page mints one idempotency key per attempt and reuses it for retries; the API stores it with the transition and answers the same state for the same key without a new audit row | `ApplicantControls.tsx`, `TransitionApplication::execute`, `transition_idempotency_key` |
| State machine | A second submit of an already submitted application with a *new* key is an illegal transition, 409 `illegal_transition` | `ApplicationTransitions` |
| One live application | `StartApplication` checks for a live application first (domain exception, 409 `duplicate_application`) | `StartApplication` |
| Concurrency backstop | `active_key`, a nullable unique column set while the application is live, rejects the loser of a race at the database; the violation is translated to the same exception | migration `…create_registration_applications_table.php`, `StartApplication` |
| Row lock | The transition locks the row for update inside its transaction, so two reviewers deciding the same application serialize | `TransitionApplication::execute` |

Observed in the HTTP test: first submit 200 `submitted` with request id `req-submit-0001` echoed and stored; retry with the same key 200, audit row count unchanged; a new key 409; a second start for the same slot 409 `duplicate_application`.

## What was not exercised

`lockForUpdate` is a no-op on SQLite, so the row-lock property is asserted by reading the code, not by the test suite; the MariaDB CI job is where it becomes real. The browser double-click itself is simulated by the second request with the same key, not by a real double event; a Playwright test that clicks twice would add little because the button disables itself while pending.

## Permanent fix and regression tests

The mechanisms above are the design, not a patch; the tests named under Detection are the regression tests.

## Lessons

- Idempotency needs an identity for the attempt, not just for the resource.
- Uniqueness that matters must live in the database as well as in code.
- A 409 with a stable code is a better answer to a duplicate than a 422 with a sentence.
