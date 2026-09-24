<?php

namespace App\Support\Notifications;

use App\Contracts\Notifications\DeliveryChannel;
use App\Models\PlatformNotification;

final class InAppChannel implements DeliveryChannel
{
    public function deliver(PlatformNotification $notification, string $idempotencyKey): void
    {
        // Publishing is the unique delivery row's transactional transition to delivered.
        // No external effect or queue dispatch is necessary for an in-app inbox.
        Registry::payload(NotificationType::from($notification->type), $notification->metadata);
    }
}
