<?php

use App\Models\Location;
use App\Models\MembershipPlan;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ScheduleRule;
use App\Models\StudioMember;
use App\Models\TrainerProfile;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\Allocation;
use App\Support\SessionMaterializer;
use App\Support\StaffRole;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

$sessionFixtures = [];
foreach (['desktop', 'mobile'] as $viewport) {
    $org = Organization::create(['name' => 'Session studio '.$viewport]);
    $actor = User::factory()->create(['name' => 'Session owner '.$viewport, 'email' => 'sessions-'.$viewport.'@browser.example.test', 'password' => 'Browser-fixture-123!']);
    $member = new OrganizationMembership;
    $member->forceFill(['organization_id' => $org->id, 'user_id' => $actor->id, 'role' => StaffRole::Owner, 'status' => 'active', 'location_scope' => 'all', 'permissions' => StaffRole::Owner->permissions()])->save();
    $location = new Location;
    $location->forceFill(['organization_id' => $org->id, 'name' => 'Session Centar', 'status' => 'active'])->save();
    $trainer = new TrainerProfile;
    $trainer->forceFill(['organization_id' => $org->id, 'organization_membership_id' => $member->id, 'display_name' => 'Session trainer', 'status' => 'active'])->save();
    $group = new TrainingGroup;
    $group->forceFill(['organization_id' => $org->id, 'location_id' => $location->id, 'primary_trainer_id' => $trainer->id, 'name' => 'Session Pilates', 'capacity' => 12, 'status' => 'active'])->save();
    $date = CarbonImmutable::now($org->timezone)->addDay()->startOfDay();
    $rule = new ScheduleRule;
    $rule->forceFill(['organization_id' => $org->id, 'training_group_id' => $group->id, 'weekday' => $date->dayOfWeekIso, 'start_minute' => 600, 'end_minute' => 660, 'effective_from' => $date->toDateString(), 'effective_until' => $date->toDateString()])->save();
    SessionMaterializer::run($org->id, $group->public_id);
    $sessionFixtures[$viewport] = ['date' => $date->toDateString()];
}
file_put_contents(base_path('.local/browser-session-fixture.json'), json_encode($sessionFixtures, JSON_THROW_ON_ERROR));

$bookingFixtures = [];
foreach (['desktop', 'mobile'] as $viewport) {
    $org = Organization::create(['name' => 'Booking studio '.$viewport]);
    $actor = User::factory()->create(['name' => 'Booking owner '.$viewport, 'email' => 'bookings-'.$viewport.'@browser.example.test', 'password' => 'Browser-fixture-123!']);
    $member = new OrganizationMembership;
    $member->forceFill(['organization_id' => $org->id, 'user_id' => $actor->id, 'role' => StaffRole::Owner, 'status' => 'active', 'location_scope' => 'all', 'permissions' => StaffRole::Owner->permissions()])->save();
    $location = new Location;
    $location->forceFill(['organization_id' => $org->id, 'name' => 'Booking Centar', 'status' => 'active'])->save();
    $trainer = new TrainerProfile;
    $trainer->forceFill(['organization_id' => $org->id, 'organization_membership_id' => $member->id, 'display_name' => 'Booking trainer', 'status' => 'active'])->save();
    $group = new TrainingGroup;
    $group->forceFill(['organization_id' => $org->id, 'location_id' => $location->id, 'primary_trainer_id' => $trainer->id, 'name' => 'Booking Pilates', 'capacity' => 1, 'status' => 'active'])->save();
    $date = CarbonImmutable::now($org->timezone)->addDay()->startOfDay();
    $rule = new ScheduleRule;
    $rule->forceFill(['organization_id' => $org->id, 'training_group_id' => $group->id, 'weekday' => $date->dayOfWeekIso, 'start_minute' => 600, 'end_minute' => 660, 'effective_from' => $date->toDateString(), 'effective_until' => $date->toDateString()])->save();
    SessionMaterializer::run($org->id, $group->public_id);
    foreach (['Offline', 'Ana', 'Elena'] as $name) {
        $client = new StudioMember;
        $client->forceFill(['organization_id' => $org->id, 'first_name' => $name, 'last_name' => 'Fixture', 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'offline'])->save();
    }
    $bookingFixtures[$viewport] = ['date' => $date->toDateString(), 'session' => TrainingSession::where('training_group_id', $group->id)->firstOrFail()->public_id, 'group' => $group->public_id];
}
file_put_contents(base_path('.local/browser-booking-fixture.json'), json_encode($bookingFixtures, JSON_THROW_ON_ERROR));

// Isolated, explicitly prepared in-window sessions: no cron or waiting for a session to start.
foreach (['desktop', 'mobile'] as $viewport) {
    $org = Organization::create(['name' => 'Attendance studio '.$viewport]);
    $actor = User::factory()->create(['name' => 'Attendance trainer', 'email' => 'attendance-'.$viewport.'@browser.example.test', 'password' => 'Browser-fixture-123!']);
    $access = new OrganizationMembership;
    $access->forceFill(['organization_id' => $org->id, 'user_id' => $actor->id, 'role' => StaffRole::Trainer, 'status' => 'active', 'location_scope' => 'all', 'permissions' => [...StaffRole::Trainer->permissions(), 'reservations.manage']])->save();
    $loc = new Location;
    $loc->forceFill(['organization_id' => $org->id, 'name' => 'Attendance Centar', 'status' => 'active'])->save();
    $trainer = new TrainerProfile;
    $trainer->forceFill(['organization_id' => $org->id, 'organization_membership_id' => $access->id, 'status' => 'active'])->save();
    $group = new TrainingGroup;
    $group->forceFill(['organization_id' => $org->id, 'location_id' => $loc->id, 'primary_trainer_id' => $trainer->id, 'name' => 'Attendance Pilates', 'capacity' => 12, 'status' => 'active'])->save();
    $start = CarbonImmutable::now()->addMinutes(10);
    $s = new TrainingSession;
    $s->forceFill(['organization_id' => $org->id, 'training_group_id' => $group->id, 'location_id' => $loc->id, 'trainer_profile_id' => $trainer->id, 'source_type' => 'manual', 'status' => 'scheduled', 'capacity' => 12, 'group_name' => $group->name, 'location_name' => $loc->name, 'trainer_name' => 'Attendance trainer', 'timezone' => $org->timezone, 'local_date' => $start->setTimezone($org->timezone)->toDateString(), 'starts_at' => $start, 'ends_at' => $start->addHour()])->save();
    foreach (['Reserved', 'Walkin'] as $name) {
        $client = new StudioMember;
        $client->forceFill(['organization_id' => $org->id, 'first_name' => $name, 'last_name' => 'Offline', 'status' => 'active', 'digital_access_mode' => 'offline', 'preferred_locale' => 'mk'])->save();
        if ($name === 'Reserved') {
            DB::transaction(function () use ($org, $s, $client, $actor) {
                Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
                Allocation::reserve($s, $client, $actor, false);
            });
        }
    }
}

foreach (['desktop', 'mobile'] as $viewport) {
    $org = Organization::create(['name' => 'Finance studio '.$viewport]);
    $actor = User::factory()->create(['name' => 'Finance owner', 'email' => 'finance-'.$viewport.'@browser.example.test', 'password' => 'Browser-fixture-123!']);
    $access = new OrganizationMembership;
    $access->forceFill(['organization_id' => $org->id, 'user_id' => $actor->id, 'role' => StaffRole::Owner, 'status' => 'active', 'location_scope' => 'all', 'permissions' => StaffRole::Owner->permissions()])->save();
    $client = new StudioMember;
    $client->forceFill(['organization_id' => $org->id, 'first_name' => 'Finance', 'last_name' => 'Client', 'status' => 'active', 'digital_access_mode' => 'offline', 'preferred_locale' => 'mk'])->save();
    $plan = new MembershipPlan;
    $plan->forceFill(['organization_id' => $org->id, 'name' => 'Monthly cash', 'price_minor' => 150000, 'currency' => 'MKD', 'duration_unit' => 'months', 'duration_count' => 1, 'status' => 'active'])->save();
}
