<?php

use App\Support\Reporting\ReportExport;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Durable polling does not depend on queue dispatch or a perfectly timed cron tick.
Schedule::command('gt:notifications:queue')->everyMinute();
foreach (array_slice(config('notifications.scheduled_organizations', []), 0, 20) as $organization) {
    if (Str::isUuid($organization)) {
        Schedule::command('gt:notifications:generate', [$organization, '--limit=100'])->everyMinute();
    }
}

Artisan::command('gt:reports:cleanup', function (): void {
    $this->info('Removed stale report artifacts: '.ReportExport::cleanup());
})->purpose('Remove private report files older than 24 hours');
