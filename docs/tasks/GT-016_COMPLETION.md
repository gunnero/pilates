# GT-016 — Trainer & Studio Operations Dashboard

Verified on 2026-10-01 in `/home/gunner/Sites/pilates`. Work began on `main` at exactly `2b5923a5d345d22813cd7747d128ca65663a6e87`, subject `GT-015: establish notifications and communication`, with a clean tracked and untracked tree. The private baseline manifest is `.local/gt016/baseline.json`.

Exactly one local commit is required, with subject `GT-016: implement trainer and studio operations dashboard`. Obtain its full hash with `git log -1 --format=%H`; the report intentionally does not embed its own commit hash. No push, deployment, publication, remote PR or GT-017 work occurred.

## Delivery and domain ownership

The web operations workspace and a real Expo native staff screen provide studio-local Today, bounded upcoming ranges, My Sessions/current trainer assignment, authorized all-trainer overview, filters, pagination, counts, occurrence status, searchable rosters, waiting positions, walk-in evidence, contextual member profiles, financial warnings and communication status. MK and EN are supported. Loading, empty, error, conflict and refresh states use canonical server responses; there is no offline write queue.

| Surface or command        | Implementation and authority                                                                                                                                                                                                                                      |
| ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Read projections          | `app/Support/OperationsDirectory.php`, `app/Http/Controllers/OperationsController.php`; reuse TenantContext and SessionDirectory, canonical Reservation/WaitingListEntry/AttendanceRecord, FinanceDirectory cash/reversal aggregates and GT-015 delivery evidence |
| Native authentication     | `OperationsAccountController.php`, AccountSecurity per-email lock, Sanctum ability ceiling, current staff grants, 30-day expiry and existing ten-token ceiling                                                                                                    |
| Attendance and correction | Existing Attendance controller/service and policy; organization then session locks, current assignment/permission recheck, transactional audit/outbox                                                                                                             |
| Walk-in                   | Existing Attendance and Allocation admission; canonical capacity/FIFO, transactional reservation plus attendance, rollback on rejection                                                                                                                           |
| Web                       | `resources/js/components/Operations.tsx`, StudioShell navigation/context guards, existing AttendanceRoster commands, authorized Members profile deep link                                                                                                         |
| Native                    | `apps/mobile/src/screens/StaffOperations.tsx`, independent OperationsClient and `gt.staff.token` SecureStore credential; separate member/staff modes, refresh on resume, stale-response generation guards                                                         |
| Follow-up                 | Existing session, reservation, membership, cash and settings workflows; native opens authenticated web follow-up with organization UUID, never bearer credentials                                                                                                 |

No business writer, ledger, grant adoption, transport or speculative state was introduced. Existing member mobile credentials and APIs remain separate. The new staff token's abilities are a ceiling, not a replacement for current database authorization.

## Authorization and fields

| Context                                              | Sessions and roster                                                                                                    | Profile and financial fields                                                                                                                                   | Commands                                                                       |
| ---------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| Active Trainer staff                                 | `studio.access`, `sessions.view`, `attendance.view`; intersection of current trainer assignment and selected locations | Minimal session participant identity; no implicit contact or financial privileges                                                                              | Independently gated attendance management/correction and reservation admission |
| Other authorized staff/admin                         | Same entry gates; current staff location scope and optional filters                                                    | Contacts only with all-location `members.view`; membership state with `memberships.view` or `payments.view`; payment balances/status only with `payments.view` | Existing independent command policies, rechecked under locks                   |
| Communication overview                               | Non-Trainer, all-location `organizations.manage`                                                                       | Channel/state/count only, last 31 days; no recipients, contents or tokens                                                                                      | No read-triggered sends/retries                                                |
| Member token, inactive/revoked staff, foreign tenant | No staff dashboard authority                                                                                           | No projection                                                                                                                                                  | Denied by existing authentication/context/policy boundaries                    |

Member IDs must be participants in the scoped session. Foreign nested IDs follow existing 404 non-disclosure behavior. Trainer/location filters can narrow scope, never broaden it. Server projection protects fields before serialization. No health/private notes are added. Quick profiles cap period history at ten with explicit truncation. Account organization lists cap at 100 with explicit truncation.

## Operational semantics

- Stable session public IDs, snapshots, overrides and cancellations come from GT-006. Today is the organization's local date; inclusive ranges are limited to 31 days. UTC instants determine upcoming/in-progress/ended, preserving existing DST resolution.
- Active reservations occupy capacity. Waiting entries do not. Free places are `max(0, capacity - active reservations)`. Present attendance is independent of occupancy. Pending means an active reservation with no attendance record. Aggregates span the filtered range, not the displayed page or roster search.
- Waiting positions use the complete canonical `joined_at,id` queue. Walk-in badges project existence of immutable `attendance.walk_in` evidence without exposing audit metadata.
- Present is normally allowed from start minus 30 minutes through session end. After end, present requires correction permission and reason. Absent/no-show are post-end actions; changes to resolved attendance require correction permission/reason. Identical retries remain idempotent. No undo-to-unmarked state was invented.
- Walk-in uses Allocation and respects FIFO/capacity; attendance and booking commit together. Present protects reservation cancellation; any attendance protects session edits/cancellation. Operational invalidity disables offered actions, and the server remains authoritative.
- Duration memberships have no visit debit or payment-based admission gate. Membership validity and cash settlement are independent. Canonical cash/reversal state supplies unpaid/partial/paid information only to authorized viewers. No financial write occurs during attendance or dashboard reads.
- Expiry uses `StudioSettings.membership_expiring_days`. Payment follow-up counts positive canonical balances for started, uncancelled periods among scoped active participants. Restricted financial fields are not interpreted as zero or paid.
- Bounded alerts cover ended unresolved attendance, waiting demand, overridden and cancelled occurrences; warnings precede information. At most 50 source occurrences are considered, with explicit truncation. Financial follow-up and communication summaries retain their independent gates. Provider acceptance/tickets/receipts do not prove device delivery.

Reads use a short read-only REPEATABLE READ transaction without organization write locks. Authentication/context resolution precedes that snapshot. This gives consistent multi-query projections, not authorization for a future command. Commands recheck canonical state. Success and conflicts refresh counts/rosters; conflict messages survive background refresh. Web aborts and native generation guards discard obsolete context/profile responses, including delayed secure credential restore and app-resume races.

## API, files and schema

Added routes:

- POST `/api/v1/staff-auth/token`
- GET `/api/v1/staff/account`
- GET `/api/v1/organizations/{organization}/operations`
- POST `/api/v1/organizations/{organization}/operations/sessions/{session}/roster`
- GET `/api/v1/organizations/{organization}/operations/sessions/{session}/members/{member}`

Strict OperationsRequest/OperationsRosterRequest classes reject unknown keys and invalid UUIDs/enums/ranges. Default page size is 25, maximum 100; page maximum is 10000. Search is a JSON-body string of at most 100 characters, parameterized and escaped for ILIKE. Responses are `no-store, private`. Existing attendance endpoints and account-security logout are reused.

New source families include the controllers/directory/requests above, web Operations and tests, native operations screen/client/locales/tests, Laravel MK/EN validation strings, four backend Operations test files, browser operations flows, and the guarded preservation helper. Existing root/mobile localization, navigation, credentials, attendance error handling, browser fixtures and documentation were extended compatibly. Five historical test files received formatting-only corrections. The complete file inventory is available from `git show --stat` on the task commit.

**No schema or index migration was added.** All 17 historical migration files match both their initial SHA-256 manifest and strict-base Git bytes. Existing measured indexes satisfy budgets. Browser preparation verified fresh installation of those migrations in isolated `gt_test`; `tests/Support/operations-preservation.php` then ran a no-op upgrade on populated fixtures and verified 20 table counts plus the existing staff-grant hash unchanged (30 organizations, 17 migrations). Development data and grants were not migrated, reset or reseeded. No rollback is necessary for a nonexistent schema change.

## Verification environment and results

Linux x86_64, PHP 8.5.4, Composer 2.10.3, Node 24.20.0, pnpm 12.3.4, PostgreSQL 18.6. Tests used local loopback port 55432, database and role `gt_test`; PHPUnit and browser database work ran sequentially. Redis was not flushed or reconfigured. Existing guarded scripts loaded test configuration without printing secrets.

Final evidence is retained locally under `.local/gt016/verification/`; filenames below preserve their `/tmp` log basenames. These private generated logs, query plans and screenshots are intentionally untracked. The tracked tests reproduce the checks.

| Command                                                            | Actual final result / evidence                                                                                                                  |
| ------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| `composer validate --strict`                                       | Pass; `gt016-composer-validate-final.log`                                                                                                       |
| `composer check`                                                   | Pint pass, Larastan level 8 zero errors, **387 tests / 3,981 assertions passed**; `gt016-completion-backend.log`                                |
| `composer test -- --filter=OperationsAccountTest`                  | Final token-cap regression: **3 tests / 17 assertions passed**; `gt016-staff-auth-final.log`                                                    |
| `pnpm typecheck`                                                   | Pass; `gt016-types-verified.log`                                                                                                                |
| `pnpm lint`                                                        | Pass, including native source; `gt016-lint-verified.log`                                                                                        |
| `pnpm format:check`                                                | Pass; `gt016-format-verified.log`                                                                                                               |
| `pnpm test`                                                        | **115 tests / 16 files passed**; `gt016-web-final.log`                                                                                          |
| `pnpm build`                                                       | Production build pass; `gt016-build-final.log`                                                                                                  |
| `pnpm test:e2e`                                                    | **28 passed**, 14 desktop and 14 mobile; `gt016-browser-verified.log`                                                                           |
| `pnpm mobile:check`                                                | Native TypeScript pass; **81 tests / 5 suites passed**; Expo Doctor **21/21**; iOS and Android Hermes exports pass; `gt016-native-verified.log` |
| `pnpm --dir .local/gt016/prebuild exec expo prebuild --no-install` | Pass in isolated copied project; `gt016-prebuild-final.log`                                                                                     |
| `php tests/Support/operations-preservation.php`                    | Pass, populated no-op migration, 20 tables and grant hash preserved; `gt016-preservation.log`                                                   |
| `composer audit --locked`                                          | No advisories; `gt016-composer-audit-final.log`                                                                                                 |
| `pnpm audit`                                                       | No vulnerabilities; `gt016-pnpm-audit-final.log`                                                                                                |
| `pnpm peers check`                                                 | Pass; `gt016-peers-final.log`                                                                                                                   |
| `scripts/secrets-check`                                            | Pass; `gt016-secrets-verified.log`                                                                                                              |
| `git diff --check`, staged diff check and manual review            | Pass; task files only, no environment files, generated bundles or secrets staged                                                                |

No test was skipped or assertion/coverage threshold weakened. Existing PostgreSQL integrity and concurrency suites are included in `composer check`. The baseline suite passed 347 tests / 3,298 assertions after restoring the private local test database; the new full suite adds 40 collected backend tests. Web/native tests also retain their existing suites.

Initial checks exposed five baseline Pint failures and a stopped private test PostgreSQL cluster. Formatting-only fixes and the existing local database start procedure resolved them. Implementation regressions found by tests included a cleared attendance conflict message, responsive selector overflow and stale native response/credential ownership; these were fixed with regression coverage. An existing settings browser selector ambiguously matched both banner language and studio locale; it is now scoped to the banner without weakening assertions.

Baseline CommonMark 2.10.1 had newly reported advisories GHSA-97jj-33gv-5xf9 and GHSA-3q6v-r5mr-hxv8; the narrow update to 2.10.3 resolved them. Expo Doctor required compatible patches: Expo 57.0.25 → 57.0.26, camera 57.0.5 → 57.0.6 and constants 57.0.19 → 57.0.20, including constants deduplication and the required transitive expo-modules-core 57.0.19 → 57.0.20 patch. No audit suppression or broad unrelated upgrade was used. Nonfatal output includes the web bundle-size warning and native Jest's delayed open-handle shutdown notice; commands completed successfully, and thresholds were not changed.

## Real PostgreSQL concurrency

`OperationsConcurrencyTest` runs nine overlapping scenarios three times each, plus three cross-tenant progress attempts: **30 overlapping attempts in 28 collected tests**. Workers are independent PHP processes using the same owning services as HTTP actions, with independent PostgreSQL connections at the existing READ COMMITTED write isolation. A parent transaction holds the organization row; a bounded barrier confirms both workers are blocked on actual PostgreSQL locks before releasing it. Barrier deadline is 10 seconds, worker deadline 30 seconds; polling observes lock state rather than choosing outcomes by arbitrary sleeps. Unexpected timeout/deadlock is a failure, not retried into success.

Scenarios: duplicate present; two walk-ins for the final seat; normal reservation versus walk-in for the final seat; reservation cancellation versus check-in; session cancellation versus check-in; attendance finalization versus correction; absent versus corrected present; trainer reassignment versus old-trainer check-in; membership cancellation versus attendance. Separate-tenant work completes while the first tenant's organization is locked, on three attempts.

Assertions check canonical seat bounds, no active reservation/waiting overlap, one attendance result and intended audit effects, preserved money and tenant isolation. Reassignment permits either check-in followed by rejected edit, or reassignment followed by rejected old-trainer action, never both successes. Membership cancellation and attendance can both succeed because existing duration memberships do not gate admission; cash remains unchanged. Correction follows the existing permission/reason state machine. No duplicate consumption counter was invented.

## Performance evidence

`OperationsPerformanceTest` creates two tenants, 100 sessions on the selected day, ten historical sessions, another tenant's session, a 100-member roster, ten waiting entries, ten memberships/partial cash payments and 21 notification records. It compares page sizes one and 100 and records response bytes. Twenty warmed in-process authenticated HTTP-kernel reads use nearest-rank p95 (sorted sample 19 of 20). These measurements exclude TCP/TLS and production contention; they are not SLAs.

| Read                                | Queries (1 / 100 rows) | Bytes (1 / 100 rows) | Warm p95  | Budget               |
| ----------------------------------- | ---------------------- | -------------------- | --------- | -------------------- |
| Dashboard, including summary/alerts | 21 / 21                | 1,403 / 86,841       | 42.799 ms | ≤25 queries, ≤500 ms |
| Roster                              | 18 / 18                | 1,379 / 30,834       | 18.544 ms | ≤30 queries, ≤500 ms |
| Quick profile                       | 14                     | 647                  | 12.075 ms | ≤30 queries, ≤500 ms |

All budgets pass without relaxation. `.local/gt016/performance.json` contains raw measurements and EXPLAIN (ANALYZE, BUFFERS): seven dashboard plans, four roster plans, plus the day/session lookup. Existing tenant/start-time and relationship indexes serve these bounded queries; measurements did not justify a migration. Batching keeps query counts constant as page rows increase. No cross-request authorization cache was added.

## Browser, native and security evidence

New browser flows cover a restricted trainer's Today/roster/profile/check-in/walk-in, administrator overview/communication/empty-day filters, and two independent authenticated operators where a stale write conflicts and refreshes to the canonical no-show state. Existing attendance/finance/member/settings flows remain in the 28-pass suite. Direct tenant/role/financial tampering, date/DST boundaries, cancelled/search states, cash reversal and scope revocation also have backend coverage. Web/native component tests cover actions, rejected responses, credential separation, locale, navigation and stale response handling.

Browser assertions and inspected screenshots cover widths 1280, 768 and 360, MK/EN, visible controls and no horizontal overflow. Artifacts: `.local/gt016/screenshots/gt016-1280.png`, `gt016-768.png`, `gt016-360.png`. Semantic labels/buttons and keyboard activation of profile open/close controls supplement layout assertions; no exhaustive accessibility conformance claim is made.

The focused architecture-aware manual security review is in `docs/security/gt-016-review.md`. It covers tenant/assignment IDOR, field-level financial filtering, immutable server authority, token storage/caps/revocation, stale caches, bounded inputs, audit and notification evidence. It does not rely on stale GT-004 generated scan bookkeeping or claim an exhaustive automated scan. Final migration hashes, complete source/dependency/test diff and staged files were reviewed for preservation, secrets and generated contamination.

## Limits and scope decisions

- Native component tests, bundles, Doctor and prebuild passed. No simulator/physical-device binary execution, Xcode/Gradle compilation or real provider delivery was verified. These remain explicit external limitations.
- Native membership/cash/full-client follow-up opens existing web workflows and requires separate web sign-in/current studio verification; no staff bearer token crosses into the browser.
- Trainer/location/group selectors list values present on the current result page and are labelled accordingly; date/status filters and paginated navigation remain available. There is no unbounded organization directory preload.
- Alerts and recent history are bounded with truncation, and override evidence is not a complete reassignment history. Communication status does not prove that a person saw a notification.
- No zero-capacity/unassigned-trainer synthetic sessions, visit-credit ledger, financial admission gate, offline writes, staff push registration, persistent alert dismissal, new transport, deployment or GT-017 feature was added.
- Offline local credential removal cannot prove remote token revocation; existing account-security semantics and expiry remain authoritative. Database owners remain privileged outside application authorization.

See `docs/domain/operations-dashboard.md` for the API/consistency contract, `docs/operations/daily-operations.md` for staff workflows and `docs/tasks/GT-016_PLAN.md` for ownership and original budgets. After creating the single commit, verify its sole parent is the strict base, the range contains one commit, the exact subject matches and tracked/untracked status is clean, using read-only Git checks.
