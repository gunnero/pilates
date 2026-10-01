<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PermanentSpot;
use App\Models\Reservation;
use App\Models\StudioMember;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WaitingListEntry;
use App\Support\MembershipStatus;
use App\Support\MemberStatus;
use App\Support\SessionMaterializer;
use App\Support\StaffRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use DatabaseMigrations, SessionFixtures;

    private function member($org, string $name = 'Offline'): StudioMember
    {
        $m = new StudioMember;
        $m->forceFill(['organization_id' => $org->id, 'first_name' => $name, 'last_name' => 'Fixture', 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'offline'])->save();

        return $m;
    }

    public function test_allocation_waiting_fifo_cancellation_and_capacity(): void
    {
        [$org,$u,$access,$loc,$t,$g] = $this->sessionFixture();
        $id = $this->postJson($this->base($org).'/sessions', $this->sessionData($g, $loc, $t, ['capacity' => 1]))->assertCreated()->json('data.id');
        $base = $this->base($org);
        $a = $this->member($org, 'Ana');
        $b = $this->member($org, 'Elena');
        $c = $this->member($org, 'Marija');
        $first = $this->postJson("$base/sessions/$id/reservations", ['member_id' => $a->public_id])->assertOk()->assertJsonPath('data.outcome', 'reserved')->json('data.reservation.id');
        $this->postJson("$base/sessions/$id/reservations", ['member_id' => $a->public_id])->assertOk()->assertJsonPath('data.outcome', 'already_reserved');
        foreach ([$b, $c] as $m) {
            $this->postJson("$base/sessions/$id/reservations", ['member_id' => $m->public_id, 'allow_waiting' => true])->assertOk()->assertJsonPath('data.outcome', 'waitlisted');
        }
        $this->postJson("$base/reservations/$first/cancel")->assertOk();
        $this->postJson("$base/reservations/$first/cancel")->assertOk();
        $this->assertSame(1, Reservation::where('status', 'active')->count());
        $this->assertSame($b->id, Reservation::where('status', 'active')->firstOrFail()->studio_member_id);
        $this->assertSame(1, WaitingListEntry::where('status', 'waiting')->count());
        $data = $this->sessionData($g, $loc, $t, ['capacity' => 2]);
        unset($data['group_id']);
        $this->patchJson("$base/sessions/$id", $data)->assertOk();
        $this->assertSame(2, Reservation::where('status', 'active')->count());
        $this->patchJson("$base/sessions/$id", [...$data, 'capacity' => 1])->assertStatus(409);
    }

    public function test_permanent_sync_release_and_end_preserve_history(): void
    {
        [$org,$u,$access,$loc,$t,$g] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $m = $this->member($org);
        $base = $this->base($org);
        $p = $this->postJson("$base/groups/$g->public_id/permanent-spots", ['member_id' => $m->public_id, 'effective_from' => '2026-09-21', 'status' => 'active'])->assertCreated()->json('data.id');
        $this->assertSame(8, Reservation::where('status', 'active')->count());
        $r = Reservation::firstOrFail();
        $this->postJson("$base/reservations/$r->public_id/cancel")->assertOk();
        SessionMaterializer::run($org->id, $g->public_id);
        $this->assertSame(7, Reservation::where('status', 'active')->count());
        $this->patchJson("$base/permanent-spots/$p", ['effective_from' => '2026-09-21', 'status' => 'inactive'])->assertOk();
        $this->assertSame(0, Reservation::where('status', 'active')->count());
        $this->assertDatabaseCount('reservations', 8);
    }

    public function test_scoped_lookup_does_not_grant_directory_or_foreign_access(): void
    {
        [$org,$u,$access,$loc,$t,$g] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $session = TrainingSession::firstOrFail();
        $m = $this->member($org);
        $base = $this->base($org);
        $staff = User::factory()->create();
        $membership = $this->membership($staff, $org, StaffRole::Staff, ['studio.access', 'sessions.view', 'reservations.view', 'reservations.manage', 'reservations.cancel', 'waiting_list.view', 'waiting_list.manage'], 'selected');
        DB::table('membership_locations')->insert(['organization_id' => $org->id, 'organization_membership_id' => $membership->id, 'location_id' => $loc->id]);
        $this->actingAs($staff)->postJson("$base/sessions/$session->public_id/member-lookup", ['search' => 'Of'])->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.notes');
        $this->getJson("$base/members")->assertForbidden();
        $this->postJson("$base/sessions/$session->public_id/reservations", ['member_id' => $m->public_id, 'source' => 'member'])->assertUnprocessable();
        $other = Organization::create(['name' => 'Other']);
        $foreign = $this->member($other);
        $this->postJson("$base/sessions/$session->public_id/reservations", ['member_id' => $foreign->public_id])->assertNotFound();
        $this->postJson("$base/sessions/$session->public_id/reservations", ['member_id' => $m->public_id])->assertOk();
        $membership->status = MembershipStatus::Suspended;
        $membership->save();
        $this->postJson("$base/sessions/$session->public_id/reservations", ['member_id' => $m->public_id])->assertNotFound();
    }

    public function test_ineligible_waiting_member_is_skipped_and_parent_cancellation_preserves_history(): void
    {
        [$org,$u,$access,$loc,$t,$g] = $this->sessionFixture();
        $base = $this->base($org);
        $id = $this->postJson("$base/sessions", $this->sessionData($g, $loc, $t, ['capacity' => 1]))->assertCreated()->json('data.id');
        $a = $this->member($org, 'First');
        $b = $this->member($org, 'Second');
        $c = $this->member($org, 'Third');
        $first = $this->postJson("$base/sessions/$id/reservations", ['member_id' => $a->public_id])->json('data.reservation.id');
        foreach ([$b, $c] as $m) {
            $this->postJson("$base/sessions/$id/waiting-list", ['member_id' => $m->public_id])->assertOk();
        }
        $b->status = MemberStatus::Inactive;
        $b->save();
        $this->postJson("$base/reservations/$first/cancel")->assertOk();
        $this->assertSame(1, WaitingListEntry::where('status', 'ineligible')->count());
        $this->assertSame($c->id, Reservation::where('status', 'active')->firstOrFail()->studio_member_id);
        $this->postJson("$base/sessions/$id/cancel")->assertOk();
        $this->assertDatabaseCount('reservations', 2);
        $this->assertDatabaseCount('waiting_list_entries', 2);
        $this->postJson("$base/sessions/$id/reservations", ['member_id' => $a->public_id])->assertStatus(409)->assertJsonPath('code', 'session_cancelled');
        $this->artisan('gt:reservations:check')->assertSuccessful();
    }

    public function test_permission_adoption_is_explicit_and_idempotent(): void
    {
        [$org,$u,$access] = $this->sessionFixture();
        $access->permissions = array_values(array_filter($access->permissions, fn ($p) => ! str_starts_with($p, 'reservations.') && ! str_starts_with($p, 'waiting_list.') && ! str_starts_with($p, 'permanent_spots.')));
        $access->save();
        $before = DB::table('audit_events')->count();
        for ($i = 0; $i < 2; $i++) {
            $this->artisan('gt:reservation-access-enable', ['organization' => $org->public_id, 'membership' => $access->public_id, '--reason' => 'Test adoption'])->assertSuccessful();
        }
        $this->assertContains('reservations.manage', $access->fresh()->permissions);
        $this->assertSame($before + 1, DB::table('audit_events')->count());
    }

    public function test_effective_period_capacity_manual_collision_and_reconciliation(): void
    {
        [$org,$u,$access,$loc,$t,$g] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $base = $this->base($org);
        $m = $this->member($org);
        $session = TrainingSession::orderBy('id')->firstOrFail();
        $manual = $this->postJson("$base/sessions/$session->public_id/reservations", ['member_id' => $m->public_id])->assertOk()->json('data.reservation.id');
        $id = $this->postJson("$base/groups/$g->public_id/permanent-spots", ['member_id' => $m->public_id, 'effective_from' => '2026-09-21', 'effective_until' => '2026-09-28', 'status' => 'active'])->assertCreated()->assertJsonPath('meta.synchronization.existing', 1)->json('data.id');
        $this->assertSame(2, Reservation::where('status', 'active')->count());
        $this->postJson("$base/groups/$g->public_id/permanent-spots", ['member_id' => $m->public_id, 'effective_from' => '2026-09-28', 'status' => 'active'])->assertStatus(409);
        $this->patchJson("$base/permanent-spots/$id", ['effective_from' => '2026-09-21', 'status' => 'inactive'])->assertOk();
        $this->assertSame('active', Reservation::where('public_id', $manual)->firstOrFail()->status);
        $this->assertSame(1, Reservation::where('status', 'active')->count());
        $this->postJson("$base/groups/$g->public_id/permanent-spots", ['member_id' => $m->public_id, 'effective_from' => '2026-10-01', 'status' => 'active'])->assertCreated();
        $this->artisan('gt:reservations:check')->assertSuccessful();
    }

    public function test_concrete_capacity_conflict_is_reported_without_overbooking(): void
    {
        [$org,$u,$access,$loc,$t,$g] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $base = $this->base($org);
        $s = TrainingSession::firstOrFail();
        $s->capacity = 1;
        $s->save();
        $a = $this->member($org, 'A');
        $b = $this->member($org, 'B');
        $this->postJson("$base/sessions/$s->public_id/reservations", ['member_id' => $a->public_id])->assertOk();
        $this->postJson("$base/groups/$g->public_id/permanent-spots", ['member_id' => $b->public_id, 'effective_from' => '2026-09-21', 'status' => 'active'])->assertCreated()->assertJsonPath('meta.synchronization.conflicts', 1);
        $this->assertSame(1, Reservation::where('training_session_id', $s->id)->where('status', 'active')->count());
        $this->artisan('gt:sessions:materialize', ['--organization' => $org->public_id])->assertFailed();
    }

    public function test_postgresql_rejects_foreign_keys_statuses_duplicates_and_overlaps(): void
    {
        [$org,$u,$access,$loc,$t,$g] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $base = $this->base($org);
        $s = TrainingSession::firstOrFail();
        $m = $this->member($org);
        $this->postJson("$base/sessions/$s->public_id/reservations", ['member_id' => $m->public_id])->assertOk();
        $r = Reservation::firstOrFail();
        $other = Organization::create(['name' => 'Foreign']);
        $foreign = $this->member($other);
        foreach ([['studio_member_id' => $foreign->id], ['status' => 'attended'], ['source' => 'forged'], []] as $change) {
            try {
                DB::transaction(function () use ($r, $change) {
                    $row = $r->getAttributes();
                    unset($row['id']);
                    $row['public_id'] = (string) Str::uuid7();
                    DB::table('reservations')->insert([...$row, ...$change]);
                });
                $this->fail('Invalid database write accepted');
            } catch (QueryException $e) {
                $this->assertStringStartsWith('23', (string) $e->getCode());
            }
        }
        $this->postJson("$base/groups/$g->public_id/permanent-spots", ['member_id' => $m->public_id, 'effective_from' => '2026-09-21', 'status' => 'active'])->assertCreated();
        $spot = PermanentSpot::firstOrFail();
        try {
            DB::transaction(function () use ($spot) {
                $row = $spot->getAttributes();
                unset($row['id']);
                $row['public_id'] = (string) Str::uuid7();
                DB::table('permanent_spots')->insert($row);
            });
            $this->fail('Overlap accepted');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->getCode());
        }
    }

    public function test_audit_failure_rolls_back_allocations_and_queue_changes(): void
    {
        [$org,$u,$access,$loc,$t,$g] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $s = TrainingSession::firstOrFail();
        $m = $this->member($org);
        DB::statement("CREATE FUNCTION gt007_reject_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'fixture'; END $$");
        DB::statement('CREATE TRIGGER gt007_reject_audit BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION gt007_reject_audit()');
        try {
            $this->postJson($this->base($org)."/sessions/$s->public_id/reservations", ['member_id' => $m->public_id])->assertStatus(500);
            $this->assertDatabaseCount('reservations', 0);
        } finally {
            DB::statement('DROP TRIGGER gt007_reject_audit ON audit_events');
            DB::statement('DROP FUNCTION gt007_reject_audit()');
        }
    }

    public function test_materialization_cannot_synchronize_hidden_occurrences(): void
    {
        [$org,$u,$access,$loc,$t,$g] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $base = $this->base($org);
        $other = $this->location($org, 'Hidden');
        $s = TrainingSession::firstOrFail();
        $s->location_id = $other->id;
        $s->save();
        $staff = User::factory()->create();
        $access = $this->membership($staff, $org, StaffRole::Staff, ['studio.access', 'groups.view', 'sessions.view', 'sessions.manage'], 'selected');
        DB::table('membership_locations')->insert(['organization_id' => $org->id, 'organization_membership_id' => $access->id, 'location_id' => $loc->id]);
        $this->actingAs($staff)->postJson("$base/groups/$g->public_id/materialize")->assertNotFound();
    }

    public function test_waiting_manager_cannot_bypass_reservation_management(): void
    {
        [$org,$u,$access,$loc,$t,$g] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $s = TrainingSession::firstOrFail();
        $m = $this->member($org);
        $access->permissions = array_values(array_diff($access->permissions, ['reservations.manage']));
        $access->save();
        $this->postJson($this->base($org)."/sessions/$s->public_id/waiting-list", ['member_id' => $m->public_id])->assertForbidden();
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_permanent_view_revoked_after_route_check_is_rechecked_under_lock(): void
    {
        [$org,$u,$access,$loc,$t,$g] = $this->sessionFixture();
        $m = $this->member($org);
        $revoked = false;
        DB::listen(function ($query) use (&$revoked, $access) {
            if (! $revoked && str_contains($query->sql, '"organizations"') && str_contains($query->sql, 'for update')) {
                $revoked = true;
                DB::table('organization_memberships')->where('id', $access->id)->update(['permissions' => json_encode(array_values(array_diff($access->permissions, ['permanent_spots.view'])), JSON_THROW_ON_ERROR)]);
            }
        });
        $this->postJson($this->base($org)."/groups/$g->public_id/permanent-spots", ['member_id' => $m->public_id, 'effective_from' => '2026-09-21', 'status' => 'active'])->assertForbidden();
        $this->assertTrue($revoked);
        $this->assertDatabaseCount('permanent_spots', 0);
    }

    public static function grants(): array
    {
        return array_map(fn ($p) => [$p], ['reservations.view', 'reservations.manage', 'reservations.cancel', 'waiting_list.view', 'waiting_list.manage', 'permanent_spots.view', 'permanent_spots.manage']);
    }

    #[DataProvider('grants')]
    public function test_independent_permission_is_required(string $permission): void
    {
        [$org,$u,$access,$loc,$t,$g] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $session = TrainingSession::firstOrFail();
        $m = $this->member($org);
        $base = $this->base($org);
        $rid = $this->postJson("$base/sessions/$session->public_id/reservations", ['member_id' => $m->public_id])->json('data.reservation.id');
        $access->permissions = array_values(array_diff($access->permissions, [$permission]));
        $access->save();
        match ($permission) {
            'reservations.view' => $this->getJson("$base/sessions/$session->public_id/reservations")->assertForbidden(),
            'reservations.manage' => $this->postJson("$base/sessions/$session->public_id/reservations", ['member_id' => $m->public_id])->assertForbidden(),
            'reservations.cancel' => $this->postJson("$base/reservations/$rid/cancel")->assertForbidden(),
            'waiting_list.view' => $this->getJson("$base/sessions/$session->public_id/waiting-list")->assertForbidden(),
            'waiting_list.manage' => $this->postJson("$base/sessions/$session->public_id/waiting-list", ['member_id' => $m->public_id])->assertForbidden(),
            'permanent_spots.view' => $this->getJson("$base/groups/$g->public_id/permanent-spots")->assertForbidden(),
            'permanent_spots.manage' => $this->postJson("$base/groups/$g->public_id/permanent-spots", ['member_id' => $m->public_id, 'effective_from' => '2026-09-21', 'status' => 'active'])->assertForbidden(),
        };
    }

    public static function invalidMembers(): array
    {
        return [['inactive'], ['archived'], ['started'], ['foreign_session']];
    }

    #[DataProvider('invalidMembers')]
    public function test_allocation_eligibility_fails_closed(string $case): void
    {
        [$org,$u,$access,$loc,$t,$g] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $s = TrainingSession::firstOrFail();
        $m = $this->member($org);
        if (in_array($case, ['inactive', 'archived'], true)) {
            $m->status = MemberStatus::from($case);
            $m->save();
        }
        if ($case === 'started') {
            $this->travelTo($s->starts_at->addMinute());
        }
        $id = $case === 'foreign_session' ? (string) Str::uuid7() : $s->public_id;
        $this->postJson($this->base($org)."/sessions/$id/reservations", ['member_id' => $m->public_id])->assertStatus($case === 'foreign_session' ? 404 : 409);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_waiting_constraints_tie_breaker_rejoin_and_protected_fields(): void
    {
        [$org,$u,$access,$loc,$t,$g] = $this->sessionFixture();
        $base = $this->base($org);
        $id = $this->postJson("$base/sessions", $this->sessionData($g, $loc, $t, ['capacity' => 1]))->json('data.id');
        $a = $this->member($org, 'A');
        $b = $this->member($org, 'B');
        $c = $this->member($org, 'C');
        $rid = $this->postJson("$base/sessions/$id/reservations", ['member_id' => $a->public_id])->json('data.reservation.id');
        foreach ([$b, $c] as $m) {
            $this->postJson("$base/sessions/$id/waiting-list", ['member_id' => $m->public_id])->assertOk();
        }
        $w = WaitingListEntry::orderBy('id')->firstOrFail();
        $this->assertSame(1, WaitingListEntry::select('joined_at')->distinct()->count('joined_at'));
        foreach ([[], ['status' => 'attended'], ['source' => 'priority']] as $change) {
            try {
                DB::transaction(function () use ($w, $change) {
                    $row = $w->getAttributes();
                    unset($row['id']);
                    $row['public_id'] = (string) Str::uuid7();
                    DB::table('waiting_list_entries')->insert([...$row, ...$change]);
                });
                $this->fail('Invalid queue write accepted');
            } catch (QueryException $e) {
                $this->assertStringStartsWith('23', (string) $e->getCode());
            }
        }
        foreach (['organization_id', 'id', 'public_id', 'created_by', 'promoted_at', 'position', 'permanent_spot_id', 'status'] as $field) {
            $this->postJson("$base/sessions/$id/reservations", ['member_id' => $b->public_id, $field => 'forged'])->assertUnprocessable();
        }
        $this->postJson("$base/waiting-list/$w->public_id/cancel")->assertOk();
        $this->postJson("$base/waiting-list/$w->public_id/cancel")->assertOk();
        $this->postJson("$base/sessions/$id/waiting-list", ['member_id' => $b->public_id])->assertOk();
        $this->postJson("$base/reservations/$rid/cancel")->assertOk();
        $this->assertSame($c->id, Reservation::where('status', 'active')->firstOrFail()->studio_member_id);
    }
}
