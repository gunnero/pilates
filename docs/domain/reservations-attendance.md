# Reservations and future attendance

Reservations, permanent places and waiting lists are implemented in GT-007; see [the domain contract](reservations.md). A reservation answers whether a member has a place, not whether they attended. Releasing a place before session start is the current “will not attend” operation. Attendance, no-show, QR check-in, payments and mobile self-service are deferred.

## GT-008 attendance extension

GT-008 now implements durable attendance, staff/trainer roster and today workflows, capacity-safe offline walk-ins, no-show finalization, audited corrections and encrypted expiring QR foundations. Earlier attendance deferrals above describe the prior milestone. See [attendance contracts](../domain/attendance.md) for authorization, API, privacy, concurrency, lifecycle protection and future member-authentication boundaries. Existing grants are preserved; adoption is explicit. No member check-in HTTP endpoint, payments, mobile app or GT-009 is included.
