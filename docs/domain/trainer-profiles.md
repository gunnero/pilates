# Trainer operational profiles (GT-004)

User is the authentication identity. OrganizationMembership is that user's staff access in one organization. TrainerProfile is an optional operational teaching profile for that membership. StudioMember is a customer/client; a future purchased Membership will be an entitlement. None of these terms are interchangeable.

## Model and enablement

OrganizationMembership has zero or one TrainerProfile. An authorized all-location manager explicitly enables any active Owner, Administrator, Trainer or Staff membership. Role alone never creates a profile. Enablement creates no User, credentials, staff membership or permission grants. Duplicate enablement returns 409 and retains the original profile.

TrainerProfile contains internal bigint ID, immutable public UUIDv7, organization and membership keys, nullable display-name override (160 characters), nullable plain-text short bio (1000), active/inactive status and UTC timestamps. The organization key exists to enforce a composite tenant-qualified membership foreign key and support tenant predicates. PostgreSQL also enforces one profile per membership, public UUID uniqueness, nonblank display name, bounded fields and controlled status. There is no email, permission array, photo/upload infrastructure, color or duplicated location assignment table.

The effective display name falls back to User.name when no override is set. An override lets a studio choose a teaching name without changing authentication identity. A later identity-name change consequently affects profiles without overrides. Optional bios should describe teaching only; do not enter contact details or sensitive personal information.

## Lifecycle

Enablement creates active. Active ↔ inactive is reversible through the dedicated status endpoint; repeated current-state requests are no-ops. Inactive profiles remain visible as history but have no eligible locations. No ordinary DELETE, archive state or delete/recreate restoration exists. Deactivation never suspends staff access or changes grants.

Enablement and reactivation require an active underlying staff membership. Suspending/revoking staff immediately removes operational eligibility without rewriting TrainerProfile.status; suspended staff can regain eligibility on staff reactivation if their profile remained active. Revoked staff remain terminal under GT-002. All-location authorized viewers can inspect historical profiles for suspended/revoked staff; managers can edit their operational text or deactivate them. Organization suspension denies the entire tenant API.

## Locations, visibility and future assignment

Eligibility is calculated from current database rows: active organization, active staff membership, active trainer profile, active location in the same organization and the staff member's all/selected location scope. All scope includes future active locations; selected scope includes only current assignments. GT-002 assignments are authoritative. A separate teaching-location table is deferred until there is a genuine narrower business rule.

Viewer scope additionally intersects these locations. All-location trainers.view callers see the organization's full trainer directory, including inactive and historical profiles. Selected-location callers see profiles only for active staff sharing at least one currently active accessible location. An inactive profile can be inspected at such a shared location, but is not eligible. Unshared/foreign UUIDs return 404. Missing assignments fail closed. The eligible count and assignable flag are relative to the viewer's visible locations.

Lists return at most five eligible location names/UUIDs per profile plus total visible count, using one batched query; the profile's locations endpoint is paginated. The location filter means **currently eligible at this location**, so combining inactive status with a location filter returns no trainers. No eligibility is persisted or cached. Subsequent reads reflect assignment removal, staff suspension and location deactivation.

These are operational discovery reads, not a reservation or authorization token. Future group/session assignment writes must acquire the shared organization lock, re-resolve actor authority, and recheck the target trainer and location predicates inside the transaction. A previously rendered assignable flag cannot authorize a future write. Historical sessions should reference the retained profile rather than use a mutable role string. No groups, sessions, scheduling or assignment endpoints are introduced here.

A TrainerProfile creates **no client relationship**. GT-003's all-location requirement for StudioMember access remains unchanged even if selected-location trainers receive members.view. Genuine group/session relationships must exist before changing that policy.

## Permissions and adoption

The catalog has 22 permissions. trainers.view permits the operational directory/detail/eligible-location names in the viewer's scope; it does not require staff.view or locations.view. The latter is required for the UI's location-picker source. trainers.manage additionally permits enable/update/status operations, with trainers.view and all-location scope. A manager need not have staff.manage to change teaching information; editing staff authority still follows GT-002. Staff UI enablement also requires staff.view to discover the existing target.

New Owner/Administrator bundles add trainers.view/manage; new Trainer bundles add trainers.view; Staff stays unchanged. Owner now has 22 default grants, Administrator 17, Trainer 5, Staff 4. No trainer capability implies clients, financial access, permission delegation or owner custody. An Administrator may manage an Owner's operational profile without changing that Owner's staff authority.

The additive migration preserves every existing stored grant. After a private backup and migration, deliberately adopt existing active all-location Owner/Administrator access with:

```bash
php artisan gt:trainer-access-enable ORGANIZATION_UUID STAFF_MEMBERSHIP_UUID --reason='GT-004 adoption'
```

This trusted host command adds only trainers.view/manage, records bounded system-actor staff.permissions_changed evidence, and creates no profile. Existing permission managers can delegate capabilities through the established bounded grant editor. Existing trainers are not automatically granted new access or profiles.

## Audit and presentation

All supported writes take the common organization row lock, refresh actor authority and write evidence in the same transaction. Events are trainer.enabled, trainer.profile_updated, trainer.deactivated and trainer.activated, subject trainer_profile/public UUID. Evidence includes bounded changed-field names, membership UUID on enablement and status transitions; it excludes display-name and bio values. Audit failure rolls back the mutation. Uniqueness is the final database invariant; competing supported enablements serialize to one 201 and one 409.

The current representation is staff-only: public profile/membership IDs, effective/override display name, profile status, staff role/status/scope, visible eligibility and detail-only bio. It includes no internal IDs, email, tokens, grants or audit metadata. Staff directory integration emits only profile UUID/status and only for all-location trainers.view callers; selected staff viewers do not gain a second path around trainer visibility.

A future member-facing schedule may expose a deliberately selected display name and approved short bio. It must use a separate representation and policy; never reuse this staff response containing role, staff status or membership UUID. There is no public profile endpoint or promise that current text is publication-approved. Photos and consent/publication controls remain deferred.

See [API](../architecture/api.md), [focused review](../security/gt-004-review.md) and [verification](../development/gt-004-verification.md).
