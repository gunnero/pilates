<?php

namespace App\Support;

use App\Models\AttendanceRecord;
use App\Models\Reservation;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Request-local projections only. All mutations remain in the owning services. */
final class OperationsDirectory
{
    /** @return Builder<TrainingSession> */
    public static function query(TenantContext $c, User $u): Builder
    {
        Gate::forUser($u)->authorize(Permission::AttendanceView->value);
        $q = SessionDirectory::query($c, $u);
        foreach (['present', 'absent', 'no_show'] as $status) {
            $q->selectSub(AttendanceRecord::query()->selectRaw('count(*)')->whereColumn('training_session_id', 'training_sessions.id')->whereColumn('organization_id', 'training_sessions.organization_id')->where('status', $status), $status.'_count');
        }
        $q->selectSub(Reservation::query()->selectRaw('count(*)')->whereColumn('training_session_id', 'training_sessions.id')->whereColumn('organization_id', 'training_sessions.organization_id')->where('status', 'active')->whereNotIn('id', AttendanceRecord::query()->where('organization_id', $c->organization()->id)->select('reservation_id')), 'pending_count');

        return $q;
    }

    /** @return array<string,mixed> */
    public static function session(TrainingSession $s, User $u): array
    {
        $data = SessionDirectory::represent($s);
        $operational = $s->status === 'scheduled' && $data['operational_issues'] === [];
        $ended = now()->gte($s->ends_at);
        $attendance = [];
        foreach (['present', 'absent', 'no_show', 'pending'] as $key) {
            $attendance[$key] = (int) $s->getAttribute($key.'_count');
        }

        return $data + ['phase' => $s->status === 'cancelled' ? 'cancelled' : ($ended ? 'completed' : (now()->lt($s->starts_at) ? 'upcoming' : 'in_progress')), 'attendance' => $attendance, 'actions' => ['present' => $operational && $data['attendance_open'] && Gate::forUser($u)->allows(Permission::AttendanceManage->value), 'absence' => $operational && $ended && Gate::forUser($u)->allows(Permission::AttendanceManage->value), 'correct' => $operational && now()->gte($s->starts_at->subMinutes(AttendancePolicy::EARLY_MINUTES)) && Gate::forUser($u)->allows(Permission::AttendanceCorrect->value), 'walk_in' => $operational && $data['attendance_open'] && Gate::forUser($u)->allows(Permission::AttendanceManage->value) && Gate::forUser($u)->allows(Permission::ReservationsView->value) && Gate::forUser($u)->allows(Permission::ReservationsManage->value)]];
    }

    /** A stable snapshot across summary, pagination and related projections; no write locks.
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    public static function snapshot(callable $read): mixed
    {
        // Tests may already own a transaction; production HTTP starts a fresh read transaction.
        if (DB::transactionLevel() > 0) {
            return $read();
        }

        return DB::transaction(function () use ($read) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $read();
        });
    }
}
