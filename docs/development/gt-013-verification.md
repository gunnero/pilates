# GT-013 verification

Base: `b4a3fbeb9732a0b2b5e0e524dd862aa19fd78bca` (`GT-012: establish mobile member app`), clean main before edits. GT-013 preserves GT-008 as the authoritative attendance domain. No migration, new data table, secret, permission grant, background job or shared-service change is introduced. No development database migration/reset/reseed was run. Prior migrations and grants are untouched. Full PostgreSQL tests include the populated GT-002–GT-011 preservation regressions; GT-012 introduced no schema. PHP and browser database suites run sequentially against guarded loopback gt_test/gt_test only.

## Commands and results

| Command                                                  | Result                                                                                         |
| -------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| `composer validate --strict`                             | Pass                                                                                           |
| `composer check`                                         | Pint, Larastan level 8; 261 tests / 2,670 assertions pass                                      |
| `pnpm typecheck`                                         | Pass                                                                                           |
| `pnpm lint`                                              | Pass                                                                                           |
| `pnpm format:check`                                      | Pass                                                                                           |
| `pnpm test`                                              | 99 web tests / 13 files pass                                                                   |
| `pnpm test:e2e`                                          | 18 browser/API flows pass                                                                      |
| `pnpm build`                                             | Production web build passes                                                                    |
| `pnpm mobile:check`                                      | Mobile TypeScript, 66 tests / 3 suites, Expo Doctor 21/21, iOS and Android Hermes bundles pass |
| `pnpm --dir apps/mobile exec expo prebuild --no-install` | Both native projects generated; camera permission present, microphone permission absent        |
| `composer audit --locked`                                | No advisories                                                                                  |
| `pnpm audit`                                             | No known vulnerabilities                                                                       |
| `pnpm peers check`                                       | No peer dependency issues                                                                      |
| `scripts/secrets-check`                                  | Pass                                                                                           |
| `git diff --check`                                       | Pass                                                                                           |

Final browser result: `pnpm test:e2e` passed all 18 flows (desktop and iPhone-sized Chromium). Codex Security completed with zero findings; see [GT-013 security review](../security/gt-013-review.md). Private execution logs are under `/tmp/gt013-*.log`; build/native output and browser screenshots remain ignored. Initial failures were fixed, not suppressed: missing organization relation in QR envelope construction; test fixtures that violated immutable account-link/cancellation/DST constraints; inactive mock AppState; assertion/configuration mistakes; browser full-page navigation resetting locale. No invariant was weakened to obtain green tests.

## New coverage

`MemberCheckInTest` has 26 cases: explicit success/outcome/method/history/audit idempotence, rotated replay, forged/modified/expired/unsupported/future/overlong/wrong-session/wrong-tenant credentials, request bounds/unknown actor fields, current member.write, inactive/unlinked access, cancelled reservation/session, staff-only identity, waiting without promotion and member rate limiting. Six exact window points cover before opening, opening, start, just before closing, closing and after closing. Four DST instants span the spring jump and both autumn 02:30 instants in Europe/Skopje, with host TZ America/New_York while preserving the established application UTC configuration. PHP timezone is not reconfigured away from UTC.

`MemberCheckInConcurrencyTest` uses six pairs of real PostgreSQL worker processes, verifies both blocked at the organization lock, then releases them together: same QR, overlapping rotated QR, QR/manual present, QR/reservation cancellation, QR/session cancellation, walk-in/final-slot reservation. Unique attendance, valid lifecycle, capacity and waiting invariants plus integrity commands pass. Credentials use worker stdin, not process arguments/output.

Web additions check bounded 10-second roster refresh, server QR method display, 30-second rotation and immediate removal on renewal failure. Existing manual/walk-in/correction/permission/cancelled-state and localization tests remain active. Mobile additions test strict parsing, permission restriction/denial, contextual camera start, duplicate-event suppression, server success/already-present results, bounded localized domain rejection, foreign studio, ambiguous network verification and stale callbacks after unmount. Existing mobile authentication expiry, organization/cache isolation, MK/EN, accessible controls and 320/430-point component tests remain active.

## Representative flows and limits

A–F use `tests/browser/check-in.spec.ts`: actual trainer display response -> native parser and MemberApi -> PostgreSQL attendance -> polling web roster, idempotent repeat, fresh rotated credential, deterministic expired fixture, foreign organization, waiting and unreserved rejection. G–H use the existing attendance browser flow for offline manual present and atomic walk-in. I uses the real competing-process backend races. J uses native camera-adapter permission tests. K runs desktop and iPhone-sized Chromium. This is the strongest available integrated equivalent, not a claim of physical native scanning.

No adb, Android emulator, xcodebuild or iOS simulator is available. Physical camera focus/QR recognition, OS permission dialogs, actual native layouts/accessibility, signed native compilation and device networking remain unexecuted. Expo bundles, prebuild/manifest inspection and mock camera events validate code/configuration only. No images/video are captured or persisted by the scanner. Browser screenshots hide the live QR first; traces are disabled.

Final screenshot inspection confirmed the mobile trainer session, QR method, roster and manual fallback controls remain readable without horizontal overflow. QR credentials were hidden before screenshots.
