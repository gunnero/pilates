<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Lifecycle;
use App\Support\LocationStatus;
use App\Support\MembershipStatus;
use App\Support\OrganizationStatus;
use App\Support\Permission;
use App\Support\StaffRole;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

class OrganizationLifecycleTest extends TestCase
{
    use OrganizationFixtures, RefreshDatabase;

    public function test_organization_settings_are_validated_scoped_and_audited(): void
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Studio']);
        $this->membership($user, $org);
        $this->getJson($this->base($org))->assertUnauthorized();
        $this->actingAs($user)->patchJson($this->base($org), ['name' => 'Renamed', 'locale' => 'en', 'timezone' => 'America/New_York', 'currency' => 'MKD'])->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonMissingPath('data.organization_id');
        $this->assertDatabaseHas('audit_events', ['organization_id' => $org->id, 'actor_id' => $user->id, 'action' => 'organization.settings_changed']);
        $this->patchJson($this->base($org), ['locale' => 'de', 'timezone' => 'Mars/Nowhere', 'currency' => 'EUR'])->assertUnprocessable()->assertJsonValidationErrors(['locale', 'timezone', 'currency']);
        $this->patchJson($this->base($org), ['organization_id' => 99, 'slug' => 'takeover'])->assertUnprocessable();
        $this->patchJson($this->base($org), ['status' => 'suspended'])->assertUnprocessable();
    }

    public function test_nonactive_memberships_and_organizations_fail_closed_and_disappear_from_picker(): void
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Studio']);
        $membership = $this->membership($user, $org);
        $this->actingAs($user)->getJson($this->base($org).'/context')->assertOk();
        foreach ([MembershipStatus::Suspended, MembershipStatus::Revoked] as $status) {
            $membership->status = $status;
            $membership->save();
            $this->getJson($this->base($org).'/context')->assertNotFound();
            $this->postJson($this->base($org).'/switch')->assertNotFound();
            $this->getJson('/api/v1/me')->assertJsonCount(0, 'data.organizations');
        }
        $membership->status = MembershipStatus::Active;
        $membership->save();
        foreach ([OrganizationStatus::Inactive, OrganizationStatus::Suspended] as $status) {
            $org->status = $status;
            $org->save();
            $this->getJson($this->base($org).'/context')->assertNotFound();
            $this->getJson('/api/v1/me')->assertJsonCount(0, 'data.organizations');
        }
    }

    public function test_switch_requires_active_access_is_audited_and_persists_only_a_preference(): void
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Studio']);
        $foreign = Organization::create(['name' => 'Foreign']);
        $this->membership($user, $org);
        $this->actingAs($user)->withHeader('Origin', 'http://127.0.0.1:8010')->postJson($this->base($org).'/switch')->assertOk()->assertSessionHas('active_organization_id', $org->public_id);
        $this->getJson('/api/v1/me')->assertJsonPath('data.active_organization_id', $org->public_id);
        $this->postJson($this->base($foreign).'/switch')->assertNotFound();
        $this->getJson($this->base($foreign).'/locations')->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['action' => 'organization.switched', 'organization_id' => $org->id, 'actor_id' => $user->id]);
        $this->assertFalse(app(TenantContext::class)->permits($user, Permission::StudioAccess));
    }

    public function test_location_crud_is_tenant_scoped_bounded_and_audited(): void
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Studio']);
        $foreign = Organization::create(['name' => 'Foreign']);
        $this->membership($user, $org);
        $other = $this->location($foreign);
        $url = $this->base($org).'/locations';
        $created = $this->actingAs($user)->postJson($url, ['name' => 'Centar', 'city' => 'Bitola', 'country_code' => 'MK'])->assertCreated()->assertJsonMissingPath('data.organization_id')->json('data.id');
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
        $this->getJson($url.'?per_page=101')->assertUnprocessable();
        $this->getJson($url.'/'.$other->public_id)->assertNotFound();
        $this->patchJson($url.'/'.$other->public_id, ['name' => 'stolen'])->assertNotFound();
        $this->patchJson($url.'/'.$created, ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->assertDatabaseHas('audit_events', ['action' => 'location.status_changed', 'subject_id' => $created]);
        $this->postJson($url, ['name' => 'Bad', 'organization_id' => $foreign->id])->assertUnprocessable();
        $this->postJson($url, ['name' => 'Bad', 'status' => 'deleted', 'country_code' => 'mk'])->assertUnprocessable();
        $this->deleteJson($url.'/'.$created)->assertMethodNotAllowed();
    }

    public function test_valid_multibyte_location_changes_fit_bounded_audit_metadata(): void
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Studio']);
        $this->membership($user, $org);
        $url = $this->base($org).'/locations';
        $fields = fn (string $character) => ['name' => str_repeat($character, 160), 'address' => str_repeat($character, 300), 'city' => str_repeat($character, 100)];
        $id = $this->actingAs($user)->postJson($url, $fields('🌍'))->assertCreated()->json('data.id');
        $this->patchJson($url.'/'.$id, $fields('🌎'))->assertOk();
        $bytes = (int) DB::table('audit_events')->where('action', 'location.updated')->selectRaw('octet_length(metadata::text) AS bytes')->first()->bytes;
        $this->assertGreaterThan(4096, $bytes);
        $this->assertLessThanOrEqual(8192, $bytes);
    }

    public function test_selected_location_scope_is_enforced_for_reads_writes_and_list(): void
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Studio']);
        $a = $this->location($org, 'A');
        $b = $this->location($org, 'B');
        $membership = $this->membership($user, $org, StaffRole::Staff, ['studio.access', 'locations.view', 'locations.manage'], 'selected');
        DB::table('membership_locations')->insert(['organization_id' => $org->id, 'organization_membership_id' => $membership->id, 'location_id' => $a->id]);
        $url = $this->base($org).'/locations';
        $this->actingAs($user)->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->public_id);
        $this->getJson($url.'/'.$b->public_id)->assertNotFound();
        $this->patchJson($url.'/'.$b->public_id, ['name' => 'stolen'])->assertNotFound();
        $this->patchJson($url.'/'.$a->public_id, ['name' => 'Allowed'])->assertOk();
        $this->postJson($url, ['name' => 'Expand my scope'])->assertForbidden();
    }

    public function test_view_only_staff_cannot_view_inactive_locations_or_mutate_settings(): void
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Studio']);
        $this->membership($user, $org, StaffRole::Trainer);
        $location = $this->location($org);
        $location->status = LocationStatus::Inactive;
        $location->save();
        $this->actingAs($user)->getJson($this->base($org).'/locations')->assertJsonCount(0, 'data');
        $this->getJson($this->base($org).'/locations/'.$location->public_id)->assertNotFound();
        $this->patchJson($this->base($org), ['name' => 'stolen'])->assertForbidden();
        $this->getJson($this->base($org).'/staff')->assertForbidden();
        $context = app(TenantContext::class);
        $context->resolve($user, $org->public_id);
        foreach (['payments.view', 'payments.record', 'payments.correct', 'reports.view'] as $permission) {
            $this->assertNotContains($permission, $context->permissions($user));
        }
    }

    public function test_membership_suspension_reactivation_and_terminal_revocation_are_audited(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create();
        $org = Organization::create(['name' => 'Studio']);
        $this->membership($owner, $org);
        $membership = $this->membership($staff, $org, StaffRole::Trainer);
        $url = $this->base($org).'/staff/'.$membership->public_id;
        $this->actingAs($owner)->getJson($this->base($org).'/staff')->assertJsonCount(2, 'data');
        foreach (['suspended', 'active', 'revoked'] as $status) {
            $this->patchJson($url, ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);
        }
        $this->patchJson($url, ['status' => 'active'])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'inactive'])->assertUnprocessable();
        $this->actingAs($staff)->getJson($this->base($org).'/context')->assertNotFound();
        foreach (['membership.suspended', 'membership.reactivated', 'membership.revoked'] as $action) {
            $this->assertDatabaseHas('audit_events', ['action' => $action, 'subject_id' => $membership->public_id]);
        }
        $this->assertDatabaseHas('organization_memberships', ['id' => $membership->id, 'status' => 'revoked']);
    }

    public function test_last_owner_cannot_be_suspended_revoked_demoted_or_stripped_of_control(): void
    {
        $owner = User::factory()->create();
        $org = Organization::create(['name' => 'Studio']);
        $membership = $this->membership($owner, $org);
        $url = $this->base($org).'/staff/'.$membership->public_id;
        $this->actingAs($owner);
        foreach ([['status' => 'suspended'], ['status' => 'revoked'], ['role' => 'trainer']] as $data) {
            $this->patchJson($url, $data)->assertStatus(409);
        }
        $this->patchJson($url, ['permissions' => ['studio.access']])->assertUnprocessable();
        $this->assertDatabaseCount('audit_events', 0);
        $second = User::factory()->create();
        $this->membership($second, $org);
        $this->patchJson($url, ['role' => 'trainer'])->assertOk();
        $this->patchJson($this->base($org), ['name' => 'stale authority'])->assertForbidden();
    }

    public function test_role_and_permission_escalation_and_mass_assignment_are_rejected(): void
    {
        $admin = User::factory()->create();
        $staff = User::factory()->create();
        $org = Organization::create(['name' => 'Studio']);
        $this->membership($admin, $org, StaffRole::Administrator);
        $target = $this->membership($staff, $org, StaffRole::Staff);
        $url = $this->base($org).'/staff/'.$target->public_id;
        $this->actingAs($admin)->patchJson($url, ['role' => 'owner'])->assertForbidden();
        $this->patchJson($url, ['permissions' => ['payments.view']])->assertForbidden();
        $this->patchJson($url, ['user_id' => $admin->id, 'organization_id' => 99])->assertUnprocessable();
        $this->patchJson($url, ['permissions' => ['invented.permission']])->assertUnprocessable();
        $this->patchJson($url, ['role' => 'super_admin'])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'suspended'])->assertOk();
    }

    public function test_delegated_permission_manager_cannot_grant_beyond_their_own_authority(): void
    {
        $actor = User::factory()->create();
        $staff = User::factory()->create();
        $org = Organization::create(['name' => 'Studio']);
        $this->membership($actor, $org, StaffRole::Administrator, [...StaffRole::Administrator->permissions(), 'permissions.manage']);
        $target = $this->membership($staff, $org, StaffRole::Staff);
        $url = $this->base($org).'/staff/'.$target->public_id;
        $this->actingAs($actor)->patchJson($url, ['permissions' => ['studio.access', 'payments.view']])->assertForbidden();
        $this->patchJson($url, ['role' => 'owner'])->assertForbidden();
    }

    public function test_owner_can_grant_independent_financial_and_selected_location_access(): void
    {
        $owner = User::factory()->create();
        $trainer = User::factory()->create();
        $org = Organization::create(['name' => 'Studio']);
        $other = Organization::create(['name' => 'Other']);
        $this->membership($owner, $org);
        $target = $this->membership($trainer, $org, StaffRole::Trainer);
        $location = $this->location($org);
        $foreign = $this->location($other);
        $url = $this->base($org).'/staff/'.$target->public_id;
        $this->actingAs($owner)->patchJson($url, ['permissions' => [...StaffRole::Trainer->permissions(), 'payments.view', 'payments.record'], 'location_scope' => 'selected', 'location_ids' => [$location->public_id]])->assertOk()->assertJsonPath('data.location_scope', 'selected');
        $this->patchJson($url, ['location_ids' => [$foreign->public_id]])->assertNotFound();
        $this->patchJson($url, ['location_ids' => []])->assertUnprocessable();
        $context = app(TenantContext::class);
        $context->resolve($trainer, $org->public_id);
        $this->assertTrue($context->permits($trainer, Permission::PaymentsRecord));
        $this->assertFalse($context->permits($trainer, Permission::PaymentsCorrect));
        $this->assertFalse($context->permits($trainer, Permission::ReportsView));
        $this->assertSame([$location->id], $context->locations()->pluck('id')->all());
    }

    public function test_foreign_staff_identifiers_are_not_enumerable_and_grants_are_not_serialized_without_permission(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $org = Organization::create(['name' => 'Studio']);
        $foreign = Organization::create(['name' => 'Other']);
        $this->membership($user, $org, StaffRole::Staff, ['studio.access', 'staff.view']);
        $target = $this->membership($other, $foreign);
        $this->actingAs($user)->getJson($this->base($org).'/staff')->assertOk()->assertJsonMissingPath('data.0.permissions')->assertJsonMissingPath('data.0.user.email');
        $this->getJson($this->base($org).'/staff/'.$target->public_id)->assertNotFound();
        $this->getJson($this->base($org).'/permissions')->assertForbidden();
    }

    public function test_selected_permission_manager_cannot_expand_their_location_authority(): void
    {
        $actor = User::factory()->create();
        $org = Organization::create(['name' => 'Studio']);
        $a = $this->location($org, 'A');
        $b = $this->location($org, 'B');
        $manager = $this->membership($actor, $org, StaffRole::Administrator, [...StaffRole::Administrator->permissions(), 'permissions.manage'], 'selected');
        $target = $this->membership(User::factory()->create(), $org, StaffRole::Trainer, null, 'selected');
        foreach ([$manager, $target] as $membership) {
            DB::table('membership_locations')->insert(['organization_id' => $org->id, 'organization_membership_id' => $membership->id, 'location_id' => $a->id]);
        }
        $url = $this->base($org).'/staff/'.$target->public_id;
        $this->actingAs($actor)->patchJson($url, ['location_scope' => 'all'])->assertForbidden();
        $this->patchJson($url, ['location_ids' => [$b->public_id]])->assertNotFound();
        $this->patchJson($url, ['status' => 'suspended'])->assertOk();
        DB::table('membership_locations')->where('organization_membership_id', $target->id)->update(['location_id' => $b->id]);
        $this->patchJson($url, ['status' => 'active'])->assertForbidden();
    }

    public function test_bearer_lifecycle_writes_intersect_token_abilities_and_recheck_revocation(): void
    {
        $actor = User::factory()->create();
        $org = Organization::create(['name' => 'Studio']);
        $membership = $this->membership($actor, $org);
        $target = $this->membership(User::factory()->create(), $org, StaffRole::Trainer);
        $token = $actor->createToken('Restricted test', [...StaffRole::Trainer->permissions(), 'staff.manage', 'permissions.manage'])->plainTextToken;
        $url = $this->base($org).'/staff/'.$target->public_id;
        $this->withToken($token)->patchJson($url, ['permissions' => [...StaffRole::Trainer->permissions(), 'payments.record']])->assertForbidden();
        $this->patchJson($url, ['status' => 'suspended'])->assertOk();
        $other = Organization::create(['name' => 'Other']);
        $foreign = $this->membership(User::factory()->create(), $other, StaffRole::Trainer);
        $this->patchJson($this->base($org).'/staff/'.$foreign->public_id, ['status' => 'suspended'])->assertNotFound();
        $this->postJson($this->base($org).'/switch')->assertOk();
        $membership->status = MembershipStatus::Revoked;
        $membership->save();
        $this->patchJson($url, ['status' => 'active'])->assertNotFound();
        $this->postJson($this->base($org).'/switch')->assertNotFound();
    }

    public function test_mutation_rechecks_authority_after_context_resolution(): void
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Studio']);
        $membership = $this->membership($user, $org);
        $context = app(TenantContext::class);
        $context->resolve($user, $org->public_id);
        DB::table('organization_memberships')->where('id', $membership->id)->update(['status' => 'revoked']);
        $this->expectException(ModelNotFoundException::class);
        Lifecycle::run($context, $user, Permission::OrganizationsManage, fn () => $this->fail('Stale context authorized a write'));
    }

    public function test_database_constraints_reject_cross_tenant_links_invalid_states_and_audit_mutation(): void
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Studio']);
        $other = Organization::create(['name' => 'Other']);
        $membership = $this->membership($user, $org);
        $foreign = $this->location($other);
        $this->actingAs($user)->patchJson($this->base($org), ['name' => 'Audited'])->assertOk();
        $operations = [
            fn () => DB::table('membership_locations')->insert(['organization_id' => $org->id, 'organization_membership_id' => $membership->id, 'location_id' => $foreign->id]),
            fn () => DB::table('organization_memberships')->where('id', $membership->id)->update(['status' => 'inactive']),
            fn () => DB::table('organization_memberships')->where('id', $membership->id)->update(['role' => 'super_admin']),
            fn () => DB::table('organizations')->where('id', $org->id)->update(['timezone' => 'Invalid/Zone']),
            fn () => DB::table('organizations')->where('id', $org->id)->update(['locale' => 'de']),
            fn () => DB::table('audit_events')->update(['action' => 'tampered']),
            fn () => DB::table('audit_events')->delete(),
            fn () => DB::statement('TRUNCATE audit_events'),
        ];
        foreach ($operations as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Constraint did not reject invalid write');
            } catch (QueryException $exception) {
                $this->assertContains($exception->getCode(), ['23514', '23503']);
            }
        }
        $this->assertDatabaseHas('audit_events', ['action' => 'organization.settings_changed']);
    }

    public function test_audit_failure_rolls_back_the_sensitive_change(): void
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Original']);
        $this->membership($user, $org);
        DB::unprepared("CREATE FUNCTION gt_test_fail_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Test audit unavailable'; END $$; CREATE TRIGGER gt_test_fail BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION gt_test_fail_audit();");
        $this->actingAs($user)->patchJson($this->base($org), ['name' => 'Must rollback'])->assertStatus(500);
        $this->assertSame('Original', $org->fresh()->name);
    }

    public function test_operator_bootstrap_and_status_commands_are_audited_without_creating_users(): void
    {
        $user = User::factory()->create();
        $this->artisan('gt:organization-create', ['name' => 'Operator Studio', 'owner-email' => $user->email])->assertSuccessful();
        $org = Organization::query()->where('name', 'Operator Studio')->firstOrFail();
        $this->assertDatabaseCount('users', 1);
        $this->artisan('gt:organization-status', ['organization' => $org->public_id, 'status' => 'suspended', '--reason' => 'Reviewed maintenance'])->assertSuccessful();
        $this->actingAs($user)->getJson($this->base($org).'/context')->assertNotFound();
        $this->artisan('gt:organization-status', ['organization' => $org->public_id, 'status' => 'active', '--reason' => 'Maintenance complete'])->assertSuccessful();
        $this->getJson($this->base($org).'/context')->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'organization.status_changed', 'actor_id' => null]);
    }
}
