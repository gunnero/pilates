<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

final class OperationsAccountTest extends TestCase
{
    use DatabaseMigrations, SessionFixtures;

    public function test_staff_token_is_explicit_scoped_revocable_and_separate_from_members(): void
    {
        [$o, $u, $a] = $this->sessionFixture();
        $u->forceFill(['password' => 'Staff-fixture-123!'])->save();
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/staff-auth/token', ['email' => $u->email, 'password' => 'Staff-fixture-123!', 'device_name' => 'Test native staff'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data.token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/staff/account')->assertOk()->assertJsonPath('data.organizations.0.id', $o->public_id);
        $this->getJson($this->base($o).'/operations')->assertOk();
        $this->getJson('/api/v1/member/account')->assertForbidden();
        $this->assertNotContains('*', $u->tokens()->sole()->abilities);
        $this->assertNotContains('payments.record', $u->tokens()->sole()->abilities);
        $a->forceFill(['role' => 'staff', 'permissions' => ['studio.access', 'sessions.view']])->save();
        $this->getJson($this->base($o).'/operations')->assertForbidden();
        $this->postJson('/api/v1/member/token/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/staff/account')->assertUnauthorized();
    }

    public function test_customer_or_wrong_password_cannot_obtain_staff_credentials(): void
    {
        $u = User::factory()->create(['password' => 'Staff-fixture-123!']);
        $this->postJson('/api/v1/staff-auth/token', ['email' => $u->email, 'password' => 'Staff-fixture-123!', 'device_name' => 'Test'])->assertUnprocessable();
        [$o, $staff] = $this->sessionFixture();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/staff-auth/token', ['email' => $staff->email, 'password' => 'Wrong-fixture-123!', 'device_name' => 'Test'])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_member_token_does_not_gain_staff_access_even_for_dual_role_user(): void
    {
        [$o, $u] = $this->sessionFixture();
        $token = $u->createToken('Member', ['member.read', 'member.write', 'account.security'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/staff/account')->assertForbidden();
        $this->getJson($this->base($o).'/operations')->assertForbidden();
    }
}
