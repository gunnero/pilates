# GT-010 security review

Scope: notification changes against GT-009 `a152b48a238d56bb8330de6f9a7a084b02a4df7e`, including the API, recipient resolution, domain hooks, delivery/generation commands, database migration, localized inbox, tests and documentation. No external URLs or production systems were accessed.

## Manual review

- Recipient isolation: authenticated User links determine active StudioMember ownership; route UUIDs are selectors, not authority. Staff resolve current TenantContext, studio.access and sessions.view with current location/trainer scope. Bulk reads reject the whole set if one item is invisible. Token abilities only narrow access.
- Privacy: no amounts, balances, plan prices, contact information or notes enter notification payloads. Staff have operational session messages only. Explicit serialization omits internal identities and delivery errors. React renders text without HTML interpretation.
- Atomicity/replay: business state, notification evidence and outbox insert share organization-locked transactions. Unique recipient/event identity and one delivery row resist repeated events. Four real competing-process tests cover reminder, expiry, domain event and worker duplication.
- Stale state: delivery validates fresh source state before claim and publication. Cancelled reservations/sessions do not get reminders; material-change fingerprints suppress obsolete messages. A reversed undelivered receipt is suppressed. Historical delivered records remain distinguishable from current state.
- Delivery: durable lease claims, attempt evidence, matching-token finalization and terminal states prevent duplicate in-app publication. Backoff/max attempts and bounded polling limit retries. External delivery is not implemented; future providers must honor the stable idempotency key.
- Integrity: concrete composite FKs, source/type shape checks, bounded metadata, immutable evidence/read triggers and restricted delivery/attempt transitions reject tampering. Schema owners remain trusted. No broad grants, secrets, external providers or destructive forward migration were added.
- Operational boundaries: explicit tenant allowlist for scheduling; default empty list; no shared service changes. Populated migration regression and private dev backup/digests verify preservation.

Implementation-time fixes included incorrect metadata validation, suppressed/failed claim outcome reporting, pre-batch operational filters to prevent invalid candidates starving valid ones, stricter database transition/attempt checks, and batch-loaded inbox eligibility relations. These were corrected before review completion. No unresolved confirmed application vulnerability was identified.

## Codex Security diff scan

Skill: `security-diff-scan`, plugin 0.1.30. Scan ID `7bcbeb85-1151-42fc-853b-31f1c5b7d0a1`. Capability preflight ready; all 35 generated source inventory paths reviewed (26 backend/config/schema/routes by a dedicated reviewer, nine UI/localization paths by the parent). Changed tests/docs were additionally inspected manually. Zero candidate/confirmed findings; canonical coverage is complete for that inventory. No validation/attack-path finding phase was needed with zero candidates.

Private local report:
`/home/gunner/.codex/state/plugins/codex-security/scans/pilates/a152b48a238d56bb8330de6f9a7a084b02a4df7e_20260924T162131Z_8q00c5k8/report.md`

Artifact limitation: the sealed scan's threat-model text copy was truncated by the shell output limit. The full repository threat model was read and retained separately without modifying the sealed result:
`/home/gunner/.codex/state/plugins/codex-security/scans/pilates/artifacts-de3fa881614079485420160617fe4d3c9a7a167e6b9204ae45df568af120a662/artifacts/01_context/gt010-complete-threat-model.md`.
The GT-010 trust-boundary section was present in both copies. Final test/document updates and a scoped checkbox alignment adjustment (one CSS class plus styles, no data or authorization change) were manually reviewed after the scan snapshot; backend runtime source remained unchanged.

As documented since GT-004, an older clean scan does not establish coverage of new code. This scan targets the actual GT-010 source diff; it does not certify dependencies, deployment, database administrators, future account linking or external push providers. No findings is evidence of this review, not proof that vulnerabilities cannot exist.
