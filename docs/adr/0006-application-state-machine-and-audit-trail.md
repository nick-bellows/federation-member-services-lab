# ADR-0006: Application lifecycle as an explicit state machine with an append-only audit trail

- **Status:** accepted
- **Date:** 2026-09-02
- **Milestone:** M2

## Context

Upstream stores membership status as a nullable string with three values and treats null as a fourth state; one action owns the only transition, without a transaction, and the controller turns every failure into a 422. A registration application has seven states, two kinds of actor, transitions that require a reason, and consequences that must be traceable to a person. Boolean flags or a free-text status fail predictably here: contradictory combinations, no record of who moved it or why, and a rule that can be bypassed from any controller.

## Decision

- **A PHP enum for the seven states and a single transition table** (`App\Federation\StateMachine\ApplicationTransitions`): from, to, required actor, reason required. Anything not in the table is illegal regardless of privileges. A unit test pins all 49 pairs.
- **One writer.** `TransitionApplication` locks the row, checks legality, actor and reason, writes status and timestamps, writes the audit entry, all in one transaction, and dispatches `ApplicationTransitioned` after commit. The model throws if status is assigned any other way.
- **Actors are relative to the application**: applicant (the filer) or reviewer (an administrator of the organization or its federation), resolved by `ApplicationActorResolver`. Upstream's super admin is not a reviewer.
- **Own `audit_entries` table**, written by `AuditRecorder` inside the same transaction: actor, action, resource, previous and new relevant state, reason, request id, timestamp. The model refuses updates and deletes; the table has no `updated_at`.
- **Portable partial uniqueness for duplicates.** `active_key` holds `applicant:organization:season:role` while the application is open or approved and null otherwise, under a unique index that MariaDB, PostgreSQL and SQLite all honour (NULLs are distinct). `StartApplication` checks first and raises a domain exception; a unique-violation under a race is translated to the same exception. An idempotency key makes retried "start" calls return the same row.

## Alternatives considered

1. **`spatie/laravel-model-states`** — solid package with state and transition classes; adds a dependency and conventions to defend for seven states that fit in one array. Revisit if a second lifecycle appears.
2. **Transition command classes** (`SubmitApplication`, `ApproveApplication`, …) — most explicit, most files; the rules would be spread across nine classes instead of one table.
3. **`spatie/laravel-activitylog`** — generic model-change logging; the transition semantics (actor kind, reason, request id, before and after state) would still need custom fields.
4. **Event sourcing with the event log as source of truth** — strongest audit, but projections, replay and their tests are a cost the review slice does not need; the outbox in M6 is the reliability step that is needed.
5. **A database `CHECK` constraint on status** — desirable, but Laravel's schema builder has no portable `check()`, and MariaDB and PostgreSQL syntax differ; the enum cast plus tests hold the line until the PostgreSQL milestone decides how to express it per engine.

## Consequences

- Positive: legal transitions are documentation that executes; every state change is attributable; duplicates are impossible at two layers; controllers cannot bypass the rules.
- Negative: the transition table must be edited on purpose when the workflow changes, and the unit test with it; the audit table grows without bound and will need retention rules before production.
- Follow-ups: request ids flow from an HTTP middleware (A4); the after-commit event is the seam for the outbox (M6); approval's side effect of registering the person is defined with the review slice.
