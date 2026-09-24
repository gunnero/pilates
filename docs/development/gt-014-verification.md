# GT-014 verification

Prerequisite base: `a1597f6cdbb52ace60cc2d8a7e768005877cedc8`, exact message `GT-013: establish secure member check-in`. GT-013 was one commit above GT-012, available checks were recorded passing, and the tree was clean before GT-014. Target branch main. Required GT-014 message: `GT-014: establish studio settings`. No push, earlier-commit amendment or release/deployment work is included.

## Implementation

See [typed settings catalog and contracts](../domain/studio-settings.md) for all 16 defaults/types/bounds, strict update/reset semantics, general identity/locale/timezone, organization-wide permissions, member policies, FIFO preservation, QR controls, notification deduplication, immutable commercial history and cache behavior. One additive migration creates optional studio_settings; existing organizations need no row. No grants are expanded.

## Verification results

| Command                                                | Result                                                                                                                 |
| ------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------- |
| composer validate --strict                             | Pass                                                                                                                   |
| composer check                                         | Pass: Pint, Larastan level 8, 318 PostgreSQL tests / 3035 assertions                                                   |
| pnpm typecheck                                         | Pass                                                                                                                   |
| pnpm lint                                              | Pass                                                                                                                   |
| pnpm format:check                                      | Pass                                                                                                                   |
| pnpm test                                              | Pass: 103 tests, 14 files                                                                                              |
| pnpm build                                             | Pass: production Vite bundle                                                                                           |
| pnpm mobile:check                                      | Pass: native typecheck, 68 tests / 3 suites, Expo Doctor 21/21, iOS and Android Hermes export                          |
| pnpm --dir apps/mobile exec expo prebuild --no-install | Pass: iOS/Android native projects generated; Android CAMERA and iOS camera usage present, microphone permission absent |
| pnpm test:e2e                                          | Pass: 20 flows, desktop and mobile-sized Chromium                                                                      |
| composer audit --locked                                | Pass: no security advisories                                                                                           |
| pnpm audit                                             | Pass: no known vulnerabilities                                                                                         |
| pnpm peers check                                       | Pass: no peer dependency issues                                                                                        |
| scripts/secrets-check                                  | Pass: 473 source files                                                                                                 |
| git diff --check                                       | Pass                                                                                                                   |

PHPUnit and browser tests ran sequentially against guarded local gt_test under gt_test role. No tests touched gt_dev. Backend logs: `/tmp/gt014-composer-final.log`; web/mobile logs: `/tmp/gt014-web-build.log`, `/tmp/gt014-mobile-check.log`; browser log: `/tmp/gt014-e2e-final.log`. Logs and generated bundles/native projects/screenshots are local ignored artifacts, not commit contents.

GT-014 backend coverage includes all default values without creating a row, override/reset/no-op audit, finite type/bounds/unknown-key/size/shape rejection, tenant IDOR, staff/member/token authorization, QR relational limits, reservation horizon/cutoff, cancellation boundary and toggle, waiting admission/FIFO preservation, current QR disable/window/TTL with manual fallback, reminder/expiry lead toggles and deduplication, pending delivery suppression, membership presentation without commercial mutation, locale precedence, safe/unsafe timezone and PostgreSQL foreign-key/unique/bounds checks. Existing milestone suites remain included.

Ten GT-014 real competing-process tests cover both queued orders for same-setting admins, member booking versus disablement, cancellation versus cutoff, QR versus disablement and reminder generation versus disablement. Both workers must be observed waiting on actual PostgreSQL locks. Same-key edits preserve final audit order; domain actions obey the serialized policy; generation remains at most once. Existing allocation/attendance integrity commands run within these tests.

Web tests cover sections/default provenance, typed partial save and confirmation, reset, MK/EN, permission denial, validation and preserved input. Native tests cover disabled server action flags, QR controls and policy refresh. Existing scanner, authentication, cache, logout and rejection tests remain intact. Browser flows include desktop and mobile-sized settings UI, defaults, real web/native API rejection, reminder lead/deduplication, cancellation cutoff, disabled/windowed QR, manual fallback, locale precedence, safe/unsafe timezone, member denial and foreign-tenant IDOR. Boundary-time proof is deterministic PostgreSQL testing rather than wall-clock browser waits.

## Migration and data preservation

Inspected `php artisan migrate --pretend --no-interaction` before applying. The only planned schema change was new studio_settings with its tenant foreign key/uniqueness and typed/relational CHECKs. No extension, shared service changes, default backfill, grant update or destructive old-table DDL was required.

Private backup `.local/backups/gt-dev-before-gt014-20260924-183045.dump` was created with mode 0600 and validated via pg_restore --list. Ignored `.local/gt014-migrate.php` guarded loopback port 55432, gt_dev database/role and uncached local config; it bootstrapped credentials internally without printing them, snapshotted 28 prior tables, applied migrate and compared every count/SHA-256 digest. `.local/gt014-migration-result.json` records exact equality. The development tables were empty; no seeded-data preservation is claimed for that database.

`StudioSettingsMigrationTest` supplies the populated proof: actual sessions, reservations, attendance, sold membership, cash payment, reversal and delivered notification evidence survive rollback of only GT-014/reapply unchanged, along with prior grants and other tables. Applying restrictive settings afterward changes only settings and their intentional audit records. Older migration-regression rollback depths were increased by one to retain their historical starting schemas.

## Security and limitations

[GT-014 review](../security/gt-014-review.md) records the manual and independent reviews, exact Codex Security scan/base, no plausible candidates, generated-inventory omissions, post-snapshot compatibility edits and the sealed tool's stale partial-coverage checkpoint. This tooling limitation is reported rather than hidden.

No simulator/device runtime or signing environment is available; native typecheck/tests/bundles/prebuild are verified, but no physical camera/installed app certification is claimed. Production HTTPS, key custody, external providers, cron installation, deployment and release hardening remain unperformed. Existing member locale values remain preferences; no nullable “follow studio” preference mode is added. More permissive timezone migration, optimistic setting versions, policy caching, arbitrary configuration and deployment are explicitly deferred.

Final browser screenshots `.local/gt014-desktop.png` and `.local/gt014-mobile.png` were visually inspected. Both render bounded controls, defaults and sections without horizontal overflow. The first browser run exposed obsolete timezone-fill and authorization-status assertions; the corrected complete rerun passed all 20 flows.
