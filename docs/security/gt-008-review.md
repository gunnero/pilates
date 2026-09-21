# GT-008 focused security review

Baseline: c870b714523589cdd172731c6164aa5e416cd977. Scope is the working-tree attendance change and directly affected GT-007 integration; no production deployment or whole-host assurance is claimed.

## Method and coverage

The Codex Security security-diff-scan skill completed scan `a3429126-1c00-4b1a-ba27-a9a356861d2c`. Capability preflight was ready; no configuration changes were needed. Daybreak status was not_granted, with no programs; this advisory did not block review. The canonical inventory contained 29 source files. An independent backend reviewer examined 20 backend files, plus the later PermanentSpotController delta; the parent examined nine frontend/package files plus the Reservations refresh delta. All 31 surfaces closed with no plausible reportable security candidates. Supporting traces included TenantContext/Lifecycle/Gates, reservation lookup/allocation, reconciliation/materialization, request validation and audit immutability. No applicable SECURITY.md policy was found.

Canonical report remains private outside Git at `/tmp/codex-security-scans-JO6Ro5/pilates/c870b714523589cdd172731c6164aa5e416cd977_20260921T005620Z_cty20v7n/report.md`. The scan began before final documentation/test edits and two integration changes; those source deltas were reviewed explicitly, and the canonical scope records this limitation. It is not an immutable final-commit scan. Measured scan usage: 4,041,085 total tokens, 4,031,441 input, 3,794,560 cached input, 9,644 output. Temporary scanner outputs and live QR tokens are excluded from the commit.

## Manual review and evidence

- Tenant/IDOR/location/trainer: active context and sessions.view plus attendance.view before roster serialization; SessionDirectory constrains tenant and current selected-location/Trainer assignment. Negative API tests cover foreign resources, missing grants, wrong trainer and selected scope. Mutations re-resolve permissions after the shared organization lock; a revocation-between-middleware-and-lock regression returns 403.
- Fake check-in: body fields cannot choose method, actor, tenant, reservation or timestamps. Member domain logic resolves the sole linked active customer from its authenticated User, checks reservation and time, and is not exposed through HTTP. Offline members use authorized staff workflows.
- QR: Laravel authenticated encryption, version/tenant/session binding, issued/expiry checks, bounded input and server-enforced window. Backend tests exercise tampering, wrong session, expiry and member-window edges. Challenges contain no member PII, do not authenticate anyone and are intentionally reusable by different eligible authenticated members during their short lifetime. Replay by the same member is idempotent; no GPS or physical-presence proof is claimed.
- Concurrency: actual competing PostgreSQL connections/processes demonstrate check-in versus reservation cancellation, walk-in versus final place, duplicate check-in, no-show finalization versus authorized late present correction, and session cancellation versus check-in. Tests observe both workers waiting on the held organization lock before release. Capacity and attendance checkers remain zero.
- Audit/privacy: explicit public-ID/minimal-name roster, no contacts/notes/financial data, bounded reason codes and before/after values, atomic evidence and audit-failure rollback. QR responses are no-store and frontend generation is local, with no token URL, console log, external generator or persistent storage.
- Bounds: roster/today pagination, capped lookup, finalizer tenant/date/session limits, and fixed allocator capacity. No persisted attendance counters or Redis correctness dependency.

## Findings and fixes

No confirmed security vulnerabilities remained in the reviewed GT-008 implementation. Integration testing exposed a preexisting GT-007 reliability bug: a new PermanentSpot's null ID selected unrelated manual reservations when determining affected future sessions, creating cross-tenant failure coupling. The query now runs only for persisted assignments and explicitly filters organization. An independent reviewer verified the fix, and a foreign-manual-reservation regression covers it. This was not misrepresented as a new GT-008 security exploit.

Additional integration corrections refreshed the reservation list after walk-in and rendered check-in timestamps in the session timezone. Initial frontend QR testing used an unrealistic far-future expiry that overflowed browser timer bounds; the test now uses the implemented 90-second token contract. Final successful checks are recorded separately.

## Limits

Zero scan findings do not prove complete security. The scan excludes unrelated historical source, dependency internals and deployment configuration; the full regression suite and dependency audits supplement it. The prior GT-004 stale-coverage limitation is not treated as evidence for GT-008: changed entry points and their current supporting controls were inspected directly. Direct SQL/schema-owner access remains trusted; service locks enforce cross-table lifecycle and aggregate capacity. Existing attendance.view grants deliberately remain intact. Future member authentication/linking, mobile transport, telemetry, retention and exceptional cancellation need separate design/review.
