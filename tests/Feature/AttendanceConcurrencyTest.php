<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\StudioMember;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

class AttendanceConcurrencyTest extends TestCase
{
    use DatabaseMigrations, SessionFixtures;

    public static function cases(): array
    {
        return array_map(fn ($v) => [$v], ['cancellation', 'capacity', 'duplicate', 'finalize', 'session_cancel']);
    }

    #[DataProvider('cases')]
    public function test_real_competing_attendance_writers(string $case): void
    {
        [$org,$u,,$loc,$t,$g] = $this->sessionFixture();
        $base = $this->base($org);
        $id = $this->postJson($base.'/sessions', $this->sessionData($g, $loc, $t, ['capacity' => 2]))->assertCreated()->json('data.id');
        $s = TrainingSession::where('public_id', $id)->firstOrFail();
        $members = [];
        for ($i = 0; $i < 3; $i++) {
            $m = new StudioMember;
            $m->forceFill(['organization_id' => $org->id, 'first_name' => 'Offline', 'last_name' => (string) $i, 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'offline'])->save();
            $members[] = $m;
        }
        $rid = $this->postJson("$base/sessions/$id/reservations", ['member_id' => $members[0]->public_id])->assertOk()->json('data.reservation.id');
        $mark = ['POST', "/sessions/$id/attendance", ['member_id' => $members[0]->public_id, 'status' => 'present']];
        $clock = $s->starts_at->subMinutes(10)->toIso8601String();
        if ($case === 'finalize') {
            $clock = $s->ends_at->addMinute()->toIso8601String();
        }
        $tasks = match ($case) {
            'cancellation' => [$mark, ['POST', "/reservations/$rid/cancel", []]],
            'capacity' => [['POST', "/sessions/$id/attendance/walk-in", ['member_id' => $members[1]->public_id]], ['POST', "/sessions/$id/reservations", ['member_id' => $members[2]->public_id]]],
            'duplicate' => [$mark, $mark],
            'finalize' => [['finalize', $id], ['POST', "/sessions/$id/attendance/correct", ['member_id' => $members[0]->public_id, 'status' => 'present', 'reason' => 'late_confirmation']]],
            default => [$mark, ['POST', "/sessions/$id/cancel", []]],
        };
        $workers = [];
        DB::beginTransaction();
        try {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            foreach ($tasks as $i => $task) {
                $w = new Process([PHP_BINARY, base_path('tests/Support/attendance-worker.php'), $u->public_id, $org->public_id, json_encode($task, JSON_THROW_ON_ERROR), (string) $i, $clock], base_path(), null, null, 30);
                $w->start();
                $workers[] = $w;
            }
            $deadline = microtime(true) + 10;
            $blocked = 0;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = (int) DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name LIKE 'gt-attendance-race-%' AND wait_event_type='Lock'")->n;
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
                $status = trim($w->getOutput());
                $this->assertContains($status, ['200', '201', '409']);
                $statuses[] = $status;
            }
            $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM training_sessions s WHERE (SELECT count(*) FROM reservations r WHERE r.training_session_id=s.id AND r.status='active')>s.capacity")->n);
            $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM reservations r JOIN waiting_list_entries w ON r.training_session_id=w.training_session_id AND r.studio_member_id=w.studio_member_id WHERE r.status='active' AND w.status='waiting'")->n);
            $this->artisan('gt:attendance:check')->assertSuccessful();
            $this->artisan('gt:reservations:check')->assertSuccessful();
            if (in_array($case, ['duplicate', 'finalize'], true)) {
                $this->assertDatabaseCount('attendance_records', 1);
                $this->assertSame('present', AttendanceRecord::firstOrFail()->status);
            }
            if ($case === 'capacity') {
                $this->assertSame(2, Reservation::where('status', 'active')->count());
                $this->assertContains('409', $statuses);
            }
            if (in_array($case, ['cancellation', 'session_cancel'], true)) {
                $this->assertContains('409', $statuses);
            }
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
