<?php

/** Run after browser verification populated gt_test, never against development data. */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if ($app->configurationIsCached() || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'gt_test' || config('database.connections.pgsql.username') !== 'gt_test' || config('database.connections.pgsql.url') || ! in_array(config('database.connections.pgsql.host'), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Isolated loopback gt_test is required.');
}
$tables = ['migrations', 'organizations', 'locations', 'organization_memberships', 'membership_locations', 'studio_members', 'trainer_profiles', 'training_groups', 'schedule_rules', 'training_sessions', 'reservations', 'waiting_list_entries', 'attendance_records', 'membership_plans', 'member_memberships', 'cash_payments', 'payment_reversals', 'platform_notifications', 'notification_deliveries', 'audit_events'];
$snapshot = function () use ($tables): array {
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = DB::table($table)->count();
    }
    $counts['grant_hash'] = hash('sha256', DB::table('organization_memberships')->select('id', 'permissions', 'role', 'location_scope', 'status')->orderBy('id')->get()->toJson());

    return $counts;
};
$before = $snapshot();
if ($before['organizations'] < 2 || $before['training_sessions'] < 1 || $before['studio_members'] < 1 || $before['migrations'] !== 17) {
    throw new RuntimeException('Run populated browser fixtures before preservation verification.');
}
if (Artisan::call('migrate', ['--force' => true]) !== 0 || $snapshot() !== $before) {
    throw new RuntimeException('No-op upgrade changed baseline records or grants.');
}
echo json_encode(['result' => 'passed', 'migration_count' => $before['migrations'], 'tables_checked' => count($tables), 'grant_hash_preserved' => true, 'organization_count' => $before['organizations']], JSON_THROW_ON_ERROR).PHP_EOL;
