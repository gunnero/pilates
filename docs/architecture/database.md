# PostgreSQL conventions

PostgreSQL is the source of truth for identities, access grants, sessions, tokens and future business records. Local native cluster uses port 55432 because shared port 5432 has no development role. `gt_dev` and `gt_test` have different random credentials and non-superuser roles with no CREATEDB/CREATEROLE. PUBLIC database CONNECT is revoked. Test runners reject cached configuration, remote hosts, URLs, and any database/role other than gt_test. CI's disposable PostgreSQL container is infrastructure for CI only, not a local Docker requirement.

- Tables/columns use plural snake_case and singular foreign keys (`organizations`, `organization_id`). Internal primary keys are bigint identity-style Laravel IDs.
- Externally addressable user/organization/location/staff-membership records get application-generated UUIDv7 `public_id`, unique and immutable by convention. Fillable lists exclude it. APIs call this field `id`; internal IDs stay private. UUIDv7 avoids sequential enumeration but reveals approximate creation time and is not authorization.
- Store instants in `timestamptz`, with application and connection timezone UTC. Laravel serializes ISO 8601 UTC timestamps. Framework queue bookkeeping uses documented Unix epoch integer columns; session last_activity is also an epoch integer. Business local dates/recurrence wall times require explicit date/time types plus an IANA zone later.
- Foreign keys and indexes are explicit. Organization memberships retain unique `(organization_id,user_id)` and user lookup index. Deletion of referenced identities/organizations is restricted. Framework session user_id is intentionally nullable indexed metadata, not domain ownership.
- Future tenant child references require matching organization via composite constraints; see tenancy.md. Add indexes based on tenant-first access patterns and query evidence.
- No automatic soft deletes. Introduce them per aggregate only with clear retention, uniqueness and authorization semantics. Financial history must never use soft deletion as a correction mechanism.
- Future money is integer minor units (bigint) plus ISO 4217 currency. APIs use integer decimal strings for amounts, never floating point; MKD has two fractional digits (deni). Formatting is locale aware. Currency conversions are out of scope.
- Schema changes are migrations. Never run migrate:fresh against development/production data; only guarded test setup does so. No SQLite substitute for PostgreSQL integration tests.

Local roles own their schemas to permit migrations. Production must separate schema/migration administration from runtime DML privileges, enforce transport security and backups, and review recovery before launch. No production database is provisioned here.

## GT-002 integrity

The incremental migration renames organization_user to organization_memberships while retaining IDs and grants. It adds controlled role/status/scope, UUIDv7, locations, composite membership_locations ownership, and append-only audit_events. Unique organization slugs, finite permissions, owner minimum grants, locale/currency and IANA timezone are database-enforced. API validation provides localized input errors first. PostgreSQL 18 uuidv7() supplies IDs for legacy access rows.

All supported lifecycle writers serialize on the organization row; the last-owner aggregate invariant and nonempty selected-location sets are service-enforced, not database aggregate constraints. Audit UPDATE/DELETE/TRUNCATE triggers reject ordinary DML, including by the development role; schema owners can still disable triggers or drop objects. See [lifecycle](organization-lifecycle.md) and [audit](../security/audit.md). Rollback is an explicit operator operation that drops GT-002 data/evidence; backup and recovery planning are mandatory for a real deployment.

## GT-003 clients

`studio_members` is tenant-owned customer identity, separate from staff access. Its nullable user_id is reserved for future verified linkage, with unique organization/user pairing for nonnull links. Optional preferred_location_id uses a composite organization/location FK. Controlled statuses/modes/locales, canonical contact syntax, bounded fields and public UUID uniqueness are enforced in PostgreSQL. Contact indexes are nonunique, permitting shared family details; tenant-leading pattern indexes support literal prefix search. The migration widens the finite grant/audit-subject catalogs without changing grants or existing evidence. See [client lifecycle and rollback limits](../domain/studio-members.md).
