# GT-012 mobile member app

The iOS/Android app in `apps/mobile` consumes GT-011. PostgreSQL and existing server services remain authoritative. It adds no backend routes, schema, grants or migrations. Staff/admin functions, card payments, accounting, purchasing, attendance correction and GT-013 remain outside this milestone.

## Stack and layout

Expo SDK 57 (`expo` 57.0.25), React Native 0.86.3, React 19.2.3 and TypeScript 6.0.3 are the SDK template's compatible versions. React Navigation 7 native stack supplies navigation/back behavior. TanStack Query 5 supplies bounded in-memory server state. Expo SecureStore supplies platform Keychain/Keystore storage. Jest Expo 57 and React Native Testing Library 13 test native components. The root web React version remains independent in a pnpm workspace; root lockfile controls both projects.

- `src/App.tsx`: bootstrap, authenticated navigation, foreground refresh and native link/notification listeners.
- `src/navigation`: finite route types and strict link parser.
- `src/api`: trusted-origin transport, bounded endpoint wrappers and member response types.
- `src/auth`, `src/storage`: account/session and secure credential lifecycle.
- `src/hooks`: account/studio query keys, memory bounds and guarded mutations.
- `src/screens`, `src/components`: member flows, accessible shared controls and safe state rendering.
- `src/locales`, `src/utils`: complete MK/EN text and explicit organization-local time/money formatting.
- `src/notifications`: provider-neutral lifecycle and disabled native delivery boundary.
- `src/__tests__`: unit/component checks. `tests/browser/mobile-api.spec.ts` consumes the same client against real guarded HTTP/PostgreSQL.

Official implementation references: [Expo monorepos](https://docs.expo.dev/guides/monorepos/), [SecureStore](https://docs.expo.dev/versions/latest/sdk/securestore/), [Expo testing](https://docs.expo.dev/develop/unit-testing/). Versions are pinned by the lockfile, not inferred from changing documentation examples.

## Local setup and environments

Use repository Node 24 and pnpm 12.3.4. Run `pnpm install --frozen-lockfile` at the root. Copy the safe mobile `.env.example` locally, set `EXPO_PUBLIC_API_URL` to the trusted backend **origin**, then `pnpm --dir apps/mobile start`. Environment variables with EXPO_PUBLIC are embedded public configuration, never secrets. The app fails closed if the URL is missing, has credentials/path/query/fragment, or uses an unsupported scheme.

Production requires HTTPS. Local HTTP additionally requires an Expo development build, `EXPO_PUBLIC_ALLOW_LOCAL_HTTP=true` and an explicit allowed local address (localhost, loopback, Android-emulator host 10.0.2.2, 10/8 or 192.168/16 IPv4). Use a trusted local HTTPS endpoint on physical devices where OS transport policy blocks HTTP. Release builds cannot enable HTTP through the flag. No TLS validation bypass exists. Keep test/production backend origins separate; no DB credentials belong in the app. The production example is deliberately non-routable and must be replaced by the operator.

Generated `ios`, `android`, `.expo`, bundles and credentials are ignored. `expo prebuild --no-install` regenerates native projects from app.json/config plugins. Project identifiers `studio.gt.member` are foundation placeholders requiring ownership confirmation before store signing/distribution.

## Identity and organization boundary

Activation, token login, forgot/reset password and password change use `/api/v1/member-auth/*` and `/api/v1/member/*`. There is no second identity system. Invitations link the existing StudioMember; neither it nor a trainer profile supplies staff authority. Tokens are restricted by GT-011 and have the server's 30-day expiry. There is no refresh-token loop.

Only the bearer is persisted, using `WHEN_UNLOCKED_THIS_DEVICE_ONLY`. SecureStore operations are serialized. Passwords are temporary form values cleared after every submission. Capability tokens remain transient for correction after a failed activation/reset and clear after successful consumption or mode cancellation; no AsyncStorage, persistent domain cache, analytics, crash-upload SDK or request logging is introduced. OS Keychain may survive iOS reinstall: restoration always verifies the token with `/account`. Platform backup/reinstall behavior and physical device secure-storage behavior require device acceptance testing.

Cold start clears memory and checks the stored credential. Valid credentials restore an account; 401 clears credentials, account and query data. Connectivity/storage failures show a verification/retry/sign-out screen without revealing unverified cached member data. 403 clears the selected studio and forces fresh selection; a 403 while restoring an unverified account clears to sign-in. Error screens retain an explicit studio-exit action even when a removed member link returns 404. A stale 401 from an old bearer cannot revoke a newer account locally. Epoch checks prevent late restore/login/studio-switch responses from restoring prior state.

Logout starts server revocation with the captured bearer and immediately clears local identity, secure storage, cache and device lifecycle. If offline, local logout still happens and the UI explains server revocation was not confirmed; the server token retains its expiry. Password change revokes all sessions/tokens server-side and clears the app. There is no automatic retry of account mutations.

Studio discovery is paginated (25/page). Selection posts to the existing switch endpoint. Clear/cancel all queries and unmount the prior navigator before loading another studio. Query keys contain account UUID, studio UUID, resource and page/filter. Route identifiers are selectors only; the server validates every request. All private data remains server-owned.

## Member experience

Home links to sessions, groups, activity, inbox and profile. It loads a bounded overview of upcoming reservations, membership, waiting entries, unread count and recent attendance. The membership overview examines the first history page and says when no active record appears **in that overview**; full paginated history remains available.

Discovery uses the server's default 56-day organization-local window and paginated groups/sessions. Details show snapshots, trainer/location, both times and the named timezone, server availability, current reservation/waiting/attendance state and eligible actions. Weekly rules stay wall-clock minutes with inclusive dates. No DST, capacity or membership validity rule is reimplemented.

Reserve and join waiting list are distinct actions. No optimistic confirmed state exists. Duplicate taps are guarded while a mutation/refetch is pending. Conflict, validation and ambiguous network failure trigger server refetch, never a replayed POST. If refetch fails, authoritative data/actions stay hidden and explicit retry is offered. Cancellation requires a destructive confirmation. Waiting views show only the member's own status; no fabricated FIFO position appears.

Attendance, memberships, balances, cash and reversals are read-only paginated server representations. Notification inbox/detail/unread count, first read/bounded page read and both preferences use GT-010 delivered inbox endpoints. Historical notices explicitly tell members to check current session state. No staff notes/audit metadata/tokens are rendered.

Macedonian is the bootstrap default; English is fully available. The selected StudioMember profile's preferred locale initializes the UI and updates through the safe profile API. Notification requests carry Accept-Language. Dates use the record's IANA timezone (payments use organization timezone), not the phone's timezone. Monetary minor units are formatted, never allocated or recomputed locally.

## Cache and network

Queries have staleTime=0, retry=false, 60-second inactive garbage collection and at most 40 inactive entries. Active screens use a bounded number of 25-row queries. Pull refresh and foreground focus refetch. Loading, refetch and error states hide authoritative content rather than rendering old availability/financial state as current. Every domain mutation invalidates its account/studio prefix. Domain queries consume AbortSignal. Organization discovery currently relies on account-scoped keys and query removal rather than transport cancellation. All cached queries are removed on identity/context changes.

The client applies a 15-second timeout, captured bearer, fixed origin, JSON, omitted cookies, requested redirect rejection and safe status categories. It never displays server exception bodies or logs credentials/PII. Expected 401/403/404/409/422/429/connectivity/server states have MK/EN messages. There is no offline mutation queue and no automatic POST retry.

## Deep links

Accepted routes are `gtstudio://activate#email=...&token=...`, `gtstudio://reset#email=...&token=...`, `gtstudio://session/{organization UUID}/{session UUID}` and `gtstudio://notification/{organization UUID}/{notification UUID}`. Length is bounded to 2048; UUIDs, supported route kinds, two normalized nonempty UUID path segments and 64-character capability fragments are validated. Query-string secrets, duplicate fields, unknown routes and malformed IDs are rejected. Links do not grant identity or studio access. Cross-studio destinations force studio selection. Activation/reset links while authenticated are rejected rather than silently replacing account identity.

Existing email links remain the GT-011 HTTPS web activation/reset routes. Store/domain association files and production app links are not configured: operators must verify a production domain before enabling universal/app-link dispatch. The native scheme accepts the same fragment payload; users can also enter email/token in the native activation/reset forms. This does not claim that production email taps automatically open the native app. There are no arbitrary URL opens or WebViews.

## Push boundary

GT-010 currently publishes the in-app inbox. `DeviceLifecycle` separates permission/native token acquisition from an authenticated `DeviceRegistry` register/revoke adapter. Tests cover disabled setup, rotation and logout during registration. The native adapter uses Expo Notifications only at the platform boundary. Core domain logic has no APNs/Firebase/Expo transport assumptions.

The registry is deliberately **null**: the user-facing button explains that push is not configured, never requests a useless permission, never invents a registration and never claims delivery. Native notification responses accept only the validated `data.url` route contract. No device token is persisted or sent to a nonexistent endpoint. Thus no backend device schema or migration is needed in GT-012. Before enabling production push, implement/review account-owned authenticated registration/revocation, safe rotation, provider credentials, invalid-token cleanup, bounded concurrency tests and actual device delivery. Inbox operation does not depend on push permission.

## Accessibility and acceptance limits

Screens use safe areas, scrolling/keyboard avoidance, wrapping action rows, 48px minimum buttons, readable text, default system text scaling, labels/roles, disabled state and spoken error regions. State labels convey meaning without color. Component fixtures exercise 320- and 430-point screen inputs; these are behavioral tests, **not native layout screenshots or device accessibility certification**.

Before distribution, run the A–I flow on physical iOS/Android or simulators, including VoiceOver/TalkBack, keyboard, large text, background/foreground, rotation, network interruption, secure-storage reinstall/backup and trusted-domain links. This host has no adb/emulator/Xcode, signing identities or push credentials. No unavailable platform/signing/delivery test is claimed passed.

## Verification and operations

Run `pnpm mobile:check` for mobile TypeScript, Jest, Expo Doctor and both Hermes platform exports. Use `pnpm --dir apps/mobile run doctor` explicitly: `pnpm doctor` is pnpm's own diagnostic command. Run `pnpm --dir apps/mobile exec expo prebuild --no-install` for native config generation without compiling or installing pods. On configured hosts, use `pnpm --dir apps/mobile exec expo run:ios` or `pnpm --dir apps/mobile exec expo run:android` to compile a development build. iOS compilation requires macOS/Xcode/CocoaPods; Android requires the Android SDK/JDK. Use development builds for native integration acceptance; Expo Go does not support remote push functionality, and its SDK warning may appear in Jest without any registration or delivery occurring. Signing and store release require separate explicit operator authorization.

Run the complete repository verification and `pnpm test:e2e`. PHPUnit and browser/native API flows share only guarded local gt_test and must run sequentially. Native API tests use independent fixtures and the production mobile API/session classes over HTTP; they are not native-device tests. Test-only invitation/reset capture is outside tracked source, behind the existing e2e/gt_test/loopback guard; it never changes production routes.

The scoped `xcode>uuid: 11.1.1` override removes GHSA-w5hq-g745-h8pq while retaining xcode's used CommonJS v4 API. Native prebuild verifies that integration. Review/remove the override when upstream fixes its dependency. All other dependency versions remain lockfile controlled; no audit suppression is used.

## GT-013 camera check-in

Home/Activity now include a contextual QR scanner using Expo Camera. The app strictly parses `GTQR1` envelopes, suppresses duplicate scan events, requires server confirmation and displays own QR history. Camera access is scoped to the focused foreground screen; no media or microphone capture is used. Permission denial and network ambiguity offer manual fallback or server attendance verification. See [secure check-in contract](../domain/secure-check-in.md). Trainer QR/roster management remains in responsive web.

## GT-014 studio settings

GT-014 retains server can_reserve/can_join_waiting/can_cancel authority and adds a tenant/account-scoped settings query to native check-in. It fails closed before camera use, refreshes on screen focus and supports manual refresh; app foreground query refresh remains in effect. A stale client still faces current server policy. Manual staff help remains available. No native settings administration is added. See [settings](../domain/studio-settings.md).

## GT-015 push registration and communication

The earlier null push registry is replaced by an authenticated server adapter when `EXPO_PUBLIC_EAS_PROJECT_ID` is configured. Expo project-token acquisition, permission denial, token-change refresh, serialized registration, original-credential cleanup and account/organization-bound registration are implemented. A restored authorized permission refreshes registration; no configured project means no permission request. Only installation UUID and existing account credentials use SecureStore; no member query data is persisted. The native profile exposes all seven preferences; inbox includes mark-all-read and authoritative source navigation. See [communication security/limits](../domain/communication-engine.md), [worker/provider setup](../operations/notifications.md), and the GT-015 completion report. Physical push display remains unverified.

## GT-017 staff reports

Staff Operations links to native StaffReports when `reports.view` is granted. Financial/member/operational gates remain server-enforced. Native charts have exact row alternatives, filters, comparisons, pagination and MK/EN. Authenticated CSV/XLSX retrieval uses Expo FileSystem app-private cache and Sharing, with 30-second request timeout, 25-MiB bound, generation guard and cleanup. No report data or credentials are written to public storage. Old staff tokens require login to gain the new ability ceiling; it does not grant membership permissions. Physical-device sharing remains unverified. See [user guide](../operations/reporting.md).

GT-017 development-server default: `pnpm --dir apps/mobile start` binds Expo to localhost. For intentional physical-device development on a trusted network use `pnpm --dir apps/mobile exec expo start --lan`. No shared firewall/service configuration was changed. The [dependency review](../security/GT-017_DEPENDENCY_REVIEW.md) describes the unpatched tooling advisories and the current no-signing configuration.
