<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ScheduleRule;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Support\StaffRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

class TrainingGroupTest extends TestCase
{
    use OrganizationFixtures, RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(now()->setDate(2026, 9, 19)->startOfDay());
        $org = Organization::create(['name' => 'Group fixture']);
        $actor = User::factory()->create();
        $access = $this->membership($actor, $org);
        $location = $this->location($org);
        $this->actingAs($actor);
        $trainerId = $this->postJson($this->base($org).'/trainers', ['membership_id' => $access->public_id])->assertCreated()->json('data.id');

        return [$org, $actor, $access, $location, $trainerId, $this->base($org).'/groups'];
    }

    private function group(string $url, string $location, ?string $trainer = null): string
    {
        return $this->postJson($url, ['name' => 'Pilates fixture', 'location_id' => $location, 'primary_trainer_id' => $trainer, 'capacity' => 12])->assertCreated()->json('data.id');
    }

    private function rule(array $overrides = []): array
    {
        return [...['weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00', 'effective_from' => '2026-09-21', 'effective_until' => null], ...$overrides];
    }

    public function test_group_creation_lifecycle_capacity_and_audit_without_description_values(): void
    {
        [$org, , , $loc, $trainer, $url] = $this->fixture();
        $id = $this->group($url, $loc->public_id);
        $this->getJson($url.'/'.$id)->assertOk()->assertJsonPath('data.operational_issues', ['trainer_unassigned'])->assertJsonPath('data.eligible_for_scheduling', false);
        $this->patchJson($url.'/'.$id, ['primary_trainer_id' => $trainer, 'capacity' => 1, 'description' => 'Private group description'])->assertOk()->assertJsonPath('data.eligible_for_scheduling', true);
        $this->patchJson($url.'/'.$id, ['capacity' => 100])->assertOk();
        foreach ([0, -1, 101, 1.5] as $capacity) {
            $this->patchJson($url.'/'.$id, ['capacity' => $capacity])->assertUnprocessable();
        }
        $this->postJson($url.'/'.$id.'/schedule', $this->rule())->assertCreated();
        foreach (['inactive', 'archived'] as $status) {
            $this->patchJson($url.'/'.$id.'/status', ['status' => $status])->assertOk()->assertJsonPath('data.eligible_for_scheduling', false);
        }
        $this->getJson($url.'/'.$id.'/schedule')->assertJsonCount(1, 'data');
        $this->patchJson($url.'/'.$id, ['name' => 'No'])->assertConflict();
        $this->patchJson($url.'/'.$id.'/status', ['status' => 'active'])->assertUnprocessable();
        $this->patchJson($url.'/'.$id.'/status', ['status' => 'inactive'])->assertOk();
        $this->patchJson($url.'/'.$id.'/status', ['status' => 'active'])->assertOk();
        $this->deleteJson($url.'/'.$id)->assertMethodNotAllowed();
        foreach (['group.created', 'group.profile_updated', 'group.capacity_changed', 'group.trainer_changed', 'group.status_changed', 'schedule.rule_created'] as $action) {
            $this->assertDatabaseHas('audit_events', ['action' => $action]);
        }
        $this->assertStringNotContainsString('Private group description', DB::table('audit_events')->pluck('metadata')->implode(' '));
        $this->assertDatabaseCount('studio_members', 0);
    }

    public function test_assignment_and_location_validation_are_authoritative(): void
    {
        [$org, , $access, $loc, $trainer, $url] = $this->fixture();
        $foreign = Organization::create(['name' => 'Foreign']);
        $foreignLoc = $this->location($foreign);
        $data = ['name' => 'Fixture', 'capacity' => 12, 'location_id' => $foreignLoc->public_id];
        $this->postJson($url, $data)->assertNotFound();
        $data['location_id'] = $loc->public_id;
        DB::table('locations')->where('id', $loc->id)->update(['status' => 'inactive']);
        $this->postJson($url, $data)->assertUnprocessable();
        DB::table('locations')->where('id', $loc->id)->update(['status' => 'active']);
        $this->postJson($url, [...$data, 'primary_trainer_id' => (string) Str::uuid7()])->assertNotFound();
        DB::table('trainer_profiles')->where('public_id', $trainer)->update(['status' => 'inactive']);
        $this->postJson($url, [...$data, 'primary_trainer_id' => $trainer])->assertUnprocessable();
        DB::table('trainer_profiles')->where('public_id', $trainer)->update(['status' => 'active']);
        $target = $this->membership(User::factory()->create(), $org, StaffRole::Staff, null, 'selected');
        $targetId = $this->postJson($this->base($org).'/trainers', ['membership_id' => $target->public_id])->json('data.id');
        $this->postJson($url, [...$data, 'primary_trainer_id' => $targetId])->assertUnprocessable();
        DB::table('membership_locations')->insert(['organization_id' => $org->id, 'organization_membership_id' => $target->id, 'location_id' => $loc->id]);
        $this->postJson($url, [...$data, 'primary_trainer_id' => $targetId])->assertCreated();
        foreach (['suspended', 'revoked'] as $status) {
            DB::table('organization_memberships')->where('id', $target->id)->update(['status' => $status]);
            $this->postJson($url, [...$data, 'primary_trainer_id' => $targetId])->assertUnprocessable();
        }
    }

    public function test_scoped_reads_writes_filters_and_serialization(): void
    {
        [$org, , , $a, $trainer, $url] = $this->fixture();
        $b = $this->location($org, 'Other branch');
        $own = $this->group($url, $a->public_id, $trainer);
        $outside = $this->group($url, $b->public_id, $trainer);
        $this->postJson($url.'/'.$own.'/schedule', $this->rule())->assertCreated();
        $viewer = User::factory()->create();
        $staff = $this->membership($viewer, $org, StaffRole::Staff, ['studio.access', 'groups.view', 'groups.manage', 'schedule.view', 'schedule.manage'], 'selected');
        DB::table('membership_locations')->insert(['organization_id' => $org->id, 'organization_membership_id' => $staff->id, 'location_id' => $a->id]);
        $this->actingAs($viewer)->getJson($url)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own)->assertJsonCount(1, 'data.0.schedule_summary');
        foreach ([$outside, (string) Str::uuid7()] as $id) {
            $this->getJson($url.'/'.$id)->assertNotFound();
            $this->patchJson($url.'/'.$id, ['capacity' => 9])->assertNotFound();
            $this->getJson($url.'/'.$id.'/schedule')->assertNotFound();
            $this->postJson($url.'/'.$id.'/schedule', $this->rule())->assertNotFound();
        }
        $this->patchJson($url.'/'.$own, ['location_id' => $b->public_id])->assertNotFound();
        $this->postJson($url.'/search', ['location_id' => $b->public_id])->assertNotFound();
        $this->patchJson($url.'/'.$own, ['capacity' => 9])->assertOk();
        $this->postJson($url.'/'.$own.'/schedule', $this->rule(['weekday' => 2]))->assertCreated();
        $response = $this->getJson($url.'/'.$own)->assertOk();
        foreach (['organization_id', 'location_id', 'primary_trainer.membership_id', 'primary_trainer.staff_role', 'primary_trainer.email', 'primary_trainer.permissions'] as $key) {
            $response->assertJsonMissingPath('data.'.$key);
        }
        $this->getJson($this->base($org).'/members')->assertForbidden();
    }

    public function test_mass_assignment_and_independent_permissions(): void
    {
        [$org, $actor, $access, $loc, $trainer, $url] = $this->fixture();
        $id = $this->group($url, $loc->public_id, $trainer);
        foreach (['id', 'public_id', 'organization_id', 'status', 'permissions', 'created_at'] as $key) {
            $this->patchJson($url.'/'.$id, [$key => 'x'])->assertUnprocessable();
        }
        $access->permissions = ['studio.access', ...StaffRole::ownerMinimum(), 'groups.view'];
        $access->save();
        $this->getJson($url.'/'.$id)->assertOk()->assertJsonMissingPath('data.schedule_summary');
        $this->patchJson($url.'/'.$id, ['capacity' => 5])->assertForbidden();
        $this->getJson($url.'/'.$id.'/schedule')->assertForbidden();
        $this->artisan('gt:group-access-enable', ['organization' => $org->public_id, 'membership' => $access->public_id, '--reason' => 'Fixture adoption'])->assertSuccessful();
        $this->postJson($url.'/'.$id.'/schedule', $this->rule())->assertCreated();
        $this->assertDatabaseCount('training_groups', 1);
        $this->assertDatabaseCount('schedule_rules', 1);
    }

    public function test_schedule_time_period_validation_duplicates_and_overlaps(): void
    {
        [, , , $loc, $trainer, $url] = $this->fixture();
        $id = $this->group($url, $loc->public_id, $trainer);
        $schedule = $url.'/'.$id.'/schedule';
        foreach ([['weekday' => 0], ['weekday' => 8], ['start_time' => '19:00'], ['end_time' => '17:00'], ['end_time' => '23:00'], ['start_time' => '25:00'], ['effective_until' => '2026-09-20'], ['effective_from' => '2026-09-18'], ['organization_id' => 2]] as $bad) {
            $this->postJson($schedule, $this->rule($bad))->assertUnprocessable();
        }
        $this->postJson($schedule, $this->rule())->assertCreated();
        $this->postJson($schedule, $this->rule())->assertConflict();
        $this->postJson($schedule, $this->rule(['start_time' => '18:30', 'end_time' => '19:30']))->assertConflict();
        $this->postJson($schedule, $this->rule(['start_time' => '19:00', 'end_time' => '20:00']))->assertCreated();
        $this->postJson($schedule, $this->rule(['weekday' => 3]))->assertCreated();
        $this->getJson($schedule)->assertJsonPath('data.0.start_time', '18:00')->assertJsonPath('data.1.start_time', '19:00')->assertJsonPath('data.2.weekday', 3)->assertJsonPath('meta.timezone', 'Europe/Skopje');
    }

    public function test_trainer_conflicts_group_activation_and_reassignment_are_serialized_checks(): void
    {
        [$org, , , $loc, $trainer, $url] = $this->fixture();
        $a = $this->group($url, $loc->public_id, $trainer);
        $b = $this->group($url, $loc->public_id);
        $this->postJson($url.'/'.$a.'/schedule', $this->rule())->assertCreated();
        $this->postJson($url.'/'.$b.'/schedule', $this->rule())->assertCreated(); // Location is not a room.
        $this->patchJson($url.'/'.$b, ['primary_trainer_id' => $trainer])->assertConflict();
        $this->patchJson($url.'/'.$b.'/status', ['status' => 'inactive'])->assertOk();
        $this->patchJson($url.'/'.$b, ['primary_trainer_id' => $trainer])->assertOk();
        $this->patchJson($url.'/'.$b.'/status', ['status' => 'active'])->assertConflict();
        $c = $this->group($url, $loc->public_id, $trainer);
        $this->postJson($url.'/'.$c.'/schedule', $this->rule(['start_time' => '18:30', 'end_time' => '19:30']))->assertConflict();
        $this->postJson($url.'/'.$c.'/schedule', $this->rule(['effective_from' => '2026-09-19', 'effective_until' => '2026-09-20']))->assertCreated();
        $this->patchJson($this->base($org), ['timezone' => 'America/Toronto'])->assertConflict();
    }

    public function test_future_replacement_closing_canceling_and_rollback_preserve_history(): void
    {
        [, , , $loc, $trainer, $url] = $this->fixture();
        $id = $this->group($url, $loc->public_id, $trainer);
        $schedule = $url.'/'.$id.'/schedule';
        $old = $this->postJson($schedule, $this->rule())->assertCreated()->json('data.id');
        $new = $this->postJson($schedule.'/'.$old.'/replace', $this->rule(['effective_from' => '2026-11-01', 'start_time' => '19:00', 'end_time' => '20:00']))->assertCreated()->json('data.id');
        $this->assertDatabaseHas('schedule_rules', ['public_id' => $old, 'effective_until' => '2026-10-31', 'start_minute' => 1080]);
        $this->assertDatabaseHas('schedule_rules', ['public_id' => $new, 'effective_from' => '2026-11-01', 'start_minute' => 1140]);
        $this->postJson($schedule.'/'.$old.'/replace', $this->rule(['effective_from' => '2026-12-01']))->assertConflict();
        $this->patchJson($schedule.'/'.$new.'/close', ['effective_until' => '2026-12-31'])->assertOk();
        $this->patchJson($schedule.'/'.$new.'/close', ['effective_until' => '2027-01-31'])->assertUnprocessable();
        $this->postJson($schedule.'/'.$new.'/cancel')->assertOk();
        $this->postJson($schedule, $this->rule(['effective_from' => '2026-11-01', 'start_time' => '19:00', 'end_time' => '20:00']))->assertCreated();
        $this->travelTo(now()->setDate(2026, 10, 1));
        $this->postJson($schedule.'/'.$old.'/cancel')->assertUnprocessable();
        $this->patchJson($schedule.'/'.$old.'/close', ['effective_until' => '2026-09-30'])->assertUnprocessable();
        foreach (['schedule.rule_closed', 'schedule.rule_replaced', 'schedule.rule_canceled'] as $action) {
            $this->assertDatabaseHas('audit_events', ['action' => $action]);
        }
        $this->patchJson($schedule.'/'.$old, ['start_time' => '15:00'])->assertNotFound();
    }

    public function test_operational_invalidity_follows_source_state_without_destroying_history(): void
    {
        [$org, , , $loc, , $url] = $this->fixture();
        $staff = $this->membership(User::factory()->create(), $org, StaffRole::Staff, null, 'selected');
        DB::table('membership_locations')->insert(['organization_id' => $org->id, 'organization_membership_id' => $staff->id, 'location_id' => $loc->id]);
        $trainer = $this->postJson($this->base($org).'/trainers', ['membership_id' => $staff->public_id])->json('data.id');
        $id = $this->group($url, $loc->public_id, $trainer);
        $this->postJson($url.'/'.$id.'/schedule', $this->rule())->assertCreated();
        $this->patchJson($this->base($org).'/trainers/'.$trainer.'/status', ['status' => 'inactive'])->assertOk();
        $this->getJson($url.'/'.$id)->assertJsonPath('data.operational_issues', ['trainer_unavailable']);
        $this->postJson($url.'/'.$id.'/schedule', $this->rule(['weekday' => 3]))->assertUnprocessable();
        $this->patchJson($this->base($org).'/trainers/'.$trainer.'/status', ['status' => 'active'])->assertOk();
        foreach (['suspended', 'revoked'] as $status) {
            $this->patchJson($this->base($org).'/staff/'.$staff->public_id, ['status' => $status])->assertOk();
            $this->getJson($url.'/'.$id)->assertJsonPath('data.operational_issues', ['trainer_unavailable']);
        }
        DB::table('organization_memberships')->where('id', $staff->id)->update(['status' => 'active']);
        DB::table('membership_locations')->where('organization_membership_id', $staff->id)->delete();
        $this->getJson($url.'/'.$id)->assertJsonPath('data.operational_issues', ['trainer_unavailable']);
        $this->patchJson($this->base($org).'/locations/'.$loc->public_id, ['status' => 'inactive'])->assertOk();
        $this->getJson($url.'/'.$id)->assertJsonPath('data.operational_issues', ['location_inactive', 'trainer_unavailable']);
        $this->getJson($url.'/'.$id.'/schedule')->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('training_groups', 1);
    }

    public function test_postgresql_constraints_prevent_invalid_links_capacity_dates_and_overlap(): void
    {
        [$org, , , $loc, $trainer, $url] = $this->fixture();
        $id = $this->group($url, $loc->public_id, $trainer);
        $this->postJson($url.'/'.$id.'/schedule', $this->rule())->assertCreated();
        $group = TrainingGroup::where('public_id', $id)->firstOrFail();
        $rule = ScheduleRule::firstOrFail();
        $foreignLoc = $this->location(Organization::create(['name' => 'Foreign']));
        $operations = [
            fn () => DB::table('training_groups')->where('id', $group->id)->update(['location_id' => $foreignLoc->id]),
            fn () => DB::table('training_groups')->where('id', $group->id)->update(['capacity' => 0]),
            fn () => DB::table('training_groups')->where('id', $group->id)->update(['status' => 'bad']),
            fn () => DB::table('schedule_rules')->where('id', $rule->id)->update(['weekday' => 8]),
            fn () => DB::table('schedule_rules')->where('id', $rule->id)->update(['end_minute' => 1080]),
            fn () => DB::table('schedule_rules')->where('id', $rule->id)->update(['effective_until' => '2026-09-01']),
            fn () => DB::table('schedule_rules')->insert([...$rule->getAttributes(), 'id' => $rule->id + 100, 'public_id' => (string) Str::uuid7(), 'start_minute' => 1090]),
            fn () => DB::table('audit_events')->update(['action' => 'tamper']),
        ];
        foreach ($operations as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Invalid direct SQL accepted');
            } catch (QueryException $e) {
                $this->assertContains($e->getCode(), ['23503', '23514', '23P01', '22000']);
            }
        }
    }

    public function test_foreign_group_trainer_and_rule_ids_fail_closed_and_source_assignment_changes_are_audited(): void
    {
        [$org, $actor, , $location, $trainer, $url] = $this->fixture();
        $own = $this->group($url, $location->public_id, $trainer);
        $foreign = Organization::create(['name' => 'Foreign fixture']);
        $otherActor = User::factory()->create();
        $access = $this->membership($otherActor, $foreign);
        $otherLoc = $this->location($foreign);
        $this->actingAs($otherActor);
        $otherTrainer = $this->postJson($this->base($foreign).'/trainers', ['membership_id' => $access->public_id])->json('data.id');
        $otherGroup = $this->group($this->base($foreign).'/groups', $otherLoc->public_id, $otherTrainer);
        $otherRule = $this->postJson($this->base($foreign).'/groups/'.$otherGroup.'/schedule', $this->rule())->json('data.id');
        $this->actingAs($actor);
        $this->getJson($url.'/'.$otherGroup)->assertNotFound();
        $this->patchJson($url.'/'.$own, ['primary_trainer_id' => $otherTrainer])->assertNotFound();
        $this->postJson($url.'/'.$own.'/schedule/'.$otherRule.'/replace', $this->rule(['effective_from' => '2026-11-01']))->assertNotFound();
        $this->patchJson($url.'/'.$own.'/schedule/'.$otherRule.'/close', ['effective_until' => '2026-11-01'])->assertNotFound();
        $this->postJson($url.'/search', ['trainer_id' => $otherTrainer])->assertNotFound();
        $next = $this->location($org, 'New roomless location');
        $this->patchJson($url.'/'.$own, ['location_id' => $next->public_id])->assertOk()->assertJsonPath('data.location.id', $next->public_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'group.location_changed', 'subject_id' => $own]);
        $this->getJson($url)->assertJsonCount(1, 'data');
    }

    public function test_replacement_conflict_and_audit_failure_roll_back_the_entire_operation(): void
    {
        [$org, , , $loc, $trainer, $url] = $this->fixture();
        $a = $this->group($url, $loc->public_id, $trainer);
        $b = $this->group($url, $loc->public_id, $trainer);
        $rule = $this->postJson($url.'/'.$a.'/schedule', $this->rule())->json('data.id');
        $this->postJson($url.'/'.$b.'/schedule', $this->rule(['weekday' => 3]))->assertCreated();
        $events = DB::table('audit_events')->count();
        $this->postJson($url.'/'.$a.'/schedule/'.$rule.'/replace', $this->rule(['weekday' => 3, 'effective_from' => '2026-11-01']))->assertConflict();
        $this->assertDatabaseHas('schedule_rules', ['public_id' => $rule, 'effective_until' => null]);
        $this->assertDatabaseCount('audit_events', $events);
        $this->assertDatabaseCount('schedule_rules', 2);
        DB::unprepared("CREATE FUNCTION gt_test_schedule_audit_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Fixture'; END $$; CREATE TRIGGER gt_test_schedule_fail BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION gt_test_schedule_audit_fail();");
        $this->postJson($url.'/'.$a.'/schedule', $this->rule(['weekday' => 5]))->assertStatus(500);
        $this->assertDatabaseCount('schedule_rules', 2);
        $this->patchJson($url.'/'.$a, ['capacity' => 20])->assertStatus(500);
        $this->assertDatabaseHas('training_groups', ['public_id' => $a, 'capacity' => 12]);
    }

    public function test_independent_write_permissions_and_nonactive_actors_cannot_write_or_resolve_groups(): void
    {
        [$org, $actor, $access, $loc, $trainer, $url] = $this->fixture();
        $id = $this->group($url, $loc->public_id, $trainer);
        $access->permissions = array_values(array_diff($access->permissions, ['groups.manage', 'schedule.manage']));
        $access->save();
        $this->postJson($url.'/'.$id.'/schedule', $this->rule())->assertForbidden();
        $this->patchJson($url.'/'.$id.'/status', ['status' => 'inactive'])->assertForbidden();
        foreach (['suspended', 'revoked'] as $status) {
            DB::table('organization_memberships')->where('id', $access->id)->update(['status' => $status]);
            $this->getJson($url.'/'.$id)->assertNotFound();
        }
        DB::table('organization_memberships')->where('id', $access->id)->update(['status' => 'active']);
        DB::table('organizations')->where('id', $org->id)->update(['status' => 'suspended']);
        $this->getJson($url)->assertNotFound();
    }
}
