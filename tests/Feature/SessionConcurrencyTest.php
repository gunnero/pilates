<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\TrainingSession;
use App\Support\SessionMaterializer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

class SessionConcurrencyTest extends TestCase
{
    use DatabaseMigrations,SessionFixtures;

    public static function races(): array
    {
        return [['generation'], ['manual'], ['reconciliation'], ['override'], ['cancellation'], ['same_override']];
    }

    #[DataProvider('races')]
    public function test_concurrent_session_operations_preserve_durable_invariants(string $race): void
    {
        [$org,$user,,$loc,$trainer,$g,$r] = $this->sessionFixture();
        if ($race === 'generation') {
            $r->effective_until = '2026-09-21';
            $r->save();
        }
        $gen = ['generate', $g->public_id, []];
        $tasks = [$gen, $gen];
        if (in_array($race, ['cancellation', 'reconciliation'], true)) {
            SessionMaterializer::run($org->id, $g->public_id);
            $s = TrainingSession::firstOrFail();
            $tasks[1] = $race === 'cancellation' ? ['cancel', $s->public_id, []] : ['replace', $g->public_id, ['old_id' => $r->public_id, 'weekday' => 1, 'start_time' => '19:00', 'end_time' => '20:00', 'effective_from' => '2026-09-28']];
        }
        if (in_array($race, ['manual', 'override', 'same_override'], true)) {
            $g2 = $g->replicate();
            $g2->public_id = (string) Str::uuid7();
            $g2->save();
            $d1 = $this->sessionData($g, $loc, $trainer);
            $d2 = $this->sessionData($g2, $loc, $trainer);
            $tasks = [['manual', '', $d1], ['manual', '', $d2]];
            if ($race !== 'manual') {
                $s1 = $this->postJson($this->base($org).'/sessions', [...$d1, 'start_time' => '16:00', 'end_time' => '17:00'])->assertCreated()->json('data.id');
                $s2 = $race === 'same_override' ? $s1 : $this->postJson($this->base($org).'/sessions', [...$d2, 'start_time' => '20:00', 'end_time' => '21:00'])->assertCreated()->json('data.id');
                unset($d1['group_id'],$d2['group_id']);
                $d2['capacity'] = 9;
                $tasks = [['override', $s1, $d1], ['override', $s2, $d2]];
            }
        }
        $workers = [];
        DB::beginTransaction();
        try {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            foreach ($tasks as $i => [$op,$id,$data]) {
                $w = new Process([PHP_BINARY, base_path('tests/Support/session-worker.php'), $org->public_id, $user->public_id, $op, $id, json_encode($data, JSON_THROW_ON_ERROR), (string) $i], base_path(), null, null, 20);
                $w->start();
                $workers[] = $w;
            }
            $deadline = microtime(true) + 10;
            $blocked = 0;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = (int) DB::selectOne("SELECT count(*) AS count FROM pg_stat_activity WHERE application_name LIKE 'gt-session-race-%' AND wait_event_type='Lock'")->count;
                if ($blocked === 2) {
                    break;
                }usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked);
            DB::commit();
            $statuses = [];
            foreach ($workers as $w) {
                $w->wait();
                $this->assertSame(0, $w->getExitCode(), $w->getErrorOutput());
                $statuses[] = trim($w->getOutput());
            }sort($statuses);
            $this->assertSame(in_array($race, ['manual', 'override'], true) ? ['200', '409'] : ['200', '200'], $statuses);
            if ($race === 'generation') {
                $this->assertDatabaseCount('training_sessions', 1);
            }
            if ($race === 'cancellation') {
                $this->assertDatabaseCount('training_sessions', 8);
                $this->assertSame('cancelled', $s->fresh()->status);
            }
            if ($race === 'reconciliation') {
                $this->assertSame(8, TrainingSession::where('status', 'scheduled')->count());
                $this->assertSame(0, TrainingSession::where('schedule_rule_id', $r->id)->where('local_date', '>=', '2026-09-28')->where('status', 'scheduled')->count());
            }
            $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS count FROM training_sessions a JOIN training_sessions b ON a.id<b.id AND a.trainer_profile_id=b.trainer_profile_id AND a.status='scheduled' AND b.status='scheduled' AND tstzrange(a.starts_at,a.ends_at,'[)') && tstzrange(b.starts_at,b.ends_at,'[)')")->count);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }foreach ($workers as $w) {
                if ($w->isRunning()) {
                    $w->stop();
                }
            }
        }
    }
}
