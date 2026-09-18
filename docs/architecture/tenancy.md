# Organization isolation

Organization means the studio-owning business; location will mean a branch within that organization. Users are global identities and may have access to several organizations. This is a shared-database/shared-schema design.

Implemented request sequence:

1. `auth:sanctum` authenticates the web session or bearer token.
2. `ResolveOrganization` reads a UUID route parameter.
3. `TenantContext::resolve` queries **through the authenticated user's organizations relationship**, joining the access relation. Unknown and unrelated organizations both return 404.
4. A Gate checks a named permission. The context endpoint requires `studio.access`; membership alone is insufficient (403).
5. Explicit response fields serialize the authorized organization. Internal IDs, grants and other organizations are not serialized here.
6. Context is scoped to the request and cleared before resolution and in middleware `finally`, including failed requests. Missing context throws; unknown permissions deny.

`GET /api/v1/me` is the intentional bootstrap query: it lists only the authenticated user's organization IDs/names so a studio can be selected. `organizations` and `organization_user` are access-control primitives, not general unscoped domain repositories. No public membership or permission write endpoint exists. Fixture writes are trusted test-only code.

## Required for each future tenant-owned aggregate

- Non-null `organization_id` with an indexed foreign key. Nested associations must use composite `(organization_id, parent_id)` foreign keys backed by corresponding unique constraints, preventing mixed-tenant links even in trusted writes.
- Query from the resolved organization's relationship or an explicit organization predicate; never perform a global `find(public_id)` and assume authentication scopes it. Use tenant-constrained route binding for nested resources.
- Derive organization and actor from trusted context on creation; reject tenant reassignment. Never accept them as mass-assigned request fields.
- Authorize before reading/serializing sensitive fields and before mutation. A resource UUID is an identifier, not access authority.
- Jobs, CLI operations and exports must accept an explicit organization, re-establish authorization as appropriate, and clear context. No implicit fallback organization or global bypass.
- Add positive tests plus guessed foreign UUIDs, missing context, cross-organization associations, revoked membership, different permissions, and concurrent job/request context reuse tests.

There is deliberately **no claim of automatic global Eloquent scoping or PostgreSQL row-level security**. The narrow current routes prove explicit isolation. Future modules must follow these rules; RLS may add defense in depth after connection/worker semantics are designed. Global platform administration must later be a separate, explicit, audited capability, never `Gate::before` granting all tenant operations.
