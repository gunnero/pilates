# GT-014 security review

Base: `a1597f6cdbb52ace60cc2d8a7e768005877cedc8` (`GT-013: establish secure member check-in`). The prerequisite gate found exactly one completed GT-013 commit, recorded passing available verification and a clean tree. No prior commit was rewritten.

Manual architecture-aware review and Codex Security diff scan `1caad652-4e32-4091-8367-0b22bb338d33` found no plausible vulnerability candidates in GT-014. Capability preflight passed without configuration changes. A fresh-context architecture worker produced a source-backed model; an independent reviewer covered all 18 backend inventory files, while the parent covered the 18 client/locale items and all test/documentation changes outside the tool inventory.

## Controls reviewed

- Staff settings resolve active TenantContext and explicit organizations.view/manage plus token abilities; mutations re-resolve authority under Lifecycle's organization lock. Members have only an own-active-organization finite read subset. No role, organization, actor or unknown key is mass-assigned. TrainerProfile grants nothing. Existing permission arrays are unchanged.
- Only JSON objects up to 4 KiB are accepted for policy PATCH. Strict original JSON bool/int/null checking defeats middleware empty-string coercion and numeric/string confusion. Every integer has application and PostgreSQL bounds; rotation/lifetime has an effective-default relational CHECK. Unknown fields, nested values, overflow, invalid tenant ownership and duplicate tenant rows are rejected.
- One explicit typed resolver has no cross-request/server cache. Member and notification lists reuse one request-local policy to avoid adding settings N+1 queries. Clients use existing account/studio-scoped memory caching, and server mutations recheck current policy.
- Settings, reservation/cancellation, QR and each reminder candidate use the same organization lock. Ten real competing-process cases exercise both queued orders for two administrators, reserve/disable, cancel/cutoff, QR/disable and scheduler/reminder disable. Successful domain operations must precede the disabling policy audit; scheduler-first may create once, policy-first creates none.
- Capacity, FIFO, permanent-place allocation, present-attendance cancellation protection and financial history remain fixed. Disabling new queue admission preserves existing promotion and cancellation. Policy snapshots do not rewrite historical evidence.
- QR retains authenticated encryption, server issue/expiry checks, hard maximum 90 seconds, tenant/session binding, member identity and active reservation. Configurable TTL is 30–90 seconds and rotation 10–30 seconds/at most half TTL. Existing credentials remain valid only until their signed expiry and within current enabled/window policy. Manual staff fallback retains independent authority.
- Reminder discovery is bounded, current policy is re-read under lock, and deduplication keys do not include changing policy values. Optional delivery checks current enablement both at claim and completion. Financial/lifecycle notices, fixed batch/catch-up/retry limits and recipient preferences remain intact.
- General timezone changes retain the existing application/database prohibition once any schedule/session exists. No timestamp reinterpretation occurs. Member locale precedence and immutable sold commercial values are preserved.
- Settings audit records override/effective before and after, actor, organization and time atomically. No-op/read noise, secrets, QR credentials and member PII are absent. Existing append-only audit regressions pass.
- Web controls are bounded, localized, permission-sensitive and confirmed before policy changes. Native scanner treats settings as UX hints, uses the fixed API origin, and keeps QR/token input transient. Browser session requests retain CSRF protection.

Development checks exposed an initially unsupported audit subject type; the final writer uses the existing organization subject. Strict empty-string handling and preservation of default started/cancelled-session error ordering were corrected before final backend verification. Browser fixtures were updated for the timezone selector and existing tenant-hiding 404 semantics. No domain invariant was weakened to obtain passing tests.

## Tooling record and precise limitations

Authoritative artifacts:

`/home/gunner/.codex/state/plugins/codex-security/scans/pilates/a1597f6cdbb52ace60cc2d8a7e768005877cedc8_20260924T183342Z_yvcy1ofz/`

The exact canonical threat model was retained unchanged in checkpoint and final draft. Candidates were recorded once as an empty list. A draft argument initially omitted the required deferred reason field; the corrected checkpoint and final draft were accepted. Completion was called once, succeeded, and sealed artifacts were read back. No measured token usage was exposed.

**The sealed tool coverage is labeled partial because it retained the earlier “Independent backend discovery in progress” checkpoint despite the final `complete:true` draft explicitly clearing deferred work.** The independent backend review completed with no candidates before finalization; all three final coverage surfaces are recorded. This is the same stale-checkpoint limitation identified in GT-004, not an unreviewed backend surface. We report it without modifying the sealed artifact or claiming the tool's coverage flag is complete.

The generated source inventory omitted PHP tests, browser fixtures and documentation; the parent reviewed the complete actual diff, including untracked additions. The initial captured snapshot precedes two compatibility changes (Allocation eligibility before policy and cancellation cutoff conflict only for future sessions), final test updates and documentation. The backend reviewer and parent explicitly inspected those current-source differences. Final reporting/browser-test edits were manually checked after source review. Prior milestone scans were not used as evidence for GT-014.

Daybreak access was reported not granted, so protected output display may be limited; local canonical artifacts were available. A no-findings result does not prove absence of vulnerabilities. Production TLS/proxy logging, key custody, scheduler/provider operations, and physical native camera/OS behavior are not established by this local review. A QR can still be forwarded during its short lifetime; configuration adds no proximity proof. Authorized same-key edits use last serialized writer wins rather than optimistic conflict detection. Native physical-device validation and release/deployment remain separate work.

Final verification after the scan: all 20 desktop/mobile-sized browser flows passed. Their final fixture and selector changes and this reporting documentation were manually reviewed; no runtime source changed after the completed independent current-source review.
