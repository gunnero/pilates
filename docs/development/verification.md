# GT-001 verification record

Verified locally on hp-server as gunner, 2026-09-18 UTC. This is local verification, not a claim that hosted GitHub Actions has run. The initially empty directory had no Git metadata/remotes. One initial local commit is created only after the checks below pass; `git log -1` identifies it.

## Exact versions

| Component                               | Verified version                   |
| --------------------------------------- | ---------------------------------- |
| PHP / Composer                          | 8.5.4 / 2.10.3                     |
| Laravel / Sanctum                       | 13.32.0 / 4.3.3                    |
| PostgreSQL client + server / Redis      | 18.6 / 8.0.5                       |
| Node / npm / pnpm                       | 24.20.0 / 11.19.0 / 12.3.4         |
| React / React DOM                       | 19.3.0 / 19.3.0                    |
| TypeScript / Vite / Laravel Vite plugin | 6.0.3 / 8.3.0 / 3.2.0              |
| React Router / i18next / react-i18next  | 7.18.4 / 26.4.2 / 17.0.14          |
| PHPUnit / Larastan / PHPStan / Pint     | 12.5.35 / 3.12.1 / 2.2.14 / 1.32.1 |
| Vitest / Playwright                     | 5.0.1 / 1.63.0                     |
| ESLint / typescript-eslint / Prettier   | 10.10.0 / 8.70.0 / 3.9.8           |

TypeScript 7.0.2 was incompatible with current typescript-eslint; the locked compatible stable version is 6.0.3. `pnpm peers check` reports no remaining peer issues. Styling is plain responsive CSS with centralized visual variables and system fonts, with no styling framework dependency.

## Commands and outcomes

| Command                                           | Outcome                                                                                                       |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| `composer install --no-interaction --prefer-dist` | Locked dependencies install                                                                                   |
| `pnpm install --frozen-lockfile`                  | Locked dependencies install                                                                                   |
| `scripts/local-db init`                           | Private native cluster and separate development/test roles created; env files mode 0600                       |
| `php artisan migrate --no-interaction`            | All 5 initial migrations applied to gt_dev                                                                    |
| `composer validate --strict`                      | Valid manifest and lock                                                                                       |
| `composer check`                                  | Pint passed; Larastan level 8, zero errors/no suppressions; 13 PostgreSQL backend tests, 94 assertions passed |
| `pnpm typecheck`                                  | Passed                                                                                                        |
| `pnpm lint`                                       | Passed                                                                                                        |
| `pnpm format:check`                               | Passed                                                                                                        |
| `pnpm test`                                       | 10 tests across 2 files passed                                                                                |
| `pnpm build`                                      | Production build passed; approximately 321 kB JS / 102 kB gzip and 6.5 kB CSS                                 |
| `pnpm test:e2e`                                   | 2 real Chromium flows passed: desktop and mobile viewport                                                     |
| `composer audit --locked`                         | No known advisories or abandoned packages                                                                     |
| `pnpm audit`                                      | Zero reported advisories at every severity                                                                    |
| `pnpm peers check`                                | No peer dependency issues                                                                                     |
| `scripts/secrets-check`                           | No detected secrets or generated/private source artifacts                                                     |
| `git diff --cached --check`                       | No whitespace errors                                                                                          |

Checks are sequential where they share gt_test. PHPUnit uses PostgreSQL (not SQLite) with array cache/mail/session to remain deterministic. Browser tests reset only guarded gt_test and run APP_ENV=e2e with database sessions/cache, strict built-asset CSP and real CSRF middleware. Login without CSRF returns 419; the valid browser flow signs in, survives reload, selects authorized context, switches language, displays honest placeholders, has no mobile horizontal overflow, and signs out. HttpOnly/SameSite cookie checks and zero page errors pass. Desktop/mobile screenshots were visually inspected; generated screenshots remain ignored.

Backend coverage includes boot/PostgreSQL/UTC, default/English errors, credential hashing/canonicalization, session rotation/logout, validation/login throttling, foreign/unknown tenant denial, permission denial, membership revocation, independent financial grants, token ability intersection/revocation, password reset expiry/single use/session+token revocation, canonical DB constraint, stale context clearing, mass-assignment rejection and safe exception responses/logging.

Additional local checks verified production config resolves secure_cookie=true, debug=false and database sessions; a unique GT-prefixed Redis key completed write/read/delete without flushing shared Redis. Development failed_jobs.failed_at was aligned to timestamptz after finalizing the initial migration convention. Both databases use isolated non-superuser application roles; no production credentials were used.

## Review and limits

Read-only independent architecture review informed the threat model; this was not an exhaustive security scan or penetration test. Real browser verification found and fixed the no-referrer/Sanctum conflict: same-origin referrers are now permitted while cross-origin referrers remain withheld. Session reset honors configured session table/connection; database-backed production sessions are enforced. Password reset URLs use a trusted configured origin and fragments.

No real outgoing mail or development account was created. Test-only browser accounts live in gt_test. No role editor, immutable audit table, tenant RLS, mobile issuance, business schema, production deployment or public exposure is included. Shared PostgreSQL/Redis/service configuration was preserved. Headless Chromium dependencies were extracted under ignored .local only; no system packages installed.

The repository has no remote. Hosted CI remains unexecuted until the owner configures one and explicitly authorizes publication. No push and no GT-002 work occurred.
