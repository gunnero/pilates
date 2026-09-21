<?php

namespace Tests\Feature;

use App\Support\SessionMaterializer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

class ReservationMigrationTest extends TestCase
{
    use DatabaseMigrations,SessionFixtures;

    public function test_populated_gt006_upgrade_preserves_business_data_and_grants(): void
    {
        [$org,$user,$access,,$trainer,$g] = $this->sessionFixture();
        // Prepare sessions while the current runtime can access its schema, then roll back only GT-007.
        SessionMaterializer::run($org->id, $g->public_id);
        $access->permissions = array_values(array_filter($access->permissions, fn ($p) => ! str_starts_with($p, 'reservations.') && ! str_starts_with($p, 'waiting_list.') && ! str_starts_with($p, 'permanent_spots.')));
        $access->save();
        $this->postJson($this->base($org).'/members', ['first_name' => 'Offline', 'last_name' => 'Fixture'])->assertCreated();
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
        $tables = ['users', 'organizations', 'locations', 'organization_memberships', 'membership_locations', 'studio_members', 'trainer_profiles', 'training_groups', 'schedule_rules', 'training_sessions', 'audit_events'];
        $before = [];
        foreach ($tables as $t) {
            $before[$t] = DB::table($t)->orderBy($t === 'membership_locations' ? 'organization_membership_id' : 'id')->get()->toJson();
        }
        Artisan::call('migrate', ['--force' => true]);
        foreach ($tables as $t) {
            $this->assertSame($before[$t], DB::table($t)->orderBy($t === 'membership_locations' ? 'organization_membership_id' : 'id')->get()->toJson(), $t);
        }
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('permanent_spots', 0);
        $this->assertDatabaseCount('waiting_list_entries',0);
    }
}
