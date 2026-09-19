<?php

use App\Models\User;
use App\Support\ScheduleLifecycle;
use App\Support\SessionLifecycle;
use App\Support\SessionMaterializer;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if ($app->configurationIsCached() || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'gt_test' || config('database.connections.pgsql.username') !== 'gt_test' || config('database.connections.pgsql.url') || ! in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Session worker requires isolated gt_test.');
}
CarbonImmutable::setTestNow('2026-09-19 00:00:00 UTC');
Carbon::setTestNow('2026-09-19 00:00:00 UTC');
DB::select("SELECT set_config('application_name', ?, false)", ['gt-session-race-'.$argv[6]]);
$user = User::where('public_id', $argv[2])->firstOrFail();
$ctx = app(TenantContext::class);
$ctx->resolve($user, $argv[1]);
$data = json_decode($argv[5], true, 512, JSON_THROW_ON_ERROR);
try {
    match ($argv[3]) {
        'generate' => SessionMaterializer::run($ctx->organization()->id, $argv[4]),
        'manual' => SessionLifecycle::save($ctx, $user, $data),
        'override' => SessionLifecycle::save($ctx, $user, $data, $argv[4]),
        'cancel' => SessionLifecycle::cancel($ctx, $user, $argv[4], null),
        'replace' => ScheduleLifecycle::create($ctx, $user, $argv[4], $data, $data['old_id']),
    };
    echo '200';
} catch (HttpExceptionInterface $e) {
    echo $e->getStatusCode();
} catch (Throwable $e) {
    fwrite(STDERR, $e::class);
    exit(1);
}
