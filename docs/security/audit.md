# Persistent lifecycle audit evidence

GT-002 implements `audit_events`: internal ID, public UUIDv7, organization FK, nullable actor user FK, fixed action key, controlled subject type, public subject UUID, UTC occurred_at and JSON object metadata. Runtime callers use `Audit::record` inside the lifecycle transaction. Failure to insert evidence rolls back the sensitive mutation; there is no asynchronous Redis audit path.

Metadata is explicitly constructed at each callsite, never copied from request bodies. Before/after fields contain only changed settings, location details, role, permission, status or location assignment public IDs. Operator actions use actor=null, source=operator and bounded reason where applicable. No passwords, tokens, session IDs, emails or internal tenant/subject IDs enter metadata. Organization/location names and addresses are intentional business audit data.

The database caps serialized JSON at **8192 bytes**. This accommodates both old and new maximum-length multilingual location fields. Input lengths, finite grant arrays and at most 20 location UUIDs bound the allowlisted structures and nesting. There is no public generic metadata submission. GT-001 proposed request correlation IDs; GT-002 retains event identity plus actor/subject/time but defers cross-event request correlation and diagnostic tracing. One operation can create multiple granular events (for example status and settings); consumers must not assume one row per request.

Implemented actions:

- organization.created, organization.settings_changed, organization.status_changed, organization.switched;
- location.created, location.updated, location.status_changed;
- membership.created, membership.owner_bootstrapped, membership.suspended, membership.reactivated, membership.revoked;
- staff.role_changed, staff.permissions_changed, staff.location_access_changed.

No-op field changes produce no change event. Accepted switch requests are always audited. Switch records channel=web/token; preference is not an authorization source. Newly provisioned or bootstrapped ownership records explicit operator evidence; the data migration itself preserves grants without inventing historical actors/events.

PostgreSQL triggers reject UPDATE, DELETE and TRUNCATE. Ordinary application users have no audit CRUD endpoint; no audit viewer is implemented yet. Triggers protect DML even with local schema-owner credentials, but **a trusted schema owner can disable/drop them or roll back the schema**. This is append-only application evidence, not protection against a hostile DBA. Before production separate runtime INSERT/scoped SELECT from migration/retention DDL authority; review off-host immutable export, retention and lawful deletion. Audit viewing must have an explicit permission and tenant scope when introduced.

Future payment corrections, attendance and reservation changes must add their own bounded transactionally persisted events. Their business modules remain out of scope. See [GT-002 security review](gt-002-review.md) and [verification](../development/gt-002-verification.md).

## GT-003 customer evidence

Added studio_member subject and member.created/profile_changed/status_changed/digital_access_changed/preferred_location_changed/notes_changed actions using the same transaction/append-only machinery. These events never copy name, phone/email or note values. Profile evidence records changed field keys plus bounded before/after contact-presence, locale and lifecycle/preference values. Notes record only changed=true. Therefore audit cannot reconstruct historical profile PII. Existing operator permission-adoption evidence contains staff grants/reason only. See [privacy review](gt-003-review.md).

## GT-004 trainer evidence

Subject trainer_profile uses the retained public profile UUID. trainer.enabled records membership UUID, initial status and supplied operational field names. trainer.profile_updated records changed field names only; trainer.activated/deactivated record before/after status. Display names and bios are never copied into evidence. No-op updates/status requests emit no new event. All events commit with the profile mutation under the organization lock. The append-only trigger protections and 8192-byte metadata cap apply unchanged. Trusted trainer-access adoption emits staff.permissions_changed with system actor, before/after grants and bounded operator reason.
