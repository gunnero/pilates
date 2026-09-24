# GT-013 security review

Base: `b4a3fbeb9732a0b2b5e0e524dd862aa19fd78bca`. Scope: final GT-013 working-tree implementation, its directly supporting authority/attendance/transport boundaries, tests and operational documentation. No environment secrets were read, no external messages sent and no production deployment tested.

Codex Security diff scan `7e0aecb2-9d91-48da-9ab0-81fb155b7cb1` completed on 2026-09-24 with **zero findings**. Artifacts are retained outside Git at:

`/home/gunner/.codex/state/plugins/codex-security/scans/pilates/b4a3fbeb9732a0b2b5e0e524dd862aa19fd78bca_20260924T181211Z__ko4_auy/`

Canonical manifest, findings, coverage, report and fresh per-scan threat model were saved through the plugin. Capability preflight passed. A fresh-context architecture review and separate backend discovery review supported the parent's mobile/web/full-diff review. No candidates required vulnerability validation or attack-path escalation. The initial semantic draft rejected reserved inventory fields; the corrected typed draft and single completion succeeded, and sealed artifacts were read back. No replacement scan or hand-edited canonical report was used.

The tool's 26-file source inventory omitted PHP tests/browser fixtures, documentation and the lockfile. The parent manually reviewed the complete actual diff including untracked files, closing that coverage omission rather than treating the inventory as complete repository coverage. The captured snapshot precedes final test formatting, browser-only locale/isolation assertions and reporting documentation; runtime implementation remained unchanged. Those final test/documentation changes were manually reviewed. Historical scan results were not reused as proof of current coverage.

## Reviewed controls

- Laravel authenticated encryption checks version, tenant/session binding, future issue time, maximum lifetime and server expiry; check-in eligibility is independently rechecked. Modified and expired credentials cannot obtain an idempotent success.
- The HTTP adapter always requires QR. Legacy nullable QR is internal compatibility, not an exposed omission path. Member identity comes only from the authenticated User and active tenant link, with member.write checked under the organization lock.
- Staff generation retains TenantContext, explicit permission, location and trainer scope. TrainerProfile does not confer authority. No new grants were introduced.
- Session and organization locks, active reservation requirement, existing attendance uniqueness/tenant-qualified foreign keys and correction rules preserve admission and lifecycle. QR contains no reserve, promote, walk-in or capacity-allocation operation. Six real competing-process tests verified the critical races.
- Scanner input is bounded and parsed as data, with no arbitrary URL/deep-link/WebView navigation. Requests use the fixed configured API origin. Duplicate camera events, stale unmounted callbacks and ambiguous network retries cannot optimistically establish attendance.
- QR credentials remain transient. Explicit response/audit fields and class-only exception reporting omit raw credentials and unrelated member data. Native bearer storage remains GT-012 SecureStore. No camera media or microphone access is requested.
- Account/IP budgets cover malformed attempts; rotating staff display uses two requests/minute by default. Roster reads stay bounded and scoped. API errors use finite localized codes.

Development verification exposed a missing organization relation in initial QR construction and test-fixture/configuration mistakes; these were corrected before final verification. No validated vulnerability remains unresolved in the reviewed diff. No security invariant was weakened to make a test pass.

## Limits

A shared live QR can be forwarded within its at-most-90-second lifetime. This feature does not prove physical proximity; account, reservation and server-window checks still apply. Rotation intentionally permits overlap. External proxy/APM redaction, encryption-key custody, synchronized server clocks, actual cache-store sharing and production HTTPS are deployment responsibilities not established by source review. Config defaults do not prove that runtime throttling uses Redis.

Physical native camera recognition, native network redirect behavior and platform storage/accessibility were not executed because no simulator/device toolchain is available. Automated tests, real HTTP/PostgreSQL flows, bundles and prebuild manifest inspection are supporting evidence, not device certification. Daybreak access was reported not granted; protected scan output display may be limited, although the local scan completed and canonical artifacts were readable. A no-findings scan is not a guarantee of absence of vulnerabilities.
