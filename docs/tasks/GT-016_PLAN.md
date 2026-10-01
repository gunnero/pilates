# GT-016 implementation plan

Strict baseline verified before product changes: `/home/gunner/Sites/pilates`, branch `main`, HEAD `2b5923a5d345d22813cd7747d128ca65663a6e87`, subject `GT-015: establish notifications and communication`, clean tracked/untracked tree, existing Aleksandar commit identity. Historical migration SHA-256 manifest is private at `.local/gt016/baseline.json`.

## Ownership and scope

| Read/action                                  | Authoritative owner/policy                                                                                                                                                                              |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Today/upcoming, filters, aggregate occupancy | SessionDirectory with TenantContext, sessions.view and attendance.view; trainer assignment and selected-location intersection                                                                           |
| Roster and quick profile                     | Tenant/session-qualified reservations, waiting entries and AttendanceRecord; identity limited to authorized session participation                                                                       |
| Present/absent/no-show/correction/walk-in    | Existing Attendance endpoints/services, AttendancePolicy, Allocation and Lifecycle locks/audit                                                                                                          |
| Membership validity and expiry               | Finance/MemberMembership and StudioSettings; memberships.view or payments.view; no new admission gating                                                                                                 |
| Cash settlement                              | Existing cash/reversal aggregates and payments.view; no debit/credit or cash-confirmation state invented                                                                                                |
| Communication status/follow-up               | GT-015 delivery evidence, all-location organizations.manage; aggregate only, no message/token projection                                                                                                |
| Native staff sign-in                         | Add narrow staff token issuer using AccountSecurity and existing User/staff grants; separate SecureStore credential and mode from member access; server rechecks current TenantContext on every request |
| Web/native UI                                | New operations list, roster, contextual profile and existing action paths; server-derived hints, refresh after success/conflict, no offline writes                                                      |

No historical migration changes, new business ledger, role grant adoption, transport configuration or deployment. Add indexes only if measured PostgreSQL plans justify them. Original task attachments exist in the conversation attachment store; repository delivery reports/domain docs and actual code/tests are the operative compatibility evidence. No missing historical specification is invented.

## Execution

1. Record baseline checks and migration manifest; inspect owning domains and auth/navigation.
2. Implement bounded operations projections and narrow native staff authentication without changing member credentials or existing writers.
3. Integrate MK/EN web and native flows with contextual profiles, actions and stale/conflict recovery.
4. Add tenant/role/field tests, real PostgreSQL races, query-growth/profile fixtures, UI/browser tests at phone/tablet/desktop widths.
5. Run full established backend/web/native/browser checks sequentially for shared test DB, dependency audits, secrets and manual security/preservation review. Document precise limitations.
6. One local commit: `GT-016: implement trainer and studio operations dashboard`; no push, publication or GT-017.

## Validation budgets

Bound list requests to 31 inclusive studio-local dates, 25 rows by default/100 maximum; profiles remain session scoped. Target <=25 queries per dashboard request and <=30 per roster/profile request, invariant with page row count. Measure 20 warmed samples, nearest-rank p95 <=500 ms for daily summary and first roster page, on isolated PostgreSQL fixtures of at least 100 sessions, 100 roster members, waiting demand, financial/notification records and a second tenant. Capture response bytes and EXPLAIN (ANALYZE, BUFFERS); these are local budgets, not production SLAs. Summary reads use a read-only repeatable-read snapshot, without organization write locks.

Concurrency tests use independent processes blocked on actual PostgreSQL locks with bounded deadlines, three repetitions of supported race scenarios, canonical state/audit assertions and cross-tenant progress. Existing financial membership changes do not gate attendance, so that race must prove independence and immutable money rather than invent a debit.
