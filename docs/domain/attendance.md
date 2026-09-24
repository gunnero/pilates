# Attendance and check-in (GT-008)

Reservation allocates a place; AttendanceRecord records what happened. Attendance never replaces capacity allocation. One durable UUIDv7 record exists per member/session, with present, absent or no_show status. Unmarked is derived from an active reservation without attendance. Waiting entries and permanent assignments are not attendance. All supported records reference a reservation; offline walk-ins first obtain one transactionally.

## Operations and time

The centralized AttendancePolicy opens staff check-in 30 minutes before start and closes ordinary marking at session end. Initial absent/no_show marking is allowed only after end. Recording present after end requires attendance.correct and a finite reason: mistake or late_confirmation. Existing resolved records require correction permission to change; identical retries return the existing record without duplicate evidence. Corrections preserve before/after status, method and check-in time in immutable audit. checked_in_at is the time the present state was recorded, including retrospective corrections, not a backdated claim of arrival time. No arbitrary timestamp is accepted.

Trainer/staff can check in reserved offline clients in one action. Walk-in requires attendance.manage plus reservations.view/manage and uses Allocation under the same transaction. The narrowly scoped internal walk-in option permits allocation during the session, while ordinary reservations retain the GT-007 start cutoff. FIFO candidates take precedence; full/queued outcomes cannot produce attendance. Failure rolls back booking, promotion and attendance together. Active member and operational session readiness are checked for manual/member attendance.

Reservation cancellation rejects present attendance. Session edits, manual cancellation and schedule reconciliation reject any existing attendance, protecting early check-in from later rewrites. Historical rows are never deleted by ordinary workflows. Inactive member/trainer/location status prevents new ordinary check-in; finalization still records unresolved historical active reservations of ended scheduled sessions. Cancelled reservations are never finalized. Ending a permanent assignment cannot cancel a present occurrence through the shared allocator.

## No-show finalization

`php artisan gt:attendance:finalize ORGANIZATION_UUID --from=YYYY-MM-DD --until=YYYY-MM-DD` runs one active tenant, at most 31 inclusive local dates and 200 sessions. Too many sessions fail before changes; use a smaller range. Ended scheduled sessions with active reservations and no attendance become no_show. Existing records are untouched. All changes and their system-actor audit events commit together. Safe JSON contains considered-session and created-record counts; failures have nonzero exit status. No scheduler is installed. The command is idempotent and does not emit member PII.

## Authorization and roster

Every staff endpoint requires authentication, active TenantContext, sessions.view and attendance.view. SessionDirectory enforces selected locations and, for Trainer roles, the assigned TrainerProfile tied to current staff membership. TrainerProfile grants nothing. Staff lookup only returns active tenant member public ID/name in an authorized session context; it does not grant the general member directory or notes. Roster is paginated 25 default/100 maximum and includes active reservations plus retained attendance history; waiting entries alone are excluded. No contacts, notes, internal IDs or financial data are serialized. Today's list uses organization timezone and the same scope, including cancelled session labels.

attendance.manage authorizes initial marking and QR issuance. attendance.correct authorizes correction independently. Existing attendance.view grants already existed in GT-001 and remain unchanged; new manage/correct capabilities are never adopted by migration. New Owner/Administrator bundles add both; Trainer adds manage; Staff is unchanged. `gt:attendance-access-enable ORGANIZATION_UUID MEMBERSHIP_UUID --reason=...` explicitly adds the three attendance grants to an active all-location owner/administrator, idempotently auditing actual changes. Permission managers can deliberately delegate to trainers using existing staff management, subject to their own grants. No financial permissions are added.

## QR and future member adapter

Laravel Crypt authenticated encryption produces an opaque versioned token bound to organization and concrete session, issued time and expiry. It contains no member PII and expires after at most 90 seconds, capped by the member window and session end. Server validation rejects malformed, oversized, tampered, wrong-session, future-issued and expired values. Member window is 30 minutes before through 30 minutes after start, additionally bounded by session end. QR issuance requires the window, operational readiness, current scoped staff manage/view authority and the organization/session locks. Responses use no-store/private; no token enters logs, audit, URLs, persisted browser storage or external QR services. [qrcode.react](https://github.com/zpao/qrcode.react) renders SVG locally; the UI refreshes while open and clears expired/failed tokens. Close/reopen retries a refresh error.

There is deliberately no member/QR HTTP endpoint without a complete member authentication/linkage flow. Future adapters must pass their authenticated User into Attendance::selfCheckIn; it derives the sole active StudioMember by user_id and tenant, never a caller-selected member UUID. It then checks active reservation, scheduled/readiness state, time window and optional QR under locks. Existing customer linkage is not a public linking API. Duplicate authenticated scans are idempotent. A QR is a short-lived shared challenge, not authentication or a one-use credential: another eligible authenticated member can use it while valid. Screenshots can be replayed within that window, but cannot create membership, reservation or capacity. GPS, identity proofing, mobile scanning and QR redemption UI are deferred. Encryption uses the existing application key; no new signing secrets or custom cryptography.

## API

Under `/api/v1/organizations/{organization}`:

- GET `attendance/today` (bounded pagination).
- GET `sessions/{session}/attendance` (roster).
- POST `sessions/{session}/attendance` with member_id and status.
- POST `sessions/{session}/attendance/correct` with member_id, status and reason.
- POST `sessions/{session}/attendance/walk-in` with member_id.
- POST `sessions/{session}/attendance/member-lookup` with JSON search (2–100 characters, at most 20 matches).
- POST `sessions/{session}/attendance/qr` with empty body.

Unknown fields are rejected. Actor, tenant, method, timestamps and reservation ownership are server-derived. Foreign resources fail closed. Finite conflict codes return 409 without database detail. Member/QR future contract requires authenticated identity plus optional opaque token in a JSON body, never in a query URL.

## Integrity, locking and statistics

All lifecycle writers lock Organization, re-resolve current authority, then lock TrainingSession. Attendance uses the same protocol as reservation cancellation, walk-in allocation, session cancellation and no-show finalization. Two check-ins produce one record. Check-in versus cancellation yields one winner; both cannot commit an invalid present/cancelled pair. Late authorized present correction versus finalization converges on present regardless of ordering. PostgreSQL enforces public UUID uniqueness, member/session uniqueness, controlled status/method, present/time nullability and time no later than updated_at, actor FK and a four-column tenant/session/member/reservation FK. Cross-table lifecycle and aggregate capacity depend on supported transactional writers; trusted raw SQL can bypass them. Redis has no correctness role.

Indexes cover tenant/session/status and tenant/member/status/created_at for future attendance totals, no-show counts and rates. No counters or analytics UI are persisted. Rates must define their eligible completed-session denominator; cancelled reservations are not no-shows. `gt:attendance:check` reports duplicate, ownership, present/cancelled reservation, cancelled-session and capacity violations without PII. Run alongside `gt:reservations:check`.

## Limits

Organization-wide serialization favors correctness over peak write throughput. Staff check-in/correction policy is currently fixed, with no configuration UI. Absent/no-show are final after end; there is no before-end absent correction or undo-to-unmarked endpoint. Sessions with attendance cannot be cancelled or rescheduled. Future exceptional studio cancellation needs an explicit audited history model. No payments, packages, notifications, GPS, advanced dashboards, member app or GT-009 work is included.

## GT-013 secure member check-in

The previously deferred member HTTP/camera integration is implemented. See [secure check-in contract](secure-check-in.md) for the mandatory authenticated QR endpoint, shared rotating credential, half-open server window, current eligibility checks, mobile scanner, live roster and fallback. Existing GT-008 records, corrections, no-show, walk-in, capacity and audit rules remain authoritative. No schema change or additional attendance domain was added.

## GT-014 studio settings

GT-014 adds bounded member check-in windows and enablement. Staff manual attendance retains its existing independent window and permissions. Current policy is checked under the existing locks; attendance history is never rewritten. See [settings](studio-settings.md).
