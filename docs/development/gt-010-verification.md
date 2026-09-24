# GT-010 verification

Baseline: clean `main` at `a152b48a238d56bb8330de6f9a7a084b02a4df7e`, three commits ahead of origin/main. GT-010 was absent. Scope is notification infrastructure only; GT-011 and GT-012 remain deferred.

## Delivered

Five notification tables, typed immutable evidence, 15 notification types, atomic outbox hooks, bounded reminder/expiry commands, leased provider-neutral delivery, authenticated recipient inbox/read/preferences APIs and responsive MK/EN inbox. See [domain and operating contract](../domain/notifications.md) and [security review](../security/gt-010-review.md).

## Commands

| Command                      | Result                                                               |
| ---------------------------- | -------------------------------------------------------------------- |
| `composer validate --strict` | Pass                                                                 |
| `composer check`             | Pint, Larastan level 8; 213 PostgreSQL tests / 2,148 assertions pass |
| `pnpm typecheck`             | Pass                                                                 |
| `pnpm lint`                  | Pass                                                                 |
| `pnpm format:check`          | Pass                                                                 |
| `pnpm test`                  | 86 tests, 11 files pass                                              |
| `pnpm build`                 | Pass                                                                 |
| `pnpm test:e2e`              | 12 desktop/mobile browser flows pass                                 |
| `composer audit --locked`    | No security advisories                                               |
| `pnpm audit`                 | No known vulnerabilities                                             |
| `pnpm peers check`           | No peer dependency issues                                            |
| `scripts/secrets-check`      | Pass                                                                 |
| `git diff --check`           | Pass                                                                 |

Additional targeted commands: `php artisan test --compact --filter=Notification`, `php artisan test --compact --filter=NotificationTest`, `php artisan migrate --pretend`. PHP/browser tests run sequentially against guarded loopback gt_test under gt_test; never gt_dev. Frontend tests/build run independently.

## Test evidence

New backend tests cover creation, own/foreign tenant and recipient access, token ability intersection, unknown-input/mass-assignment rejection, unread/detail/bulk bounds, preferences/audit, reminder UTC/DST and catch-up behavior, stale/cancelled sources, waiting-list promotion, membership expiry/activation, cash privacy, retry exhaustion, rollback, event duplication, immutable evidence and database transitions. Existing attendance finalization now also proves unique no-show notifications and stale suppression after correction.

Four concurrency cases use two separate PHP processes blocked on a parent-held PostgreSQL organization lock, then released to compete: same reservation reminder, same membership expiry, duplicate domain event and duplicate delivery. Final rows prove one notification/delivery; worker duplication produces one finalized attempt. Expired-lease tests prove abandoned-attempt recovery. These are not mock-only tests.

Frontend tests exercise list/unread/read/bulk, empty state, pagination/filter, MK/EN, stale history, permission errors and preferences. Browser fixtures generate real domain events and explicitly run generation twice. Desktop/mobile flows verify reservation confirmation/cancellation, waiting promotion, one reminder/expiry each, payment/reversal privacy, persistent read state, bulk read, empty state, language switch, filled-inbox width and logout. Cancelled reservations are asserted to produce no reminder during fixture generation. Existing attendance, finance, foundation, reservation and session flows remain covered.

An initial browser test expected English after a full reload, while the established app default resets to Macedonian. The test now verifies Macedonian and explicitly chooses English after reload. The direct Playwright API assertion also needed an explicit same-origin Origin header to use Sanctum cookie authentication, matching real browser requests. No application invariant was weakened. Initial implementation failures were fixed and checks rerun.

## Preservation

Forward plan inspected before migration. Private custom-format backup created with mode 0600, checked with pg_restore --list, then migration applied without reset/reseed. All 20 GT-001–GT-009 table counts and SHA-256 row digests matched before/after, including grants and financial tables. Private evidence: `.local/gt010-migration-result.json`; backup name recorded there. New populated GT-009 regression includes real membership, cash and reversal records and compares all 20 tables. Earlier populated migration regressions continue passing after updating rollback depth for the additional migration.

## Limits

Only in-app delivery is implemented. No external provider, account linking, mobile app, marketing, administrative replay UI, retention policy or host cron installation. Scheduler generation requires explicit tenant allowlist configuration; bounded catch-up can miss optional reminders after longer outages. Current schema/runtime roles and database owners remain trusted. See the security review for scan artifact limitations.

## Final results

Backend: 213 tests, 2,148 assertions. Frontend: 86 tests in 11 files. Browser: 12 flows across desktop/mobile. After screenshot inspection, a scoped checkbox alignment adjustment was rebuilt and the two inbox browser flows were rerun. All required checks pass. Final staged diff review excludes private backups, environment files, generated build artifacts and test output. Exactly one local commit is required: `GT-010: establish notifications`; no push or GT-011 work.
