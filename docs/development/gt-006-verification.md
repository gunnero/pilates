# GT-006 verification record

Baseline: clean main at `550fafd9c275a44abaf10cd32566de6e8f8907cd`, GT-005, no partial sessions or remotes. The original attachment ended in section 37; the user's continuation supplied sections 37–56 and the exact commit instruction. All supplied requirements were considered. Exactly one local commit is prepared with message `GT-006: establish training sessions`; no push, remote configuration, deployment or GT-007.

## Final checks

| Command                                          | Result                                                                               |
| ------------------------------------------------ | ------------------------------------------------------------------------------------ |
| `composer validate --strict`                     | Passed                                                                               |
| `composer check`                                 | Passed: Pint; Larastan level 8, zero errors; 107 PostgreSQL tests / 1,084 assertions |
| `pnpm typecheck`                                 | Passed                                                                               |
| `pnpm lint`                                      | Passed                                                                               |
| `pnpm format:check`                              | Passed                                                                               |
| `pnpm test`                                      | 52 tests / 7 files passed                                                            |
| `pnpm build`                                     | Passed, 64 modules; JS 398.45 kB / 118.32 kB gzip; CSS 10.10 kB / 2.77 kB gzip       |
| `pnpm test:e2e`                                  | 4 desktop/mobile browser flows passed                                                |
| `composer audit --locked`                        | No security vulnerability advisories                                                 |
| `pnpm audit`                                     | No known vulnerabilities                                                             |
| `pnpm peers check`                               | No peer dependency issues                                                            |
| `scripts/secrets-check`                          | Passed                                                                               |
| `git diff --check`                               | Passed                                                                               |
| `php artisan migrate --pretend --no-interaction` | Additive plan inspected                                                              |
| Guarded native backup and Laravel migration      | Passed; ten existing table counts/digests preserved                                  |
| `php artisan migrate:status`                     | All applied; GT-006 batch 6                                                          |

No check waived or invariant weakened. No dependency/lockfile change. PHPUnit and browser tests ran sequentially against guarded loopback gt_test. Shared Redis/services and private credentials were unchanged. The existing browser helper provides local Chromium libraries; no new browser/system dependency procedure was introduced.

## Backend and PostgreSQL evidence

GT-006 adds 23 backend cases to the previous 84: 16 session/domain/API/command cases, six competing-process cases and one populated upgrade case. Coverage includes effective dates/multiple weekdays/eight-week bounds/idempotency, normal Skopje UTC offsets across DST, gap/fold skips, unavailable source states, capacity/trainer/name snapshots, manual creation, whole editable occurrence updates, terminal/idempotent cancellation, cancellation persistence, protected exception rejection and transactional replacement/close/cancel reconciliation.

Authorization tests cover distinct sessions.view/manage/cancel grants, explicit adoption, Trainer-role own-assignment scope intersected with selected locations, source/destination assignment validation, actual foreign group/location/trainer/session identifiers, missing/guessed identifiers, cross-tenant calendars, protected-field injection and retained selected-location client denial. A scoped session remains attached to its saved location after a group move. Calendar representations exclude internal IDs, source rule keys, staff email/grants and fabricated availability. An eight-session calendar stays within 16 database queries.

Direct SQL tests reject bad capacity, unsupported status/source, inconsistent manual/recurring fields, foreign location, mismatched rule/group, invalid intervals, duplicate recurring identity, trainer overlap and audit mutation. Application boundary tests accept adjacent 18:00–19:00 / 19:00–20:00 and reject 18:59 overlap. Simultaneous sessions sharing a location with different groups/trainers are accepted.

Six real PostgreSQL races start two PHP processes behind a held organization lock, confirm both connections waiting through pg_stat_activity, then release them:

1. Same recurring occurrence generation: both operations succeed, exactly one durable occurrence.
2. Concurrent manual conflicts: one succeeds, one 409.
3. Materialization versus schedule replacement: no duplicate active occurrence; deterministic successor and cancelled predecessor history.
4. Conflicting overrides of two sessions: one succeeds, one 409.
5. Cancellation versus materialization: cancellation remains; no recreated occurrence.
6. Concurrent overrides of the same session: serialized successful writes retain all invariants.

Command tests prove bounded success, idempotent rerun, unknown organization failure, invalid-group reporting, no name/exception-prose leakage and continuation across a failing organization/group. Injected database failure rolls back the failed group while another organization creates its sessions. Injected audit failure rolls back both manual and generated creation. Existing lifecycle/audit/concurrency tests continue passing.

The populated GT-005 upgrade regression preserves users, organization/location/staff/grants/client/trainer/group/rule/audit data and creates no sessions. Earlier migration tests only adjust rollback depth and legacy grant fixtures for the added capability catalog; historical migrations remain unchanged.

## Frontend and browser evidence

Nine new frontend cases cover agenda/snapshots/timezone/no occupancy, date navigation and all filters, empty/error states, scoped action presentation and unavailable trainer messaging, manual creation with group defaults, occurrence-only edit/conflict recovery with inputs retained, explicit cancellation confirmation/history, denied view with no request and bounded preparation counts. Prior 43 frontend cases remain passing.

Four browser flows retain the full GT-001–005 regression journey on desktop/mobile and add a dedicated session journey on both viewports:

login → Schedule → inspect a generated occurrence → create a manual occurrence → edit its time/capacity only → confirm cancellation → verify cancelled history and unchanged generated occurrence → logout.

The seed explicitly prepares one upcoming dated fixture per isolated session-test organization through the actual materializer and writes the selected date to an ignored fixture manifest. Tests do not wait for cron or background timing. Dedicated fixture accounts avoid interfering with existing rate-limit tests; no API limiter was weakened. Narrow viewport overflow checks pass. Desktop cancelled-session and mobile occurrence-editor screenshots were visually inspected.

Early failures were corrected in test fixtures/selectors/imports: selected-scope test initially attempted to make an Owner selected-location, a migration snapshot sorted a composite-key table by nonexistent id, frontend assertions used different wording from the existing catalog, and the final browser cancellation assertion matched both a filter option and a card. The resulting tests follow existing ownership/semantics rather than weakening them. Final commands above pass.

## Migration preservation

The private migration runner verified uncached local gt_dev/gt_dev on 127.0.0.1:55432 without a DB URL, without printing environment values. It created mode-0600 native custom-format backup `.local/backups/gt-dev-before-gt006-20260919-010012.dump`. `pg_restore --list` verified archive readability before migration; this is not a complete restore drill.

Pre/post counts and SHA-256 digests matched for users, organizations, locations, organization_memberships, membership_locations, studio_members, trainer_profiles, training_groups, schedule_rules and audit_events. All ten development tables were empty; the separate populated upgrade regression supplies nonempty preservation evidence. The new training_sessions table also remains empty. No reset, reseed, automatic grant adoption or sample session/account creation occurred in gt_dev. Migration batch is 6; no PostgreSQL extension or unrelated service setting changed.

## Review and limitations

[Focused review](../security/gt-006-review.md) combines independent backend discovery, parent frontend review, actual 30/30 workbench source coverage and runtime evidence. Zero reportable findings; complete canonical coverage with no stale deferred checkpoint. Final test-only import/format/negative-identifier corrections and these verification/migration records were checked separately from the frozen scan. No runtime-source changes followed the reviewed snapshot.

[Session domain decisions](../domain/training-sessions.md) specify the conservative reconciliation contract: overridden/manually cancelled exceptions block affected weekly changes; untouched future occurrences can be replaced atomically while cancelled predecessor identities remain. Cancellation is terminal. Past scheduled status does not assert completion. Generation is command/API-driven; no host scheduler is installed. GT-007 reservation allocation, permanent places, attendance/check-in, packages/payments, mobile, notifications, rooms, overnight time and exception-migration workflows remain deferred.
