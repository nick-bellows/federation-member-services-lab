# Deployment architecture

Status: **planned, not provisioned** (B8, ADR-0015). Nothing in this document exists in any cloud account. What exists is the release rehearsal in Compose (`deploy/compose.release.yml`), which runs the release images this design would deploy, and the CI matrix, which proves the code on the engine this design chooses. Since 2026-09-04 (owner's approval of provisioning, ADR-0015 addendum) a minimal proof of this design is written in Terraform under [`deploy/terraform/`](../deploy/terraform/README.md): validated, priced, and waiting for AWS credentials to be planned and applied. Every service below is named with what it replaces from the Compose stack, so a reader can see that the design is the same system, not a different one.

## Shape

```
                       ┌──────────────────────────────────────────────┐
  people ───► CloudFront ──► ALB ──┬─► ECS Fargate: web (Next.js, standalone image)
                                   │
                                   └─► ECS Fargate: api (nginx + PHP-FPM, release image)
                                                   │         │
                                     ECS Fargate: worker    ECS Fargate: scheduler
                                     (federation:work)      (schedule:work)
                                                   │         │
                                     ┌─────────────┴─────────┴──────────┐
                                     │ RDS PostgreSQL 16 (private subnet) │
                                     └────────────────────────────────────┘
  identity provider (Auth0) ◄──── browser (authorization code + PKCE) / api (JWKS)
  Learning Center ◄──────────────── api and worker (client credentials, outbound only)
  Secrets Manager ──► task definitions (environment)      CloudWatch ◄── stdout/stderr JSON
  S3 (documents, planned)          ECR (images by git SHA)
```

## Components

| Component | Replaces in Compose | Design |
|---|---|---|
| CloudFront | nothing (the browser hits the containers) | One distribution, two origins: the web service and the API service. TLS from ACM. Caches only static assets under `/_next/static`; everything else passes through. WAF rate rule for anonymous traffic (threat model 4.5). |
| Application load balancer | the published ports 3000 and 3001 | Two target groups. Health check on `/api/health/ready` for the API (503 drains an instance whose outbox is backing up, which is the intent of readiness, ADR-0012) and on `/en/member/sign-in` for the web app. Only CloudFront's prefix list may reach it. |
| ECS Fargate, `api` | the `api` container | The release image, 2 tasks minimum, CPU and memory sized after a load run on the real engine (the k6 numbers in `docs/PERFORMANCE.md` are from a laptop). Environment from Secrets Manager and SSM. `RUN_MIGRATIONS` unset. |
| ECS Fargate, `worker` | `docker compose exec -d -u verein api php artisan federation:work` | Same image, command `php artisan federation:work`, 1 task; scale on `federation_outbox_unpublished`. Restart on exit. |
| ECS Fargate, `scheduler` | nothing (run by hand until B8) | Same image, command `php artisan schedule:work`, exactly 1 task (the schedule uses `withoutOverlapping`, which also guards a second task by accident). |
| One-off task, `migrate` | the entrypoint's `migrate --force` on every start | Same image, `RUN_MIGRATIONS=1`, command `php artisan migrate:status`, run once by the release pipeline before the service update; the pipeline stops if it exits non-zero. |
| ECS Fargate, `web` | the `tooling` container's `next dev` | Upstream's standalone image. `API_DOMAIN` points at the ALB's internal name; `NEXTAUTH_URL` at the public host; the OIDC variables at the tenant. `API_BEARER_TOKEN` is not set: the federation pages do not use upstream's super-admin token (threat model 3.5), and upstream's public apply form is not part of this deployment. |
| RDS PostgreSQL 16 | `mariadb:11.8` | Chosen because the compatibility matrix proved the suite and the seeder on PostgreSQL in CI on every pull request (ADR-0011). Multi-AZ, automated snapshots, a manual snapshot before every migration. The application role has no `DROP` or `TRUNCATE`; a write-once policy for `audit_entries` is a follow-up. |
| Queue | Laravel's database queue | Kept for v1: the `jobs` and `failed_jobs` tables on RDS, the outbox relay and the worker as they run today. The mapping to SQS is in ADR-0010 and is the first change after a deployment carries real volume. |
| S3 | nothing (the slice stores document metadata only, ADR-0008) | Planned for the day document bytes are accepted: one private bucket, pre-signed uploads, the checksum the metadata already carries. |
| Secrets Manager and SSM | `api/.env`, `deploy/release.env` | `APP_KEY`, `DB_PASSWORD`, `LEARNING_CENTER_CLIENT_SECRET`, `METRICS_TOKEN`, `NEXTAUTH_SECRET`, the Auth0 client secret in Secrets Manager; the rest in SSM parameters; both injected into task definitions, never baked into images (the release images carry no `.env`, by their ignore lists). |
| CloudWatch Logs | `docker compose logs` | The JSON lines from every container (ADR-0012). Metric filters on `"message":"scheduled_task_failed"`, on `"status":5` in access lines, and on `oidc.rejected` rate. |
| CloudWatch alarms | nothing | `scheduled_task_failed` > 0 in 15 minutes; readiness failures on the ALB target group; `federation_outbox_oldest_unpublished_seconds` > 300 scraped from `/api/metrics` with the scrape token by a CloudWatch agent or a Prometheus sidecar. |
| Traces | Jaeger in Compose | An OpenTelemetry collector sidecar receiving OTLP on the task and exporting to X-Ray or a managed Jaeger; `OTEL_EXPORTER=otlp` and the collector's endpoint. |
| ECR | local images | Images tagged with the git SHA of the merge commit; the previous tag retained for rollback (`docs/RELEASE.md`). |
| Auth0 | the mock identity provider | The tenant the owner creates; `OIDC_ISSUER`, `OIDC_AUDIENCE`, `OIDC_JWKS_URI` for the API, the `AUTH0_*` variables for the web app (`docs/AUTH0_WALKTHROUGH.md`). The mock is never deployed (threat model 3.6). |
| Learning Center | the mock in Compose | The provider's real base URL and token endpoint; the same contract (ADR-0009). Readiness reports it and never requires it. |

## Network boundaries

- Public: CloudFront only. The ALB accepts CloudFront's managed prefix list; the tasks accept the ALB's security group; RDS accepts the tasks' security group. Nothing else is reachable from outside.
- Outbound from tasks: the identity provider (JWKS, token endpoint), the Learning Center, the OpenTelemetry collector. A NAT gateway or VPC endpoints for ECR, Secrets Manager and CloudWatch.
- `/api/health/live` and `/api/health/ready` are open to the ALB; `/api/health/checks` and `/api/metrics` require the scrape token (ADR-0014) and are additionally restricted at CloudFront to the scraper's origin, or not exposed through CloudFront at all.

## What the rehearsal proves and what it does not

| Proven by `deploy/compose.release.yml` | Not proven until an environment exists |
|---|---|
| The release images build from a clean context and start with no bind mount | Task sizing, autoscaling thresholds |
| The API answers liveness and readiness from the release image | ALB health-check timing under real latency |
| A migration runs once as a task, not on every start | IAM boundaries, secret rotation |
| The worker and the scheduler run as separate containers on the same image | CloudFront caching behaviour for the App Router |
| A signed-in journey completes against the release images | Auth0 tenant settings (the walkthrough is planned) |
| The images carry no `.env` | Image scanning and digest pinning in the pipeline |

## Cost

Nothing here has been provisioned or priced. The smallest honest version (two Fargate tasks for the API, one each for web, worker and scheduler, a single-AZ RDS instance, one CloudFront distribution) is a recurring monthly cost that the owner decides on; the approvals list at the end of B9 carries the question. A one-day proof on a personal account would still cost money and would need the owner's approval first (workspace rule: no paid resources without it).
