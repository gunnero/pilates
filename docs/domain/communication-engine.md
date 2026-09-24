# Notifications and communication (GT-015)

GT-015 extends the GT-010 notification tables, event registry, recipient scope, leases and GT-014 policy resolver. It does not create a second inbox, reservation allocator or settings store. PostgreSQL owns evidence and claims. Domain transactions create immutable logical notifications and their channel destinations together; external I/O happens after the claim transaction commits. A provider failure cannot undo the business transition. Transactions that roll back leave no notification or outbox rows.

## Catalog and localization

Existing dotted enum values remain stable. Product names `reservation_created`, `reservation_changed` and `reservation_reminder` map to `reservation.confirmed`, `session.changed` and `session.reminder`; there are no duplicate messages for aliases. `reservation.cancelled`, `waiting.promoted`, `session.cancelled`, membership lifecycle/expiry and generic cash messages retain their GT-010 sources. GT-015 adds `waiting.joined`, `waiting.session_cancelled` and `studio.announcement`. Session cancellation notifies current waiting members without changing their waiting-list records or ordering. `waiting.expired` is reserved in the typed catalog; the current waiting-list policy has no expiry/missed transition, so it is not emitted. Ineligible waiting entries do not introduce a new lifecycle or change FIFO. Ordinary present attendance remains quiet.

Categories derive from the enum: operational, reminders, waiting_list, session_changes, membership and announcements. Session cancellation and waiting promotion are high priority; others are normal. Type, source, bilingual title/message snapshots and bounded metadata are immutable. In-app rendering selects the requested MK/EN locale from the historical snapshots. External delivery selects the member's current stored preferred locale. Announcements require both MK and EN plain-text versions. The database retains the exact approved versions; no HTML rendering or arbitrary template evaluation occurs. Push always uses generic translated text and a `gtstudio://notification/{organization}/{notification}` destination, never member names, financial amounts, announcement text or booking details on the lock screen.

Reservation/session operational notices and financial evidence remain mandatory in-app. The existing two optional in-app reminder preferences remain authoritative. Announcement opt-out also suppresses an undelivered announcement. Session-change and waiting-list category controls govern external channels; they do not remove mandatory in-app history. Security account activation/reset/password/token flows are unchanged and cannot be disabled here.

## Preferences and studio policy

The existing `notification_preferences` row gains five boolean fields. Effective defaults without a row are:

| Field                    | Default | Effect                                                                    |
| ------------------------ | ------- | ------------------------------------------------------------------------- |
| reminders_enabled        | true    | Optional reservation/session reminders                                    |
| expiry_reminders_enabled | true    | Optional membership-expiring warnings                                     |
| session_changes_enabled  | true    | External session change/cancellation                                      |
| waiting_updates_enabled  | true    | External waiting updates                                                  |
| announcements_enabled    | true    | Optional announcements                                                    |
| push_enabled             | true    | External push destinations, after explicit native permission/registration |
| email_enabled            | false   | External email destinations, explicit opt-in                              |

GET is read-only; PATCH merges only submitted strict booleans under the organization lock and audits effective before/after values. Existing clients posting the original two fields remain compatible. Neither reading nor creating a new member needs to insert defaults. Opt-in does not retrospectively create external destinations for old events. Opt-out rechecks at claim/finalization and suppresses pending optional delivery without deleting historical evidence.

GT-014 remains the sole source of reminder enablement, lead time and expiry-warning days. Generation still uses bounded current-state SQL plus revalidation under the organization lock. Reminders bind the concrete UTC session start; timezone/DST resolution remains GT-006's responsibility. A changed instant can create its own reminder, while stale/cancelled source evidence cannot be delivered as a current reminder. Membership warnings use organization-local inclusive dates and do not alter sold membership or cash records. Catch-up windows and the explicit scheduled-organization allowlist remain unchanged.

## Identity, devices and channels

A logical notification has exactly one tenant-qualified member or staff recipient. Existing `NotificationDelivery` now has a unique `(notification, channel, destination_key)`: one in-app destination, up to ten active device registrations for a member/account, and an optional email user reference. PostgreSQL checks channels, destination shape and recipient/device/account consistency. The model's original `delivery` relation deliberately refers only to in-app delivery, preserving inbox behavior.

Device registration requires an authenticated, unexpired native personal access token and active MemberContext. Cookie-only authentication cannot register push. Each registration has a UUIDv7, organization/member/user ownership, login-token FK, platform, installation UUID, last-seen/revocation times and encrypted Expo token. A separate immutable digest-to-user ownership ledger prevents reassignment to a different account, even after revocation. The same account may register the token in multiple studios; each registration is independently tenant scoped. Global digest locking and PostgreSQL uniqueness serialize competing registrations. No endpoint returns tokens or hashes. API input cannot supply an owner.

Deleting/revoking a login token atomically nulls its registration FK. Claim and pre-send validation reject that registration, including password reset and revoke-all paths. Native cleanup captures the original bearer and registration IDs, so account/organization switching cannot send cleanup using the next account's credential. Late registration after local cleanup revokes itself. Token acquisition uses Expo project tokens, serialized registration attempts, token-change listeners and refresh on an authorized app restoration. Only the installation UUID is retained in native SecureStore; push tokens stay in bounded memory and encrypted server storage. Existing account credentials remain exclusively in SecureStore.

A previously bound Expo token cannot be adopted by a different account. That account needs a fresh provider token/installation; a conflict is surfaced instead of transferring ownership. Offline logout still clears local identity/cache immediately and reports that remote revocation could not be confirmed. Network isolation cannot retroactively retract an already accepted push or prove a remote logout succeeded. Generic payloads contain no account contents, and notification taps always require current authenticated API access. Operators must validate these native/provider cases before release.

## Delivery and receipts

In-app/email lifecycle: pending → processing → delivered, retry, failed or suppressed. Push adds processing → awaiting_receipt. An Expo ticket is not success. Receipt acceptance moves the delivery to delivered (provider handoff, not physical display); a permanent failure fails it and `DeviceNotRegistered` revokes the device. Transient receipt failures retry with the same bounded five-attempt policy. Missing receipts are polled every 15 minutes, becoming an explicit unknown/failed outcome after 23 hours rather than being reported delivered. Receipt evidence is retained, including failed attempts followed by successful retries.

Claims still lock Organization then delivery, with a 120-second lease. Attempts and receipts have unique durable identities and protected state transitions. External calls have a 5-second connection and 15-second total timeout, fixed HTTPS Expo endpoints, no redirects and no response-body logging. The provider contract accepts batches of 1–100; delivery workers currently send one destination per claim, while receipt polling batches 100 IDs. Duplicate queue jobs cannot acquire the same live lease. A persisted ticket is reused on recovery rather than blindly resending. A crash after external acceptance but before persisting its ticket, or SMTP acceptance before local finalization, remains an at-least-once boundary: external exactly-once delivery is not claimed.

Email uses Laravel Mail and a text-only localized snapshot template from the queued delivery job. It resolves the current authenticated account email, requires verification, and never uses a staff-entered client contact address. Both default transports are explicitly disabled outside production, even if an enablement flag is accidentally true. Tests inject fakes; no test requires Expo, SMTP or a commercial provider. Existing account-security mail remains separate.

## Announcements

Creation/cancellation/status require current `organizations.manage` and all-location staff scope. This existing administrative capability accurately represents studio-wide communication; no stored grants are expanded. TrainerProfile grants nothing. Members cannot enumerate announcements or provider state.

An announcement has immutable bilingual content, author, tenant, optional group FK, client request UUID and a 30-day expiry. Request UUID replay is idempotent; conflicting content under the same UUID is rejected. Group targeting means current active permanent-place holders or active reservations in future group sessions. Fan-out considers only members whose internal IDs existed at creation, rechecks active membership, and advances a durable cursor in batches of 100 under the organization lock. Cursor and generated notification evidence commit atomically. Delivery rows are not synchronously fanned out in the HTTP request. A later group join may be included only if that member existed at creation and has not yet been passed by the cursor; this is a bounded live-audience policy, not a frozen membership export.

Cancellation stops future fan-out and suppresses undelivered announcements. Expiry also stops generation/delivery. Already delivered content stays as immutable history, with `current=false` when no longer current. No retention deletion runs. Admin status returns bounded recent announcements, recipient totals and aggregate channel/state counts, never device/provider identifiers. The initial web composer targets all active members; group targeting is available through the typed API.

## API extensions

Existing recipient-scoped inbox/detail/page-read endpoints remain. Under `/api/v1/organizations/{organization}/notifications`:

- GET `/unread-count`: current recipient's unread delivered in-app count.
- POST `/read-all`: empty JSON body; marks visible delivered notifications read atomically. Future deliveries remain unread.
- GET/PATCH `/preferences`: the seven effective fields above, partial strict-boolean patch.

Native MemberContext endpoints under `/api/v1/member/organizations/{organization}`:

- POST `/devices`: `{token, platform: "ios"|"android", installation_id: UUID}`; returns only registration public ID.
- DELETE `/devices/{device}`: empty body, own registration only, idempotent revocation.

Staff endpoints under `/api/v1/organizations/{organization}`:

- GET `/announcements?page=N`: pages of 25, safe state/recipient/delivery aggregates.
- POST `/announcements`: `{request_id, group_id?, title_mk, title_en, message_mk, message_en}`; 202, no synchronous fan-out.
- POST `/announcements/{announcement}/cancel`: empty body.

Titles are at most 160 characters and bodies 1000; Angle brackets are rejected. Titles reject U+0000–001F; bodies reject U+0000–0008 and permit multiline text. Clients render text without HTML evaluation. All routes retain existing authentication, tenant resolution and token capability checks. Device/announcement/read-all writes use the 30/minute member-write limiter in addition to the API limiter. Preference/read requests also remain under the established API limiter. Notification responses add enum-derived category/priority/locale and a finite source destination; native navigation fetches authoritative session/membership state after opening.

## Evidence and limits

Business audits cover preferences, registrations/revocations and announcement creation/cancellation, without content/tokens. Existing business transition audit evidence remains immutable. Automated attempts use delivery/attempt/receipt rows and content-free operational logs, replacing new retry/failure business audit noise. Existing audit history is not rewritten.

See [operations](../operations/notifications.md), [verification](../tasks/GT-015_COMPLETION.md), and the GT-015 security review. SMS, WhatsApp, Viber, marketing, production credentials/infrastructure, native signing/store release, GT-016 and deployment are excluded. Physical push display, SMTP delivery and provider credentials remain unverified until configured externally.
