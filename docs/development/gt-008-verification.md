# GT-008 verification and completion record

Baseline `c870b714523589cdd172731c6164aa5e416cd977` was clean main tracking origin/main, one local GT-007 commit ahead. GT-008 creates exactly one further local commit, `GT-008: establish attendance and check-in`. No push, remote modification, deployment or GT-009 work is included. Final hash is reported in the completion response.

## Delivered contracts

See [attendance domain/API](../domain/attendance.md) for status/method/window policy, roster privacy, offline check-in, transactional walk-in, no-show finalization, finite-reason corrections, encrypted expiring QR and future member adapter. Attendance has its own table and never replaces reservation/capacity. Check-in methods are trainer/staff/member/qr; staff manual source is inferred from current membership role. Member authentication/linkage has no new public endpoint. The responsive Macedonian/English trainer today and session roster screens support quick marking, walk-in, correction and local QR rendering.

## Required commands

| Command                                           | Result                                                                    |
| ------------------------------------------------- | ------------------------------------------------------------------------- |
| `composer validate --strict`                      | Valid                                                                     |
| `composer check`                                  | Passed: Pint, Larastan level 8, 173 PostgreSQL tests and 1,659 assertions |
| `pnpm typecheck`                                  | Passed                                                                    |
| `pnpm lint`                                       | Passed                                                                    |
| `pnpm format:check`                               | Passed                                                                    |
| `pnpm test`                                       | 69 tests in nine files passed                                             |
| `pnpm build`                                      | Passed; 69 modules                                                        |
| `pnpm test:e2e`                                   | Eight desktop/mobile flows passed                                         |
| `composer audit --locked`                         | No security advisories                                                    |
| `pnpm audit`                                      | No known vulnerabilities                                                  |
| `pnpm peers check`                                | No peer dependency issues                                                 |
| `scripts/secrets-check`                           | Passed                                                                    |
| `git diff --check`                                | Passed                                                                    |
| `php artisan migrate --pretend --no-interaction`  | Additive plan inspected                                                   |
| `php .local/gt008-migrate.php`                    | Private backup, migration and preservation digests passed                 |
| `php artisan migrate:status`                      | GT-008 applied as batch 8                                                 |
| `php artisan gt:attendance:check`                 | All five development counts zero                                          |
| `php artisan gt:reservations:check`               | All ten development counts zero                                           |
| `php artisan gt:attendance:check --env=testing`   | All five populated browser-fixture counts zero                            |
| `php artisan gt:reservations:check --env=testing` | All ten populated browser-fixture counts zero                             |

The backend suite adds 29 tests to the 144-test GT-007 baseline: 23 attendance/API/integrity cases, five competing-process cases and one populated migration case.

Backend and browser suites were executed sequentially against isolated local gt_test under gt_test. No tests ran against gt_dev. Prior migration tests now roll back one additional migration and omit new capabilities from historical grant fixtures; existing grants are never adopted implicitly.

## Behavioral evidence

Backend coverage includes reserved/offline present, duplicate idempotency and evidence, cancellation/session protection, walk-in after start and full capacity, FIFO priority, no-show timing/idempotency/cancelled exclusion, corrections, foreign resources, permissions and post-lock revocation, selected-location and trainer-scope denial, input allowlists, inactive members, QR tampering/expiry/session binding, authenticated member linkage and time boundaries, PostgreSQL constraints, audit rollback, bounded roster/today, explicit adoption and populated migration preservation.

Five real competing-process cases hold the organization row in the parent, observe both worker connections blocked through pg_stat_activity, then release: check-in/cancel reservation; walk-in/final place; duplicate check-in; finalization/late authorized present correction; session cancellation/check-in. The full suite retains GT-007's twelve competing-process scenarios and earlier invariants. Both consistency commands return zero unexplained violations, including active reservations above capacity.

Browser flows retain foundation, sessions and reservations on desktop/mobile, plus trainer login → My sessions → session roster → present → offline walk-in → verify roster/counts → QR display/hide → logout. Fixtures create explicit in-window sessions without cron/waiting. Screenshots were visually inspected at desktop and narrow mobile widths; no horizontal overflow. Live QR is hidden before retained screenshots. Frontend component tests cover today list, roster, quick marking, no-show, correction, walk-in, QR display, permissions, errors and empty state.

The first integration run exposed an existing permanent-assignment null-ID query that coupled creation to unrelated tenants' manual reservations. It was fixed with an existing-record guard and tenant predicate; the regression and browser flow pass. Walk-ins now refresh both roster and reservation list; check-in times use session timezone. A QR component test initially used a far-future fake expiry and overflowed timer bounds; its fixture now matches the 90-second contract. A backend window assertion was corrected to compare Carbon's numeric duration rather than require an integer PHP type. No security or business invariant was weakened.

## Migration and preservation

Migration `2026_09_21_000001_establish_attendance.php` adds attendance_records, its unique/FK/check/index protections, and extends finite permission/audit-subject catalogs. It does not alter stored grants or existing business rows. No PostgreSQL extension or shared configuration change was required.

Private backup `.local/backups/gt-dev-before-gt008-20260921-010020.dump` is a mode-0600 custom PostgreSQL archive, verified with pg_restore --list before applying batch 8. No full restore drill is claimed. Fifteen pre/post table counts and SHA-256 row digests match, including users, organizations, staff/grants/scope, clients, trainers, groups/rules, sessions, reservations, waiting entries, permanent assignments/releases and audit. Development tables were empty; the populated upgrade regression separately covers real sessions, permanent reservations, waiting and grants. No reset/reseed or permission adoption ran against development.

## Security and limits

[Focused review](../security/gt-008-review.md) documents completed Codex Security scan, 31 reviewed source surfaces, manual control tracing, independent backend review, integration fix and snapshot/tooling limits. Zero confirmed findings is not treated as proof of full security. Dependency and secret checks supplement source review.

Known limits: fixed policy windows; organization-level serialization; trusted raw SQL; bounded synchronous finalizer with no installed scheduler; no exceptional cancellation of sessions with attendance; no undo-to-unmarked endpoint; correction time is recording time rather than backdated arrival; QR replay within its short window is allowed only with independently authenticated/reserved identity. Deferred: member/mobile authentication and linking UI, QR redemption UI, payments/packages, GPS, notifications, advanced analytics and GT-009.

Documentation updated: README/AGENTS, architecture overview/API/authorization, reservation/session boundaries, attendance domain, threat model, focused review and this record. Private environment files, dumps, logs, generated builds, test screenshots and scanner reports remain ignored and excluded from Git.
