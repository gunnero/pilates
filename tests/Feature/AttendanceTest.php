<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\StudioMember;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\Attendance;
use App\Support\AttendanceConflict;
use App\Support\AttendanceQr;
use App\Support\SessionMaterializer;
use App\Support\StaffRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use DatabaseMigrations, SessionFixtures;

    private function member(Organization $org, string $name = 'Offline'): StudioMember
    {
        $m = new StudioMember;
        $m->forceFill(['organization_id' => $org->id, 'first_name' => $name, 'last_name' => 'Fixture', 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'offline'])->save();

        return $m;
    }

    private function fixture(): array
    {
        [$o,$u,$a,$l,$t,$g] = $this->sessionFixture();
        $id = $this->postJson($this->base($o).'/sessions', $this->sessionData($g, $l, $t))->assertCreated()->json('data.id');
        $s = TrainingSession::where('public_id', $id)->firstOrFail();
        $m = $this->member($o);
        $this->postJson($this->base($o)."/sessions/$id/reservations", ['member_id' => $m->public_id])->assertOk();
        $this->travelTo($s->starts_at->subMinutes(10));

        return [$o, $u, $a, $s, $m, $this->base($o)."/sessions/$id/attendance"];
    }

    public function test_offline_check_in_is_idempotent_audited_and_blocks_cancellation_and_edit(): void
    {
        [$o,$u,$a,$s,$m,$url] = $this->fixture();
        $r = Reservation::firstOrFail();
        foreach ([1, 2] as $_) {
            $this->postJson($url, ['member_id' => $m->public_id, 'status' => 'present'])->assertOk()->assertJsonPath('data.method', 'staff');
        }
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'attendance.marked')->count());
        $this->postJson($this->base($o)."/reservations/$r->public_id/cancel")->assertStatus(409);
        $this->postJson($this->base($o)."/sessions/$s->public_id/cancel")->assertStatus(409);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.attendance.status', 'present')->assertJsonMissingPath('data.0.member.email');
        $this->artisan('gt:attendance:check')->assertSuccessful();
        $this->artisan('gt:reservations:check')->assertSuccessful();
    }

    public function test_walk_in_after_start_uses_capacity_and_audits_atomic_booking(): void
    {
        [$o,$u,$a,$s,$m,$url] = $this->fixture();
        $this->travelTo($s->starts_at->addMinutes(5));
        $n = $this->member($o, 'Walk');
        $s->capacity = 2;
        $s->save();
        $this->postJson($url.'/walk-in', ['member_id' => $n->public_id])->assertOk();
        $this->assertSame(2, Reservation::where('status', 'active')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'attendance.walk_in')->count());
        $full = $this->member($o, 'Full');
        $this->postJson($url.'/walk-in', ['member_id' => $full->public_id])->assertStatus(409);
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_waiting_is_not_a_roster_entry_and_has_fifo_priority_over_walk_in(): void
    {
        [$o,$u,$a,$s,$m,$url] = $this->fixture();
        $s->capacity = 1;
        $s->save();
        $n = $this->member($o, 'Waiting');
        $this->postJson($this->base($o)."/sessions/$s->public_id/reservations", ['member_id' => $n->public_id, 'allow_waiting' => true])->assertOk();
        $this->getJson($url)->assertJsonCount(1, 'data');
        $this->postJson($url, ['member_id' => $n->public_id, 'status' => 'present'])->assertStatus(409);
        $s->capacity = 2;
        $s->save();
        $third = $this->member($o, 'Third');
        $this->postJson($url.'/walk-in', ['member_id' => $third->public_id])->assertStatus(409);
        // A failed walk-in is fully rolled back, including incidental FIFO changes.
        $this->assertSame(1, Reservation::where('status', 'active')->count());
        $this->postJson($url.'/walk-in', ['member_id' => $n->public_id])->assertOk();
        $this->assertSame(2, Reservation::where('status', 'active')->count());
    }

    public function test_no_show_finalization_excludes_cancelled_and_present_and_is_idempotent(): void
    {
        [$o,$u,$a,$s,$m,$url] = $this->fixture();
        $n = $this->member($o, 'NoShow');
        $cancelled = $this->member($o, 'Cancelled');
        foreach ([$n, $cancelled] as $x) {
            $this->postJson($this->base($o)."/sessions/$s->public_id/reservations", ['member_id' => $x->public_id])->assertOk();
        }
        $r = Reservation::where('studio_member_id', $cancelled->id)->firstOrFail();
        $this->postJson($this->base($o)."/reservations/$r->public_id/cancel")->assertOk();
        $this->postJson($url, ['member_id' => $m->public_id, 'status' => 'present'])->assertOk();
        $this->postJson($url, ['member_id' => $n->public_id, 'status' => 'no_show'])->assertStatus(409);
        $this->travelTo($s->ends_at->addMinute());
        for ($i = 0; $i < 2; $i++) {
            $this->artisan('gt:attendance:finalize', ['organization' => $o->public_id, '--from' => $s->local_date, '--until' => $s->local_date])->assertSuccessful();
        }
        $this->assertDatabaseCount('attendance_records', 2);
        $this->assertSame(1, AttendanceRecord::where('status', 'no_show')->count());
        $this->postJson($url, ['member_id' => $n->public_id, 'status' => 'present'])->assertStatus(409);
        $this->postJson($url.'/correct', ['member_id' => $n->public_id, 'status' => 'present', 'reason' => 'late_confirmation'])->assertOk();
        $this->postJson($url.'/correct', ['member_id' => $m->public_id, 'status' => 'absent', 'reason' => 'mistake'])->assertOk()->assertJsonPath('data.checked_in_at', null);
        $this->assertSame(2, DB::table('audit_events')->where('action', 'attendance.corrected')->count());
        $this->artisan('gt:attendance:finalize', ['organization' => $o->public_id, '--from' => '2026-01-01', '--until' => '2026-12-01'])->assertFailed();
    }

    public static function deniedCases(): array
    {
        return array_map(fn ($x) => [$x], ['view', 'manage', 'foreign_member', 'foreign_session', 'cancelled_reservation', 'cancelled_session', 'inactive_member', 'early', 'method', 'actor', 'status', 'selected', 'trainer']);
    }

    #[DataProvider('deniedCases')]
    public function test_attendance_fails_closed(string $case): void
    {
        [$o,$u,$a,$s,$m,$url] = $this->fixture();
        $code = 409;
        $body = ['member_id' => $m->public_id, 'status' => 'present'];
        if (in_array($case, ['view', 'manage'], true)) {
            $a->permissions = array_values(array_diff($a->permissions, ['attendance.'.$case]));
            $a->save();
            $code = 403;
        }
        if ($case === 'foreign_member') {
            $foreign = $this->member(Organization::create(['name' => 'Foreign']));
            $body['member_id'] = $foreign->public_id;
            $code = 404;
        }
        if ($case === 'foreign_session') {
            $url = str_replace($s->public_id, (string) Str::uuid7(), $url);
            $code = 404;
        }
        if ($case === 'cancelled_reservation') {
            Reservation::firstOrFail()->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        }
        if ($case === 'cancelled_session') {
            $this->postJson($this->base($o)."/sessions/$s->public_id/cancel")->assertOk();
        }
        if ($case === 'inactive_member') {
            $m->forceFill(['status' => 'inactive'])->save();
        }
        if ($case === 'early') {
            $this->travelTo($s->starts_at->subMinutes(31));
        }
        if (in_array($case, ['method', 'actor', 'status'], true)) {
            $body[$case === 'status' ? 'organization_id' : $case] = 'forged';
            $code = 422;
        }
        if (in_array($case, ['selected', 'trainer'], true)) {
            $u2 = User::factory()->create();
            $this->membership($u2, $o, $case === 'trainer' ? StaffRole::Trainer : StaffRole::Staff, ['studio.access', 'sessions.view', 'attendance.view', 'attendance.manage'], $case === 'selected' ? 'selected' : 'all');
            $this->actingAs($u2);
            $code = 404;
        }
        $this->postJson($url, $body)->assertStatus($code);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_qr_and_member_foundation_enforce_identity_window_binding_tamper_and_expiry(): void
    {
        [$o,$u,$a,$s,$m,$url] = $this->fixture();
        $memberUser = User::factory()->create();
        $m->forceFill(['user_id' => $memberUser->id])->save();
        $token = $this->postJson($url.'/qr')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data.token');
        AttendanceQr::validate($s, $token);
        foreach (['tampered', 'expired', 'wrong_session', 'late', 'early'] as $case) {
            $this->travelTo($s->starts_at->subMinutes(10));
            $target = clone $s;
            $value = $token;
            if ($case === 'tampered') {
                $value = substr($token, 0, -8).'xxxxxxxx';
            }
            if ($case === 'expired') {
                $this->travelTo($s->starts_at->subMinutes(8));
            }
            if ($case === 'wrong_session') {
                $target->public_id = (string) Str::uuid7();
            }
            if ($case === 'late') {
                $this->travelTo($s->starts_at->addMinutes(31));
            }
            if ($case === 'early') {
                $this->travelTo($s->starts_at->subMinutes(31));
            }
            try {
                AttendanceQr::validate($target, $value);
                $this->fail('Invalid QR accepted');
            } catch (AttendanceConflict $e) {
                $this->assertContains($e->reason, ['qr_invalid', 'window']);
            }
        }
        $this->travelTo($s->starts_at->subMinutes(10));
        $record = Attendance::selfCheckIn($memberUser, $o->public_id, $s->public_id, $token);
        $this->assertSame('qr', $record->check_in_method);
        $this->assertSame($record->id, Attendance::selfCheckIn($memberUser, $o->public_id, $s->public_id, $token)->id);
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertStringNotContainsString($token, DB::table('audit_events')->get()->toJson());
    }

    public function test_correct_permission_and_locked_view_revalidation(): void
    {
        [$o,$u,$a,$s,$m,$url] = $this->fixture();
        $a->permissions = array_values(array_diff($a->permissions, ['attendance.correct']));
        $a->save();
        $this->postJson($url.'/correct', ['member_id' => $m->public_id, 'status' => 'present', 'reason' => 'mistake'])->assertForbidden();
        $revoked = false;
        DB::listen(function ($q) use (&$revoked, $a) {
            if (! $revoked && str_contains($q->sql, '"organizations"') && str_contains($q->sql, 'for update')) {
                $revoked = true;
                DB::table('organization_memberships')->where('id', $a->id)->update(['permissions' => json_encode(array_values(array_diff($a->permissions, ['attendance.view'])), JSON_THROW_ON_ERROR)]);
            }
        });
        $this->postJson($url, ['member_id' => $m->public_id, 'status' => 'present'])->assertForbidden();
        $this->assertTrue($revoked);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_postgresql_constraints_and_audit_rollback(): void
    {
        [$o,$u,$a,$s,$m,$url] = $this->fixture();
        $this->postJson($url, ['member_id' => $m->public_id, 'status' => 'present'])->assertOk();
        $record = AttendanceRecord::firstOrFail();
        $other = $this->member($o, 'Other');
        foreach ([[], ['status' => 'unknown'], ['check_in_method' => 'forged'], ['checked_in_at' => null], ['studio_member_id' => $other->id], ['organization_id' => 999999]] as $change) {
            try {
                DB::transaction(function () use ($record, $change) {
                    $row = $record->getAttributes();
                    unset($row['id']);
                    $row['public_id'] = (string) Str::uuid7();
                    if ($change === []) {
                        DB::table('attendance_records')->insert($row);
                    } else {
                        DB::table('attendance_records')->where('id', $record->id)->update($change);
                    }
                });
                $this->fail('Invalid DB write accepted');
            } catch (QueryException $e) {
                $this->assertStringStartsWith('23', (string) $e->getCode());
            }
        }
        DB::statement("CREATE FUNCTION gt008_reject_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'fixture'; END $$");
        DB::statement('CREATE TRIGGER gt008_reject_audit BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION gt008_reject_audit()');
        try {
            $this->postJson($url.'/walk-in', ['member_id' => $other->public_id])->assertStatus(500);
            $this->assertDatabaseCount('reservations', 1);
            $this->assertDatabaseCount('attendance_records', 1);
        } finally {
            DB::statement('DROP TRIGGER gt008_reject_audit ON audit_events');
            DB::statement('DROP FUNCTION gt008_reject_audit()');
        }
    }

    public function test_foreign_manual_reservations_do_not_block_new_permanent_assignment(): void
    {
        [$foreign,,,,,$url] = $this->fixture();
        [$org,$user,$access,$location,$trainer,$group] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $group->public_id);
        $member = $this->member($org);
        $this->postJson($this->base($org)."/groups/$group->public_id/permanent-spots", ['member_id' => $member->public_id, 'effective_from' => '2026-09-21', 'status' => 'active'])->assertCreated();
        $this->assertSame(1, Reservation::where('organization_id', $foreign->id)->count());
    }

    public function test_member_identity_reservation_and_window_boundaries(): void
    {
        [$o,$u,$a,$s,$m,$url] = $this->fixture();
        $memberUser = User::factory()->create();
        $m->forceFill(['user_id' => $memberUser->id])->save();
        foreach ([$s->starts_at->subMinutes(31), $s->starts_at->addMinutes(31)] as $time) {
            $this->travelTo($time);
            try {
                Attendance::selfCheckIn($memberUser, $o->public_id, $s->public_id);
                $this->fail('Outside window accepted');
            } catch (AttendanceConflict $e) {
                $this->assertSame('window', $e->reason);
            }
        }
        $this->travelTo($s->starts_at->subMinutes(30));
        $qr = AttendanceQr::issue($s);
        $this->assertEquals(90, now()->diffInSeconds(CarbonImmutable::parse($qr['expires_at'])));
        $foreignUser = User::factory()->create();
        try {
            Attendance::selfCheckIn($foreignUser, $o->public_id, $s->public_id);
            $this->fail('Unlinked user accepted');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('attendance_records', 0);
        }
        $reservation = Reservation::firstOrFail();
        $reservation->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        try {
            Attendance::selfCheckIn($memberUser, $o->public_id, $s->public_id);
            $this->fail('Cancelled reservation accepted');
        } catch (AttendanceConflict $e) {
            $this->assertSame('reservation_required', $e->reason);
        }
        $reservation->forceFill(['status' => 'active', 'cancelled_at' => null])->save();
        $this->assertSame('member', Attendance::selfCheckIn($memberUser, $o->public_id, $s->public_id)->check_in_method);
        $this->travelTo($s->starts_at->addMinutes(30));
        $this->assertSame('present', Attendance::selfCheckIn($memberUser, $o->public_id, $s->public_id)->status);
    }

    public function test_adoption_and_bounded_roster_today(): void
    {
        [$o,$u,$a,$s,$m,$url] = $this->fixture();
        $a->permissions = array_values(array_diff($a->permissions, ['attendance.manage', 'attendance.correct']));
        $a->save();
        for ($i = 0; $i < 2; $i++) {
            $this->artisan('gt:attendance-access-enable', ['organization' => $o->public_id, 'membership' => $a->public_id, '--reason' => 'Adoption'])->assertSuccessful();
        }
        $this->assertSame(1, DB::table('audit_events')->where('action', 'staff.permissions_changed')->count());
        $this->getJson($url.'?per_page=10000')->assertUnprocessable();
        $this->getJson($this->base($o).'/attendance/today')->assertOk()->assertJsonCount(1, 'data');
    }
}
