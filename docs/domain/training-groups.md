# Training groups and weekly schedules (GT-005)

TrainingGroup is a durable studio-defined class configuration. ScheduleRule is a weekly wall-clock definition with an effective date period. A future TrainingSession will be a concrete dated occurrence. GT-005 creates no sessions, member assignments, permanent spots, reservations, waiting lists, attendance, packages or financial records.

## Groups, capacity and relationships

Every group belongs to one organization and one location in that organization. PostgreSQL composite foreign keys also enforce same-tenant primary TrainerProfile ownership. Public identifiers are UUIDv7; internal bigint keys never appear in API responses. Name is required (160 characters), description optional (2000), capacity an integer **1–100**. One hundred accommodates larger group-fitness classes while remaining a realistic ceiling for this initial studio product; a larger-event product needs an explicit reconsideration, not an unconstrained integer.

Capacity is configured intent, never occupancy. Lowering it is allowed and audited because there are no reservations. A future reservation/session domain must define a deliberate reduction workflow when existing reservations exceed the proposed limit; it must not silently drop people or infer that the new value repairs overbooking. No such behavior is invented here.

Exactly one nullable primary trainer is supported. Any eligible TrainerProfile can be assigned, independently of staff role. Null allows setup and a weekly definition to be prepared before staffing is final. Operational eligibility remains false until a trainer is assigned and available. Additional trainers, rooms, equipment, pricing and membership requirements are deferred.

Creation/new assignment requires an active location, active TrainerProfile, active underlying staff membership and current staff authorization at that location. The group manager must themselves have location access. A profile alone never supplies permissions. Group APIs use operational trainer name/UUID only, without staff membership ID, role, email, grants or bio.

Same-tenant moves are allowed while the group is not archived. Both source and destination must be accessible to the actor, the destination active, and the resulting trainer assignment eligible. Moving or changing trainer when any schedule history exists additionally requires schedule.manage. Active groups recheck all retained future trainer conflicts under the organization lock. Location/trainer/capacity changes have distinct audit events. There are no dated sessions to migrate; future sessions must snapshot their own location, trainer, capacity and rule/version reference instead of reinterpreting past occurrences through mutable group defaults.

## Lifecycle and operational state

New groups are active; active ↔ inactive and active/inactive → archived are supported. Archived → inactive is the explicit restore path; archived → active is rejected. Archived definitions are read-only until restored. There is no DELETE endpoint. Inactive and archived groups retain rules but are never eligible for future generation.

Status and operational readiness are separate. Every group response contains eligible_for_scheduling and operational_issues. The latter can contain group_inactive, group_archived, location_inactive, trainer_unassigned or trainer_unavailable. This is a fresh projection of authoritative group/location/trainer/staff/assignment state, not a stored flag. An empty issue list means the group prerequisites are satisfied; it does not claim a dated session or even a weekly rule exists.

Trainer deactivation, staff suspension/revocation, location-assignment removal and location deactivation remain allowed through their existing audited paths. They preserve the group and historical trainer reference, but immediately surface unavailability on subsequent group reads. No silent reassignment, automatic group deletion or duplicated trainer-location table exists. Existing source audit events identify the cause/time; derived invalidity has no redundant fan-out audit event or cached state to drift. Restoration of source availability can restore readiness. Active-group trainer conflicts are reserved even while the trainer/location is unavailable, so restoration cannot introduce a conflict that was admitted during downtime.

New/replacement rules require valid current location/trainer assignment (or deliberately null trainer). Closing/canceling is possible while prerequisites are unavailable, allowing cleanup. Re-activating a group checks location, any assigned trainer and future trainer conflicts; if retained rules exist, schedule.manage is also required. Future session writes must take the shared organization lock and recheck all these conditions. A client-rendered readiness flag is not authorization.

## Weekly time and effective dates

Rules store ISO weekday **1 Monday … 7 Sunday**, integer minutes after local midnight, effective_from/effective_until as PostgreSQL DATE, optional canceled_at, and optional predecessor reference. API times are strict HH:mm. Times stay on the same local calendar day; end must be later than start, maximum duration **240 minutes**. Overnight sessions and 24:00 notation are unsupported.

Both effective dates are **inclusive**; null end is open-ended. Accepted date bounds are 2000-01-01 through 2100-12-31. New definitions cannot start before today in the organization's timezone. Effective dates do not need to be that rule's weekday. A short period may contain no matching weekday; the rule remains a definition, not a generated occurrence. Ordering is weekday, start minute, effective start, then internal ID for stable pagination.

All locations inherit the organization timezone. GT-005 introduces no location-specific timezone. The API/UI show the organization IANA zone (normally Europe/Skopje), and **18:00 remains local 18:00 across DST**, not a permanent UTC clock time. To prevent silently reinterpreting existing local history, organization timezone edits return 409 once any rule exists, including canceled/history rows. A future explicit timezone-version migration can relax this; the current settings endpoint cannot.

No DST conversion or occurrence materialization happens now. The future occurrence resolver must evaluate each actual local date with timezone rules. Nonexistent spring-forward times and ambiguous fall-back times must be surfaced for explicit resolution; it must not silently shift a class or generate it twice. UTC instants belong on future occurrences, never in place of the weekly local clock definition. Cross-organization trainer conflicts and travel-time buffers are outside this tenant-level model.

## History-safe schedule changes

There is no generic PATCH that rewrites weekday, time or start date. Use:

- **Add weekly time:** a new period from today or later; historical dates are rejected.
- **Change from a future date:** atomically close the predecessor through the day before the chosen date and create a successor with its new weekday/time/period. The date must be later than today and the predecessor start, and inside its existing period. Explicitly supplied/new null end defines the successor end; an omitted end is open-ended. One predecessor has at most one successor. Edit the successor next.
- **Set last effective date:** only shorten a non-canceled period, not before today or its start. Extending an already closed period is rejected; add a new future definition instead.
- **Cancel future weekly time:** only when effective_from is strictly after local today. The row is retained with cancellation evidence, excluded from overlaps and normal summaries. Already-started rules must be closed, not canceled.

A failed successor conflict/audit insert rolls back the predecessor closure too. Past rules stay addressable and ordered in the schedule history endpoint. Group changes do not delete or rewrite schedule timing history.

## Conflicts and concurrency

Clock intervals are half-open: 18:00–19:00 and 19:00–20:00 are adjacent, not overlapping. Effective dates are inclusive: sharing an end/start date counts as date-period overlap. Conflicts conservatively compare same weekday, overlapping local minute interval and overlapping effective date ranges; they can reject very short overlapping periods even when the shared dates contain no occurrence of that weekday. This deliberate conservative definition avoids claiming exact occurrence resolution before it exists.

Same-group overlaps/duplicates are prohibited for every retained non-canceled rule, regardless of group status. A native PostgreSQL GiST exclusion constraint combines a singleton bigint range for group ownership, weekly minute range and date range. It needs no btree_gist extension or privileged installation. It remains a final invariant under direct competing inserts. See [PostgreSQL range/exclusion documentation](https://www.postgresql.org/docs/18/rangetypes.html#RANGETYPES-CONSTRAINT).

Trainer conflicts span active groups in the same tenant sharing a primary TrainerProfile, across all their locations. They are checked on rule create/replace, trainer/location reassignment and group activation. Every supported writer takes the same organization row lock before re-reading authority, assignment and conflicting rules. This is not an unlocked pre-insert SELECT. Competing-process tests prove only one conflicting write commits. Cross-group trainer consistency is enforced by this common supported-writer protocol, not a cross-table database exclusion or trigger; trusted raw SQL can bypass that application invariant.

Inactive/archived groups do not reserve trainer time across groups; activation revalidates. Active groups with unavailable trainers/locations still reserve their definitions to avoid collisions after restoration. No conflict is inferred from location alone: **Location is not Room** and may host simultaneous groups with different/unassigned trainers. No travel-time, duration-over-midnight or cross-timezone conflict engine is implied.

## Permissions, adoption and visibility

The finite catalog now contains 26 capabilities. groups.view is required for scoped directory/detail; groups.manage for create/profile/status. schedule.view independently controls history and five-rule summaries; schedule.manage plus schedule.view/groups.view controls rule mutations. Trainer filtering uses trainers.view. The UI's location and eligible trainer pickers use locations.view/trainers.view; backend assignment authorization is always repeated under the lock. A manager may enter an unassigned group without trainer-view access. Selected-location staff can manage within their assigned locations when explicitly permitted; no all-location requirement is imposed on groups.

New Owner/Administrator bundles add all four permissions; Trainer gets groups.view/schedule.view; Staff stays unchanged. Counts: Owner 26, Administrator 21, Trainer 7, Staff 4. Existing persisted grants remain byte-for-byte unchanged. Deliberately adopt an existing active all-location Owner/Administrator with:

```bash
php artisan gt:group-access-enable ORGANIZATION_UUID STAFF_MEMBERSHIP_UUID --reason='GT-005 adoption'
```

The trusted command adds only those four capabilities, writes system-actor permission audit evidence and creates no groups/rules. Other delegation uses GT-002's bounded permission editor. Group/schedule capabilities never grant financial access, client access or staff authority. There are no client-group relationships: GT-003 selected-location client denial remains intact.

Directory/history queries scope by tenant and actor-authorized locations before pagination or serialization. Foreign/unassigned group/rule/location identifiers return 404. Group data remains inspectable at an assigned inactive location so invalidity can be repaired. A currently assigned trainer's minimal operational name/UUID remains visible even if that trainer is now unavailable there; this is historical group context, not access to their private staff profile. A trainer conflict outside the actor's location scope returns a generic 409 without the other group's identity or timing.

## Audit and UI

Events: group.created/profile_updated/capacity_changed/location_changed/trainer_changed/status_changed; schedule.rule_created/closed/replaced/canceled. Group profile evidence uses changed-field names, not name/description values; scalar capacity/status and public relationship references are recorded separately. Schedule evidence includes bounded local timing/period/predecessor references. No full request body, description, client information or internal ID is recorded. Append-only audit triggers are unchanged and all mutations/evidence are atomic.

Групи provides body-based search, status/location/trainer filters, pagination, configured capacity, operational issues and up to five retained current/future rule summaries plus total. Распоред provides the same scoped group overview initially filtered to active groups. Detail exposes full paginated rule history and add/close/cancel/replace actions. Macedonian is default; English fallback. No fake occupancy, member list, attendance or payments are shown. Schedule summaries can include several future periods, with dates displayed to distinguish them.

See [API](../architecture/api.md), [review](../security/gt-005-review.md) and [verification](../development/gt-005-verification.md).
