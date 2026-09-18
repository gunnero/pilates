# Audit architecture

GT-001 provides an audit **design**, not a generic event recorder or mutable audit CRUD. There are no business writes, permission-editing APIs or organization-management actions to audit yet. Operational error logs are deliberately bounded diagnostics and are not business audit evidence.

The first sensitive domain write must introduce a PostgreSQL append-only audit table and transactionally write its evidence alongside the domain change. Required fields: internal ID/public UUIDv7, organization_id, actor user ID (nullable only for explicit system actor), action key, subject type and public ID, occurred_at UTC, request/correlation ID and bounded allowlisted JSON metadata. Cap metadata size and depth. Record relevant before/after values or reason codes without passwords, tokens, session IDs, unnecessary contact data or unbounded free text.

Audited actions will include payment recorded/corrected/reversed, manual attendance edits, staff reservation creation/cancellation, permanent-spot changes, permission changes, trainer financial access grants/revocations and organization settings changes. Actor and organization must come from verified context, never submitted identity fields.

Runtime database privileges must permit audit INSERT and scoped SELECT but deny UPDATE/DELETE. Use a distinct migration/retention role, and consider database triggers plus off-host append-only exports for stronger tamper evidence. Ordinary users must never edit/delete evidence. Audit viewing is itself permission- and tenant-scoped. Retention and lawful deletion requirements need a policy before collecting real data.

Do not equate an application model's lack of update methods with tamper-proof storage. Current local DB owner credentials are for development/migrations and do not implement future production append-only privileges. Fail the sensitive transaction if required audit evidence cannot be persisted; asynchronous Redis logging cannot satisfy this guarantee.
