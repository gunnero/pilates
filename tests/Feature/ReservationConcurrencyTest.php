<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PermanentSpot;
use App\Models\Reservation;
use App\Models\StudioMember;
use App\Models\TrainingSession;
use App\Models\WaitingListEntry;
use App\Support\SessionMaterializer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

class ReservationConcurrencyTest extends TestCase
{
    use DatabaseMigrations,SessionFixtures;

    public static function cases(): array
    {
        return array_map(fn ($x) => [$x], ['final', 'same', 'reduce', 'cancel_new', 'double_cancel', 'promoters', 'increase', 'spot_create', 'group_reduce', 'sync', 'release_sync', 'session_cancel']);
    }

    #[DataProvider('cases')]
    public function test_competing_processes_preserve_allocation(string $case): void
    {
        [$org,$u,,$loc,$t,$g] = $this->sessionFixture();
        SessionMaterializer::run($org->id, $g->public_id);
        $s = TrainingSession::firstOrFail();
        $base = $this->base($org);
        $members = [];
        for ($i = 0; $i < 15; $i++) {
            $m = new StudioMember;
            $m->forceFill(['organization_id' => $org->id, 'first_name' => 'Fixture', 'last_name' => (string) $i, 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'offline'])->save();
            $members[] = $m;
        }
        $reserve = fn ($i) => ['POST', "/sessions/$s->public_id/reservations", ['member_id' => $members[$i]->public_id, 'allow_waiting' => true]];
        $data = ['location_id' => $loc->public_id, 'trainer_id' => $t->public_id, 'local_date' => $s->local_date, 'start_time' => '18:00', 'end_time' => '19:00', 'capacity' => 10];
        $spot = fn ($i) => ['POST', "/groups/$g->public_id/permanent-spots", ['member_id' => $members[$i]->public_id, 'effective_from' => '2026-09-21', 'status' => 'active']];
        if (in_array($case, ['spot_create', 'group_reduce', 'sync', 'release_sync'], true)) {
            $n = in_array($case, ['spot_create', 'group_reduce'], true) ? 11 : 1;
            for ($i = 0; $i < $n; $i++) {
                $task = $spot($i);
                $this->postJson($base.$task[1], $task[2])->assertCreated();
            }
            $tasks = match ($case) {
                'spot_create' => [$spot(11), $spot(12)],'group_reduce' => [$spot(11), ['PATCH', "/groups/$g->public_id", ['capacity' => 11]]],'sync' => [['sync', $g->public_id], ['sync', $g->public_id]],default => [['POST', '/reservations/'.Reservation::firstOrFail()->public_id.'/cancel', []], ['sync', $g->public_id]]
            };
        } else {
            $n = $case === 'same' ? 0 : ($case === 'reduce' ? 10 : ($case === 'final' ? 11 : 12));
            for ($i = 0; $i < $n; $i++) {
                $task = $reserve($i);
                $this->postJson($base.$task[1], $task[2])->assertOk();
            }
            if (in_array($case, ['double_cancel', 'promoters', 'increase', 'session_cancel'], true)) {
                foreach ([12, 13] as $i) {
                    $task = $reserve($i);
                    $this->postJson($base.$task[1], $task[2])->assertOk();
                }
            }
            $cancel = ['POST', '/reservations/'.(Reservation::first()?->public_id ?? 'none').'/cancel', []];
            $tasks = match ($case) {
                'final' => [$reserve(11), $reserve(12)],'same' => [$reserve(0), $reserve(0)],'reduce' => [$reserve(10), ['PATCH', "/sessions/$s->public_id", $data]],'cancel_new' => [$cancel, $reserve(12)],'double_cancel' => [$cancel, $cancel],'promoters' => [['promote', $s->public_id], ['promote', $s->public_id]],'increase' => [['PATCH', "/sessions/$s->public_id", [...$data, 'capacity' => 14]], ['promote', $s->public_id]],default => [['POST', "/sessions/$s->public_id/cancel", []], $cancel]
            };
            if ($case === 'promoters') {
                Reservation::firstOrFail()->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
            }
        }
        $workers = [];
        DB::beginTransaction();
        try {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            foreach ($tasks as $i => $task) {
                $w = new Process([PHP_BINARY, base_path('tests/Support/reservation-worker.php'), $u->public_id, $org->public_id, json_encode($task, JSON_THROW_ON_ERROR), (string) $i], base_path(), null, null, 30);
                $w->start();
                $workers[] = $w;
            }
            $deadline = microtime(true) + 10;
            $blocked = 0;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = (int) DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name LIKE 'gt-reservation-race-%' AND wait_event_type='Lock'")->n;
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
            if ($case === 'final') {
                $this->assertSame(12, Reservation::where('training_session_id', $s->id)->where('status', 'active')->count());
                $this->assertSame(1, WaitingListEntry::where('status', 'waiting')->count());
            }
            if ($case === 'same') {
                $this->assertSame(1, Reservation::where('status', 'active')->count());
            }
            if ($case === 'double_cancel' || $case === 'promoters') {
                $this->assertSame($members[12]->id, WaitingListEntry::where('status', 'promoted')->firstOrFail()->studio_member_id);
                $this->assertSame(1, WaitingListEntry::where('status', 'promoted')->count());
            }
            if ($case === 'increase') {
                $this->assertSame(14, Reservation::where('status', 'active')->count());
                $this->assertSame(2, WaitingListEntry::where('status', 'promoted')->count());
            }
            if ($case === 'spot_create') {
                $this->assertSame(12, PermanentSpot::count());
                $this->assertContains('409', $statuses);
            }
            if ($case === 'group_reduce') {
                $this->assertLessThanOrEqual($g->fresh()->capacity, PermanentSpot::count());
            }
            if ($case === 'sync') {
                $this->assertSame(8, Reservation::where('status', 'active')->count());
            }
            if ($case === 'release_sync') {
                $this->assertSame(7, Reservation::where('status', 'active')->count());
            }
            if ($case === 'session_cancel') {
                $this->assertSame('cancelled', $s->fresh()->status);
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
