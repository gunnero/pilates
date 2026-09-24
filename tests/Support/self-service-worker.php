<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if ($app->configurationIsCached() || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'gt_test' || config('database.connections.pgsql.username') !== 'gt_test' || config('database.connections.pgsql.url') || ! in_array(config('database.connections.pgsql.host'), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Isolated gt_test required');
}
config(['session.driver' => 'database']);
// Test credentials arrive on stdin, never process arguments or output.
$task = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
CarbonImmutable::setTestNow($task['clock']);
Carbon::setTestNow($task['clock']);
DB::select("SELECT set_config('application_name',?,false)", ['gt-self-service-race-'.$argv[1]]);
if (isset($task['user'])) {
    Sanctum::actingAs(User::findOrFail($task['user']), ['*']);
}
try {
    $r = Request::create($task['path'], 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($task['data'], JSON_THROW_ON_ERROR));
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($r);
    echo $response->getStatusCode();
} catch (Throwable $e) {
    fwrite(STDERR, $e::class);
    exit(1);
}
