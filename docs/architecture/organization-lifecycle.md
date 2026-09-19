# Organizations, locations and staff access (GT-002)

**OrganizationMembership is the relationship between an authenticated platform User and an Organization. It is not a StudioMember/customer profile.** This document describes GT-002 staff lifecycle; GT-003 introduces the separate [StudioMember](../domain/studio-members.md) client domain. Invitations, booking, attendance and payments remain absent.

## Organizations

An organization is the primary tenant: internal bigint ID, immutable public UUIDv7, stable unique slug, name, controlled status, locale (`mk`/`en`), valid IANA timezone, `MKD` currency and UTC timestamps. New slugs combine a transliterated name and random suffix; renaming never changes them. Legacy organizations receive `studio-{existing UUID}`. Slugs are display/reference identifiers, not authorization keys.

`active` authorizes access, subject to active staff membership and permissions. `inactive` represents a studio voluntarily disabled through its settings. `suspended` is a trusted operator restriction. Both nonactive states deny all organization routes with 404 and disappear from `/me`. Settings allow active → inactive; the UI warns that recovery needs an operator. Only the audited operator command can restore a nonactive organization or impose suspension. Restoring requires an active owner. There is no destructive organization deletion or public studio registration.

Existing GT-001 organization and access IDs/grants are preserved by the incremental migration. Existing access becomes active Staff with all-location scope; the migration never infers ownership or expands permissions. An operator must establish an owner explicitly with `gt:owner-bootstrap` when none exists. Invalid legacy locale/currency/timezone/permission data causes migration failure for deliberate remediation, never silent data loss. PostgreSQL 18 supplies the migration's UUIDv7 default for legacy memberships.

## Locations

A location belongs to exactly one organization. It has internal ID, public UUIDv7, name, optional address line/city/two-letter uppercase country code, active/inactive status and UTC timestamps. Country codes are syntactically checked, not validated against a country catalog. Locations inherit the organization's timezone; separate scheduling timezones have no current justification.

Active ↔ inactive is reversible. Ordinary `locations.view` callers see active locations only. `locations.manage` also permits inspecting/reactivating inactive locations within the caller's scope. Creating a location additionally requires all-location scope, preventing a selected-location manager from manufacturing access. Locations are retained; there is no DELETE endpoint.

## Staff lifecycle and authority

Each user has at most one membership per organization. A membership has UUIDv7, role, finite explicit permission array, controlled status and location scope. Active ↔ suspended and active/suspended → revoked are supported. Revoked is terminal and records are retained indefinitely at this stage; a retention/legal-deletion policy is needed before production. Suspended/revoked members cannot resolve context. There is no pending state, invitation, default password, user creation endpoint, or membership creation endpoint.

Roles provide explicit initial bundles; gates check stored permissions, never a business role hierarchy. Changing role replaces grants with its bundle unless the request includes a full explicit permission array. Editing grants alone preserves the role. New enum permissions are never automatically granted to owners.

| Role            | Default grants                                                                                                                                                                 |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Owner           | `studio.access`, organization view/manage, location view/manage, staff view/manage, permission view/manage, `attendance.view`, and the four independent financial capabilities |
| Administrator   | `studio.access`, organization view/manage, location view/manage, staff view/manage, `permissions.view`, `attendance.view`                                                      |
| Trainer / Staff | `studio.access`, `organizations.view`, `locations.view`, `attendance.view`                                                                                                     |

The financial capabilities are `payments.view`, `payments.record`, `payments.correct`, `reports.view`. They expose no payment/reporting implementation. Trainer and Administrator default bundles contain none. A permission manager can grant each independently.

All staff changes require `staff.manage`. Role, permission and location-scope changes additionally require `permissions.manage`. Both the target's existing grants and proposed grants must be subsets of the actor's effective authority, including bearer-token ability restrictions. Consequently an administrator cannot suspend a more privileged member simply because they have `staff.manage`. Owner target changes and owner promotions require an actor who is also an owner: this centralized custody rule is additional restriction, never a permission bypass.

Owners must keep all-location scope and the explicit control permissions `studio.access`, organization view/manage, staff view/manage, permission view/manage. Database checks also enforce this minimum. Optional owner grants can be reduced. Promote another active member before removing the last active owner; there is no special bypassing transfer endpoint.

## Multi-location access

`location_scope=all` grants access to all current/future organization locations, subject to operation permissions and location status. `selected` uses `membership_locations`; composite foreign keys require the membership and location to belong to the same organization. Reads, list counts and writes use the same context-constrained location query. Missing, foreign and unassigned location UUIDs return 404.

Selected membership changes require at least one location, with at most 20 distinct public UUIDs per assignment update. Switching to all clears assignments. A selected-location grantor can only manage selected-location targets whose existing and proposed assignments fit their own scope. Owners always have all-location scope. These collection invariants are enforced by the transactional service; raw trusted DB writes are not a supported provisioning protocol. A malformed selected membership with no assignments fails closed to an empty location set.

**`staff.view` deliberately grants an organization-wide staff directory**, including name, user/membership public IDs, role, status and scope. Selected-location access does not turn the staff directory into separate branch directories. Stored grants appear only with `permissions.view`; assignment IDs appear only with `permissions.manage`, intersected with the viewer's own location scope. No staff email is returned. Grant staff.view only to someone who should see that directory.

## Context and switching

Every organization route includes `/api/v1/organizations/{public UUID}`. Authentication, active organization, active membership, `studio.access` and the operation's permission are checked afresh. The context response includes the caller's effective permissions and membership/scope for UI affordances; subsequent mutations never trust those client values.

`POST .../switch` revalidates access under the organization lock, writes audit evidence and, for a stateful web request, stores `active_organization_id` as a session preference. `/me` returns it only while still accessible. Explicit route context remains authoritative, so multiple tabs and future bearer-token/mobile requests do not depend on mutable global server context. Token switches are audited but do not change token authority. The UI shows the switcher only for multiple active accessible organizations, waits for server acceptance, and unmounts old tenant resource state.

## Transaction protocol and last-owner invariant

Every supported lifecycle mutation locks the organization row inside one PostgreSQL transaction, then re-resolves actor membership/status/permissions before writing. Membership transitions, role/grant edits, assignment synchronization and audit inserts are atomic. Removal of an active owner counts remaining active owners only after this common lock. Two concurrent owner removals cannot both commit. Transaction retries are bounded to three.

The count invariant depends on all supported writers taking that same lock; it is not a database aggregate CHECK, and trusted direct SQL can bypass it. Operator status/bootstrap commands use the lock too. Already-running read requests may finish with their request snapshot; subsequent requests re-resolve authority, and queued mutations recheck after obtaining the lock. There are no jobs yet; future jobs must establish and clear explicit context and use this protocol.

See [audit evidence](../security/audit.md), [API contract](api.md), [security review](../security/gt-002-review.md) and [verification](../development/gt-002-verification.md).

## GT-003 permission extension

The current explicit Owner/Administrator bundles add six client capabilities. Existing GT-002 stored grants are preserved; an audited operator adoption command enables a legacy manager deliberately. Trainer/Staff defaults remain unchanged. The catalog now contains 20 permissions; the delegation, token, owner-custody and minimum-control rules above remain intact. See [client permissions](../domain/studio-members.md).

## GT-004 operational extension

TrainerProfile is now an optional one-to-one teaching profile for any active membership, independent of role. New Owner/Administrator bundles add trainers.view/manage; Trainer adds trainers.view; Staff is unchanged. The current catalog contains 22 capabilities (Owner 22, Administrator 17, Trainer 5, Staff 4). Existing stored grants stay unchanged until explicit audited adoption. Operational profile changes do not edit staff authority. Staff responses include trainer UUID/status only for all-location trainers.view callers. See [trainer model, lifecycle and location policy](../domain/trainer-profiles.md).

## GT-005 groups and weekly definitions

Group/schedule writers join the same organization lock protocol. New permission defaults add groups.view/manage and schedule.view/manage to Owner/Administrator, both view capabilities to Trainer; stored grants require deliberate adoption. Group managers with selected scope are constrained to accessible locations. Trainer/staff/location deactivation or assignment removal remains supported and audited at its source; groups retain references and dynamically expose operational invalidity. Organization timezone changes are rejected once any schedule rule exists, preserving historical local-time semantics. See [groups and schedules](../domain/training-groups.md).
