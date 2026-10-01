# GT / GrupniTreninzi.mk

A multi-tenant studio platform, initially for Pilates in North Macedonia. GT-001 through GT-014 implement tenant/staff/client management, scheduling, reservations, attendance, cash memberships, notifications, member self-service, the native member app, secure QR check-in and typed studio settings. PostgreSQL owns business invariants; StudioMember never means staff access. Card payments, production push and deployment remain separate work.

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

Read [system overview](docs/architecture/overview.md), [tenant strategy](docs/architecture/tenancy.md), [API conventions](docs/architecture/api.md), [organization lifecycle](docs/architecture/organization-lifecycle.md), [studio-client lifecycle](docs/domain/studio-members.md), [trainer profiles](docs/domain/trainer-profiles.md), [groups and weekly schedules](docs/domain/training-groups.md), [training sessions](docs/domain/training-sessions.md), and [GT-006 verification](docs/development/gt-006-verification.md). [GT-001 verification](docs/development/verification.md) is historical. GT-002 through GT-005 verification records remain historical. GT-007 and GT-008 remain local; no production deployment is included. See [reservations](docs/domain/reservations.md).

## GT-008 attendance extension

GT-008 now implements durable attendance, staff/trainer roster and today workflows, capacity-safe offline walk-ins, no-show finalization, audited corrections and encrypted expiring QR foundations. Earlier attendance deferrals above describe the prior milestone. See [attendance contracts](docs/domain/attendance.md) for authorization, API, privacy, concurrency, lifecycle protection and future member-authentication boundaries. Existing grants are preserved; adoption is explicit. No member check-in HTTP endpoint, payments, mobile app or GT-009 is included.

## GT-009 memberships and cash

[Domain, API and permission contracts](docs/domain/cash-payments.md) cover duration plans, immutable sold periods, partial cash payments, full reversals and responsive membership workflows. No existing staff grants are expanded during migration. Trusted adoption: `php artisan gt:finance-access-enable ORGANIZATION_UUID STAFF_MEMBERSHIP_UUID --reason="Approved adoption"`. Read-only consistency: `php artisan gt:finance:check`. Do not use financial membership state to infer reservation/attendance eligibility.

See [notification contracts and operations](docs/domain/notifications.md) and [GT-010 verification](docs/development/gt-010-verification.md).

## GT-011 member self-service

GT-011 member accounts and responsive self-service are implemented. See [identity, activation and API contracts](docs/domain/member-self-service.md) and [verification](docs/development/gt-011-verification.md). Use `/member/login` for member access. GT-012 is deferred.

GT-012 adds the iOS/Android member app in `apps/mobile`. See [mobile setup and contracts](docs/mobile/overview.md). Run `pnpm mobile:check` alongside the established repository checks. Native signing/device execution and production push delivery require separately configured environments.

GT-013 adds secure member QR check-in, contextual native scanning and a polling trainer attendance roster. See [the secure check-in contract](docs/domain/secure-check-in.md) for operation and limitations.

## GT-014 studio settings

GT-014 adds typed studio policies and responsive administration. Existing tenants retain their previous defaults without a settings row. See [settings catalog, API and operations](docs/domain/studio-settings.md) and [GT-014 verification](docs/development/gt-014-verification.md). Release hardening and deployment remain separate work.

## GT-015 communication engine

GT-015 extends the existing inbox with channel preferences, account-bound native device registration, Expo ticket/receipt delivery, Laravel mail architecture, queued announcements and durable worker recovery. See [domain/API contracts](docs/domain/communication-engine.md), [worker operations](docs/operations/notifications.md) and [completion evidence](docs/tasks/GT-015_COMPLETION.md). External providers are disabled locally; nothing is deployed or pushed.

## GT-016 daily operations

Staff web navigation now includes Daily operations; the native app includes separate trainer/staff sign-in. Both use scoped session/roster/profile projections and existing attendance commands. See [domain/API contracts](docs/domain/operations-dashboard.md), [daily workflows](docs/operations/daily-operations.md), and [verification](docs/tasks/GT-016_COMPLETION.md).
