# GT-009 verification and completion record

Baseline: clean main at `b5000ca7ba29fc2cb71a40e98cdf4fe95b123ae5`, tracking origin/main and already two local commits ahead (GT-007 and GT-008). GT-009 adds exactly one local commit: `GT-009: establish memberships and cash payments`. The resulting hash is in the completion response. No push, remote changes, deployment or GT-010 work.

## Delivered

Four tenant-owned domains: MembershipPlan, MemberMembership, CashPayment and PaymentReversal. See [complete domain/API contract](../domain/cash-payments.md) for inclusive local periods, calendar-month clamp policy, commercial snapshots, immutable ledger, partial/multiple payments, overpayment, full reversal, retry keys, independent permissions, explicit adoption and query foundations. Responsive English/Macedonian plans, membership directory and client-profile finance are implemented. Membership validity never allocates capacity or gates reservations/attendance.

## Commands and results

| Command                                          | Result                                                        |
| ------------------------------------------------ | ------------------------------------------------------------- |
| `composer validate --strict`                     | Valid                                                         |
| `composer check`                                 | Pint, Larastan level 8, 193 tests / 1,914 assertions passed   |
| `pnpm typecheck`                                 | Passed                                                        |
| `pnpm lint`                                      | Passed                                                        |
| `pnpm format:check`                              | Passed                                                        |
| `pnpm test`                                      | 79 tests in 10 files passed                                   |
| `pnpm build`                                     | Passed, 71 modules                                            |
| `pnpm test:e2e`                                  | 10 flows passed, desktop and mobile                           |
| `composer audit --locked`                        | No security advisories                                        |
| `pnpm audit`                                     | No known vulnerabilities                                      |
| `pnpm peers check`                               | No peer dependency issues                                     |
| `scripts/secrets-check`                          | Passed                                                        |
| `git diff --check`                               | Passed                                                        |
| `php artisan migrate --pretend --no-interaction` | Additive migration plan inspected                             |
| `php .local/gt009-migrate.php`                   | Private backup, migration and all preservation digests passed |
| `php artisan gt:finance:check`                   | Five development consistency counts zero                      |
| `php artisan gt:finance:check --env=e2e`         | Five populated browser-database consistency counts zero       |

Backend result: **193 tests passed, 1,914 assertions**. This adds 20 tests to GT-008: 15 financial API/date/integrity tests, four real competing-process PostgreSQL cases and one populated preservation regression. Earlier tenant, reservation/capacity, attendance, audit and concurrency regressions remain in the full suite. PHP and browser tests ran sequentially against local gt_test as gt_test, never gt_dev.

## Evidence

Financial backend coverage includes plan lifecycle/validation, price snapshots, local validity boundaries and leap/month dates, inactive plan/customer denial, overlaps and cancellation, zero/partial/exact/overpayments, integer inputs, immutable cash, full/duplicate reversal, foreign identifiers, mass assignment, independent grants and field omission, selected-location deliberate financial authority, private metrics, explicit adoption, post-lock grant revocation, forced audit failure rollback, database constraints and retained audit evidence.

Four competing PHP processes tests hold the organization lock in the parent until both worker connections are observed waiting in pg_stat_activity: duplicate reversal, payment/cancellation, duplicate overlapping assignment, and payment/reversal. Exactly one duplicate reversal/overlap succeeds; payment/cancellation resolves according to serialized order; final totals match all preserved cash/reversals. Read-only consistency queries independently compare two net calculation methods and check tenant bindings, reversal count/value and period overlap. Zero unexplained mismatches. API tests separately establish absence of unauthorized financial fields; the SQL check does not claim to test HTTP permissions.

Frontend tests cover money parsing, permissions/no private requests, redacted basic membership view, directory filters/balances, membership preview, plan creation, payment confirmation, stable retry identifier on error, required reversal reason and empty states. Browser flows perform login → client → add membership → record cash → verify paid → reverse → verify unpaid/balance → record replacement → verify paid/history → logout on desktop and mobile. Both screenshots were visually inspected and viewport overflow assertions pass. The other eight foundation/session/reservation/attendance flows remain green.

Integration fixes included function recreation after test database rebuilds, the frontend minimal-member display-name shape, explicit new staff-permission translations, updated Payments placeholder assertions and accessible combobox selectors. No domain or security invariant was weakened.

## Migration preservation

`2026_09_24_000001_establish_memberships_cash.php` creates four tables and their tenant-qualified FKs, checks, unique indexes, overlap exclusion, aggregate/query indexes and immutable-history triggers. Only finite permission and audit-subject vocabularies are extended on existing tables; no grants or domain rows are rewritten. Native PostgreSQL range types require no extension or shared service changes.

Before applying to development, the established private backup procedure produced `.local/backups/gt-dev-before-gt009-20260924-154819.dump` (custom archive, mode 0600). `pg_restore --list` verified readability; no full restore drill is claimed. Counts and SHA-256 row digests match before/after across 16 existing tables, including attendance and audit. These development tables were empty. The populated upgrade regression separately preserves organizations, users/staff/grants, clients, trainer/group/rule/session records, reservations, permanent places/releases, waiting entries, attendance and audit. No reset/reseed or automatic permission adoption ran on gt_dev.

## Security and limitations

[Focused security review](../security/gt-009-review.md) records the completed Codex Security diff review (33 source surfaces, no findings), architecture-aware manual checks and precise coverage limits. Dependency and secret checks also passed. The full local scope is not a production penetration test or physical cash reconciliation.

Known limits: duration-only commercial entitlement; MKD only; integer bounded prices/payments; UTC payment-entry form; no custom price, prorations, credits or partial reversal; organization-level writer serialization; financial grants intentionally span the organization; live metrics restate original paid_at periods after reversal; database-owner bypass outside application guarantees. Deferred: card/bank/mobile payments, fiscal receipts/invoices, accounting, refunds to external instruments, advanced reports, notifications and GT-010.

Updated documentation: README, AGENTS, architecture overview/API/authorization, membership/cash domain, audit and threat model, focused security review and this verification record. Private backups, environment files, build output, logs, screenshots and scan artifacts remain outside Git.
