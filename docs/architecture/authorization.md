# Permission-based authorization

Server-side Gates are authoritative. `Permission` defines 26 finite capabilities: studio access, organization view/manage, location view/manage, staff view/manage, permission view/manage, attendance view, four independent financial capabilities, six client capabilities trainer view/manage, group view/manage and schedule view/manage. Financial/attendance capability names do not implement those future modules.

Explicit grants live on OrganizationMembership. Active organization and membership, matching actor, current context, stored grant and token ability are all required. Unknown permission, absent context or missing grant denies. There is no wildcard Owner or Super Admin bypass. Changes affect subsequent requests; lifecycle mutations also refresh authority under the common organization lock.

Owner, Administrator, Trainer and Staff are explicit default bundles, not business authorization hierarchies. Role changes apply the chosen bundle unless a full custom grant array is supplied. See [the bundle table and delegation rules](organization-lifecycle.md). `permissions.view` controls staff grant serialization; `permissions.manage` plus `staff.manage` controls role/grant/location assignment editing. Existing and proposed target grants must fit the actor's effective authority. Owner custody and minimum control grants are additional invariants.

A trainer can independently receive payments.view or payments.record without payments.correct or reports.view. No financial data is queried or serialized in GT-002. Future financial endpoints must authorize before reading/serialization. Client management uses members.view/create/update/status.manage/notes.view/notes.manage; all-location scope is required until real session/group relationships exist. New Owner/Administrator bundles include all six; existing grants remain unchanged until explicit audited adoption. Customer self-access remains a separate future design. See [StudioMember decisions](../domain/studio-members.md).

TrainerProfile is operational data, never an authorization principal. trainers.view exposes the scoped staff-only trainer directory; trainers.manage additionally requires all-location scope for global profile edits. New Owner/Administrator defaults add both, Trainer adds view, Staff stays unchanged. Existing stored grants require explicit adoption. No client or financial permission is implied. See [trainer authorization and rollout](../domain/trainer-profiles.md).

GT-005 grants group and schedule capabilities independently. Selected-location managers can manage only accessible groups. Operational readiness grants no authority or client access; only schedule.view exposes timing summaries/history. Existing grants are preserved. New Owner/Administrator bundles include all four capabilities, Trainer receives both view capabilities, Staff is unchanged. See [group authorization/adoption](../domain/training-groups.md).

## GT-006 additions

The current catalog contains 29 capabilities. sessions.view/manage/cancel are independent; new Owner/Administrator/Trainer/Staff bundles contain 29/24/8/4 grants. Existing arrays are preserved. Trainer-role session access always intersects own TrainerProfile assignment with current location scope, including on overrides. A session at a former group location is authorized by its saved location. Recurring reconciliation requires authority over every affected session and sessions.view/manage/cancel when materialized rows are affected.

## GT-007 reservation extension

[Reservation domain and API](../domain/reservations.md) defines implemented staff/offline bookings, permanent-place assignment, synchronous FIFO waiting promotion, capacity guards, member lookup and relationship views. Existing grants remain unchanged; adoption is explicit. Earlier future-booking statements in this document describe the historical baseline; GT-007 uses active-record uniqueness and organization/session locks for retry safety, without a global idempotency-key service. Attendance, payment and mobile self-service remain deferred.

## GT-008 attendance extension

GT-008 now implements durable attendance, staff/trainer roster and today workflows, capacity-safe offline walk-ins, no-show finalization, audited corrections and encrypted expiring QR foundations. Earlier attendance deferrals above describe the prior milestone. See [attendance contracts](../domain/attendance.md) for authorization, API, privacy, concurrency, lifecycle protection and future member-authentication boundaries. Existing grants are preserved; adoption is explicit. No member check-in HTTP endpoint, payments, mobile app or GT-009 is included.

## GT-009 financial authority

Financial grants are explicit organization-wide powers, even for selected-location staff. memberships.view provides redacted commercial-period metadata; payments.view gates all money/history; record/correct remain separate and also require view. reports.view plus payments.view gates metrics. No trainer-profile, attendance or location-scope inference grants finance. Existing grants are preserved; adoption is opt-in. See [full permission matrix](../domain/cash-payments.md#authority-and-privacy).

## GT-010 notifications

GT-010 notification endpoints authenticate then derive recipient ownership. Staff continue through TenantContext, studio.access and session location/trainer scope; member inbox identity comes only from the existing active StudioMember.user_id link. Token abilities notifications.view/read restrict access further and add no stored staff grants. No caller-selected recipient or financial payload is accepted. See [recipient contract](../domain/notifications.md).

## GT-011 member self-service

GT-011 member authority is User → active linked StudioMember → active Organization, resolved by MemberContext for every request and again under the organization lock for mutations. Member bearer abilities intersect ownership; they confer no staff powers. Staff invitations require all-location members.view, members.update and members.status.manage. Existing grants remain unchanged. See [member authorization](../domain/member-self-service.md).
