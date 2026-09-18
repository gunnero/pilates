<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\StudioMember;
use App\Models\User;
use App\Support\MemberLifecycle;
use App\Support\MembershipStatus;
use App\Support\OrganizationStatus;
use App\Support\StaffRole;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

class StudioMemberTest extends TestCase
{
    use OrganizationFixtures, RefreshDatabase;

    private function setupOwner(): array
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Fixture Studio']);
        $access = $this->membership($user, $org);
        $this->actingAs($user);

        return [$user, $org, $access, $this->base($org).'/members'];
    }

    private function createClient(string $url, array $data = []): string
    {
        return $this->postJson($url, [...['first_name' => 'Тест', 'last_name' => 'Клиент', 'digital_access_mode' => 'offline'], ...$data])->assertCreated()->json('data.id');
    }

    public function test_offline_creation_needs_no_user_or_contact_and_returns_only_explicit_fields(): void
    {
        [$user, $org, $access, $url] = $this->setupOwner();
        $id = $this->createClient($url);
        $this->getJson($url.'/'.$id)->assertOk()->assertJsonPath('data.phone', null)->assertJsonPath('data.email', null)->assertJsonPath('data.status', 'active')->assertJsonPath('data.preferred_locale', 'mk')->assertJsonMissingPath('data.user_id')->assertJsonMissingPath('data.organization_id')->assertJsonMissingPath('data.notes.created_at');
        $this->assertTrue(Str::isUuid($id));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('organization_memberships', 1);
        $this->assertDatabaseHas('studio_members', ['public_id' => $id, 'organization_id' => $org->id, 'user_id' => null]);
        $this->assertDatabaseHas('audit_events', ['action' => 'member.created', 'actor_id' => $user->id, 'subject_id' => $id]);
    }

    public function test_contact_normalization_validation_and_tampering(): void
    {
        [, , , $url] = $this->setupOwner();
        $id = $this->createClient($url, ['email' => '  FIXTURE@example.test ', 'phone' => '00 44 (20) 7946-0958']);
        $this->getJson($url.'/'.$id)->assertJsonPath('data.email', 'fixture@example.test')->assertJsonPath('data.phone', '+442079460958');
        foreach ([['email' => 'not-mail'], ['phone' => '070123456'], ['phone' => '+123x456789'], ['first_name' => ''], ['digital_access_mode' => 'manual_user'], ['preferred_locale' => 'de'], ['notes' => str_repeat('x', 2001)]] as $data) {
            $this->patchJson($url.'/'.$id, $data)->assertUnprocessable();
        }
        foreach (['organization_id', 'id', 'public_id', 'user_id', 'created_by', 'created_at', 'audit_metadata', 'status'] as $field) {
            $this->patchJson($url.'/'.$id, [$field => 'spoof'])->assertUnprocessable();
        }
        $this->postJson($url, ['first_name' => 'Test', 'last_name' => 'Only', 'organization_id' => 999])->assertUnprocessable();
        $this->patchJson($url.'/'.$id, ['email' => null, 'phone' => null])->assertOk()->assertJsonPath('data.email', null)->assertJsonPath('data.phone', null);
    }

    public function test_foreign_members_lists_guessed_ids_and_locations_fail_closed(): void
    {
        [$user, $org, , $url] = $this->setupOwner();
        $own = $this->createClient($url);
        $other = Organization::create(['name' => 'Other Fixture']);
        $foreignLocation = $this->location($other);
        $foreign = new StudioMember;
        $foreign->forceFill(['organization_id' => $other->id, 'first_name' => 'Foreign', 'last_name' => 'Fixture'])->save();
        $this->getJson($url)->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
        foreach ([$foreign->public_id, (string) Str::uuid7()] as $id) {
            $this->getJson($url.'/'.$id)->assertNotFound();
            $this->patchJson($url.'/'.$id, ['first_name' => 'stolen'])->assertNotFound();
            $this->patchJson($url.'/'.$id.'/status', ['status' => 'archived'])->assertNotFound();
        }
        $this->getJson($this->base($other).'/members')->assertNotFound();
        $this->patchJson($url.'/'.$own, ['preferred_location_id' => $foreignLocation->public_id])->assertNotFound();
        $this->postJson($url, ['first_name' => 'Test', 'last_name' => 'Only', 'preferred_location_id' => $foreignLocation->public_id])->assertNotFound();
        $this->postJson($url.'/search', ['preferred_location_id' => $foreignLocation->public_id])->assertNotFound();
    }

    public function test_location_preference_is_optional_and_new_assignments_require_active_location(): void
    {
        [, $org, , $url] = $this->setupOwner();
        $location = $this->location($org);
        $id = $this->createClient($url, ['preferred_location_id' => $location->public_id]);
        $this->getJson($url.'/'.$id)->assertJsonPath('data.preferred_location.id', $location->public_id);
        DB::table('locations')->where('id', $location->id)->update(['status' => 'inactive']);
        $this->patchJson($url.'/'.$id, ['preferred_location_id' => $location->public_id, 'first_name' => 'Renamed'])->assertOk();
        $second = $this->createClient($url);
        $this->patchJson($url.'/'.$second, ['preferred_location_id' => $location->public_id])->assertUnprocessable();
        $this->patchJson($url.'/'.$id, ['preferred_location_id' => null])->assertOk()->assertJsonPath('data.preferred_location', null);
        $this->assertDatabaseHas('audit_events', ['action' => 'member.preferred_location_changed']);
    }

    public function test_duplicate_warnings_allow_shared_contacts_and_never_cross_tenants(): void
    {
        [, $org, , $url] = $this->setupOwner();
        $first = $this->createClient($url, ['email' => 'family@example.test']);
        $this->postJson($url, ['first_name' => 'Another', 'last_name' => 'Fixture', 'email' => 'family@example.test'])->assertCreated()->assertJsonPath('meta.duplicate_warning', true)->assertJsonMissingPath('meta.duplicates');
        $this->postJson($url, ['first_name' => 'Тест', 'last_name' => 'Клиент'])->assertCreated()->assertJsonPath('meta.duplicate_warning', true);
        $this->patchJson($url.'/'.$first.'/status', ['status' => 'archived'])->assertOk();
        $this->postJson($url, ['first_name' => 'Different', 'last_name' => 'Fixture', 'email' => 'family@example.test'])->assertCreated()->assertJsonPath('meta.duplicate_warning', true);
        $other = Organization::create(['name' => 'Other']);
        $this->membership(auth()->user(), $other);
        $this->postJson($this->base($other).'/members', ['first_name' => 'Тест', 'last_name' => 'Клиент', 'email' => 'family@example.test'])->assertCreated()->assertJsonPath('meta.duplicate_warning', false);
    }

    public function test_status_lifecycle_archived_records_are_retained_and_require_deliberate_restore(): void
    {
        [, , , $url] = $this->setupOwner();
        $id = $this->createClient($url);
        foreach (['inactive', 'active', 'archived'] as $status) {
            $this->patchJson($url.'/'.$id.'/status', ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);
        }
        $this->getJson($url)->assertJsonCount(0, 'data');
        $this->getJson($url.'?status=archived')->assertJsonCount(1, 'data');
        $this->getJson($url.'/'.$id)->assertOk();
        $this->patchJson($url.'/'.$id, ['first_name' => 'Blocked'])->assertConflict();
        $this->patchJson($url.'/'.$id.'/status', ['status' => 'active'])->assertUnprocessable();
        $this->patchJson($url.'/'.$id.'/status', ['status' => 'deleted'])->assertUnprocessable();
        $this->patchJson($url.'/'.$id.'/status', ['status' => 'inactive', 'notes' => 'tamper'])->assertUnprocessable();
        $this->patchJson($url.'/'.$id.'/status', ['status' => 'inactive'])->assertOk();
        $this->patchJson($url.'/'.$id, ['first_name' => 'Restored'])->assertOk();
        $this->deleteJson($url.'/'.$id)->assertMethodNotAllowed();
        $this->assertDatabaseHas('studio_members', ['public_id' => $id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'member.status_changed', 'subject_id' => $id]);
    }

    public function test_admin_trainer_and_restricted_staff_permissions_are_separate(): void
    {
        [, $org, , $url] = $this->setupOwner();
        $id = $this->createClient($url);
        $admin = User::factory()->create();
        $this->membership($admin, $org, StaffRole::Administrator);
        $this->actingAs($admin)->getJson($url)->assertOk();
        $this->patchJson($url.'/'.$id, ['first_name' => 'Admin edit'])->assertOk();
        $trainer = User::factory()->create();
        $access = $this->membership($trainer, $org, StaffRole::Trainer);
        $this->actingAs($trainer)->getJson($url)->assertForbidden();
        $access->permissions = [...$access->permissions, 'members.view'];
        $access->save();
        $this->getJson($url)->assertOk();
        $this->getJson($url.'/'.$id)->assertJsonMissingPath('data.notes');
        $this->postJson($url, ['first_name' => 'Test', 'last_name' => 'Fixture'])->assertForbidden();
        $this->patchJson($url.'/'.$id, ['first_name' => 'No'])->assertForbidden();
        $this->patchJson($url.'/'.$id.'/status', ['status' => 'inactive'])->assertForbidden();
    }

    public function test_selected_location_staff_have_no_member_directory_even_with_all_member_grants(): void
    {
        [, $org, , $url] = $this->setupOwner();
        $id = $this->createClient($url);
        $actor = User::factory()->create();
        $this->membership($actor, $org, StaffRole::Administrator, StaffRole::Administrator->permissions(), 'selected');
        $this->actingAs($actor)->getJson($url)->assertForbidden();
        $this->postJson($url.'/search', ['search' => 'Тест'])->assertForbidden();
        $this->getJson($url.'/'.$id)->assertForbidden();
        $this->postJson($url, ['first_name' => 'Test', 'last_name' => 'Fixture'])->assertForbidden();
        $this->patchJson($url.'/'.$id, ['notes' => 'No'])->assertForbidden();
        $this->patchJson($url.'/'.$id, ['preferred_location_id' => null])->assertForbidden();
        $this->patchJson($url.'/'.$id.'/status', ['status' => 'inactive'])->assertForbidden();
    }

    public function test_notes_read_and_write_are_independent_and_absent_from_lists(): void
    {
        [, $org, , $url] = $this->setupOwner();
        $id = $this->createClient($url, ['notes' => 'Operational fixture only']);
        $staff = User::factory()->create();
        $access = $this->membership($staff, $org, StaffRole::Staff, ['studio.access', 'members.view', 'members.update']);
        $this->actingAs($staff)->getJson($url.'/'.$id)->assertOk()->assertJsonMissingPath('data.notes')->assertJsonMissingPath('data.preferred_location');
        $this->patchJson($url.'/'.$id, ['notes' => 'No'])->assertForbidden();
        $this->patchJson($url.'/'.$id, ['preferred_location_id' => null])->assertForbidden();
        $access->permissions = [...$access->permissions, 'members.notes.view'];
        $access->save();
        $this->getJson($url.'/'.$id)->assertJsonPath('data.notes', 'Operational fixture only');
        $this->getJson($url)->assertJsonMissingPath('data.0.notes');
        $this->patchJson($url.'/'.$id, ['notes' => null])->assertForbidden();
        $access->permissions = ['studio.access', 'members.view', 'members.update', 'members.notes.manage'];
        $access->save();
        $this->patchJson($url.'/'.$id, ['notes' => 'Blind authorized replacement'])->assertOk()->assertJsonMissingPath('data.notes');
    }

    public function test_search_is_bounded_literal_prefix_scoped_filtered_and_has_no_n_plus_one(): void
    {
        [, $org, , $url] = $this->setupOwner();
        $location = $this->location($org);
        for ($i = 0; $i < 12; $i++) {
            $this->createClient($url, ['first_name' => 'Example'.$i, 'last_name' => 'Fixture', 'email' => 'fixture'.$i.'@example.test', 'phone' => '+120255501'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'preferred_location_id' => $location->public_id, 'digital_access_mode' => 'assisted']);
        }
        foreach (['example', 'fixture', 'fixture1@', '+1 (202) 555-01'] as $search) {
            $this->postJson($url.'/search', ['search' => $search])->assertOk()->assertJsonCount($search === 'fixture1@' ? 1 : 12, 'data');
        }
        $this->postJson($url.'/search', ['search' => 'ex%'])->assertJsonCount(0, 'data');
        $this->postJson($url.'/search', ['search' => 'x'])->assertUnprocessable();
        $this->postJson($url.'/search', ['search' => str_repeat('x', 101)])->assertUnprocessable();
        $this->getJson($url.'?search=example')->assertUnprocessable();
        $this->postJson($url.'/search', ['per_page' => 101])->assertUnprocessable();
        $this->postJson($url.'/search', ['digital_access_mode' => 'offline'])->assertJsonCount(0, 'data');
        $this->postJson($url.'/search', ['preferred_location_id' => $location->public_id, 'page' => 2, 'per_page' => 5])->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 12);
        DB::enableQueryLog();
        $this->getJson($url)->assertJsonCount(12, 'data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $locations = array_filter($queries, fn ($query) => str_contains($query['query'], 'from "locations"'));
        $this->assertCount(1, $locations, 'One eager location query for the whole page.');
    }

    public function test_audit_excludes_profile_pii_and_notes_and_failures_are_atomic_and_redacted(): void
    {
        [$user, $org, , $url] = $this->setupOwner();
        $pii = ['first_name' => 'PrivateFixtureName', 'last_name' => 'PrivateFixtureSurname', 'email' => 'private-fixture@example.test', 'phone' => '+12025550199', 'notes' => 'Private fixture operational note'];
        $id = $this->createClient($url, $pii);
        $this->patchJson($url.'/'.$id, ['first_name' => 'NewPrivateName', 'notes' => 'New private note', 'digital_access_mode' => 'assisted'])->assertOk();
        $metadata = DB::table('audit_events')->where('subject_id', $id)->pluck('metadata')->implode(' ');
        foreach ([...array_values($pii), 'NewPrivateName', 'New private note'] as $value) {
            $this->assertStringNotContainsString($value, $metadata);
        }
        foreach (['member.created', 'member.profile_changed', 'member.notes_changed', 'member.digital_access_changed'] as $action) {
            $this->assertDatabaseHas('audit_events', ['action' => $action, 'subject_id' => $id]);
        }
        Log::spy();
        DB::unprepared("CREATE OR REPLACE FUNCTION gt_test_member_audit_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'PII fixture must not be logged'; END $$; CREATE TRIGGER gt_test_member_fail BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION gt_test_member_audit_fail();");
        $response = $this->patchJson($url.'/'.$id, ['first_name' => 'MustRollback']);
        $response->assertStatus(500);
        $this->assertStringNotContainsString('MustRollback', $response->getContent());
        $this->assertDatabaseHas('studio_members', ['public_id' => $id, 'first_name' => 'NewPrivateName']);
        Log::shouldHaveReceived('error')->with('Unhandled application exception', ['type' => QueryException::class])->once();
    }

    public function test_nonactive_organization_or_staff_and_stale_context_cannot_access_members(): void
    {
        [$user, $org, $access, $url] = $this->setupOwner();
        $id = $this->createClient($url);
        foreach ([MembershipStatus::Suspended, MembershipStatus::Revoked] as $status) {
            $access->status = $status;
            $access->save();
            $this->getJson($url)->assertNotFound();
            $this->patchJson($url.'/'.$id, ['first_name' => 'No'])->assertNotFound();
        }
        $access->status = MembershipStatus::Active;
        $access->save();
        $org->status = OrganizationStatus::Suspended;
        $org->save();
        $this->getJson($url)->assertNotFound();
        $this->postJson($url, ['first_name' => 'No', 'last_name' => 'Fixture'])->assertNotFound();
        $org->status = OrganizationStatus::Active;
        $org->save();
        $context = app(TenantContext::class);
        $context->resolve($user, $org->public_id);
        $access->status = MembershipStatus::Revoked;
        $access->save();
        $this->expectException(ModelNotFoundException::class);
        MemberLifecycle::save($context, $user, $id, ['first_name' => 'Stale']);
    }

    public function test_unauthenticated_and_bearer_permissions_are_enforced(): void
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Fixture']);
        $this->membership($user, $org);
        $url = $this->base($org).'/members';
        $this->getJson($url)->assertUnauthorized();
        $this->postJson($url, ['first_name' => 'Test', 'last_name' => 'Fixture'])->assertUnauthorized();
        $token = $user->createToken('Read only fixture', ['studio.access', 'members.view'])->plainTextToken;
        $this->withToken($token)->getJson($url)->assertOk();
        $this->postJson($url, ['first_name' => 'Test', 'last_name' => 'Fixture'])->assertForbidden();
    }

    public function test_postgresql_constraints_reject_invalid_ownership_enums_and_identifiers(): void
    {
        [, $org, , $url] = $this->setupOwner();
        $id = $this->createClient($url);
        $member = StudioMember::query()->where('public_id', $id)->firstOrFail();
        $second = $this->createClient($url, ['first_name' => 'Second']);
        $foreign = $this->location(Organization::create(['name' => 'Other']));
        $operations = [
            fn () => DB::table('studio_members')->where('id', $member->id)->update(['preferred_location_id' => $foreign->id]),
            fn () => DB::table('studio_members')->where('id', $member->id)->update(['organization_id' => 999999]),
            fn () => DB::table('studio_members')->where('id', $member->id)->update(['status' => 'deleted']),
            fn () => DB::table('studio_members')->where('id', $member->id)->update(['digital_access_mode' => 'manual_user']),
            fn () => DB::table('studio_members')->where('id', $member->id)->update(['preferred_locale' => 'de']),
            fn () => DB::table('studio_members')->where('id', $member->id)->update(['email' => 'NOTCANONICAL@EXAMPLE.TEST']),
            fn () => DB::table('studio_members')->where('id', $member->id)->update(['phone' => '070123']),
            fn () => DB::table('studio_members')->where('public_id', $second)->update(['public_id' => $id]),
            fn () => DB::table('audit_events')->where('subject_id', $id)->update(['action' => 'tamper']),
            fn () => DB::table('audit_events')->where('subject_id', $id)->delete(),
            fn () => DB::statement('TRUNCATE audit_events'),
        ];
        foreach ($operations as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Invalid database write accepted');
            } catch (QueryException $exception) {
                $this->assertContains($exception->getCode(), ['23514', '23503', '23505']);
            }
        }
    }

    public function test_optional_user_linkage_does_not_grant_staff_access_and_is_unique_per_tenant(): void
    {
        [, $org, , $url] = $this->setupOwner();
        $id = $this->createClient($url);
        $second = $this->createClient($url, ['first_name' => 'Second']);
        $customer = User::factory()->create();
        DB::table('studio_members')->where('public_id', $id)->update(['user_id' => $customer->id]);
        $this->actingAs($customer)->getJson($url)->assertNotFound();
        $this->getJson('/api/v1/me')->assertJsonCount(0, 'data.organizations');
        $this->expectException(QueryException::class);
        DB::table('studio_members')->where('public_id', $second)->update(['user_id' => $customer->id]);
    }

    public function test_operator_adoption_is_explicit_scoped_and_audited(): void
    {
        [, $org, $access] = $this->setupOwner();
        $legacy = array_values(array_diff($access->permissions, StaffRole::memberPermissions()));
        $access->permissions = $legacy;
        $access->save();
        $this->getJson($this->base($org).'/members')->assertForbidden();
        $this->artisan('gt:member-access-enable', ['organization' => $org->public_id, 'membership' => $access->public_id, '--reason' => 'GT-003 fixture adoption'])->assertSuccessful();
        $this->getJson($this->base($org).'/members')->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.permissions_changed', 'actor_id' => null]);
    }
}
