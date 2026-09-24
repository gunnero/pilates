<?php

namespace App\Console\Commands;

use App\Support\Notifications\Generate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class GenerateNotifications extends Command
{
    protected $signature = 'gt:notifications:generate {organization} {--limit=100}';

    protected $description = 'Bounded, idempotent reservation reminders and membership expiry notifications';

    public function handle(): int
    {
        $v = Validator::make(['organization' => $this->argument('organization'), 'limit' => $this->option('limit')], ['organization' => 'required|uuid', 'limit' => 'required|integer|min:1|max:200']);
        if ($v->fails()) {
            $this->error('Invalid notification command arguments.');

            return self::FAILURE;
        }
        $v = $v->validated();
        try {
            $counts = Generate::run($v['organization'], (int) $v['limit']);
        } catch (\Throwable) {
            $this->error('Notification generation failed; check organization and configuration.');

            return self::FAILURE;
        }
        $this->line(json_encode(['organization' => $v['organization']] + $counts, JSON_THROW_ON_ERROR));

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
