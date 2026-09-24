# System overview and repository ownership

GT is a modular monolith. Laravel owns HTTP authentication, authorization, relational state and all future domain rules. React is a first-party same-origin admin client. Future trainer screens and an Expo/React Native member app will use the same `/api/v1` API. No mobile application or microservice exists.

Backend source is `app/`, migrations/factories are `database/`, routes are `routes/`. Frontend code and component tests live in `resources/js/`, CSS in `resources/css/`, and the server-rendered HTML entry point in `resources/views/app.blade.php`. Both package manifests and lockfiles live at the root. This is one deployable application with obvious source ownership, not two projects or a nested pilates directory.

Current primitives: global User identity, Organization, Location, OrganizationMembership (staff access), StudioMember (customer identity, optionally linked to a User), optional TrainerProfile teaching information linked to staff access, and TrainingGroup/ScheduleRule definitions. GT-002 adds persisted status, explicit role bundles/grants, selected-location access, audited lifecycle mutations, safe organization switching and management screens. Authentication/recovery and the translated responsive shell remain from GT-001. GT-003 adds client directory/create/profile, notes protection, controlled digital preference and lifecycle, and privacy-minimized audit. GT-004 adds explicit trainer enablement, directory/profile/status and current staff-derived location eligibility, without new authentication or grants. See [trainer decisions](../domain/trainer-profiles.md). GT-005 adds groups, nullable primary trainer assignment, capacity and local weekly effective periods with transactional conflict protection and responsive editing. See [group/schedule decisions](../domain/training-groups.md). GT-006 adds durable TrainingSession snapshots, recurring generation and the operational agenda; see [sessions](../domain/training-sessions.md). GT-007 implements reservations, permanent places and waiting lists through the session/group/member screens. GT-008 adds attendance and check-in. GT-009 adds duration memberships and immutable cash tracking; advanced packages remain deferred.

See [organization lifecycle](organization-lifecycle.md) for state transitions, operator recovery, permission delegation and the PostgreSQL lock protocol.

Use ordinary Laravel controllers, FormRequests, Gates, models and small support classes. Introduce domain directories/actions only when real business behavior requires them. New tenant-owned models must implement the tenant conventions before exposure. PostgreSQL is authoritative; Redis caches and rate limits can disappear without changing entitlement or financial truth. Database queues are configured but no application jobs exist.

Native HP processes bind loopback. Development uses PHP's server on 8010 and Vite on 5173. Built frontend assets are served by Laravel; a later production release must use a reviewed HTTPS web server rooted at `public/`, proper PHP workers, migrations, backups, observability and least-privilege runtime credentials. None of that deployment has been performed.

## GT-007 reservation extension

[Reservation domain and API](../domain/reservations.md) defines implemented staff/offline bookings, permanent-place assignment, synchronous FIFO waiting promotion, capacity guards, member lookup and relationship views. Existing grants remain unchanged; adoption is explicit. Earlier future-booking statements in this document describe the historical baseline; GT-007 uses active-record uniqueness and organization/session locks for retry safety, without a global idempotency-key service. Mobile self-service remains deferred; GT-008 attendance is described below.

## GT-008 attendance extension

GT-008 now implements durable attendance, staff/trainer roster and today workflows, capacity-safe offline walk-ins, no-show finalization, audited corrections and encrypted expiring QR foundations. Earlier attendance deferrals above describe the prior milestone. See [attendance contracts](../domain/attendance.md) for authorization, API, privacy, concurrency, lifecycle protection and future member-authentication boundaries. Existing grants are preserved; adoption is explicit. That milestone did not add member check-in HTTP endpoints or a mobile app.

## GT-009 membership and cash extension

See [membership and cash contracts](../domain/cash-payments.md). Immutable commercial snapshots and append-only cash/reversal records use PostgreSQL, tenant-qualified ownership and the common organization lock. Financial grants deliberately have organization scope; attendance and TrainerProfile confer no financial access. Membership validity does not gate bookings or attendance in GT-009.

## GT-010 notifications

GT-010 adds immutable tenant/recipient notification evidence and a durable polling outbox. Existing lifecycle transactions create evidence atomically; provider-neutral delivery runs outside those transactions. Notification-only inbox access supports existing authenticated member links without extending member self-service. See [notifications](../domain/notifications.md).

## GT-011 member self-service

GT-011 adds optional account activation and member self-service using the same global User identity and existing StudioMember link. MemberContext resolves own tenant access separately from staff TenantContext. Reservations share Allocation; history is read-only. See [member self-service](../domain/member-self-service.md).
