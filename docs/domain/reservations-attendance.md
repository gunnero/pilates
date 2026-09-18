# Future reservations and attendance — not implemented

A member may belong to a regular group, reserve another available session, confirm attendance, indicate non-attendance, and check in. These are distinct facts: a reservation is not actual attendance, and a cancellation is not a check-in. Staff/trainers need independently authorized on-behalf reservations and manual attendance edits.

Members without smartphones/mobile accounts must remain first-class studio records. Do not require a global login account to reserve for an older/non-digital member. Member identity and optional digital access are separate associations.

A permanent spot is an ongoing assignment to a recurring group/time pattern, e.g. Monday/Wednesday/Friday at 10:00 Europe/Skopje. Generate or recognize expected places in dated sessions. A release for one occurrence removes only that occurrence's capacity claim; it must not delete the permanent assignment. Preserve exceptions, effective dates, cancellations and audit evidence. DST gaps/overlaps require explicit recurrence policy before generating sessions.

Capacity claims and waiting-list promotion must be PostgreSQL transactions. Use a locked session/capacity row or equivalent constraint-backed allocation; recheck remaining capacity inside the lock and make claims unique. Two people must never both take the final place. Permanent claims, ad hoc reservations, temporary releases and promotions must use the same authoritative accounting path. Redis locks/cache alone are insufficient. Use consistent lock ordering, safe retries, persisted idempotency and deterministic promotion ordering; test competing transactions and rollbacks against PostgreSQL.

Staff-created/cancelled reservations, manual attendance and permanent-spot changes need tenant-scoped authorization and immutable bounded audit evidence. Future QR/member self-check-in requires short-lived signed/unguessable credentials bound to session/member/organization, replay prevention and explicit check-in windows. None of these engines, schemas, APIs or screens exists in GT-001.
