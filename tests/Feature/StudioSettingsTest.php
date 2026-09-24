<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\StudioMember;
use App\Models\StudioSetting;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\AttendanceQr;
use App\Support\Notifications\Generate;
use App\Support\StaffRole;
use App\Support\StudioSettings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

class StudioSettingsTest extends TestCase
{
    use DatabaseMigrations, SessionFixtures;

    private function fixture(): array
    {
        [$o, $u, , $l, $t, $g] = $this->sessionFixture();
        $base = $this->base($o);
        $id = $this->postJson($base.'/sessions', $this->sessionData($g, $l, $t, ['capacity' => 1]))->assertCreated()->json('data.id');
        $s = TrainingSession::where('public_id', $id)->firstOrFail();
        $memberUser = User::factory()->create();
        $m = new StudioMember;
        $m->forceFill(['organization_id' => $o->id, 'user_id' => $memberUser->id, 'first_name' => 'Policy', 'last_name' => 'Fixture', 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'app'])->save();

        return [$o, $u, $s, $memberUser, $m, $base, "/api/v1/member/organizations/$o->public_id"];
    }

    public function test_defaults_partial_updates_reset_audit_and_tenant_independence(): void
    {
        [$o, $owner] = $this->sessionFixture();
        $url = $this->base($o).'/settings';
        $defaults = array_map(fn ($d) => $d['default'], StudioSettings::catalog());
        $this->getJson($url)->assertOk()->assertJsonPath('data.effective', $defaults);
        $this->assertDatabaseCount('studio_settings', 0);
        $this->patchJson($url, ['self_reservation_enabled' => false, 'reminder_lead_hours' => 12])->assertOk()->assertJsonPath('data.effective.self_reservation_enabled', false);
        $this->patchJson($url, ['reminder_lead_hours' => 6])->assertOk()->assertJsonPath('data.effective.self_reservation_enabled', false);
        $this->patchJson($url, ['reminder_lead_hours' => null])->assertOk()->assertJsonPath('data.effective.reminder_lead_hours', 24)->assertJsonPath('data.overrides.reminder_lead_hours', null);
        $this->assertSame(3, DB::table('audit_events')->where('action', 'studio.settings_changed')->count());
        $audit = DB::table('audit_events')->where('action', 'studio.settings_changed')->orderByDesc('id')->first();
        $this->assertEquals(['before' => ['reminder_lead_hours' => 6], 'after' => ['reminder_lead_hours' => null], 'effective_before' => ['reminder_lead_hours' => 6], 'effective_after' => ['reminder_lead_hours' => 24]], json_decode($audit->metadata, true));
        $this->patchJson($url, ['reminder_lead_hours' => null])->assertOk();
        $this->assertSame(3, DB::table('audit_events')->where('action', 'studio.settings_changed')->count());
        $foreign = Organization::create(['name' => 'Other']);
        $this->assertSame($defaults, StudioSettings::forOrganization($foreign)->values());
        $this->getJson($this->base($foreign).'/settings')->assertNotFound();
        $this->patchJson($this->base($foreign).'/settings', ['self_reservation_enabled' => true])->assertNotFound();
    }

    public static function invalid(): array
    {
        $cases = [['reminder_lead_hours', ''], ['self_check_in_enabled', ''], ['organization_id', 1], ['permissions', ['organizations.manage']], ['public_id', 'forged'], ['qr_secret', 'forged'], ['reminder_lead_hours', '24'], ['self_reservation_enabled', 1], ['self_reservation_enabled', 'false'], ['qr_lifetime_seconds', 90.5], ['reminder_lead_hours', []]];
        foreach (StudioSettings::catalog() as $key => $d) {
            if ($d['type'] === 'integer') {
                $cases[] = [$key, $d['min'] - 1];
                $cases[] = [$key, $d['max'] + 1];
            }
        }

        return $cases;
    }

    #[DataProvider('invalid')]
    public function test_strict_validation(string $key, mixed $value): void
    {
        [$o] = $this->sessionFixture();
        $this->patchJson($this->base($o).'/settings', [$key => $value])->assertUnprocessable();
        $this->assertDatabaseCount('studio_settings', 0);
    }

    public function test_permission_revocation_member_access_and_qr_relational_bounds(): void
    {
        [$o, $owner, , $user, , $base, $member] = $this->fixture();
        $this->patchJson($base.'/settings', ['qr_lifetime_seconds' => 30])->assertUnprocessable();
        $this->patchJson($base.'/settings', ['qr_lifetime_seconds' => 30, 'qr_rotation_seconds' => 10])->assertOk();
        $this->patchJson($base.'/settings', ['qr_rotation_seconds' => null])->assertUnprocessable();
        Sanctum::actingAs($user, ['member.read', 'member.write']);
        $this->getJson($member.'/settings')->assertOk()->assertJsonMissingPath('data.qr_lifetime_seconds')->assertJsonMissingPath('data.organization_id');
        $this->patchJson($base.'/settings', ['self_check_in_enabled' => false])->assertNotFound();
        $this->patchJson($member.'/settings', ['self_check_in_enabled' => false])->assertStatus(405);
        $trainer = User::factory()->create();
        $this->membership($trainer, $o, StaffRole::Trainer);
        Sanctum::actingAs($trainer, ['*']);
        $this->getJson($base.'/settings')->assertOk();
        $this->patchJson($base.'/settings', ['self_check_in_enabled' => false])->assertForbidden();
    }

    public function test_booking_cutoff_horizon_disabled_and_cancellation_boundaries(): void
    {
        [$o, $owner, $s, $user, , $base, $member] = $this->fixture();
        $this->patchJson($base.'/settings', ['reservation_horizon_days' => 1])->assertOk();
        Sanctum::actingAs($user, ['member.read', 'member.write']);
        $this->postJson("$member/sessions/$s->public_id/reservations", [])->assertConflict();
        Sanctum::actingAs($owner, ['*']);
        $this->patchJson($base.'/settings', ['reservation_horizon_days' => 0, 'reservation_cutoff_minutes' => 60])->assertOk();
        $this->travelTo($s->starts_at->subHour());
        Sanctum::actingAs($user, ['member.read', 'member.write']);
        $this->postJson("$member/sessions/$s->public_id/reservations", [])->assertConflict();
        $this->travel(-1)->seconds();
        $id = $this->postJson("$member/sessions/$s->public_id/reservations", [])->assertOk()->json('data.reservation_id');
        Sanctum::actingAs($owner, ['*']);
        $this->patchJson($base.'/settings', ['self_reservation_enabled' => false, 'cancellation_cutoff_minutes' => 60])->assertOk();
        $this->travel(1)->seconds();
        Sanctum::actingAs($user, ['member.read', 'member.write']);
        $this->getJson("$member/sessions/$s->public_id")->assertOk()->assertJsonPath('data.can_reserve', false)->assertJsonPath('data.reservation.can_cancel', false);
        $this->postJson("$member/reservations/$id/cancel", [])->assertConflict();
        $this->travel(-1)->seconds();
        $this->postJson("$member/reservations/$id/cancel", [])->assertOk();
        $this->postJson("$member/sessions/$s->public_id/reservations", [])->assertConflict()->assertJsonPath('code', 'policy');
    }

    public function test_waiting_disable_preserves_fifo_and_member_cancellation_toggle(): void
    {
        [$o, $owner, $s, $user, $m, $base, $member] = $this->fixture();
        $other = new StudioMember;
        $other->forceFill(['organization_id' => $o->id, 'first_name' => 'Waiting', 'last_name' => 'Fixture', 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'offline'])->save();
        $first = $this->postJson("$base/sessions/$s->public_id/reservations", ['member_id' => $other->public_id])->assertOk()->json('data.reservation.id');
        $this->patchJson($base.'/settings', ['waiting_list_enabled' => false])->assertOk();
        Sanctum::actingAs($user, ['member.read', 'member.write']);
        $this->postJson("$member/sessions/$s->public_id/reservations", ['allow_waiting' => true])->assertConflict();
        Sanctum::actingAs($owner, ['*']);
        $this->patchJson($base.'/settings', ['waiting_list_enabled' => true])->assertOk();
        Sanctum::actingAs($user, ['member.read', 'member.write']);
        $waiting = $this->postJson("$member/sessions/$s->public_id/reservations", ['allow_waiting' => true])->assertOk()->json('data.waiting_entry_id');
        Sanctum::actingAs($owner, ['*']);
        $this->patchJson($base.'/settings', ['waiting_list_enabled' => false, 'self_cancellation_enabled' => false])->assertOk();
        Sanctum::actingAs($user, ['member.read', 'member.write']);
        $this->getJson($member.'/waiting-list')->assertOk()->assertJsonPath('data.0.can_cancel', false);
        $this->postJson("$member/waiting-list/$waiting/cancel", [])->assertConflict();
        Sanctum::actingAs($owner, ['*']);
        $this->postJson("$base/reservations/$first/cancel", [])->assertOk();
        $this->assertDatabaseHas('reservations', ['studio_member_id' => $m->id, 'status' => 'active']);
        $this->assertDatabaseHas('waiting_list_entries', ['public_id' => $waiting, 'status' => 'promoted']);
    }

    public function test_qr_policy_change_rechecks_current_window_and_manual_fallback(): void
    {
        [$o, $owner, $s, $user, $m, $base, $member] = $this->fixture();
        $this->postJson("$base/sessions/$s->public_id/reservations", ['member_id' => $m->public_id])->assertOk();
        $this->travelTo($s->starts_at->subMinutes(10));
        $qr = AttendanceQr::issue($s);
        $this->patchJson($base.'/settings', ['check_in_early_minutes' => 5])->assertOk();
        Sanctum::actingAs($user, ['member.read', 'member.write']);
        $this->postJson("$member/sessions/$s->public_id/check-in", ['credential' => $qr['token']])->assertConflict()->assertJsonPath('code', 'too_early');
        Sanctum::actingAs($owner, ['*']);
        $this->patchJson($base.'/settings', ['check_in_early_minutes' => null, 'self_check_in_enabled' => false])->assertOk();
        Sanctum::actingAs($user, ['member.read', 'member.write']);
        $this->postJson("$member/sessions/$s->public_id/check-in", ['credential' => $qr['token']])->assertConflict()->assertJsonPath('code', 'policy');
        Sanctum::actingAs($owner, ['*']);
        $this->postJson("$base/sessions/$s->public_id/attendance", ['member_id' => $m->public_id, 'status' => 'present'])->assertOk();
        $this->assertDatabaseCount('attendance_records', 1);
        $this->patchJson($base.'/settings', ['self_check_in_enabled' => true, 'qr_lifetime_seconds' => 40, 'qr_rotation_seconds' => 20])->assertOk();
        $issued = AttendanceQr::issue($s);
        $this->assertSame(20, $issued['refresh_after']);
        $this->assertSame(40, (int) now()->diffInSeconds($issued['expires_at']));
        AttendanceQr::validate($s, $qr['token'], true); // Previously issued 90s token remains valid within current window.
        $this->patchJson($base.'/settings', ['check_in_late_minutes' => 5])->assertOk();
        $this->travelTo($s->starts_at->addMinutes(5));
        Sanctum::actingAs($user, ['member.read', 'member.write']);
        $this->postJson("$member/sessions/$s->public_id/check-in", ['credential' => $issued['token']])->assertConflict()->assertJsonPath('code', 'too_late');
    }

    public function test_reminder_lead_change_disable_and_idempotency(): void
    {
        [$o, , $s, , $m, $base] = $this->fixture();
        $this->postJson("$base/sessions/$s->public_id/reservations", ['member_id' => $m->public_id])->assertOk();
        $this->travelTo($s->starts_at->subHours(12));
        $this->assertSame(0, Generate::run($o->public_id)['created']);
        $this->patchJson($base.'/settings', ['reminder_lead_hours' => 12, 'session_reminders_enabled' => false])->assertOk();
        $this->assertSame(0, Generate::run($o->public_id)['created']);
        $this->patchJson($base.'/settings', ['session_reminders_enabled' => true])->assertOk();
        $this->assertSame(1, Generate::run($o->public_id)['created']);
        $this->patchJson($base.'/settings', ['reminder_lead_hours' => 13])->assertOk();
        $this->assertSame(0, Generate::run($o->public_id)['created']);
        $this->assertSame(1, DB::table('platform_notifications')->where('type', 'session.reminder')->count());
    }

    public function test_locale_precedence_safe_timezone_and_unsafe_timezone(): void
    {
        [$o, $owner, , $user, , $base, $member] = $this->fixture();
        $this->patchJson($base, ['locale' => 'en'])->assertOk();
        Sanctum::actingAs($user, ['member.read', 'member.write']);
        $this->getJson($member.'/settings')->assertOk()->assertJsonPath('data.locale', 'mk');
        Sanctum::actingAs($owner, ['*']);
        $this->patchJson($base, ['timezone' => 'America/New_York'])->assertConflict();
        $this->patchJson($base, ['timezone' => '../../Europe/Skopje'])->assertUnprocessable();
        $fresh = Organization::create(['name' => 'Fresh']);
        $this->membership($owner, $fresh);
        $this->patchJson($this->base($fresh), ['timezone' => 'America/New_York', 'locale' => 'en'])->assertOk();
        $this->postJson($this->base($fresh).'/members', ['first_name' => 'New', 'last_name' => 'Member'])->assertCreated()->assertJsonPath('data.preferred_locale', 'en');
    }

    public function test_request_shape_size_and_unique_tenant_row(): void
    {
        [$o] = $this->sessionFixture();
        $url = $this->base($o).'/settings';
        $this->patchJson($url, ['unknown' => str_repeat('x', 4097)])->assertStatus(413);
        $this->call('PATCH', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '[]')->assertUnprocessable();
        $this->patchJson($url, ['reservation_horizon_days' => 56])->assertOk();
        $duplicate = new StudioSetting;
        $this->expectException(QueryException::class);
        $duplicate->forceFill(['organization_id' => $o->id])->save();
    }

    public static function databaseViolations(): array
    {
        return [['reminder_lead_hours', 0], ['qr_lifetime_seconds', 91], ['qr_rotation_seconds', 31], ['check_in_early_minutes', -1], ['qr_lifetime_seconds', 30], ['organization_id', 999999]];
    }

    #[DataProvider('databaseViolations')]
    public function test_postgresql_rejects_invalid_settings(string $column, int $value): void
    {
        [$o] = $this->sessionFixture();
        $row = new StudioSetting;
        $row->forceFill(['organization_id' => $o->id])->save();
        $this->expectException(QueryException::class);
        DB::table('studio_settings')->where('id', $row->id)->update([$column => $value]);
    }
}
