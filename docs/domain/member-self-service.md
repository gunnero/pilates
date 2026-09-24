# Member accounts and self-service (GT-011)

## Identity and custody

User is a global authentication account. StudioMember is a tenant-owned business record. GT011 reuses the existing nullable `studio_members.user_id` foreign key and unique `(organization_id,user_id)` constraint: one account may belong to several studios, but can link to only one member in each studio. Activation never creates another StudioMember. Existing links and stored staff grants are preserved. Neither a member link nor TrainerProfile grants staff authority.

Staff invitations require active TenantContext, all-location client visibility and **members.view + members.update + members.status.manage**. These existing combined powers explicitly include account invitation custody; no permissions are granted by this migration. Staff must verify the recipient address with the member. The invitation address is independently entered; contact email or name matching never links accounts automatically.

Invitations contain random 64-character capabilities, stored only as SHA-256 hashes. Delivery receives the secret transiently in a URL fragment, avoiding normal server access-log and Referer leakage. The browser removes the fragment after success. Invitations expire after 24 hours. Reissue revokes all previous pending invitations for that member. Only pending → accepted/revoked transitions are supported; expired is a derived display state. Pending uniqueness, tenant-qualified member ownership, accepted-account linkage and immutable terminal evidence are PostgreSQL-enforced. Non-null member links cannot be reassigned or cleared. Unlinking/account-email changes require a future reviewed recovery workflow.

Possessing an invitation proves control of the delivered capability. For an existing account, its current password is also required. A new account needs a strong confirmed password; activation records verified email because the recipient received the capability. Activation does not automatically sign in. Invalid, expired, replayed, inactive and conflicting-link attempts receive the same activation error. There is no public account lookup or invitation discovery endpoint.

`InvitationDelivery` is provider-neutral. The default Laravel mail implementation rejects the log mailer; development uses the array mailer, so no real mail is sent. A deployment must configure a reviewed real transport. Send occurs after commit, and failure is reported honestly to staff as `delivery=failed`; reissue is the recovery procedure. Invitations deliberately have no durable raw-token outbox or automatic retry. The test-only browser router captures transient URLs in private ignored files and refuses any target other than local gt_test; production never loads it.

## Transactions and authentication

Per-canonical-email PostgreSQL transaction advisory locks serialize activation/account creation, forgot-password issuance, reset consumption, password change and native token issuance. Activation then takes Organization → invitation → member → account row locks. Member mutations take Organization, re-resolve the active account/member/organization relationship, then use existing session/allocation locks. Competing reset requests include broker verification, password update, session/token deletion and broker token consumption inside one transaction.

Web uses the established same-origin Laravel session, CSRF cookie and Sanctum stateful authentication. `/member/login`, `/member/forgot-password`, `/member/reset-password` and `/activate` use the existing authentication forms. Member navigation lives at `/member`. The general password-reset email route remains `/reset-password`; after resetting an account can sign in via `/member/login`. Production HTTPS/secure cookies remain deployment prerequisites.

Native clients may call `POST /api/v1/member-auth/token` with canonical email, password and a bounded device_name. An active linked member in an active studio is required. Returned bearer token is shown once with `Cache-Control: no-store`; Sanctum stores only its hash. Fixed abilities are member.read, member.write, account.security, notifications.view and notifications.read. Tokens expire after 30 days, with at most 10 unexpired tokens per account. No client-supplied abilities are accepted. Native secure storage is GT012's responsibility; this task implements no native client or refresh-token protocol.

`POST /api/v1/member/token/logout` revokes the current bearer token; `/tokens/revoke` revokes all personal tokens. Password change requires the current password and revokes every database session and personal token. Reset does the same. Atomic revocation requires the same PostgreSQL connection for application state and database sessions; unsupported session stores fail closed. Global immutable `account_security_events` records only user identity, controlled action, public ID and timestamp. Already-authorized requests can finish; revocation governs subsequent authentication.

## Ownership and context

`GET /api/v1/member/organizations` discovers only active linked studios. `POST .../organizations/{uuid}/switch` revalidates membership under the organization lock and stores a session preference. Every resource URL carries its authoritative context, so multi-tab/native calls never rely on a mutable global tenant. Switching unmounts the previous workspace immediately. Missing/foreign/unlinked/inactive resources return 404; insufficient bearer abilities return 403. No Super Admin bypass exists. Staff authentication and TenantContext are unchanged.

`MemberContext` is the separate member authority resolver: authenticated User → active StudioMember → active Organization. `member.read`/`member.write` constrain bearer tokens, never confer ownership. Self-service requests accept no member_id, organization_id, actor, role, source or audit fields. Resource IDs are UUIDv7 public IDs; internal IDs stay private. Explicit serializers exclude notes, staff identity, audit metadata, rosters, token hashes and protected administration fields.

## Stable v1 API

Common prefix `/api/v1/member/organizations/{organization UUID}`:

| Method / suffix                                 | Contract                                                                                                                             |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| GET, PATCH `/profile`                           | Own profile; PATCH allowlist first_name, last_name, email, phone, preferred_locale. Contact email never changes login identity.      |
| GET `/groups`, `/groups/{group}`                | Active groups at active locations; descriptive trainer, location, local weekly rules and timezone. No roster.                        |
| GET `/sessions`, `/sessions/{session}`          | Upcoming discovery or owned bookings, concrete times, snapshots, capacity availability and own reservation/waiting/attendance state. |
| POST `/sessions/{session}/reservations`         | Optional strict boolean allow_waiting; result outcome/reservation_id/waiting_entry_id. Identity derived by server.                   |
| GET `/reservations/{reservation}`               | Own booking and session detail.                                                                                                      |
| POST `/reservations/{reservation}/cancel`       | Empty body; existing cancellation/attendance protections and FIFO promotion.                                                         |
| GET `/waiting-list`, `/waiting-list/{waiting}`  | Own waiting history, promotion state and session identity; no invented queue position.                                               |
| POST `/waiting-list/{waiting}/cancel`           | Empty body; shared allocation cancellation, suppression and audit.                                                                   |
| GET `/attendance`, `/attendance/{attendance}`   | Own read-only attendance; pending is derived on a session with no record.                                                            |
| GET `/memberships`, `/memberships/{membership}` | Own plan snapshot, dates, derived state, agreed price, paid total and balance.                                                       |
| GET `/payments`, `/payments/{payment}`          | Own cash records and reversal amount/time, without correction reasons or staff data.                                                 |

Account endpoints: GET `/api/v1/member/account`, GET `/organizations`, POST `/password`, POST `/token/logout`, POST `/tokens/revoke`. Public native authentication: POST `/api/v1/member-auth/{activate,token,forgot-password,reset-password}`. Browser activation additionally uses POST `/auth/activate`. Existing web login/logout/recovery endpoints remain.

Staff: GET/POST `/api/v1/organizations/{org}/members/{member}/invitations`; POST `/member-invitations/{invite}/revoke`. Invitation listing exposes public ID, status, expiry and linked flag, never delivery address or raw token.

Collections use `{data: [...], meta: {current_page,last_page,per_page,total}}`, default 25, maximum 100, maximum page 10000. Invitation history returns current/last pagination plus linked. Single resources use `{data: {...}}`. Errors preserve existing JSON validation/409 domain contracts. All lists are database paginated; session relations/counts are eager loaded. Existing tenant/start/source/owner indexes are reused; invitation/member and account-audit indexes are added.

Sessions default to today through 55 days later in organization timezone. Explicit `from` and `until` must be supplied together, inclusive, at most 56 days. `group_id` filters discovery; `relationship=all|reserved|waiting` selects discovery or own active relationships. Historical records are available through bounded date windows/owned detail. Attendance accepts the same paired bounded date filter. ISO timestamps are UTC with explicit IANA timezone and local_date; weekly rule minutes are local wall time. GT006 DST resolution remains authoritative; GT011 does not regenerate time rules. Group capacity is descriptive; free spots are derived for concrete sessions, never a promise of permanent admission.

## Allocation, history and notifications

Member reservations call the existing GT007 Allocation engine with a trusted server-selected member source. Capacity counts come from SessionDirectory for staff and members alike. Final-place checks, active uniqueness, waiting FIFO, cancellation suppression, promotion notifications and present-attendance cancellation protection are reused. A displayed free place is advisory until the transaction commits. Session cancellations and historical snapshots remain durable. Waiting cancellation is shared with staff code. Membership validity does not add a booking gate.

GT008 attendance and GT009 finances are read-only. Cash net totals subtract actual reversals; no mutable cached counters or financial corrections are introduced. Existing state/date policies determine membership validity. GT010 Inbox is embedded unchanged, using `/api/v1/organizations/{org}/notifications`, individual detail/read, bounded bulk read and preferences. Recipient mapping still uses current member links and active tenant/member status.

Rate limits reuse login/password/global API policies and add activation (10/IP/minute, 5/token-hash/minute) and member writes (30/account/minute). Unknown body fields and bounded pagination/date ranges are rejected. Profile audits contain changed field names, never contact values. Invitation/link/switch actions use existing tenant audit transactions; normal reads produce no audit noise. No account passwords, invitation/reset secrets or member PII are logged.

## Limits and deferrals

No unlink/reassignment UI, login-email change, SMS, MFA, refresh-token rotation, native app, PWA, production push, cards, invoices or QR check-in UI. Member accounts do not imply self check-in authorization: attendance HTTP writes remain privileged. Invitations need a configured delivery transport outside local development; array mail acceptance is not evidence of real delivery. Permanent spots remain staff-managed. No membership payment/attendance mutations or staff permissions are exposed to members. Organization discovery and histories are paginated; the dashboard shows recent pages, not an unbounded full history.

## GT-014 studio settings

GT-014 adds a member-readable own-studio settings resource and enforces member reservation/cancellation/check-in policies on the server. List/detail action flags account for current policy; members gain no admin route or settings authority. Existing preferred locales remain intact; newly created members inherit the organization locale. See [settings](studio-settings.md).
