<?php

namespace App\Support;

use App\Models\AttendanceRecord;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\StudioMember;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WaitingListEntry;
use App\Support\Notifications\Events;
use App\Support\SelfService\MemberContext;
use Illuminate\Support\Facades\Gate;

final class Attendance
{
    /** Staff entry point; no caller-selected method, actor or timestamp. */
    public static function mark(TenantContext $c, User $u, string $session, string $member, string $status, bool $correction = false, ?string $reason = null, bool $walkIn = false): AttendanceRecord
    {
        return Lifecycle::run($c, $u, $correction ? Permission::AttendanceCorrect : Permission::AttendanceManage, function () use ($c, $u, $session, $member, $status, $correction, $reason, $walkIn) {
            Gate::forUser($u)->authorize(Permission::AttendanceView->value);
            $s = Allocation::lock(SessionDirectory::query($c, $u)->where('public_id', $session)->firstOrFail());
            $m = StudioMember::query()->where('organization_id', $s->organization_id)->where('public_id', $member)->firstOrFail();
            AttendancePolicy::staffWindow($s, $status, $correction);
            if ($walkIn) {
                Gate::forUser($u)->authorize(Permission::ReservationsManage->value);
                Gate::forUser($u)->authorize(Permission::ReservationsView->value);
                $result = Allocation::reserve($s, $m, $u, false, true);
                if ($result['reservation'] === null) {
                    throw new AttendanceConflict('reservation_required');
                }
            }
            $r = self::activeReservation($s, $m);
            $method = $c->membership()->role === StaffRole::Trainer ? 'trainer' : 'staff';

            return self::write($s, $r, $status, $method, $u, $correction, $reason, $walkIn ? 'attendance.walk_in' : 'attendance.marked');
        });
    }

    public static function activeReservation(TrainingSession $s, StudioMember $m): Reservation
    {
        if ($m->status !== MemberStatus::Active || $m->organization_id !== $s->organization_id) {
            throw new AttendanceConflict('member_ineligible');
        }
        $r = Reservation::query()->where('organization_id', $s->organization_id)->where('training_session_id', $s->id)->where('studio_member_id', $m->id)->where('status', 'active')->first();
        if ($r === null) {
            throw new AttendanceConflict('reservation_required');
        }

        return $r;
    }

    /** Internal; organization then session locks required. */
    public static function write(TrainingSession $s, Reservation $r, string $status, string $method, ?User $u, bool $correction = false, ?string $reason = null, string $action = 'attendance.marked'): AttendanceRecord
    {
        $a = AttendanceRecord::query()->where('training_session_id', $s->id)->where('studio_member_id', $r->studio_member_id)->first();
        if ($a !== null && $a->status === $status && $a->reservation_id === $r->id) {
            return $a;
        }
        if ($a !== null && ! $correction) {
            throw new AttendanceConflict('correction_required');
        }
        if ($correction && ! in_array($reason, ['mistake', 'late_confirmation'], true)) {
            throw new AttendanceConflict('reason_required');
        }
        $before = $a === null ? null : ['status' => $a->status, 'method' => $a->check_in_method, 'checked_in_at' => $a->checked_in_at?->toISOString()];
        $a ??= new AttendanceRecord;
        $a->forceFill(['organization_id' => $s->organization_id, 'training_session_id' => $s->id, 'studio_member_id' => $r->studio_member_id, 'reservation_id' => $r->id, 'status' => $status, 'check_in_method' => $method, 'checked_in_at' => $status === 'present' ? now() : null, 'recorded_by' => $u?->id])->save();
        $eventId = Audit::record(Organization::findOrFail($s->organization_id), $u, $correction ? 'attendance.corrected' : $action, 'attendance_record', $a->public_id, ['session_id' => $s->public_id, 'before' => $before, 'after' => ['status' => $status, 'method' => $method, 'checked_in_at' => $a->checked_in_at?->toISOString()], 'reason' => $reason]);

        if ($status !== 'present' || $correction) {
            Events::attendance($a, $eventId);
        }

        return $a;
    }

    /** Authenticated member adapter; identity and authority are re-resolved under the organization lock. */
    public static function selfCheckIn(User $authenticatedUser, string $organization, string $session, ?string $qr = null, bool $detailed = false): AttendanceRecord
    {
        return MemberContext::mutate($authenticatedUser, $organization, function (MemberContext $context) use ($session, $qr, $detailed) {
            $m = $context->member;
            $s = Allocation::lock(TrainingSession::query()->where('organization_id', $context->organization->id)->where('public_id', $session)->firstOrFail());
            AttendancePolicy::memberWindow($s, $detailed);
            if ($qr !== null) {
                AttendanceQr::validate($s, $qr, $detailed);
            }
            if ($detailed && ! Reservation::where('training_session_id', $s->id)->where('studio_member_id', $m->id)->where('status', 'active')->exists()) {
                if (WaitingListEntry::where('training_session_id', $s->id)->where('studio_member_id', $m->id)->where('status', 'waiting')->exists()) {
                    throw new AttendanceConflict('waiting');
                }
                throw new AttendanceConflict('reservation_required');
            }

            return self::write($s, self::activeReservation($s, $m), 'present', $qr === null ? 'member' : 'qr', $context->user);
        });
    }

    /** Caller holds organization lock. Skips resolved rows; finalization never corrects a check-in. */
    public static function finalize(TrainingSession $session): int
    {
        $s = Allocation::lock($session);
        if ($s->status !== 'scheduled' || now()->lt($s->ends_at)) {
            return 0;
        }
        $count = 0;
        foreach (Reservation::query()->where('training_session_id', $s->id)->where('status', 'active')->whereNotIn('studio_member_id', AttendanceRecord::query()->where('training_session_id', $s->id)->select('studio_member_id'))->orderBy('id')->get() as $r) {
            self::write($s, $r, 'no_show', 'staff', null, false, null, 'attendance.finalized');
            $count++;
        }

        return $count;
    }

    /** @return array<string,mixed> */
    public static function represent(AttendanceRecord $a): array
    {
        return ['id' => $a->public_id, 'status' => $a->status, 'method' => $a->check_in_method, 'checked_in_at' => $a->checked_in_at?->toISOString()];
    }
}
