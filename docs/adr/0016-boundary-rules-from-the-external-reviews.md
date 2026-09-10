# ADR-0016: Boundary rules — a key belongs to its actor, an answer to its request, a window to its hierarchy, and authorization to the locked row

- Status: accepted (2026-09-10, Phase C1, from the external reviews of 2026-09-04)
- Milestone: C1
- Related: ADR-0006 (state machine and audit), ADR-0007 (identity boundary), ADR-0009 (credentials contract), ADR-0014 (security review), [INCIDENT-002](../incidents/INCIDENT-002-duplicate-submission.md), [`docs/THREAT_MODEL.md`](../THREAT_MODEL.md)

## Context

Two independent reviews of `a68f88f` (ROADMAP decision 10) found four defects that 237 passing tests had never caught, each verified against the code before it was accepted:

1. `StartApplication` looked an idempotency key up globally: a second person presenting a key someone else had used received the first person's application, and the controller re-filled the details from the replayed request on top of the stored ones.
2. `CredentialFacts` never compared the `member.subject` in the Learning Center's answer with the subject that was asked for; a test even normalised the mismatch by mapping alex's URL to sam's fixture.
3. `RegistrationWindowController` never checked that the window's season belongs to the organization's federation; `SeasonNotInFederationException` existed and was thrown nowhere.
4. `PatchApplicationFields`, `AttachDocumentMetadata` and `ReviewDocument` computed who may do what on the row the request had loaded, then locked the row and wrote; a transition committed in between was invisible to the check.

The reviews also flagged, in upstream's `publish.yml`, the pull request's branch name interpolated into a `run:` script, and the CI history showed one flaky upstream test (`MemberTest::test_consent_boolean_mutates_timestamp_column`, which compares a stored `now()` with a second `now()` read after the request; it failed on the MariaDB job of 2026-09-05).

All four defects have one shape: a rule stated at a boundary was checked against the wrong object — the key without its owner, the answer without its request, the window without its hierarchy, the row before its lock.

## Decision

1. **An idempotency key belongs to the applicant who presented it.** The unique constraint moves from `idempotency_key` alone to `(applicant_user_id, idempotency_key)` (migration `2026_09_10_100000`). The same applicant presenting the same key for the same window and role is a replay: the stored application answers with 200 and nothing on it changes, the details in the replayed request included. The same key with another window or role is a client fault: 409 `idempotency_key_reused`. Another person presenting the same key starts their own application. Two requests racing on the same key still meet the database: the loser gets 409 `duplicate_application` and its retry gets the replay.
2. **A provider's answer is bound to the request.** `CredentialFacts::fromArray` takes the subject that was asked for and refuses an answer about anyone else as a `ContractMismatchException`: a reviewer's refresh answers 502 `learning_center_error` and the previous snapshot stays; the refresh after approval parks in the outbox like any other consumer failure. The stored snapshot is bound again on read, against the subject the snapshot recorded, so a migrated or hand-edited row cannot read as someone else's facts. The test that mapped alex's URL to sam's fixture now builds alex's answer with sam's eligibility, which is what it meant.
3. **The hierarchy holds at creation.** A window's season must belong to the organization's federation, whoever opens it: 409 `season_not_in_federation`, for an organization administrator and a federation administrator alike, with no row and no audit entry written.
4. **Authorization is computed on the locked row.** The three actions open their transaction first, lock the application, and only then decide who may act, whether the application is still editable or still under review, and what is valid; the write follows in the same transaction. The regression tests simulate the interleaving deterministically: a listener on the transaction's beginning moves the application on before the lock, and the action must refuse.
5. **The inherited workflow reads the branch name from an environment variable**, and the version derived from it as a shell variable, never through template interpolation into the script. The flaky consent test brackets the request between two instants instead of comparing with a second `now()`. Both go into the upstream offer as items 5 and 6.

## Alternatives considered

1. **Keep the global unique constraint and translate its violation into 409 `idempotency_key_reused`** — tells one person that another person's key exists, and a client cannot tell a collision from its own reuse. Rejected.
2. **A request fingerprint (a hash of the whole payload) as the replay test** — the Stripe pattern. The parameters that define an application are the window and the role; the details are the applicant's editable fields, and rewriting them on a replay was the defect, so comparing them refuses a retry the applicant would have to repeat with a new key. Rejected for this resource; the ETag idea in `docs/future-work.md` is the general answer.
3. **Verify the subject in the HTTP client only** — leaves the stored payload unbound when it is read back. The check belongs to the value object that every reader uses. Rejected.
4. **Express the season rule as a validation rule (422) in `RegistrationWindowRequest`** — possible, but it is a domain invariant, not a document shape; 409 keeps it beside `window_closed` and `duplicate_application`, and the exception already existed for it. Rejected.
5. **A version column and `If-Match` instead of lock-then-authorise** — would also catch the interleaving, but changes the API for every client; the lock was already there, only the checks were on the wrong side of it. Rejected here; recorded as the follow-up it was.
6. **A real two-connection race in the test** — SQLite in memory has one connection, and on the two servers the assertion would depend on timing. The simulated interleaving asserts the ordering property on every engine, every run, and says in its docblock what it does and does not prove.

## Consequences

- Two error codes more (`idempotency_key_reused`, `season_not_in_federation`); one migration, idempotent on the three engines, whose `down` restores the global constraint and fails by design while two applicants share a key (the two tests that create that state clean it up before the rollback at teardown).
- `CredentialFacts::fromArray` has a third, required parameter; the mock's fixtures are unchanged because they already carry the subject they answer for. A stored snapshot whose recorded subject and payload disagree (which the write path cannot produce, since both come from one bound fetch) now fails the read loudly, with a 500 carrying the request id, rather than rendering as someone's participation.
- A refused patch, attachment or review now costs a `BEGIN` and a `ROLLBACK` it did not before; the cost is not measurable against the request.
- Ten regression tests (cross-user key, reused key, replay that does not rewrite, wrong subject at the value object, at the snapshot writer and over HTTP, cross-federation season, and the three interleavings), retained as `docs/baseline/c1_tests_before.txt` (failing) and `c1_tests_after.txt` (passing).
- The threat model's leaves 1.2.5, 1.3.3 and the new 1.2.7, 1.3.4, 1.6 and 4.8 say what is now mitigated and by which test; 6.6 records the workflow fix.
- What would make this decision wrong: a client that legitimately reuses one key across roles (none exists; the web app mints one per attempt), or a provider that answers with a canonicalised subject different from the one asked for (the contract says the subject asked for, and the mock and the reference implementation both echo it).
