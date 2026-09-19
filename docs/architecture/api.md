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

## GT-003 client endpoints

All paths below are relative to `/api/v1/organizations/{organization UUID}/members`. Require active organization/staff context, studio.access, members.view and all-location scope. Selected-location staff are denied even with client permissions.

| Method | Path                    | Additional authority / result                                              |
| ------ | ----------------------- | -------------------------------------------------------------------------- |
| GET    | `/`                     | Paginated default active/inactive directory; non-PII filters only          |
| POST   | `/search`               | Same directory with bounded search/filter JSON body; browser CSRF required |
| POST   | `/`                     | members.create; creates active profile, 201                                |
| GET    | `/{member UUID}`        | Explicit profile, including archived history                               |
| PATCH  | `/{member UUID}`        | members.update; profile fields; archived profile returns 409               |
| PATCH  | `/{member UUID}/status` | members.status.manage; dedicated status-only transition                    |

Search accepts page (1–10000), per_page (1–100), search (2–100 characters), status (active/inactive/archived/all), digital_access_mode (app/assisted/offline) and optional preferred_location_id UUID. Default page size 25. Search uses bound, escaped literal prefixes on first/last/full name, email and normalized phone. Search is prohibited on GET to avoid contact data in access-log URLs. List returns data/meta without notes, user/internal IDs or duplicate information. Preferred location filters/assignment and relationship serialization require locations.view.

Create/profile writes accept first_name, last_name, phone, email, preferred_locale, digital_access_mode, preferred_location_id and notes. Names required for creation; contact/account optional. Notes submission additionally requires members.notes.manage; notes are returned only with members.notes.view. Status, organization ownership, user linkage, IDs/timestamps and all unknown fields are rejected on profile writes. Status endpoint accepts only status. Archived → inactive is the only restore transition; archived → active returns 422. No DELETE or account linking API.

Successful create/update returns `{data: profile, meta: {duplicate_warning: boolean}}`. Warning is advisory for an exact normalized contact/name match in this tenant, never a merge or unique-contact rule. Other detail/status responses use `{data: profile}`. Phone/email are nullable and canonical; mode is descriptive, never authentication. See [client domain](../domain/studio-members.md) for bounded fields and privacy semantics.

## GT-004 trainer endpoints

Paths are relative to `/api/v1/organizations/{organization UUID}/trainers`. Require active staff/organization context, studio.access and trainers.view; selected viewers receive only shared active-location staff profiles. All mutations also require trainers.manage and all-location scope.

| Method | Path                        | Result                                                                      |
| ------ | --------------------------- | --------------------------------------------------------------------------- |
| GET    | `/`                         | Paginated directory, both active/inactive by default; no search text in URL |
| POST   | `/search`                   | Same directory with JSON body search/filters                                |
| POST   | `/`                         | Enable an existing active membership; 201, duplicate 409                    |
| GET    | `/{trainer UUID}`           | Operational detail, including optional bio                                  |
| PATCH  | `/{trainer UUID}`           | Partial operational display_name/bio update                                 |
| PATCH  | `/{trainer UUID}/status`    | Explicit active/inactive transition                                         |
| GET    | `/{trainer UUID}/locations` | Paginated currently eligible locations visible to caller                    |

Enable accepts required membership_id (public UUID), optional nullable display_name (160) and bio (1000). Update accepts only display_name/bio; status accepts only status. Unknown/protected fields, relationship reassignment and access fields return 422. Nonactive target staff cannot be enabled/reactivated (422). Foreign/unshared resource UUIDs return 404, never a global existence lookup. No DELETE endpoint exists.

Directory filters: page 1–10000, per_page 1–100 (default 25), status active/inactive/all, location_id UUID and POST-only search 2–100 characters. Search is a bound, escaped case-insensitive prefix on effective display name; GET search is prohibited. Location filters require a currently active location accessible to the viewer and select only eligible active trainers. Locations paging uses the same page/per_page bounds. List order is stable internal ID; no internal ID is serialized.

The explicit staff representation includes id, membership_id, display_name, display_name_override, status, staff_role, staff_status, location_scope, eligible_location_count, up to five eligible_locations ({id,name}) and assignable. Detail adds bio. Eligibility/counts are viewer-scoped and refreshed from authoritative assignments/status; no auth grants, email or audit metadata appear. Existing Staff representations optionally add trainer_profile: null or {id,status}, only with all-location scope and trainers.view. See [trainer policy](../domain/trainer-profiles.md) before using any response in a future member surface.
