# Local development on HP

The initial directory was empty and not a Git repository. GT-001 initializes main locally with no remote. Existing server PHP 8.5.4, Composer 2.10.3, Node 24.20.0, pnpm 12.3.4, PostgreSQL 18.6 and Redis 8.0.5 were inspected before changes. Port 8000 was occupied, so Laravel uses 8010. No existing application was overwritten.

## Dependencies and databases

Run README setup from the repository root. `scripts/local-db init` uses `/usr/lib/postgresql/18/bin` (override PG_BIN for another native installation), private `.local/postgres`, socket `.local/pg-socket`, and loopback 55432. It creates both isolated roles/databases and private environment files. It is intentionally non-destructive and refuses existing data/env files. Use `scripts/local-db start` / `stop` subsequently. There is no system service/autostart. If 55432 is occupied or initialization partially fails, inspect the private cluster and files; do not delete data to force a rerun.

The shared native server on 5432 lacks a gunner role, and sudo requires interactive authentication. A separate native cluster avoids changing shared production databases or requiring Docker. Native Redis on 6379 supplies GT-prefixed cache/rate limiting only. Never call cache:clear/flush on a shared Redis database: prefixing does not make FLUSHDB safe. PostgreSQL holds sessions, jobs and business truth.

Existing managed PostgreSQL can be used by configuring DB_* in private environment files instead. Have an authorized operator provision dedicated development/test roles and databases; do not reuse production endpoints. Test role/database must remain gt_test on loopback, with no DB_URL or cached config. Tests stop before destructive migrations if that safety check fails.

No default account is seeded. To create a local user for manual UI checks, use `php artisan tinker` and Laravel's hidden prompt (no real password in shell history):

```php
$password = \Laravel\Prompts\password('Local-only password (12+ characters)');
$user = \App\Models\User::create(['name' => 'Local admin', 'email' => 'admin@example.test', 'password' => $password]);
unset($password);
```

An account without studio access correctly shows an empty access state. User invitations and membership creation UI are deferred. Trusted organization provisioning uses the commands below. Automated browser tests create a test-only organization/account in gt_test, never gt_dev. No development account was created during GT-001.

## Daily work and checks

Use `composer dev` and `pnpm dev` in separate terminals, open http://127.0.0.1:8010. To inspect from another computer, tunnel these loopback ports using existing authorized SSH access; do not expose public listeners. A production frontend can be exercised locally with `pnpm build` and no Vite process. Always remove a stale public/hot only after confirming Vite has stopped.

`composer check` runs Pint check, Larastan level 8 without suppressions and PHPUnit against PostgreSQL. `pnpm typecheck`, `pnpm lint`, `pnpm format:check`, `pnpm test`, `pnpm build` check frontend source. `composer format` / `pnpm format` fix style. TypeScript 6 is intentional: the current stable typescript-eslint parser does not yet support TypeScript 7.

Browser checks require `pnpm exec playwright install chromium`, followed by `pnpm test:e2e`. They run built assets under APP_ENV=e2e on 8011 with **real CSRF enforcement**, database sessions and database-backed rate limiting. `scripts/prepare-browser` derives a private .env.e2e from .env.testing; its guarded fixture reset can only target gt_test. Run PHPUnit then browser tests sequentially, not concurrently. No Playwright trace archives containing credentials are saved; screenshots show test data only.

HP lacks some Chromium shared libraries. GT-001 extracted official Ubuntu packages under ignored `.local/browser-libs` without installing system packages. To reproduce on this HP:

```bash
mkdir -p .local/browser-debs .local/browser-libs
cd .local/browser-debs
apt-get download libatk1.0-0t64 libatk-bridge2.0-0t64 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 libgbm1 libatspi2.0-0t64 libwayland-server0 libxrender1 libxi6 libxres1
for package in *.deb; do dpkg-deb -x "$package" ../browser-libs; done
cd ../..
pnpm test:e2e
```

`scripts/browser-test` adds that directory to LD_LIBRARY_PATH only for the child test process. Other distributions should use their standard Playwright dependencies. CI installs browser dependencies inside its disposable runner.

## Production boundary

This is local foundation work, not a deployable business launch. Before production: reviewed HTTPS host/proxy configuration, dedicated runtime/migration DB roles, isolated Redis ACL/service, real mail setup, backups/restore verification, log/access-log redaction policy, monitoring, dependency review and customer-access lifecycle. Serve only public/. Do not commit .env files or generated data. Never use APP_ENV=testing for a browser/server deployment; that disables framework CSRF checks.

## GT-002 operator commands

After backing up an existing database, run `php artisan migrate` to apply the incremental migration. Never use migrate:fresh on gt_dev. Existing GT-001 access retains its grants and becomes Staff; it is not automatically promoted. The migration checks legacy controlled values and fails rather than silently altering invalid permissions/settings.

```bash
php artisan gt:organization-create 'Studio name' existing-user@example.test
php artisan gt:owner-bootstrap ORGANIZATION_UUID USER_UUID --reason='GT-001 adoption'
php artisan gt:organization-status ORGANIZATION_UUID suspended --reason='Operator restriction'
php artisan gt:organization-status ORGANIZATION_UUID active --reason='Restriction resolved'
```

These are trusted host-operator capabilities, not ordinary user authorization endpoints. Create uses an existing identity and grants its explicit Owner bundle; it never creates credentials. Bootstrap runs only when no active owner exists and cannot restore a revoked membership. Status recovery requires an active owner. Operator actions record null actor with source=operator and bounded reason where required. Keep reasons free of secrets/personal incident details. No commands create a default password, send invitations, or configure mail. New organizations start with Macedonian, Europe/Skopje and MKD; API settings accept other valid IANA timezones.

## GT-003 adoption

Back up gt_dev, then run `php artisan migrate` for the additive client schema. Existing staff permission arrays do not expand silently. An operator can deliberately grant client capabilities to an existing active all-location owner/administrator using:

```bash
php artisan gt:member-access-enable ORGANIZATION_UUID STAFF_MEMBERSHIP_UUID --reason='GT-003 adoption'
```

The membership UUID is available in the authorized staff directory API. This command creates no credentials, sends no mail and writes bounded system-actor audit evidence. Newly provisioned Owner/Administrator bundles include the six client permissions. Clients can be entered through Клиенти without any User, phone or email. Do not populate real PII in test fixtures, screenshots or development demonstrations.

## GT-004 adoption

Inspect `php artisan migrate --pretend`, take the established private native pg_dump backup, then `php artisan migrate` for the additive trainer schema. Preserve existing organizations, memberships/grants, clients and audit rows; do not reset/reseed gt_dev. The migration does not create trainer profiles or change grants. Deliberately enable trainer management for an existing active all-location Owner/Administrator with:

```bash
php artisan gt:trainer-access-enable ORGANIZATION_UUID STAFF_MEMBERSHIP_UUID --reason='GT-004 adoption'
```

Then use Staff → Enable as trainer for an existing active membership. This creates operational teaching capability only. See [trainer domain](../domain/trainer-profiles.md) for permissions, visibility and future member-facing restrictions.

## GT-005 adoption

Inspect `php artisan migrate --pretend`, back up the guarded local gt_dev with native pg_dump and then apply `php artisan migrate`. Compare existing users, organizations, locations, staff/assignment/grants, clients, trainers and audit records before/after. Never migrate:fresh or reseed the development database. No btree_gist extension installation is needed.

```bash
php artisan gt:group-access-enable ORGANIZATION_UUID STAFF_MEMBERSHIP_UUID --reason='GT-005 adoption'
```

This trusted adoption adds group/schedule view/manage only to an active all-location Owner/Administrator and audits; it creates no group or rule. Other selected-location management grants use existing bounded delegation. Use Групи to create a group at an active location, optionally assign an eligible trainer, and add weekly local-time definitions. Rules are retained through close/replace/cancel. Existing local schedule history prevents ordinary organization timezone changes. No session generation or client assignment is provided.
