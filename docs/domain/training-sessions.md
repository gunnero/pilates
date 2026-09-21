# Training sessions (GT-006)

TrainingGroup is the durable class configuration; ScheduleRule defines weekly local timing; **TrainingSession is a durable dated occurrence** with a stable UUIDv7. No clients, reservations, permanent spots, attendance or financial relationships are created here.

## Ownership, sources and snapshots

Every session belongs to an organization and group. Tenant-qualified foreign keys protect the group, location, trainer and source rule; the rule must also belong to that group. `recurring` requires a source rule and immutable `occurrence_date`; `manual` requires both to be null. The original occurrence date is independent of `local_date`, which an explicit occurrence edit can move. `(schedule_rule_id, occurrence_date)` remains unique even for cancelled or moved occurrences.

Location/trainer foreign keys and group/location/trainer display names, capacity, local date, UTC start/end instants and IANA timezone are snapshotted. Changing group defaults, organization/group/location names, trainer display names or staff names does not rewrite existing occurrences. An explicit location/trainer override replaces that reference and its display-name snapshot. Group identity/source identity/timezone cannot be edited through session APIs. Relationships remain for current operational validation, not to reconstruct historical names or assignments.

Group capacity/trainer changes affect only subsequently created occurrences. Rerunning generation preserves all existing rows, including untouched ones. This means already-prepared weeks retain their previous assignment; use explicit occurrence editing to change them. Group moves do not move prepared sessions. Visibility follows the session's snapshotted location, not the group's current location. Organization timezone changes are blocked after any schedule rule **or session** exists.

## Resolution and generation

`SessionMaterializer::run` takes the common organization row lock, then reloads tenant/group state. HTTP callers also re-resolve current actor membership and grants after acquiring it. An active organization/group/location and active, currently location-eligible primary trainer are mandatory. Missing trainer, inactive group/location/profile, suspended/revoked staff or removed selected-location eligibility yields an explicit invalid result; nothing is reassigned.

The rolling horizon is **56 inclusive local dates: today through today + 55 days**. Rules are restricted to effective periods intersecting that horizon; matching ISO weekdays within each inclusive rule period are considered. Already-started instants today are skipped. At most 100 intersecting rules are processed per group (at most 800 candidate occurrences); excess is reported as `limit` without partial generation. There is no user-supplied unbounded generation range.

Each wall-clock endpoint is resolved by enumerating timezone transition offsets and round-tripping candidate UTC instants back to the requested local date/time. Exactly one matching instant is required. **Both nonexistent spring-forward and ambiguous fall-back times are skipped and reported**, never shifted or guessed. Manual edits at such times return 422; a replacement requiring such generation rolls back. Normal local 18:00 changes its UTC offset with the date. Both endpoints must be on the same local date, duration 1–240 wall-clock minutes and at most 300 elapsed minutes (allowing a fall-back crossing with unambiguous endpoints). No overnight or 24:00 support. See [PHP transition offsets](https://www.php.net/manual/en/datetimezone.gettransitions.php).

For every candidate, an existing identity—including cancelled/overridden—is preserved. Concrete trainer/group conflicts are reported as skips. Otherwise a session is inserted with current group snapshots. Organization locking serializes all supported writers, and unique/exclusion constraints remain the final protection. Deterministic order is rule internal ID then local date. When competing group definitions conflict with existing concrete exceptions, existing sessions win; operational output identifies skipped work rather than changing an exception.

```bash
php artisan gt:sessions:materialize
php artisan gt:sessions:materialize --organization=ORGANIZATION_UUID
```

The trusted command walks organizations and groups in batches of 100; every group is a separate transaction. Unexpected failure rolls back that group and processing continues. Output uses public organization/group identifiers and numeric considered/created/existing/elapsed/DST/conflict/invalid/limit counts; invalid source reason codes are included. It never prints names, reasons, SQL, credentials or exception messages. Exit 1 means any group failed or needs attention; exit 0 means no unresolved generation failures. A missing/inactive explicitly selected organization fails. Inactive/archived groups are reported rather than silently forgotten. No shared Redis lock, queue or correctness dependency exists. The command is ready for a future scheduler/cron; **no host cron or Laravel schedule has been installed**.

An authorized group manager can also prepare the next eight weeks through the agenda's group selector. Successful HTTP generation returns counts, including skipped work, which the UI displays as needing attention. Operators inspect command reason codes and group operational issues; the UI does not claim every requested class exists when skips occurred.

## Lifecycle and occurrence operations

Only `scheduled` and `cancelled` exist. Past occurrences remain scheduled as historical planning records; this does not claim completion or attendance. Future scheduled occurrences can be edited or cancelled. Cancellation is **terminal**, retains the UUID/source identity, optionally stores a reason up to 300 characters, and releases trainer/group time. Repeated cancellation is an idempotent no-op and cannot change its reason. No delete, restore or completed endpoint exists. After start, edits/cancellation return 409.

Manual sessions require an active visible group plus an explicitly selected active location, eligible trainer, capacity and valid local times. The trainer/location may differ from current group defaults; this is an explicit one-off assignment. The UI offers group defaults. They create no fake rules. Start must be in the future and local date within 365 days. Creation and edits resolve in the organization/saved timezone respectively.

A session PATCH supplies all six editable values: local_date, start_time, end_time, location_id, trainer_id, capacity. It cannot move to another group/source. Actual changes set `is_overridden`; an identical save does not create audit noise or mark an exception. Overrides affect only that occurrence. Source rule/date/UUID stay stable. Assignment checks always repeat under the lock; the selector is not a security boundary.

Current readiness is derived from **session** location/trainer plus group status. Group deactivation/archive or later staff/profile/location invalidity preserves existing scheduled sessions and exposes operational issues; operators explicitly cancel/reassign as appropriate. It stops new recurring generation. A different current group trainer does not invalidate a still-eligible historical session assignment. Unavailable scheduled sessions retain concrete conflict reservations, preventing restoration from introducing collisions. Cancellation remains possible while prerequisites are invalid.

## Weekly schedule reconciliation

GT-005 close/replace/cancel operations now inspect affected sessions by immutable occurrence date under the same organization transaction:

1. Sessions at/before the retained inclusive end stay byte-for-byte unchanged.
2. If any affected session was manually overridden, manually cancelled, or has already started, reject the **entire schedule change with 409**. The error explains that the exception is preserved and a later change date is needed. This is a deliberate conservative workflow, not silent partial reconciliation. Cancellation is terminal; there is no exception-discard override in GT-006.
3. Otherwise mark affected scheduled sessions cancelled with `cancellation_kind=schedule`, retaining their original rule, date, UUID and snapshot. Explicit session-manage/cancel/view permissions and visibility of every affected session are required, including sessions at a group's former location. A rule writer without those capabilities cannot indirectly cancel sessions.
4. For replacement, close the predecessor, create its linked successor and materialize the current 56-day horizon in the **same transaction**. Any invalid/DST/conflict/limit result rolls the entire replacement back. Untouched replacement occurrences follow the successor timing; the old records remain explainable as cancelled history, not duplicate active sessions. Simple close/cancel removes affected future operating times and creates no successor.

Existing sessions with protected exceptions cause rejection even if a proposed replacement might otherwise avoid a clock conflict. Managers may move the change date beyond the last protected occurrence. A more flexible explicitly approved migration of exceptions is deferred. Rule creation by itself does not automatically prepare sessions; use the command or agenda action. Group default edits do not run reconciliation. At present automatic recurring generation cannot create an unmodified occurrence beyond the rolling horizon, so replacement can cover all affected unmodified future occurrences.

## Conflicts, integrity and capacity contract

Native GiST exclusions combine singleton bigint ranges with `tstzrange(starts_at, ends_at, '[)')`, independently for trainer and group, for scheduled sessions only. No extension installation is required. Adjacent intervals are valid; overlapping intervals are rejected across recurring, manual and overridden sources. No location-only exclusion exists: **Location is not Room**. Cross-organization trainer/person identity and travel time are outside scope. See [PostgreSQL ranges and exclusions](https://www.postgresql.org/docs/18/rangetypes.html#RANGETYPES-CONSTRAINT).

Checks enforce source/link presence, statuses/cancellation metadata, capacity 1–100, valid date bounds, positive bounded elapsed duration, and same local date/positive bounded wall duration in the saved timezone. Composite foreign keys prevent foreign group/location/trainer and mismatched rule/group ownership. UUID and recurring identity are unique. Indexes support organization with start time, location/start, trainer/start, group/start and local-date agenda order. Calendar eager-loads the related group/location/trainer/staff/location assignments; it never loads an organization's session history to show one week.

`TrainingSession.capacity` is the authoritative configured limit for this occurrence. GT-007 must allocate reservations transactionally against it and define stricter capacity-reduction rules when reservations exist. No reserved, checked-in, available or waiting counters exist now. Stable session identity is ready for future permanent-place reservations and attendance; cancellation/history will not erase their future parent record. Those relationships are not implemented.

## Access, API and audit

New capabilities: sessions.view, sessions.manage, sessions.cancel. Cancel remains independent from manage. New bundles: Owner 29, Administrator 24, Trainer 8, Staff 4. Existing grants are untouched. Trusted explicit adoption:

```bash
php artisan gt:session-access-enable ORGANIZATION_UUID STAFF_MEMBERSHIP_UUID --reason='GT-006 adoption'
```

The command adds only those three grants to an active all-location owner/administrator and audits the change. It does not create sessions. A TrainerProfile grants no authority. Trainer-role actors additionally see/write only sessions whose snapshotted trainer belongs to their own staff membership, always intersected with current authorized session locations. Staff/admin/owner roles use location scope plus grants. The role restriction applies even if a trainer is explicitly given management. All-location scope never bypasses trainer relevance. Session visibility does not grant client visibility; GT-003 rules are unchanged.

All endpoints are under `/api/v1/organizations/{organization}`:

| Method | Path                          | Contract                                                                                                                               |
| ------ | ----------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| GET    | `/sessions`                   | sessions.view; required from/until inclusive, maximum 56 dates; optional location_id, trainer_id, group_id, status; bounded pagination |
| POST   | `/sessions`                   | sessions.view/manage and groups.view; manual occurrence                                                                                |
| GET    | `/sessions/{session}`         | scoped detail                                                                                                                          |
| PATCH  | `/sessions/{session}`         | sessions.view/manage; complete editable-field payload                                                                                  |
| POST   | `/sessions/{session}/cancel`  | sessions.view/cancel; optional reason                                                                                                  |
| POST   | `/groups/{group}/materialize` | sessions.view/manage and groups.view; empty body, fixed horizon                                                                        |

Calendar sorts local date, UTC start, group internal key, session key. Local-date filtering refers to saved occurrence timezone. Filters are applied inside the scoped query; inaccessible/unknown filter UUIDs yield an empty collection, while inaccessible direct resources/assignments return 404. Explicit representations expose public IDs, snapshots, local/UTC timing, source type, capacity, status, exception marker and current operational issues. No internal rule IDs, staff grants/email, audit internals or member/availability data appear.

Manual creation/override audit contains public assignment references, capacity, date/time/timezone before/after; it therefore evidences trainer/location/time/capacity changes in a single bounded event. Cancellation audit stores only reason presence; the optional reason is detail-visible operational text, never copied to logs or audit. Routine materialization emits one aggregate event per group transaction only when rows were created; idempotent reruns emit none. Reconciliation emits one affected-count/rule event and preserves the schedule's original close/replace audit. Audit failure rolls back mutations. Source invalidity is evidenced by the existing GT-002/004 lifecycle audit; no redundant fan-out events are emitted.

## Limits

Native schema owners can bypass DDL invariants; raw SQL can bypass freshness/history policies enforced by supported writers. Generation is synchronous and bounded per group, with an organization lock favoring correctness over large-tenant throughput. Duplicate manual form submissions have no idempotency key; conflicting clock intervals are nevertheless rejected. No cancellation reversal, completed status, overnight scheduling, room model, travel buffers, timezone migration, exception migration or automatic host scheduling is provided. Already-running reads may complete on their resolved request context; every supported mutation refreshes authority under the lock.

## GT-007 consumers

The reservation contract above is now implemented by [GT-007](reservations.md): session capacity updates lock and check active allocations, increases promote FIFO, materialization synchronizes permanent places, and parent cancellation preserves child history without usable entitlement. Snapshot/source identity and trainer conflict invariants remain unchanged. Earlier GT-006-only no-occupancy statements describe that milestone.

## GT-008 attendance extension

GT-008 now implements durable attendance, staff/trainer roster and today workflows, capacity-safe offline walk-ins, no-show finalization, audited corrections and encrypted expiring QR foundations. Earlier attendance deferrals above describe the prior milestone. See [attendance contracts](../domain/attendance.md) for authorization, API, privacy, concurrency, lifecycle protection and future member-authentication boundaries. Existing grants are preserved; adoption is explicit. No member check-in HTTP endpoint, payments, mobile app or GT-009 is included.
