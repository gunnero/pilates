# GT-017 implementation plan

Strict baseline: clean `main`, `a7a3f66ae9b7304bd2a3d1dc352de76745bb2f68`, `GT-016: implement trainer and studio operations dashboard`. Historical migration SHA-256 manifest: private `.local/gt017/baseline.json`. Existing historical verification documents are evidence of earlier runs only; execute baseline and final suites separately.

## Ownership and definitions

Reporting is a read projection over GT-009 CashPayment/PaymentReversal/MemberMembership, GT-007 Reservation/WaitingListEntry, GT-008 AttendanceRecord and GT-006 TrainingSession. No ledger, balance, attendance, admission, notification or scheduling writes. Reuse TenantContext, SessionDirectory, FinanceDirectory, existing permission gates and Lifecycle/Audit for audit metadata only.

Revenue means net collected cash restated to original `paid_at`, matching FinanceMetrics: gross payments minus full linked reversals, both in the original payment period. A reversal is a correction, not physical refund evidence. MKD integer minor units only. Pending cash, partial refunds, overdue rules, accrual accounting and historical financial attribution do not exist. Settlement is current sold-period agreed minus current net cash; credits remain negative. Current eligibility/expiry cannot be reconstructed historically.

Attendance uses occurrence start time and current final outcome, capacity snapshots and retained session assignment/name snapshots. Held means scheduled and ended at the report's fixed as-of instant. Expected participants are active reservations of held occurrences; pending is explicitly excluded from resolved attendance rate. Booked occupancy and present utilization have distinct weighted denominators. Waiting demand and cancelled reservations remain separate. Popularity ranks additive member-occurrences with stable identifiers, plus unique participants and sample size. Time slots use studio-local ISO weekday/start hour. Comparisons use equal calendar days or bounded custom ranges; only period facts compare historically.

## Authority and implementation

| Surface                                          | Required current authority                                                                    |
| ------------------------------------------------ | --------------------------------------------------------------------------------------------- |
| Every report/export                              | authenticated TenantContext, studio.access, reports.view                                      |
| Operational aggregates                           | sessions.view + attendance.view, existing location/current Trainer assignment intersection    |
| Financial report/details                         | payments.view; deny Trainer role financial reporting even when it has ordinary payment access |
| Current whole-studio member activity/eligibility | all-location members.view and memberships.view; no Trainer-wide population                    |
| Operational details (session facts only)         | same operational scope; no member-identifying operational detail projection                   |
| Exports                                          | same report/field gates; no new export privilege or automatic grant adoption                  |

One normalized contract: local inclusive dates converted to half-open UTC instants, 366 days per range / two ranges, daily/weekly/monthly, Monday weeks, page 25/max100, top10/max50, allowlisted UUID filters. Reject unsupported filter/report combinations rather than misleadingly ignoring them. No cross-request cache. Each report/comparison/export uses a read-only REPEATABLE READ snapshot, fixed studio today/as-of, current scope. Recheck authority before releasing protected output; audit access/export through the organization lock in a separate short transaction, fail closed before bytes. Document in-flight revocation linearization.

Prefer synchronous private temporary exports: at most50,000 rows,25MiB,30seconds,128MiB incremental memory; no persisted jobs or public downloads. Create the complete file under one snapshot, then reauthorize/audit before response. Cleanup on success/failure and stale temporary files. CSV UTF-8 BOM/CRLF with formula-safe text; genuine XLSX using narrowly added maintained OpenSpout, typed literal text, metadata, headers, freeze/filter/widths. Native authenticated retrieval plus private cache/share and cleanup uses SDK-compatible Expo filesystem/sharing. No browser-token URL. Charts use accessible native/web primitives and full table equivalents without adding a chart framework.

## Validation budgets and execution

1. Inspect existing specifications/domain/code; baseline full backend/web/native/browser checks sequentially for shared gt_test.
2. Build canonical query/metric/filter/audit/export services and scoped APIs; test exact reconciliation, adversarial inputs, permission/tenant boundaries, export bytes and read-only preservation.
3. Integrate responsive MK/EN web and native report, details, comparisons, charts, exports and stale/context guards.
4. Real PostgreSQL representative fixture:3tenants,12months,1000members,2000occurrences,20000reservation/attendancefacts,10000financialevents.10warmups/50timedrequests per key read; p95 overview≤750ms,details≤500ms,10000-row export≤15s; ≤35overview/≤15detail queries independent of page size. Capture EXPLAIN ANALYZE BUFFERS, bytes, peakmemory, environment. Keep original budgets unless actual evidence justifies a change.
5. Independent PostgreSQL workers with explicit snapshot/write barriers, ≥5repetitions each: payment/read, reversal/export, attendance/read, renewal/snapshot, boundary insert/export, revocation/response and cross-tenant concurrency. No mocks or sleeps as overlap evidence; bounded deadlines, canonical after-state checks.
6. Preserve all historical migrations/grants; no migration unless measured need. Fresh and populated strict-base upgrade preservation checks on isolated databases; financial totals/identities unchanged.
7. Full established gates including native doctor/bundles/isolated prebuild, browser desktop/tablet/phone and parsed downloads, audits, secrets, manual security/diff review. Document all results and real-device/provider limitations.
8. Exactly one local commit `GT-017: implement reporting and business analytics`; no push/deployment/remote PR/GT-018. Read-only post-commit verification.
