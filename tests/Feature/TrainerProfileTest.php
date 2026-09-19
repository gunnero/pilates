<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Support\MembershipStatus;
use App\Support\StaffRole;
use App\Support\TenantContext;
use App\Support\TrainerLifecycle;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

class TrainerProfileTest extends TestCase
{
    use OrganizationFixtures, RefreshDatabase;

    private function setupOwner(): array
    {
        $user = User::factory()->create(['name' => 'Owner Fixture']);
        $org = Organization::create(['name' => 'Trainer Fixture']);
        $access = $this->membership($user, $org);
        $this->actingAs($user);

        return [$user, $org, $access, $this->base($org).'/trainers'];
    }

    private function enable(string $url, OrganizationMembership $membership, array $data = []): string
    {
        return $this->postJson($url, ['membership_id' => $membership->public_id, ...$data])->assertCreated()->json('data.id');
    }

    public function test_any_active_role_can_teach_without_creating_identity_or_changing_grants(): void
    {
        [$owner, $org, $access, $url] = $this->setupOwner();
        $this->assertDatabaseCount('trainer_profiles', 0);
        $id = $this->enable($url, $access);
        $this->getJson($url.'/'.$id)->assertJsonPath('data.display_name', 'Owner Fixture')->assertJsonPath('data.staff_role', 'owner')->assertJsonMissingPath('data.permissions')->assertJsonMissingPath('data.user_id')->assertJsonMissingPath('data.organization_id')->assertJsonMissingPath('data.email');
        foreach ([StaffRole::Administrator, StaffRole::Trainer, StaffRole::Staff] as $role) {
            $membership = $this->membership(User::factory()->create(), $org, $role);
            $before = $membership->permissions;
            $this->enable($url, $membership);
            $this->assertSame($before, $membership->fresh()->permissions);
        }
        $this->assertDatabaseCount('trainer_profiles', 4);
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('organization_memberships', 4);
        $this->assertDatabaseCount('studio_members', 0);
    }

    public function test_enable_requires_management_active_staff_same_tenant_and_uniqueness(): void
    {
        [, $org, $owner, $url] = $this->setupOwner();
        $admin = User::factory()->create();
        $adminAccess = $this->membership($admin, $org, StaffRole::Administrator);
        $this->actingAs($admin);
        $id = $this->enable($url, $adminAccess);
        $this->postJson($url, ['membership_id' => $adminAccess->public_id])->assertConflict();
        $foreign = $this->membership(User::factory()->create(), Organization::create(['name' => 'Other']));
        $this->postJson($url, ['membership_id' => $foreign->public_id])->assertNotFound();
        foreach (['suspended', 'revoked'] as $status) {
            $target = $this->membership(User::factory()->create(), $org, StaffRole::Staff);
            $target->status = MembershipStatus::from($status);
            $target->save();
            $this->postJson($url, ['membership_id' => $target->public_id])->assertUnprocessable();
        }
        $trainer = User::factory()->create();
        $this->membership($trainer, $org, StaffRole::Trainer);
        $this->actingAs($trainer)->postJson($url, ['membership_id' => $owner->public_id])->assertForbidden();
        $this->patchJson($url.'/'.$id, ['bio' => 'No'])->assertForbidden();
    }

    public function test_profile_lifecycle_is_separate_from_staff_and_audited_without_pii(): void
    {
        [, $org, $access, $url] = $this->setupOwner();
        $location = $this->location($org);
        $id = $this->enable($url, $access, ['display_name' => 'Private fixture display', 'bio' => 'Private fixture bio']);
        $this->getJson($url.'/'.$id)->assertJsonPath('data.assignable', true)->assertJsonPath('data.eligible_locations.0.id', $location->public_id);
        $this->patchJson($url.'/'.$id, ['display_name' => 'Changed fixture display', 'bio' => 'Changed fixture bio'])->assertOk();
        $this->patchJson($url.'/'.$id.'/status', ['status' => 'inactive'])->assertOk()->assertJsonPath('data.assignable', false)->assertJsonPath('data.staff_status', 'active');
        $this->assertDatabaseHas('organization_memberships', ['id' => $access->id, 'status' => 'active']);
        $this->patchJson($url.'/'.$id.'/status', ['status' => 'active'])->assertOk()->assertJsonPath('data.assignable', true);
        $this->patchJson($url.'/'.$id.'/status', ['status' => 'archived'])->assertUnprocessable();
        $this->deleteJson($url.'/'.$id)->assertMethodNotAllowed();
        foreach (['trainer.enabled', 'trainer.profile_updated', 'trainer.deactivated', 'trainer.activated'] as $action) {
            $this->assertDatabaseHas('audit_events', ['action' => $action, 'subject_id' => $id]);
        }
        $metadata = DB::table('audit_events')->where('subject_id', $id)->pluck('metadata')->implode(' ');
        foreach (['Private fixture', 'Changed fixture'] as $pii) {
            $this->assertStringNotContainsString($pii, $metadata);
        }
        $this->patchJson($url.'/'.$id, ['display_name' => null])->assertOk()->assertJsonPath('data.display_name', 'Owner Fixture');
    }

    public function test_profiles_cannot_reassign_membership_tenant_or_edit_access(): void
    {
        [, , $access, $url] = $this->setupOwner();
        $id = $this->enable($url, $access);
        foreach (['organization_id', 'organization_membership_id', 'membership_id', 'public_id', 'id', 'user_id', 'role', 'permissions', 'location_scope', 'location_ids', 'status', 'created_at', 'audit_metadata'] as $key) {
            $this->patchJson($url.'/'.$id, [$key => 'spoof'])->assertUnprocessable();
        }
        $this->postJson($url, ['membership_id' => $access->public_id, 'organization_id' => 99])->assertUnprocessable();
        $this->patchJson($url.'/'.$id, ['bio' => str_repeat('x', 1001), 'display_name' => str_repeat('x', 161)])->assertUnprocessable();
        $this->patchJson($url.'/'.$id.'/status', ['status' => 'inactive', 'permissions' => []])->assertUnprocessable();
    }

    public function test_tenant_and_location_filters_do_not_reveal_foreign_resources(): void
    {
        [, $org, $access, $url] = $this->setupOwner();
        $own = $this->enable($url, $access);
        $other = Organization::create(['name' => 'Other']);
        $foreignAccess = $this->membership(User::factory()->create(), $other);
        $foreign = new TrainerProfile;
        $foreign->forceFill(['organization_id' => $other->id, 'organization_membership_id' => $foreignAccess->id])->save();
        $location = $this->location($other);
        foreach ([$foreign->public_id, (string) Str::uuid7()] as $id) {
            $this->getJson($url.'/'.$id)->assertNotFound();
            $this->getJson($url.'/'.$id.'/locations')->assertNotFound();
            $this->patchJson($url.'/'.$id, ['bio' => 'No'])->assertNotFound();
            $this->patchJson($url.'/'.$id.'/status', ['status' => 'inactive'])->assertNotFound();
        }
        $this->getJson($url)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own);
        $this->postJson($url.'/search', ['location_id' => $location->public_id])->assertNotFound();
        $this->getJson($this->base($other).'/trainers')->assertNotFound();
    }

    public function test_eligibility_uses_current_membership_scope_status_and_active_locations(): void
    {
        [, $org, $access, $url] = $this->setupOwner();
        $a = $this->location($org, 'A');
        $b = $this->location($org, 'B');
        $inactive = $this->location($org, 'Inactive');
        DB::table('locations')->where('id', $inactive->id)->update(['status' => 'inactive']);
        $target = $this->membership(User::factory()->create(), $org, StaffRole::Staff, null, 'selected');
        DB::table('membership_locations')->insert(['organization_id' => $org->id, 'organization_membership_id' => $target->id, 'location_id' => $a->id]);
        $id = $this->enable($url, $target);
        $this->getJson($url.'/'.$id.'/locations')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->public_id);
        $this->postJson($url.'/search', ['location_id' => $b->public_id])->assertJsonCount(0, 'data');
        $this->postJson($url.'/search', ['location_id' => $inactive->public_id])->assertNotFound();
        // Change through the established staff path, not a duplicate teaching assignment table.
        $this->patchJson($this->base($org).'/staff/'.$target->public_id, ['location_ids' => [$b->public_id]])->assertOk();
        $this->getJson($url.'/'.$id.'/locations')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->public_id);
        $this->patchJson($this->base($org).'/staff/'.$target->public_id, ['location_scope' => 'all'])->assertOk();
        $this->getJson($url.'/'.$id)->assertJsonPath('data.eligible_location_count', 2);
        $this->patchJson($this->base($org).'/staff/'.$target->public_id, ['status' => 'suspended'])->assertOk();
        $this->getJson($url.'/'.$id)->assertOk()->assertJsonPath('data.assignable', false)->assertJsonPath('data.eligible_location_count', 0);
        $this->patchJson($url.'/'.$id.'/status', ['status' => 'inactive'])->assertOk();
        $this->patchJson($url.'/'.$id.'/status', ['status' => 'active'])->assertUnprocessable();
        $this->patchJson($this->base($org).'/staff/'.$target->public_id, ['status' => 'revoked'])->assertOk();
        $this->getJson($url.'/'.$id.'/locations')->assertJsonCount(0, 'data');
    }

    public function test_selected_viewers_see_only_shared_locations_and_cannot_manage_or_see_clients(): void
    {
        [, $org, $owner, $url] = $this->setupOwner();
        $a = $this->location($org, 'A');
        $b = $this->location($org, 'B');
        $ownerProfile = $this->enable($url, $owner);
        $outside = $this->membership(User::factory()->create(), $org, StaffRole::Staff, null, 'selected');
        DB::table('membership_locations')->insert(['organization_id' => $org->id, 'organization_membership_id' => $outside->id, 'location_id' => $b->id]);
        $outsideProfile = $this->enable($url, $outside);
        $viewer = User::factory()->create();
        $access = $this->membership($viewer, $org, StaffRole::Trainer, [...StaffRole::Trainer->permissions(), 'trainers.manage', 'members.view'], 'selected');
        DB::table('membership_locations')->insert(['organization_id' => $org->id, 'organization_membership_id' => $access->id, 'location_id' => $a->id]);
        $viewerProfile = $this->enable($url, $access);
        $this->actingAs($viewer)->getJson($url)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($url.'/'.$ownerProfile)->assertJsonPath('data.eligible_locations.0.id', $a->public_id)->assertJsonPath('data.eligible_location_count', 1);
        $this->getJson($url.'/'.$outsideProfile)->assertNotFound();
        $this->postJson($url.'/search', ['location_id' => $b->public_id])->assertNotFound();
        $this->patchJson($url.'/'.$viewerProfile, ['bio' => 'Global edit'])->assertForbidden();
        $this->postJson($url, ['membership_id' => $access->public_id])->assertForbidden();
        $this->getJson($this->base($org).'/members')->assertForbidden();
        foreach (['payments.view', 'payments.record', 'payments.correct', 'reports.view'] as $financial) {
            $this->assertNotContains($financial, $access->permissions);
        }
        DB::table('membership_locations')->where('organization_membership_id', $access->id)->delete();
        $this->getJson($url)->assertJsonCount(0, 'data');
        $this->getJson($url.'/'.$ownerProfile)->assertNotFound();
    }

    public function test_search_pagination_and_batched_location_summaries_are_bounded(): void
    {
        [, $org, $access, $url] = $this->setupOwner();
        for ($i = 0; $i < 7; $i++) {
            $this->location($org, 'Location '.$i);
        }
        $id = $this->enable($url, $access, ['display_name' => 'Teaching Fixture']);
        for ($i = 0; $i < 5; $i++) {
            $this->enable($url, $this->membership(User::factory()->create(['name' => 'Fallback '.$i]), $org, StaffRole::Staff));
        }
        $this->getJson($url.'/'.$id)->assertJsonPath('data.eligible_location_count', 7)->assertJsonCount(5, 'data.eligible_locations');
        $this->getJson($url.'/'.$id.'/locations?per_page=5&page=2')->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 7);
        $this->postJson($url.'/search', ['search' => 'teach'])->assertJsonCount(1, 'data');
        $this->postJson($url.'/search', ['search' => 'fall'])->assertJsonCount(5, 'data');
        $this->postJson($url.'/search', ['search' => 'te%'])->assertJsonCount(0, 'data');
        $this->getJson($url.'?search=teach')->assertUnprocessable();
        $this->postJson($url.'/search', ['per_page' => 101, 'search' => str_repeat('x', 101)])->assertUnprocessable();
        $this->postJson($url.'/search', ['status' => 'inactive'])->assertJsonCount(0, 'data');
        $this->postJson($url.'/search', ['per_page' => 2, 'page' => 2])->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 6);
        DB::enableQueryLog();
        $this->getJson($url)->assertJsonCount(6, 'data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $eligibility = array_filter($queries, fn ($query) => str_contains($query['query'], 'teaching_profile'));
        $this->assertCount(1, $eligibility, 'Eligibility summaries use one query for a page.');
    }

    public function test_suspended_organization_actor_and_stale_context_fail_closed(): void
    {
        [$actor,$org,$access,$url] = $this->setupOwner();
        $id = $this->enable($url, $access);
        foreach (['suspended', 'revoked'] as $status) {
            DB::table('organization_memberships')->where('id', $access->id)->update(['status' => $status]);
            $this->getJson($url)->assertNotFound();
            $this->patchJson($url.'/'.$id, ['bio' => 'No'])->assertNotFound();
        }
        DB::table('organization_memberships')->where('id', $access->id)->update(['status' => 'active']);
        DB::table('organizations')->where('id', $org->id)->update(['status' => 'suspended']);
        $this->postJson($url, ['membership_id' => $access->public_id])->assertNotFound();
        DB::table('organizations')->where('id', $org->id)->update(['status' => 'active']);
        $context = app(TenantContext::class);
        $context->resolve($actor, $org->public_id);
        DB::table('organization_memberships')->where('id', $access->id)->update(['status' => 'revoked']);
        $this->expectException(ModelNotFoundException::class);
        TrainerLifecycle::update($context, $actor, $id, ['bio' => 'Stale']);
    }

    public function test_database_uniqueness_composite_fk_status_and_audit_immutability(): void
    {
        [, $org, $access, $url] = $this->setupOwner();
        $id = $this->enable($url, $access);
        $foreign = $this->membership(User::factory()->create(), Organization::create(['name' => 'Foreign']));
        $operations = [
            fn () => DB::table('trainer_profiles')->insert(['public_id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'organization_membership_id' => $access->id]),
            fn () => DB::table('trainer_profiles')->where('public_id', $id)->update(['organization_membership_id' => $foreign->id]),
            fn () => DB::table('trainer_profiles')->where('public_id', $id)->update(['status' => 'archived']),
            fn () => DB::table('trainer_profiles')->where('public_id', $id)->update(['bio' => str_repeat('x', 1001)]),
            fn () => DB::table('audit_events')->where('subject_id', $id)->update(['action' => 'tamper']),
            fn () => DB::table('audit_events')->where('subject_id', $id)->delete(),
            fn () => DB::statement('TRUNCATE audit_events'),
        ];
        foreach ($operations as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Invalid write accepted');
            } catch (QueryException $error) {
                $this->assertContains($error->getCode(), ['23505', '23503', '23514', '22001']);
            }
        }
    }

    public function test_staff_integration_and_operator_adoption_are_permission_controlled(): void
    {
        [, $org, $access, $url] = $this->setupOwner();
        $this->getJson($this->base($org).'/staff')->assertJsonPath('data.0.trainer_profile', null);
        $id = $this->enable($url, $access);
        $this->getJson($this->base($org).'/staff')->assertJsonPath('data.0.trainer_profile.id', $id);
        $access->permissions = array_values(array_diff($access->permissions, ['trainers.view', 'trainers.manage']));
        $access->save();
        $this->getJson($url)->assertForbidden();
        $this->getJson($this->base($org).'/staff')->assertJsonMissingPath('data.0.trainer_profile');
        $this->artisan('gt:trainer-access-enable', ['organization' => $org->public_id, 'membership' => $access->public_id, '--reason' => 'Fixture adoption'])->assertSuccessful();
        $this->getJson($url)->assertOk();
        $this->assertDatabaseCount('trainer_profiles', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.permissions_changed', 'actor_id' => null]);
    }

    public function test_authentication_token_abilities_and_audit_failure_are_enforced(): void
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Fixture']);
        $access = $this->membership($user, $org);
        $url = $this->base($org).'/trainers';
        $this->getJson($url)->assertUnauthorized();
        $token = $user->createToken('Fixture view',['studio.access', 'trainers.view'])->plainTextToken;
        $this->withToken($token)->getJson($url)->assertOk();
        $this->postJson($url,['membership_id' => $access->public_id])->assertForbidden();
    }

    public function test_audit_failure_rolls_back_enablement(): void
    {
        [, ,$access,$url] = $this->setupOwner();
        DB::unprepared("CREATE OR REPLACE FUNCTION gt_test_trainer_audit_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Fixture'; END $$; CREATE TRIGGER gt_test_trainer_fail BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION gt_test_trainer_audit_fail();");
        $this->postJson($url,['membership_id' => $access->public_id])->assertStatus(500);
        $this->assertDatabaseCount('trainer_profiles',0);
    }
}
