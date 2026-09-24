<?php

namespace App\Contracts\Notifications;

use App\Models\PlatformNotification;

interface DeliveryChannel
{
    /** Runs outside business/claim transactions. Future external adapters must honor this stable idempotency key.
     * A successful return permits publishing delivery state; retries may call this more than once after a crash.
     */
    public function deliver(PlatformNotification $notification, string $idempotencyKey): void;
}
