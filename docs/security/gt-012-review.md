# GT-012 security review

Base: `ce927bb7d15d67ded53499db6b54f24fc0c3750c` (`GT-011: establish member self-service`). Codex Security diff scan `49725557-0265-48b9-893a-b1ea1cc85b7d` completed and sealed against working-tree snapshot `fe82a5817e4e8eb2b45276ec21219663444d4f5783cf5f49390de375277bd811`. It found **zero security candidates/findings**, covering all 32 generated source-inventory files. This is bounded review evidence, not proof that every vulnerability is absent.

Retained local artifacts are under `/home/gunner/.codex/state/plugins/codex-security/scans/pilates/ce927bb7d15d67ded53499db6b54f24fc0c3750c_20260924T171402Z_29myjg4a/`: `report.md`, `coverage.json`, `findings.json`, `scan-manifest.json`, `exports/results.sarif`, and exact independent architecture/threat-model copies under `artifacts/01_context/`. The canonical six-field threat model preserves the independent architecture pass, effective resource locations and documentation qualifications. No shared model cache was substituted.

Preflight passed all three evaluated capabilities with no configuration changes. A dedicated worker reviewed 12 native core/session/API/cache/link/push/storage files; the parent reviewed the other 20 inventory files and the complete remaining diff. Generated inventory omits tracked PHP test changes, browser API test, documentation, ignores and lockfile: these were **separately manually reviewed**, including the guarded reset capture and lack of production binding. No native/device/runtime claim follows from the inventory count.

The tool reported Daybreak access not granted; protected outputs may not be displayable. Its advisory did not block the review. The completed workbench reports rollout usage of 4,726,203 total tokens (4,705,072 input, including 4,567,808 cached; 21,131 output). This is the tool's rollout measurement, not a separately measured GT-012 implementation budget.

## Boundaries reviewed

- SecureStore-only token persistence; no AsyncStorage, analytics, persistent profile/finance cache or sensitive logging.
- Fixed configured API origin, release HTTPS, explicit development-only local HTTP, captured bearer, omitted cookie transport, timeout/error reduction and no automatic POST replay. Native enforcement of fetch options remains a device-validation limit.
- Account/studio query keys, epoch guards, navigator remounts, cache cancellation/removal and late-response rejection. Organization discovery has account-scoped keys but does not forward transport AbortSignal; removal prevents old query data from reappearing.
- Existing MemberContext/RecipientAccess/Allocation remain the authority for UUID ownership, financial privacy, booking/FIFO, attendance and notification recipient checks. No new grants, bypasses or production writers.
- Strict supported link kinds, bounded fragment capabilities, normalized UUID destination paths, no arbitrary URL opening/WebViews. Capability state after a failed form submission is transient; consumed secrets clear on success/cancellation.
- Native provider boundary has a null registry; no registration, persistence or external delivery is enabled. Production push requires future reviewed backend/provider work.
- Test capture writes only private ignored `.local` files outside public root, after e2e/gt_test/role/DB loopback checks. Playwright binds HTTP to loopback. Normal `public/index.php` does not install captures. Secrets checks and full PostgreSQL/browser tests remain enabled.
- Native dependencies were audited. The scoped xcode UUID update fixes GHSA-w5hq-g745-h8pq; its used v4 API is compatible and native prebuild passed. No advisory or lint failure is suppressed.

## Snapshot qualification and final manual follow-up

Documentation changed while the scan ran, so the workbench correctly warns that results belong to the **original snapshot**. After sealing, the following correctness-only fixes were reviewed separately and tested; the sealed result is not represented as a scan of those later bytes:

1. A 403 during `/account` restoration now clears to anonymous instead of leaving the restoring spinner active. Ready accounts can still leave a denied studio without losing their valid account identity.
2. Domain query error screens expose a studio-exit action even after a removed link returns 404; a named `leaveOrganization` method clears context/cache. This prevents a recovery dead end, without granting access or weakening server checks.
3. Headerless auth/studio/loading/configuration screens include top safe-area padding; stack screens retain header-managed top spacing.
4. Regression tests cover the 403/404 cases; docs clarify failed capability state, organization transport cancellation, link normalization and unavailable native validation. AGENTS reflects GT-012 scope.

These are availability/usability fixes, not previously validated unauthorized-access findings. No in-scope security finding remains unresolved. Tests and final review include the changes above.

## Explicit limits

No physical iOS/Android device, simulator, signing, OS storage/reinstall/backup behavior, native transport enforcement, VoiceOver/TalkBack or real push-provider delivery was available. iOS/Android Hermes export, Expo Doctor, native project generation, component behavior and actual HTTP/PostgreSQL integration were tested. Production domain association/universal links, push registration and store release remain deferred. Offline logout cannot promise remote revocation; the UI explains this and clears local state immediately. Existing trusted DB/operator boundaries and GT-011 in-flight-request limits remain unchanged.
