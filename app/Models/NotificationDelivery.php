<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property-read PlatformNotification $notification
 * @property int $organization_id
 * @property int $platform_notification_id
 * @property string $channel
 * @property string $state
 * @property int $attempts
 * @property string|null $lease_token
 * @property CarbonImmutable $next_attempt_at
 * @property CarbonImmutable|null $lease_expires_at
 * @property CarbonImmutable|null $delivered_at
 * @property string|null $error_code
 */
class NotificationDelivery extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'next_attempt_at' => 'immutable_datetime', 'lease_expires_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<PlatformNotification,$this> */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(PlatformNotification::class, 'platform_notification_id');
    }
}
