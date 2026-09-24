<?php

namespace App\Jobs;

use App\Support\Notifications\Delivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 120, 240];
    }

    public function handle(): void
    {
        // Durable outbox owns retries; duplicate queue jobs cannot claim the same lease.
        try {
            Delivery::run($this->deliveryId);
        } catch (\Throwable) {
            // Failed-job storage must never retain SQL bindings or announcement contents.
            throw new \RuntimeException('Communication job failed; inspect safe operational counters.');
        }
    }
}
