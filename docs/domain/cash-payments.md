# Memberships and cash — GT-009

Implemented domains are deliberately separate: StudioMember is a customer, MembershipPlan is a reusable offer, MemberMembership is one sold duration entitlement, CashPayment records money received, PaymentReversal corrects that record. None grants staff identity, reserves a session, or enforces booking/attendance eligibility.

## Plans and membership periods

Plans belong to an organization and have UUIDv7 public identifiers, name (160), optional description (1000), price, MKD currency, duration and active/inactive/archived status. Archived is terminal through the API. Inactive/archived plans cannot be assigned. There is no delete endpoint; tenant-qualified historical membership foreign keys prevent deleting used plans.

A membership snapshots plan name, agreed price, currency, duration unit/count, start/end dates and creator. Updating a plan never rewrites sold terms. PostgreSQL rejects snapshot changes and membership deletion. Cancellation is the only one-way modification, preserving timestamp, actor and finite reason. Repeating cancellation is an audited no-op; cancellation does not erase the financial obligation, refund cash, or cancel bookings. Payments cannot be newly recorded against a cancelled membership; an identical retry of an already-recorded payment returns its original record. Reversals remain possible after cancellation.

Dates are inclusive organization-local calendar dates: start ≤ today ≤ end is active. Other derived states are upcoming and expired; cancellation takes precedence. No stored expired flag or cron is required. Starts are bounded to 2000–2100, computed ends through 2103.

Duration is `days` (1–1095) or `months` (1–36). End is start plus duration minus one day. Calendar-month addition first clamps the anniversary to the last valid day of its month; then subtracts one day. Thus September 1 + one month ends September 30; January 31, 2026 + one month ends February 27; leap-year January 31, 2024 ends February 28. These anniversary semantics are explicit, and the UI previews the exact inclusive period before assignment. Custom price/date adjustments are not supported.

The initial entitlement is `studio_duration`. Two uncancelled memberships for the same member cannot share any date, even if one is future or a different duration plan. Adjacent periods may start on the day after the prior end. A PostgreSQL GiST exclusion constraint using native `int8range` and inclusive `daterange` enforces this without an extension. Future distinct products must deliberately extend the entitlement model; GT-009 does not assume all future products are mutually exclusive.

## Money and immutable ledger

All money uses integer minor units: 100 = 1 MKD, 150000 = 1500 MKD. Prices allow zero through 100000000; cash payments require 1 through 100000000. JSON numeric strings, fractions, non-MKD currency and mass-assigned system fields are rejected. Zero-price memberships are paid at zero. UI decimal entry converts digits to minor units without decimal-money rounding.

A cash payment durably binds organization, customer, membership, integer amount, MKD, method `cash`, explicit `paid_at`, authenticated recorded_by, created_at and optional 500-character note. The customer/currency come from the membership, never request input. Backdated payment times from 2000 onward are permitted, future times are rejected, and seconds are the stored precision. The form labels its date/time **UTC**; membership validity remains organization-local. Expired/upcoming memberships and inactive clients may settle existing obligations, provided the membership is not cancelled. Assignment itself requires an active customer and plan.

Payments have no update/delete routes. PostgreSQL triggers reject every ordinary UPDATE/DELETE of payments and reversals. A reversal appends the full original amount/currency, authenticated actor, timestamp and one reason: wrong_member, wrong_amount, wrong_date, duplicate or other. A unique cash-payment FK permits only one reversal; a composite FK ensures exact original tenant/amount/currency. A reversal corrects the ledger, not proof of a physical refund. Record the replacement payment separately. Original payments remain visible as `reversed`; `recorded`/`reversed` is derived from the linked reversal, not a mutable duplicate state.

Each recording requires a UUID `request_id`, unique within the organization. An identical request (membership, amount, paid time, actor and note) returns the original payment. Reusing the key with different contents returns 409. The UI retains the confirmation and key after an error so retries do not duplicate money. A new separate confirmation creates a new key. At most 10000 payments per membership is an application safety bound.

Partial and multiple payments are supported. Gross = all recorded amounts; reversed = linked reversals; net = gross − reversed; balance = agreed − net. Negative balances are retained as overpayment. Status is overpaid when balance < 0, paid at zero, unpaid when net = 0 and balance > 0, otherwise partially_paid. Summations use PostgreSQL integer/numeric arithmetic and PHP integers. A single SELECT snapshot obtains both gross and reversed to avoid read-skew between competing transactions. There are no mutable balance counters.

## Authority and privacy

Every HTTP request authenticates, resolves TenantContext and requires studio.access. Financial permissions are **organization-wide deliberately granted powers**, including selected-location staff who receive them. Session/location visibility does not imply financial authority, and financial grants do not broaden the ordinary member directory. TrainerProfile grants nothing.

- `memberships.view` OR `payments.view`: minimal plan/membership directory, member name and validity. Money fields require `payments.view` independently.
- `memberships.view` + `memberships.manage`: assign/cancel membership. Plan creation/edit additionally requires `payments.view` because plan price is editable.
- `payments.view`: prices, agreed/gross/reversed/net/balance/payment state and payment history/detail; financial member summary.
- `payments.view` + `payments.record`: record cash.
- `payments.view` + `payments.correct`: reverse, independently of record permission.
- `payments.view` + `reports.view`: organization metrics. Ordinary trainer payment viewing does not grant totals.

No API resource returns raw Eloquent records or internal IDs. Financial fields are absent, not null placeholders, for non-financial viewers. Membership lookup only returns public client ID/display name and uses JSON POST search. Notes appear only on authorized payment detail; they are not duplicated into audit, lists, URLs or operational logs. Existing private response handling remains in force. Authorized actor display names are part of ledger evidence.

Migration widens permission vocabulary without changing any existing grants. New owner/administrator bundles gain basic membership permissions; administrator/trainer bundles gain no payments permissions. Existing owner payment defaults are unchanged. Trusted operators may explicitly run `php artisan gt:finance-access-enable ORGANIZATION_UUID STAFF_MEMBERSHIP_UUID --reason='Approved financial adoption'` for an active all-location owner/administrator. It grants the five membership/payment permissions, not reports.view, and audits only actual changes. Trainer opt-in uses the existing explicit staff-permission editor/delegation rules. No adoption was run on development staff automatically.

## Transactions, database and operational checks

Every mutation takes the shared Organization row lock, re-resolves current authority, checks its independent permission dependencies and writes audit evidence in the same transaction. Payment/cancellation additionally lock the membership; reversals lock the original payment after the organization lock. This serializes competing lifecycle and staff-revocation operations. SQL constraints remain the backstop for ownership, amounts, immutable history, overlap and duplicate reversal.

Tenant-qualified FKs bind memberships to their customer/plan, payments to matching membership/customer/currency and reversals to matching payment/amount/currency. Controlled states/reasons, date and amount bounds, UUID uniqueness and append-only triggers protect stored evidence. Indexes cover organization/status, organization/end/start dates, organization/customer, organization/membership, membership aggregate lookup, organization/paid_at, organization/customer/paid_at and organization/reversal-created_at. Ledger history is retained; there is no purge API. Database-owner/superuser tampering and TRUNCATE are outside application guarantees.

Four real competing-process tests wait for both workers to block on the PostgreSQL organization lock before release: duplicate reversal, cash versus membership cancellation, overlapping assignment, and cash versus reversal. Tests verify valid outcomes and exact resulting balances. A payment/cancel race may record money first and then cancel, or cancel first and reject the payment; neither loses evidence.

`php artisan gt:finance:check` is read-only and emits safe counts for tenant mismatch, duplicate/mismatched reversal, overlap and independently calculated net disagreement. It exits nonzero on any inconsistency. This is a structural check, not proof of physical cash reconciliation or authorization; HTTP privacy tests cover unauthorized serialization separately. No customer names, cash notes or individual amounts enter operational logs.

## API and query foundation

All paths below are relative to `/api/v1/organizations/{organization}` and use UUIDs:

| Method      | Path                               | Purpose                                               |
| ----------- | ---------------------------------- | ----------------------------------------------------- |
| GET / POST  | `/membership-plans`                | Paginated plans / create                              |
| GET / PATCH | `/membership-plans/{plan}`         | Detail / full validated editable fields and lifecycle |
| POST        | `/membership-plans/{plan}/preview` | Start date → computed end and permitted plan fields   |
| GET / POST  | `/memberships`                     | Filtered directory / assign                           |
| GET         | `/memberships/{membership}`        | Explicit resource with gated monetary fields          |
| POST        | `/memberships/{membership}/cancel` | One-way cancellation with reason                      |
| GET         | `/members/{member}/memberships`    | Customer membership history                           |
| GET         | `/finance/members/{member}`        | Financial membership summary (payments.view)          |
| POST        | `/finance/member-lookup`           | Minimal active-client name lookup                     |
| GET / POST  | `/cash-payments`                   | Paginated history / record                            |
| GET         | `/cash-payments/{payment}`         | Detail including bounded note                         |
| POST        | `/cash-payments/{payment}/reverse` | Full reversal with finite reason                      |
| GET         | `/finance/metrics`                 | Payment + report permission gated query foundation    |

Lists cap per_page at 100 and page at 10000. Membership filters: member_id, state (active, upcoming, expired, cancelled, expiring) and payment_state (unpaid, partially_paid, paid, overpaid). Expiring means active and inclusive end within the next **7 days**, centrally defined by MembershipPeriod. Ledger filters are member_id/membership_id. Relationships are eager loaded; no full history is preloaded for one page.

FinanceMetrics returns active, expiring, outstanding (positive balance, including cancelled obligations), net cash today and month. Date boundaries use the organization timezone, then UTC timestamps. Net cash is attributed to the original paid_at period; a later reversal restates that period. These are live derived operational figures, not sealed accounting reports. No financial dashboard is built.

The responsive Macedonian/English UI includes plans, directory filters, profile membership/amounts/history, assignment preview, cash confirmation (client, amount, period), reversal reason/confirmation and retained actor evidence. Plans and client membership histories paginate. Payment date is currently explicitly UTC. No custom-price discounts, prorations, credits/session-count packages, partial reversal, bank/card refunds, fiscal receipts, invoices, integrations, notifications or mobile payment/self-service is included. GT-010 was not started. Future notification work can use the central expiring query; it must add its own consent and delivery behavior.
