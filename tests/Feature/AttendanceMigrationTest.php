<?php

namespace Tests\Feature;

use App\Models\StudioMember;
use App\Models\TrainingSession;
use App\Support\SessionMaterializer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

class AttendanceMigrationTest extends TestCase
{
    use DatabaseMigrations, SessionFixtures;

    public function test_populated_gt007_upgrade_preserves_records_and_grants(): void
    {
        [$o,$u,$a,$l,$t,$g] = $this->sessionFixture();
        $a->permissions = array_values(array_diff($a->permissions, ['memberships.view', 'memberships.manage', 'attendance.manage', 'attendance.correct']));
        $a->save();
        $g->capacity = 1;
        $g->save();
        SessionMaterializer::run($o->id, $g->public_id);
        $s = TrainingSession::firstOrFail();
        for ($i = 0; $i < 2; $i++) {
            $m = new StudioMember;
            $m->forceFill(['organization_id' => $o->id, 'first_name' => 'Offline', 'last_name' => (string) $i, 'status' => 'active', 'digital_access_mode' => 'offline', 'preferred_locale' => 'mk'])->save();
            if ($i === 0) {
                $this->postJson($this->base($o)."/groups/$g->public_id/permanent-spots", ['member_id' => $m->public_id, 'effective_from' => $s->local_date, 'status' => 'active'])->assertCreated();
            } else {
                $this->postJson($this->base($o)."/sessions/$s->public_id/reservations", ['member_id' => $m->public_id, 'allow_waiting' => true])->assertOk();
            }
        }
        Artisan::call('migrate:rollback', ['--step' => 6, '--force' => true]);
        $tables = ['users', 'organizations', 'locations', 'organization_memberships', 'membership_locations', 'studio_members', 'trainer_profiles', 'training_groups', 'schedule_rules', 'training_sessions', 'reservations', 'waiting_list_entries', 'permanent_spots', 'permanent_spot_releases', 'audit_events'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::select('SELECT row_to_json(t)::text AS value FROM '.$table.' t ORDER BY row_to_json(t)::text');
        }
        Artisan::call('migrate', ['--force' => true]);
        foreach ($tables as $table) {
            $this->assertEquals($before[$table], DB::select('SELECT row_to_json(t)::text AS value FROM '.$table.' t ORDER BY row_to_json(t)::text'), $table);
        }
        $this->assertDatabaseCount('attendance_records', 0);
    }
}
