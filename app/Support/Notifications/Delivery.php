<?php

namespace App\Support\Notifications;

use App\Contracts\Notifications\DeliveryChannel;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class Delivery
{
    public const MAX_ATTEMPTS = 5;

    /** Stable safe outcome; never return/log payloads or exception messages. */
    public static function run(int $id, ?DeliveryChannel $channel = null): string
    {
        $claim = DB::transaction(function () use ($id): NotificationDelivery|string|null {
            $hint = NotificationDelivery::find($id);
            if ($hint === null) {
                return null;
            }
            $org = Organization::whereKey($hint->organization_id)->lockForUpdate()->firstOrFail();
            $d = NotificationDelivery::whereKey($id)->lockForUpdate()->firstOrFail();
            if (in_array($d->state, ['delivered', 'failed', 'suppressed'], true)) {
                return null;
            }
            if ($d->state === 'processing') {
                if ($d->lease_expires_at?->isFuture()) {
                    return null;
                }
                DB::table('notification_delivery_attempts')->where('notification_delivery_id', $id)->where('lease_token', $d->lease_token)->where('outcome', 'started')->update(['outcome' => 'abandoned', 'finished_at' => now(), 'error_code' => 'lease_expired']);
            } elseif ($d->next_attempt_at->isFuture()) {
                return null;
            }
            if ($d->attempts >= self::MAX_ATTEMPTS) {
                self::finishState($d, 'failed', 'attempt_limit');
                self::audit($org, $d, 'notification.delivery_failed', 'attempt_limit');

                return 'failed';
            }
            $reason = Eligibility::reason($d->notification);
            if ($reason !== null) {
                self::finishState($d, 'suppressed', $reason);

                return 'suppressed';
            }
            $d->forceFill(['state' => 'processing', 'attempts' => $d->attempts + 1, 'lease_token' => (string) Str::uuid7(), 'lease_expires_at' => now()->addSeconds(120), 'error_code' => null])->save();
            DB::table('notification_delivery_attempts')->insert(['organization_id' => $org->id, 'notification_delivery_id' => $d->id, 'attempt' => $d->attempts, 'lease_token' => $d->lease_token, 'outcome' => 'started', 'started_at' => now()]);

            return $d;
        }, 3);
        if (is_string($claim)) {
            return $claim;
        }
        if ($claim === null) {
            return 'skipped';
        }
        $error = null;
        try {
            ($channel ?? new InAppChannel)->deliver($claim->notification, 'notification:'.$claim->notification->public_id.':in_app');
        } catch (Throwable) {
            $error = 'transient';
        }

        return DB::transaction(function () use ($claim, $error): string {
            $org = Organization::whereKey($claim->organization_id)->lockForUpdate()->firstOrFail();
            $d = NotificationDelivery::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($d->state !== 'processing' || $d->lease_token !== $claim->lease_token) {
                return 'skipped';
            }
            $reason = Eligibility::reason($d->notification);
            $state = $reason !== null ? 'suppressed' : ($error === null ? 'delivered' : ($d->attempts >= self::MAX_ATTEMPTS ? 'failed' : 'retry'));
            $code = $reason ?? $error;
            DB::table('notification_delivery_attempts')->where('notification_delivery_id', $d->id)->where('lease_token', $d->lease_token)->where('outcome', 'started')->update(['outcome' => $state, 'finished_at' => now(), 'error_code' => $code]);
            self::finishState($d, $state, $code);
            if (in_array($state, ['retry', 'failed'], true)) {
                self::audit($org, $d, $state === 'failed' ? 'notification.delivery_failed' : 'notification.delivery_retry', $code ?? 'transient');
            }

            return $state;
        }, 3);
    }

    private static function finishState(NotificationDelivery $d, string $state, ?string $code): void
    {
        $d->forceFill(['state' => $state, 'lease_token' => null, 'lease_expires_at' => null, 'error_code' => $code, 'delivered_at' => $state === 'delivered' ? now() : null, 'next_attempt_at' => $state === 'retry' ? now()->addSeconds(min(3600, 60 * (2 ** ($d->attempts - 1)))) : now()])->save();
    }

    private static function audit(Organization $o, NotificationDelivery $d, string $action, string $code): void
    {
        Audit::record($o, null, $action, 'platform_notification', $d->notification->public_id, ['attempt' => $d->attempts, 'channel' => 'in_app', 'code' => $code]);
    }
}
