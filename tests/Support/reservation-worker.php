<?php

use App\Models\Organization;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\Allocation;
use App\Support\SessionMaterializer;
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
CarbonImmutable::setTestNow('2026-09-19 00:00:00 UTC');
Carbon::setTestNow('2026-09-19 00:00:00 UTC');
DB::select("SELECT set_config('application_name',?,false)", ['gt-reservation-race-'.$argv[4]]);
$user = User::where('public_id', $argv[1])->firstOrFail();
$org = Organization::where('public_id', $argv[2])->firstOrFail();
$task = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);
try {
    if ($task[0] === 'sync') {
        SessionMaterializer::run($org->id, $task[1]);
        echo '200';
    } elseif ($task[0] === 'promote') {
        DB::transaction(function () use ($org, $task) {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            Allocation::promote(Allocation::lock(TrainingSession::where('public_id', $task[1])->firstOrFail()));
        });
        echo '200';
    } else {
        Sanctum::actingAs($user, ['*']);
        $r = Request::create('/api/v1/organizations/'.$org->public_id.$task[1], $task[0], [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($task[2], JSON_THROW_ON_ERROR));
        $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($r);
        echo $response->getStatusCode();
    }
} catch (Throwable $e) {
    fwrite(STDERR,$e::class);
    exit(1);
}
