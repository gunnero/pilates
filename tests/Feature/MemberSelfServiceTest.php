<?php

namespace Tests\Feature;

use App\Contracts\Accounts\InvitationDelivery;
use App\Models\MemberInvitation;
use App\Models\Organization;
use App\Models\StudioMember;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\NotificationFixtures;
use Tests\TestCase;

class MemberSelfServiceTest extends TestCase
{
    use DatabaseMigrations,NotificationFixtures;

    private function capture(): object
    {
        $delivery = new class implements InvitationDelivery
        {
            public string $url = '';

            public function send(string $email, string $url, string $locale): void
            {
                $this->url = $url;
            }
        };
        $this->app->instance(InvitationDelivery::class, $delivery);

        return $delivery;
    }

    private function activation(object $capture, string $password = 'Strong-password-123!'): array
    {
        parse_str(parse_url($capture->url, PHP_URL_FRAGMENT), $data);

        return $data + ['password' => $password, 'password_confirmation' => $password];
    }

    public function test_invite_activation_preserves_member_and_cannot_take_over_existing_account(): void
    {
        [$o,$owner,$a,$m,$recipient,$r] = $this->notificationFixture();
        $new = $this->notificationMember($o);
        $capture = $this->capture();
        $base = $this->base($o);
        $this->postJson("$base/members/$new->public_id/invitations", ['email' => ' NEW@example.test '])->assertCreated()->assertJsonMissingPath('data.token');
        $i = MemberInvitation::firstOrFail();
        $data = $this->activation($capture);
        $this->assertNotSame($data['token'], $i->token_hash);
        $this->postJson('/auth/activate', $data)->assertOk();
        $linked = $new->fresh();
        $this->assertNotNull($linked->user_id);
        $this->assertSame(2, StudioMember::count());
        $this->assertDatabaseHas('member_invitations', ['status' => 'accepted', 'accepted_user_id' => $linked->user_id]);
        $this->postJson('/auth/activate', $data)->assertUnprocessable();
        $this->assertDatabaseHas('audit_events', ['action' => 'member.account_linked']);
        $other = Organization::create(['name' => 'Second studio']);
        $this->membership($owner, $other);
        $next = $this->notificationMember($other);
        $this->postJson($this->base($other)."/members/$next->public_id/invitations", ['email' => 'new@example.test'])->assertCreated();
        $this->postJson('/auth/activate', $this->activation($capture, 'Wrong-password-123!'))->assertUnprocessable();
        $this->postJson('/auth/activate', $this->activation($capture))->assertOk();
        $this->assertSame($linked->user_id, $next->fresh()->user_id);
        $u = User::findOrFail($linked->user_id);
        $this->actingAs($u)->getJson('/api/v1/member/organizations')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/v1/member/organizations/$other->public_id/switch")->assertOk();
    }

    public function test_self_service_ownership_profile_discovery_and_booking_engine(): void
    {
        [$o,$owner,$a,$m,$u,$r] = $this->notificationFixture(1);
        $base = "/api/v1/member/organizations/$o->public_id";
        $this->actingAs($u)->getJson("$base/profile")->assertOk()->assertJsonPath('data.id', $m->public_id)->assertJsonMissingPath('data.notes');
        $this->patchJson("$base/profile", ['first_name' => 'Changed', 'preferred_locale' => 'en'])->assertOk();
        foreach (['user_id', 'organization_id', 'notes', 'status', 'permissions'] as $key) {
            $this->patchJson("$base/profile", [$key => 'forged'])->assertUnprocessable();
        }
        $this->getJson("$base/groups")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("$base/sessions")->assertOk()->assertJsonPath('data.0.available_spots', 0)->assertJsonPath('data.0.reservation.id', $r->public_id);
        $this->getJson("$base/sessions?from=2026-01-01&until=2026-12-01")->assertUnprocessable();
        $this->postJson("$base/sessions/{$r->session->public_id}/reservations", ['member_id' => $m->public_id])->assertUnprocessable();
        $this->postJson("$base/sessions/{$r->session->public_id}/reservations")->assertOk()->assertJsonPath('data.outcome', 'already_reserved');
        $otherUser = User::factory()->create();
        $this->notificationMember($o, $otherUser);
        $this->actingAs($otherUser)->getJson("$base/reservations/$r->public_id")->assertNotFound();
        $this->postJson("$base/reservations/$r->public_id/cancel")->assertNotFound();
        $this->postJson("$base/sessions/{$r->session->public_id}/reservations", ['allow_waiting' => true])->assertOk()->assertJsonPath('data.outcome', 'waitlisted');
        $this->getJson("$base/waiting-list")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($u)->postJson("$base/reservations/$r->public_id/cancel")->assertOk();
        $this->actingAs($otherUser)->getJson("$base/sessions?relationship=reserved")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reservation.source', 'waiting_list');
        $this->getJson($this->base($o).'/members')->assertNotFound();
        $foreign = Organization::create(['name' => 'Foreign']);
        $this->getJson("/api/v1/member/organizations/$foreign->public_id/profile")->assertNotFound();
    }

    public function test_invitation_expiry_revocation_and_permissions(): void
    {
        [$o,$owner,$a,$m,$u,$r] = $this->notificationFixture();
        $new = $this->notificationMember($o);
        $capture = $this->capture();
        $url = $this->base($o)."/members/$new->public_id/invitations";
        $this->actingAs($u)->postJson($url, ['email' => 'new@example.test'])->assertNotFound();
        $this->actingAs($owner)->postJson($url, ['email' => 'new@example.test'])->assertCreated();
        $old = $this->activation($capture);
        $this->postJson($url, ['email' => 'new@example.test'])->assertCreated();
        $this->postJson('/auth/activate', $old)->assertUnprocessable();
        $data = $this->activation($capture);
        $this->travel(25)->hours();
        $this->postJson('/auth/activate', $data)->assertUnprocessable();
        $this->assertNull($new->fresh()->user_id);
    }

    public function test_password_change_and_mobile_tokens_revoke_credentials(): void
    {
        [$o,$owner,$a,$m,$u,$r] = $this->notificationFixture();
        $u->password = 'Current-password-123!';
        $u->save();
        $token = $this->postJson('/api/v1/member-auth/token', ['email' => $u->email, 'password' => 'Current-password-123!', 'device_name' => 'Test device'])->assertOk()->json('data.token');
        $this->assertSame(1, $u->tokens()->count());
        $this->assertStringNotContainsString($token, DB::table('personal_access_tokens')->get()->toJson());
        $this->actingAs($u)->postJson('/api/v1/member/password', ['current_password' => 'Current-password-123!', 'password' => 'Changed-password-123!', 'password_confirmation' => 'Changed-password-123!'])->assertOk();
        $this->assertSame(0, $u->tokens()->count());
        $this->assertTrue(Hash::check('Changed-password-123!', $u->fresh()->password));
        $this->assertDatabaseHas('account_security_events', ['action' => 'password_changed']);
    }

    public function test_native_bearer_current_logout_expiry_and_session_revocation(): void
    {
        [$o,$owner,$a,$m,$u,$r] = $this->notificationFixture();
        $u->password = 'Current-password-123!';
        $u->save();
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/member-auth/token', ['email' => $u->email, 'password' => 'Current-password-123!', 'device_name' => 'Native fixture'])->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/member/account')->assertOk()->assertJsonPath('data.id', $u->public_id);
        $this->withToken($token)->getJson($this->base($o).'/context')->assertNotFound();
        $this->postJson('/api/v1/member/token/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/member/account')->assertUnauthorized();
        $expired = $u->createToken('Expired', ['member.read'], now()->subMinute())->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($expired)->getJson('/api/v1/member/account')->assertUnauthorized();
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $u->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->actingAs($u)->postJson('/api/v1/member/password', ['current_password' => 'Current-password-123!', 'password' => 'Changed-password-123!', 'password_confirmation' => 'Changed-password-123!'])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
    }
}
