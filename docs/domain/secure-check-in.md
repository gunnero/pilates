# Secure member check-in (GT-013)

GT-008 `AttendanceRecord` remains the only attendance domain. GT-013 adds authenticated QR redemption and native scanning, not a second attendance system. No schema migration, new secret, permission grant or QR-token table is introduced.

## Credential and display

Authorized staff obtain `POST /api/v1/organizations/{organization}/sessions/{session}/attendance/qr` through existing tenant membership, `studio.access`, `sessions.view`, `attendance.view`, `attendance.manage`, selected-location and trainer-assignment checks. Authority and current session eligibility are rechecked under the organization and session locks. A trainer profile alone grants nothing. Rotation creates neither attendance nor audit events.

Laravel authenticated encryption protects version 1, organization ownership, session public UUID, server-issued time and expiry. Ciphertext contains no member identity. Public camera envelope: `GTQR1:` followed by JSON with exactly `organization` (public UUID), `session` (public UUID), `credential` (opaque ciphertext). The public routing hints confer no authority: the server independently checks the encrypted binding. Neither application encryption key nor account bearer token appears in a QR. The response also includes `server_time`, `expires_at`, `refresh_after`; legacy `token` remains an alias for the opaque credential for GT-008 compatibility.

Default lifetime is at most 90 seconds, rotation every 30 seconds, capped at check-in closing time/session end. Issuance refuses a zero-lifetime credential. The display clears on renewal failure or expiry and aborts on unmount. It derives its expiry timer conservatively from server TTL minus elapsed request time, not the device wall clock. No extra clock-skew grace: future issued times and expiry at or before server now fail. Operators must keep server clocks synchronized. A suspended browser can throttle timers; every redemption still checks server time.

QR sharing within the validity window cannot prove physical presence. This is a short-lived shared class credential, not proximity attestation. Many eligible members can use one displayed code. Old screenshots fail after expiry; live forwarding during the remaining lifetime is a documented limitation. No global single-use nonce is appropriate here.

## Server contract

`POST /api/v1/member/organizations/{organization}/sessions/{session}/check-in`, authenticated through Sanctum, requires JSON `{credential}` only. Unknown fields, forged member/actor/source fields, arbitrary URLs, non-base64 data and credentials over 2,048 characters are rejected. The camera additionally bounds the entire envelope to 4,096 characters, validates exact keys and UUIDs, and rejects unsupported versions before making a request.

`MemberContext::mutate` locks Organization, resolves the currently active organization and linked active StudioMember and requires `member.write` for scoped tokens. Then the existing Allocation session lock and GT-008 attendance writer are used. The organization/session, current operational group/location/trainer, scheduled status, server time window, credential and active reservation are validated inside that transaction. No staff identity is inferred from a member account. The existing organization lock serializes membership, reservation, session and attendance lifecycle changes.

GT-013 redemption window is half-open: `[starts_at − 30 minutes, min(starts_at + 30 minutes, ends_at))`. Instants are UTC; display uses the session's snapshotted IANA timezone. PHP/application and PostgreSQL connections retain the established UTC configuration regardless of host OS timezone. GT-006 resolves recurring local wall time and DST before these concrete sessions exist. Both occurrences of an ambiguous autumn wall time are distinct instants; spring gaps require no new conversion in QR redemption. The legacy internal GT-008 non-QR method retains its prior +30 minute boundary behavior; no HTTP self-check-in path permits omitting the QR.

Waiting-only and cancelled bookings cannot check in. Redemption never reserves, promotes, allocates capacity, charges money, or creates a walk-in. Existing present attendance is returned idempotently only after current credential/eligibility checks; an expired QR is rejected even if attendance already exists. A different recorded status requires an authorized staff correction. Concurrent manual/QR attempts preserve the first authoritative record and method.

Success contains only `outcome` (`checked_in` or `already_present`), attendance public ID/status/method/time and session public ID/name/start/timezone. Responses are private/no-store. Own history includes method; no credential, internal ownership IDs or other members appear. Existing audit records one meaningful attendance mutation with server-derived method `qr`; duplicates/rotation create no noise. Notification behavior remains GT-010's existing attendance behavior, without new delivery channels.

Failure semantics: 401 unauthenticated/expired account session; 403 insufficient token permission; 404 missing or inaccessible tenant/member/session; 422 invalid request shape; 429 rate limit. Domain 409 codes include `qr_invalid`, `qr_expired`, `too_early`, `too_late`, `waiting`, `reservation_required`, `cancelled`, `unavailable`, `member_ineligible`, `correction_required`. Clients use a finite localized allowlist, never display untrusted exception text. Missing/inactive member links deliberately retain the existing non-disclosing 404 response.

## Mobile and trainer experience

Home and Activity open Scan to check in. The camera starts only after a contextual user action and permission grant, only while focused/foregrounded. Expo Camera scans QR codes only. No image/video capture, microphone access, gallery upload, arbitrary URL navigation, WebView or QR persistence is used. Denial/restriction/mount failure has an explicit trainer fallback. One scan locks submission immediately, removes the camera and waits for the authoritative result. A new scan requires another explicit action. Unmount aborts the request and clears the screen; account/studio navigation keys prevent cross-context results. Aborting does not undo a committed server write.

A timeout may follow a successful commit. The screen offers session attendance verification and does not retry the mutation automatically. Native member history exposes QR method where applicable. MK/EN labels and accessible controls use existing localization and UI primitives. Trainer management stays in responsive web; the member app gains no staff screens.

Trainer roster polls the existing bounded, scoped roster page every 10 seconds, with one request at a time; it pauses requests when the document is hidden and aborts on unmount. A failed refresh removes stale roster data. This is near-live polling, not a websocket guarantee. Pending means an active booking without an attendance record; present/absent/no-show are GT-008 statuses. Waiting-only members remain in the separately permission-scoped reservation/waiting UI, not the attendance roster. Authorized manual present, correction, absence/no-show and walk-in remain available. Walk-in uses existing transactional capacity allocation and FIFO; QR is never the only path.

## Limits and operations

Member check-in: 20 attempts/minute/account and 300/minute/source IP, plus existing API limits. Staff QR generation: 12/minute/account plus existing API limits. A normal display uses two requests/minute; legitimate members have independent per-account budgets, and a shared studio network can admit up to 300 check-in attempts/minute. Per-account QR limits are shared across that staff user's displays. Limits cover malformed requests before validation. No raw QR credentials, auth headers, camera media or request bodies belong in logs, traces, analytics, audit metadata or screenshots. Existing exception reporting records classes, not request payloads. Reverse proxies must follow this same body/header redaction contract.

There is no new background job, cleanup table, migration or production setting. Existing PostgreSQL attendance uniqueness, tenant-qualified foreign keys, state/time constraints, reservation capacity protection and organization/session lock order remain authoritative. Tests use real competing PostgreSQL processes; race credentials are sent over stdin, not process arguments.

GT-014 may introduce authorized configuration for check-in windows, credential TTL, rotation and rate limits with validated bounds. No GT-014 implementation is included. Push delivery, GPS/proximity assurance, offline attendance, member walk-in creation, biometric identity, BLE/NFC and anti-relay hardware are deferred. Native camera execution on physical devices still requires an iOS/Android runtime; automated camera-adapter tests and real API/browser flows do not establish device camera compatibility.

## GT-014 studio settings

GT-014 makes member windows, TTL (30–90 seconds) and rotation (10–30 seconds, at most half TTL) tenant-configurable. Existing 30/30-minute, 90/30-second defaults remain. Previously issued credentials retain their signed expiration but must satisfy current enabled/window policy. Native scanning checks current settings before showing camera controls; the API remains authoritative. See [settings](studio-settings.md).
