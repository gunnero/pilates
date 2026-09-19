# Organization isolation

Organization means the studio-owning business; Location is a physical branch. Users are global identities with separate OrganizationMembership records per organization. OrganizationMembership is not a StudioMember customer profile. This is shared-database/shared-schema isolation with explicit queries, not global Eloquent scopes or PostgreSQL RLS.

1. `auth:sanctum` authenticates a session or bearer token.
2. `ResolveOrganization` clears context and reads the route UUID.
3. `TenantContext::resolve` queries through the user's active `organizationMemberships`, requiring an active organization. Missing, unrelated and nonactive tenants return 404.
4. Gates require `studio.access` and the endpoint permission, intersected with token abilities. An active member without a required permission receives 403.
5. Locations are constrained by organization and all/selected assignments. StudioMember queries require all-location scope and members.view, with organization constraints; selected-location staff are denied until actual attendance relationships exist. Preferred location is never a visibility grant. Staff queries are constrained by organization; `staff.view` intentionally exposes an organization-wide staff directory.
6. Explicit representations omit internal IDs, emails and unauthorized grants. Context is cleared in middleware `finally`, including failures; absent context and unknown permissions deny.

`GET /api/v1/me` lists only this user's active organizations with active membership and stored studio.access. It is identity bootstrap, without an additional bearer ability check; each selected context still requires token abilities. Session selection is a preference, never authority. Lifecycle writes acquire the organization row lock and recheck current authority before executing.

## Required for future tenant aggregates

- Non-null indexed organization ownership; composite `(organization_id,parent_id)` foreign keys when linking tenant-owned parents. `membership_locations` demonstrates this now.
- Query from verified context before resolving a resource UUID; never globally find and then assume authentication scoped it. Apply all location constraints before pagination/counting.
- Derive organization/actor from context; reject unknown write fields and tenant reassignment. Public UUIDs are not authorization.
- Authorize before reading/serializing sensitive fields and before mutation. Do not use frontend filtering as access control.
- Jobs/exports/CLI must establish explicit organization and appropriate authority, clear context and follow the documented lock protocol. Trusted operator recovery is separate from application-user authority.
- Add positive and guessed-UUID, nonactive membership/organization, permissions, selected-scope, stale-context and competing-transaction tests.

Future global administration needs a separate audited capability, never a blanket Gate bypass. See [lifecycle decisions](organization-lifecycle.md) for request snapshot limits, location status handling, migration adoption and last-owner guarantees.

GT-005 TrainingGroup/ScheduleRule reads and writes scope groups through current actor-accessible location IDs, including assigned inactive locations for retained history/repair. Trainer assignment checks active same-tenant profile/staff and current location eligibility again under lock. Group relationship summaries expose operational trainer name/UUID even when its retained assignment becomes unavailable; they expose no private staff data. Schedule conflicts may span actor-inaccessible locations in the same tenant but return only a generic conflict, never another group's identity. Groups establish no StudioMember visibility relationship.

## Session boundary

Session calendars scope by tenant, saved session location, and own assignment for Trainer-role actors before serialization. Composite FKs also bind recurring source rules to the exact tenant/group. Recurring materialization is an internal trusted command or explicitly authorized scoped HTTP action. It never treats a teaching profile as authority. No StudioMember visibility is expanded.
