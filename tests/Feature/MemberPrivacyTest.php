<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\PlatformNotification;
use App\Models\User;
use App\Support\Notifications\Delivery;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FinanceFixtures;
use Tests\Support\NotificationFixtures;
use Tests\TestCase;

class MemberPrivacyTest extends TestCase
{
    use DatabaseMigrations,FinanceFixtures,NotificationFixtures;

    public function test_own_finance_readonly_with_reversal_and_foreign_ids_hidden(): void
    {
        [$o,$staff,$a,$m,$plan,$base] = $this->financeFixture();
        $u = User::factory()->create();
        $m->forceFill(['user_id' => $u->id])->save();
        $id = $this->assignFinance($base, $m, $plan);
        $pay = $this->postJson("$base/cash-payments", $this->payData($id, 100000))->assertCreated()->json('data.id');
        $this->postJson("$base/cash-payments/$pay/reverse", ['reason' => 'wrong_amount'])->assertOk();
        $self = "/api/v1/member/organizations/$o->public_id";
        $this->actingAs($u);
        $res = $this->getJson("$self/memberships/$id")->assertOk()->assertJsonPath('data.net_paid_minor', 0)->assertJsonPath('data.balance_minor', 150000);
        $this->assertSame(['id', 'plan_name', 'starts_on', 'ends_on', 'status', 'agreed_price_minor', 'currency', 'net_paid_minor', 'balance_minor'], array_keys($res->json('data')));
        $this->getJson("$self/memberships")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("$self/payments")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("$self/payments/$pay")->assertOk()->assertJsonPath('data.status', 'reversed')->assertJsonMissingPath('data.reversal.reason')->assertJsonMissingPath('data.recorded_by')->assertJsonMissingPath('data.notes');
        $this->postJson("$base/cash-payments", $this->payData($id))->assertNotFound();
        $this->postJson("$base/cash-payments/$pay/reverse", ['reason' => 'wrong_amount'])->assertNotFound();
        $other = User::factory()->create();
        $this->notificationMember($o, $other);
        $this->actingAs($other);
        foreach (["memberships/$id", "payments/$pay"] as $path) {
            $this->getJson("$self/$path")->assertNotFound();
        }$this->getJson("$self/payments")->assertJsonCount(0, 'data');
        $this->getJson("$self/memberships")->assertJsonCount(0, 'data');
    }

    public function test_attendance_notifications_and_present_cancellation_are_scoped(): void
    {
        [$o,$owner,$a,$m,$u,$r] = $this->notificationFixture();
        $this->travelTo($r->session->starts_at->subMinutes(10));
        $staff = $this->base($o);
        $self = "/api/v1/member/organizations/$o->public_id";
        $this->postJson("$staff/sessions/{$r->session->public_id}/attendance", ['member_id' => $m->public_id, 'status' => 'present'])->assertOk();
        $id = AttendanceRecord::firstOrFail()->public_id;
        $notification = PlatformNotification::where('studio_member_id', $m->id)->firstOrFail();
        Delivery::run($notification->delivery->id);
        $notice = $notification->public_id;
        $this->actingAs($u)->getJson("$self/attendance/$id")->assertOk()->assertJsonPath('data.status', 'present')->assertJsonMissingPath('data.actor')->assertJsonMissingPath('data.reason');
        $this->getJson("$self/attendance?from=2026-09-22&until=2026-09-23")->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("$self/reservations/$r->public_id/cancel")->assertConflict();
        $this->getJson("$self/sessions/{$r->session->public_id}")->assertJsonPath('data.reservation.can_cancel', false)->assertJsonPath('data.timezone', 'Europe/Skopje');
        $this->getJson("$staff/notifications/$notice")->assertOk();
        $this->postJson("$staff/notifications/$notice/read")->assertOk();
        $other = User::factory()->create();
        $this->notificationMember($o, $other);
        $this->actingAs($other)->getJson("$self/attendance/$id")->assertNotFound();
        $this->getJson("$staff/notifications/$notice")->assertNotFound();
        $this->getJson("$self/attendance")->assertJsonCount(0, 'data');
        $this->postJson("$staff/sessions/{$r->session->public_id}/attendance", ['member_id' => $m->public_id, 'status' => 'present'])->assertNotFound();
    }

    public function test_token_abilities_status_bounds_and_native_logout(): void
    {
        [$o,$owner,$a,$m,$u,$r] = $this->notificationFixture();
        $base = "/api/v1/member/organizations/$o->public_id";
        Sanctum::actingAs($u, ['member.read']);
        $this->getJson("$base/profile")->assertOk();
        $this->patchJson("$base/profile", ['first_name' => 'No'])->assertForbidden();
        $this->postJson("$base/sessions/{$r->session->public_id}/reservations")->assertForbidden();
        $this->postJson('/api/v1/member/tokens/revoke')->assertForbidden();
        foreach (['per_page=101', 'page=10001', 'until=2026-10-01', 'from=bad'] as $q) {
            $this->getJson("$base/sessions?$q")->assertUnprocessable();
        }
        $m->forceFill(['status' => 'inactive'])->save();
        $this->getJson("$base/profile")->assertNotFound();
        $this->getJson('/api/v1/member/organizations')->assertJsonCount(0, 'data');
    }

    public function test_activation_and_reservation_throttles(): void
    {
        [$o,$owner,$a,$m,$u,$r] = $this->notificationFixture();
        $this->withMiddleware();
        $payload = ['email' => 'absent@example.test', 'token' => str_repeat('A', 64), 'password' => 'Password-1234!', 'password_confirmation' => 'Password-1234!'];
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/member-auth/activate', $payload)->assertUnprocessable();
        }$this->postJson('/api/v1/member-auth/activate', $payload)->assertTooManyRequests();
        $this->actingAs($u);
        for ($i = 0; $i < 30; $i++) {
            $this->postJson("/api/v1/member/organizations/$o->public_id/sessions/{$r->session->public_id}/reservations")->assertOk();
        }$this->postJson("/api/v1/member/organizations/$o->public_id/sessions/{$r->session->public_id}/reservations")->assertTooManyRequests();
    }
}
