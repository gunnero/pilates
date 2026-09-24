<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

class TrainingSessionMigrationTest extends TestCase
{
    use DatabaseMigrations,SessionFixtures;

    public function test_populated_gt005_upgrade_preserves_all_data_and_grants(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 7, '--force' => true]);
        // Reuse legacy grants while building a populated pre-session fixture.
        $org = Organization::create(['name' => 'Legacy']);
        $user = User::factory()->create();
        $permissions = array_values(array_diff(StaffRole::Owner->permissions(), ['memberships.view', 'memberships.manage', 'attendance.manage', 'attendance.correct', 'reservations.view', 'reservations.manage', 'reservations.cancel', 'waiting_list.view', 'waiting_list.manage', 'permanent_spots.view', 'permanent_spots.manage', 'sessions.view', 'sessions.manage', 'sessions.cancel']));
        $access = $this->membership($user, $org, StaffRole::Owner, $permissions);
        $loc = $this->location($org);
        $this->actingAs($user);
        $trainer = $this->postJson($this->base($org).'/trainers', ['membership_id' => $access->public_id])->assertCreated()->json('data.id');
        $group = $this->postJson($this->base($org).'/groups', ['name' => 'Legacy group', 'location_id' => $loc->public_id, 'primary_trainer_id' => $trainer, 'capacity' => 12])->assertCreated()->json('data.id');
        $this->postJson($this->base($org).'/groups/'.$group.'/schedule', ['weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00', 'effective_from' => '2035-01-01'])->assertCreated();
        $this->postJson($this->base($org).'/members', ['first_name' => 'Legacy', 'last_name' => 'Fixture'])->assertCreated();
        $tables = ['users', 'organizations', 'locations', 'organization_memberships', 'membership_locations', 'studio_members', 'trainer_profiles', 'training_groups', 'schedule_rules', 'audit_events'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy($table === 'membership_locations' ? 'organization_membership_id' : 'id')->get()->toJson();
        }
        Artisan::call('migrate', ['--force' => true]);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy($table === 'membership_locations' ? 'organization_membership_id' : 'id')->get()->toJson());
        }
        $this->assertDatabaseCount('training_sessions', 0);
        $this->assertSame($permissions, $access->fresh()->permissions);
    }
}
