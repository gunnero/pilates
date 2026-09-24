<?php

use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Support\Notifications\Delivery;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.e2e');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('e2e') || $app->configurationIsCached() || config('database.connections.pgsql.database') !== 'gt_test' || config('database.connections.pgsql.username') !== 'gt_test' || config('database.connections.pgsql.url') || ! in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Isolated browser database required');
}
$org = Organization::where('public_id', $argv[1])->firstOrFail();
foreach (NotificationDelivery::where('organization_id', $org->id)->where('state', 'pending')->limit(100)->pluck('id') as $id) {
    Delivery::run($id);
}
