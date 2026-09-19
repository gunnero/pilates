<?php

use App\Models\User;
use App\Support\ScheduleLifecycle;
use App\Support\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if ($app->configurationIsCached() || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'gt_test'
    || config('database.connections.pgsql.username') !== 'gt_test' || config('database.connections.pgsql.url')
    || ! in_array(config('database.connections.pgsql.host'), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Schedule worker requires isolated gt_test.');
}
DB::select("SELECT set_config('application_name', ?, false)", ['gt-schedule-race-'.$argv[5]]);
$user = User::query()->where('public_id', $argv[2])->firstOrFail();
$context = app(TenantContext::class);
$context->resolve($user, $argv[1]);
try {
    ScheduleLifecycle::create($context, $user, $argv[3], ['weekday' => 1, 'start_time' => $argv[4], 'end_time' => '19:30', 'effective_from' => '2035-01-01']);
    echo '201';
} catch (HttpExceptionInterface $error) {
    echo $error->getStatusCode();
} catch (Throwable $error) {
    fwrite(STDERR, $error::class);
    exit(1);
}
