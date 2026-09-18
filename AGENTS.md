# GT development

Read README.md and docs/architecture/overview.md before extending this foundation.

- Work only in the requested task; GT-001 contains no booking, attendance or payment module.
- PostgreSQL is authoritative. Redis is cache/rate limiting only. Never flush the shared Redis service.
- Every tenant request must authenticate, resolve membership through TenantContext, then authorize a permission before serializing data. No implicit Super Admin bypass.
- Never take organization_id, permissions, role, or actor identity from mass-assigned request input.
- Use UUIDv7 public IDs, UTC timestamps and organization locale/timezone/currency settings.
- Do not read or print .env secrets. Do not run tests against gt_dev or another project's database.
- Tests require local gt_test under role gt_test. PHPUnit and browser tests share that test DB and must run sequentially.
- Keep Laravel/React localization keys in lang/ and resources/js/locales/.
- Before committing: composer check; pnpm typecheck; pnpm lint; pnpm format:check; pnpm test; pnpm build; pnpm test:e2e; composer audit --locked; pnpm audit; scripts/secrets-check.
- Do not push, deploy or alter shared service configuration without explicit task authorization.
