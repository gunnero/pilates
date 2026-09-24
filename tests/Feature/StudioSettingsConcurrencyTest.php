<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Reservation;
use App\Models\StudioMember;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\AttendanceQr;
use App\Support\Notifications\Generate;
use App\Support\StudioSettings;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

class StudioSettingsConcurrencyTest extends TestCase
{
    use DatabaseMigrations, SessionFixtures;

    public static function cases(): array
    {
        $cases = [];
        foreach (['admins', 'reserve', 'cancel', 'qr', 'reminder'] as $case) {
            $cases[] = [$case, false];
            $cases[] = [$case, true];
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_competing_processes_share_policy_linearization(string $case, bool $reverse): void
    {
        [$o, $owner, , $l, $t, $g] = $this->sessionFixture();
        $secondAdmin = $owner;
        if ($case === 'admins') {
            $secondAdmin = User::factory()->create();
            $this->membership($secondAdmin, $o);
        }
        $base = $this->base($o);
        $sid = $this->postJson($base.'/sessions', $this->sessionData($g, $l, $t))->assertCreated()->json('data.id');
        $s = TrainingSession::where('public_id', $sid)->firstOrFail();
        $u = User::factory()->create();
        $m = new StudioMember;
        $m->forceFill(['organization_id' => $o->id, 'user_id' => $u->id, 'first_name' => 'Race', 'last_name' => 'Fixture', 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'app'])->save();
        $rid = null;
        if (in_array($case, ['cancel', 'qr', 'reminder'], true)) {
            $rid = $this->postJson("$base/sessions/$sid/reservations", ['member_id' => $m->public_id])->assertOk()->json('data.reservation.id');
        }
        $this->travelTo($s->starts_at->subMinutes(10));
        $qr = AttendanceQr::issue($s);
        if ($case === 'reminder') {
            $this->travelTo($s->starts_at->subHours(24));
        }
        $member = "/api/v1/member/organizations/$o->public_id";
        $setting = match ($case) {
            'cancel' => ['cancellation_cutoff_minutes' => 60],
            'qr' => ['self_check_in_enabled' => false],
            'reminder' => ['session_reminders_enabled' => false],
            default => ['self_reservation_enabled' => false],
        };
        $operation = match ($case) {
            'admins' => ['PATCH', $base.'/settings', ['self_reservation_enabled' => true], $secondAdmin->public_id],
            'reserve' => ['POST', "$member/sessions/$sid/reservations", [], $u->public_id],
            'cancel' => ['POST', "$member/reservations/$rid/cancel", [], $u->public_id],
            'qr' => ['POST', "$member/sessions/$sid/check-in", ['credential' => $qr['token']], $u->public_id],
            default => ['GENERATE', $o->public_id, [], $owner->public_id],
        };
        $tasks = [['PATCH', $base.'/settings', $setting, $owner->public_id], $operation];
        if ($reverse) {
            $tasks = array_reverse($tasks);
        }
        $workers = [];
        DB::beginTransaction();
        try {
            Organization::whereKey($o->id)->lockForUpdate()->firstOrFail();
            foreach ($tasks as $i => $task) {
                $w = new Process([PHP_BINARY, base_path('tests/Support/studio-settings-worker.php'), $task[3], (string) $i, now()->toIso8601String()], base_path(), null, json_encode($task, JSON_THROW_ON_ERROR), 30);
                $w->start();
                $workers[] = $w;
                $deadline = microtime(true) + 10;
                do {
                    DB::select('SELECT pg_stat_clear_snapshot()');
                    $blocked = (int) DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name LIKE 'gt-settings-race-%' AND wait_event_type='Lock'")->n;
                    if ($blocked === $i + 1) {
                        break;
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline);
                $this->assertSame($i + 1, $blocked, 'Both actual processes must contend on PostgreSQL locks.');
            }
            DB::commit();
            $results = [];
            foreach ($workers as $w) {
                $w->wait();
                $this->assertSame(0, $w->getExitCode(), $w->getErrorOutput());
                $results[] = trim($w->getOutput());
            }
            $policyResult = $results[$reverse ? 1 : 0];
            $actionResult = $results[$reverse ? 0 : 1];
            $this->assertSame('200', $policyResult);
            if ($case === 'admins') {
                $this->assertSame('200', $actionResult);
                $events = DB::table('audit_events')->where('action', 'studio.settings_changed')->orderBy('id')->get();
                $this->assertCount(2, $events);
                $last = json_decode($events->last()->metadata, true)['after']['self_reservation_enabled'];
                $this->assertSame($last, StudioSettings::forOrganization($o)->self_reservation_enabled);
            } elseif ($case === 'reminder') {
                $counts = json_decode($actionResult, true, 8, JSON_THROW_ON_ERROR);
                $this->assertSame(0, $counts['failed']);
                $this->assertSame($reverse ? 1 : 0, $counts['created'], 'Queued policy-first stops generation; queued scheduler-first may emit once.');
                $this->assertSame($counts['created'], DB::table('platform_notifications')->where('type', 'session.reminder')->count());
                $this->assertSame(0, Generate::run($o->public_id)['created']);
            } else {
                $this->assertContains($actionResult, ['200', '409']);
                if ($case === 'reserve') {
                    $this->assertSame($actionResult === '200' ? 1 : 0, Reservation::where('status', 'active')->count());
                } elseif ($case === 'cancel') {
                    $this->assertDatabaseHas('reservations', ['public_id' => $rid, 'status' => $actionResult === '200' ? 'cancelled' : 'active']);
                } else {
                    $this->assertDatabaseCount('attendance_records', $actionResult === '200' ? 1 : 0);
                }
                // A successful domain action must precede the settings audit, never use stale policy after it.
                if ($actionResult === '200') {
                    $policyAudit = DB::table('audit_events')->where('action', 'studio.settings_changed')->max('id');
                    $action = match ($case) {
                        'reserve' => 'reservation.created', 'cancel' => 'reservation.cancelled', default => 'attendance.marked'
                    };
                    $this->assertLessThan($policyAudit, DB::table('audit_events')->where('action', $action)->max('id'));
                }
            }
            $this->artisan('gt:attendance:check')->assertSuccessful();
            $this->artisan('gt:reservations:check')->assertSuccessful();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $w) {
                if ($w->isRunning()) {
                    $w->stop();
                }
            }
        }
    }
}
