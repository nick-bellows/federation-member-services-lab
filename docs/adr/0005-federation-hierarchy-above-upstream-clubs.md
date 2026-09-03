# ADR-0005: Attach the federation hierarchy above upstream's clubs with additive keys

- **Status:** accepted
- **Date:** 2026-09-02
- **Milestone:** M2

## Context

The federation needs two levels above the club (member organization, federation), seasons, administrative roles at those levels, and a person who can sign in as an applicant. Upstream models a club as both an organization and a login principal, a member as a person without an account, and a user as an administrator with club-scoped spatie roles. Its core tables carry no foreign keys or indexes on `club_id` (`docs/DATABASE_BASELINE.md`), and every upstream workflow, scope and policy depends on `clubs` and `members` as they are.

## Decision

- **New tables** for `federations`, `seasons`, `member_organizations`, `federation_administrators`, `organization_administrators`, `registration_applications` and `audit_entries`, all with real foreign keys and indexes.
- **Two additive, nullable columns on upstream tables**: `clubs.member_organization_id` (a club may belong to one organization or none) and `members.user_id` (a person may be linked to one login principal). Both are foreign keys with `ON DELETE SET NULL`, so deleting an organization or a user never deletes upstream rows.
- **Users are the principals.** Applicants, organization administrators and federation administrators are `users`; the administrative roles are explicit pivot tables because spatie's team key is a club id and cannot express "administers this organization".
- **Upstream's `members` table is not the federation member.** A federation member is a user with an approved application; that fact is derived, not stored.
- Upstream's existing foreign-key gaps are **left as they are** in this milestone. Adding constraints to `members.club_id` and friends would be a behaviour change to upstream data with migration risk, and belongs in its own upstream-facing change with the maintainers' input.

## Alternatives considered

1. **A generic self-referential `organizations` table typed federation, organization, club** — cleanest hierarchy, but replaces upstream's central table and every scope keyed on `club_id`; a rewrite in disguise.
2. **A pivot `member_organization_club` and no column on `clubs`** — zero upstream schema change, but a club could belong to several organizations, which the domain does not want; the nullable column expresses "at most one" in the schema.
3. **A separate `persons` table with the OIDC subject** — cleaner identity separation, but a third people-shaped table and a second set of policies for no immediate benefit; `users` already carries roles, tokens and policies.
4. **Putting the OIDC subject on `members`** — smallest change, but members are club-scoped rows created by an anonymous form and one person can have several; they are not accounts.

## Consequences

- Positive: every upstream workflow runs unchanged with the new columns null; the federation domain has complete referential integrity; the diff against upstream stays small and reviewable.
- Negative: two vocabularies for "member" coexist and must be explained (`docs/DOMAIN_MODEL.md`); a club without an organization is valid, so queries "all clubs in the federation" must say what they mean by it.
- Follow-ups: the OIDC subject on `users` arrives with the identity milestone; the credential and eligibility derivation with the Learning Center contract.
