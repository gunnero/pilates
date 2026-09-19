# GT / GrupniTreninzi.mk

A multi-tenant studio platform, initially for Pilates in North Macedonia. **GT-006 adds durable dated training sessions, bounded recurring materialization, capacity snapshots and an operational agenda.** Authentication, tenant isolation, explicit permissions, transactional audit evidence and localized management screens are implemented. StudioMember is a customer who may have no account, phone or email; OrganizationMembership remains staff access; TrainerProfile optionally adds teaching information without granting access. Booking, attendance, payments and business dashboards remain unimplemented.

## Stack and requirements

Laravel 13.32.0, Sanctum 4.3.3, PHP 8.4+ (verified 8.5.4), PostgreSQL 18, Redis 8, React 19.3.0, TypeScript 6.0.3, Vite 8.3.0. Native Node 24, pnpm 12.3.4, Composer 2 and Python 3 are required. PHP needs pdo_pgsql, mbstring, intl, redis, XML, curl and zip. Lockfiles pin exact dependency resolution. Plain CSS with design tokens keeps this small shell maintainable. No Docker requirement.

## First setup on HP

```bash
cd ~/Sites/pilates
composer install
pnpm install --frozen-lockfile
scripts/local-db init
php artisan migrate
pnpm build
```

`init` generates private `.env` and `.env.testing` files, random keys/credentials, and separate `gt_dev` / `gt_test` PostgreSQL databases. It refuses to overwrite an existing setup. On this checkout setup is already done; use `scripts/local-db start`. Shared Redis must be available on loopback 6379. Never run `redis-cli FLUSHALL`, `FLUSHDB`, or Laravel cache flush against the shared service.

```bash
composer dev           # backend: http://127.0.0.1:8010
pnpm dev               # separate terminal; Vite assets on loopback 5173
```

Open the **backend** URL, not port 5173. Without Vite, `pnpm build` supplies the same app through Laravel. No default accounts or sample studio data are seeded. Authentication UI is usable immediately; trusted local account provisioning is documented in [local setup](docs/development/local-setup.md). Real email is disabled (array transport), so password recovery delivery is not active.

## Checks

```bash
composer validate --strict
composer check         # Pint, Larastan level 8, PostgreSQL PHPUnit tests
pnpm typecheck
pnpm lint
pnpm format:check
pnpm test
pnpm build
pnpm exec playwright install chromium
pnpm test:e2e          # sequentially AFTER PHPUnit; resets ONLY gt_test
composer audit --locked
pnpm audit
pnpm peers check
scripts/secrets-check
git diff --check
```

Formatting: `composer format` and `pnpm format`. CI runs these checks plus Chromium on desktop/mobile viewports. For the headless HP's project-local browser libraries, see [local setup](docs/development/local-setup.md). Tests never need production credentials.

## Repository

```text
app/                  Laravel HTTP, models, permissions and tenant context
bootstrap/, config/   Laravel application and service configuration
database/             PostgreSQL migrations, test factories, empty seeder
lang/                 Backend mk/en catalogs
resources/js/         React shell, routing, i18n, API client and component tests
resources/css/        Responsive visual tokens and styles
resources/views/      Laravel HTML entry point
routes/               Web auth and /api/v1 endpoints
tests/                Backend and real browser security/flow tests
scripts/              Private native DB and verification helpers
docs/                 Architecture, domain, security and development decisions
.github/workflows/    Verification pipeline; no deployment job
```

Read [system overview](docs/architecture/overview.md), [tenant strategy](docs/architecture/tenancy.md), [API conventions](docs/architecture/api.md), [organization lifecycle](docs/architecture/organization-lifecycle.md), [studio-client lifecycle](docs/domain/studio-members.md), [trainer profiles](docs/domain/trainer-profiles.md), [groups and weekly schedules](docs/domain/training-groups.md), [training sessions](docs/domain/training-sessions.md), and [GT-006 verification](docs/development/gt-006-verification.md). [GT-001 verification](docs/development/verification.md) is historical. GT-002 through GT-005 verification records remain historical. No production deployment, remote push, or GT-007 implementation is included.
