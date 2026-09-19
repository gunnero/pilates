<?php

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\StaffRole;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

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
$owners = [];
foreach (['desktop', 'mobile'] as $viewport) {
    $owners[] = User::factory()->create(['name' => 'Test Owner '.$viewport, 'email' => $viewport.'@browser.example.test', 'password' => 'Browser-fixture-123!']);
}
$trainer = User::factory()->create(['name' => 'Jovana', 'email' => 'trainer@example.test']);
foreach (['Test Studio', 'Other Studio'] as $name) {
    $organization = Organization::create(['name' => $name]);
    foreach ($owners as $user) {
        $membership = new OrganizationMembership;
        $membership->forceFill(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => StaffRole::Owner, 'status' => 'active', 'location_scope' => 'all', 'permissions' => StaffRole::Owner->permissions()])->save();
    }
    foreach (['desktop', 'mobile'] as $viewport) {
        $teachingUser = User::firstOrCreate(['email' => $viewport.'@example.test'], ['name' => 'Teacher '.$viewport, 'password' => Str::random(40)]);
        $teachingAccess = new OrganizationMembership;
        $teachingAccess->forceFill(['organization_id' => $organization->id, 'user_id' => $teachingUser->id, 'role' => StaffRole::Administrator, 'status' => 'active', 'location_scope' => 'all', 'permissions' => StaffRole::Administrator->permissions()])->save();
    }
    $access = new OrganizationMembership;
    $access->forceFill(['organization_id' => $organization->id, 'user_id' => $trainer->id, 'role' => StaffRole::Trainer, 'status' => 'active', 'location_scope' => 'all', 'permissions' => StaffRole::Trainer->permissions()])->save();
}
echo "Isolated browser fixtures prepared.\n";
