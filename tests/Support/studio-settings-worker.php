<?php

use App\Models\User;
use App\Support\Notifications\Generate;
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
    throw new RuntimeException('Isolated gt_test required.');
}
CarbonImmutable::setTestNow($argv[3]);
Carbon::setTestNow($argv[3]);
DB::select("SELECT set_config('application_name',?,false)", ['gt-settings-race-'.$argv[2]]);
$user = User::where('public_id', $argv[1])->firstOrFail();
// Credentials travel through stdin, never process arguments or output.
$task = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
try {
    if ($task[0] === 'GENERATE') {
        $counts = Generate::run($task[1]);
        echo json_encode($counts, JSON_THROW_ON_ERROR);
        exit(0);
    }
    Sanctum::actingAs($user, ['*']);
    $r = Request::create($task[1], $task[0], [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($task[2], JSON_THROW_ON_ERROR));
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($r);
    echo $response->getStatusCode();
} catch (Throwable $e) {
    fwrite(STDERR, $e::class);
    exit(1);
}
