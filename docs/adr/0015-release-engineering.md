# ADR-0015: Release engineering as immutable images, a one-off migration task, and a designed-not-provisioned deployment

- Status: accepted (2026-09-04; the opening decision defaulted by the owner's instruction, see Context)
- Milestone: B8 (M10 in the brief)
- Related: ADR-0010 (outbox and the broker mapping), ADR-0011 (PostgreSQL compatibility), ADR-0012 (observability), ADR-0014 (threat model and update policy), [`docs/DEPLOYMENT.md`](../DEPLOYMENT.md), [`docs/RELEASE.md`](../RELEASE.md), [`deploy/compose.release.yml`](../../deploy/compose.release.yml)

## Context

Six milestones had produced a system that runs in Compose with bind mounts, a worker an operator starts by hand, an entrypoint that migrates the database every time the API container starts, and images that would carry a developer's `.env` if built as they stand. The brief asks for production images, a deployment architecture, a rollback plan and a release checklist, with no provisioning and no cost without the owner's approval.

The milestone's opening decision was design-only versus opt-in provisioning. On 2026-09-04 the owner asked for B8 and B9 to be completed without a pause for decisions and for every approval to be collected at the end. Design-only is the only option the workspace rules permit without approval, so it is the default recorded here; provisioning stays on the approvals list.

## Decision

1. **Separate release images, built from the same source.** `docker/api/api.release.Dockerfile` installs Composer dependencies without development packages at build time, builds the admin assets, removes the build toolchain, answers a `HEALTHCHECK` on liveness, and carries no environment file (the API container starts as root so nginx and PHP-FPM can bind and then drop to the application user, as upstream's image does; the worker and scheduler containers run as that user outright; a rootless nginx variant is a follow-up): a per-Dockerfile ignore list excludes every `.env*`, `vendor`, `node_modules`, the tests and the documentation. The web app keeps upstream's standalone Next.js image with its own ignore list, which excludes `.env.local` (the file that holds upstream's super-admin token in development). The development images are untouched.
2. **A release entrypoint that does not migrate.** Configuration, routes, views and events are cached; the database is awaited through PDO on the configured connection, host and port; `migrate --force` runs only when `RUN_MIGRATIONS=1`. A one-off task with that variable runs the migration once per release; replicas start without racing each other or the schema.
3. **The worker and the scheduler are services on the API image.** `federation:work` and `schedule:work` run as separate containers. The federation module registers its schedule: the reconciliation hourly, the outbox status and upstream's health checks every fifteen minutes, each with a failure hook that logs `scheduled_task_failed` with the task name. That line is the alarm's attachment point.
4. **A release rehearsal in Compose.** `deploy/compose.release.yml` runs the release images beside the development stack with no bind mount: database, one-off migration, API, worker, scheduler, web, and the two development mocks so a journey can be walked. It is the evidence that the images work; it is not a deployment.
5. **The deployment is designed, labelled planned, and costs nothing.** `docs/DEPLOYMENT.md` names the managed services (CloudFront, an application load balancer, Fargate services for API, worker and scheduler, RDS PostgreSQL, S3, Secrets Manager, CloudWatch), what each replaces from Compose, the network boundaries, the secrets, and the one-off migration task. PostgreSQL is the production engine because the compatibility matrix proved the suite and the seeder on it (ADR-0011). The database queue stays for v1; the SQS mapping from ADR-0010 is the next step. No Terraform is committed: an untested module set would be a claim without evidence.
6. **The release checklist runs the threat model's update policy.** A report-only CI job runs both dependency audits on every pull request. This milestone applied the within-major fixes the B7 audit named (Filament 5.7.8, commonmark 2.10.0, Livewire 4.4.3; the framework followed to 13.30.1) and the non-breaking npm fixes; the majors (Next 16, `sharp`, `swiper`) stay recorded and unapplied.
7. **Rollback is an image tag plus a precondition.** Images are tagged with the git SHA and the previous tag is retained; a migration is expand-and-contract so the previous image runs against the new schema; a snapshot precedes every migration; the fork's migrations that cannot be reversed are listed by name with their compensating action in `docs/RELEASE.md`.

## Alternatives considered

1. **One image for development and release** — the development image bind-mounts the source and installs dependencies at runtime; making it production-safe means changing what every contributor runs. Rejected; two Dockerfiles that share the runtime stage's shape.
2. **Migrate in the entrypoint, as upstream does** — simple, and wrong with more than one replica or a managed database on another host and port. Rejected.
3. **Supervisor inside one container for PHP-FPM, the worker and the scheduler** — one container to schedule, but one failure domain and one log stream for three roles; a platform scales and restarts them separately. Rejected.
4. **SQS from the start** — the ADR-0010 mapping is ready, but a queue table on RDS serves the current volume and keeps the rehearsal honest with no cloud dependency. Deferred to the step after a first deployment.
5. **Terraform, labelled untested** — the roadmap allowed it. Rejected for this milestone: syntax validation proves nothing about a plan against a real account, and the document carries the design without pretending to more.
6. **Provisioning a minimal environment** — needs the owner's approval and money. On the approvals list.

## Consequences

- Two more Dockerfiles and their ignore lists to maintain; the release rehearsal is the test that catches drift between them and the development stack.
- Every deployment must supply the full environment from a secret store; the example file lists it.
- The scheduler's failure line and the readiness age are the two signals an alarm design depends on; `docs/RUNBOOK.md` names both.
- The dependency update moved the framework by seven minor versions within 13.x; the three-engine suite and the browser journeys are the evidence it was safe.
- Follow-ups in `docs/future-work.md`: Terraform or CDK once an account exists, image scanning and digest pinning in the pipeline, SQS behind the relay, a write-once audit table by database role.
