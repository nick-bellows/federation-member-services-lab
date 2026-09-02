# Roadmap

Last verified: 2026-09-02

## Handoff snapshot

| Field | Current state |
| --- | --- |
| Lifecycle | `DRAFT` - Milestone 0 engineering archaeology only |
| Visibility | Local workspace only; no configured remote |
| Portfolio role | Future modernization, Laravel/Next.js, registration, review, and organization-aware workflow evidence |
| Upstream | Fork of `vereinfacht/vereinfacht`; upstream behavior and authorship must remain explicit |
| Original product claim | None yet; no federation workflow has been implemented |

Start with `docs/UPSTREAM_ANALYSIS.md` and `docs/adr/`. The sibling `learning-center-reference` owns education, certification, and safeguarding-derived eligibility. This repository owns organizations, membership, registration applications, document review, and audit, and may consume credentials through an HTTP contract only.

## Current milestone - M1 baseline and one safe modernization change

Goal: prove the inherited system can be understood, tested, and improved without a rewrite before adding a federation workflow.

### Work

1. Reproduce and record the upstream API and web test/build baseline from a clean checkout-equivalent environment.
2. Inventory authentication, tenancy scopes, authorization, seeded credentials, environment handling, uploads, and existing API contracts.
3. Fix one bounded, defensible issue already identified in `docs/UPSTREAM_ANALYSIS.md`, preferably the `env()`/configuration-cache behavior or development config-cache trap.
4. Add a regression test that fails before the fix and passes after it.
5. Record whether the change should be offered upstream; do not imply upstream acceptance.
6. Decide and document the end-to-end test tool for the fork's future critical path.

### Acceptance criteria

- Baseline commands, versions, failures, and environmental assumptions are retained.
- The selected change is small enough to explain line by line and does not alter unrelated upstream behavior.
- A regression test demonstrates the issue and fix.
- Fork attribution, license, and original-versus-upstream diff remain obvious.
- No product milestone is claimed complete.

## Next product milestone - one registration review slice

Only after M1:

```text
fictional organization admin starts registration
-> participant submits synthetic application metadata
-> reviewer requests or approves one requirement
-> registration status is derived
-> immutable audit entry is visible to authorized roles
```

Keep organization boundaries explicit. Use document metadata or safe generated fixtures; do not begin with general-purpose uploads. Define the Learning Center credential API contract without sharing databases.

## Presentation and hosting decision

Do not deploy this repository yet. A public upstream application with only archaeology notes would confuse authorship and add little hiring value. Once the original registration-review slice, tests, and attribution are complete, build a static modernization case study first. A small container host such as Railway can be evaluated later, but account creation, a public remote, and any cost require explicit approval.

Vercel may eventually host the Next.js frontend, but splitting the inherited Laravel/Next.js system across services is not useful before the workflow exists. Replit is not appropriate for establishing a faithful upstream baseline.

## Stop conditions

- Do not publish or deploy until Nick approves the remote and visibility.
- Do not retain or expose upstream default credentials in a public deployment.
- Do not claim original authorship of upstream code or a completed federation platform.
- Do not duplicate Learning Center domains or share its database.
- Do not broaden beyond one registration-review path.

## Verification before changing status

Run the recorded upstream and fork API/web tests, dependency/security checks, OpenAPI validation, and the new regression test. A local build does not establish a deployable or publicly secure system. Deferred ideas remain in `docs/future-work.md`.
