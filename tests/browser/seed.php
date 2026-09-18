<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.e2e');
$app->make(Kernel::class)->bootstrap();
if ($app->configurationIsCached() || ! $app->environment('e2e')
    || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'gt_test'
    || config('database.connections.pgsql.username') !== 'gt_test'
    || config('database.connections.pgsql.url')
    || ! in_array(config('database.connections.pgsql.host'), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Browser fixtures may only reset the isolated loopback gt_test database.');
}
Artisan::call('migrate:fresh', ['--force' => true]);
$user = User::factory()->create(['name' => 'Test Trainer', 'email' => 'browser@example.test', 'password' => 'Browser-fixture-123!']);
$organization = Organization::create(['name' => 'Test Studio']);
DB::table('organization_user')->insert(['organization_id' => $organization->id, 'user_id' => $user->id, 'permissions' => '["studio.access"]', 'created_at' => now(), 'updated_at' => now()]);
echo "Isolated browser fixtures prepared.\n";
