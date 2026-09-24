<?php

namespace App\Console\Commands;

use App\Jobs\DeliverNotification;
use App\Jobs\FanOutAnnouncement;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\StudioAnnouncement;
use App\Support\Notifications\Receipts;
use Illuminate\Console\Command;
use Throwable;

final class Communicate extends Command
{
    protected $signature = 'gt:notifications:queue';

    protected $description = 'Dispatch bounded communication work and resolve provider receipts';

    public function handle(): int
    {
        $counts = ['announcements' => 0, 'deliveries' => 0, 'receipts' => 0, 'errors' => 0];
        foreach (StudioAnnouncement::whereIn('organization_id', Organization::where('status', 'active')->select('id'))->where('state', 'pending')->where('expires_at', '>', now())->orderBy('id')->limit(100)->pluck('id') as $id) {
            try {
                FanOutAnnouncement::dispatch($id)->onQueue('notifications');
                $counts['announcements']++;
            } catch (Throwable) {
                $counts['errors']++;
            }
        }
        foreach (NotificationDelivery::where(fn ($q) => $q->whereIn('state', ['pending', 'retry'])->where('next_attempt_at', '<=', now())->orWhere(fn ($q) => $q->where('state', 'processing')->where('lease_expires_at', '<=', now())))->orderBy('id')->limit(200)->pluck('id') as $id) {
            try {
                DeliverNotification::dispatch($id)->onQueue('notifications');
                $counts['deliveries']++;
            } catch (Throwable) {
                $counts['errors']++;
            }
        }
        try {
            $counts['receipts'] = Receipts::run();
        } catch (Throwable) {
            $counts['errors']++;
        }
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

        return $counts['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
