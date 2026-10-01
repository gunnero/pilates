# Trainer and studio operations (GT-016)

This is a request-local read projection over existing domains, plus navigation to their existing commands. No new table, role grant, reservation allocation, attendance ledger, financial ledger, notification dispatcher or persistent alert queue is introduced.

## Authorization and API

Every tenant request authenticates through Sanctum, resolves active staff membership with TenantContext and requires `studio.access`, `sessions.view`, and `attendance.view`. SessionDirectory intersects selected locations; the Trainer role always intersects the current TrainerProfile assignment. A trainer filter can narrow that intersection only. Foreign session/member combinations return 404. Invalid filters return 422. Revoked authority is checked again by the existing mutation services under their organization/session locks.

| Endpoint                                                                                  | Purpose                                                                                                                                                                               |
| ----------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET `/api/v1/organizations/{organization}/operations`                                     | Studio-local today by default; `from` + `until` inclusive, at most 31 days; UUID trainer/location/group and scheduled/cancelled/all filters; page 1–10000, per_page 1–100, default 25 |
| POST `/api/v1/organizations/{organization}/operations/sessions/{session}/roster`          | JSON-body search up to 100 characters; reservation history or current waiting queue; independent page totals; names never enter URLs                                                  |
| GET `/api/v1/organizations/{organization}/operations/sessions/{session}/members/{member}` | Current scoped participant's minimal profile; no unrelated member directory                                                                                                           |
| POST `/api/v1/staff-auth/token`                                                           | Explicit staff native sign-in, existing login limiter and per-email AccountSecurity lock; active studio/session/attendance access required                                            |
| GET `/api/v1/staff/account`                                                               | Authenticated staff account and at most 100 eligible organizations; explicit truncation                                                                                               |

All operations responses use `Cache-Control: no-store, private`. Unknown request keys are rejected. An organization ID, actor or permission submitted in an action body never grants authority. Existing attendance mark/correct/walk-in/member-lookup endpoints remain the only command paths. Existing session/reservation/waiting/finance pages remain their management workflows.

Native staff credentials use a separate `gt.staff.token` SecureStore key and an independent client. Tokens expire after 30 days, share the existing ten-token account ceiling and carry explicit abilities, never `*`. Abilities are a ceiling: every tenant request still requires current database grants. Staff tokens cannot call member APIs; member tokens cannot acquire staff access, including for dual-role users. Logout uses the existing account-security token revocation endpoint and clears local credentials/context. Native member push registrations are not reused for staff. Credential issuance/revocation uses existing account-security audit events.

## Counts, identity and time

Stable TrainingSession public IDs identify occurrences. Chronological ordering uses `starts_at,id`. Today comes from the organization's timezone, independent of the device. Dates remain inclusive studio-local dates; UTC instants determine upcoming/in-progress/ended. DST resolution remains the GT-006 scheduling contract. `is_overridden` is evidence of an occurrence override, not a complete change history. Cancellation remains durable.

Reservations own occupancy. Active reservations occupy places; waiting rows do not. Free places are SessionDirectory's `max(0, capacity - active reservations)`. Cancelled sessions remain listed; their retained children do not contribute to aggregate configured/reserved/waiting totals. Present attendance is a separate count, never a seat counter. Pending attendance is an active reservation with no attendance record. Summary counts span the complete filtered range, independently of page length and roster search. Attendance totals preserve historical records, including cancelled reservations with an existing record.

Reservation rows sort by immutable internal ID. Current waiting rows sort by canonical `joined_at,id`; displayed positions come from that complete queue, unaffected by name search or page. The queue is already capped by the owning domain. Walk-in badges derive from immutable `attendance.walk_in` audit evidence for the displayed attendance IDs and permitted admission window. Audit metadata is not returned.

## Financial and profile boundaries

Roster identity is name/public ID and current member status. Contacts require `members.view` and all-location scope. No health/private notes or unrelated history are returned. Membership state requires `memberships.view` OR `payments.view`; payment settlement requires `payments.view` separately. No financial privilege follows from TrainerProfile, attendance, a trainer role or a selected location.

Roster membership summaries prefer a currently active uncancelled period, otherwise the most recent ending period, using a batched PostgreSQL DISTINCT ON query. Quick profiles show up to ten recent periods with explicit truncation. Missing membership, restricted access and failed loading remain distinct. Expiry uses `StudioSettings.membership_expiring_days`. Financial fields and amounts come from FinanceDirectory's cash/reversal aggregates; a missing field must not be treated as paid or zero.

Duration memberships have no remaining-use counter, attendance debit, cash-confirmation state or admission gate. Cancelling a financial period therefore does not invalidate an otherwise permitted attendance action. No financial value is changed by reading or recording attendance. Existing membership/cash pages own collection and reversal workflows.

## Alerts and communications

| Condition                                                                     | Severity and target                              | Visibility                                                  |
| ----------------------------------------------------------------------------- | ------------------------------------------------ | ----------------------------------------------------------- |
| Scheduled ended session with unresolved active reservations                   | Warning → session roster                         | Current session scope                                       |
| Overridden occurrence                                                         | Information → occurrence                         | Current session scope; not an inferred reassignment history |
| Cancelled occurrence                                                          | Information → retained occurrence                | Current session scope                                       |
| Scheduled session with waiting demand                                         | Information → session/waiting workflow           | Current session scope                                       |
| Participant's active period within configured expiry threshold                | Count → membership workflow                      | Independent financial read permission                       |
| Participant's uncancelled started period with positive canonical cash balance | Count → payment workflow                         | payments.view                                               |
| Communication channel/state totals over last 31 days                          | Status → existing settings/announcement workflow | Non-Trainer, all-location organizations.manage              |

Session alerts consider at most 50 matching occurrences, with explicit truncation. Warnings sort before informational alerts; within each severity chronological source order is retained. Financial items only consider active reservations in the filtered session scope. Refresh removes resolved conditions. There is no dismissal state or alert-owned persistence.

Communication projection contains only channel/state/count, not recipient identity, payloads, provider bodies, device tokens or amounts. Pending/retry/processing/ticketed/failed/suppressed/provider-accepted states remain distinct. Provider acceptance, an Expo ticket or a successful receipt does not prove physical device delivery. Reads never enqueue, dispatch or retry messages.

## Consistency and performance

Production projections use a short read-only REPEATABLE READ transaction for consistent multi-query rows/counts. They do not take the organization write lock. Middleware authentication/context resolution happens before the snapshot. This is a read snapshot, not a future mutation authorization. Existing writers re-resolve authority after acquiring organization then session locks.

Request-local batching avoids cross-request authorization caches and per-row reads. Web effects abort/ignore obsolete responses; native clients invalidate response generations on context/navigation/logout and refresh on resume. Successful actions and conflicts refresh canonical state. No offline action queue or optimistic capacity/financial balance is introduced.

No schema/index migration is added: measured plans use existing tenant/session indexes and remain within documented local budgets. Historical migration files remain byte-identical. Local warmed benchmarks are not production SLAs. See the completion report for fixture scale, actual counts/timings, races and verification limitations.
