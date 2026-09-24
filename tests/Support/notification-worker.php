<?php

use App\Models\Organization;
use App\Models\Reservation;
use App\Support\Notifications\Delivery;
use App\Support\Notifications\Events;
use App\Support\Notifications\Generate;
use App\Support\Notifications\NotificationType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if ($app->configurationIsCached() || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'gt_test' || config('database.connections.pgsql.username') !== 'gt_test' || config('database.connections.pgsql.url') || ! in_array(config('database.connections.pgsql.host'), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Isolated gt_test required.');
}
CarbonImmutable::setTestNow($argv[5]);
Carbon::setTestNow($argv[5]);
DB::select("SELECT set_config('application_name',?,false)", ['gt-notification-race-'.$argv[4]]);
try {
    $org = Organization::where('public_id', $argv[2])->firstOrFail();
    if (in_array($argv[1], ['reminder', 'expiry'], true)) {
        $result = Generate::run($org->public_id);
        if ($result['failed'] > 0) {
            throw new RuntimeException('Generation failed');
        }
    } elseif ($argv[1] === 'delivery') {
        Delivery::run((int) $argv[3]);
    } else {
        DB::transaction(function () use ($org, $argv) {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            Events::reservation(Reservation::findOrFail($argv[3]), NotificationType::ReservationConfirmed);
        });
    }
    echo 'ok';
} catch (Throwable $e) {
    fwrite(STDERR, $e::class);
    exit(1);
}
