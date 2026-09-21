# GT-007 security and privacy review

Baseline: GT-006 `56d4e9122f7e57bc5312544a19ea25deb776e8a1`. Codex Security diff scan `9dd320c9-e828-40cd-b05a-19b20667ab28` reviewed 39 source inventory files: 29 backend/schema/routes/catalog files independently and 10 frontend/representation files in the parent. Preflight was ready, no configuration changes. Daybreak was not granted; advisory only. No applicable SECURITY.md existed.

The scan deliberately records the **pre-fix** snapshot, not a clean bill of health for the final working tree. Its completed canonical report has three medium/high-confidence authorization findings. The workbench warns that the working tree changed during scanning (test/documentation edits); later fixes and final deltas were reviewed separately. This is not the GT-004 stale-checkpoint/zero-findings claim: every source inventory row was closed, all three candidates were reproduced and retained, and no unresolved candidates were hidden.

## Findings and corrections

1. HTTP materialization authorized a group's current scope but permanent synchronization also visited hidden overridden occurrences. The entry point now checks all existing affected session visibility under the organization lock before generation/synchronization. It fails closed for hidden location or trainer assignments and excessive batches. Regression returned 200 before correction and 404 afterward.
2. Permanent-place mutation rechecked manage under lock but view only in route middleware. It now rechecks permanent_spots.view after fresh context resolution. A regression removes view between middleware and the locked callback: 201 before correction, 403 afterward.
3. Waiting-list POST could directly reserve free capacity with waiting management but no reservations.manage. Both allocation endpoints now recheck reservations.manage under the lock. Regression: 200 before correction, 403 afterward. Waiting cancellation still needs only its own explicit management/view grants.

The independent reviewer subsequently inspected all three fixes and found the identified paths closed. These are implemented fixes, not permission exceptions. No broad grants, tenant bypass or invariant weakening was introduced.

## Manual review

Reviewed tenant-qualified resource lookups/FKs, current session location/trainer scope, explicit fields and source allowlists, suspended/revoked staff and inactive tenant middleware, post-lock authority, active uniqueness, fixed lock order, authoritative counts, capacity updates, FIFO tie-breaking, ineligible queue handling, release suppression, permanent effective-period sweep, reconciliation provenance, materialization integration and rollback on audit failure. Twelve real PostgreSQL competing-process cases exercise critical races; aggregate rules are service-enforced transactions, not falsely described as CHECK constraints.

Customer lookup uses JSON body prefixes, 20-result limit, current authorized session/group, minimal public IDs and names. General directory and notes restrictions remain. Reservation/member relationship lists expose only visible parents, have bounded pagination and no contacts, notes, internal IDs or audit internals. React renders escaped text and shared same-origin CSRF requests. Source/actor/status/queue position cannot be supplied through reservation APIs. UI lookup clears old results while loading; queue pagination and occurrence-capacity refresh were checked after scan.

Logs contain safe identifiers/counts/reason codes, not PII. Audit is append-only, bounded and in the same transaction. Explicit adoption preserves existing grants and financial authority. Migration plan is additive; no data reset, extension or shared service changes.

## Residual boundaries

Raw schema-owner SQL remains trusted and can bypass aggregate capacity and cross-table exclusivity; run `gt:reservations:check` operationally. Local migration roles are not a production least-privilege design. Authorized workflow lookup permits bounded tenant customer identification and is not a general directory grant, but a malicious authorized staff user can still misuse permitted searches; deployment monitoring/access review remain needed. Queue size is capped at 1,000, synchronization at 800 sessions, future permanent admission at 10,000 retained effective candidates. No notifications, mobile/self-service authority, attendance, payments or public production deployment is assessed or delivered.

Temporary tool report: `/tmp/codex-security-scans-JO6Ro5/pilates/56d4e9122f7e57bc5312544a19ea25deb776e8a1_20260921T000457Z_q8y57m2w/report.md` (not committed). Measured scan usage: 5,972,108 total tokens; 5,961,276 input; 5,539,712 cached input, complete measurement. Report checkpoint initially rejected group-writable temporary parent directories; their permissions were tightened to 0700 and the same scan completed. No persistent user configuration changed.
