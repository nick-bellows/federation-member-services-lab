# Future work

The single home for deferred ideas. Items move out of here into a milestone or an ADR; they do not accumulate in the README.

## Deferred from Milestone 0 (2026-09-01)

- **Upstream contribution candidates** are ranked in `docs/UPSTREAM_ANALYSIS.md` section 10 and will be picked one at a time from Milestone 1.
- **Cypress / end-to-end suite** does not exist upstream (issue #197). The fork will decide in M1 whether to add Playwright or Cypress for its own critical paths.
- **Config-cache trap in the dev container** (see `docs/UPSTREAM_ANALYSIS.md` section 11): worth a small upstream fix to the entrypoint or a documented `config:clear` step.
- **Two parallel tenant-isolation mechanisms** (`ClubScope` for the API, `ApplyTenantScopes` for Filament) — a candidate for consolidation only if the federation hierarchy in M2 forces a change; otherwise leave as is.
- **`env()` calls outside `config/`** (`Club::applyUrl`, `HealthCheckServiceProvider`) return defaults once configuration is cached; fold into config in M1 or upstream.
