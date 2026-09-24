<?php

namespace App\Support\Notifications;

use App\Contracts\Notifications\PushProvider;
use App\Models\DevicePushToken;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class Receipts
{
    public static function run(): int
    {
        $rows = DB::table('notification_push_receipts')->where('state', 'pending')->where('next_check_at', '<=', now())->orderBy('id')->limit(100)->get();
        if ($rows->isEmpty()) {
            return 0;
        }
        $results = app(PushProvider::class)->receipts(array_values($rows->pluck('provider_id')->filter(fn ($id) => is_string($id))->all()));
        foreach ($rows as $row) {
            DB::transaction(function () use ($row, $results) {
                $d = NotificationDelivery::whereKey($row->notification_delivery_id)->firstOrFail();
                Organization::whereKey($d->organization_id)->lockForUpdate()->firstOrFail();
                $d = NotificationDelivery::whereKey($d->id)->lockForUpdate()->firstOrFail();
                if ($d->state === 'processing') {
                    return;
                }
                $current = DB::table('notification_push_receipts')->where('id', $row->id)->lockForUpdate()->first();
                if ($current === null || $current->state !== 'pending') {
                    return;
                }
                $result = $results[$row->provider_id] ?? null;
                $state = $result === null ? (CarbonImmutable::parse($row->created_at)->addHours(23)->isPast() ? 'unknown' : 'pending') : ($result['status'] === 'accepted' ? 'accepted' : 'failed');
                $code = $state === 'unknown' ? 'receipt_timeout' : ($state === 'failed' ? (($result['error'] ?? '') === 'invalid_device' ? 'invalid_device' : 'provider_failure') : null);
                DB::table('notification_push_receipts')->where('id', $row->id)->update(['state' => $state, 'error_code' => $code, 'checked_at' => now(), 'next_check_at' => now()->addMinutes(15)]);
                if ($state !== 'pending' && $d->state === 'awaiting_receipt') {
                    $reason = ExternalChannels::reason($d);
                    $transient = $state === 'failed' && ($result['error'] ?? null) === 'transient';
                    $deliveryState = $reason !== null ? 'suppressed' : ($state === 'accepted' ? 'delivered' : ($transient && $d->attempts < Delivery::MAX_ATTEMPTS ? 'retry' : 'failed'));
                    $d->forceFill(['state' => $deliveryState, 'error_code' => $reason ?? ($state === 'accepted' ? null : ($state === 'unknown' ? 'receipt_timeout' : (($result['error'] ?? null) === 'invalid_device' ? 'invalid_device' : ($transient ? 'transient' : 'permanent')))), 'delivered_at' => $deliveryState === 'delivered' ? now() : null, 'next_attempt_at' => now()->addSeconds(60 * (2 ** ($d->attempts - 1)))])->save();
                }
                if ($state !== 'pending') {
                    DB::afterCommit(fn () => Log::info('notification.receipt', ['organization_id' => $d->organization_id, 'delivery_id' => $d->id, 'state' => $state, 'code' => $code]));
                }
                if ($code === 'invalid_device') {
                    DevicePushToken::whereKey($d->getAttribute('device_push_token_id'))->update(['revoked_at' => now(), 'updated_at' => now()]);
                }
            }, 3);
        }

        return $rows->count();
    }
}
