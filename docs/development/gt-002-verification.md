# GT-002 verification record

Verified locally on HP against GT-001 commit `e04b789e87b959abaa9bfeabc8ae6a79d43384b4`. Initial main working tree was clean with no remotes and no partial GT-002. The task makes one incremental local commit: `GT-002: establish organization lifecycle`. No dependency versions or GT-001 migration definitions were changed.

Environment: PHP 8.5.4, Laravel 13.32, Larastan level 8, PostgreSQL 18.6 private native cluster on loopback 55432, Node 24.20, pnpm 12.3.4. Tests target guarded gt_test under gt_test; browser and PHPUnit run sequentially. No shared Redis flush or shared service configuration changes were made. Browser fixtures are test-only and never provision development identities.

## Commands and results

| Exact command                          | Result                                                                                       |
| -------------------------------------- | -------------------------------------------------------------------------------------------- |
| `composer validate --strict`           | Passed                                                                                       |
| `composer check`                       | Pint passed; Larastan level 8, zero errors; PHPUnit **36 tests, 257 assertions**, all passed |
| `pnpm typecheck`                       | Passed                                                                                       |
| `pnpm lint`                            | Passed                                                                                       |
| `pnpm format:check`                    | Passed                                                                                       |
| `pnpm test`                            | **17 tests across 3 files**, all passed                                                      |
| `pnpm build`                           | Passed, 58 modules; JS about 335 kB / 105 kB gzip, CSS about 7.75 kB / 2.32 kB gzip          |
| `pnpm test:e2e`                        | **2 browser flows**, desktop and mobile, passed with real CSRF and no page errors            |
| `composer audit --locked`              | No security vulnerability advisories                                                         |
| `pnpm audit`                           | No known vulnerabilities                                                                     |
| `pnpm peers check`                     | No peer dependency issues                                                                    |
| `scripts/secrets-check`                | Passed source secret/generated-artifact scan                                                 |
| `git diff --check`                     | Passed                                                                                       |
| `php artisan migrate --no-interaction` | Incremental GT-002 migration applied to gt_dev after private backup                          |
| `php artisan migrate:status`           | All migrations applied; GT-002 is batch 2                                                    |

No verification command was replaced or weakened. CI also includes pnpm peers check and git diff --check. Generated frontend bundles, browser screenshots/results, dependency directories, environments, private PostgreSQL and backup data remain ignored.

## Backend and PostgreSQL evidence

The 13 foundation tests retain authentication, password reset/revocation, token intersection, generic errors/security headers, mass-assignment and context-clearing coverage. New lifecycle tests exercise unauthenticated and foreign context, active/nonactive organization and staff status, terminal revocation, settings validation, location CRUD/status, bounded pagination, selected-location reads/writes/delegation, staff field visibility, independent financial grants, arbitrary role/permission tampering, token-restricted mutations, stale authority, operator commands and atomic audit failure.

Direct PostgreSQL tests reject cross-tenant assignment FKs, invalid role/status/locale/timezone and audit UPDATE/DELETE/TRUNCATE. Maximum-length multibyte location before/after values fit the bounded 8192-byte audit envelope. The upgrade test rolls back to GT-001, inserts legacy access, reapplies GT-002 and verifies IDs/grants are preserved with no inferred ownership, then exercises audited operator adoption.

Three concurrency cases use two separate PHP processes and PostgreSQL connections, not mocked transactions: self-revoke, self-suspend and self-demote. The parent holds the organization row lock until both workers are observed waiting on locks through pg_stat_activity (statistics snapshot refreshed each poll). After release each case gets one 200 and one 409, retains one active owner and records exactly one winning role/status event. The guarantee applies to writers following Lifecycle's organization-lock protocol; raw trusted SQL is outside it.

## Frontend and browser evidence

Component tests cover existing auth/navigation, organization rendering/switch acceptance/rejection, location list/validation with retained input, scope-aware create controls, staff grant visibility, read-only settings and preserving undisclosed grants during status-only edits.

Desktop/mobile Chromium flows cover real CSRF rejection, Macedonian default and English switch, session cookie protection, authenticated reload, location create/edit/deactivate, verified organization switch with reload persistence and old-tenant content removal, staff financial-grant separation, staff suspension/reactivation, timezone update, responsive navigation, logout and post-logout denial. Ignored desktop/mobile staff screenshots were visually inspected. No test traces or real account credentials were published.

During development the browser status selector initially used exact label text that included nested option text. Switching to the accessible combobox role fixed the selector; both final flows pass. An extra component test initially used Playwright's `exact` option with Testing Library; removing that unsupported option resolved TypeScript validation. These were test-code failures, not waived checks.

## Local state and limits

Before migration gt_dev had zero users and zero organizations. A private mode-0600 pg_dump backup was stored under ignored `.local/backups`, then the additive/rename migration ran successfully. No development account, sample studio or temporary password was created. Owner provisioning remains an explicit trusted operation for an existing user.

Selected-location assignment editing is implemented through the API; the minimal staff UI displays scope but has no location-assignment editor. The role/status/grant editor is functional. No public invitation/provisioning, customer domain, payment, reservation, attendance, audit viewer/export, request correlation or production deployment is delivered. `/me` choices are not paginated. Runtime/migration privilege separation and audit retention remain production prerequisites. See [security review](../security/gt-002-review.md) and [lifecycle decisions](../architecture/organization-lifecycle.md).
