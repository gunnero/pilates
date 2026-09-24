# GT-009 security review

Reviewed 2026-09-24 against GT-008 b5000ca7ba29fc2cb71a40e98cdf4fe95b123ae5. Scope: memberships, immutable cash/reversals, finance authorization and serialization, UI confirmation and migrations. No GT-010 work.

## Automated/tool-assisted review

Codex Security `security-diff-scan` completed scan `e57498f6-a77e-4ef9-aa5c-e2ae58be1c8a` over its authoritative 33 changed source inventory files. Preflight was ready; a dedicated worker reviewed 24 backend files and supporting tenant/gate/lifecycle code, and the parent reviewed nine frontend/style/catalog/component-test files. No plausible supported-path vulnerability survived discovery; zero findings. The supplied repository threat model was retained. Report resides in the local Codex Security scan store under the scan ID, outside Git. No secrets or personal records are copied into this document.

Tool source inventory omits documentation and backend test directories. The parent reviewed those separately, including competing-process workers, fixtures and preservation regressions. Final added audit/constraint tests, membership-permission translations, browser selectors/updated placeholder expectations and verification documentation were reviewed after the fixed source scan. No financial authorization or ledger implementation changed after that scan. No repository SECURITY.md exists. A zero-finding scan is not proof of exhaustive absence of vulnerabilities; GT-004's stale baseline scan limitation does not cover these new paths, so current source review and direct tests are the evidence here.

## Architecture-aware checks

- Foreign tenant UUIDs fail closed for plans, memberships, client financial summaries, payments and reversals. Composite SQL FKs independently bind membership/customer/plan/tenant and payment/member/currency.
- Authentication and TenantContext precede permission decisions. Independent view/record/correct gates intersect token abilities. Mutations acquire the shared organization lock and re-resolve authority; a regression removes payment visibility at the lock boundary and proves no cash write occurs.
- Attendance permissions, TrainerProfile and selected-location access do not grant finance. Deliberately granted financial permissions are organization-wide; this is a documented V1 decision. Metrics additionally require reports.view.
- Membership-only callers receive validity and plan metadata without price, balances, paid status or payment history. Lookup is minimal JSON-body name search. React is not the authorization boundary.
- All system-owned fields are rejected by request allowlists. Amounts are strict bounded integers, currency is server-owned MKD, actors come from authentication. Parameterized search and enumerated filter operators avoid input interpolation.
- Original payments, reversals and sold snapshots cannot be silently rewritten or deleted. Full reversal is uniquely constrained and linked to the exact original amount/currency; concurrent reversals cannot both succeed.
- Membership overlap uses PostgreSQL exclusion as well as locked application validation. Cash/cancellation and cash/reversal races preserve evidence and exact final totals. UI retries retain an operation key; request-key reuse with changed contents fails closed.
- Gross/reversed membership aggregates come from the same SQL snapshot. Overpayments remain negative balances; no mutable payment-state counters exist.
- Audit inserts share the mutation transaction. Forced audit failure rolls back cash insertion. Existing audit immutability is regression-tested. Bounded audit metadata omits contact, names, descriptions and cash notes.
- HTTP responses retain no-store/private and existing security headers. Exception reporting logs class names only, not SQL bindings/request bodies. No online payment provider, secret token or notification integration was introduced.
- Frontend displays the client, amount and period before cash confirmation, requires reversal reason/confirmation, and retains original/reversal actor evidence. Standard React escaping handles names and notes; no HTML injection sink was introduced.

## Corrections during implementation

Validation caught and fixed a nullable cancellation-reason CHECK edge, stale frontend member-name shape, and reusable PostgreSQL function creation for test rebuilds. The full-suite migration fixtures now roll back the correct number of milestones and remove later permissions before older constraints are installed. These were fixed before completion, not suppressed.

## Limits

This is local application/security validation, not a deployed penetration test or physical cash reconciliation. Database owner/superuser DDL/TRUNCATE bypass is outside the application threat model. Metrics are live operational totals attributed to original paid_at dates, not sealed accounting reports. There is no physical-refund proof, partial reversal, fiscal receipt, retention purge, notification dispatch or mobile self-service. Existing GT-001 through GT-008 domain behavior remains authoritative.
