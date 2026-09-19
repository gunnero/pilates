# GT-004 verification record

Before edits: clean main at `46630682557d3f1a45a579152ca659af3c95a37a` (`GT-003: establish studio member lifecycle`), no remotes or partial GT-004 domain. Implemented incremental operational trainer profiles only. Required local commit: `GT-004: establish trainer profiles`; no push, remote configuration or GT-005.

## Required checks

| Command                                          | Result                                                                                 |
| ------------------------------------------------ | -------------------------------------------------------------------------------------- |
| `composer validate --strict`                     | Passed                                                                                 |
| `composer check`                                 | Pint passed; Larastan level 8 zero errors; 68 PostgreSQL tests / 633 assertions passed |
| `pnpm typecheck`                                 | Passed                                                                                 |
| `pnpm lint`                                      | Passed                                                                                 |
| `pnpm format:check`                              | Passed                                                                                 |
| `pnpm test`                                      | 34 tests / 5 files passed                                                              |
| `pnpm build`                                     | Passed; 60 modules; JS 363.50 kB / 111.01 kB gzip; CSS 9.14 kB / 2.58 kB gzip          |
| `pnpm test:e2e`                                  | 2 desktop/mobile browser flows passed; real CSRF, no page errors                       |
| `composer audit --locked`                        | No security vulnerability advisories                                                   |
| `pnpm audit`                                     | No known vulnerabilities                                                               |
| `pnpm peers check`                               | No peer dependency issues                                                              |
| `scripts/secrets-check`                          | Passed                                                                                 |
| `git diff --check`                               | Passed                                                                                 |
| `php artisan migrate --pretend --no-interaction` | Additive trainer schema/catalog plan inspected                                         |
| Guarded native backup + Laravel migrate          | Passed; before/after existing-data digests identical                                   |
| `php artisan migrate:status`                     | All applied; trainer migration batch 4                                                 |

No checks waived. PHPUnit and browser suites ran sequentially on guarded gt_test; no development reset or fixture seeding. Original migrations and dependency locks remain unchanged. Legacy upgrade tests roll back the additional migration before exercising their original baselines.

## Behavioral evidence

Backend coverage enables every active staff role without creating authentication or client records/changing grants; verifies Administrator management, unauthorized actions, suspended/revoked/foreign staff, duplicate enablement, foreign/random trainer IDs and filters, protected input fields, no DELETE, independent profile/staff status and audit privacy. Location tests cover current all/selected scope, inactive locations, changed/removed assignments, suspended staff, selected-viewer intersection and preserved client denial. Token ability restrictions and stale actor context fail closed.

PostgreSQL rejects duplicate membership profiles, cross-tenant composite membership references, invalid status and oversized bio. Existing audit UPDATE/DELETE/TRUNCATE protection remains exercised; injected audit failure rolls back enablement. Two real child processes block on the same organization lock and produce one 201/one 409, one profile and one audit event. A populated GT-003 upgrade fixture preserves existing organizations, stored grants, clients and evidence and invents no trainer profile. One batched query returns bounded five-location summaries plus counts; complete eligible locations paginate.

Frontend tests cover list, body search/status/location filters, empty state, pagination, detail, operational update, inactive/active status, staff enablement, current eligibility, read-only/selected-scope restrictions, inactive staff reactivation denial and API errors without losing input. Browser flows retain earlier authentication/CSRF, switching, location, staff, settings and client regressions and add Staff → enable existing Administrator as trainer → Trainers search/detail → display-name/bio update → deactivate/reactivate → logout. Assertions distinguish inactive teaching from still-active staff access. Mobile overflow checks pass; desktop/mobile trainer screenshots visually inspected.

An initial mobile browser failure came from reusing the desktop run's already-enabled staff fixture. Each viewport now uses a distinct existing test Administrator; both flows exercise real first enablement. A final browser-test formatting issue was corrected. No production checks or authorization controls were relaxed.

## Development migration and review

Laravel configuration was bootstrapped without displaying environment secrets and required local gt_dev / gt_dev on 127.0.0.1:55432, with no DB URL or cached configuration. A mode-0600 native custom-format backup was saved to ignored `.local/backups/gt-dev-before-gt004-20260919-001221.dump`; pg_restore --list verified the archive was readable before migration. This checks archive readability, not a full restoration drill.

Pre/post counts and SHA-256 digests matched for users, organizations, locations, organization_memberships (including stored grants), membership_locations, studio_members and audit_events. All seven were empty in this development database; the separate populated upgrade test proves preservation with existing business records. trainer_profiles remains empty. No development account, organization, client, teaching profile or grant was created; no shared PostgreSQL/Redis configuration changed.

The focused review covered all source changes and found no concrete in-scope vulnerability. See [security review](../security/gt-004-review.md) for evidence and the review tool's stale checkpoint bookkeeping limitation. [Trainer domain](../domain/trainer-profiles.md) records the optional one-to-one relationship, role independence, live eligibility, explicit permission adoption, audit policy and future member-facing restrictions. No groups, sessions, reservations, attendance, packages, payments, mobile authentication or GT-005 work is included.
