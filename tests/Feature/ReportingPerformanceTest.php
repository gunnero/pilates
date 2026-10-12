<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

final class ReportingPerformanceTest extends TestCase
{
    use DatabaseMigrations, SessionFixtures;

    public function test_representative_authenticated_report_and_export_budgets(): void
    {
        [$o,$u,$a,$l,$t,$g] = $this->sessionFixture();
        [$other, $otherUser, , $otherLocation, $otherTrainer, $otherGroup] = $this->sessionFixture();
        [$empty] = $this->sessionFixture();
        $this->actingAs($u);
        $this->travelTo(CarbonImmutable::parse('2026-10-12T12:00:00Z'));
        $secondTrainer = $t->replicate(['public_id']);
        $secondAccess = $this->membership(User::factory()->create(), $o);
        $secondTrainer->organization_membership_id = $secondAccess->id;
        $secondTrainer->save();
        $secondGroup = $g->replicate(['public_id']);
        $secondGroup->primary_trainer_id = $secondTrainer->id;
        $secondGroup->name = 'Second synthetic group';
        $secondGroup->save();
        $org = $o->id;
        $actor = $u->id;
        DB::insert("INSERT INTO studio_members (public_id,organization_id,first_name,last_name,status,preferred_locale,digital_access_mode,created_at,updated_at) SELECT uuidv7(),?, 'Synthetic', n::text, 'active','mk','offline',now(),now() FROM generate_series(1,1000) n", [$org]);
        $plan = DB::table('membership_plans')->insertGetId(['public_id' => (string) Str::uuid7(), 'organization_id' => $org, 'name' => 'Synthetic year', 'price_minor' => 20000, 'currency' => 'MKD', 'duration_unit' => 'months', 'duration_count' => 12, 'status' => 'active']);
        DB::insert("INSERT INTO member_memberships(public_id,organization_id,studio_member_id,membership_plan_id,plan_name,agreed_price_minor,currency,duration_unit,duration_count,starts_on,ends_on,created_by,created_at,updated_at) SELECT uuidv7(),organization_id,id,?,'Synthetic year',20000,'MKD','months',12,'2025-10-01','2026-09-30',?,now(),now() FROM studio_members WHERE organization_id=?", [$plan, $actor, $org]);
        DB::insert("INSERT INTO cash_payments(public_id,request_id,organization_id,studio_member_id,member_membership_id,amount_minor,currency,method,paid_at,recorded_by,created_at) SELECT uuidv7(),uuidv7(),m.organization_id,m.studio_member_id,m.id,1000,'MKD','cash',TIMESTAMPTZ '2025-10-01 12:00:00+00'+(((m.id*10+p)%365)::int)*INTERVAL '1 day',?,now() FROM member_memberships m CROSS JOIN generate_series(1,10) p WHERE m.organization_id=?", [$actor, $org]);
        DB::insert("INSERT INTO training_sessions(public_id,organization_id,training_group_id,location_id,trainer_profile_id,source_type,local_date,starts_at,ends_at,timezone,group_name,location_name,trainer_name,capacity,status,is_overridden,created_at,updated_at) SELECT uuidv7(),?,?,?,?,'manual',DATE '2025-10-01'+(n/6), ((DATE '2025-10-01'+(n/6))::timestamp+(8+(n%6)*2)*INTERVAL '1 hour') AT TIME ZONE 'Europe/Skopje',((DATE '2025-10-01'+(n/6))::timestamp+(9+(n%6)*2)*INTERVAL '1 hour') AT TIME ZONE 'Europe/Skopje','Europe/Skopje','Synthetic group','Synthetic location','Synthetic trainer',12,'scheduled',false,now(),now() FROM generate_series(0,1999)n", [$org, $g->id, $l->id, $t->id]);
        DB::table('training_sessions')->where('organization_id', $org)->whereRaw('id%2=0')->update(['training_group_id' => $secondGroup->id, 'trainer_profile_id' => $secondTrainer->id, 'group_name' => 'Second synthetic group', 'trainer_name' => 'Second synthetic trainer']);
        // A genuinely populated smaller tenant and an empty tenant exercise skew.
        DB::insert("INSERT INTO studio_members (public_id,organization_id,first_name,last_name,status,preferred_locale,digital_access_mode,created_at,updated_at) SELECT uuidv7(),?, 'Other synthetic', n::text, 'active','mk','offline',now(),now() FROM generate_series(1,20) n", [$other->id]);
        DB::insert("INSERT INTO training_sessions(public_id,organization_id,training_group_id,location_id,trainer_profile_id,source_type,local_date,starts_at,ends_at,timezone,group_name,location_name,trainer_name,capacity,status,is_overridden,created_at,updated_at) SELECT uuidv7(),?,?,?,?,'manual',DATE '2025-10-01'+n,TIMESTAMPTZ '2025-10-01 10:00:00+00'+n*INTERVAL '1 day',TIMESTAMPTZ '2025-10-01 11:00:00+00'+n*INTERVAL '1 day','Europe/Skopje','Other group','Other location','Other trainer',12,'scheduled',false,now(),now() FROM generate_series(0,29)n", [$other->id, $otherGroup->id, $otherLocation->id, $otherTrainer->id]);
        DB::insert("WITH members AS (SELECT id,row_number() OVER(ORDER BY id)-1 AS n FROM studio_members WHERE organization_id=?), sessions AS (SELECT id,row_number() OVER(ORDER BY id)-1 AS n FROM training_sessions WHERE organization_id=?) INSERT INTO reservations(public_id,organization_id,training_session_id,studio_member_id,status,source,created_by,created_at,updated_at) SELECT uuidv7(),?,s.id,m.id,'active','staff',?,now(),now() FROM sessions s CROSS JOIN generate_series(0,9) p JOIN members m ON m.n=((s.n%100)*10+p)", [$org, $org, $org, $actor]);
        DB::insert("INSERT INTO attendance_records(public_id,organization_id,training_session_id,studio_member_id,reservation_id,status,check_in_method,checked_in_at,recorded_by,created_at,updated_at) SELECT uuidv7(),r.organization_id,r.training_session_id,r.studio_member_id,r.id,CASE WHEN r.id%10=0 THEN 'no_show' WHEN r.id%10=1 THEN 'absent' ELSE 'present' END,'staff',CASE WHEN r.id%10 IN(0,1) THEN NULL ELSE s.starts_at END,?,s.starts_at,s.ends_at FROM reservations r JOIN training_sessions s ON s.id=r.training_session_id WHERE r.organization_id=?", [$actor, $org]);
        DB::insert("INSERT INTO payment_reversals(public_id,organization_id,cash_payment_id,amount_minor,currency,reason,recorded_by,created_at) SELECT uuidv7(),organization_id,id,amount_minor,currency,'wrong_amount',?,now() FROM cash_payments WHERE organization_id=? AND id%20=0", [$actor, $org]);
        // Bulk fixtures need the same current statistics as an operated database.
        foreach (['studio_members', 'member_memberships', 'cash_payments', 'payment_reversals', 'training_sessions', 'reservations', 'attendance_records'] as $table) {
            DB::statement('ANALYZE '.$table);
        }
        $evidence = ['fixture' => ['tenants' => 3, 'members' => 1000, 'sessions' => 2000, 'reservations' => 20000, 'attendance' => 20000, 'payments' => 10000, 'reversals' => 500, 'months' => 12, 'main_groups' => 2, 'main_trainers' => 2, 'smaller_tenant_members' => 20, 'smaller_tenant_sessions' => 30, 'empty_tenants' => 1], 'postgres' => DB::selectOne('select version() as v')->v, 'reads' => []];
        $before = DB::table('cash_payments')->where('organization_id', $org)->sum('amount_minor');
        $base = $this->base($o).'/reports?from=2025-10-01&until=2026-09-30&comparison=previous&report=';
        foreach (['revenue', 'settlement', 'activity', 'expiry', 'attendance', 'occupancy', 'cancellations', 'popularity'] as $name) {
            $this->travel(1)->minutes(); // Each 60-request benchmark group respects the existing 120/minute limiter.
            $url = $base.$name;
            for ($i = 0; $i < 10; $i++) {
                $this->getJson($url)->assertOk();
            }
            $times = [];
            for ($i = 0; $i < 50; $i++) {
                $start = hrtime(true);
                $r = $this->getJson($url)->assertOk();
                $times[] = (hrtime(true) - $start) / 1e6;
            }
            sort($times);
            $this->assertLessThanOrEqual(750, $times[47], $name);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $r = $this->getJson($url)->assertOk();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertLessThanOrEqual(35, count($queries));
            $evidence['reads'][$name] = ['p95_ms' => $times[47], 'queries' => count($queries), 'bytes' => strlen($r->getContent())];
            foreach ($queries as $query) {
                if (str_starts_with($query['query'], 'select') && preg_match('/cash_payments|training_sessions|member_memberships/', $query['query'])) {
                    $evidence['plans'][$name][] = DB::select('EXPLAIN (ANALYZE,BUFFERS,FORMAT JSON) '.$query['query'], $query['bindings']);
                }
            }
        }
        $this->travel(1)->minutes();
        $detailTimes = [];
        for ($i = 0; $i < 60; $i++) {
            $start = hrtime(true);
            $this->getJson($base.'revenue&view=details&per_page=100')->assertOk();
            if ($i >= 10) {
                $detailTimes[] = (hrtime(true) - $start) / 1e6;
            }
        }
        sort($detailTimes);
        $evidence['detail_p95_ms'] = $detailTimes[47];
        $this->assertLessThanOrEqual(500, $detailTimes[47]);
        foreach ([1, 100] as $size) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $r = $this->getJson($base.'revenue&view=details&per_page='.$size)->assertOk();
            $evidence['detail_queries'][$size] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
        $this->assertSame($evidence['detail_queries'][1], $evidence['detail_queries'][100]);
        $this->assertLessThanOrEqual(15, $evidence['detail_queries'][100]);
        foreach (['csv', 'xlsx'] as $format) {
            memory_reset_peak_usage();
            $start = hrtime(true);
            $memory = memory_get_usage(true);
            $r = $this->get($base.'revenue&view=details&format='.$format)->assertOk();
            $elapsed = (hrtime(true) - $start) / 1e9;
            $path = $r->baseResponse->getFile()->getPathname();
            $evidence['exports'][$format] = ['seconds' => $elapsed, 'bytes' => filesize($path), 'incremental_peak_bytes' => memory_get_peak_usage(true) - $memory];
            $this->assertLessThanOrEqual(15, $elapsed);
            $this->assertLessThanOrEqual(134217728, $evidence['exports'][$format]['incremental_peak_bytes']);
            $parser = new Process(['python3', base_path('tests/Support/parse-report-export.py'), $path, $format]);
            $parser->mustRun();
            $parsed = json_decode($parser->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(10000, $parsed['rows']);
            $this->assertSame('10000000', $parsed['gross_minor']);
            $this->assertSame('500000', $parsed['reversed_minor']);
            $this->assertSame('9500000', $parsed['net_minor']);
            $evidence['exports'][$format]['independent_reconciliation'] = $parsed['net_minor'];
            unlink($path);
        }
        $this->assertSame($before, DB::table('cash_payments')->where('organization_id', $org)->sum('amount_minor'));
        $this->getJson($base.'revenue')->assertOk()->assertJsonPath('data.totals.net_minor', '9500000');
        file_put_contents(base_path('.local/gt017/performance.json'), json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
