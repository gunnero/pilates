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

class StudioMemberMigrationTest extends TestCase
{
    use DatabaseMigrations, OrganizationFixtures;

    public function test_upgrade_preserves_existing_grants_and_audit_without_inventing_clients(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Upgrade Fixture']);
        $permissions = array_values(array_diff(StaffRole::Owner->permissions(), [...StaffRole::memberPermissions(), 'trainers.view', 'trainers.manage']));
        $access = $this->membership($user, $org, StaffRole::Owner, $permissions);
        $this->actingAs($user)->patchJson($this->base($org), ['name' => 'Preserved Fixture'])->assertOk();
        $before = DB::table('audit_events')->get()->toJson();
        Artisan::call('migrate', ['--force' => true]);
        $this->assertSame($permissions, $access->fresh()->permissions);
        $this->assertSame($before, DB::table('audit_events')->get()->toJson());
        $this->assertDatabaseCount('studio_members', 0);
        $this->assertDatabaseCount('users', 1);
        $this->getJson($this->base($org).'/members')->assertForbidden();
    }
}
