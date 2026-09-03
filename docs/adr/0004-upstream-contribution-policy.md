# ADR-0004: What is offered upstream, and how

- **Status:** accepted
- **Date:** 2026-09-02
- **Milestone:** M1

## Context

The brief asks for genuine, minimal upstream contributions and forbids flooding the maintainers. Upstream (`vereinfacht/vereinfacht`) has never merged an external pull request, has no `CONTRIBUTING.md`, and keeps its issue labels unused; how it receives outside work is unknown. Milestone 1 produced three candidate changes: the `env()` fix (ADR-0002), the `.gitattributes` normalisation, and a CI workflow.

## Decision

- **Offer first: the `env()` fix together with `.gitattributes`**, as one small pull request after opening an issue that describes the reproduction in `docs/baseline/env_bug_before_fix.txt`. Both are generic, behaviour-preserving when configuration is not cached, and covered by tests. The pull request is prepared on a branch from `upstream/main` so it carries none of the fork's documentation.
- **Do not offer the CI workflow yet.** Upstream issue #7 asks for formatting and style enforcement, but the maintainers' preferences (which jobs, which engines, whether Pint should block) are unknown, and their code currently fails Pint 215 times. Ask in the issue thread first; offer a workflow only if they answer.
- **Never offer** federation-specific code, fork documentation, or anything that changes upstream behaviour without a test.
- Every offer, answer and outcome is recorded in `docs/UPSTREAM_ANALYSIS.md` §10 and in the README's contribution section, in the state it is actually in (`proposed`, `open`, `merged`, `declined`, `no response`). Nothing is described as merged unless it is.

## Alternatives considered

1. **Offer all three at once** — three unsolicited pull requests to a team that has never merged one is noise, not contribution.
2. **Offer nothing until the fork is public** — delays useful, low-risk fixes for no benefit; the fork's visibility is a separate decision.

## Consequences

- Positive: one clear, testable, generic change reaches the maintainers with a reproduction; the fork's claims about upstream work stay verifiable.
- Negative: the pull request cannot be opened until the fork exists on GitHub (Phase A5), so the offer waits for the visibility decision; upstream may not respond.
- Follow-ups: open the issue and the pull request in A5; revisit the CI offer if the maintainers reply on #7.
