# GT-017 focused security review

Status: implementation review performed; dependency audit remains FAILED under the user-approved exception through 26 October 2026, limited to the exact three versions/paths in the [dependency review](GT-017_DEPENDENCY_REVIEW.md). This is architecture-aware manual review plus automated application tests, not an exhaustive generated scan. GT-004's historical scan coverage does not establish coverage of these changes.

## Boundaries reviewed

- Route authentication, active TenantContext and `reports.view` precede report projection. Each report reuses independent financial/member/operational gates. Trainer finances and whole-studio population are denied, even with an otherwise sufficient individual financial grant. Member tokens fail the staff ability ceiling. No new membership grants are adopted.
- SessionDirectory retains selected-location/current Trainer scope for rows, totals, rankings, options and cancellation events. Foreign tenant/plan references conceal existence. Filtered group/trainer IDs must be visible under that scope. Organization IDs and actor/role/grants cannot be supplied as report fields.
- Financial joins are one posting or pre-aggregated membership grain, tenant-qualified. Reports do not write business tables. Independent export parsing, canonical checks, overlap tests and strict-base preservation detect ledger or row multiplication.
- Fixed read-only REPEATABLE READ includes both comparison periods. The short existing Lifecycle lock re-resolves authority before audit and output. A committed membership/grant revocation before that point denies a prepared export; downloaded bytes and already-authorized in-flight assignment snapshots cannot be retroactively withdrawn. No durable bearer download link bypasses later authorization. Existing middleware checks token/session validity at entry; Lifecycle refreshes membership/grants, not the cached bearer-token model, so token revocation does not cancel a request already authenticated before revocation. Subsequent requests fail authentication.
- Web report state remounts when organization/role/permissions/location-scope changes, and pending requests abort on unmount. Native generation/token checks reject stale downloads; context refresh clears old values. No report payload is stored offline or in a cross-request cache. Parent operations auth/logout flows remain authoritative.
- Input dates, formats, ranks and SQL grouping units are allowlisted; all values are parameterized. Export limits are 50,000 rows, 25 MiB, 30 seconds and 128 MiB incremental memory, with 15-second SQL statements. Existing rate limiting remains in place. Detail retrieval is bounded, not an unbounded cursor promise. Aggregate workload can still grow with a large tenant's underlying history, especially current settlement.
- Private exports are completely prepared before release, then reauthorized and audited. Random private paths cannot be requested through the route; response filenames are constant-derived. Success/failure/stale cleanup, no-store/nosniff, CSV control-prefix protection and literal OOXML strings reduce disclosure/injection risk. Original close/write failures preserve cleanup. Export metadata contains no notes, contacts or report rows.
- Audit failures fail closed before bytes. Both export-prepared and download-start audit events share the same transaction. Download-start is not an OS receipt. Rejected requests do not emit a new unbounded diagnostic payload.
- Native credentials remain in existing secure storage/client headers. App-private temporary files are removed after sharing or failure and screen exit. OS/user copies outside the app are outside revocation control. Native network timeout and size/generation guards do not prove physical-device share behavior.

## Findings corrected during implementation

1. Numeric-looking names/identifiers were initially normalized as counts. Normalization now preserves identity, name, label and expiry fields as text; Excel also uses literal string cells.
2. Switching away from custom comparison retained stale custom bounds. Both clients now remove those fields and defer incomplete custom comparisons.
3. Web permission changes could retain an already-loaded report. The report instance now resets on authority-context changes; server authorization remains mandatory on every request.
4. A second XLSX close failure could interfere with output deletion. Cleanup now preserves the original exception while removing the output.
5. Initial bulk performance fixtures lacked refreshed PostgreSQL statistics. Explicit ANALYZE gives representative operated-database plans without lowering budgets or changing production DB configuration.

## Dependency audit — FAILED, scoped exception, no suppression

The strict GT-016 lockfile and GT-017 lockfile were both audited on 2026-10-12. Both initially reported five advisories. Narrow transitive updates resolved shell-quote GHSA-pqg4-j6r4-53mv and source-map-js GHSA-68fv-2mgg-jv7q. Three remain in required build/test dependency trees, with the registry's latest releases still affected:

| Package    | Latest checked | Severity | Advisory / patched release                                                     |
| ---------- | -------------- | -------- | ------------------------------------------------------------------------------ |
| node-forge | 1.4.0          | high     | [GHSA-86w9-cpqp-85rv](https://github.com/advisories/GHSA-86w9-cpqp-85rv), none |
| braces     | 3.0.3          | high     | [GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm), none |
| sprintf-js | 1.1.3          | moderate | [GHSA-hp3w-g68c-fv3c](https://github.com/advisories/GHSA-hp3w-g68c-fv3c), none |

They predate GT-017, but that does not satisfy the task's clean-audit gate. Removing required Expo/Jest/build dependencies, fabricating patched versions, ignoring advisories or raising audit thresholds would weaken verification. No such change was made. Evidence: ignored `.local/gt017/audit-baseline.json`, `audit-final.json`, `advisory-paths.json`. On 12 October 2026 the user explicitly amended only GT-017 §18's dependency-audit gate and §20 item 16 for these three exact existing versions/paths, permitting the single local commit after every other requirement passes. Audit output remains FAILED, with no suppression or claim of remediation. The binding scope, expiry, mitigations, uncertainty and review triggers are recorded in the dependency review.

## Remaining verification limits

No physical-device/simulator binary execution, real OS sharing receipt, code signing, store distribution or real notification delivery was performed. Existing external limitations carry forward. No production/development database mutation, deployment, push, publication, remote PR or shared Redis operation was performed.

The continuation adds a separate [per-advisory dependency review](GT-017_DEPENDENCY_REVIEW.md) and exact lockfile path appendix. Installed sprintf-js is **1.0.3**, while the table above intentionally lists registry latest1.1.3; neither is patched. The user, not the scan, explicitly accepted only the scoped residual risk recorded in that dependency review; all other requirements remain unchanged. Further verified fixes: exact localized presentation; scoped input retention/context guards; Strict Mode-safe export cancellation; native independent secondary tables; peak-memory guards; nonempty operational/trainer/isolation browser evidence. See the [acceptance map](../tasks/GT-017_ACCEPTANCE.md) for exact evidence.
