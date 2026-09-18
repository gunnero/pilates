# Permission-based authorization

Server-side Gates are authoritative. `Permission` defines independent named capabilities. Implemented authorization requires `studio.access` for organization context. `attendance.view`, `payments.view`, `payments.record`, `payments.correct` and `reports.view` currently exist only to establish/test independent grants; they expose no business data or operations.

Explicit string grants live in the organization's access relation. A permission must match the resolved organization and actor; absent context, unknown permission, missing grant or insufficient token ability denies access. No role name is checked and no wildcard Super Admin bypass exists. Membership/grant changes take effect on subsequent requests because context is resolved afresh.

Future organization roles Owner, Administrator, Trainer and Staff are editable **permission bundles**, not hardcoded authority levels. Super Admin is a separate platform role, with explicit audited cross-tenant access if introduced. Customer/member access is separate. A Trainer may have attendance only; adding payments.view and payments.record must not imply payments.correct or reports.view. Tests prove these capabilities remain independent.

Future controllers must authorize the operation and return explicit resource fields. Financial queries/resources must be authorized before reading/serialization; do not send financial fields and rely on React to hide them. Permission editing requires a separately authorized, audited transaction and protection against granting capabilities beyond the grantor's authority. There is no permission editor, role management UI, organization management UI or public access-grant endpoint in GT-001.
