# API contract

Version application endpoints under `/api/v1`. Browser session/recovery endpoints remain `/auth/*`; Sanctum initializes CSRF through `/sanctum/csrf-cookie`. Unknown API/auth routes return bounded errors, not React HTML. Auth/API responses are no-store.

## Identity and authentication

| Method | Route                   | Authority/result                                                                            |
| ------ | ----------------------- | ------------------------------------------------------------------------------------------- |
| GET    | `/up`                   | Framework liveness                                                                          |
| GET    | `/sanctum/csrf-cookie`  | First-party CSRF initialization, 204                                                        |
| POST   | `/auth/login`           | Credentials, CSRF, throttled, 204                                                           |
| POST   | `/auth/logout`          | Session, CSRF, 204                                                                          |
| POST   | `/auth/forgot-password` | CSRF, throttled, generic 200                                                                |
| POST   | `/auth/reset-password`  | CSRF, valid broker token, strong password, 200                                              |
| GET    | `/api/v1/me`            | Profile, own active accessible organization choices, validated session selection preference |

## Organization endpoints

All paths below are relative to `/api/v1/organizations/{organization UUID}`. Every route requires authentication, active organization and membership, `studio.access`, and any listed additional authority. Token abilities intersect stored grants. Stateful browser mutations require CSRF.

| Method | Path                         | Additional authority/result                                                                        |
| ------ | ---------------------------- | -------------------------------------------------------------------------------------------------- |
| GET    | `/context`                   | Settings, own effective permissions, membership UUID and location scope                            |
| POST   | `/switch`                    | Empty body; audited, verified selection; web session preference                                    |
| GET    | `/`                          | `organizations.view`; settings                                                                     |
| PATCH  | `/`                          | `organizations.manage`; name, locale, timezone, currency, active/inactive status                   |
| GET    | `/locations`                 | `locations.view`; scoped paginated list                                                            |
| POST   | `/locations`                 | `locations.manage` and all-location scope; create, 201                                             |
| GET    | `/locations/{location UUID}` | `locations.view`; scoped location                                                                  |
| PATCH  | `/locations/{location UUID}` | `locations.manage`; scoped location details/status                                                 |
| GET    | `/staff`                     | `staff.view`; organization-wide paginated staff directory                                          |
| GET    | `/staff/{membership UUID}`   | `staff.view`; organization-scoped membership                                                       |
| PATCH  | `/staff/{membership UUID}`   | `staff.manage`; status changes, plus `permissions.manage` for role/grants/scope/assignment changes |
| GET    | `/permissions`               | `permissions.view`; finite permission catalog and explicit role bundles                            |

Additional delegation, owner and selected-location restrictions apply; see [lifecycle](organization-lifecycle.md). Inactive locations are visible only to scoped location managers. Staff grants require permissions.view; assignment UUIDs require permissions.manage and are intersected with the viewer's own scope. Internal IDs and staff emails are never serialized.

PATCH uses partial fields; a supplied permissions array replaces the full grant set, and supplied location_ids replace selected assignments (at most 20 distinct UUIDs). Changing role without a permissions array applies the role's bundle. Unknown write fields are rejected. There are no DELETE, invitation, user-creation or membership-creation endpoints. Organization suspension/recovery is a trusted operator command.

## Envelopes and validation

Success resources use `{data: ...}`. Lists use `{data: [...], meta: {current_page, last_page, per_page, total}}`, default 25/max 100, ordered by internal ID after tenant/location constraints. GT-002 uses this minimal pagination envelope rather than the future Laravel resource links proposal; no internal IDs appear in it. `/me` remains a small unpaginated identity bootstrap list; revisit for platform-scale users.

Validation is 422 `{message, errors: {field: [messages]}}`. 401 requires authentication; 403 denies authority; 404 hides unknown/foreign/nonactive organization or unassigned/foreign resource; 409 rejects last-owner removal; 419 means invalid CSRF; 429 is throttled with Retry-After. Server errors are generic. Clients branch on status and field keys, not translated prose. Settings accept mk/en, MKD and valid IANA timezone. Location fields are name ≤160, address ≤300, city ≤100 and uppercase two-letter country code. All enums and grant lists are finite.

Send `Accept: application/json` and `Accept-Language: mk` or `en`. Responses include Content-Language and Vary. Macedonian is the default; React sends selected locale. Resource IDs are UUIDv7; instants use ISO 8601 UTC. Future money uses integer decimal strings and currency, never floats; no financial resource exists now.

Sensitive future money/reservation writes require persisted idempotency keys scoped to organization/actor/operation, request hash checks and replay semantics. GT-002 does not add an idempotency API; repeated location POST can create separate locations, and successful switch requests each produce evidence.
