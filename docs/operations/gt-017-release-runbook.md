# GT-017 private local release and recovery runbook

Scope confirmed by the user on 12 October 2026: **local use only**. This is a reviewed procedure and an isolated exercise, not authorization to expose a site, change shared Caddy/PHP/PostgreSQL/Redis/firewall/DNS, or enable providers/signing. Current security policy is denied pending the separate dependency decision in the [review](../security/GT-017_DEPENDENCY_REVIEW.md).

## Verified boundary

The exercise runs as gunner on hp-server. `tests/Support/release-candidate.py` creates a private directory under `.local/gt017-closeout/release-TIMESTAMP`, rejects occupied ports, and provisions two disposable PostgreSQL18 clusters on loopback55437/55438, both named gt_test. It never reads an existing environment file. Synthetic browser-domain fixtures are created while isolated; the HTTP candidate then uses **APP_ENV=production**, debug off, cached configuration/routes/views, database sessions/cache/queues, secure cookies, real authentication/CSRF, and disabled external transports. The PHP backend binds127.0.0.1:8018; a temporary TLS gateway binds127.0.0.1:8017. Its one-day certificate is trusted only by that verification client, never installed into a machine/browser trust store. The backend router marks HTTPS only inside this fixed loopback gateway boundary; it does not disable middleware.

The gateway and both clusters stop in `finally`. This is not a supervised persistent service or production web server. The user must separately choose and authorize any persistent local access mechanism. Do not expose PHP's development server or the temporary gateway publicly. The shared server guide remains authoritative for any future administrative work.

## Reproduce the reviewed candidate

From the clean reviewed revision, as gunner:

```sh
git status --porcelain=v1 --untracked-files=all
git log -1 --format='%H %s'
composer install --no-interaction --prefer-dist
pnpm install --frozen-lockfile
pnpm build
python3 tests/Support/release-candidate.py
```

Do not use this helper against an arbitrary database or move its synthetic credentials into ordinary development accounts. Every run creates new evidence rather than overwriting a prior run. The result records source HEAD, a full source-file SHA-256 manifest, runtime archive checksum, backup checksum, restored row/constraint/grant results and actual smoke outcomes. A dirty pre-commit exercise is identified by HEAD **plus** its manifest, never falsely claimed to be an exact committed tree. After source changes, rebuild/reverify affected artifacts; hosted recovery builds from its exact checkout.

The runtime archive contains PHP application files, locked **non-development Composer** dependencies, views/locales/migrations and built public assets. It contains no node_modules, Expo/Jest, tests, source maps, environment files or cached configuration secrets. Node packages remain on the isolated build/verification host; their audit still fails. Never serve the repository root or add a public storage symlink to report exports. Laravel's private report directory remains private, with cleanup on response/error plus `gt:reports:cleanup` for expired files.

## Configuration and ownership prerequisites

For a future persistent **local** candidate, use a dedicated private configuration file outside the served public directory, owner-only mode0600, and a dedicated release path with write access only to storage and bootstrap/cache. Do not reuse another site's runtime identity or service. The verified exercise uses gunner only because all processes and data are disposable and user-owned; it does not prove a separate persistent runtime account is provisioned.

Required values/decisions, not a provisioned environment:

| Setting              | Required local release value / control                                                                                                                                                |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| APP_ENV / APP_DEBUG  | production / false; no testing/e2e HTTP deployment                                                                                                                                    |
| APP_KEY              | Newly generated private key; back up privately, preserve across restores                                                                                                              |
| APP_URL / host/proxy | Exact authorized local TLS origin; no guessed domain or broad trusted proxies                                                                                                         |
| Session              | Database, secure cookies, HttpOnly, SameSite=lax, JSON serialization; production enforces Secure even if an environment flag tries to disable it                                      |
| Database             | Dedicated database; runtime DML role separate from migration/object owner; no superuser/CREATEDB/CREATEROLE in runtime                                                                |
| Cache / Redis        | Database cache used by this candidate; shared Redis untouched. Any future Redis use needs dedicated ACL/key/service review; never FLUSHDB/FLUSHALL                                    |
| Mail / push          | Array mail, NOTIFICATION_EMAIL_ENABLED=false, NOTIFICATION_PUSH_ENABLED=false; no Expo/signing project or real credentials                                                            |
| Logs                 | Existing bounded exception/audit/notification metadata; no request-body, token, SQL binding or export-content logging; access logs must avoid query strings containing private inputs |
| Files                | public/ document root only; private exports/config/backups excluded from web routing and Git                                                                                          |

The exercise transfers schema ownership to gt_migrate (NOLOGIN), grants gt_test CONNECT/USAGE/DML/sequence access, denies runtime mutation of the migrations table, and verifies runtime CREATE TABLE rejection. Restore must explicitly reproduce database CONNECT privileges: an object-only pg_dump does not provision destination database-level rights. Application grants, constraints and immutable ledger/audit triggers remain authoritative. A future operator migration credential requires separate provisioning; none is supplied here.

Existing users acquire no implicit grants. Owner onboarding uses the existing hidden-password provisioning procedure and explicit `gt:organization-create` / `gt:owner-bootstrap` documented in [local setup](../development/local-setup.md); never seed weak/default accounts into a persistent environment. Member links and TrainerProfile never grant staff access.

## Backup and restore evidence / commands

The exercise takes a custom-format, transaction-consistent `pg_dump` of synthetic data, lists it with `pg_restore --list`, restores to the second new cluster, and compares all canonical table rows, ownership/grants, constraint identities/validation flags, normalized CHECK expressions and trigger definitions. CHECK text is normalized by PostgreSQL's planner because dump/restore changes equivalent parentheses/array casts. Matching invalid capacity/status/ledger writes must still fail. Exact financial totals and real CSV/XLSX are verified through the restored application.

Private configuration and a synthetic private file are archived separately and restored byte-for-byte. The runtime archive is extracted independently and key entry/lock/asset bytes are checked. Archives/evidence directories use the owner's private umask; no real member data is used. Locally retained synthetic evidence is not an established production backup schedule. Suggested local evidence retention is seven days after review; deletion requires selecting only this task's known private artifacts.

For an authorized future persistent owner, substitute **reviewed** destination/service names and private `.pgpass`/PGSERVICE configuration—never passwords in command arguments:

```sh
# Record source/artifact checksums and migration plan before a change.
git log -1 --format='%H %s'
php artisan migrate --pretend --no-interaction
umask 077
pg_dump --dbname='service=gt_backup' --format=custom --file="$GT_BACKUP_DIR/database.dump"
pg_restore --list "$GT_BACKUP_DIR/database.dump" > "$GT_BACKUP_DIR/database.list"
sha256sum "$GT_BACKUP_DIR/database.dump" > "$GT_BACKUP_DIR/SHA256SUMS"
# Restore only into a newly provisioned isolated recovery database.
pg_restore --dbname='service=gt_recovery' --role=gt_migrate --exit-on-error "$GT_BACKUP_DIR/database.dump"
```

`GT_BACKUP_DIR`, service definitions, owner, retention and private configuration/upload backup paths must be provisioned before these commands are used outside the synthetic helper. A successful scheduled backup is insufficient: periodically repeat restore, canonical comparisons and authenticated smoke checks. RPO is the last verified consistent database snapshot; no point-in-time/WAL recovery system is claimed. Coordinate persistent uploads/configuration with the database snapshot, retaining the correct APP_KEY. There are no canonical uploaded files in the exercised domain; report files are disposable and must not become public backup artifacts.

## Activation, health and rollback

No activation is authorized here. A future operator should prepare an immutable release, back up the current state, inspect cumulative migrations from the **actual installed** version, apply compatible migrations using the migration owner, switch the reviewed release pointer atomically, rebuild private config caches with runtime ownership, and restart only this application's supervised processes. Verify `/up`, built assets, real login/CSRF, explicit denials, exact report/export totals and queue/scheduler health. The temporary local exercise proves these application checks, not persistent supervision, disk alerts, public TLS or external backups.

Existing worker contract:

```sh
php artisan schedule:list
php artisan gt:notifications:queue
php artisan queue:work database --queue=notifications --tries=3 --timeout=60
php artisan gt:reports:cleanup
php artisan gt:finance:check
php artisan gt:attendance:check
php artisan gt:reservations:check
```

Keep notification generation allowlists explicit. Worker retry-after must exceed60seconds; the current connection uses90seconds and delivery leases120seconds. Monitor process health, queue age/failures, safe error codes, disk space/private export age and backup/restore freshness. Automated verification uses in-app/array behavior only. Real provider delivery requires its own authorized recipient/configuration test.

Rollback source/assets by restoring the prior verified immutable artifact and its matching private configuration, then rerun smoke/integrity checks. Do not run migrate:fresh or destructive down migrations on persistent data. GT-017/this maintenance add no schema migration. Older populated migrations such as GT-015 deliberately refuse unsafe downgrade; use a reviewed forward repair. Restore a database backup only as an explicit owner-approved disaster-recovery decision with a declared recovery point and write freeze, since it discards newer writes. First-install recovery can discard only the newly created, confirmed disposable installation.

## External native/provider acceptance script

These are not gates for the user-confirmed local web surface. They remain mandatory before any separately authorized native distribution or real provider launch. This Linux host has no adb/emulator/Android SDK, lacks KVM access for gunner, and has no Xcode or enrolled physical device; no access workaround was attempted.

On an authorized device/build: record OS/device, source revision and build identity; sign in as scoped staff; open reports in MK/EN; download and share CSV/XLSX to a controlled local destination; independently parse exact values; exercise background/resume, network loss/retry, logout and studio/account switches during download; confirm stale data/share is blocked and private cache files disappear on success/failure/logout. Record actual OS share completion, not merely API invocation. For providers, use an explicitly approved synthetic account/recipient, record permission states, token rotation/reinstall/revocation, Expo ticket/receipt and actual display/tap or email arrival, and test failures. Do not obtain signing/provider credentials or send a message as incidental verification.
