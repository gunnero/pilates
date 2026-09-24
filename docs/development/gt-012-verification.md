# GT-012 verification

GT-011 base: `ce927bb7d15d67ded53499db6b54f24fc0c3750c`, exactly one commit titled `GT-011: establish member self-service`. The prerequisite gate verified the clean main branch, GT-001–GT-011 history, GT-011 verification and member API/auth contracts before edits. GT-012 does not amend prior commits or push.

## Scope and preservation

The change adds `apps/mobile`, a pnpm workspace, CI mobile checks and isolated mobile API test fixtures. It adds **no production backend code, route, migration, permission, grant or stored domain data**. Only test seed/router code changes in PHP. No development database migration, reseed or reset was needed or run. Existing populated migration and concurrency regressions remain enabled in the full PostgreSQL suite. Tests use local gt_test under gt_test; PHP and browser/native API suites ran sequentially. Shared Redis/configuration were untouched.

## Available checks

| Command                                                  | Result                                                             |
| -------------------------------------------------------- | ------------------------------------------------------------------ |
| `composer validate --strict`                             | Pass                                                               |
| `composer check`                                         | Pint, Larastan level 8, 229 tests / 2,426 assertions pass          |
| `pnpm typecheck`                                         | Pass                                                               |
| `pnpm lint`                                              | Pass; native TS included, generated platform trees excluded        |
| `pnpm format:check`                                      | Pass                                                               |
| `pnpm test`                                              | 97 web tests / 13 files pass                                       |
| `pnpm build`                                             | Web production build passes                                        |
| `pnpm test:e2e`                                          | 16 desktop/mobile browser and native API flows pass                |
| `pnpm --dir apps/mobile typecheck`                       | Pass                                                               |
| `pnpm --dir apps/mobile test`                            | 43 tests / 2 suites pass                                           |
| `pnpm --dir apps/mobile bundle`                          | iOS and Android Hermes exports pass                                |
| `pnpm --dir apps/mobile run doctor`                      | Expo Doctor 21/21 checks pass                                      |
| `pnpm --dir apps/mobile exec expo prebuild --no-install` | Both native projects generated successfully; no native compilation |
| `composer audit --locked`                                | No security advisories                                             |
| `pnpm audit`                                             | No known vulnerabilities after scoped xcode UUID patch             |
| `pnpm peers check`                                       | No peer issues                                                     |
| `scripts/secrets-check`                                  | Pass                                                               |
| `git diff --check`                                       | Pass                                                               |

`pnpm mobile:check` combines the four mobile checking commands. Logs are private ignored `.local/gt012-*.log`. No check failure was suppressed: initial Expo test-renderer peer mismatch was fixed using SDK-compatible versions; initial xcode UUID advisory was fixed using CommonJS-compatible 11.1.1 and verified with prebuild. The native API recovery test was limited to one fixture to respect the real shared five-per-minute password limiter, not bypass it.

## Mobile tests and representative flows

Core tests cover configuration/TLS rules, all consumed endpoint wrappers, bounded pagination, safe errors/statuses, bearer/cookie/redirect request options, timeout/no-retry, valid/expired/revoked/offline restore, late identity responses, cache bounds/isolation, malformed/capability links, push disabled/rotation/logout races, MK/EN completeness and Skopje DST display.

Native component tests cover dashboard/navigation at 320/430-point input sizes, discovery/pagination, reservation confirmation, full-session waiting, destructive cancellation, duplicate taps, conflict refresh, ambiguous timeout handling, read-error hiding, own waiting/attendance/membership/payment views, inbox/bulk read/detail localized auth validation with password clearing, profile locale/preferences, disabled push UX, restore-time 403 recovery and removed-studio 404 exit. The core follow-up also verifies that leaving a studio clears private data and invalidates an in-flight selection. Native component layout is mocked; these sizes do not certify actual device layout.

`tests/browser/mobile-api.spec.ts` runs the actual mobile transport and session controller against guarded HTTP/PostgreSQL. Independent fixtures cover A–I: activation/login; sessions/groups; reserve and authoritative refetch; full-session waiting and FIFO promotion; eligible cancellation; studio cache reset; populated attendance/membership/cash/inbox; notification detail/read/preferences; logout/A→B isolation; revoked token rejection and malformed link. Password change and successful forgot/reset consumption also run. Test-only private capability capture remains behind e2e, gt_test role/database and loopback guards. It is not a production route or mailbox.

Existing desktop and iPhone-sized Chromium flows remain unchanged. Real-API mobile flows use the two fixture projects but are **not iOS/Android device execution**. This host has no adb, Android emulator, xcodebuild or iOS simulator. OS secure-storage, VoiceOver/TalkBack, screenshots/keyboard/native networking, signing, universal-link association and provider push delivery remain unavailable and are not claimed passed. Cross-platform bundles and native project generation validate code/configuration only.

## Security and completion

Security review base is the actual GT-011 hash above. See [GT-012 security review](../security/gt-012-review.md) for scan identity, exact coverage, qualifications and manual follow-up. The complete diff, generated-file exclusions, dependency lockfile, test captures and documentation were inspected before the single local commit `GT-012: establish mobile member app`. GT-013 is untouched.
