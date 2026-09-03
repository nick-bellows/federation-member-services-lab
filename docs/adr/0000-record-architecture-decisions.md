# ADR-0000: Record architecture decisions

- **Status:** accepted
- **Date:** 2026-09-01
- **Milestone:** M0

## Context

This repository is a long-lived fork of an existing open-source system. Every change to it has to be defensible against two audiences: upstream maintainers who may receive parts of it back, and reviewers who need to distinguish inherited design from added design. Decisions that live only in commit messages or chat are lost within weeks.

## Decision

Architecture decisions are recorded as numbered Markdown files in `docs/adr/`, using `docs/adr/template.md` (a reduced MADR format: context, decision, alternatives, consequences). An ADR is written when a choice constrains later work, changes a public contract, or departs from an upstream convention. ADRs are immutable once accepted; a change is a new ADR that supersedes the old one.

## Alternatives considered

1. **Decisions in the README** — the README is the five-minute entry point and would become the dumping ground the portfolio house rules warn about.
2. **Decisions in issues or PR descriptions** — GitHub-hosted, not cloned with the repository, and not readable in an interview with the code open.

## Consequences

- Positive: reviewers can read why, not only what; the fork's additions stay separable from upstream's.
- Negative: small overhead per decision; the discipline has to be kept.
- Follow-ups: ADR-0001 records the fork strategy. Later ADRs are expected for identity, eligibility derivation, the outbox, the Learning Center boundary and PostgreSQL support.
