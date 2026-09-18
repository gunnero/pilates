<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Permission;
use App\Support\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Accept-Language', 'mk');
    }

    private function grant(User $user, Organization $organization, array $permissions = ['studio.access']): void
    {
        // Bootstrap fixtures use a trusted database write, not a public permission-management API.
        DB::table('organization_memberships')->insert(['organization_id' => $organization->id, 'user_id' => $user->id, 'permissions' => json_encode($permissions), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_application_boots_with_security_headers_and_postgresql(): void
    {
        $this->get('/up')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY');
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('UTC', DB::selectOne('SHOW TIMEZONE')->TimeZone);
    }

    public function test_api_requires_authentication_and_returns_localized_bounded_errors(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('message', 'Најавете се.')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/me', ['Accept-Language' => 'en'])->assertUnauthorized()->assertJsonPath('message', 'Please sign in.');
        $this->getJson('/api/v1/missing')->assertNotFound()->assertExactJson(['message' => 'Не е пронајдено.']);
    }

    public function test_login_canonicalizes_email_hashes_password_and_rotates_session(): void
    {
        $user = User::factory()->create(['email' => ' OWNER@EXAMPLE.TEST ', 'password' => 'valid-password']);
        $this->assertSame('owner@example.test', $user->email);
        $this->assertNotSame('valid-password', $user->password);
        $this->assertTrue(Hash::check('valid-password', $user->password));
        $this->withSession(['existing' => 'value']);
        $session = session()->getId();
        $this->postJson('/auth/login', ['email' => ' Owner@Example.Test ', 'password' => 'valid-password'])->assertNoContent();
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($session, session()->getId());
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->public_id)->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.email');
        $session = session()->getId();
        $this->postJson('/auth/logout')->assertNoContent();
        $this->assertGuest('web');
        $this->app['auth']->forgetGuards();
        $this->assertNotSame($session, session()->getId());
        $this->assertNull(session('existing'));
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_validation_is_localized_and_login_failures_are_generic_and_throttled(): void
    {
        $this->postJson('/auth/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/auth/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertUnprocessable()->assertJsonPath('errors.email.0', 'Податоците за најава не се точни.');
        }
        $this->postJson('/auth/login', ['email' => ' UNKNOWN@EXAMPLE.TEST ', 'password' => 'wrong'])->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_organization_context_requires_membership_and_explicit_permission(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $a = Organization::create(['name' => 'Studio A']);
        $b = Organization::create(['name' => 'Studio B']);
        $this->grant($alice, $a);
        $this->grant($bob, $b);
        $this->actingAs($alice)->getJson('/api/v1/organizations/'.$a->public_id.'/context')->assertOk()->assertJsonPath('data.id', $a->public_id)->assertJsonPath('data.currency', 'MKD');
        $this->getJson('/api/v1/organizations/'.$b->public_id.'/context')->assertNotFound();
        $this->getJson('/api/v1/organizations/'.Str::uuid7().'/context')->assertNotFound();
        $this->getJson('/api/v1/organizations/'.$a->id.'/context')->assertNotFound();
        $this->getJson('/api/v1/me')->assertJsonCount(1, 'data.organizations')->assertJsonMissing(['name' => 'Studio B']);
        $this->assertFalse(Gate::forUser($alice)->allows('studio.access')); // request context was cleared
        $this->grant($bob, $a, []);
        $this->actingAs($bob)->getJson('/api/v1/organizations/'.$a->public_id.'/context')->assertForbidden();
        DB::table('organization_memberships')->where('user_id', $bob->id)->where('organization_id', $b->id)->delete();
        $this->getJson('/api/v1/organizations/'.$b->public_id.'/context')->assertNotFound();
    }

    public function test_financial_capabilities_are_independent_and_fail_closed(): void
    {
        $trainer = User::factory()->create();
        $other = User::factory()->create();
        $organization = Organization::create(['name' => 'Studio']);
        $this->grant($trainer, $organization, ['attendance.view', 'payments.view', 'payments.record']);
        $context = app(TenantContext::class);
        $this->assertFalse(Gate::forUser($trainer)->allows('payments.view'));
        $context->resolve($trainer, $organization->public_id);
        $this->assertTrue(Gate::forUser($trainer)->allows('attendance.view'));
        $this->assertTrue(Gate::forUser($trainer)->allows('payments.view'));
        $this->assertTrue(Gate::forUser($trainer)->allows('payments.record'));
        $this->assertFalse(Gate::forUser($trainer)->allows('payments.correct'));
        $this->assertFalse(Gate::forUser($trainer)->allows('reports.view'));
        $this->assertFalse(Gate::forUser($other)->allows('payments.view'));
        $this->assertFalse(Gate::forUser($trainer)->allows('invented.permission'));
        $context->clear();
        $this->expectException(\LogicException::class);
        $context->organization();
    }

    public function test_sanctum_token_cannot_exceed_permissions_or_cross_tenants(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Studio']);
        $this->grant($user, $organization);
        $token = $user->createToken('test', [])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/organizations/'.$organization->public_id.'/context')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $token = $user->createToken('test', [Permission::StudioAccess->value])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/organizations/'.$organization->public_id.'/context')->assertOk();
        $this->app['auth']->forgetGuards();
        $user->tokens()->delete();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_password_reset_is_generic_single_use_and_revokes_credentials(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.test']);
        $this->postJson('/auth/forgot-password', ['email' => 'missing@example.test'])->assertOk()->assertJsonPath('message', __('auth.reset_sent'));
        $this->postJson('/auth/forgot-password', ['email' => ' RESET@EXAMPLE.TEST '])->assertOk()->assertJsonPath('message', __('auth.reset_sent'));
        Notification::assertSentTo($user, ResetPassword::class);
        $token = Password::createToken($user);
        $user->createToken('old-device');
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'A-new-password-123!', 'password_confirmation' => 'A-new-password-123!'];
        $this->postJson('/auth/reset-password', $payload)->assertOk();
        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/auth/reset-password', $payload)->assertUnprocessable();
    }

    public function test_password_reset_rejects_weak_and_expired_credentials(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $this->postJson('/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->travel(61)->minutes();
        $this->postJson('/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'Long-password-123!', 'password_confirmation' => 'Long-password-123!'])->assertUnprocessable();
        $this->travelBack();
    }

    public function test_database_rejects_noncanonical_email_even_when_model_is_bypassed(): void
    {
        $this->expectException(QueryException::class);
        DB::table('users')->insert(['public_id' => Str::uuid7(), 'name' => 'Test', 'email' => 'UPPER@example.test', 'password' => 'not-a-live-password']);
    }

    public function test_invalid_tenant_resolution_cannot_reuse_a_previous_context(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Studio']);
        $this->grant($user, $organization);
        $context = app(TenantContext::class);
        $context->resolve($user, $organization->public_id);
        try {
            $context->resolve($user, (string) Str::uuid7());
            $this->fail('Unknown tenant must fail.');
        } catch (ModelNotFoundException) {
            $this->assertFalse($context->permits($user, Permission::StudioAccess));
        }
    }

    public function test_sensitive_attributes_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);
        User::create(['name' => 'Test', 'email' => 'test@example.test', 'password' => 'example', 'permissions' => ['payments.correct']]);
    }

    public function test_exceptions_do_not_expose_or_log_submitted_secrets(): void
    {
        Log::spy();
        Route::get('/api/v1/test-error', function (): never {
            throw new \RuntimeException('submitted-private-value');
        });
        $this->getJson('/api/v1/test-error')->assertStatus(500)
            ->assertExactJson(['message' => 'Се појави неочекувана грешка.'])
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        Log::shouldHaveReceived('error')->once()
            ->with('Unhandled application exception', ['type' => \RuntimeException::class]);
    }
}
