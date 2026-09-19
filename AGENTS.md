# GT development

Read README.md and docs/architecture/overview.md before extending this foundation.

- Work only in the requested task; GT-006 adds durable TrainingSession occurrences; no client-group assignments, reservations or GT-007; no package, booking, attendance or payment module. StudioMember never means staff access.
- PostgreSQL is authoritative. Redis is cache/rate limiting only. Never flush the shared Redis service.
- Every tenant request must authenticate, resolve membership through TenantContext, then authorize a permission before serializing data. No implicit Super Admin bypass.
- Lifecycle mutations must take the organization row lock, re-resolve current authority, and persist audit evidence in the same transaction. Read docs/architecture/organization-lifecycle.md before extending these writers.
- Client access currently requires all-location staff scope and members.view, with independent mutation/notes permissions. Never infer visibility from preferred location. Use JSON body search; keep names/contact/note contents out of logs and audit metadata.
- TrainerProfile never grants authority or client visibility. Reuse current staff location scope and active statuses; future assignment writes must recheck eligibility under the organization lock. Read docs/domain/trainer-profiles.md.
- Weekly schedules use local wall-clock minutes and inclusive effective dates, never permanent UTC clock times. Read docs/domain/training-groups.md. All conflict-sensitive writers use the organization lock; same-group overlap also has a native PostgreSQL exclusion constraint. Preserve rule history via close/replace/cancel, and do not change an organization timezone after rules exist.
- Session materialization uses a bounded 56-day horizon and the shared organization lock. Read docs/domain/training-sessions.md. Preserve recurring identity, snapshots, cancellations and manual exceptions. Session capacity is configured capacity, not availability.
- Never take organization_id, permissions, role, or actor identity from mass-assigned request input.
- Use UUIDv7 public IDs, UTC timestamps and organization locale/timezone/currency settings.
- Do not read or print .env secrets. Do not run tests against gt_dev or another project's database.
- Tests require local gt_test under role gt_test. PHPUnit and browser tests share that test DB and must run sequentially.
- Keep Laravel/React localization keys in lang/ and resources/js/locales/.
- Before committing: composer check; pnpm typecheck; pnpm lint; pnpm format:check; pnpm test; pnpm build; pnpm test:e2e; composer audit --locked; pnpm audit; scripts/secrets-check.
- Do not push, deploy or alter shared service configuration without explicit task authorization.
