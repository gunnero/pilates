<?php

use App\Models\Location;
use App\Models\MembershipPlan;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PlatformNotification;
use App\Models\Reservation;
use App\Models\ScheduleRule;
use App\Models\StudioMember;
use App\Models\TrainerProfile;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\Allocation;
use App\Support\FinanceLifecycle;
use App\Support\Notifications\Delivery;
use App\Support\Notifications\Generate;
use App\Support\SessionMaterializer;
use App\Support\StaffRole;
use App\Support\TenantContext;
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

$notificationFixtures = [];
foreach (['desktop', 'mobile'] as $viewport) {
    $org = Organization::create(['name' => 'Notification studio '.$viewport]);
    $actor = User::factory()->create(['name' => 'Notification owner', 'email' => 'notifications-staff-'.$viewport.'@browser.example.test', 'password' => 'Browser-fixture-123!']);
    $access = new OrganizationMembership;
    $access->forceFill(['organization_id' => $org->id, 'user_id' => $actor->id, 'role' => StaffRole::Owner, 'status' => 'active', 'location_scope' => 'all', 'permissions' => StaffRole::Owner->permissions()])->save();
    $loc = new Location;
    $loc->forceFill(['organization_id' => $org->id, 'name' => 'Attendance Centar', 'status' => 'active'])->save();
    $trainer = new TrainerProfile;
    $trainer->forceFill(['organization_id' => $org->id, 'organization_membership_id' => $access->id, 'status' => 'active'])->save();
    $group = new TrainingGroup;
    $group->forceFill(['organization_id' => $org->id, 'location_id' => $loc->id, 'primary_trainer_id' => $trainer->id, 'name' => 'Attendance Pilates', 'capacity' => 1, 'status' => 'active'])->save();
    $start = CarbonImmutable::now()->addHours(23);
    $s = new TrainingSession;
    $s->forceFill(['organization_id' => $org->id, 'training_group_id' => $group->id, 'location_id' => $loc->id, 'trainer_profile_id' => $trainer->id, 'source_type' => 'manual', 'status' => 'scheduled', 'capacity' => 1, 'group_name' => $group->name, 'location_name' => $loc->name, 'trainer_name' => 'Notification owner', 'timezone' => $org->timezone, 'local_date' => $start->setTimezone($org->timezone)->toDateString(), 'starts_at' => $start, 'ends_at' => $start->addHour()])->save();
    $recipient = User::factory()->create(['name' => 'Notification member', 'email' => 'notifications-'.$viewport.'@browser.example.test', 'password' => 'Browser-fixture-123!']);
    $client = new StudioMember;
    $client->forceFill(['organization_id' => $org->id, 'user_id' => $recipient->id, 'first_name' => 'Inbox', 'last_name' => 'Client', 'status' => 'active', 'digital_access_mode' => 'app', 'preferred_locale' => 'mk'])->save();
    $other = new StudioMember;
    $other->forceFill(['organization_id' => $org->id, 'first_name' => 'Other', 'last_name' => 'Client', 'status' => 'active', 'digital_access_mode' => 'offline', 'preferred_locale' => 'mk'])->save();
    DB::transaction(function () use ($org, $s, $client, $other, $actor) {
        Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
        Allocation::reserve($s, $other, $actor, false);
        Allocation::reserve($s, $client, $actor, true);
        $first = Reservation::where('training_session_id', $s->id)->where('status', 'active')->firstOrFail();
        Allocation::cancel($s, $first, $actor);
    });
    // Confirm one reservation, then cancel before reminder generation in the eligible window.
    $cancelSession = $s->replicate(['public_id']);
    $cancelSession->forceFill(['starts_at' => $s->starts_at->addHour(), 'ends_at' => $s->ends_at->addHour(), 'local_date' => $s->starts_at->addHour()->setTimezone($org->timezone)->toDateString()])->save();
    DB::transaction(function () use ($org, $cancelSession, $client, $actor) {
        Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
        Allocation::reserve($cancelSession, $client, $actor, false);
    });
    $cancelReservation = Reservation::where('training_session_id', $cancelSession->id)->firstOrFail();
    Delivery::run(PlatformNotification::where('reservation_id', $cancelReservation->id)->firstOrFail()->delivery->id);
    DB::transaction(function () use ($org, $cancelSession, $cancelReservation, $actor) {
        Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
        Allocation::cancel($cancelSession, $cancelReservation, $actor);
    });
    $context = app(TenantContext::class);
    $context->resolve($actor, $org->public_id);
    $plan = FinanceLifecycle::plan($context, $actor, ['name' => 'Inbox plan', 'price_minor' => 150000, 'duration_unit' => 'days', 'duration_count' => 7, 'status' => 'active']);
    $membership = FinanceLifecycle::assign($context, $actor, $client->public_id, $plan->public_id, now($org->timezone)->toDateString());
    $payment = FinanceLifecycle::pay($context, $actor, ['membership_id' => $membership->public_id, 'amount_minor' => 150000, 'paid_at' => now()->toISOString(), 'request_id' => (string) Str::uuid7()]);
    Delivery::run(PlatformNotification::where('cash_payment_id', $payment->id)->firstOrFail()->delivery->id);
    FinanceLifecycle::reverse($context, $actor, $payment->public_id, 'wrong_amount');
    Generate::run($org->public_id);
    $rerun = Generate::run($org->public_id);
    if (PlatformNotification::where('reservation_id', $cancelReservation->id)->where('type', 'session.reminder')->exists()) {
        throw new RuntimeException('Cancelled reservation received reminder');
    }
    if ($rerun['created'] !== 0 || $rerun['failed'] !== 0) {
        throw new RuntimeException('Notification fixture idempotency failed');
    }
    foreach (NotificationDelivery::where('organization_id', $org->id)->pluck('id') as $delivery) {
        Delivery::run($delivery);
    }
    $notificationFixtures[$viewport] = ['organization' => $org->public_id];
}
file_put_contents(base_path('.local/browser-notification-fixture.json'), json_encode($notificationFixtures, JSON_THROW_ON_ERROR));

// GT011 isolated account fixtures. Tokens are captured only by the guarded test router.
$selfFixtures = [];
foreach (['desktop', 'mobile', 'native-desktop', 'native-mobile'] as $viewport) {
    $org = Organization::create(['name' => 'Member studio '.$viewport]);
    $actor = User::factory()->create(['name' => 'Member owner', 'email' => 'member-staff-'.$viewport.'@browser.example.test', 'password' => 'Browser-fixture-123!']);
    $access = new OrganizationMembership;
    $access->forceFill(['organization_id' => $org->id, 'user_id' => $actor->id, 'role' => StaffRole::Owner, 'status' => 'active', 'location_scope' => 'all', 'permissions' => StaffRole::Owner->permissions()])->save();
    $loc = new Location;
    $loc->forceFill(['organization_id' => $org->id, 'name' => 'Member Centar', 'status' => 'active'])->save();
    $trainer = new TrainerProfile;
    $trainer->forceFill(['organization_id' => $org->id, 'organization_membership_id' => $access->id, 'status' => 'active'])->save();
    $group = new TrainingGroup;
    $group->forceFill(['organization_id' => $org->id, 'location_id' => $loc->id, 'primary_trainer_id' => $trainer->id, 'name' => 'Member Pilates', 'capacity' => 1, 'status' => 'active'])->save();
    $start = CarbonImmutable::now()->addDay()->startOfHour();
    $s = new TrainingSession;
    $s->forceFill(['organization_id' => $org->id, 'training_group_id' => $group->id, 'location_id' => $loc->id, 'trainer_profile_id' => $trainer->id, 'source_type' => 'manual', 'status' => 'scheduled', 'capacity' => 1, 'group_name' => $group->name, 'location_name' => $loc->name, 'trainer_name' => 'Member trainer', 'timezone' => $org->timezone, 'local_date' => $start->setTimezone($org->timezone)->toDateString(), 'starts_at' => $start, 'ends_at' => $start->addHour()])->save();
    $otherUser = User::factory()->create(['name' => 'Waiting member', 'email' => 'member-wait-'.$viewport.'@browser.example.test', 'password' => 'Browser-fixture-123!']);
    $other = new StudioMember;
    $other->forceFill(['organization_id' => $org->id, 'user_id' => $otherUser->id, 'first_name' => 'Waiting', 'last_name' => 'Member', 'status' => 'active', 'digital_access_mode' => 'app', 'preferred_locale' => 'en'])->save();
    // An existing second studio link for the account that will activate the first studio invitation.
    $account = User::factory()->create(['name' => 'Self member', 'email' => 'member-'.$viewport.'@browser.example.test', 'password' => 'Browser-fixture-123!']);
    $second = Organization::create(['name' => 'Second member studio '.$viewport]);
    $linked = new StudioMember;
    $linked->forceFill(['organization_id' => $second->id, 'user_id' => $account->id, 'first_name' => 'Second', 'last_name' => 'Profile', 'status' => 'active', 'digital_access_mode' => 'app', 'preferred_locale' => 'en'])->save();
    $attendanceSession = $s->replicate(['public_id']);
    $attendanceStart = CarbonImmutable::now()->addMinutes(10);
    $attendanceSession->forceFill(['starts_at' => $attendanceStart, 'ends_at' => $attendanceStart->addHour(), 'local_date' => $attendanceStart->setTimezone($org->timezone)->toDateString()])->save();
    $selfFixtures[$viewport] = ['organization' => $org->public_id, 'second' => $second->public_id, 'session' => $s->public_id, 'other_member' => $other->public_id, 'attendance_session' => $attendanceSession->public_id];
}
file_put_contents(base_path('.local/browser-member-fixture.json'), json_encode($selfFixtures, JSON_THROW_ON_ERROR));
