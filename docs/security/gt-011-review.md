# GT-011 security review

Base: `245ab5a660d2e8c6daa8c9adfd921754804ee265` (GT-010). Reviewed the GT011 working-tree snapshot without changing its source during the scan. This is a scoped change review, not a claim of exhaustive repository coverage.

Codex Security plugin 0.1.30 scan `cce6c233-f1a6-4799-be06-4bd68011ac1d` completed and sealed, with **40/40 generated source inventory files reviewed and zero candidates/findings**. Dedicated preflight returned ready, all three capability checks passing. An independent worker reviewed 25 backend/schema/route files; the parent reviewed 15 frontend/config/localization files and manually inspected changed test helpers, preservation regressions and documentation omitted by the generated source inventory. No applicable SECURITY.md policy exists. The exact 40,471-character repository threat model was retained without truncation at `artifacts/01_context/threat_model.md` (SHA-256 `53b533b5adb25612ae411342568ff59426ab4653245771e8da985b8bd9f946e3`). The scan retained the same text as its canonical model summary.

Retained artifacts live in `/home/gunner/.codex/state/plugins/codex-security/scans/pilates/245ab5a660d2e8c6daa8c9adfd921754804ee265_20260924T165310Z_4h2u89y2/`: report.md, findings.json, coverage.json, scan-manifest.json, SARIF and exact threat-model copy. The completed scan was read back after finalization. No measured token usage was supplied by the completion result. Daybreak access was reported not_granted; protected output display may be restricted. These artifacts are local workstation evidence, outside the repository.

## Architecture-aware checks

- Account takeover: staff custody requires combined client view/update/status powers and all-location scope. No name/contact matching silently links accounts. Existing-account activation requires its current password in addition to an invitation capability.
- Invitation lifecycle: random hash-at-rest capability, 24-hour expiry, pending uniqueness, state/expiry recheck under locks, immutable accepted evidence, tenant-qualified member and accepted-account FKs. Reissue revokes prior pending invitation. No raw capability/email is returned by staff invitation APIs or tenant audit.
- Authentication: established CSRF/session protections; fixed narrow native bearer abilities, 30-day expiry and account token cap. Password changes/reset revoke database sessions and personal tokens. Per-email advisory locking includes broker token consumption, verified against Laravel broker implementation and actual competing requests.
- Ownership/IDOR: active account→member→organization predicates, UUID-scoped own reservation/waiting/attendance/membership/payment reads; no authority from request identity fields, TrainerProfile or member digital preference. Write authority is resolved again after organization locking.
- Allocation: shared GT007 implementation for reserve, waiting, cancel and FIFO. Shared capacity query helper, native admission constraints, session locks and attendance protections. No independent frontend capacity enforcement is trusted.
- Privacy: explicit serializers, contact-only profile allowlist, read-only finances and attendance, no correction reasons/staff notes/internal audit data. GT010 delivered recipient inbox is reused.
- Abuse: existing global/login/password limits, activation IP/token-hash limits, member mutation limits, bounded page/date/body validation.
- UI: React text escaping, fixed same-origin API routes and CSRF helper, no localStorage credentials, fragment token removed on successful activation/reset, immediate old-studio workspace unmount. Collection endpoint remount fixes the development-time stale-history rendering bug.
- PostgreSQL evidence: actual rejection tests for foreign ownership, duplicate pending/link, invalid lifecycle, immutable links and audit, plus populated preservation and five competing-process scenarios.

## Fixes and limitations

Development-time failures corrected before the sealed source review included using database sessions in tests to exercise revocation, the schedule cancellation column name, and clearing history component state when its resource endpoint changes. Notification privacy tests explicitly deliver queued evidence before asserting inbox visibility; delivery gating remains intact.

After sealing, final documentation was completed and the **test-only router** was tightened to keep PHP entry points inside its guarded e2e bootstrap rather than handing them to the built-in static server. This prevents an accidental `/index.php` test request from booting ambient development configuration. That small follow-up was manually reviewed; it is outside the immutable scan snapshot. Production routing is unchanged. Browser checks were repeated after this test-harness change.

Real mail transport, production deployment configuration and native devices were not exercised. Array transport acceptance is not real delivery. Already-authorized in-flight requests may finish after credential revocation; unchanged browser login is outside the new per-email lock protocol. No unlink/account reassignment/recovery override exists. No claim of a clean scan proving absence of all vulnerabilities is made. Final execution results are in [GT011 verification](../development/gt-011-verification.md).
