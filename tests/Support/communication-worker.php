<?php

use App\Contracts\Notifications\PushProvider;
use App\Support\Notifications\Announcements;
use App\Support\Notifications\Delivery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if ($app->configurationIsCached() || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'gt_test' || config('database.connections.pgsql.username') !== 'gt_test' || config('database.connections.pgsql.url') || ! in_array(config('database.connections.pgsql.host'), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Isolated gt_test required.');
}
$task = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
CarbonImmutable::setTestNow($task['now']);
Carbon::setTestNow($task['now']);
DB::select("SELECT set_config('application_name',?,false)", ['gt-communication-race-'.$argv[1]]);
try {
    if ($task['kind'] === 'announcement') {
        Announcements::run($task['id']);
    } elseif (in_array($task['kind'], ['push', 'queue'], true)) {
        $app->instance(PushProvider::class, new class implements PushProvider
        {
            public function send(array $messages): array
            {
                return [['status' => 'accepted', 'id' => 'race-ticket']];
            }

            public function receipts(array $ids): array
            {
                return [];
            }
        });
        if ($task['kind'] === 'queue') {
            $exit = Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'notifications-race', '--once' => true, '--tries' => 1, '--timeout' => 25]);
            if ($exit !== 0) {
                throw new RuntimeException('Queue worker failed');
            }
        } else {
            Delivery::run($task['id']);
        }
    } else {
        $request = Request::create($task['path'], 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$task['token']], json_encode($task['body'], JSON_THROW_ON_ERROR));
        $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
        if ($response->getStatusCode() >= 300) {
            throw new RuntimeException('HTTP '.$response->getStatusCode());
        }
    }
    echo 'ok';
} catch (Throwable $e) {
    fwrite(STDERR, $e::class);
    exit(1);
}
