<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\MemberMembership;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\StudioMember;
use App\Models\TrainerProfile;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

class OperationsConcurrencyTest extends TestCase
{
    use DatabaseMigrations, SessionFixtures;

    public static function cases(): array
    {
        return array_merge(...array_map(fn ($iteration) => array_map(fn ($v) => [$v], ['cancellation', 'capacity', 'duplicate', 'finalize', 'session_cancel', 'walk_ins', 'correction', 'reassign', 'finance']), range(1, 3)));
    }

    #[DataProvider('cases')]
    public function test_real_competing_attendance_writers(string $case): void
    {
        [$org,$u,$access,$loc,$t,$g] = $this->sessionFixture();
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
        $extraTask = null;
        if ($case === 'finance') {
            $plan = $this->postJson("$base/membership-plans", ['name' => 'Duration', 'price_minor' => 10000, 'duration_unit' => 'days', 'duration_count' => 30, 'status' => 'active'])->assertCreated()->json('data.id');
            $period = $this->postJson("$base/memberships", ['member_id' => $members[0]->public_id, 'plan_id' => $plan, 'starts_on' => '2026-09-01'])->assertCreated()->json('data.id');
            $extraTask = ['POST', "$base/memberships/$period/cancel", ['reason' => 'member_request']];
            $extraTask[1] = "/memberships/$period/cancel";
        }
        if ($case === 'reassign') {
            $other = User::factory()->create();
            $otherAccess = $this->membership($other, $org);
            $otherTrainer = new TrainerProfile;
            $otherTrainer->forceFill(['organization_id' => $org->id, 'organization_membership_id' => $otherAccess->id, 'status' => 'active'])->save();
            $data = $this->sessionData($g, $loc, $otherTrainer, ['capacity' => 2]);
            unset($data['group_id']);
            $extraTask = ['PATCH', "/sessions/$id", $data];
            $access->forceFill(['role' => 'trainer'])->save();
        }
        $mark = ['POST', "/sessions/$id/attendance", ['member_id' => $members[0]->public_id, 'status' => 'present']];
        $clock = $s->starts_at->subMinutes(10)->toIso8601String();
        if (in_array($case, ['finalize', 'correction'], true)) {
            $clock = $s->ends_at->addMinute()->toIso8601String();
        }
        $tasks = match ($case) {
            'cancellation' => [$mark, ['POST', "/reservations/$rid/cancel", []]],
            'capacity' => [['POST', "/sessions/$id/attendance/walk-in", ['member_id' => $members[1]->public_id]], ['POST', "/sessions/$id/reservations", ['member_id' => $members[2]->public_id]]],
            'duplicate' => [$mark, $mark],
            'finance', 'reassign' => [$mark, $extraTask],
            'walk_ins' => [['POST', "/sessions/$id/attendance/walk-in", ['member_id' => $members[1]->public_id]], ['POST', "/sessions/$id/attendance/walk-in", ['member_id' => $members[2]->public_id]]],
            'correction' => [['POST', "/sessions/$id/attendance", ['member_id' => $members[0]->public_id, 'status' => 'absent']], ['POST', "/sessions/$id/attendance/correct", ['member_id' => $members[0]->public_id, 'status' => 'present', 'reason' => 'late_confirmation']]],
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
                $this->assertContains($status, ($case === 'reassign' ? ['200', '201', '409', '404'] : ['200', '201', '409']));
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
            if (in_array($case, ['capacity', 'walk_ins'], true)) {
                $this->assertSame(2, Reservation::where('status', 'active')->count());
                $this->assertContains('409', $statuses);
            }
            if ($case === 'finance') {
                $this->assertSame(['200', '200'], $statuses);
                $this->assertSame('present', AttendanceRecord::sole()->status);
                $this->assertNotNull(MemberMembership::sole()->cancelled_at);
            }
            if ($case === 'reassign') {
                $this->assertContains('200', $statuses);
                $this->assertNotSame(['200', '200'], $statuses);
                $this->assertSame($statuses[0] === '200' ? $t->id : $otherTrainer->id, $s->fresh()->trainer_profile_id);
            }
            if ($case === 'duplicate') {
                $this->assertSame(1, DB::table('audit_events')->where('action', 'attendance.marked')->count());
            }
            if ($case === 'correction') {
                $this->assertDatabaseCount('attendance_records', 1);
                $this->assertSame('present', AttendanceRecord::firstOrFail()->status);
            }
            $this->assertDatabaseCount('cash_payments', 0);
            $this->assertDatabaseCount('payment_reversals', 0);
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

    public function test_a_locked_tenant_does_not_block_another_tenants_attendance(): void
    {
        [$one] = $this->sessionFixture();
        [$two, $u, , $l, $t, $g] = $this->sessionFixture();
        $id = $this->postJson($this->base($two).'/sessions', $this->sessionData($g, $l, $t))->assertCreated()->json('data.id');
        $s = TrainingSession::where('public_id', $id)->sole();
        $m = new StudioMember;
        $m->forceFill(['organization_id' => $two->id, 'first_name' => 'Isolated', 'last_name' => 'Fixture', 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'offline'])->save();
        $this->postJson($this->base($two)."/sessions/$id/reservations", ['member_id' => $m->public_id])->assertOk();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            DB::beginTransaction();
            $worker = null;
            try {
                Organization::whereKey($one->id)->lockForUpdate()->sole();
                $task = ['POST', "/sessions/$id/attendance", ['member_id' => $m->public_id, 'status' => 'present']];
                $worker = new Process([PHP_BINARY, base_path('tests/Support/attendance-worker.php'), $u->public_id, $two->public_id, json_encode($task, JSON_THROW_ON_ERROR), 'independent', $s->starts_at->subMinutes(10)->toIso8601String()], base_path(), null, null, 10);
                $worker->run();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $this->assertSame('200', trim($worker->getOutput()));
                $this->assertSame(0, AttendanceRecord::where('organization_id', $one->id)->count());
            } finally {
                DB::rollBack();
                if ($worker?->isRunning()) {
                    $worker->stop();
                }
            }
        }
        $this->assertSame(1, AttendanceRecord::where('organization_id', $two->id)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'attendance.marked')->count());
    }
}
