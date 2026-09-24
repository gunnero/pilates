# GT-011 verification

Baseline: `245ab5a660d2e8c6daa8c9adfd921754804ee265` (`GT-010: establish notifications`), initially clean main. No prior commits rewritten and nothing pushed.

Implementation contracts: [member self-service](../domain/member-self-service.md). The required checks below were executed against this local implementation; browser and PHPUnit database runs were sequential.

## Data preservation

`php artisan migrate --pretend --no-interaction` inspected the one additive migration. `.local/gt011-migrate.php` refused any target except uncached local loopback port 55432 / gt_dev role and database, wrote a private mode-0600 pg_dump custom archive and verified it with pg_restore --list. It captured row counts and SHA-256 digests before/after for 25 existing domain tables, including all GT010 notification tables. All matched exactly. No reset, reseed or grants change occurred. Detailed private evidence: `.local/gt011-migration-result.json`; archive is under `.local/backups/`.

The populated MemberMigrationTest constructs linked account, plan, membership, cash, reversal and delivered notification evidence, rolls back only GT011, and proves the upgrade preserves all selected rows and staff grants. Earlier milestone preservation tests roll back one additional migration to retain their original baseline.

## Test design

MemberSelfServiceTest exercises activation, replay, existing-account password proof, multi-studio links, profile allowlists, discovery, full-session waiting/promotion, token issuance/change and native lifecycle. MemberPrivacyTest exercises own finance/reversals, attendance protection, notification ownership, read/write abilities, inactive links, bounds and throttles. MemberIntegrityTest uses actual PostgreSQL constraint violations for tenant ownership, accepted-account consistency, pending uniqueness, immutable links/invitations and audit. MemberConcurrencyTest launches two separate PHP processes behind PostgreSQL row/advisory lock barriers and verifies five required races.

Frontend component coverage includes member dashboard, studio switching with old state removed, group schedules, availability, reserve/full conflict/waiting/cancel, profile, histories, activation, MK and staff invitation grant/delivery states. Existing Members tests isolate the new invitation child; MemberInvitations tests independently verify its behavior. History collections remount by endpoint to prevent stale rows being interpreted as another resource type.

Browser flow runs at desktop and iPhone-sized Chromium viewports. A guarded test-only router replaces the invitation delivery contract, writing private transient capability fixtures outside tracked source. Real application routes, sessions and CSRF remain enabled. This replaces artisan serve only in Playwright, because the default array mailer is process-local and cannot expose an HTTP request's delivery to the browser runner. Production public/index.php is unchanged. Tests invoke the guarded test delivery worker deterministically for notification visibility. No cron/wall-clock wait is needed.

Use `pnpm test:e2e` (scripts/browser-test), which supplies this host's existing local browser shared libraries. Direct `pnpm exec playwright test` cannot load libatk on this host. No shared service or system dependency was changed.

PHPUnit now uses database sessions, matching atomic credential revocation and the browser environment. Tests remain restricted to loopback gt_test under gt_test. PHPUnit and Playwright run sequentially.

## Final checks

| Command                      | Result                                                                                   |
| ---------------------------- | ---------------------------------------------------------------------------------------- |
| `composer validate --strict` | Pass                                                                                     |
| `composer check`             | Pint, Larastan at established level, 229 PostgreSQL-backed tests / 2,426 assertions pass |
| `pnpm typecheck`             | Pass                                                                                     |
| `pnpm lint`                  | Pass                                                                                     |
| `pnpm format:check`          | Pass                                                                                     |
| `pnpm test`                  | 97 tests in 13 files pass                                                                |
| `pnpm build`                 | Production build passes                                                                  |
| `pnpm test:e2e`              | 14 desktop/mobile flows pass, including the final test-router follow-up rerun            |
| `composer audit --locked`    | No vulnerability advisories                                                              |
| `pnpm audit`                 | No known vulnerabilities                                                                 |
| `pnpm peers check`           | No peer issues                                                                           |
| `scripts/secrets-check`      | Pass                                                                                     |
| `git diff --check`           | Pass                                                                                     |

`composer check` includes all real PostgreSQL integrity, populated migration and concurrency tests. Five GT011 competing-process scenarios pass: same invite, competing account link, final place, cancellation versus attendance, and reset-token single consumption. Prior GT001–GT010 checks remain enabled. No failure was suppressed. Full command logs remain privately under `.local/gt011-*.log`.

The new browser flow on both viewports covers A–H: staff-created member invitation, activation without duplicating the domain record, own booking, full-session waiting and FIFO promotion, cancellation, membership/cash/attendance views, delivered notification privacy, two-studio switching, foreign reservation/membership/payment/attendance/notification/profile-path rejection, responsive layout and logout. Mobile screenshot inspected at `test-results/mobile-member.png`; no horizontal overflow.

## Security and final review

[GT011 security review](../security/gt-011-review.md) records the completed Codex Security scan, exact retained threat model, 40/40 source coverage, zero findings, manually reviewed test/docs coverage and precise limitations. Final test-router guard hardening after the immutable snapshot is explicitly separated from scan claims. Production mail/native/deployment behavior is not claimed tested.

Final review covers staged migration/constraints/indexes, account linking, protected invitation evidence, serializers, allocation reuse, reset/token transactions, permissions, audit payloads, tests and documentation. No .env, private fixtures, backups, credentials, build output or unrelated work is included. Runtime and stored grants were not broadened. Exactly one local GT011 commit is required with `GT-011: establish member self-service`; no push, no earlier commit rewrite, no GT012 work.

Final test-router rerun: all 14 flows passed. A direct `/index.php` request also emitted the guarded e2e session cookie, confirming that PHP entry paths stay in the test bootstrap.
