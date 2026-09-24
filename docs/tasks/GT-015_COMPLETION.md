# GT-015 — Notifications & Communication Engine

GT-015 extends the existing notification domain with native push, queued email architecture, preferences, announcements and durable delivery recovery. Production provider/device acceptance remains a documented environmental limitation; no release or deployment is included.

Baseline: `5446160306f243138f6fb18380bb6cc73e4f40c3` — `GT-014: establish studio settings`. Exact HEAD and a clean working tree were verified before changes. GT-001–GT-014 history is unchanged.

GT-015 commit: the one local commit containing this report, message `GT-015: establish notifications and communication`. Resolve its full hash with `git log -1 --format='%H %s' -- docs/tasks/GT-015_COMPLETION.md`; the final delivery response records it. A file cannot contain its own future commit hash without changing that hash.

## Delivered domain and schema

One additive migration, `2026_09_24_000005_extend_communication_engine`, extends existing `platform_notifications`, `notification_deliveries`, attempts and preferences, and adds `studio_announcements`, `push_token_owners`, `device_push_tokens` and `notification_push_receipts`. No duplicate inbox/settings/reservation domain is introduced. No grants, extensions or shared service configuration are changed.

The [domain/API contract](../domain/communication-engine.md) documents the typed catalog, enum-derived categories/priority, immutable MK/EN snapshots, current-source semantics and durable recipient evidence. Existing reservation/session, waiting promotion, membership and cash types remain stable. GT-015 adds waiting joined, waiting session cancellation and studio announcements. Ordinary present attendance and established account-security flows are unchanged. The current waiting policy has no expiry/missed transition, so that reserved catalog variant is not emitted.

Logical notifications have separate in-app, device and email destinations. PostgreSQL enforces tenant/source/destination ownership, valid channel/state/error classes, event/destination/request/token uniqueness and immutable ownership/evidence. Organization locks, account/token advisory locks, delivery leases, attempt identities and receipt transitions protect competing writers. Existing capacity, FIFO, attendance and sold financial snapshots remain authoritative.

## Delivery, preferences and experience

The recipient-scoped inbox retains pagination, unread state and mark-one behavior, adding unread-count and mark-all APIs. Optional announcements expire after 30 days; historical delivered content remains identifiable. Seven effective preference booleans support reminders, session changes, waiting updates, membership expiry, announcements, push and email. Operational/security in-app evidence cannot be disabled; external channels respect current preferences. Missing rows use documented defaults.

Expo registration binds encrypted provider tokens to immutable account ownership, tenant/member and current native login. Registration/rotation, permission denial, late-request cleanup, logout, account/studio switching, invalid tokens and safe cold/warm notification navigation are implemented. Push payloads contain generic translated text and a notification route only. The native app fetches authoritative API data after a tap; credentials remain in SecureStore and member queries remain bounded memory only.

Provider I/O runs outside business transactions. Queue jobs claim durable destinations, retry transient errors with bounded attempts, and retain finite failure evidence. Expo tickets enter `awaiting_receipt`; receipt acceptance, permanent rejection and timeout are distinct. Laravel text mail uses localized snapshots and the verified account email after opt-in. Local/testing default external transports are disabled even when flags are true. No real provider delivery was performed.

GT-014 remains authoritative for reminder lead time/enablement and expiry-warning policy. Existing bounded current-state generation revalidates changed/cancelled sources, organization timezone and preferences. Successful committed waiting promotion alone produces a promotion notice. Cancellation now also notifies current waiting members without mutating FIFO records.

Announcements require current all-location `organizations.manage`, accept bounded bilingual plain text and an idempotency UUID, and fan out in queued batches of 100 with an atomic cursor. API targeting supports all active members or a tenant-owned group. Cancellation stops pending fan-out/delivery. Staff status exposes recipient totals and channel/state aggregates without tokens or private provider data. No new permissions are granted.

Web and native inboxes add mark-all and expanded preferences. Studio settings adds responsive MK/EN announcement creation/status/cancellation. Native notice detail links to current session/membership state. Desktop/mobile browser flows cover announcement delivery, session change/cancellation, waiting promotion, membership warning, read state, preferences and tenant/member denial. Both viewport screenshots were captured locally; narrow rendering was checked for horizontal overflow.

Meaningful device, preference and announcement actions use the append-only business audit transaction. Automated attempts/receipts use durable operational evidence and finite logs rather than new business-audit noise. Existing audit history is untouched. [Worker operations](../operations/notifications.md) documents scheduler, database queue, retries, configuration and recovery.

## Preservation

- Before changes, populated GT-014 preservation passed: 1 test, 69 assertions.
- Pre-change backup: `.local/backups/gt-dev-before-gt015-20260924-223922.dump`.
- After inspecting the final migration plan, fresh pre-apply backup: `.local/backups/gt-dev-before-gt015-20260924-231246.dump`. Both custom archives were verified with `pg_restore --list`, retained privately with mode 0600, and excluded from Git.
- The migration applied successfully to guarded local `gt_dev` on loopback 55432. Counts and SHA-256 digests of every pre-existing column in all 29 captured tables matched before/after. The development baseline had zero rows; this is explicitly not populated preservation evidence.
- Populated PostgreSQL migration/regression fixtures independently preserve organizations, staff grants, clients, trainers, groups, schedules, sessions, reservations, waiting/permanent records, attendance, finance, notifications, account security and studio policy. GT-015's dedicated test compares every old column across 29 tables. No reset/reseed of development data occurred.

## Verification

Required checks use the established commands:

| Command/check                                            | Result                                                                                                        |
| -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| `composer validate --strict`                             | Passed                                                                                                        |
| `composer check`                                         | Pint, Larastan level 8 and full PostgreSQL suite passed: 347 tests, 3,298 assertions                          |
| `pnpm typecheck`                                         | Passed                                                                                                        |
| `pnpm lint`                                              | Passed, includes native source/tests                                                                          |
| `pnpm format:check`                                      | Passed                                                                                                        |
| `pnpm test`                                              | 107 tests, 15 files passed                                                                                    |
| `pnpm build`                                             | Passed production web build                                                                                   |
| `pnpm mobile:check`                                      | Native TypeScript passed; 75 tests, 4 suites passed; Expo Doctor 21/21; iOS and Android Hermes exports passed |
| `pnpm --dir apps/mobile exec expo prebuild --no-install` | iOS/Android native generation passed; no signing, pods or device build                                        |
| `pnpm test:e2e`                                          | 22 passed: 11 desktop and 11 mobile, each project 1.2 minutes                                                 |
| `composer audit --locked`                                | No security vulnerability advisories                                                                          |
| `pnpm audit`                                             | No known vulnerabilities                                                                                      |
| `pnpm peers check`                                       | No peer dependency issues                                                                                     |
| `scripts/secrets-check`                                  | Passed for 505 source files                                                                                   |
| `git diff --check`                                       | Passed                                                                                                        |
| Redis integration                                        | Unique random GT-prefixed key: SETEX / GET / DEL passed on loopback; no flush                                 |

The Redis probe uses PHP's Redis extension with a random `gt:gt015:verification:` key and 30-second expiry, deleting only that key in `finally`. This follows the established isolated-key procedure; PHPUnit uses an array cache and therefore does not itself establish Redis integration.

The full backend count includes 29 GT-015 communication/provider/migration/concurrency cases. Real PostgreSQL competing-process coverage comprises seven GT-015 cases (registration, read state, announcement fan-out, push claim, actual database queue workers, FIFO promotion and session cancellation) plus four retained notification races (domain event, reminders, expiry and in-app delivery). Workers demonstrably contend on PostgreSQL locks; the queue case consumes two real queued jobs and asserts no leftovers/failed jobs. Tests use guarded local `gt_test` under role `gt_test`; PHP and browser suites run sequentially. Shared Redis is not flushed. The browser wrapper now runs desktop then mobile as separate complete Playwright projects, each using the existing guarded test seeder and database cache. This prevents unrelated desktop login-IP counters from rate-limiting mobile login fixtures; production rate limits are unchanged. The first combined run exposed this cross-project test-state coupling (20 passed, 2 limited); the isolated full rerun is the final result below.

The [security review](../security/gt-015-review.md) records manual review and completed Codex Security scan `14eb6f9f-fe6e-43ab-8462-9efbfa37c80c`: 52 source items, zero confirmed findings. It explicitly distinguishes the captured snapshot from final manual deltas and the tool's filtered test/documentation inventory. Review corrected the registration-cap reactivation/race edge, restored clean rollback error enums, excluded expired device logins before bounded destination selection and removed waiting-notice query multiplication. No in-scope confirmed vulnerability remains.

## Known limitations and deferred work

Physical iOS/Android push, APNs/FCM/Expo credentials, real SMTP delivery and deployed worker infrastructure were unavailable. Provider tests use fakes; no successful external delivery is claimed. Native prebuild/bundles do not establish signed application or device acceptance. Expo Go does not support remote push validation.

External delivery is at-least-once across provider acceptance/database persistence crashes; accepted messages cannot be retracted. Offline logout clears local identity but cannot prove unreachable remote revocation. A previously account-bound provider token cannot transfer to another account; a fresh provider token/installation is required. Group targeting is API-only in this milestone; the web composer targets all active members. Worker dispatch uses the configured default queue connection, which must match the documented database worker. Optional reminder catch-up remains bounded and organization-allowlisted. No SMS, WhatsApp, Viber, marketing automation, signing/store release, deployment or GT-016 work is included.

All required checks passed before the single local GT-015 commit. Delivery Git checks: `git status --porcelain` must be empty and `git rev-list --count 5446160306f243138f6fb18380bb6cc73e4f40c3..HEAD` must equal `1`; both are verified immediately after committing and reported in the final response. Nothing was pushed, no release/deployment was started and GT-016 was not started.
