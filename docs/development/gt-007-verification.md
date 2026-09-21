# GT-007 verification and completion record

GT-006 baseline: `56d4e9122f7e57bc5312544a19ea25deb776e8a1`, clean `main` tracking `origin/main`, remote `https://github.com/gunnero/pilates.git`. GT-007 adds exactly one local commit with message `GT-007: establish reservations and capacity allocation`; no push or GT-008 work. The commit hash is reported by the completion response (this file is included in that commit).

## Implementation and contracts

[Reservation domain](../domain/reservations.md) documents Reservation, PermanentSpot, WaitingListEntry, source/status semantics, inclusive dates, occurrence releases, transaction ordering, shared insertion/promotion engine, staff/offline workflows, scoped lookup, API routes, permissions/adoption, capacity counters, audit/privacy and future-mobile contract. Four tables are added by `2026_09_20_000001_establish_reservations.php`: reservations, permanent_spots, waiting_list_entries, permanent_spot_releases. Session composite identity and permission/audit allowlists are extended without changing stored grants.

Reservations remain active/cancelled durable records; sources are staff/permanent_spot/waiting_list, with member reserved for a future authenticated adapter. Cancellation is idempotent, frees capacity and synchronously promotes FIFO. Parent session cancellation preserves child history but invalidates usable entitlement. Occurrence overrides preserve allocations. The shared organization lock and session lock serialize capacity changes, insertion and promotion. All twelve specified races use actual PHP processes and PostgreSQL connections. Database partial uniqueness/exclusions/FKs supplement transaction-enforced aggregates; Redis has no authority.

Permanent admission sweeps simultaneously effective future demand against group capacity. Synchronization uses existing and newly materialized recurring sessions within 56 local dates and a bounded 800-session batch. Existing manual reservations retain provenance. Durable member/session release rows suppress automated recreation; explicit manual rebooking remains possible. Ending/shortening reconciles only attributable future permanent reservations and promotes waiting customers, preserving history and unrelated manual records. Operational conflicts are counted and make the materialization command fail explicitly.

React delivers localized session counts, paginated reservations/queue, member lookup, reserved/waitlisted feedback, occurrence release confirmation, permanent-place forms/effective dates/end policy, and permission-filtered member-profile relationships. Session cancellation shows child counts. Narrow layouts use existing responsive styles; desktop/mobile screenshots were visually inspected. No contact or notes fields enter booking lists.

## Required checks

| Command                                           | Result                                                                                            |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| `composer validate --strict`                      | Passed                                                                                            |
| `composer check`                                  | Pint passed; Larastan level 8, zero errors; 144 PostgreSQL backend tests, 1,424 assertions passed |
| `pnpm typecheck`                                  | Passed                                                                                            |
| `pnpm lint`                                       | Passed                                                                                            |
| `pnpm format:check`                               | Passed                                                                                            |
| `pnpm test`                                       | 59 tests in 8 files passed                                                                        |
| `pnpm build`                                      | Production build passed                                                                           |
| `pnpm test:e2e`                                   | 6 desktop/mobile flows passed                                                                     |
| `composer audit --locked`                         | No security advisories                                                                            |
| `pnpm audit`                                      | No known vulnerabilities                                                                          |
| `pnpm peers check`                                | No peer dependency issues                                                                         |
| `scripts/secrets-check`                           | Passed                                                                                            |
| `git diff --check`                                | Passed                                                                                            |
| `php artisan migrate --pretend --no-interaction`  | Additive plan inspected                                                                           |
| `php .local/gt007-migrate.php`                    | Guarded private backup, migration and data digests passed                                         |
| `php artisan migrate:status`                      | GT-007 applied as batch 7                                                                         |
| `php artisan gt:reservations:check`               | Zero development violations                                                                       |
| `php artisan gt:reservations:check --env=testing` | Zero violations on populated browser fixtures                                                     |

The final backend suite adds 37 tests to GT-006's 107: 24 reservation/API/integrity/security cases (including data-provider cases), 12 competing-process cases and one populated migration case. Backend and browser suites share gt_test and final successful executions ran sequentially. An earlier accidental overlap was stopped and discarded; no overlapping run is claimed as evidence. No tests ran on gt_dev. No verification command or invariant was weakened.

## Concurrency, database and browser evidence

Twelve races: final place; same customer double reservation; reservation versus capacity reduction; cancellation versus new reservation; duplicate cancellation/promotion; two promoters; capacity increase/promotion; last permanent place; group reduction versus assignment; duplicate synchronization; permanent release versus synchronization; session cancellation versus promotion. Each starts two workers behind a held organization lock and observes both waiting in pg_stat_activity before release. All retain capacity and reservation/wait exclusivity; additional assertions verify FIFO, suppression and admission.

Database tests exercise tenant FK rejection, reservation status/source checks, partial active uniqueness, waiting status/source/uniqueness, equal joined_at tie-breaking and permanent overlap exclusion. Prior audit-immutability and tenant/security migration tests remain passing. Audit insertion failure rolls allocation back. The reusable checker covers capacity, duplicate reservations/waiting, reserved+waiting collision, promotion linkage, effective permanent demand, source group agreement, recreated releases, ended assignment allocations and tenant ownership. All return zero unexplained violations.

The new browser journey runs on desktop and mobile: login → session → offline member reservation → full-session waiting entry → cancellation/FIFO promotion → capacity release → group permanent-place assignment → generated reservation → release only that occurrence → save/retry permanent assignment → verify no recreation → logout. Existing GT-001–006 foundation/session flows remain. Fixtures use explicit isolated organizations and a deterministic generated next-day occurrence manifest, without cron/wall-clock waiting. Earlier browser failures identified full-navigation language reset in the test and stale lookup results; tests now select language explicitly and the UI clears old lookup results before a new search.

## Migration preservation

Backup: `.local/backups/gt-dev-before-gt007-20260921-001153.dump`, private mode 0600 custom-format PostgreSQL archive, verified with pg_restore --list before migration. No full restore drill claimed. Pre/post counts and SHA-256 row digests matched for users, organizations, locations, organization_memberships (including grants), membership_locations, studio_members, trainer_profiles, training_groups, schedule_rules, training_sessions and audit_events. The development business tables were empty; a separate populated GT-006 upgrade regression verifies nonempty preservation including sessions and audit history. No reset/reseed or grant adoption occurred in development. No extension or shared service setting changed.

## Security, documentation and limitations

[Focused security review](../security/gt-007-review.md) records the pre-fix 39-source-file scan, three reproduced medium authorization findings, fixes, independent delta review and tooling caveats. Final code closes hidden-occurrence materialization, stale permanent-view grant and waiting-to-reservation permission bypasses. The scan is not misrepresented as a post-fix zero-findings report. Additional source deltas (bounds, queue pagination, lookup clearing, count refresh and minimal upcoming-session labels) were manually reviewed.

Documentation updated: README, AGENTS, architecture overview/API/authorization, reservation/attendance boundary, reservation domain, threat model, focused review and this verification record. Deferred: GT-008 attendance/check-in/no-show/QR, financial/package entitlements, payments, mobile/member authentication and self-service UI, notifications/SMS/WhatsApp, configurable cutoffs, priority/reordering and room scheduling. Operational limits: start-time cutoff, 56-day horizon, 800-session sync, 1,000 waiting entries/session, 10,000 effective permanent candidates, organization-wide write serialization, trusted raw SQL and no installed host scheduler. Staff resolve explicit concrete-capacity conflicts; permanent entitlement never overbooks.

Final Git review excludes private env files, backups, logs, build outputs, temporary scanner artifacts, browser screenshots, node_modules and vendor. Exactly one new GT-007 local commit; preceding six milestone commits and remote configuration are preserved. Expected final state is clean `main`, one commit ahead of `origin/main`, nothing from GT-007 pushed and GT-008 unstarted.
