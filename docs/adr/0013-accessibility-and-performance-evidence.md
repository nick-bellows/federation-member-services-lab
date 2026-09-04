# ADR-0013: Accessibility and performance as measured evidence

- Status: accepted (2026-09-03, owner's decisions at the start of B6)
- Milestone: B6 (M9 in the brief)
- Related: ADR-0003 (Playwright with axe), ADR-0011 (compatibility matrix), [`docs/ACCESSIBILITY.md`](../ACCESSIBILITY.md), [`docs/PERFORMANCE.md`](../PERFORMANCE.md)

## Context

The browser journeys had scanned every page with axe since M3, which finds about a third of accessibility problems. The M0 baseline had recorded two performance findings that nobody had measured: the tenant column and a foreign key without indexes on four upstream tables, and a listing that queried per row. The brief asks for a manual WCAG 2.1 AA review, a low-bandwidth pass, and synthetic load on three endpoints with retained before-and-after numbers.

## Decision

1. **k6 in Docker** as the load tool: scripts in `perf/k6/`, run through the `grafana/k6` image on the stack network, per-scenario thresholds, JSON summaries retained under `docs/baseline/`. No host install, no cost.
2. **Single-column indexes, measured.** One migration adds `club_id` indexes to `members`, `memberships`, `membership_types` and `divisions` and a `membership_id` index to `members`; a test asserts they exist on every engine; the query plans and the k6 numbers before and after are retained. The listing's per-row queries, found by the same measurement, are fixed by eager loading with a query-count guard.
3. **A manual review of the slice.** Seven member and reviewer pages walked keyboard-only with focus order and focus visibility recorded, a best-practice axe scan on top of the AA tags, each criterion recorded with evidence, findings deferred where they are improvements rather than failures, and a slow-3G pass recorded with the caveat that the development server is not the product.
4. **The rate limit becomes configuration.** Upstream's 60 requests per user per minute stays the default; a load run raises it through `API_RATE_LIMIT_PER_MINUTE` for the measurement window, and the document says the numbers describe the code, not the production ceiling.

## Alternatives considered

1. **Artillery or ApacheBench** — a host dependency, or no authenticated scenarios. Rejected.
2. **Composite indexes shaped to each listing** — premature without the single-column baseline; revisit if a specific query shows.
3. **Automated accessibility only** — cannot judge focus order, error guidance or keyboard completion, which is what the review is for. Rejected.
4. **Reviewing upstream's pages too** — real findings, upstream's scope, more than one session. Deferred.

## Consequences

- Five indexes and an eager-loaded listing, each with a regression test, join the upstream offer at B9 (decision 7).
- The performance seeder makes the development database large; it is opt-in and idempotent.
- The accessibility spec runs in CI for the keyboard walk and the best-practice scan; the throttled timing is skipped there because it is machine-specific.
- Three accessibility improvements (skip link, per-page titles, described transition buttons) are deferred to B9's final pass.
- Follow-ups: a production measurement of the frontend once B8 serves a built image; composite indexes if a query asks for them; a `CHECK` on money columns per engine (ADR-0011).
