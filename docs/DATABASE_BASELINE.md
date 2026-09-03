# Database baseline

The relational model as inherited from upstream at `dca9be3`, before any federation-specific migration. Section 1 is derived from reading `api/database/migrations`; section 2 records what the live MariaDB schema actually contains after `migrate:fresh --seeder=FakeDatabaseSeeder`. Where the two disagree, section 2 wins. This document is completed in Milestone 2 when the federation tables are designed.

## 1. From the migrations

### 1.1 Core domain

| Table | Key columns | Notes |
|---|---|---|
| `clubs` | `id`, `slug` (unique), `title`, address fields, `email`, branding URLs, `membership_start_cycle_type`, `allow_voluntary_contribution`, media-consent flags, `tax_account_chart_id`, `preferred_locale` | `owner_user_id` was added in 2023 and dropped in 2024 when roles arrived. `apply_title` is JSON (translatable). |
| `membership_types` | `id`, `title` (JSON), `description` (JSON), `monthly_fee` (unsigned int, cents), `minimum_number_of_months`, `minimum/maximum_number_of_members`, `admission_fee`, `sort_order`, division-count limits, `club_id` | Per-club catalogue of what a member can apply for. |
| `memberships` | `id`, `bank_iban`, `bank_account_holder`, `started_at`, `ended_at`, `status` (nullable string), `notes`, `voluntary_contribution`, `membership_type_id` (nullable since 2026), `club_id`, `owner_member_id`, `payment_period_id` | The contract between a club and one or more people. `status` values (`applied`, `active`, `cancelled`) exist only in `App\Enums\MembershipStatusEnum`. |
| `members` | `id`, person fields, `birthday`, `email`, `status` (nullable string), `preferred_locale`, `consented_media_publication_at`, `membership_id` (nullable), `club_id` | A person inside a membership. `status` values (`active`, `inactive`) only in `App\Enums\MemberStatusEnum`. |
| `divisions` | `id`, `title` (JSON), `club_id` | Sections of a club (teams, departments). |
| `division_member` | `member_id`, `division_id` | Pivot, constrained, cascade on delete/update, composite index. |
| `division_membership_type` | `division_id`, `membership_type_id`, `monthly_fee` | Pivot with a fee, constrained, cascading. |
| `payment_periods`, `club_payment_period` | | Billing cadence options per club. |
| `finance_accounts`, `statements`, `transactions`, `receipts`, `finance_contacts`, `tax_account_charts`, `tax_accounts` | | Bookkeeping: imported bank statements (CAMT/MT940), transactions, receipts, tax accounts. Out of scope for the federation work but must keep working. |
| `media` | | `spatie/laravel-medialibrary` polymorphic media table. |

### 1.2 Identity and authorization

| Table | Notes |
|---|---|
| `users` | Filament users and club admins; `preferred_locale`. |
| `personal_access_tokens` | Sanctum tokens for `User` **and** `Club` (both are `Authenticatable`). |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | spatie permission in teams mode; `club_id` is the team column on the pivot tables (`api/config/permission.php`). `User::clubs()` is a `morphToMany` through `model_has_roles`, so "which clubs does this user belong to" is answered by role assignments. |

### 1.3 Constraints and indexes as declared

- Original 2023 tables use `foreignId(...)` **without** `constrained()`: `membership_types.club_id`, `members.membership_id`, `members.club_id`, `memberships.membership_type_id`, `memberships.club_id`, `memberships.owner_member_id`, `divisions.club_id`. Only pivot tables and later finance tables declare real foreign keys. `memberships.membership_type_id` received a foreign key with `nullOnDelete` in `2026_03_04_140930_…`.
- Unique: `clubs.slug`. Composite index: `division_member(member_id, division_id)`.
- No check constraints or enum columns for status fields.
- `2023_06_20_125253_…` back-filled every existing membership's status to `applied` when the column was introduced.

### 1.4 Questionable decisions to discuss (not to fix yet)

1. Referential integrity between clubs and their children depends on application code, not the database (to be confirmed in section 2).
2. Status as free-text nullable columns; the null state is meaningful (`ApplyMembershipAction` requires `status === null` before applying), which is an implicit fourth state.
3. Permission and role rows inserted by 18 migrations rather than seeders.
4. `Club` as an `Authenticatable` with API tokens conflates an organization with a principal.
5. MySQL-only SQL in one migration and one model scope (see `docs/UPSTREAM_ANALYSIS.md` section 4).

### 1.5 Assumptions carried forward

- A member belongs to exactly one club and at most one membership; a membership belongs to exactly one club and one membership type.
- One user may administer several clubs but the API always acts on the first (`ClubPermission`).
- All money is integer cents; all timestamps are stored naive (`timestamp` columns, application timezone).

## 2. Live schema check

Verified 2026-09-01 against MariaDB 11.8 after `migrate:fresh --seeder=FakeDatabaseSeeder`. Raw output: `docs/baseline/schema_core.sql` (`SHOW CREATE TABLE` for the six core tables), `foreign_keys.txt` (all 24 referencing columns in the schema), `indexes.txt`, `row_counts.txt`.

- **Foreign keys on the core domain tables: two.** `memberships.membership_type_id → membership_types.id` (ON DELETE SET NULL) and `clubs.tax_account_chart_id → tax_account_charts.id`. The following have **no foreign key**: `members.club_id`, `members.membership_id`, `memberships.club_id`, `memberships.owner_member_id`, `memberships.payment_period_id`, `membership_types.club_id`, `divisions.club_id`. Section 1.4 item 1 is confirmed: referential integrity between a club and its members, memberships, types and divisions is enforced only by application code.
- **Indexes.** `members`, `membership_types` and `divisions` carry only their primary key. `memberships` has the primary key and the index that came with the 2026 foreign key. `clubs` has `slug` unique and the chart FK index. Every tenant-scoped query on these tables (`WHERE club_id = ?`, which `ClubScope` adds to almost everything) is a full table scan; harmless at 116 members, relevant for the performance milestone. Pivot tables and `model_has_roles` (composite primary key `club_id, role_id, model_id, model_type` plus team and morph indexes) are indexed properly.
- **Column types** match the migrations: `memberships.status` and `members.status` are `varchar(255) NULL`; `memberships.started_at` is `timestamp NULL`; money is `int(10) unsigned`; InnoDB, `utf8mb4_unicode_ci` throughout.
- **Seeded volume:** 6 clubs, 19 users, 116 members, 60 memberships, 33 divisions, 18 membership types, 3 roles, 56 permissions.
- **Observed but not explained:** `CREATE TABLE` statements took 6–24 s each on this container (the full `migrate:fresh` with seed took 298 s). To be examined before any performance claim is made.

## 3. Federation tables added in Milestone 2

Migrations `api/database/migrations/2026_09_02_1000*.php`; decisions in `docs/adr/0005` and `docs/adr/0006`; entity meaning in `docs/DOMAIN_MODEL.md`. Every new table has real foreign keys and indexes.

| Table | Keys and constraints |
|---|---|
| `federations` | `code` unique |
| `seasons` | FK `federation_id` cascade; unique (`federation_id`, `label`) |
| `member_organizations` | FK `federation_id` cascade; unique (`federation_id`, `code`) |
| `federation_administrators` | FKs `federation_id`, `user_id` cascade; unique pair |
| `organization_administrators` | FKs `member_organization_id`, `user_id` cascade; unique pair, **named explicitly** (see below) |
| `registration_applications` | FKs `member_organization_id`, `season_id`, `applicant_user_id` **restrict** on delete; `active_key` nullable unique (portable partial uniqueness); `idempotency_key` nullable unique; indexes (`member_organization_id`, `status`) and (`applicant_user_id`, `season_id`) |
| `audit_entries` | FK `actor_user_id` set null; indexes (`auditable_type`, `auditable_id`), `actor_user_id`, `request_id`; no `updated_at` |
| `clubs.member_organization_id` (new column) | nullable FK, set null on delete |
| `members.user_id` (new column) | nullable FK, set null on delete |

**Engine difference found by running the migrations on MariaDB after they passed on SQLite.** Laravel's generated name for the unique index on `organization_administrators` (`organization_administrators_member_organization_id_user_id_unique`) is 65 characters; MariaDB and MySQL limit identifiers to 64, SQLite has no limit. Because MariaDB DDL is not transactional and Laravel adds indexes with separate statements after `CREATE TABLE`, both pivot tables existed without their unique indexes when the migration failed, and the retry failed with "table already exists". Fix: the index is named explicitly; `api/tests/Unit/Federation/SchemaIdentifierLengthTest.php` now asserts on SQLite that every table, index and foreign-key name in the whole schema fits 64 characters. Evidence: `docs/baseline/northgate_seed_run.txt`.

**Verified on MariaDB 11.8, 2026-09-02** (`docs/baseline/northgate_seed_rows.txt`): the eight migrations ran in 14 s on the already-populated development database; the Northgate seeder produced 1 federation, 2 seasons, 3 organizations, 5 assigned and 1 unassigned club, 4 administrator rows, 5 applications in five distinct states and 15 audit entries whose actor and state chains match the seeder's script.
