# Future cash-payment invariants — not implemented

Initially cash only, default currency MKD. Every payment must preserve organization, member, integer minor-unit amount, currency, paid_at timestamp, membership/period being paid, payment method, recorded_by user, created_at and optional bounded note. All associations must belong to the same organization.

Recording requires payments.record; viewing requires payments.view. Corrections require payments.correct independently, and reporting requires reports.view. A trainer may receive viewing/recording authority without correction/report access. Authorize before financial queries or response serialization.

Original payments must remain visible as historical evidence. Corrections/reversals append linked records and preserve actor, time, reason, original values, replacement values and relationship to the original. No silent overwrite, delete or soft-delete disappearance. Reconciliation derives effective totals from the ledger; corrections must not double-count.

Create payment and bounded audit evidence in one PostgreSQL transaction. Tenant/operation-scoped idempotency keys prevent repeated cash recording after network retries; correction chains need constraints/locking to prevent incompatible concurrent corrections. Monetary values never use floating-point arithmetic. Define retention, backdated-entry policy, cash reconciliation and allowed reversal semantics before implementation.

No payment table, recording endpoint, financial serialization, membership accounting or cash UI exists in GT-001.
