<?php

namespace Tests\Feature;

use App\Models\StudioMember;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\StaffRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

final class OperationsTest extends TestCase
{
    use DatabaseMigrations, SessionFixtures;

    private function setupSession(): array
    {
        [$o, $u, $a, $l, $t, $g] = $this->sessionFixture();
        $id = $this->postJson($this->base($o).'/sessions', $this->sessionData($g, $l, $t))->assertCreated()->json('data.id');
        $m = new StudioMember;
        $m->forceFill(['organization_id' => $o->id, 'first_name' => 'Roster', 'last_name' => 'Person', 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'offline'])->save();
        $this->postJson($this->base($o)."/sessions/$id/reservations", ['member_id' => $m->public_id])->assertOk();
        $s = TrainingSession::where('public_id', $id)->firstOrFail();
        $this->travelTo($s->starts_at->subMinutes(10));

        return [$o, $u, $a, $s, $m];
    }

    public function test_dashboard_roster_and_profile_are_canonical_and_read_only(): void
    {
        [$o, $u, $a, $s, $m] = $this->setupSession();
        $base = $this->base($o).'/operations';
        $this->getJson($base)->assertOk()->assertJsonPath('summary.reserved', 1)->assertJsonPath('data.0.attendance.pending', 1)->assertJsonPath('data.0.actions.present', true)->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($base."/sessions/$s->public_id/roster", [])->assertOk()->assertJsonPath('data.0.member.id', $m->public_id)->assertJsonMissingPath('data.0.member.email')->assertJsonPath('meta.total', 1);
        $this->getJson($base."/sessions/$s->public_id/members/$m->public_id")->assertOk()->assertJsonPath('data.entitlement', 'not_applicable')->assertJsonPath('data.finance_access', true)->assertJsonCount(0, 'data.memberships');
        $this->assertDatabaseCount('attendance_records', 0);
        $this->postJson($this->base($o)."/sessions/$s->public_id/attendance", ['member_id' => $m->public_id, 'status' => 'present'])->assertOk();
        $this->getJson($base)->assertOk()->assertJsonPath('data.0.attendance.present', 1)->assertJsonPath('data.0.attendance.pending', 0)->assertJsonPath('summary.reserved', 1);
    }

    public function test_unassigned_trainers_cannot_broaden_with_filters_or_open_profiles(): void
    {
        [$o, $u, $a, $s, $m] = $this->setupSession();
        $other = User::factory()->create();
        $this->membership($other, $o, StaffRole::Trainer);
        $this->actingAs($other);
        $base = $this->base($o).'/operations';
        $this->getJson($base.'?trainer_id='.$s->trainer->public_id)->assertOk()->assertJsonPath('summary.sessions', 0)->assertJsonCount(0, 'data')->assertJsonCount(0, 'alerts');
        $this->postJson($base."/sessions/$s->public_id/roster", [])->assertNotFound();
        $this->getJson($base."/sessions/$s->public_id/members/$m->public_id")->assertNotFound();
    }

    public function test_bounds_and_strict_payloads(): void
    {
        [$o, $u, $a, $s] = $this->setupSession();
        $base = $this->base($o).'/operations';
        foreach (['?from=2026-01-01&until=2026-02-01', '?per_page=101', '?trainer_id=1', '?organization_id=1', '?until=2026-09-22'] as $query) {
            $this->getJson($base.$query)->assertUnprocessable();
        }
        $this->postJson($base."/sessions/$s->public_id/roster", ['permissions' => ['payments.view']])->assertUnprocessable();
    }

    public function test_trainer_financial_fields_are_absent_and_revocation_is_immediate(): void
    {
        [$o, $u, $a, $s, $m] = $this->setupSession();
        $a->forceFill(['role' => 'trainer', 'permissions' => ['studio.access', 'sessions.view', 'attendance.view']])->save();
        $url = $this->base($o)."/operations/sessions/$s->public_id/members/$m->public_id";
        $this->getJson($url)->assertOk()->assertJsonPath('data.finance_access', false)->assertJsonMissingPath('data.memberships')->assertJsonMissingPath('data.email');
        $a->forceFill(['status' => 'suspended'])->save();
        $this->getJson($url)->assertNotFound();
    }

    public function test_local_midnight_and_dst_use_the_studio_clock_and_selected_locations(): void
    {
        [$o, $u, $a, $s] = $this->setupSession();
        $url = $this->base($o).'/operations';
        $this->travelTo(CarbonImmutable::parse('2026-10-24T22:30:00Z'));
        $this->getJson($url)->assertOk()->assertJsonPath('today', '2026-10-25');
        $this->travelTo(CarbonImmutable::parse('2026-10-25T23:30:00Z'));
        $this->getJson($url)->assertOk()->assertJsonPath('today', '2026-10-26');
        $a->forceFill(['role' => 'trainer', 'location_scope' => 'selected'])->save();
        $this->getJson($url.'?from=2026-09-22&until=2026-09-22')->assertOk()->assertJsonPath('summary.sessions', 0)->assertJsonPath('communication', null);
        $this->postJson($url."/sessions/$s->public_id/roster", [])->assertNotFound();
        $a->locations()->attach($s->location_id, ['organization_id' => $o->id]);
        $this->getJson($url.'?from=2026-09-22&until=2026-09-22')->assertOk()->assertJsonPath('summary.sessions', 1);
    }

    public function test_foreign_tenant_and_unrelated_member_cannot_be_enumerated(): void
    {
        [$o, $u, $a, $s, $m] = $this->setupSession();
        [$other, $otherUser, , $otherSession, $otherMember] = $this->setupSession();
        $this->actingAs($u);
        $base = $this->base($o).'/operations';
        $this->postJson($base."/sessions/$otherSession->public_id/roster", [])->assertNotFound();
        $this->getJson($base."/sessions/$s->public_id/members/$otherMember->public_id")->assertNotFound();
        $this->getJson($this->base($other).'/operations')->assertNotFound();
        $this->getJson($base.'?trainer_id='.$otherSession->trainer->public_id)->assertOk()->assertJsonPath('summary.sessions', 0);
        $this->actingAs($otherUser)->getJson($this->base($other)."/operations/sessions/$otherSession->public_id/members/$m->public_id")->assertNotFound();
    }

    public function test_cash_indicators_use_the_ledger_and_independent_permissions(): void
    {
        [$o, $u, $a, $s, $m] = $this->setupSession();
        $base = $this->base($o);
        $plan = $this->postJson($base.'/membership-plans', ['name' => 'Duration fixture', 'price_minor' => 10000, 'duration_unit' => 'days', 'duration_count' => 30, 'status' => 'active'])->assertCreated()->json('data.id');
        $period = $this->postJson($base.'/memberships', ['member_id' => $m->public_id, 'plan_id' => $plan, 'starts_on' => '2026-09-01'])->assertCreated()->json('data.id');
        $profile = "$base/operations/sessions/$s->public_id/members/$m->public_id";
        $this->getJson($profile)->assertOk()->assertJsonPath('data.memberships.0.payment_status', 'unpaid')->assertJsonPath('data.memberships.0.balance_minor', 10000);
        $payment = $this->postJson($base.'/cash-payments', ['membership_id' => $period, 'amount_minor' => 4000, 'paid_at' => now()->toIso8601String(), 'request_id' => (string) Str::uuid7()])->assertCreated()->json('data.id');
        $this->getJson($profile)->assertOk()->assertJsonPath('data.memberships.0.payment_status', 'partially_paid')->assertJsonPath('data.memberships.0.balance_minor', 6000);
        $this->postJson("$base/operations/sessions/$s->public_id/roster", [])->assertOk()->assertJsonPath('data.0.membership.payment_status', 'partially_paid');
        $this->getJson($base.'/operations')->assertOk()->assertJsonPath('financial.payment_due', 1);
        $this->postJson("$base/cash-payments/$payment/reverse", ['reason' => 'wrong_amount'])->assertOk();
        $this->getJson($profile)->assertOk()->assertJsonPath('data.memberships.0.payment_status', 'unpaid');
        $a->forceFill(['role' => 'trainer', 'permissions' => ['studio.access', 'sessions.view', 'attendance.view', 'memberships.view']])->save();
        $this->getJson($profile)->assertOk()->assertJsonMissingPath('data.memberships.0.payment_status')->assertJsonMissingPath('data.memberships.0.balance_minor')->assertJsonMissingPath('data.memberships.0.agreed_price_minor');
        $this->postJson("$base/operations/sessions/$s->public_id/roster", [])->assertOk()->assertJsonPath('data.0.membership.status', 'active')->assertJsonMissingPath('data.0.membership.payment_status');
        $this->getJson($base.'/operations')->assertOk()->assertJsonMissingPath('financial.payment_due')->assertJsonPath('communication', null);
        $this->assertDatabaseCount('cash_payments', 1);
        $this->assertDatabaseCount('payment_reversals', 1);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_cancelled_occurrence_and_search_do_not_change_unfiltered_counts(): void
    {
        [$o, $u, $a, $s, $m] = $this->setupSession();
        $base = $this->base($o);
        $this->postJson("$base/operations/sessions/$s->public_id/roster", ['search' => 'not a match'])->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)->assertJsonPath('session.reserved_count', 1);
        $this->postJson("$base/sessions/$s->public_id/cancel", [])->assertOk();
        $this->getJson($base.'/operations')->assertOk()->assertJsonPath('summary.cancelled', 1)->assertJsonPath('summary.reserved', 0)->assertJsonPath('data.0.phase', 'cancelled')->assertJsonPath('data.0.actions.present', false);
        $this->getJson($base.'/operations?status=scheduled')->assertOk()->assertJsonCount(0, 'data');
    }
}
