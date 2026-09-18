# API contract

Version application endpoints under `/api/v1`; breaking contracts require a new major namespace. Browser-only session/recovery endpoints remain `/auth/*`; Sanctum uses `/sanctum/csrf-cookie`. HTML routes return the React entry point. Unknown API/auth URLs return bounded errors, not the SPA.

Implemented routes:

| Method | Route                                | Authority/result                                         |
| ------ | ------------------------------------ | -------------------------------------------------------- |
| GET    | /up                                  | Framework liveness; no application details               |
| GET    | /sanctum/csrf-cookie                 | First-party CSRF initialization, 204                     |
| POST   | /auth/login                          | Validated credentials, CSRF, throttled, 204              |
| POST   | /auth/logout                         | Session auth, CSRF, 204                                  |
| POST   | /auth/forgot-password                | CSRF, throttled, generic 200 message                     |
| POST   | /auth/reset-password                 | CSRF, valid broker token, strong password, 200 message   |
| GET    | /api/v1/me                           | Authenticated minimal profile + own organization choices |
| GET    | /api/v1/organizations/{uuid}/context | Auth, membership, studio.access, settings only           |

Success resources use `{ "data": ... }`; no-content operations use 204. Message-only recovery responses use `{ "message": "..." }`. Validation is Laravel's 422 `{message, errors: {field: [messages]}}`. 401 means authentication required; 403 means insufficient permission; unrelated/missing tenant is 404; 419 means expired/missing CSRF; 429 means throttled and includes Retry-After. Server errors are generic; clients must branch on status/field keys rather than localized prose. Auth/API responses are no-store. Exception details/SQL/bindings never enter API responses.

Use Accept: application/json and Accept-Language: mk or en. Responses include Content-Language and Vary: Accept-Language. Macedonian is the default for absent/unsupported preferences. Web client sends selected locale explicitly. Resource IDs are UUIDv7 strings; ISO 8601 UTC instants include a timezone. Future money uses `{amount_minor: "125000", currency: "MKD"}`. No financial field exists now.

Future lists use Laravel resource pagination: data + links + meta, default 25 and max 100, deterministic sorting including an ID tie-breaker, all filters scoped before pagination. `/me` organization choices are currently a small unpaginated identity bootstrap list; revisit for platform-scale users.

Future sensitive writes (payments/corrections, reservation claims, waiting-list promotion) require persisted idempotency keys scoped to organization, actor and operation, request-body hash validation, response replay and conflict for reuse with different content. PostgreSQL uniqueness and transactions make retries safe; Redis is not the idempotency ledger. Define retention and replay semantics with each endpoint. No idempotency API is implemented in GT-001.
