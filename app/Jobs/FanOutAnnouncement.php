<?php

namespace App\Jobs;

use App\Support\Notifications\Announcements;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class FanOutAnnouncement implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $announcementId) {}

    public function backoff(): int
    {
        return 60;
    }

    public function handle(): void
    {
        try {
            Announcements::run($this->announcementId);
        } catch (\Throwable) {
            // Failed-job storage must never retain SQL bindings or announcement contents.
            throw new \RuntimeException('Communication job failed; inspect safe operational counters.');
        }
    }
}
