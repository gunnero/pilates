# System overview and repository ownership

GT is a modular monolith. Laravel owns HTTP authentication, authorization, relational state and all future domain rules. React is a first-party same-origin admin client. Future trainer screens and an Expo/React Native member app will use the same `/api/v1` API. No mobile application or microservice exists.

Backend source is `app/`, migrations/factories are `database/`, routes are `routes/`. Frontend code and component tests live in `resources/js/`, CSS in `resources/css/`, and the server-rendered HTML entry point in `resources/views/app.blade.php`. Both package manifests and lockfiles live at the root. This is one deployable application with obvious source ownership, not two projects or a nested pilates directory.

Current primitives: global user identity, Organization, Location and OrganizationMembership (staff access, never a StudioMember/customer). GT-002 adds persisted status, explicit role bundles/grants, selected-location access, audited lifecycle mutations, safe organization switching and management screens. Authentication/recovery and the translated responsive shell remain from GT-001. Booking, customer memberships, trainer scheduling, groups, reservations, attendance and payments remain unimplemented; their navigation entries are marked Coming soon.

See [organization lifecycle](organization-lifecycle.md) for state transitions, operator recovery, permission delegation and the PostgreSQL lock protocol.

Use ordinary Laravel controllers, FormRequests, Gates, models and small support classes. Introduce domain directories/actions only when real business behavior requires them. New tenant-owned models must implement the tenant conventions before exposure. PostgreSQL is authoritative; Redis caches and rate limits can disappear without changing entitlement or financial truth. Database queues are configured but no application jobs exist.

Native HP processes bind loopback. Development uses PHP's server on 8010 and Vite on 5173. Built frontend assets are served by Laravel; a later production release must use a reviewed HTTPS web server rooted at `public/`, proper PHP workers, migrations, backups, observability and least-privilege runtime credentials. None of that deployment has been performed.
