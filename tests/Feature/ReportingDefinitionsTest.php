<?php

namespace Tests\Feature;

use App\Models\StudioMember;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ReportingFixtures;
use Tests\TestCase;

final class ReportingDefinitionsTest extends TestCase
{
    use DatabaseMigrations, ReportingFixtures;

    public function test_weighted_capacity_pending_exclusion_cancellation_events_and_unique_participants(): void
    {
        [$o,$u,$a,$id,$m] = $this->reportFixture();
        $base = $this->base($o);
        $first = TrainingSession::where('public_id', $id)->firstOrFail();
        $make = function (string $date, int $capacity) use ($base, $first) {
            return $this->postJson($base.'/sessions', ['group_id' => $first->group->public_id, 'trainer_id' => $first->trainer->public_id, 'location_id' => $first->location->public_id, 'local_date' => $date, 'start_time' => '18:00', 'end_time' => '19:00', 'capacity' => $capacity])->assertCreated()->json('data.id');
        };
        $second = $make('2026-09-23', 2);
        $cancelled = $make('2026-09-24', 10);
        $this->postJson("$base/sessions/$second/reservations", ['member_id' => $m->public_id])->assertOk();
        $this->postJson("$base/sessions/$cancelled/reservations", ['member_id' => $m->public_id])->assertOk();
        $this->postJson("$base/sessions/$cancelled/cancel", [])->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-09-25T12:00:00Z'));
        $this->postJson("$base/sessions/$id/attendance/correct", ['member_id' => $m->public_id, 'status' => 'present', 'reason' => 'late_confirmation'])->assertOk();
        $url = "$base/reports?report=occupancy&from=2026-09-01&until=2026-09-30";
        $this->getJson($url)->assertOk()->assertJsonPath('data.totals.capacity', 14)->assertJsonPath('data.totals.reserved', 2)->assertJsonPath('data.totals.occupancy_rate', 14.29)->assertJsonPath('data.totals.utilization_rate', 7.14)->assertJsonPath('data.totals.attendance_rate', 100)->assertJsonPath('data.totals.pending', 1)->assertJsonPath('data.totals.unique_participants', 1)->assertJsonPath('data.totals.cancelled', 1);
        $this->getJson(str_replace('report=occupancy', 'report=cancellations', $url))->assertOk()->assertJsonPath('data.totals.session_cancellation_events', 1);
        $this->assertSame(1, DB::table('attendance_records')->count());
    }

    public function test_two_autumn_instants_same_local_clock_and_half_open_boundaries_are_distinct(): void
    {
        [$o,$u,$a] = $this->reportFixture();
        $base = $this->base($o);
        $membership = DB::table('member_memberships')->where('organization_id', $o->id)->value('public_id');
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
        foreach (['2026-10-25T00:30:00Z', '2026-10-25T01:30:00Z', '2026-10-25T23:00:00Z'] as $instant) {
            $this->postJson($base.'/cash-payments', ['membership_id' => $membership, 'amount_minor' => 111, 'paid_at' => $instant, 'request_id' => (string) Str::uuid7()])->assertCreated();
        }
        $this->getJson($base.'/reports?report=revenue&from=2026-10-25&until=2026-10-25')->assertOk()->assertJsonPath('data.totals.payments', 2)->assertJsonPath('data.totals.net_minor', '222');
        $this->getJson($base.'/reports?report=revenue&from=2026-10-26&until=2026-10-26')->assertOk()->assertJsonPath('data.totals.payments', 1)->assertJsonPath('data.totals.net_minor', '111');
    }

    public function test_same_payment_retry_overpayment_and_current_expiry_do_not_inflate_members(): void
    {
        [$o,$u,$a] = $this->reportFixture();
        $base = $this->base($o);
        $membership = DB::table('member_memberships')->where('organization_id', $o->id)->value('public_id');
        $payload = ['membership_id' => $membership, 'amount_minor' => 7000, 'paid_at' => '2026-09-10T10:00:00Z', 'request_id' => (string) Str::uuid7()];
        $this->postJson($base.'/cash-payments', $payload)->assertCreated();
        $this->postJson($base.'/cash-payments', $payload)->assertCreated();
        $this->getJson($base.'/reports?report=settlement')->assertOk()->assertJsonPath('data.totals.memberships', 1)->assertJsonPath('data.totals.members', 1)->assertJsonPath('data.totals.balance_minor', '-1000')->assertJsonPath('data.rows.0.payment_state', 'overpaid');
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:30:00Z'));
        $this->getJson($base.'/reports?report=expiry&from=2020-01-01&until=2020-01-02')->assertOk()->assertJsonPath('data.rows.0.expiry', 'expired')->assertJsonPath('data.meta.basis', 'current_snapshot');
    }

    public function test_expiry_boundaries_and_settlement_filters_reconcile_at_membership_grain(): void
    {
        [$o] = $this->reportFixture();
        $base = $this->base($o);
        $plan = DB::table('membership_plans')->where('organization_id', $o->id)->value('public_id');
        $today = CarbonImmutable::parse('2026-09-19');
        foreach ([-1, 0, 7, 8, 14, 15, 30, 31] as $days) {
            $member = new StudioMember;
            $member->forceFill(['organization_id' => $o->id, 'first_name' => 'Synthetic', 'last_name' => 'Boundary '.($days + 1), 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'offline'])->save();
            $end = $today->addDays($days);
            $start = $end->addDay()->subMonthNoOverflow()->toDateString();
            $this->postJson($base.'/memberships', ['member_id' => $member->public_id, 'plan_id' => $plan, 'starts_on' => $start])->assertCreated();
        }
        $result = $this->getJson($base.'/reports?report=expiry')->assertOk()->json('data');
        $counts = array_column($result['rows'], 'memberships', 'expiry');
        $this->assertSame(['expired' => 1, '7' => 2, '14' => 3, '30' => 2, 'later' => 1], array_replace(array_fill_keys(['expired', '7', '14', '30', 'later'], 0), $counts));
        $this->assertSame(9, array_sum($counts));
        foreach (['expired' => 1, '7' => 2, '14' => 3, '30' => 2, 'later' => 1] as $bucket => $count) {
            $this->getJson($base.'/reports?report=expiry&view=details&expiry='.$bucket)->assertOk()->assertJsonPath('data.pagination.total', $count)->assertJsonCount($count, 'data.rows');
        }
        $this->getJson($base.'/reports?report=settlement&payment_state=unpaid')->assertOk()->assertJsonPath('data.totals.memberships', 8)->assertJsonPath('data.totals.outstanding_minor', '80000');
        $this->getJson($base.'/reports?report=settlement&payment_state=partially_paid')->assertOk()->assertJsonPath('data.totals.memberships', 1)->assertJsonPath('data.totals.outstanding_minor', '6000');
        $this->getJson($base.'/reports?report=settlement&payment_state=paid')->assertOk()->assertJsonPath('data.totals.memberships', 0);
    }
}
