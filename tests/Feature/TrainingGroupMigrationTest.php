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

class TrainingGroupMigrationTest extends TestCase
{
    use DatabaseMigrations, OrganizationFixtures;

    public function test_upgrade_preserves_existing_staff_clients_trainers_and_audit(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);
        $org = Organization::create(['name' => 'Legacy fixture']);
        $user = User::factory()->create();
        $grants = array_values(array_diff(StaffRole::Owner->permissions(), ['groups.view', 'groups.manage', 'schedule.view', 'schedule.manage', 'sessions.view', 'sessions.manage', 'sessions.cancel']));
        $access = $this->membership($user, $org, StaffRole::Owner, $grants);
        $this->location($org);
        $this->actingAs($user)->postJson($this->base($org).'/members', ['first_name' => 'Fixture', 'last_name' => 'Legacy'])->assertCreated();
        $this->postJson($this->base($org).'/trainers', ['membership_id' => $access->public_id])->assertCreated();
        $tables = ['organizations', 'locations', 'organization_memberships', 'studio_members', 'trainer_profiles', 'audit_events'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        Artisan::call('migrate', ['--force' => true]);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
        }
        $this->assertDatabaseCount('training_groups', 0);
        $this->assertDatabaseCount('schedule_rules', 0);
        $this->assertSame($grants, $access->fresh()->permissions);
    }
}
