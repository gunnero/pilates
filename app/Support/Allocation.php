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
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Internal engine. All entry points must hold the organization lock first. */
final class Allocation
{
    public static function lock(TrainingSession $session): TrainingSession
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Allocation requires a transaction.');
        }

        return TrainingSession::query()->where('organization_id', $session->organization_id)->whereKey($session->id)->lockForUpdate()->firstOrFail();
    }

    public static function eligible(TrainingSession $s, bool $walkIn = false): void
    {
        if ($s->status !== 'scheduled') {
            throw new ReservationConflict('session_cancelled');
        }
        if ($walkIn) {
            AttendancePolicy::staffWindow($s, 'present', false);
        }
        if (! $walkIn && ! $s->starts_at->isFuture()) {
            throw new ReservationConflict('session_started');
        }
        if (SessionDirectory::issues($s) !== []) {
            throw new ReservationConflict('session_unavailable');
        }
    }

    public static function count(TrainingSession $s): int
    {
        return Reservation::query()->where('training_session_id', $s->id)->where('status', 'active')->count();
    }

    /** The only active-reservation insertion path. Caller holds session and organization locks. */
    public static function insert(TrainingSession $s, StudioMember $m, string $source, ?User $actor = null, ?int $spotId = null, bool $walkIn = false): Reservation
    {
        self::eligible($s, $walkIn);
        if ($m->organization_id !== $s->organization_id || $m->status !== MemberStatus::Active) {
            throw new ReservationConflict('member_ineligible');
        }
        if (self::count($s) >= $s->capacity) {
            throw new ReservationConflict('capacity_conflict');
        }
        $r = new Reservation;
        $r->forceFill(['organization_id' => $s->organization_id, 'training_session_id' => $s->id, 'studio_member_id' => $m->id, 'source' => $source, 'permanent_spot_id' => $spotId, 'created_by' => $actor?->id, 'status' => 'active'])->save();

        Events::reservation($r, $source === 'waiting_list' ? NotificationType::WaitingPromoted : NotificationType::ReservationConfirmed);

        return $r;
    }

    /** FIFO runs before any new caller may claim free capacity. No SKIP LOCKED. */
    public static function promote(TrainingSession $s, ?User $actor = null, bool $walkIn = false): int
    {
        if ($s->status !== 'scheduled' || (! $s->starts_at->isFuture() && ! $walkIn) || now()->gte($s->ends_at) || SessionDirectory::issues($s) !== []) {
            return 0;
        }
        $count = 0;
        while (self::count($s) < $s->capacity) {
            $entry = WaitingListEntry::query()->where('training_session_id', $s->id)->where('status', 'waiting')->orderBy('joined_at')->orderBy('id')->lockForUpdate()->first();
            if ($entry === null) {
                break;
            }
            if ($entry->member->status !== MemberStatus::Active) {
                $entry->forceFill(['status' => 'ineligible', 'cancelled_at' => now()])->save();

                continue;
            }
            $r = Reservation::query()->where('training_session_id', $s->id)->where('studio_member_id', $entry->studio_member_id)->where('status', 'active')->first();
            if ($r === null) {
                $r = self::insert($s, $entry->member, 'waiting_list', $actor, null, $walkIn);
            }
            $entry->forceFill(['status' => 'promoted', 'promoted_at' => now(), 'reservation_id' => $r->id])->save();
            $count++;
        }
        if ($count > 0) {
            Audit::record(Organization::findOrFail($s->organization_id), $actor, 'waiting_list.promoted', 'training_session', $s->public_id, ['count' => $count]);
        }

        return $count;
    }

    /** @return array{outcome:string,reservation:?Reservation,waiting_entry:?WaitingListEntry} */
    public static function reserve(TrainingSession $s, StudioMember $m, User $actor, bool $allowWaiting, bool $walkIn = false, string $source = 'staff'): array
    {
        if (! in_array($source, ['staff', 'member'], true)) {
            throw new LogicException('Invalid reservation source');
        }
        $s = self::lock($s);
        self::eligible($s, $walkIn);
        if ($m->organization_id !== $s->organization_id || $m->status !== MemberStatus::Active) {
            throw new ReservationConflict('member_ineligible');
        }
        self::promote($s, $actor, $walkIn);
        $r = Reservation::query()->where('training_session_id', $s->id)->where('studio_member_id', $m->id)->where('status', 'active')->first();
        if ($r !== null) {
            return ['outcome' => 'already_reserved', 'reservation' => $r, 'waiting_entry' => null];
        }
        $w = WaitingListEntry::query()->where('training_session_id', $s->id)->where('studio_member_id', $m->id)->where('status', 'waiting')->first();
        if ($w !== null) {
            return ['outcome' => 'already_waiting', 'reservation' => null, 'waiting_entry' => $w];
        }
        if (self::count($s) < $s->capacity) {
            $r = self::insert($s, $m, $source, $actor, null, $walkIn);
            Audit::record(Organization::findOrFail($s->organization_id), $actor, 'reservation.created', 'reservation', $r->public_id, ['session_id' => $s->public_id]);

            return ['outcome' => 'reserved', 'reservation' => $r, 'waiting_entry' => null];
        }
        if (! $allowWaiting) {
            throw new ReservationConflict('capacity_conflict');
        }
        if (WaitingListEntry::query()->where('training_session_id', $s->id)->where('status', 'waiting')->count() >= 1000) {
            throw new ReservationConflict('limit');
        }
        $w = new WaitingListEntry;
        $w->forceFill(['organization_id' => $s->organization_id, 'training_session_id' => $s->id, 'studio_member_id' => $m->id, 'source' => $source, 'status' => 'waiting', 'created_by' => $actor->id, 'joined_at' => now()])->save();
        Audit::record(Organization::findOrFail($s->organization_id), $actor, 'waiting_list.joined', 'waiting_list_entry', $w->public_id, ['session_id' => $s->public_id]);

        return ['outcome' => 'waitlisted', 'reservation' => null, 'waiting_entry' => $w];
    }

    public static function cancel(TrainingSession $s, Reservation $r, ?User $actor = null, bool $suppress = true): void
    {
        if (AttendanceRecord::query()->where('reservation_id', $r->id)->where('status', 'present')->exists()) {
            throw new AttendanceConflict('present_cancellation');
        }
        if ($r->status === 'cancelled') {
            return;
        }
        if (! $s->starts_at->isFuture()) {
            throw new ReservationConflict('session_started');
        }
        $r->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        Events::reservation($r, NotificationType::ReservationCancelled);
        if ($suppress) {
            DB::table('permanent_spot_releases')->insertOrIgnore(['organization_id' => $s->organization_id, 'training_session_id' => $s->id, 'studio_member_id' => $r->studio_member_id, 'released_at' => now()]);
        }
        Audit::record(Organization::findOrFail($s->organization_id), $actor, 'reservation.cancelled', 'reservation', $r->public_id, ['session_id' => $s->public_id, 'occurrence_release' => $suppress]);
        self::promote($s, $actor);
    }

    public static function cancelWaiting(WaitingListEntry $w, User $actor): void
    {
        self::lock($w->session);
        if ($w->status === 'waiting') {
            $w->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
            Audit::record(Organization::findOrFail($w->organization_id), $actor, 'waiting_list.cancelled', 'waiting_list_entry', $w->public_id, []);
        }
    }
}
