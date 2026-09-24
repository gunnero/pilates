<?php

namespace App\Support\Notifications;

use App\Contracts\Notifications\PushProvider;
use App\Mail\NotificationMessage;
use App\Models\DevicePushToken;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\PlatformNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;

final class ExternalChannels
{
    /** Called in the domain transaction; no provider calls and no retrofit of old evidence. */
    public static function enqueue(PlatformNotification $n): void
    {
        $user = $n->member?->user_id;
        if ($user === null) {
            return;
        }
        $base = ['organization_id' => $n->organization_id, 'platform_notification_id' => $n->id, 'destination_user_id' => $user, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        if (Preferences::permits($n, 'push')) {
            foreach (DevicePushToken::where('organization_id', $n->organization_id)->where('studio_member_id', $n->studio_member_id)->where('user_id', $user)->whereNull('revoked_at')->whereIn('access_token_id', PersonalAccessToken::where('expires_at', '>', now())->select('id'))->orderBy('id')->limit(10)->get() as $device) {
                DB::table('notification_deliveries')->insertOrIgnore($base + ['channel' => 'push', 'device_push_token_id' => $device->id, 'destination_key' => 'device:'.$device->id]);
            }
        }
        if (Preferences::permits($n, 'email')) {
            DB::table('notification_deliveries')->insertOrIgnore($base + ['channel' => 'email', 'destination_key' => 'user:'.$user]);
        }
    }

    public static function reason(NotificationDelivery $d): ?string
    {
        $n = $d->notification;
        $reason = Eligibility::reason($n);
        if ($reason !== null || $d->channel === 'in_app') {
            return $reason;
        }
        if ($n->member?->user_id !== $d->getAttribute('destination_user_id') || ! Preferences::permits($n, $d->channel)) {
            return 'preference';
        }
        if ($d->channel === 'push') {
            $device = DevicePushToken::whereKey($d->getAttribute('device_push_token_id'))->first();
            if ($device === null || $device->revoked_at !== null || $device->user_id !== $n->member?->user_id || $device->access_token_id === null || ! PersonalAccessToken::whereKey($device->access_token_id)->where('expires_at', '>', now())->exists()) {
                return 'ineligible';
            }
        }

        return null;
    }

    /** Return a finite outcome. Provider acceptance is not device display confirmation. */
    public static function send(NotificationDelivery $d): string
    {
        if (self::reason($d->fresh() ?? $d) !== null) {
            return 'ineligible';
        }
        $n = $d->notification;
        $locale = $n->member?->preferred_locale === 'en' ? 'en' : 'mk';
        if ($d->channel === 'email') {
            if (! app()->environment('production') || ! config('notifications.email_enabled')) {
                return 'disabled';
            }
            $user = User::find($n->member?->user_id);
            if ($user === null || $user->email_verified_at === null) {
                return 'ineligible';
            }
            Mail::to($user->email)->send(new NotificationMessage($n->getAttribute('title_'.$locale), $n->getAttribute('message_'.$locale)));

            return 'accepted';
        }
        if (DB::table('notification_push_receipts')->where('notification_delivery_id', $d->id)->whereIn('state', ['pending', 'accepted'])->exists()) {
            return 'accepted';
        }
        $device = DevicePushToken::whereKey($d->getAttribute('device_push_token_id'))->firstOrFail();
        $result = app(PushProvider::class)->send([['to' => $device->token_ciphertext, 'title' => __('notifications.push_title', [], $locale), 'body' => __('notifications.push_body', [], $locale), 'data' => ['url' => 'gtstudio://notification/'.$n->organization->public_id.'/'.$n->public_id]]])[0];
        if ($result['status'] === 'accepted' && isset($result['id'])) {
            DB::table('notification_push_receipts')->insertOrIgnore(['notification_delivery_id' => $d->id, 'attempt' => $d->attempts, 'provider_id' => $result['id'], 'next_check_at' => now()->addMinutes(15), 'created_at' => now()]);

            return 'accepted';
        }
        if (($result['error'] ?? null) === 'invalid_device') {
            DB::transaction(function () use ($device) {
                Organization::whereKey($device->organization_id)->lockForUpdate()->firstOrFail();
                $device->forceFill(['revoked_at' => now()])->save();
            });
        }

        return $result['error'] ?? 'transient';
    }
}
