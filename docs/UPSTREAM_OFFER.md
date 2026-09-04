# Upstream offer (draft, not sent)

Status: **drafted 2026-09-04, not sent.** ROADMAP decision 7 (2026-09-03): one offer, at the end of Phase B, on the owner's word. ADR-0004 governs the form: generic only, one message, easy to decline, nothing promised. This file is the message and the material behind it; sending it is on the approvals list.

## Where it would be sent

A new issue on `vereinfacht/vereinfacht` titled "Offer: portability and CI fixes from a downstream fork". An issue rather than a pull request, so the maintainers choose what they want and in what shape before anyone rebases anything. Issue #7 (CI) and #125 (locale header) are related and would be linked.

## The message

> Hello. I maintain a public fork, [federation-member-services-lab](https://github.com/nick-bellows/federation-member-services-lab), which extends vereinfacht into a fictional federation's member-services platform as a learning and portfolio project. The fork's own domain code is not relevant to you, but four small pieces of it are generic to vereinfacht and I would like to offer them. Each is a separate commit, has tests, and runs green in the fork's CI on SQLite, MariaDB 11.8 and PostgreSQL 16.
>
> 1. **`env()` read under a cached configuration** ([d72534f](https://github.com/nick-bellows/federation-member-services-lab/commit/d72534f)). `Club::applyUrl`, `HealthCheckServiceProvider` and `WelcomeClubAdminMailable` read `env('WEB_APPLICATION_URL')` directly; with `php artisan config:cache`, as the API container's entrypoint runs, the value is `null` and the fallback is the production domain. The fix moves the two settings into `config/app.php` and reads them through `config()`; a test fails before and passes after.
> 2. **`.gitattributes` with `* text=auto eol=lf`** ([3090736](https://github.com/nick-bellows/federation-member-services-lab/commit/3090736)). On Windows with `core.autocrlf=true`, `docker/api/entrypoint.sh` is checked out with CRLF and the API container fails to start. One line fixes it for every contributor.
> 3. **MySQL-only SQL made portable** ([badc93d](https://github.com/nick-bellows/federation-member-services-lab/commit/badc93d)). `FIELD()` in seven export actions replaced by a `CASE` expression helper (`App\Support\OrderByIdList`) with a regression test on ordering; `CAST(... AS UNSIGNED)` in `Membership::scopeWithMembersDivisionsFee` replaced by `CAST(... AS INTEGER)`; a double-quoted string literal in `2025_02_05_125657` written through the query builder; three tests that compared JSON columns as text changed to compare through the model. Nothing changes on MariaDB; the suite and the fake seeder run on PostgreSQL 16 in the fork's CI.
> 4. **Indexes on `club_id` and an eager-loaded memberships listing** ([8981d88](https://github.com/nick-bellows/federation-member-services-lab/commit/8981d88), the `api/` part only). `members`, `memberships`, `membership_types` and `divisions` have no index on `club_id`, and `members` none on `membership_id`; with 30,000 synthetic members the memberships listing scanned 32,020 rows per request. One idempotent migration adds five single-column indexes (a test asserts them on every engine); `MembershipSchema::indexQuery` eager-loads the type, the club, the member count and the fee subselect, which took a page of twenty from 89 queries to 11 (a query-count test guards it). Query plans and k6 numbers before and after are in the fork under `docs/baseline/perf_*`.
>
> I can open one pull request per item, rebased on `main`, in whatever order or subset you prefer, or none if this does not fit your plans. The CI workflow in the fork (#7 is related) is opinionated about the fork's paths and I am not offering it as is; I would be glad to trim it to a PHPUnit-on-SQLite plus Pint job if that is useful.
>
> Thank you for publishing vereinfacht under MIT; the fork keeps the license, the attribution and the upstream remote.

## Behind the message

| Item | Fork commit | Tests | Touches upstream files |
|---|---|---|---|
| `env()` fix | d72534f | `tests/Unit/WebApplicationUrlConfigTest.php` (3 failing before, 4 passing after) | `config/app.php`, `app/Models/Club.php`, `app/Providers/HealthCheckServiceProvider.php`, `app/Mail/WelcomeClubAdminMailable.php` |
| `.gitattributes` | 3090736 | none needed | root |
| Portability | badc93d | `tests/Feature/Export/ExportOrderTest.php`, the three ClubAdmin tests | seven export actions, `app/Models/Membership.php`, one migration, `app/Support/OrderByIdList.php` |
| Indexes and eager loading | 8981d88 (`api/` part) | `tests/Feature/Performance/ClubIdIndexTest.php`, `MembershipsListingQueryCountTest.php` | one new migration, `app/JsonApi/V1/Memberships/MembershipSchema.php`, `app/Models/Membership.php` |

Each would be re-cut onto upstream `main` as a fresh branch (the fork's commits carry fork-only files beside these changes), which is why the offer is an issue and not four pull requests: the rebasing is work worth doing only for what the maintainers want.

## What is deliberately not offered

- The CI workflow as it stands (fork-specific paths, three engines, the federation e2e stack).
- Anything under `app/Federation`, `routes/federation.php`, the federation migrations, the frontend `/member` pages: fork domain.
- The observability, outbox and security work: generic in spirit, but each would need a design conversation upstream first.
- The M0 findings on the public apply form's super-admin token, the `Throwable` to 422 mapping and the CORS wildcard: they are recorded in the fork's threat model as upstream's surface; raising them is a security conversation the maintainers should have in private, not in a public issue. The owner decides whether to write to them separately.
