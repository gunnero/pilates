<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

class TrainerMigrationTest extends TestCase
{
    use DatabaseMigrations, OrganizationFixtures;

    public function test_upgrade_preserves_existing_staff_clients_and_evidence(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 7, '--force' => true]);
        $org = Organization::create(['name' => 'Legacy fixture']);
        $user = User::factory()->create();
        $grants = array_values(array_diff(StaffRole::Owner->permissions(), ['memberships.view', 'memberships.manage', 'attendance.manage', 'attendance.correct', 'reservations.view', 'reservations.manage', 'reservations.cancel', 'waiting_list.view', 'waiting_list.manage', 'permanent_spots.view', 'permanent_spots.manage', 'trainers.view', 'trainers.manage', 'groups.view', 'groups.manage', 'schedule.view', 'schedule.manage', 'sessions.view', 'sessions.manage', 'sessions.cancel']));
        $access = $this->membership($user, $org, StaffRole::Owner, $grants);
        $this->actingAs($user)->postJson($this->base($org).'/members', ['first_name' => 'Test', 'last_name' => 'Legacy'])->assertCreated();
        $tables = ['organizations', 'organization_memberships', 'studio_members', 'audit_events'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        Artisan::call('migrate', ['--force' => true]);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
        }
        $this->assertDatabaseCount('trainer_profiles', 0);
        $this->assertSame($grants, $access->fresh()->permissions);
    }
}
