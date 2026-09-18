# GT-003 verification record

Baseline confirmed before edits: clean main at `4f76415944e49ebf7e33196a4991f3a883d9049e` (`GT-002: establish organization lifecycle`), no remotes or partial client implementation. Scope is staff-managed StudioMember identity/profile lifecycle. One local commit is required: `GT-003: establish studio member lifecycle`. No push, remote configuration or GT-004 work.

## Exact commands and results

| Command                                | Result                                                                                                  |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `composer validate --strict`           | Passed                                                                                                  |
| `composer check`                       | Pint passed; Larastan level 8, zero errors; **53 PostgreSQL-backed tests / 459 assertions**, all passed |
| `pnpm typecheck`                       | Passed                                                                                                  |
| `pnpm lint`                            | Passed                                                                                                  |
| `pnpm format:check`                    | Passed                                                                                                  |
| `pnpm test`                            | **25 tests / 4 files**, all passed                                                                      |
| `pnpm build`                           | Passed; 59 modules, JS about 350.95 kB / 108.77 kB gzip, CSS 8.89 kB / 2.55 kB gzip                     |
| `pnpm test:e2e`                        | **2 browser flows**, desktop/mobile, passed with real CSRF; no page errors                              |
| `composer audit --locked`              | No vulnerability advisories                                                                             |
| `pnpm audit`                           | No known vulnerabilities                                                                                |
| `pnpm peers check`                     | No peer dependency issues                                                                               |
| `scripts/secrets-check`                | Passed secret/private/generated-artifact scan                                                           |
| `git diff --check`                     | Passed                                                                                                  |
| `php artisan migrate --no-interaction` | GT-003 incremental migration applied locally after backup                                               |
| `php artisan migrate:status`           | All migrations applied, GT-003 batch 3                                                                  |

No checks were waived or weakened. PHPUnit and browser suites ran sequentially against guarded gt_test under its own role. The GT-001 upgrade test now rolls back two migrations to reach its original baseline; a separate GT-003 upgrade test verifies existing grants/evidence remain unchanged and no clients/accounts are invented. Original migration definitions and dependency lockfiles are unchanged.

## Evidence

New backend coverage includes offline creation without contact/account, contact normalization, invalid fields and mass assignment, foreign/random member UUIDs, foreign preferred locations, active/inactive preference rules, shared-family duplicate warnings, cross-tenant duplicate isolation, archived history/restore, independent create/update/status/notes permissions, selected-location denial, token ability restriction and suspended/revoked authority.

PostgreSQL checks cover tenant/preferred-location foreign keys, valid status/mode/locale/contact constraints, public UUID uniqueness, optional per-tenant user linkage, and continuing audit UPDATE/DELETE/TRUNCATE rejection. A linked customer with no OrganizationMembership has no staff authority. The GT-002 real-process owner contention tests continue passing for revoke/suspend/demote. An eager-loading assertion confirms one location query for a 12-client directory page.

Audit privacy tests inspect member metadata for absence of names/contact/note values. Injected audit failure rolls back the profile change and emits only exception-class diagnostics with generic public errors. Profile/notes metadata does not retain full old/new PII. Operator permission adoption is explicit and audited. The focused review also caught and fixed an initially missing locations.view check when clearing a preferred location.

Frontend tests cover list, body-based search/filters, pagination, empty/error states, contact-optional offline creation, validation retaining form input, duplicate warning, profile, separate note visibility, preserving undisclosed notes during edits, archived restoration, scope denial and API errors.

Both browser flows retain the complete prior authentication/location/staff/settings/switching regression and add Clients → create offline profile without phone/email → search/open profile → edit name/operational note → change status → logout. Browser assertions wait for the persisted status in profile details, not just the selected dropdown value. Narrow form/profile overflow checks pass. Desktop/mobile screenshots of test-only client forms/profiles were visually inspected and remain ignored.

Two initial component-test issues (an unused destructured test variable and an incorrect Macedonian pagination label) were corrected; final lint/tests pass. No production control was relaxed to accommodate tests.

## Local state, privacy and limits

Local gt_dev was verified on loopback 55432 under gt_dev with zero users/organizations before migration. A mode-0600 native pg_dump backup was written beneath ignored `.local/backups` and the additive migration applied. No development users, clients, sample studios or credentials were created. Shared services/Redis configuration were untouched.

The UI displays preferred location and supports choosing it; optional directory location filtering is API-only. Selected-location staff are deliberately denied client access until a later genuine relationship model exists. Existing managers need explicit adoption of new permissions. Search is exact prefix, duplication advisory; no merging or account activation. Audit can prove profile fields changed but cannot reconstruct old PII values. Real retention/export/deletion and deployment controls remain future work.

See [client domain and rollout](../domain/studio-members.md), [API](../architecture/api.md), [security/privacy review](../security/gt-003-review.md) and [threat model](../security/threat-model.md). Packages, payments, groups, sessions, reservations, attendance, check-in, mobile authentication and GT-004 are deferred.
