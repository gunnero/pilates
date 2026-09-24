# Communication workers and recovery

GT-015 configures application jobs and scheduler entries only. It installs no cron, daemon, shared Redis configuration, production credential, provider account or deployment.

## Processes

On a separately authorized deployment, run Laravel Scheduler every minute and a database-backed worker on the `notifications` queue:

```sh
php artisan schedule:work
php artisan queue:work database --queue=notifications --tries=3 --timeout=60
```

Set the scheduler application's default queue connection to `database` to match that worker; dispatch uses the configured default connection. Ensure the queue connection's retry-after exceeds the worker timeout. The repository database connection uses 90 seconds; notification claims independently use 120-second PostgreSQL leases. More than one worker is supported. Queue records contain only internal delivery/announcement IDs; handlers re-resolve database state. A failed queue job stores a sanitized exception, never SQL/provider bodies or announcement text.

The scheduler runs `gt:notifications:queue` every minute. One sweep enqueues up to 100 active-tenant pending announcements and 200 due deliveries, and checks at most 100 due Expo receipts. Duplicate dispatches are possible and harmless to business evidence: PostgreSQL claims/cursors remain authoritative. Queue backlog may contain duplicate work until workers catch up; size workers against observed backlog. One poison item does not stop the command from dispatching others. Dispatch/receipt failures increment `errors` and yield a nonzero exit status.

`gt:notifications:deliver --limit=100` remains an explicit bounded synchronous operator/test recovery command (maximum 200). It uses the same claims and channel adapters. Its summary now includes `awaiting_receipt`; receipt acceptance is not a successful device-display measurement. A failed/retry/error result exits nonzero. No HTTP administrative retry/reset endpoint exists. Do not reset attempts, mutate immutable evidence or flush Redis to replay delivery.

Reminder/expiry generation remains `gt:notifications:generate ORGANIZATION_UUID --limit=100`. Automatic generation is limited to the explicit `notifications.scheduled_organizations` allowlist, at most 20 tenants. An empty allowlist means no implicit platform-wide reminder sweep. Current GT-014 policy, active source state and anti-join deduplication govern each candidate. Outages beyond the documented catch-up windows can miss optional reminders; this milestone does not perform unlimited historical replay.

## Provider configuration

Production transport flags are `NOTIFICATION_PUSH_ENABLED` and `NOTIFICATION_EMAIL_ENABLED`, both false by default. Expo's optional access credential is `EXPO_ACCESS_TOKEN`; keep it in the deployment secret store. The native build needs the public project identifier `EXPO_PUBLIC_EAS_PROJECT_ID` and separately configured APNs/FCM credentials for that Expo project. The identifier is not a secret; none is fabricated by this task. Without it, the native push control remains explicitly unconfigured and does not request permission.

Local, testing and browser environments cannot use the default external adapters, even if enablement flags are true. Injected test providers prove behavior without sending. Laravel's established local mail transport remains `array`. Production email requires a separately reviewed Laravel mail transport and verified sender configuration. Email sends only to the member's verified account email, after opt-in; financial/customer contact data is not used as a destination.

[Expo's send/receipt contract](https://docs.expo.dev/push-notifications/sending-notifications/) distinguishes tickets from later provider receipts. GT-015 polls receipts after 15 minutes, revokes invalid tokens, retries explicit transient failures within five attempts and records absent receipts as unknown after 23 hours. Expo acceptance does not prove physical display. External crash boundaries can permit duplicates; neither Expo nor SMTP is presented as an exactly-once transport. Notification links resolve only an authenticated inbox record. [Native notification setup](https://docs.expo.dev/versions/latest/sdk/notifications/) requires a development build and platform credentials; Expo Go is not physical push validation.

## Monitoring

Monitor database queue backlog/oldest jobs, failed jobs, due delivery backlog, expired leases, awaiting-receipt age, permanent failures and invalid-device receipts. Dispatch commands print only counts. `notification.delivery` and `notification.receipt` logs contain safe organization/delivery IDs, channel, attempt/state and finite error codes. Immutable attempts/receipts explain recovery. Business audit records cover meaningful user/admin changes; automatic retry events no longer add business audit noise.

An announcement's status API exposes aggregate counts only to all-location `organizations.manage` staff. The web composer displays recent fan-out status and can cancel; the API also provides channel/state totals. No recipient export, token dump, provider payload viewer or messaging PII log is added.

## Migration and rollback

Inspect `php artisan migrate --pretend --no-interaction`, create a private custom-format PostgreSQL backup, verify `pg_restore --list`, and compare every pre-existing column/grant after applying. The GT-015 migration adds communication columns/tables and replaces relevant notification checks with strict extended versions. It does not update business rows, seed users, expand grants, install extensions or alter shared services.

A clean rollback restores the earlier schema. A populated GT-015 rollback is refused when upgrading an existing GT-014 installation: use a reviewed forward migration. Full fresh-install migration-batch teardown can remove the entire foundation, which the isolated test suite uses; it is not a production data-retention operation. Never reset a development or production database to recover from a failed migration. See completion evidence for the exact private backup and populated preservation tests.

## Release acceptance still required

This task performs no release. Before separately authorized distribution, validate real iOS/Android permission states, token rotation, account/studio switching, logout/reinstall and notification taps on physical devices, including network loss and already accepted notifications. Offline logout can clear local identity immediately but cannot prove remote revocation without reaching the server. Never show success for an unconfirmed remote operation. Validate Expo credentials/receipts, real SMTP delivery, worker supervision and operational alerts in the target environment.
