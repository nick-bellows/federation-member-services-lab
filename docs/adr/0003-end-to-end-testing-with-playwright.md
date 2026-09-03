# ADR-0003: End-to-end testing with Playwright

- **Status:** accepted
- **Date:** 2026-09-02
- **Milestone:** M1 (decision), M3/M4 (first suite)

## Context

Upstream's README documents a Cypress project under `/e2e` that is not in the repository (upstream issue #197), and the frontend has no tests of any kind. The fork will add a sign-in flow, a registration workflow and a review queue, and needs a critical-path suite plus automated accessibility checks that run in CI.

## Decision

Use **Playwright** for the fork's end-to-end and accessibility tests, as a separate `e2e/` project at the repository root (the layout upstream's README reserved for Cypress), running against the compose stack in CI. Accessibility assertions use `@axe-core/playwright` on every new page. The suite covers only the fork's critical path: sign-in, submit an application, review it, see the derived status and the audit history.

## Alternatives considered

1. **Cypress** — matches the README's description, so a later upstream contribution of the suite would be more natural. Against it: nothing exists to build on; component testing would require installing Cypress inside the Next.js app, which upstream's own notes wanted to avoid; the sibling Learning Center already uses Playwright with axe, so one tool serves both repositories.
2. **No end-to-end suite, API feature tests only** — cheaper, but the review slice's value is its user-facing workflow and its accessibility; those cannot be asserted below the browser.

## Consequences

- Positive: browser-level regression protection and accessibility gates for the federation workflow; one E2E toolchain across the portfolio.
- Negative: an extra Node project and a slower CI job that needs the full stack; upstream's README will describe a different tool until the README is rewritten in A5.
- Follow-ups: first spec lands with the identity milestone (sign-in); the CI job is added then, and watched failing once before it is trusted.
