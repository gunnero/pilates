<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Reservation;
use App\Models\StudioMember;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\AttendanceQr;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

class MemberCheckInTest extends TestCase
{
    use DatabaseMigrations, SessionFixtures;

    private function fixture(): array
    {
        [$o, $owner, , $l, $t, $g] = $this->sessionFixture();
        $id = $this->postJson($this->base($o).'/sessions', $this->sessionData($g, $l, $t))->assertCreated()->json('data.id');
        $s = TrainingSession::where('public_id', $id)->firstOrFail();
        $u = User::factory()->create();
        $m = new StudioMember;
        $m->forceFill(['organization_id' => $o->id, 'user_id' => $u->id, 'first_name' => 'QR', 'last_name' => 'Fixture', 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'app'])->save();
        $this->postJson($this->base($o)."/sessions/$id/reservations", ['member_id' => $m->public_id])->assertOk();
        $this->travelTo($s->starts_at->subMinutes(10));
        $qr = AttendanceQr::issue($s);
        Sanctum::actingAs($u, ['member.read', 'member.write']);

        return [$o, $owner, $s, $u, $m, "/api/v1/member/organizations/$o->public_id/sessions/$id/check-in", $qr];
    }

    public function test_authoritative_idempotent_check_in_and_private_history(): void
    {
        [$o, $owner, $s, , $m, $url, $qr] = $this->fixture();
        $envelope = json_decode(substr($qr['payload'], 6), true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame($o->public_id, $envelope['organization']);
        $this->assertSame($s->public_id, $envelope['session']);
        $this->assertSame($qr['token'], $envelope['credential']);
        $this->assertSame(30, $qr['refresh_after']);
        $this->postJson($url, ['credential' => $qr['token']])->assertOk()->assertJsonPath('data.outcome', 'checked_in')->assertJsonPath('data.attendance.method', 'qr')->assertJsonMissingPath('data.attendance.credential');
        $this->postJson($url, ['credential' => $qr['token']])->assertOk()->assertJsonPath('data.outcome', 'already_present');
        $this->travel(30)->seconds();
        $rotated = AttendanceQr::issue($s);
        $this->assertNotSame($qr['token'], $rotated['token']);
        $this->postJson($url, ['credential' => $rotated['token']])->assertOk()->assertJsonPath('data.outcome', 'already_present');
        $this->getJson("/api/v1/member/organizations/$o->public_id/attendance")->assertOk()->assertJsonPath('data.0.method', 'qr')->assertJsonMissingPath('data.0.user_id');
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'attendance.marked')->count());
        $this->assertStringNotContainsString($qr['token'], DB::table('audit_events')->get()->toJson());
        Sanctum::actingAs($owner, ['*']);
        $this->postJson($this->base($o)."/sessions/$s->public_id/attendance/correct", ['member_id' => $m->public_id, 'status' => 'present', 'reason' => 'late_confirmation'])->assertOk();
        $this->artisan('gt:attendance:check')->assertSuccessful();
    }

    public static function invalidCredentials(): array
    {
        return array_map(fn ($case) => [$case], ['forged', 'modified', 'expired', 'version', 'future', 'long_lifetime', 'wrong_session', 'wrong_org', 'oversized', 'url', 'missing', 'actor']);
    }

    #[DataProvider('invalidCredentials')]
    public function test_untrusted_credentials_never_write(string $case): void
    {
        [, , $s, , , $url, $qr] = $this->fixture();
        $credential = $qr['token'];
        $payload = json_decode(Crypt::decryptString($credential), true, 8, JSON_THROW_ON_ERROR);
        if ($case === 'forged') {
            $credential = base64_encode('untrusted');
        }
        if ($case === 'modified') {
            $credential[10] = $credential[10] === 'A' ? 'B' : 'A';
        }
        if ($case === 'expired') {
            $this->travel(90)->seconds();
        }
        if ($case === 'version') {
            $payload['v'] = 2;
        }
        if ($case === 'future') {
            $payload['issued'] += 1;
            $payload['expires'] += 1;
        }
        if ($case === 'long_lifetime') {
            $payload['expires'] += 1;
        }
        if ($case === 'wrong_session') {
            $payload['session'] = (string) Str::uuid7();
        }
        if ($case === 'wrong_org') {
            $payload['organization'] = $s->organization_id + 999;
        }
        if (in_array($case, ['version', 'future', 'long_lifetime', 'wrong_session', 'wrong_org'], true)) {
            $credential = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        }
        if ($case === 'oversized') {
            $credential = str_repeat('A', 2049);
        }
        if ($case === 'url') {
            $credential = 'https://attacker.invalid';
        }
        $body = $case === 'missing' ? [] : ['credential' => $credential];
        if ($case === 'actor') {
            $body['member_id'] = 'forged';
        }
        $response = $this->postJson($url, $body);
        $response->assertStatus(in_array($case, ['oversized', 'url', 'missing', 'actor'], true) ? 422 : 409);
        if ($case === 'expired') {
            $response->assertJsonPath('code', 'qr_expired');
        }
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_current_link_authority_reservation_and_operational_state_are_required(): void
    {
        [$o, $owner, $s, $u, $m, $url, $qr] = $this->fixture();
        Sanctum::actingAs($u, ['member.read']);
        $this->postJson($url, ['credential' => $qr['token']])->assertForbidden();
        Sanctum::actingAs($u, ['member.write']);
        $m->status = 'inactive';
        $m->save();
        $this->postJson($url, ['credential' => $qr['token']])->assertNotFound();
        $m->status = 'active';
        Sanctum::actingAs(User::factory()->create(), ['member.write']);
        $m->save();
        $this->postJson($url, ['credential' => $qr['token']])->assertNotFound();
        Sanctum::actingAs($u, ['member.write']);
        $m->save();
        Sanctum::actingAs($owner, ['*']);
        $r = Reservation::firstOrFail();
        $this->postJson($this->base($o)."/reservations/$r->public_id/cancel")->assertOk();
        Sanctum::actingAs($u, ['member.write']);
        $this->postJson($url, ['credential' => $qr['token']])->assertConflict()->assertJsonPath('code', 'reservation_required');
        Sanctum::actingAs($owner, ['*']);
        $this->postJson($this->base($o)."/sessions/$s->public_id/cancel")->assertOk();
        Sanctum::actingAs($u, ['member.write']);
        $this->postJson($url, ['credential' => $qr['token']])->assertConflict()->assertJsonPath('code', 'cancelled');
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public static function boundaries(): array
    {
        return [[-1801, 'too_early'], [-1800, null], [0, null], [1799, null], [1800, 'too_late'], [1801, 'too_late']];
    }

    #[DataProvider('boundaries')]
    public function test_server_time_window_is_half_open(int $offset, ?string $reason): void
    {
        [, , $s, , , $url] = $this->fixture();
        $this->travelTo($s->starts_at->addSeconds(max(-1800, min(1799, $offset))));
        $qr = AttendanceQr::issue($s);
        $this->travelTo($s->starts_at->addSeconds($offset));
        $response = $this->postJson($url, ['credential' => $qr['token']]);
        if ($reason === null) {
            $response->assertOk();
        } else {
            $response->assertConflict()->assertJsonPath('code', $reason);
        }
    }

    public function test_member_rate_limit_is_bounded(): void
    {
        [, , , , , $url] = $this->fixture();
        for ($i = 0; $i < 20; $i++) {
            $this->postJson($url, ['credential' => 'bad'])->assertConflict();
        }
        $this->postJson($url, ['credential' => 'bad'])->assertTooManyRequests();
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_waiting_list_and_staff_only_identity_cannot_self_check_in(): void
    {
        [$o, $owner, $s, $u, , $url, $qr] = $this->fixture();
        Sanctum::actingAs($owner, ['*']);
        $this->postJson($url, ['credential' => $qr['token']])->assertNotFound();
        $s->capacity = 1;
        $s->save();
        $waitingUser = User::factory()->create();
        $m = new StudioMember;
        $m->forceFill(['organization_id' => $o->id, 'user_id' => $waitingUser->id, 'first_name' => 'Waiting', 'last_name' => 'Fixture', 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'app'])->save();
        $this->postJson($this->base($o)."/sessions/$s->public_id/reservations", ['member_id' => $m->public_id, 'allow_waiting' => true])->assertOk();
        Sanctum::actingAs($waitingUser, ['member.write']);
        $this->postJson($url, ['credential' => $qr['token']])->assertConflict()->assertJsonPath('code', 'waiting');
        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertSame(1, Reservation::where('status', 'active')->count());
        $this->assertDatabaseHas('waiting_list_entries', ['studio_member_id' => $m->id, 'status' => 'waiting']);
        $this->postJson($this->base($o)."/sessions/$s->public_id/attendance/qr")->assertNotFound();
        Sanctum::actingAs($u, ['member.write']);
        $foreign = Organization::create(['name' => 'Foreign studio']);
        $this->postJson(str_replace($o->public_id, $foreign->public_id, $url), ['credential' => $qr['token']])->assertNotFound();
    }

    public static function zonedInstants(): array
    {
        return [['2026-03-29T00:45:00Z', '01:45'], ['2026-03-29T01:15:00Z', '03:15'], ['2026-10-25T00:30:00Z', '02:30'], ['2026-10-25T01:30:00Z', '02:30']];
    }

    #[DataProvider('zonedInstants')]
    public function test_dst_and_different_server_timezone_use_absolute_instants(string $instant, string $wallTime): void
    {
        [, , $s, , , $url] = $this->fixture();
        $start = CarbonImmutable::parse($instant);
        $s->local_date = $start->setTimezone('Europe/Skopje')->toDateString();
        $s->starts_at = $start;
        $s->ends_at = $start->addHours(2);
        $s->save();
        $s->refresh();
        $this->assertSame($wallTime, $start->setTimezone('Europe/Skopje')->format('H:i'));
        $original = getenv('TZ');
        try {
            putenv('TZ=America/New_York');
            $this->assertSame('UTC', date_default_timezone_get());
            $this->travelTo($start->subMinutes(30)->setTimezone('America/New_York'));
            $qr = AttendanceQr::issue($s);
            $this->postJson($url, ['credential' => $qr['token']])->assertOk();
            $this->travel(90)->seconds();
            $this->postJson($url, ['credential' => $qr['token']])->assertConflict()->assertJsonPath('code', 'qr_expired');
        } finally {
            putenv($original === false ? 'TZ' : 'TZ='.$original);
        }
    }
}
