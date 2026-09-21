# Reservations, permanent places and waiting lists (GT-007)

Reservation is a customer's durable place in a concrete TrainingSession, not attendance. StudioMember needs no User, email, phone or smartphone. Staff use session detail to search a name, reserve or join waiting, and release a place. Attendance, check-in, packages, money, notifications and mobile authentication remain deferred.

## Allocation and lifecycle

Active reservations consume one place. Cancellation retains identity and provenance and permits a later explicit new reservation. A partial unique PostgreSQL index prevents duplicate active member/session records. Sources are staff, permanent_spot, waiting_list and the reserved future member source; client requests cannot set provenance or actor. No member self-service route exists. A future authenticated member adapter must resolve its own StudioMember and call the same allocation insertion/promotion engine, never accept an arbitrary customer identity as self.

All supported writers first lock Organization and revalidate staff authority in the existing transaction protocol, with three bounded database concurrency retries. Session operations then lock TrainingSession. Multi-session operations visit session IDs in ascending order; reservation and FIFO entry locks follow the session. Permanent assignments are protected by the organization lock. No Redis dependency exists. Active counts are queried under the lock before insertion, and capacity changes use the same serialization. Capacity, exclusivity and group aggregate admission are service-enforced PostgreSQL transactions, not aggregate CHECK constraints; trusted direct SQL can bypass aggregate rules. `gt:reservations:check` reports integrity counts and exits nonzero for violations.

Only active customers can receive new reservations. A session must be scheduled, future and operationally ready. Booking and cancellation cutoff is the start instant, without staff override. Later member inactivation preserves history. Cancellation of a parent session retains all child records and statuses, but no child is usable for that cancelled session and promotion stops. Counts retain active record counts for historical explanation; availability is arithmetic, not a promise of eligibility. Existing reservations survive ordinary session overrides and visibility follows the current session location/trainer.

Session capacity reductions below active count fail with 409. Increases synchronously promote waiting customers. Waiting entries do not consume capacity. Group default capacity remains distinct from concrete snapshots.

## FIFO

Waiting entries are durable waiting, promoted, cancelled or ineligible records. Partial uniqueness protects one waiting member/session. Order is database-recorded joined_at then bigint ID, never a client position. There is no SKIP LOCKED or queue reordering. Promotion runs synchronously before a new allocation, after reservation cancellation and after capacity edits. It fills only available capacity, rechecks members and marks inactive/archived customers ineligible without blocking the queue. Promotion links its resulting reservation with a tenant/member/session-qualified FK; later cancellation of that reservation does not erase promotion evidence. Explicit waiting cancellation retains history. A later join creates a new entry at the end. Active queue length is bounded to 1,000 per session; APIs paginate.

## Permanent places

PermanentSpot is an active/inactive group/customer assignment with inclusive local effective dates. A native GiST range exclusion prevents overlapping active assignments for the same member/group. Admission uses an event sweep of effective date boundaries, considering today and future demand. Expired/inactive assignments do not constrain new group capacity. Group capacity reduction uses the same admission check under the organization lock. No permanent assignment alone consumes session capacity.

Creation/update synchronizes eligible recurring occurrences already materialized in the rolling 56-date horizon, bounded to 800 sessions per group. Matching uses immutable recurring occurrence_date, so moving one occurrence does not erase its assignment. Manual sessions do not automatically inherit permanent places. Existing active manual reservations satisfy demand without rewriting source. FIFO has precedence over new permanent allocations; insufficient concrete capacity is a reported conflict, never overbooking or eviction. The materialization command synchronizes existing and newly generated sessions and returns failure for unresolved permanent conflicts/ineligible members. Reruns discover durable unfilled demand. No host cron is installed; schedule the existing materialization command operationally to keep advancing the horizon.

A deliberate reservation cancellation writes a permanent_spot_releases row for the member/session, including cancellation of a manual reservation that could later collide with a permanent assignment. Synchronization honors that row across assignment changes and never recreates the released occurrence. An explicit manual rebooking remains possible. Assignment deactivation or shortening cancels only attributable future permanent reservations outside the new period, preserves past/manual records, and promotes waiting customers. Such reconciliation does not create deliberate-release suppression, so later explicit reactivation can fill eligible demand. Staff must see every affected future session; hidden old-location occurrences block both assignment changes and HTTP materialization.

## Permissions and privacy

Seven capabilities: reservations.view/manage/cancel, waiting_list.view/manage, permanent_spots.view/manage. New Owner/Administrator bundles contain all seven; Trainer defaults add reservation view only. Existing grants remain unchanged. `gt:reservation-access-enable ORGANIZATION_UUID MEMBERSHIP_UUID --reason=...` explicitly adopts the seven permissions for an active all-location owner/administrator and audits only actual changes. Financial grants are unaffected.

Session reads and writes also require sessions.view and current session scope; Trainer roles intersect their own assigned sessions with location access. Permanent lists require groups.view and group location scope. Reservation-scoped POST name lookup requires reservation management and a visible session, returns at most 20 active members with only public ID/display name, and requires a 2–100 character literal prefix. A corresponding group-context lookup supports permanent management. Neither grants the general directory or notes access. Member relationship endpoints filter by visible sessions/groups independently; the general GT-003 profile/directory remains all-location only. Aggregate occupancy contains no member identity.

Audit records contain public subjects/session identifiers, lifecycle/effective dates and bounded counts, no contact details or names. Staff actions and FIFO promotions are audited transactionally. Routine permanent synchronization relies on aggregate materialization output, not per-member logs. Errors use finite codes, never SQL details. Tenant-qualified FKs protect all session/member/group parents; statuses, sources, effective periods and partial uniqueness are database enforced. Capacity aggregation and cross-table waiting/reservation exclusivity require the common supported writer protocol.

## API

All routes are under `/api/v1/organizations/{organization}` and use existing authentication, CSRF, throttling and tenant context.

- GET/POST `sessions/{session}/reservations`; POST accepts member_id and optional allow_waiting. Outcomes: reserved, already_reserved, waitlisted, already_waiting. Joining waiting requires explicit waiting management/view and reservation manage/view because available capacity allocates immediately.
- POST `sessions/{session}/member-lookup` with search in the JSON body.
- GET `reservations/{reservation}`; POST `reservations/{reservation}/cancel` with empty body.
- GET/POST `sessions/{session}/waiting-list`; POST `waiting-list/{entry}/cancel`.
- GET/POST `groups/{group}/permanent-spots`; GET/PATCH `permanent-spots/{spot}`. PATCH provides effective_from, nullable effective_until and active/inactive status; member/group are immutable.
- POST `groups/{group}/member-lookup`.
- GET `members/{member}/reservations` (upcoming scheduled active) and `members/{member}/permanent-spots` within scope.

Lists follow data/meta pagination, 25 default, 100 maximum, page 1–10000. Lookup returns at most 20 minimal results. Unknown write fields are rejected. Business conflict codes include session_cancelled, session_started, session_unavailable, member_ineligible, capacity_conflict, permanent_spot_conflict and limit. Duplicate active requests are naturally idempotent through durable uniqueness and locks; no global idempotency-key framework is added. Explicit rebooking after cancellation is a new action.

## Deferred and operational limits

No attendance/no-show/check-in, QR, financial/package validation, notifications, mobile authentication, priority queue, manual reorder, rooms or configurable cutoffs. Promotion is visible on refresh; no push delivery exists. Group-wide organization locking favors correctness over high tenant write throughput. Materialization/synchronization remains command-driven and bounded; an operator must schedule the command, without manually renewing assignments. Conflict output requires staff action; a permanent place is not permission to exceed an overridden session capacity.
