# ADR-0001: Fork strategy and upstream relationship

- **Status:** accepted
- **Date:** 2026-09-01
- **Milestone:** M0

## Context

The project extends [vereinfacht/vereinfacht](https://github.com/vereinfacht/vereinfacht) (MIT, developed by visuellverstehen GmbH) into a federation member-services lab. Upstream is actively developed by a single company (127 commits, last push 2026-08-13 at the time of forking) and has never merged an external pull request. The fork must stay honest about what is inherited, stay mergeable with upstream, and be able to send generic fixes back.

## Decision

- The repository is a **GitHub fork** of upstream, created only after this analysis existed so that the first public state already carries the fork notice and `docs/UPSTREAM_ANALYSIS.md`. Full upstream git history is retained. The remote `upstream` points at vereinfacht; `origin` is the fork.
- **License stays MIT** for the whole repository. Upstream code cannot be relicensed, and a split license would add friction for nothing. The upstream copyright line in `LICENSE` is untouched; additions are contributed under the same license.
- `main` tracks upstream's `main` plus the fork's merged work. `main` is never force-pushed. Work happens on milestone branches (`m0/…`, `m1/…`) merged through pull requests into the fork's `main`, so `git log upstream/main..main` and `git diff --stat upstream/main` always show exactly what the fork added.
- Upstream is merged into `main` periodically (fetch `upstream`, merge, resolve). Conflicts are expected mainly in `README.md`, which the fork replaces at the top.
- Commits follow **Conventional Commits** as parsed by upstream's `cliff.toml` (`feat`, `fix`, `doc`, `refactor`, `test`, `chore`, `ci`), so history stays compatible with upstream's changelog tooling and cherry-picks read naturally.
- Generic fixes are proposed upstream from short-lived branches based on `upstream/main`, one at a time, after checking existing issues. Federation-specific work stays in the fork.

## Alternatives considered

1. **Copy upstream into a fresh repository** — loses provenance and the fork relationship on GitHub; would misrepresent authorship. Rejected by the project brief.
2. **Fork immediately, before any documentation** — the first public state would have been upstream's code with no explanation; the portfolio's own audit had already penalised a fork that looked like that.
3. **Vendor upstream as a git subtree/submodule under a new top-level application** — keeps a clean separation but defeats the purpose: the exercise is working *inside* an inherited codebase, not beside it.

## Consequences

- Positive: provenance is verifiable with two git commands; upstream contributions are cheap to extract; the fork can absorb upstream changes.
- Negative: README and shared files will conflict on upstream merges; the fork is public from creation and can never be made private.
- Follow-ups: automate the upstream-vs-fork manifest in CI (M1); decide per milestone which changes are generic enough to offer upstream.
