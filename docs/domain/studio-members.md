# Studio members / clients (GT-003)

**StudioMember is a customer attending training at an Organization. OrganizationMembership is authenticated staff access. Neither is a purchased membership/package.** A customer can have no User account, phone, email or smartphone. The same human may have independent records in multiple organizations; there is no global contact matching.

## Identity and contact

A client has an internal bigint ID, public UUIDv7, immutable API organization ownership, first/last name (required, each ≤100), optional phone/email, controlled status, preferred locale, digital access mode, optional preferred location, optional operational notes and UTC timestamps. Names alone are sufficient for an offline customer. No placeholder contact or credentials are generated.

Email is trimmed/lowercased and validated, max 254 characters; it is not unique. Phone is optional, stored as `+` followed by 7–15 digits, with a nonzero first digit. Spaces, hyphens, periods and parentheses are removed; an international `00` prefix becomes `+`. Local-only numbers and extensions are rejected rather than guessing a country. This is syntax normalization, not number ownership/reachability verification. No North Macedonia-only schema assumption is made.

Duplicate detection returns a boolean warning after a successful create/update when another record in the **same organization**, including archives, shares canonical email, canonical phone or a case-insensitive exact first+last name. It returns no candidate contact/name list. Shared family contacts and same names remain allowed; there is no uniqueness constraint, automatic merge or fuzzy/global matching. Concurrent duplicates can exist; the warning is advisory.

## Lifecycle and workflow preference

| State    | Behavior                                                                                                                |
| -------- | ----------------------------------------------------------------------------------------------------------------------- |
| active   | Normal participating client; default at creation                                                                        |
| inactive | Retained, temporarily not participating; normal profile editing                                                         |
| archived | Historical record; excluded from default directory; detail remains available to authorized staff; profile edits blocked |

Active ↔ inactive and either → archived are allowed. Archived → inactive is the deliberate restore operation; archived → active is rejected until restoration. Same-state requests are harmless no-ops without another audit event. There is no DELETE endpoint. Archival is not erasure/anonymization and never changes staff authorization.

`digital_access_mode` is one controlled enum: `app` (mobile app preference), `assisted` (staff assistance), `offline` (without an app, default). None grants authentication, creates an account or implies a phone exists. All modes may exist without contact data. This is workflow preference, not notification consent; messages and push notifications are not implemented.

## Future account linkage

Nullable user_id is minimal schema preparation only, with a restricted user FK and uniqueness on `(organization_id,user_id)` when nonnull. Multiple unlinked clients are allowed. The same User may link independently to one client per tenant in future. No API accepts or exposes this column. A future verified claim/invitation process must establish identity, handle duplicate profiles and audit the link without changing organization ownership. It must not match accounts automatically by phone/email or create OrganizationMembership. A linked User without staff membership still cannot access staff APIs; there is no customer login/self representation yet.

## Locations and temporary staff visibility

Clients belong to the organization, not a location. Optional preferred/home location is a preference only, never booking eligibility or evidence that a client attends a branch. A composite FK guarantees matching organization. New assignments require an active location; an existing preference can remain when its location becomes inactive, or be cleared. Assignment/filtering requires locations.view; location details in member representations are omitted without it.

**Every client endpoint currently requires all-location staff scope, regardless of role.** Selected-location staff receive 403 even when they hold members.view/manage-related permissions or their assigned location matches the client's preference. This intentionally avoids organization-wide customer exposure before sessions/groups provide genuine relationships. Future GT-004+ work must define relationship-based visibility before relaxing the rule; a preferred location alone must never authorize it. No role-name bypass exists.

## Permissions and rollout

Six independent permissions: `members.view`, `members.create`, `members.update`, `members.status.manage`, `members.notes.view`, `members.notes.manage`. All routes additionally require active organization/staff membership, studio.access and all-location scope. members.view is a prerequisite even for writes, since successful writes return a profile and duplicate signal. Create/update/status each require their specific capability. Notes writes additionally require notes.manage; notes contents are loaded/serialized only on detail/write responses with notes.view, never on directory pages. A custom notes manager without notes.view may explicitly replace notes via API, but the UI preserves undisclosed existing notes.

New Owner and Administrator bundles contain all six. Trainer and Staff defaults contain none; owners can explicitly delegate visibility/other operations once branch scope permits. Financial permissions remain independent and unchanged. Existing GT-002 memberships retain their exact stored grants during migration; the finite database catalog is expanded from 14 to 20. To deliberately enable a legacy owner/administrator:

```bash
php artisan gt:member-access-enable ORGANIZATION_UUID STAFF_MEMBERSHIP_UUID --reason='GT-003 adoption'
```

This trusted host command requires an active all-location owner/administrator record, uses the common organization lock, and audits the before/after grants with system actor. It creates no account or password. Once enabled, ordinary bounded permission delegation manages client access. No automatic role-derived grant expansion is applied to existing users.

## Privacy and audit

Operational notes are optional and limited to 2000 characters: contact preferences, preferred training times or staff assistance only. The UI explicitly discourages diagnoses and sensitive health data. There are no structured medical fields. Staff can still enter unsuitable free text, so studio policy and training remain necessary.

Member mutations follow GT-002's organization row lock, fresh authorization and atomic audit protocol. Events include member.created, member.profile_changed, member.status_changed, member.digital_access_changed, member.preferred_location_changed and member.notes_changed. Profile metadata contains changed field names, contact-present booleans, locale and non-PII lifecycle/preference values. It deliberately cannot reconstruct old names or contact values. Notes events record only that notes changed. No names, email/phone values or note text are duplicated in audit metadata. Existing append-only triggers and 8192-byte bound remain unchanged.

No new PII logging, debug output, browser storage or analytics was introduced. Contact search uses POST JSON, not URL query parameters. Exceptions remain generic/class-only. PII is necessarily present in authorized responses, application memory, primary DB and backups; it is not field-encrypted by this task. Production HTTPS, DB/runtime privilege separation, backup/access controls and redacted infrastructure logging remain prerequisites. App no-store headers apply to profiles and search.

Before production, define tenant retention, export, correction, anonymization/deletion and future historical-reference handling, plus audit and backup retention. Archival alone is not a privacy-deletion workflow. No legal compliance certification or GDPR workflow is claimed.

## Directory and performance

The localized Клиенти directory supports prefix search, status/digital access filters, pagination, create, detail, update and deliberate status changes. Default directory includes active/inactive; archived/all filters explicitly include history. Optional location filtering is supported by API. Empty/error states show no invented financial, package or attendance data.

Search is 2–100 characters, case-insensitive prefix on first/last/full name and canonical email, normalized prefix on phone. SQL parameters are bound and LIKE wildcard characters escaped; unrestricted substring/fuzzy search is absent. PostgreSQL tenant-leading B-tree pattern indexes cover names/contact; ordinary indexes cover status, digital mode and preferred location. Pages default to 25, max 100, max page 10000, ordered by ID. Preferred locations load in one eager query; tests assert this for a multi-client page. Notes are excluded from list SELECTs. No external search service is needed.

Schema rollback is a trusted destructive operation for the new client table. It preserves audit evidence and explicit grants with legacy constraints marked NOT VALID, rather than deleting evidence to fit the old catalog. Old application code cannot authorize new permission names. Operators must plan backups/recovery and grant cleanup before real rollback; normal operation uses forward migrations.

Deferred: packages/memberships, payments, groups, schedules/sessions, reservations, permanent spots, waiting lists, attendance, check-in, mobile app/authentication, push, invitations, merge workflows, audit timeline and privacy export/deletion tooling.
