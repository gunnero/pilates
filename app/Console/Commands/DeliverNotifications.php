<?php

namespace App\Console\Commands;

use App\Models\NotificationDelivery;
use App\Support\Notifications\Delivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

class DeliverNotifications extends Command
{
    protected $signature = 'gt:notifications:deliver {--limit=100}';

    protected $description = 'Poll a bounded durable outbox batch; recover expired leases and deliver in-app';

    public function handle(): int
    {
        $v = Validator::make(['limit' => $this->option('limit')], ['limit' => 'required|integer|min:1|max:200']);
        if ($v->fails()) {
            $this->error('Invalid notification command arguments.');

            return self::FAILURE;
        }
        $v = $v->validated();
        $ids = NotificationDelivery::query()->where(fn ($q) => $q->where(fn ($q) => $q->whereIn('state', ['pending', 'retry'])->where('next_attempt_at', '<=', now()))->orWhere(fn ($q) => $q->where('state', 'processing')->where('lease_expires_at', '<=', now())))->orderBy('next_attempt_at')->orderBy('id')->limit((int) $v['limit'])->pluck('id');
        $counts = ['considered' => count($ids), 'delivered' => 0, 'awaiting_receipt' => 0, 'retry' => 0, 'failed' => 0, 'suppressed' => 0, 'skipped' => 0, 'errors' => 0];
        foreach ($ids as $id) {
            try {
                $counts[Delivery::run($id)]++;
            } catch (Throwable) {
                $counts['errors']++;
            }
        }
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

        return $counts['failed'] + $counts['retry'] + $counts['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
