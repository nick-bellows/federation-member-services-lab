# Performance

Synthetic load on three endpoints, measured before and after two changes, with the numbers retained (B6, ADR-0013). Every number below comes from `docs/baseline/perf_*` files produced on 2026-09-03 on one laptop against the Compose stack; numbers from a laptop compare only with numbers from the same laptop, the same seed and the same settings. Nothing here is a production claim.

## Method

- **Seed.** `php artisan db:seed --class=PerformanceSeeder` adds 20 synthetic clubs with 1,500 members and 500 memberships each (30,162 members, 10,060 memberships in total with the demo data), plus 500 memberships and 1,500 members for the club the seeded administrator logs into. Every name is generated.
- **Tool.** k6 0.54 in Docker (`perf/k6/federation.js`), 10 virtual users for 30 seconds per scenario, 200 ms think time, scenarios run one after another. Tokens come from the environment: a Sanctum token from `POST /api/v1/users/login` for the club administrator, a persona token from the mock identity provider for the federation administrator.
- **Rate limit.** Upstream limits the API to 60 requests per user per minute; the first run failed four requests in five on that limit and was discarded (`perf_before_k6.txt` was overwritten). The limit is a config key now (`API_RATE_LIMIT_PER_MINUTE`, default 60, unchanged behaviour) and was raised for the measurement window only.
- **Endpoints.** `GET /api/v1/memberships?page[size]=50` (upstream, tenant-scoped, one member count and one fee per row); `GET /api/v1/federation/registration-applications?filter[status]=approved` (the review queue, scoped, eager-loaded); `GET /api/v1/federation-identity/me` (token verification and one user lookup, the floor).

## Findings

### 1. No index on the tenant column or the membership foreign key

The original 2023 tables declared `club_id` on `members`, `memberships`, `membership_types` and `divisions` and `membership_id` on `members` without an index (M0 baseline). Every tenant-scoped query filters on `club_id`; the per-row member count filters on `membership_id`.

Query plans on MariaDB 11.8 (`docs/baseline/perf_explain_before.txt`, `perf_explain_after.txt`):

| Query | Before | After |
|---|---|---|
| `SELECT * FROM memberships WHERE club_id = 1` | full scan, 10,613 rows | index `memberships_club_id_index`, 510 rows |
| `SELECT COUNT(*) FROM members WHERE membership_id = 5` | full scan, 32,020 rows | index `members_membership_id_index`, 1 row |
| `SELECT * FROM members WHERE club_id = 1` | full scan, 32,020 rows | index `members_club_id_index`, 1,510 rows |

Fix: migration `2026_09_03_130000_add_club_id_indexes_to_upstream_tables` (five indexes, idempotent). Guard: `tests/Feature/Performance/ClubIdIndexTest.php` on every engine of the matrix.

### 2. Per-row queries in the memberships listing

The memberships resource computed `membersCount` with a query per row and `monthlyFee` with `getMonthlyFee()`, which ran its own fee query and touched two relations per row (M0 finding). A page of 20 memberships ran **89 queries** (`docs/baseline/perf_query_count_before.txt`).

Fix: the schema eager-loads `membershipType` and `club`, and its index query adds `withCount('members')` and the fee subselect; the model uses the loaded fee when present. The same page runs **11 queries** (`perf_query_count_after.txt`). Guard: `tests/Feature/Performance/MembershipsListingQueryCountTest.php` fails above 15.

## Numbers

10 virtual users, 30 s per scenario, p50 and p95 in milliseconds, requests per second as measured (think time included):

| Endpoint | Before | After indexes | After indexes and eager loading |
|---|---|---|---|
| memberships listing (page of 50, club with 510 memberships) | p50 504 · p95 663 · 4.2 rps | p50 340 · p95 482 · 5.4 rps | p50 211 · p95 333 · 7.0 rps |
| federation applications index | p50 206 · p95 321 · 7.0 rps | p50 215 · p95 370 · 7.0 rps | p50 205 · p95 359 · 7.1 rps |
| federation identity | p50 195 · p95 318 · 7.3 rps | p50 195 · p95 328 · 7.3 rps | p50 192 · p95 312 · 7.4 rps |

Failure rate 0 % in all three valid runs. The federation endpoints did not move, as expected: their tables were indexed from M2 and their pages eager-load from M4. The floor of about 190 ms median is this development stack's own request latency on a bind mount, noted in INCIDENT-001; the deltas, not the absolutes, are the finding.

## What would make these numbers lie

- A different machine, Docker Desktop's file sharing, or a warm versus cold cache: rerun before and after on the same day.
- The think time: 10 users at 200 ms cannot exceed roughly 50 rps; the listing was latency-bound, not throughput-bound.
- The seed: one big club among small ones; a club with 50,000 members would show the index gain more and the per-row cost less.
- The raised rate limit: the numbers describe the code, not the production ceiling of 60 requests per user per minute.

## Running it

```sh
docker compose exec tooling bash -lc 'cd api && php artisan db:seed --class=PerformanceSeeder'
# raise API_RATE_LIMIT_PER_MINUTE in api/.env for the window, restart the api container, then:
docker run --rm --network vereinfacht_stack -v "$PWD/perf/k6:/scripts" -v "$PWD/docs/baseline:/results" \
  -e BASE_URL=http://api -e SANCTUM_TOKEN=... -e OIDC_TOKEN=... -e LABEL=mine grafana/k6:0.54.0 run /scripts/federation.js
```

Both changes are candidates for the upstream offer at B9 (decision 7): five indexes and an eager-loaded listing, each with a test.
