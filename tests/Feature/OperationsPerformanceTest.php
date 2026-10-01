<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\StudioMember;
use App\Models\TrainingSession;
use App\Models\WaitingListEntry;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

final class OperationsPerformanceTest extends TestCase
{
    use DatabaseMigrations, SessionFixtures;

    public function test_representative_bounded_reads_have_constant_queries_and_warmed_budget(): void
    {
        [$o, $u, $a, $l, $t, $g] = $this->sessionFixture();
        $id = $this->postJson($this->base($o).'/sessions', $this->sessionData($g, $l, $t, ['capacity' => 100]))->assertCreated()->json('data.id');
        $first = TrainingSession::where('public_id', $id)->firstOrFail();
        for ($i = 1; $i < 100; $i++) {
            $s = $first->replicate(['public_id']);
            $s->starts_at = $first->starts_at->startOfDay()->addMinutes($i * 5);
            $s->ends_at = $s->starts_at->addMinutes(5);
            $s->save();
        }
        $members = [];

        for ($i = 0; $i < 100; $i++) {
            $m = new StudioMember;
            $m->forceFill(['organization_id' => $o->id, 'first_name' => 'Synthetic', 'last_name' => 'Member'.$i, 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'offline'])->save();
            $members[] = $m;
            $r = new Reservation;
            $r->forceFill(['organization_id' => $o->id, 'training_session_id' => $first->id, 'studio_member_id' => $m->id, 'status' => 'active', 'source' => 'staff', 'created_by' => $u->id])->save();
        }
        for ($i = 0; $i < 10; $i++) {
            $m = new StudioMember;
            $m->forceFill(['organization_id' => $o->id, 'first_name' => 'Synthetic', 'last_name' => 'Waiting'.$i, 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'offline'])->save();
            $w = new WaitingListEntry;
            $w->forceFill(['organization_id' => $o->id, 'training_session_id' => $first->id, 'studio_member_id' => $m->id, 'source' => 'staff', 'status' => 'waiting', 'joined_at' => now()->addSeconds($i), 'created_by' => $u->id])->save();
        }
        $finance = $this->base($o);
        $plan = $this->postJson($finance.'/membership-plans', ['name' => 'Fixture period', 'price_minor' => 10000, 'duration_unit' => 'months', 'duration_count' => 1, 'status' => 'active'])->assertCreated()->json('data.id');
        foreach (array_slice($members, 0, 10) as $m) {
            $membership = $this->postJson($finance.'/memberships', ['member_id' => $m->public_id, 'plan_id' => $plan, 'starts_on' => '2026-08-27'])->assertCreated()->json('data.id');
            $this->postJson($finance.'/cash-payments', ['membership_id' => $membership, 'amount_minor' => 5000, 'paid_at' => now()->toIso8601String(), 'request_id' => (string) Str::uuid7()])->assertCreated();
        }
        [$other, $otherUser, , $otherLocation, $otherTrainer, $otherGroup] = $this->sessionFixture();
        $otherId = $this->postJson($this->base($other).'/sessions', $this->sessionData($otherGroup, $otherLocation, $otherTrainer))->assertCreated()->json('data.id');
        $otherMember = new StudioMember;
        $otherMember->forceFill(['organization_id' => $other->id, 'first_name' => 'Other', 'last_name' => 'Tenant', 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'offline'])->save();
        $this->postJson($this->base($other)."/sessions/$otherId/reservations", ['member_id' => $otherMember->public_id])->assertOk();
        $this->actingAs($u);
        for ($i = 1; $i <= 10; $i++) {
            $historical = $first->replicate(['public_id']);
            $historical->starts_at = $first->starts_at->subDays($i + 30);
            $historical->ends_at = $first->ends_at->subDays($i + 30);
            $historical->local_date = $historical->starts_at->setTimezone($o->timezone)->toDateString();
            $historical->save();
        }
        $base = $this->base($o).'/operations';
        $results = [];
        foreach (['dashboard', 'roster'] as $kind) {
            foreach ([1, 100] as $size) {
                DB::enableQueryLog();
                DB::flushQueryLog();
                $r = $kind === 'dashboard' ? $this->getJson($base.'?from=2026-09-22&until=2026-09-22&per_page='.$size) : $this->postJson($base."/sessions/$id/roster", ['per_page' => $size]);
                $r->assertOk()->assertJsonPath('meta.total', 100);
                $count = count(DB::getQueryLog());
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                if ($size === 100) {
                    foreach ($queries as $query) {
                        if (str_starts_with($query['query'], 'select') && preg_match('/training_sessions|reservations|member_memberships|notification_deliveries/', $query['query'])) {
                            $results[$kind]['plans'][] = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query['query'], $query['bindings']);
                        }
                    }
                }
                $results[$kind]['queries'][$size] = $count;
                $results[$kind]['bytes'][$size] = strlen($r->getContent());
            }
            $this->assertSame($results[$kind]['queries'][1], $results[$kind]['queries'][100]);
            $this->assertLessThanOrEqual($kind === 'dashboard' ? 25 : 30, $results[$kind]['queries'][100]);
            $times = [];
            for ($i = 0; $i < 20; $i++) {
                $start = hrtime(true);
                $r = $kind === 'dashboard' ? $this->getJson($base.'?from=2026-09-22&until=2026-09-22') : $this->postJson($base."/sessions/$id/roster", []);
                $r->assertOk();
                $times[] = (hrtime(true) - $start) / 1e6;
            }
            sort($times);
            $results[$kind]['p95_ms'] = $times[18];
            $this->assertLessThanOrEqual(500, $times[18]);
        }
        $profileUrl = $base."/sessions/$id/members/".$members[0]->public_id;
        DB::enableQueryLog();
        DB::flushQueryLog();
        $profile = $this->getJson($profileUrl)->assertOk();
        $results['profile']['queries'] = count(DB::getQueryLog());
        $results['profile']['bytes'] = strlen($profile->getContent());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(30, $results['profile']['queries']);
        $times = [];
        for ($i = 0; $i < 20; $i++) {
            $start = hrtime(true);
            $this->getJson($profileUrl)->assertOk()->assertJsonPath('data.memberships.0.payment_status', 'partially_paid');
            $times[] = (hrtime(true) - $start) / 1e6;
        }
        sort($times);
        $results['profile']['p95_ms'] = $times[18];
        $this->assertLessThanOrEqual(500, $times[18]);
        $results['fixture'] = ['sessions_in_day' => 100, 'historical_sessions' => 10, 'other_tenant_sessions' => 1, 'roster' => 100, 'waiting' => 10, 'memberships' => 10, 'payments' => 10, 'notifications' => DB::table('platform_notifications')->count(), 'organizations' => 2];
        $results['postgres'] = DB::selectOne('select version() as v')->v;
        $results['plan'] = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) SELECT id FROM training_sessions WHERE organization_id=? AND local_date=? ORDER BY starts_at,id LIMIT 25', [$o->id, '2026-09-22']);
        $directory = base_path('.local/gt016');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/performance.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
