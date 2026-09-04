# Release checklist and rollback plan

Status: the checklist is exercised by the release rehearsal in Compose (`deploy/compose.release.yml`, `docs/baseline/release_rehearsal_2026-09-04.txt`); the deployment steps are **planned** until an environment exists (`docs/DEPLOYMENT.md`, ADR-0015). "CI" here means the workflow in `.github/workflows/ci.yml`; there is no automated deployment.

## Versioning

- Conventional Commits, as upstream (`cliff.toml`). Upstream's `release.yml` (manual dispatch) opens a `release/<version>` branch with a changelog; `publish.yml` tags and publishes a GitHub release when that branch merges. **Neither has been run on this fork** (they would tag and publish; that is the owner's call).
- Images are tagged with the git SHA of the merge commit on `main`, never `latest`. A release is a SHA and the changelog entry that names it.

## Checklist

Every line has an owner (the person releasing) and evidence (a link or a file). A release stops at the first line that fails.

### Before merging

1. CI green on the pull request: three engines, style and env guard, frontend build, browser journeys, upstream manifest, and the dependency audit summaries read (`dependency-audit` job, report only).
2. Dependency audits: within-major fixes applied per the policy in `docs/THREAT_MODEL.md`; every unapplied major listed in `docs/future-work.md` with its advisory.
3. Migrations in the change are expand-and-contract: a new column is nullable or defaulted, a rename is add-copy-drop across two releases, an index is added with `Schema::getIndexes` guards (as `2026_09_03_130000` does). No migration deletes data.
4. `ROADMAP.md` and the README rows say what the release contains; numbers link to retained runs.

### Build

5. `docker build -f docker/api/api.release.Dockerfile -t federation-api:<sha> .` and the web image likewise; both from a clean checkout of the merge commit (the ignore lists exclude `.env*`, `vendor`, `node_modules`, `.next`).
6. `docker run --rm federation-api:<sha> sh -c 'test ! -e /var/www/html/.env && php artisan about --only=environment'`: no environment file inside the image, configuration from the environment only.
7. Push both images to the registry under the SHA tag. Retain the previous release's tag.

### Rehearse (Compose, every release)

8. `cp deploy/release.env.example deploy/release.env`, set `APP_KEY`; `docker compose -p federation-release -f deploy/compose.release.yml build`.
9. `docker compose -p federation-release -f deploy/compose.release.yml --profile release run --rm migrate` exits 0 and prints the migration table.
10. `docker compose -p federation-release -f deploy/compose.release.yml up -d`; `/api/health/live` 200, `/api/health/ready` 200 within the start period; the worker and scheduler containers stay up; `docker compose … exec api php artisan schedule:list` shows the three federation tasks.
11. A signed-in journey against the release web image (sign in, start an application, submit) and a reviewer action that produces an outbox job the worker processes (`federation:outbox-status` reports `processed` rising).
12. `/api/health/checks` and `/api/metrics` answer 401 without the scrape token and 200 with it.

### Deploy (planned)

13. Snapshot the database (manual RDS snapshot named after the SHA).
14. Run the one-off `migrate` task with the new image and `RUN_MIGRATIONS=1`; stop if it exits non-zero.
15. Update the `api` service to the new image; wait for the ALB health check (`/api/health/ready`) on every new task before the old tasks drain.
16. Update `worker` and `scheduler`; confirm one scheduler task only.
17. Update `web`; confirm `/en/member/sign-in` from CloudFront.
18. Smoke: the journey from line 11 against the public host; `federation_outbox_oldest_unpublished_seconds` below 60 after five minutes; no `scheduled_task_failed` in fifteen minutes.
19. Record the release: SHA, snapshot id, migration names, time, who; the changelog entry.

## Rollback

Rollback is a service update to the previous image tag. Its precondition is line 3: the previous image must run against the new schema, which expand-and-contract guarantees.

| Situation | Action | Time budget |
|---|---|---|
| New tasks fail the health check | The deployment never shifts traffic; stop the update, the old tasks are still serving. | minutes |
| Errors after traffic shifts, no migration in the release | Update `api`, `worker`, `scheduler`, `web` to the previous SHA. | under 15 minutes |
| Errors after traffic shifts, migration in the release | Same image rollback (the schema is additive). Do **not** run `migrate:rollback` in production: every fork migration has a `down()`, but reversing a table or column creation discards the rows written since. Leave the schema; contract it in a later release once the previous image is retired. | under 15 minutes |
| Data corruption by the release | Restore the snapshot from line 13 into a new instance, point the previous image at it, accept the loss of writes since the snapshot, and say so in the incident record. | hours; the owner decides |
| Worker parks events during the window | No rollback: `federation:outbox-replay` after the fix (INCIDENT-003). | as the runbook says |

Migrations that would lose data if reversed (all of the fork's `create_*` migrations and the column additions): `2026_09_02_100000` to `2026_09_02_100007`, `2026_09_02_110000`, `2026_09_02_120000` to `2026_09_02_120002`, `2026_09_03_100000`, `2026_09_03_110000`, `2026_09_03_120000`, `2026_09_03_140000`. Reversible without loss: `2026_09_03_130000` (indexes only).

## Who decides

The person releasing stops the release at any failed line without asking. Rolling back after traffic has shifted is the same person's call within the time budget; restoring a snapshot is the owner's call, because it discards writes.
