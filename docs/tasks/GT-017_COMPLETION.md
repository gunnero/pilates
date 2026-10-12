# GT-017 completion report

**Implementation and verification complete under the user-approved dependency exception dated 12 October 2026, expiring 26 October 2026.** The dependency audit remains **FAILED**, unsuppressed, with exactly three accepted findings; the packages are not patched. All other mandatory acceptance gates passed. See the [approved dependency review](../security/GT-017_DEPENDENCY_REVIEW.md), [exact lockfile paths](../security/GT-017_DEPENDENCY_PATHS.json), and [acceptance evidence map](GT-017_ACCEPTANCE.md).

## Strict base and scope

Implementation started on clean `main` at exactly `a7a3f66ae9b7304bd2a3d1dc352de76745bb2f68`, `GT-016: implement trainer and studio operations dashboard`. Initial tracked/untracked status and SHA-256 manifest of 17 migrations are retained in ignored `.local/gt017/baseline.json`. That exact HEAD was preserved throughout implementation and all pre-commit verification; it is the required sole parent of the GT-017 completion commit. No history rewrite, remote operation, push, deployment, publication, remote PR or GT-018 work occurred.

The user explicitly authorized the uploaded task, the narrow dependency exception below, and exactly one local commit with subject `GT-017: implement reporting and business analytics`. No checkpoint or substitute commit was created. Obtain the resulting full hash from `git log -1 --format=%H`; this report intentionally avoids a self-referential commit hash. The final response records the actual hash after read-only parent/count/status verification.

## Implementation

The backend adds ReportRequest, ReportScope, ReportFilters, ReportQuery, Report, ReportExport and ReportController, one protected GET endpoint and a private-file cleanup command. Web Reports integrates into existing studio navigation; native StaffReports integrates into StaffOperations. Both provide MK/EN, bounded dates/grouping/comparisons, report-specific filters, summary/detail tables, charts, rankings, refresh, permission guards and authenticated CSV/real XLSX export. Native uses actual Expo FileSystem/Sharing APIs with private cache cleanup and account-generation guards.

Reports cover net cash collections, current membership settlement, current population/eligibility and period participation, expiry, group/trainer attendance, weighted occupancy/utilization, explicit cancellations/no-shows and group/local-slot popularity. The [dictionary](../domain/reporting.md) defines canonical grain, population, timestamps, formulas, denominators, permission and unavailable behavior. The [user guide](../operations/reporting.md) and [API/export contract](../architecture/reporting-api.md) describe usage and limits.

GT-009 is unchanged: original-paid-at net collections restate after full correction reversal; no invented accrual, partial refund, pending confirmation, overdue, payment allocation or group/trainer revenue attribution. Current member states are not presented as historical snapshots. Reports never write business records. No new grants, schema, indexes, caches, queues, warehouse or projection tables were introduced.

Operational scope uses SessionDirectory; finance additionally requires payments.view and denies Trainer; current whole-studio population requires all-location members.view/memberships.view and denies Trainer. Financial details and aggregate options use the same gates. Operational detail rows expose sessions, not member identity. The native staff token ceiling adds reports.view, which still requires a current membership grant; existing tokens require re-login.

Each response/export uses one read-only REPEATABLE READ snapshot for current/prior totals, buckets, options and details. A short separate organization-lock transaction rechecks authority and writes audit evidence before output. Membership/grant revocation before that boundary denies access. Existing token/session middleware authenticates at request entry; revoking that token during an already-authenticated in-flight request does not independently cancel it, while subsequent requests are denied. In-flight assignment snapshots and already delivered files cannot be retroactively revoked. No persistent public download links exist.

Limits: 366 inclusive days per range; two ranges at most 732 days; detail page default 25/max100; rankings default10/max50; options100; export50,000 rows/25MiB/30seconds/128MiB incremental memory; SQL statement15seconds. File rows are written incrementally from a bounded in-memory result. New exports use a fresh labelled snapshot.

## Discovery and preservation

Prior records are under docs/development/verification.md (GT-001), gt-002-verification.md through gt-014-verification.md, and docs/tasks/GT-015_COMPLETION.md / GT-016_COMPLETION.md. There is no separate original task-spec file for every historical task in the repository; actual domain docs, migrations, services and tests govern missing historical specification details. Critical finance, reservations, attendance, settings, authentication, notifications and operations contracts were inspected. Laravel13/PHP8.5, PostgreSQL18, React19/TypeScript6/Vite8, Expo57/ReactNative0.86, Composer and pnpm remain the toolchain.

All 17 migration files match both initial SHA-256 and strict-base Git bytes. No development migration, reset, reseed or data backup was needed because no development schema/data was touched. The existing private test cluster was stopped at baseline and was restarted with scripts/local-db; no shared service configuration or Redis flush occurred.

`python3 tests/Support/reporting-preservation.py` passed fresh installation and strict-base upgrade on **separate disposable private PostgreSQL clusters**, each database/role gt_test, loopback55435/55436. Fresh installation applied all17 migrations. Upgrade first ran the strict-base browser fixture/schema, then current no-op migration and192 report snapshots/384 exports. Complete rows/identifiers/grants in41tables, catalog constraints and exact per-tenant cash/reversal totals hashed identically before/after. Temporary clusters stopped and were removed. No destructive rollback exists for a nonexistent schema change.

## Baseline and verification

Actual baseline: backend387tests/3981assertions, web115tests, native81tests, browser28flows passed after restoring the stopped private test DB. Baseline Doctor failed six compatible Expo patch checks; narrow SDK patch updates fixed them. Those are measured baseline results, separate from the historical GT-016 report.

The completed verification results are recorded below. The final browser procedure runs38flows (19desktop+19mobile) in isolated batches; all 38 passed; the exact result is recorded below. Evidence stays under ignored `.local/gt017/verification/` (original phase) and `.local/gt017/resume/` (this continuation), with parsed exports/screenshots in `.local/gt017/browser/` and query plans in `.local/gt017/performance.json`. Generated/private artifacts are excluded from source.

## PostgreSQL overlap methodology

ReportingConcurrencyTest has seven scenarios repeated five times: **35 actual independent-process overlaps**. A child starts an authenticated report and establishes its read-only REPEATABLE READ snapshot, signals a file barrier with isolation/snapshot proof after its first domain SELECT, and pauses. The parent performs the canonical payment/reversal/attendance/membership/grant writer or other tenant's request, then releases the child. Barrier/process deadlines are bounded; no arbitrary sleep stands in for overlap. Database writes use existing production services/API routes and organization locking.

Expected read outcomes are the coherent pre-write snapshot, or denial when final current authority has been revoked; subsequent reads see committed writes. Canonical financial, reservation and attendance checks run after each case. There is no persisted export job, so duplicate-job lifecycle races are inapplicable; retries are fresh authenticated synchronous requests. Earlier targeted run:35passed/525assertions. The final full/targeted result below supersedes this historical targeted observation.

## Performance methodology

Real PostgreSQL18.6 on local Linux x86_64, AMD Ryzen3 7320U (8 logical CPUs), 6.5GiB RAM, PHP8.5.4/Node24.20.0; single authenticated HTTP test request at a time, ordinary middleware/policy/audit paths. Fixture:3tenants, measuredtenant1000members/2000occurrences/20000reservations/20000attendance/10000payments/500reversals across12months,2groups/2trainers; smaller tenant20members/30sessions, plus emptytenant. Statistics are explicitly analyzed after bulk loading. Ten warm-ups then50timed requests per report and detail, p95 sorted index47 (nearest-rank); primary plus equal-length comparison. Plans include EXPLAIN ANALYZE BUFFERS; no new index was justified by these measured budgets. Local timing is not a production SLA.

A first performance run exceeded settlement750ms because bulk fixture statistics were stale. ANALYZE corrected that fixture issue without changing production settings or thresholds. A full backend run exceeded Composer's300-second process wrapper; the rerun uses `COMPOSER_PROCESS_TIMEOUT=900 composer check`. This changes only the suite runner deadline, not report/API performance bounds or assertions. That timed-out run left a child process alive, causing test DB collisions in the initial rerun (386passed/49errors). Both processes were confirmed stopped before the final isolated full run passed. A duplicate test-only failure-injection function was also made repeatable. Neither failed run is counted as a pass.

## Security, dependencies and limitations

Manual review and corrections are detailed in [gt-017-review](../security/gt-017-review.md). OpenSpout5.12 adds genuine XLSX; Expo FileSystem/Sharing add native export. Compatible Expo patches satisfy Doctor. Narrow transitive shell-quote/source-map-js updates resolve two current baseline advisories. The remaining node-forge1.4.0, braces3.0.3 and sprintf-js1.0.3 advisories have no patched latest releases; baseline/current audit artifacts prove they were pre-existing. There is no advisory suppression, weakened audit level, fake version, removed test or replacement cryptography implementation.

No simulator/physical-device binary execution, real OS share completion, signing/store deployment or real provider notification delivery was verified. Native bundles/component tests/Doctor/prebuild cannot establish those claims. Native back navigation returns to existing Operations; this iteration does not introduce new financial mutation screens. Charts show at most60buckets with complete bounded table alternatives; dimension/option lists show explicit truncation. Historical mutable states/assignments, group revenue allocation and unsupported ledger states remain unavailable. Previously received exports cannot be remotely erased.

The detailed acceptance review in GT-017_ACCEPTANCE.md distinguishes implemented/tested behavior from unsupported domain states and inherited physical-device limitations. Only §18's dependency-audit gate and §20 checklist item 16 are amended by the explicit approval below; no functional, preservation, security-control or other verification requirement is waived.

## Verified commands and results

| Command                                                                                                                                                  | Actual result                                                                          | Local evidence                     |
| -------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- | ---------------------------------- |
| `composer validate --strict`                                                                                                                             | Passed                                                                                 | resume/composer-validate.log       |
| `COMPOSER_PROCESS_TIMEOUT=900 composer check`                                                                                                            | Pint passed; Larastan level8 zero errors; **437/437 tests,5351assertions**,355.0s      | resume/backend-final.log           |
| `pnpm typecheck`                                                                                                                                         | Passed                                                                                 | resume/typecheck-final-fourth.log  |
| `pnpm lint`                                                                                                                                              | Passed, includes native source                                                         | resume/lint-final-third.log        |
| `pnpm format:check`                                                                                                                                      | Passed                                                                                 | resume/format-approved.log         |
| `pnpm test`                                                                                                                                              | **130/130 tests,18files**                                                              | resume/web-final-fifth.log         |
| `pnpm test:e2e`                                                                                                                                          | **38/38 flows (19 desktop + 19 mobile)**                                               | resume/browser-final-second.log    |
| `pnpm build`                                                                                                                                             | Passed; existing nonfatal chunk-size notice                                            | resume/build-final-fifth.log       |
| `pnpm --dir apps/mobile typecheck`                                                                                                                       | Passed                                                                                 | resume/native-type-final.log       |
| `pnpm --dir apps/mobile test`                                                                                                                            | **89/89 tests,7suites**; existing delayed open-handle shutdown notice, successful exit | resume/native-final-third.log      |
| `pnpm --dir apps/mobile run doctor`                                                                                                                      | **21/21checks**                                                                        | resume/doctor-final.log            |
| `pnpm --dir apps/mobile bundle`; final `expo export --platform ios --platform android --source-maps --output-dir ../../.local/gt017/resume/final-native` | Both iOS/Android Hermes exports passed; final source-map export additionally inspected | resume/native-bundle-final.log     |
| `pnpm --dir .local/gt017/prebuild exec expo prebuild --no-install`                                                                                       | Both platform projects generated in disposable copied app                              | resume/prebuild-final.log          |
| `python3 tests/Support/reporting-preservation.py`                                                                                                        | Fresh + strict-base upgrade passed;41tablehashes,192snapshots,384exports               | resume/preservation-final.log      |
| `composer audit --locked`                                                                                                                                | No advisories                                                                          | resume/composer-audit-approved.log |
| `pnpm audit --json`                                                                                                                                      | **FAILED: 2 high, 1 moderate; accepted only under the scoped exception; unpatched**    | resume/audit-approved.json         |
| `pnpm peers check`                                                                                                                                       | No peer issues                                                                         | resume/peers-final.log             |
| `scripts/secrets-check`                                                                                                                                  | Passed                                                                                 | resume/secrets-approved.log        |
| `git diff --check`                                                                                                                                       | Passed                                                                                 | resume/diff-check-approved.log     |

The full backend suite adds50tests and1370assertions to the measured387/3981baseline, retaining all existing tests. It includes the35reporting overlaps and PostgreSQL integrity suites. Web adds15tests and native adds8. No tests were skipped/deleted or thresholds relaxed.

## Final measured report budgets

| Report        | p95 ms | Queries (comparison where supported) | JSON bytes |
| ------------- | -----: | -----------------------------------: | ---------: |
| revenue       |  67.84 |                                   13 |      84214 |
| settlement    |  36.22 |                                   13 |       1470 |
| activity      |  48.15 |                                   14 |       1527 |
| expiry        |  23.41 |                                   13 |       1163 |
| attendance    | 392.62 |                                   30 |     267895 |
| occupancy     | 490.24 |                                   30 |     267893 |
| cancellations | 293.43 |                                   34 |     317983 |
| popularity    | 283.48 |                                   30 |      15896 |

Revenue details p95 **50.99 ms**, 15 queries at page sizes 1 and 100. Overview p95 remains below 750 ms, detail below 500 ms, overview queries ≤35, detail ≤15. Final timings are local measurements, subject to concurrent host tooling load, not a production SLA.

- CSV: 10,000 rows, **0.235 s**, 11,708,778 bytes, 10,485,760 incremental peak allocated bytes.
- XLSX: 10,000 rows, **0.510 s**, 429,673 bytes, 0 incremental peak allocated bytes.

The XLSX zero incremental allocated arena peak means memory arenas were reused, not that the writer uses no memory. Both were below128MiB. Independent Python CSV/OOXML parsing verified10,000rows and exact10,000,000gross−500,000reversed=**9,500,000net minor MKD**. Browser fixtures reconcile6,000gross−2,000reversed=4,000net. An additional writer regression parses900719925474099301minor units exactly and preserves the text identifier000001 and Cyrillic/control-prefix input.

Browser-harness corrections: the first full desktop run passed14existingflows but failed2newreportingflows. The report selector now targets its accessible combobox role; direct cookie-authenticated API checks include the same-origin Referer expected by Sanctum, matching actual browser requests. Expected denial statuses and export assertions were preserved. Both targeted reporting flows subsequently passed.

A subsequent complete desktop run passed both reporting flows but exposed a pre-existing ambiguous member self-service locator when the same class title appeared in both a scheduled session and a promoted waiting-list card. That assertion now specifically locates the scheduled-session article and its exact class heading; expected business behavior is unchanged and the assertion is more specific, not removed or relaxed. The final full browser rerun includes this correction.

## Browser evidence and final Git state

The final browser procedure **passed all38flows**, preserving all28existing flows and adding10reporting flows (five per viewport). It covers live daily/weekly/monthly receipts, exact independently parsed CSV/XLSX, current settlement/activity, nonempty attendance/weighted occupancy/cancellation/ranking, custom comparison, trainer finance/detail/export denial despite an explicit payment grant, foreign tenant404, range422, private headers, two isolated tenant contexts, discarded delayed actual responses, network failure/retry, MK/EN and keyboard/layout checks at1280/768/360pixels. After the last display/date-normalization changes, all10 affected reporting browser flows passed again (`pnpm test:e2e tests/browser/reports.spec.ts`, `resume/browser-report-final.log`); these reruns are not added to the38 unique flow count. Screenshots/downloads remain ignored under `.local/gt017/browser/`.

During this continuation, the first targeted browser run served stale built assets, then a stricter combobox role selector corrected the comparison-control test lookup. The first enlarged full desktop run passed18flows but hit the real login-IP rate limit in the reservation flow. The runner now partitions prior/reporting flows into fresh private gt_test fixture/cache lifecycles for each viewport; every flow and all production limits remain intact. No shared Redis flush or service change occurred. The final command/result below supersedes those failed runs. A unit-test response mock and Testing Library role-option types were corrected without weakening the failure/retry assertions. Logs of intermediate failures remain in `resume/`.

Continuation fixes also include MK/EN exact BigInt money/date/count/rate display; translated comparison and status cells; server-local bounded date presets and day counts; appropriate occupancy/cancellation chart measures with null values distinct from zero; fresh per-download abort controllers under Strict Mode; native comparison/ranking pagination isolation; preserved scoped plan/default dates during custom comparison; current-studio allowlisted web navigation context; actionable export422 messages; protected option cleanup on failed revalidation; and peak-memory checks before result release. All are linked to their specific regression evidence in the acceptance map. No published compatible remediation for the three dependency advisories was established. Expo development serving now defaults to localhost; intentional trusted-LAN use remains available.

The local completion procedure creates exactly one commit on `main` with subject `GT-017: implement reporting and business analytics`, directly above strict base `a7a3f66ae9b7304bd2a3d1dc352de76745bb2f68`. After creation, read-only verification checks the exact subject, sole parent, one-commit range and empty tracked/untracked status. The actual results and hash are emitted in the final response; no second commit or amend is used to insert a hash into this report. Nothing was pushed, deployed, published or opened as a remote PR; EAS/custom signing and GT-018 were not started.

## Approved dependency exception and final checks

On **12 October 2026**, the user explicitly approved the narrowly scoped exception in the dependency review through **26 October 2026**, authorizing only the single local GT-017 commit. It covers exactly:

- **GHSA-86w9-cpqp-85rv — node-forge 1.4.0**: existing Expo CLI/signing-helper paths.
- **GHSA-vfj7-8cjw-p6xm — braces 3.0.3**: existing seven micromatch parent paths.
- **GHSA-hp3w-g68c-fv3c — sprintf-js 1.0.3**: existing NYC/js-yaml/argparse paths.

Every importer/optional/peer prefix is limited to the exact unchanged [path appendix](../security/GT-017_DEPENDENCY_PATHS.json), SHA-256 `79950107d7a0218b28e005e20a69fd49821377676ba180a692308841604c2445`. Only **GT-017 §18's dependency-audit gate and §20 checklist item 16** change: permit this one local commit with the executed, unsuppressed audit accurately marked **FAILED with three accepted findings**, provided every other mandatory acceptance requirement passes. This approval covers no new advisory, version/path change, additional exposure or unrelated acceptance failure.

Retain loopback development serving, trusted local code/configuration, isolation of untrusted builds from credentials, and disabled EAS/custom signing. Reassess immediately if an upstream fix becomes available, configuration/exposure or paths change, or attacker reachability is found; otherwise re-review by **26 October 2026**. Adopt compatible upstream fixes with the relevant regression checks. Run the full unsuppressed audit after dependency changes and before any separately authorized deployment. No deployment, publication, remote PR or GT-018 is authorized.

Remaining uncertainty is unchanged: unexecuted macOS/signing trust chains, dynamic tooling/plugin glob entry points, and arbitrary argparse consumers/modified format templates have not been proven safe. Inspected bundle absence and current input guards are evidence for specific conditions, not universal unreachability. See the per-advisory source-to-sink assessments in the [dependency review](../security/GT-017_DEPENDENCY_REVIEW.md).

Approval-time `pnpm audit --json` again exited **1** with exactly two high and one moderate findings, the three approved versions, and no patched releases. The lockfile hash and all 53/221/142 respective dependency-graph edges match the approved appendix; each returned audit path is included. This finalization changes only the four status/approval documents. The completed code suites above remain applicable to unchanged implementation; documentation formatting, Composer audit, secret scanning and working/staged diff checks are refreshed before the commit. No audit threshold or output is suppressed.

Final production bundle inspection: web52source entries, Android981, iOS984; none contain node-forge, braces or sprintf-js. Exact source-map hashes and commands are in the dependency review and `.local/gt017/resume/bundle-evidence-final.json`. All three remain installed in tooling; production-bundle absence does not change the failing audit.
