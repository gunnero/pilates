# GT-006 focused security review

Reviewed the complete GT-006 change against GT-005 `550fafd9c275a44abaf10cd32566de6e8f8907cd`. No concrete in-scope vulnerability remained. This is an architecture-aware local source review with PostgreSQL/browser test evidence, not a production penetration test or certification of vendor/host internals.

## Coverage and controls

An independent read-only reviewer covered all 23 changed backend/schema/routes/localization source files and followed TenantContext, Lifecycle, Gates, TrainerEligibility, GroupDirectory, Audit and FK definitions. The implementing reviewer covered all seven frontend/CSS/type/localization/component-test inventory files plus changed backend/browser tests and documents. No source inventory item was deferred.

| Boundary                                        | Evidence                                                                                                                                                                                                                                                 |
| ----------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Tenant, selected location and trainer relevance | SessionDirectory scopes tenant and saved session location before lookup/serialization, then own TrainerProfile for Trainer-role actors. Updates check both old scope and eligible new destination; foreign resources fail closed.                        |
| Permission and stale-state bypass               | Routes require independent sessions.view/manage/cancel. Mutations re-resolve active actor/context under the organization lock, including generation and replacement reconciliation. Profile existence grants nothing; GT-003 client denial is unchanged. |
| Forged generation/source ownership              | Strict FormRequests reject internal/public IDs, tenant, source/rule/date/override/cancellation/audit fields. Source fields are server-owned. Composite rule FK includes organization and group.                                                          |
| Duplicate generation and cancellation loss      | Permanent unique rule/occurrence-date identity includes cancelled and moved rows. Reruns preserve existing records. Organization lock plus native constraints protect races.                                                                             |
| Concrete trainer/group conflicts                | Native half-open UTC range exclusions for scheduled sessions; shared lock also coordinates validation, override and cancellation. No location-only exclusion or room assumption.                                                                         |
| Reconciliation authorization/history            | Requires visibility and explicit manage/cancel permissions over every affected occurrence. Protected manual exceptions block the whole rule change. Untouched future replacement is atomic; generation failure rolls back rule and session changes.      |
| Capacity/time/assignment invariants             | Capacity bounds in validator and DB; strict local/DST resolution; saved local-date/time consistency checks; current trainer/staff/location eligibility. Session snapshots do not follow mutable group defaults.                                          |
| Audit tampering and disclosure                  | Audit in the same transaction; append-only regression remains. Public assignment references/time/capacity only; cancellation reason presence, not prose. Aggregate routine generation avoids per-occurrence audit noise.                                 |
| Serialization and errors                        | Explicit public representations omit internal source/staff/authority/client data. React escapes text. Session conflict output is a fixed allowlist of localized codes; SQL/framework errors remain generic.                                              |
| Resource exhaustion                             | Calendar ≤56 dates, ≤100 rows/page; generation fixed 56 dates, ≤100 rules per group; CLI groups/organizations traversed in batches of 100. Tenant locks and API throttles retained.                                                                      |
| Migration and operational tooling               | No data reset/adoption, extension install or shared service change. Guards restrict tests to gt_test. Trusted operator generation prints identifiers/counts/codes, never SQL or sensitive names.                                                         |

Implementation review tightened fresh sessions.view checks on manual creation, explicit own-trainer checks when replacement invokes generation, cancellation metadata null checks, local-date consistency, snapshot trainer-name length, and fixed-code user-facing conflict explanations before the immutable scan. No confirmed vulnerability candidate was recorded by the final scan.

## Tool record and GT-004 coverage limitation

Codex Security diff scan `9bab4157-4974-4d93-95db-88947f86a31f` completed: **30/30 source items**, zero findings, canonical coverage **complete**, empty deferred work/open questions. Snapshot digest: `codex-security-snapshot/v1:sha256:9893d7c293008c7f804dbb04417d8687a6e9699ed036c310e6841efe208b3404`. Preflight passed without changing persistent configuration. Daybreak advisory was `not_granted`, no programs; the local sealed result/report was readable.

The earlier GT-004 report retained a stale deferred checkpoint. This review did not assume that zero findings closed coverage: both reviewers enumerated actual files, the parent read the completed canonical coverage, and confirmed all 30 source items closed with no deferred entries. Historical GT-004 coverage is not broadened by this review.

Generated report lives in `/tmp/codex-security-scans-HqjDo4/pilates/550fafd9c275a44abaf10cd32566de6e8f8907cd_20260919T005553Z_0fze226l/report.md`. The scan froze runtime source before final verification. Subsequent test import/format corrections, additional foreign-identifier assertions, and final verification/migration documents were reviewed by the parent separately; runtime source remained unchanged. Final test results are in [verification](../development/gt-006-verification.md), rather than rewriting sealed evidence.

Workbench measured usage: 4,142,781 total tokens; 4,135,360 input; 3,920,000 cached input; measurement coverage complete. This is recorded tool usage across the contributing threads, not a cost estimate.

## Remaining limits

Schema owners/CLI operators are trusted. Raw SQL can bypass supported-writer eligibility and history rules; DDL owners can remove constraints. Already-running reads may complete on their resolved context. No production telemetry/retention/backup policy is deployed. Cancellation is terminal; protected exceptions deliberately block affected weekly changes. No member-facing session API, customer entitlement, reservation allocation, attendance, room scheduling or public deployment exists. The [session domain](../domain/training-sessions.md) documents those limits and the future capacity contract precisely.
