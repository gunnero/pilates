<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if ($app->configurationIsCached() || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'gt_test' || config('database.connections.pgsql.username') !== 'gt_test' || config('database.connections.pgsql.url') || ! in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('gt_test only');
}
CarbonImmutable::setTestNow($argv[4]);
Carbon::setTestNow($argv[4]);
$barrier = $argv[3];
$paused = false;
DB::listen(function ($event) use (&$paused, $barrier) {
    if ($paused || DB::transactionLevel() === 0 || ! str_starts_with($event->sql, 'select') || ! preg_match('/cash_payments|training_sessions|member_memberships/', $event->sql)) {
        return;
    }
    $paused = true;
    $isolation = DB::selectOne('SHOW transaction_isolation')->transaction_isolation;
    file_put_contents($barrier.'.ready', json_encode(['isolation' => $isolation, 'snapshot' => DB::selectOne('SELECT pg_current_snapshot()::text as s')->s], JSON_THROW_ON_ERROR));
    $deadline = microtime(true) + 20;
    while (! is_file($barrier.'.release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('barrier_timeout');
        }usleep(10000);
    }
});
Sanctum::actingAs(User::where('public_id', $argv[1])->firstOrFail(), ['*']);
$request = Request::create($argv[2], 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
$result = ['status' => $response->getStatusCode()];
if ($response instanceof BinaryFileResponse) {
    $path = $response->getFile()->getPathname();
    $stream = fopen($path, 'rb');
    $result['header'] = array_map(fn ($v) => explode(' · ', $v)[0], fgetcsv($stream, escape: ''));
    $result['rows'] = [];
    while (($row = fgetcsv($stream, escape: '')) !== false) {
        if ($row[0] === 'data') {
            $result['rows'][] = $row;
        }
    }fclose($stream);
    unlink($path);
} else {
    $result['body'] = json_decode($response->getContent(), true);
}
echo json_encode($result, JSON_THROW_ON_ERROR);
