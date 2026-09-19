# GT-005 verification record

Baseline confirmed before edits: clean main at e7487a4154820eadc3ef63b7059123b7afb44dea (`GT-004: establish trainer profiles`), no remotes or partial groups/schedule implementation. The supplied brief ended mid-section 34; clarification was requested while implementation continued. The established complete verification, backed-up migration and single local commit procedure was followed. Commit message: `GT-005: establish groups and recurring schedules`. No push, remote configuration or GT-006.

## Checks

| Command                                          | Result                                                                                  |
| ------------------------------------------------ | --------------------------------------------------------------------------------------- |
| `composer validate --strict`                     | Passed                                                                                  |
| `composer check`                                 | Pint passed; Larastan level 8, zero errors; 84 PostgreSQL tests / 853 assertions passed |
| `pnpm typecheck`                                 | Passed                                                                                  |
| `pnpm lint`                                      | Passed                                                                                  |
| `pnpm format:check`                              | Passed                                                                                  |
| `pnpm test`                                      | 43 tests / 6 files passed                                                               |
| `pnpm build`                                     | Passed, 63 modules; JS about 383.7 kB / 115.4 kB gzip, CSS 9.87 kB / 2.72 kB gzip       |
| `pnpm test:e2e`                                  | 2 desktop/mobile flows passed, real CSRF and no page errors                             |
| `composer audit --locked`                        | No security vulnerability advisories                                                    |
| `pnpm audit`                                     | No known vulnerabilities                                                                |
| `pnpm peers check`                               | No peer dependency issues                                                               |
| `scripts/secrets-check`                          | Passed                                                                                  |
| `git diff --check`                               | Passed                                                                                  |
| `php artisan migrate --pretend --no-interaction` | Additive schema/catalog plan inspected                                                  |
| Guarded native backup + Laravel migrate          | Passed; all eight existing table digests preserved                                      |
| `php artisan migrate:status`                     | All migrations applied; groups/rules batch 5                                            |

No checks waived, no dependency/lockfile changes and no existing migration definitions rewritten. PHPUnit and browser suites ran sequentially against guarded gt_test. Browser work never reset gt_dev or changed shared Redis/service configuration.

## Behavior and race evidence

Twelve new group API tests cover capacity bounds/lifecycle/archive restoration, unassigned setup, current trainer eligibility, foreign/inactive locations, inactive profiles and suspended/revoked staff, selected-location reads/writes, foreign group/trainer/rule identifiers, mass assignment, independent group/schedule permissions and explicit adoption. Source availability changes immediately alter operational issues without deleting group/rule history. Selected-location staff still cannot see clients.

Schedule tests cover invalid weekday/time/duration/date, no backdating, duplicates, same-group overlap, trainer overlap across groups, adjacent times/disjoint periods, simultaneous same-location groups without a shared trainer, future replacement/close/cancel, immutable predecessor timing and conflict rollback. Reassignment and group activation recheck conflicts; timezone changes cannot reinterpret retained rules. An injected audit failure rolls back rule creation and group edits. Explicit representations omit private staff/internal data; audit payloads exclude descriptions.

Direct PostgreSQL tests exercise composite location ownership, capacity/status/weekday/time/date bounds, native same-group overlap exclusion and continued audit immutability. Three real competing-process tests independently prove duplicate, same-group overlapping and cross-group trainer conflicts: both connections first contend for the organization lock, then exactly one succeeds and one receives 409; only one rule/audit event persists. The exclusion constraint supplies an additional database invariant for same-group rules.

A populated GT-004 upgrade fixture retains organization/location/staff grants/client/trainer/audit bytes and creates no groups/rules. Earlier GT-001/002/003 upgrade tests adjust rollback depth to their original baseline; all previous lifecycle/concurrency tests continue passing.

Nine frontend tests cover filtered group lists, configured capacity, group creation, location-dependent trainer choices and clearing a stale selection, operational invalidity/history, read-only access, archived restoration, empty/error states, local weekly creation, conflict errors retaining inputs, future replacement, close/cancel and schedule permission restrictions. All prior 34 frontend tests pass.

Both browser flows retain prior authentication/CSRF, tenant switching, locations, staff, trainer, settings and client regressions. New flow: create active location → Groups → create group with currently eligible trainer/capacity → add local weekly rule → replace from a future date → verify old inclusive end/new time → deactivate/reactivate group → search directory → Schedule overview → logout. Mobile overflow assertions pass for editor/overview; desktop/mobile group and schedule screenshots were inspected. A layout adjustment keeps schedule actions below timing details for readability.

Initial browser failures identified selectors that needed the controls' accessible roles and a shared test-account rate limit after the longer combined flow. Each viewport now has a distinct fixture owner; the actual API rate limit remains unchanged. No production control was weakened. Macedonian group statuses use feminine forms; English remains the fallback.

## Local migration and limitations

Laravel bootstrap verified local gt_dev under gt_dev on 127.0.0.1:55432 with no DB URL/config cache, without printing environment secrets. A mode-0600 native custom-format pg_dump backup was saved to ignored `.local/backups/gt-dev-before-gt005-20260919-003830.dump`; pg_restore --list confirmed archive readability before migration. This is not a full restore drill.

Pre/post counts and SHA-256 digests matched for users, organizations, locations, organization_memberships (including grants), membership_locations, studio_members, trainer_profiles and audit_events. All were empty in this development database; the separate populated upgrade regression provides nonempty preservation evidence. Both new tables remain empty. No automatic permission adoption, sample groups, schedules, accounts or credentials were created in development.

The [focused implementation review](../security/gt-005-review.md) found no concrete supported-path authorization, tenant, audit or conflict bypass. It is source review plus test evidence, not a workbench scan or production penetration test. [Domain documentation](../domain/training-groups.md) states local-time/DST policy, conservative short-period overlap semantics, raw-SQL limits for cross-group conflicts, nullable setup, dynamic operational invalidity and future occurrence/capacity obligations. No sessions, client assignments, reservations, attendance, packages, payments, mobile app or GT-006 implementation exists.
