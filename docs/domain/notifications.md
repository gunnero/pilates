# Notifications (GT-010)

Durable `platform_notifications` are immutable tenant-owned evidence with UUIDv7 public IDs, localized MK/EN title/message snapshots and bounded structured metadata. Read state, delivery state, attempts and preferences live in separate tables. PostgreSQL is authoritative; Redis is not a notification store.

## Recipients and catalog

Exactly one recipient is a StudioMember or OrganizationMembership. Each source is a concrete, tenant-qualified FK; there is no polymorphic subject input. Customer records may be offline: evidence remains durable even without an account. Only an already authenticated User linked through StudioMember.user_id can access that member inbox. This does not implement signup, account linking, booking self-service or mobile authentication. A member who is also staff can see both of their own recipient identities, subject to current staff session/location/trainer scope.

| Type                                            | Trigger / audience                                                      |
| ----------------------------------------------- | ----------------------------------------------------------------------- |
| reservation.confirmed                           | New active reservation, member                                          |
| reservation.cancelled                           | Reservation cancellation, member                                        |
| waiting.promoted                                | FIFO waiting-list promotion, member                                     |
| session.changed                                 | Material time/location/trainer override, active reserved members        |
| session.cancelled                               | Session cancellation, active reserved members                           |
| session.reminder                                | Bounded upcoming reservation sweep, member; optional                    |
| attendance.result                               | Non-present result or correction; ordinary present check-in stays quiet |
| membership.created                              | Membership assigned, member                                             |
| membership.activated                            | Previously created future membership enters its active period           |
| membership.expiring                             | Approaching inclusive expiry, member; optional                          |
| membership.expired                              | Membership ended, member                                                |
| cash.recorded / cash.reversed                   | Cash evidence recorded/reversed, member                                 |
| staff.session_changed / staff.session_cancelled | Assigned trainer's staff membership                                     |

The server-owned NotificationType enum and Registry define all supported types and messages. Payload metadata allows only date, time, timezone, status, starts_on, ends_on string values (100 characters each; 2048 bytes total). No customer names, contact information, notes, cash amounts, balances, plan prices or internal recipient IDs are serialized. Financial messages are generic even for members. Staff never receive cash/membership notices.

## Atomic events and idempotency

Domain writers hold the existing organization lock and insert evidence plus one pending in-app delivery in the same transaction as domain changes/audit. Rollback removes all three. No external delivery or queue dispatch occurs inside that transaction. Unique `(organization_id, recipient_key, event_key)` prevents event duplication; unique delivery notification ID prevents duplicate inbox delivery. Fixed lifecycle events use source UUIDs. Repeatable session changes and attendance corrections use their audit event UUID as version identity. Reminders additionally bind the concrete session start instant. Replaying an old source version cannot create a new event.

Session capacity changes alone do not notify. Generated schedule reconciliation does not rewrite reserved sessions under GT-007 protection; any actual session cancellation routes through the shared cancellation writer. Notifications retain old evidence rather than rewriting payloads after changes.

## Reminders and expiry

`gt:notifications:generate ORGANIZATION_UUID --limit=100` processes an explicit active tenant, at most 200 candidates per category (four categories, at most 800). Defaults in config/notifications.php are operator-owned and bounded by validation. SQL filters and anti-joins select only eligible, not-yet-generated sources. Each candidate rechecks state under the organization lock. Operationally invalid sessions, inactive clients and cancelled reservations are excluded. One failed candidate increments failed and the command exits nonzero; source contents and exception messages are not logged.

Session reminder default lead is 24 elapsed hours with a two-hour catch-up window: starts between now+22h and now+24h inclusive. After that window no late reminder is generated. Eligibility uses the stored GT-006 UTC instant, never reparses ambiguous local times; payload display uses the session timezone snapshot. GT-006 nonexistent/ambiguous-time materialization policies therefore remain authoritative. An actual changed start instant can have a new reminder; a replay at the same instant cannot.

Membership expiry uses the organization's local calendar date and inclusive ends_on. Active memberships ending today through seven days ahead receive one pre-expiry event. Memberships ended before today within the previous seven days receive one expired event. A future membership created before its start date receives activation when starts_on is today or yesterday and the membership is still active. Cancelled memberships receive neither. Repeated small batches advance through anti-joins without a persistent cursor. Missing the catch-up window is an explicit limit, not an unlimited historical replay.

## Delivery and recovery

`gt:notifications:deliver --limit=100` polls up to 200 due outbox records across organizations. Organization then delivery locks serialize claims. Each claim durably increments attempts and inserts a started attempt with a unique 120-second lease. The transaction commits before invoking DeliveryChannel. Finalization locks again, requires the matching lease, and rechecks eligibility. InAppChannel publishes only by the final delivered state; an inbox query never exposes pending rows.

States: pending → processing → delivered, suppressed, retry or failed. Retry claims return to processing. Delivered/suppressed/failed are terminal. Maximum five attempts; transient retry delays are 60, 120, 240 and 480 seconds. Expired leases mark the previous started attempt abandoned and reclaim with a new token; stale workers cannot finalize the new claim. Attempt/failure codes are bounded and content-free. Exhausted leases become failed. Ineligible sources/preferences become suppressed, never silently successful delivery. Commands report considered/delivered/retry/failed/suppressed/skipped/errors and exit nonzero on retry/failure/errors.

Delivery rechecks active organization/member/staff, current staff permissions/location/assignment, reservation/session status and time, trainer/location operational eligibility, material snapshot fingerprint, attendance result and membership dates/cancellation. Undelivered cash receipts are suppressed after reversal; reversal notices remain useful after membership cancellation. Already delivered evidence remains readable as history and is labeled when no longer current. An opt-out does not remove history.

DeliveryChannel receives a stable provider idempotency key. Future push adapters must honor it: a crash after an external send but before finalization permits retry, so external exactly-once delivery is not claimed. GT-010 has no provider credentials, push tokens, actual push, SMS, WhatsApp or marketing.

## Inbox, preferences and authorization

Authenticated endpoints:

- GET `/api/v1/notification-contexts` — recipient-owned active organizations, bounded pagination.
- GET `/api/v1/organizations/{organization}/notifications` — own delivered inbox, unread_count, page/per_page (1–100); optional unread=0/1.
- GET `.../notifications/{notification}` — own detail.
- POST `.../notifications/{notification}/read` — empty body, idempotent first read.
- POST `.../notifications/read` — ids array, 1–100 distinct UUIDs; whole batch rejected if any ID is invisible.
- GET/PATCH `.../notifications/preferences` — exactly reminders_enabled and expiry_reminders_enabled booleans.

Cookie authentication uses existing CSRF/session protections. Tokens intersect notifications.view and, for mutations, notifications.read; staff also need existing studio.access and sessions.view for operational notices. No stored staff grants are added. Staff resolution reuses TenantContext; profile alone grants nothing. Member authorization derives solely from the current authenticated User link and active member/org status. Ownership never comes from request fields. Preference writes and read mutations re-resolve identity under the organization lock. Ordinary read state is immutable after first insertion and does not create audit noise. Preference changes and delivery retries/failures create bounded audit evidence in the same transaction.

## Database and operational contract

Composite FKs protect recipient/source tenant and member ownership. Checks constrain recipient cardinality, catalog/source shape, payloads, channels, states, attempts, leases and timestamps. Triggers prohibit evidence/read mutation, terminal delivery mutation, attempt rewriting and ownership changes. Attempt insertion must match a live delivery claim. Indexes serve tenant/recipient inboxes, source deduplication, due delivery and expired leases. Inbox relations are batch-loaded; history pagination does not load all messages.

Run Laravel Scheduler every minute using the project's normal application environment. GT-010 registers the delivery poll and generates reminders only for the explicit `scheduled_organizations` allowlist (maximum 20 UUIDs) in config/notifications.php. No OS cron, systemd unit or shared service is installed by this task. Empty allowlist means reminders require explicit command invocation. Monitor nonzero statuses, failed/suppressed totals and stale processing leases. Do not reset attempts or alter immutable evidence to force replay. No administrative HTTP retry endpoint exists; any future correction/requeue tool requires a separate audited policy. History retention/deletion and external provider dead-letter administration are deferred.

Per-tenant organization locks trade throughput for consistency. Commands bound selected/processed rows, but require indexed source searches; monitor data growth and batch backlog. Scheduler outages beyond catch-up windows can miss optional reminders. Notification evidence for offline customers accumulates without introducing accounts. Notification-only access is not GT-011 self-service.

## GT-014 studio settings

GT-014 moves reminder lead/expiry-warning defaults into the typed tenant resolver. Each candidate rechecks current settings after its organization lock. Optional delivery checks respect enablement; fixed batching/catch-up/retries and event deduplication keys remain. Disabled/suppressed or historical notices are never rewritten or resurrected by policy changes. See [settings](studio-settings.md).
