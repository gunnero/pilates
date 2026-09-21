<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ScheduleRule;
use App\Models\TrainerProfile;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\SessionMaterializer;
use App\Support\SessionTime;
use App\Support\StaffRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

class TrainingSessionTest extends TestCase
{
    use RefreshDatabase,SessionFixtures;

    public function test_materialization_boundaries_multiple_days_idempotency_and_snapshots(): void
    {
        [$org,,,$loc,$trainer,$g,$r] = $this->sessionFixture();
        $wed = $r->replicate();
        $wed->public_id = (string) Str::uuid7();
        $wed->weekday = 3;
        $wed->effective_from = '2026-09-23';
        $wed->effective_until = '2026-09-30';
        $wed->save();
        $a = SessionMaterializer::run($org->id, $g->public_id);
        $this->assertSame(10, $a['created']);
        $this->assertSame(10, SessionMaterializer::run($org->id, $g->public_id)['existing']);
        $this->assertDatabaseCount('training_sessions', 10);
        $this->assertDatabaseCount('audit_events', 1);
        $first = TrainingSession::where('local_date', '2026-09-21')->firstOrFail();
        $this->assertSame('16:00', $first->starts_at->format('H:i'));
        $this->assertSame('17:00', TrainingSession::where('local_date', '2026-10-26')->firstOrFail()->starts_at->format('H:i'));
        $g->capacity = 15;
        $g->name = 'Renamed';
        $g->save();
        $newStaff = $this->membership(User::factory()->create(), $org);
        $newTrainer = $trainer->replicate();
        $newTrainer->public_id = (string) Str::uuid7();
        $newTrainer->organization_membership_id = $newStaff->id;
        $newTrainer->save();
        $g->primary_trainer_id = $newTrainer->id;
        $g->save();
        $this->travelTo(now()->addWeek());
        SessionMaterializer::run($org->id, $g->public_id);
        $this->assertSame(12, $first->fresh()->capacity);
        $this->assertSame($trainer->id, $first->fresh()->trainer_profile_id);
        $this->assertSame('Pilates', $first->fresh()->group_name);
        $this->assertDatabaseHas('training_sessions', ['local_date' => '2026-11-16', 'capacity' => 15, 'trainer_profile_id' => $newTrainer->id]);
        $this->assertSame(11, TrainingSession::count());
    }

    public function test_dst_rejects_gap_and_fold_and_resolves_normal_offsets(): void
    {
        foreach ([['2026-03-29', '02:30'], ['2026-10-25', '02:30']] as [$date,$time]) {
            try {
                SessionTime::resolve($date, $time, 'Europe/Skopje');
                $this->fail('DST ambiguity accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('time', $e->errors());
            }
        }
        $this->assertSame('2026-03-22T17:00:00+00:00', SessionTime::resolve('2026-03-22', '18:00', 'Europe/Skopje')->toIso8601String());
        $this->assertSame('2026-03-29T16:00:00+00:00', SessionTime::resolve('2026-03-29', '18:00', 'Europe/Skopje')->toIso8601String());
        $this->assertSame('2026-09-21T18:00:00+00:00', SessionTime::resolve('2026-09-21', '18:00', 'UTC')->toIso8601String());
        [$org,,,,,$g,$r] = $this->sessionFixture();
        $r->weekday = 7;
        $r->start_minute = 150;
        $r->end_minute = 210;
        $r->save();
        $result = SessionMaterializer::run($org->id, $g->public_id);
        $this->assertSame(1, $result['dst']);
        $this->assertDatabaseMissing('training_sessions', ['local_date' => '2026-10-25']);
    }

    public function test_invalid_source_state_skips_generation_and_preserves_sessions(): void
    {
        [$org,,$access,$loc,$trainer,$g,$r] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $access->role = StaffRole::Administrator;
        $access->save();
        foreach ([['training_groups', 'status', 'inactive', $g->id], ['training_groups', 'status', 'archived', $g->id], ['locations', 'status', 'inactive', $loc->id], ['trainer_profiles', 'status', 'inactive', $trainer->id], ['organization_memberships', 'status', 'suspended', $access->id], ['organization_memberships', 'status', 'revoked', $access->id], ['organization_memberships', 'location_scope', 'selected', $access->id]] as [$table,$field,$value,$id]) {
            $before = DB::table($table)->where('id', $id)->value($field);
            DB::table($table)->where('id', $id)->update([$field => $value]);
            $this->assertSame(1, SessionMaterializer::run($org->id, $g->public_id)['invalid']);
            DB::table($table)->where('id', $id)->update([$field => $before]);
        }
        $g->primary_trainer_id = null;
        $g->save();
        $this->assertSame(1, SessionMaterializer::run($org->id, $g->public_id)['invalid']);
        $g->primary_trainer_id = $trainer->id;
        $g->save();
        $r->canceled_at = now();
        $r->save();
        $this->assertSame(0, SessionMaterializer::run($org->id, $g->public_id)['considered']);
        $this->assertDatabaseCount('training_sessions', 8);
    }

    public function test_manual_override_cancel_and_audit_privacy(): void
    {
        [$org,,,$loc,$trainer,$g] = $this->sessionFixture();
        $url = $this->base($org).'/sessions';
        $data = $this->sessionData($g, $loc, $trainer);
        $id = $this->postJson($url, $data)->assertCreated()->assertJsonPath('data.source_type', 'manual')->json('data.id');
        unset($data['group_id']);
        $data['capacity'] = 10;
        $data['start_time'] = '19:00';
        $data['end_time'] = '20:00';
        $this->patchJson($url.'/'.$id, $data)->assertOk()->assertJsonPath('data.is_overridden', true)->assertJsonPath('data.capacity', 10);
        $this->postJson($url.'/'.$id.'/cancel', ['reason' => str_repeat('x', 301)])->assertUnprocessable();
        $this->postJson($url.'/'.$id.'/cancel', ['reason' => 'Private explanation'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson($url.'/'.$id.'/cancel')->assertOk();
        $this->patchJson($url.'/'.$id, $data)->assertConflict();
        $this->postJson($url.'/'.$id.'/restore')->assertNotFound();
        $this->assertDatabaseCount('audit_events', 3);
        $this->assertStringNotContainsString('Private explanation', DB::table('audit_events')->pluck('metadata')->implode(' '));
        $this->assertDatabaseCount('schedule_rules', 1);
        $this->deleteJson($url.'/'.$id)->assertMethodNotAllowed();
    }

    public function test_cancelled_recurring_identity_survives_generation(): void
    {
        [$org,,,,,$g] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $s = TrainingSession::firstOrFail();
        $this->postJson($this->base($org).'/sessions/'.$s->public_id.'/cancel')->assertOk();
        $this->assertSame(8, SessionMaterializer::run($org->id, $g->public_id)['existing']);
        $this->assertDatabaseCount('training_sessions', 8);
        $this->assertSame('cancelled', $s->fresh()->status);
    }

    public function test_schedule_replacement_reconciles_future_only_and_retains_source_evidence(): void
    {
        [$org,,,,,$g,$r] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $before = TrainingSession::where('local_date', '<', '2026-10-19')->orderBy('id')->get()->toJson();
        $this->postJson($this->base($org).'/groups/'.$g->public_id.'/schedule/'.$r->public_id.'/replace', ['weekday' => 1, 'start_time' => '19:00', 'end_time' => '20:00', 'effective_from' => '2026-10-19'])->assertCreated();
        $this->assertSame($before, TrainingSession::where('local_date', '<', '2026-10-19')->orderBy('id')->get()->toJson());
        $this->assertSame(4, TrainingSession::where('status', 'cancelled')->where('cancellation_kind', 'schedule')->count());
        $this->assertSame(8, TrainingSession::where('status', 'scheduled')->count());
        $this->assertDatabaseHas('training_sessions', ['local_date' => '2026-10-19', 'status' => 'scheduled', 'starts_at' => '2026-10-19 17:00:00+00']);
        $this->assertSame(0, SessionMaterializer::run($org->id, $g->public_id)['created']);
    }

    public function test_reconciliation_protects_manual_exceptions_and_rolls_back(): void
    {
        [$org,,,$loc,$trainer,$g,$r] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $s = TrainingSession::where('local_date', '2026-10-19')->firstOrFail();
        $data = $this->sessionData($g, $loc, $trainer, ['local_date' => '2026-10-19', 'capacity' => 10]);
        unset($data['group_id']);
        $this->patchJson($this->base($org).'/sessions/'.$s->public_id, $data)->assertOk();
        $url = $this->base($org).'/groups/'.$g->public_id.'/schedule/'.$r->public_id;
        $change = ['weekday' => 1, 'start_time' => '19:00', 'end_time' => '20:00', 'effective_from' => '2026-10-12'];
        $this->postJson($url.'/replace', $change)->assertConflict();
        $this->assertNull($r->fresh()->effective_until);
        $this->assertSame(10, $s->fresh()->capacity);
        $this->postJson($this->base($org).'/sessions/'.$s->public_id.'/cancel')->assertOk();
        $this->postJson($url.'/replace', $change)->assertConflict();
        $this->patchJson($url.'/close', ['effective_until' => '2026-10-12'])->assertConflict();
        $this->assertSame('cancelled', $s->fresh()->status);
        $this->assertDatabaseCount('training_sessions', 8);
    }

    public function test_validation_conflicts_and_location_is_not_a_room(): void
    {
        [$org,,,$loc,$trainer,$g] = $this->sessionFixture();
        $url = $this->base($org).'/sessions';
        $data = $this->sessionData($g, $loc, $trainer);
        foreach ([['capacity' => 0], ['capacity' => 101], ['capacity' => 1.5], ['end_time' => '18:00'], ['end_time' => '23:00'], ['local_date' => '2025-01-01'], ['local_date' => '2035-01-01']] as $bad) {
            $this->postJson($url, [...$data, ...$bad])->assertUnprocessable();
        }
        $this->postJson($url, $data)->assertCreated();
        $this->postJson($url, [...$data, 'start_time' => '18:59', 'end_time' => '20:00'])->assertConflict();
        $this->postJson($url, [...$data, 'start_time' => '19:00', 'end_time' => '20:00'])->assertCreated();
        $member = $this->membership(User::factory()->create(), $org);
        $other = $trainer->replicate();
        $other->public_id = (string) Str::uuid7();
        $other->organization_membership_id = $member->id;
        $other->save();
        $g2 = $g->replicate();
        $g2->public_id = (string) Str::uuid7();
        $g2->primary_trainer_id = $other->id;
        $g2->save();
        $this->postJson($url, $this->sessionData($g2, $loc, $other))->assertCreated();
        $other->status = 'inactive';
        $other->save();
        $this->postJson($url, $this->sessionData($g2, $loc, $other, ['local_date' => '2026-09-23']))->assertUnprocessable();
    }

    public function test_tenant_scope_permissions_and_forged_fields_fail_closed(): void
    {
        [$org,$actor,$access,$loc,$trainer,$g] = $this->sessionFixture();
        $url = $this->base($org).'/sessions';
        $data = $this->sessionData($g, $loc, $trainer);
        $id = $this->postJson($url, $data)->assertCreated()->json('data.id');
        foreach (['organization_id', 'id', 'public_id', 'schedule_rule_id', 'occurrence_date', 'source_type', 'is_overridden', 'status', 'cancelled_at', 'timezone', 'actor_id'] as $field) {
            $this->postJson($url, [...$data, $field => 'forged'])->assertUnprocessable();
        }
        $foreign = Organization::create(['name' => 'Foreign']);
        $foreignLoc = $this->location($foreign);
        foreach (['group_id', 'trainer_id', 'location_id'] as $field) {
            $this->postJson($url, [...$data, $field => $foreignLoc->public_id])->assertNotFound();
        }
        $outsider = User::factory()->create();
        $limited = $this->membership($outsider, $org, StaffRole::Staff, ['studio.access', 'sessions.view', 'sessions.manage', 'sessions.cancel', 'groups.view'], 'selected');
        $this->actingAs($outsider)->getJson($url.'/'.$id)->assertNotFound();
        $this->getJson($url.'?from=2026-09-21&until=2026-09-27')->assertJsonCount(0, 'data');
        DB::table('membership_locations')->insert(['organization_id' => $org->id, 'organization_membership_id' => $limited->id, 'location_id' => $loc->id]);
        $this->getJson($url.'/'.$id)->assertOk();
        $this->getJson($this->base($org).'/members')->assertForbidden();
        $limited->role = StaffRole::Trainer;
        $limited->save();
        $this->getJson($url.'/'.$id)->assertNotFound();
        $this->actingAs($actor);
        $access->permissions = array_values(array_diff($access->permissions, ['sessions.manage']));
        $access->save();
        $this->postJson($url, $data)->assertForbidden();
        $this->postJson($url.'/'.$id.'/cancel')->assertOk();
        $access->permissions = array_values(array_diff($access->permissions, ['sessions.cancel']));
        $access->save();
        $this->postJson($url.'/'.$id.'/cancel')->assertForbidden();
        $access->permissions = array_values(array_diff($access->permissions, ['sessions.view']));
        $access->save();
        $this->getJson($url.'/'.$id)->assertForbidden();
        $this->artisan('gt:session-access-enable', ['organization' => $org->public_id, 'membership' => $access->public_id, '--reason' => 'Explicit adoption'])->assertSuccessful();
        $this->getJson($url.'/'.$id)->assertOk();
    }

    public function test_calendar_is_bounded_filtered_ordered_and_minimizes_data(): void
    {
        [$org,,,$loc,$trainer,$g] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $url = $this->base($org).'/sessions';
        $this->getJson($url)->assertUnprocessable();
        $this->getJson($url.'?from=2026-09-19&until=2026-12-31')->assertUnprocessable();
        $res = $this->getJson($url.'?from=2026-09-19&until=2026-10-01&location_id='.$loc->public_id.'&trainer_id='.$trainer->public_id.'&group_id='.$g->public_id)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.local_date', '2026-09-21');
        foreach (['organization_id', 'training_group_id', 'trainer_profile_id', 'schedule_rule_id', 'email', 'permissions', 'available'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'"', $res->getContent());
        }
        $this->getJson($url.'?from=2026-09-19&until=2026-10-01&status=cancelled')->assertJsonCount(0, 'data');
    }

    public function test_database_constraints_are_final_authority(): void
    {
        [$org,,,$loc,$trainer,$g,$r] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $s = TrainingSession::firstOrFail();
        $foreignLoc = $this->location(Organization::create(['name' => 'Foreign']));
        $g2 = $g->replicate();
        $g2->public_id = (string) Str::uuid7();
        $g2->save();
        $ops = [];
        foreach ([['capacity' => 0], ['capacity' => 101], ['status' => 'completed'], ['source_type' => 'other'], ['source_type' => 'manual'], ['location_id' => $foreignLoc->id], ['training_group_id' => $g2->id], ['ends_at' => $s->starts_at], ['starts_at' => $s->ends_at]] as $bad) {
            $ops[] = fn () => DB::table('training_sessions')->where('id', $s->id)->update($bad);
        }
        $ops[] = fn () => DB::table('training_sessions')->insert([...$s->getAttributes(), 'id' => 99999, 'public_id' => (string) Str::uuid7()]);
        $ops[] = fn () => DB::table('training_sessions')->insert([...$s->getAttributes(), 'id' => 99998, 'public_id' => (string) Str::uuid7(), 'source_type' => 'manual', 'schedule_rule_id' => null, 'occurrence_date' => null, 'training_group_id' => $g2->id]);
        $ops[] = fn () => DB::table('audit_events')->update(['action' => 'tamper']);
        foreach ($ops as $op) {
            try {
                DB::transaction($op);
                $this->fail('Invalid SQL accepted');
            } catch (QueryException $e) {
                $this->assertContains($e->getCode(), ['23503', '23514', '23505', '23P01', '22000']);
            }
        }
    }

    public function test_command_is_repeatable_reports_partial_failures_and_isolates_organizations(): void
    {
        [$org,,,,,$g] = $this->sessionFixture();
        $this->artisan('gt:sessions:materialize', ['--organization' => $org->public_id])->assertSuccessful();
        $this->assertDatabaseCount('training_sessions', 8);
        $this->artisan('gt:sessions:materialize', ['--organization' => $org->public_id])->assertSuccessful();
        $this->assertDatabaseCount('training_sessions', 8);
        $bad = $g->replicate();
        $bad->public_id = (string) Str::uuid7();
        $bad->primary_trainer_id = null;
        $bad->save();
        $status = Artisan::call('gt:sessions:materialize', ['--organization' => $org->public_id]);
        $this->assertSame(1, $status);
        $output = Artisan::output();
        $this->assertStringContainsString('"invalid":1', $output);
        $this->assertStringNotContainsString('Pilates', $output);
        $this->artisan('gt:sessions:materialize', ['--organization' => (string) Str::uuid7()])->assertFailed();
        $this->assertDatabaseCount('training_sessions', 8);
    }

    public function test_audit_failure_rolls_back_manual_and_generated_sessions(): void
    {
        [$org,,,$loc,$trainer,$g] = $this->sessionFixture();
        DB::unprepared("CREATE FUNCTION gt_session_audit_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Fixture'; END $$; CREATE TRIGGER gt_session_fail BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION gt_session_audit_fail();");
        $this->postJson($this->base($org).'/sessions', $this->sessionData($g, $loc, $trainer))->assertStatus(500);
        try {
            SessionMaterializer::run($org->id, $g->public_id);
            $this->fail('Audit failure swallowed');
        } catch (QueryException) {
            $this->assertDatabaseCount('training_sessions', 0);
        }
    }

    public function test_own_trainer_scope_and_snapshot_location_changes_are_enforced(): void
    {
        [$org,$owner,,$loc,$trainer,$g] = $this->sessionFixture();
        $teacher = User::factory()->create();
        $access = $this->membership($teacher, $org, StaffRole::Trainer, ['studio.access', 'sessions.view', 'sessions.manage', 'sessions.cancel', 'groups.view'], 'selected');
        DB::table('membership_locations')->insert(['organization_id' => $org->id, 'organization_membership_id' => $access->id, 'location_id' => $loc->id]);
        $own = $trainer->replicate();
        $own->public_id = (string) Str::uuid7();
        $own->organization_membership_id = $access->id;
        $own->save();
        $url = $this->base($org).'/sessions';
        $data = $this->sessionData($g, $loc, $own);
        $id = $this->postJson($url, $data)->assertCreated()->json('data.id');
        $other = $this->postJson($url, $this->sessionData($g, $loc, $trainer, ['local_date' => '2026-09-23']))->assertCreated()->json('data.id');
        $this->actingAs($teacher)->getJson($url.'/'.$id)->assertOk();
        $this->getJson($url.'/'.$other)->assertNotFound();
        unset($data['group_id']);
        $this->patchJson($url.'/'.$id, [...$data, 'trainer_id' => $trainer->public_id])->assertNotFound();
        $this->postJson($this->base($org).'/groups/'.$g->public_id.'/materialize')->assertNotFound();
        $this->getJson($url.'?from=2026-09-21&until=2026-09-27')->assertJsonCount(1, 'data');
        DB::table('membership_locations')->where('organization_membership_id', $access->id)->delete();
        $this->getJson($url.'/'.$id)->assertNotFound();
        $this->actingAs($owner);
        $next = $this->location($org, 'Next');
        $this->patchJson($url.'/'.$id, [...$data, 'location_id' => $next->public_id])->assertUnprocessable();
        DB::table('membership_locations')->insert(['organization_id' => $org->id, 'organization_membership_id' => $access->id, 'location_id' => $next->id]);
        $this->patchJson($url.'/'.$id, [...$data, 'location_id' => $next->public_id])->assertOk()->assertJsonPath('data.location.name', 'Next');
        $g->location_id = $next->id;
        $g->save();
        $this->getJson($url.'/'.$other)->assertJsonPath('data.location.id', $loc->public_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'session.overridden', 'subject_id' => $id]);
    }

    public function test_reconciliation_conflict_rolls_back_and_rule_close_cancel_are_audited(): void
    {
        [$org,,,$loc,$trainer,$g,$r] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $url = $this->base($org).'/groups/'.$g->public_id.'/schedule/'.$r->public_id;
        $this->postJson($this->base($org).'/sessions', $this->sessionData($g, $loc, $trainer, ['local_date' => '2026-10-19', 'start_time' => '19:00', 'end_time' => '20:00']))->assertCreated();
        $this->postJson($url.'/replace', ['weekday' => 1, 'start_time' => '19:00', 'end_time' => '20:00', 'effective_from' => '2026-10-19'])->assertConflict()->assertJsonPath('code', 'reconciliation_failed');
        $this->assertNull($r->fresh()->effective_until);
        $this->assertSame(9, TrainingSession::where('status', 'scheduled')->count());
        $this->patchJson($url.'/close', ['effective_until' => '2026-10-18'])->assertOk();
        $this->assertSame(4, TrainingSession::where('cancellation_kind', 'schedule')->count());
        $this->postJson($url.'/cancel')->assertOk();
        $this->assertSame(8, TrainingSession::where('cancellation_kind', 'schedule')->count());
        $this->assertSame(1, TrainingSession::where('status', 'scheduled')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'sessions.reconciled']);
    }

    public function test_command_continues_after_group_failure_and_calendar_queries_are_bounded(): void
    {
        [$org,,,$loc,$trainer,$g] = $this->sessionFixture();
        $other = Organization::create(['name' => 'Private other organization']);
        $user = User::factory()->create();
        $m = $this->membership($user, $other);
        $l = $this->location($other);
        $t = new TrainerProfile;
        $t->forceFill(['organization_id' => $other->id, 'organization_membership_id' => $m->id, 'status' => 'active'])->save();
        $g2 = new TrainingGroup;
        $g2->forceFill(['organization_id' => $other->id, 'location_id' => $l->id, 'primary_trainer_id' => $t->id, 'name' => 'Private other group', 'capacity' => 12, 'status' => 'active'])->save();
        $r = new ScheduleRule;
        $r->forceFill(['organization_id' => $other->id, 'training_group_id' => $g2->id, 'weekday' => 1, 'start_minute' => 1080, 'end_minute' => 1140, 'effective_from' => '2026-09-21'])->save();
        DB::unprepared('CREATE FUNCTION gt_session_group_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.organization_id = '.$org->id." THEN RAISE EXCEPTION 'Fixture private data'; END IF; RETURN NEW; END $$; CREATE TRIGGER gt_session_group_fail BEFORE INSERT ON training_sessions FOR EACH ROW EXECUTE FUNCTION gt_session_group_fail();");
        $this->assertSame(1, Artisan::call('gt:sessions:materialize'));
        $output = Artisan::output();
        $this->assertStringContainsString('"failed":1', $output);
        $this->assertStringNotContainsString('Private', $output);
        $this->assertStringNotContainsString('Fixture', $output);
        $this->assertSame(0, TrainingSession::where('organization_id', $org->id)->count());
        $this->assertSame(8, TrainingSession::where('organization_id', $other->id)->count());
        DB::unprepared('DROP TRIGGER gt_session_group_fail ON training_sessions; DROP FUNCTION gt_session_group_fail();');
        SessionMaterializer::run($org->id, $g->public_id);
        $foreignSession = TrainingSession::where('organization_id', $other->id)->firstOrFail();
        $this->getJson($this->base($org).'/sessions/'.$foreignSession->public_id)->assertNotFound();
        $this->getJson($this->base($other).'/sessions/'.$foreignSession->public_id)->assertNotFound();
        $ownData = $this->sessionData($g, $loc, $trainer);
        foreach (['group_id' => $g2->public_id, 'location_id' => $l->public_id, 'trainer_id' => $t->public_id] as $key => $foreignId) {
            $this->postJson($this->base($org).'/sessions', [...$ownData, $key => $foreignId])->assertNotFound();
        }
        $this->postJson($this->base($org).'/groups/'.$g->public_id.'/materialize', ['until' => '2035-01-01'])->assertUnprocessable();
        DB::enableQueryLog();
        $this->getJson($this->base($org).'/sessions?from=2026-09-19&until=2026-11-13')->assertOk()->assertJsonCount(8, 'data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(16, $queries);
    }
}
