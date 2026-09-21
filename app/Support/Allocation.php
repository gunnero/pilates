<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\Reservation;
use App\Models\StudioMember;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WaitingListEntry;
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

    public static function eligible(TrainingSession $s): void
    {
        if ($s->status !== 'scheduled') {
            throw new ReservationConflict('session_cancelled');
        }
        if (! $s->starts_at->isFuture()) {
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
    public static function insert(TrainingSession $s, StudioMember $m, string $source, ?User $actor = null, ?int $spotId = null): Reservation
    {
        self::eligible($s);
        if ($m->organization_id !== $s->organization_id || $m->status !== MemberStatus::Active) {
            throw new ReservationConflict('member_ineligible');
        }
        if (self::count($s) >= $s->capacity) {
            throw new ReservationConflict('capacity_conflict');
        }
        $r = new Reservation;
        $r->forceFill(['organization_id' => $s->organization_id, 'training_session_id' => $s->id, 'studio_member_id' => $m->id, 'source' => $source, 'permanent_spot_id' => $spotId, 'created_by' => $actor?->id, 'status' => 'active'])->save();

        return $r;
    }

    /** FIFO runs before any new caller may claim free capacity. No SKIP LOCKED. */
    public static function promote(TrainingSession $s, ?User $actor = null): int
    {
        if ($s->status !== 'scheduled' || ! $s->starts_at->isFuture() || SessionDirectory::issues($s) !== []) {
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
                $r = self::insert($s, $entry->member, 'waiting_list', $actor);
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
    public static function reserve(TrainingSession $s, StudioMember $m, User $actor, bool $allowWaiting): array
    {
        $s = self::lock($s);
        self::eligible($s);
        if ($m->organization_id !== $s->organization_id || $m->status !== MemberStatus::Active) {
            throw new ReservationConflict('member_ineligible');
        }
        self::promote($s, $actor);
        $r = Reservation::query()->where('training_session_id', $s->id)->where('studio_member_id', $m->id)->where('status', 'active')->first();
        if ($r !== null) {
            return ['outcome' => 'already_reserved', 'reservation' => $r, 'waiting_entry' => null];
        }
        $w = WaitingListEntry::query()->where('training_session_id', $s->id)->where('studio_member_id', $m->id)->where('status', 'waiting')->first();
        if ($w !== null) {
            return ['outcome' => 'already_waiting', 'reservation' => null, 'waiting_entry' => $w];
        }
        if (self::count($s) < $s->capacity) {
            $r = self::insert($s, $m, 'staff', $actor);
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
        $w->forceFill(['organization_id' => $s->organization_id, 'training_session_id' => $s->id, 'studio_member_id' => $m->id, 'source' => 'staff', 'status' => 'waiting', 'created_by' => $actor->id, 'joined_at' => now()])->save();
        Audit::record(Organization::findOrFail($s->organization_id), $actor, 'waiting_list.joined', 'waiting_list_entry', $w->public_id, ['session_id' => $s->public_id]);

        return ['outcome' => 'waitlisted', 'reservation' => null, 'waiting_entry' => $w];
    }

    public static function cancel(TrainingSession $s, Reservation $r, ?User $actor = null, bool $suppress = true): void
    {
        if ($r->status === 'cancelled') {
            return;
        }
        if (! $s->starts_at->isFuture()) {
            throw new ReservationConflict('session_started');
        }
        $r->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        if ($suppress) {
            DB::table('permanent_spot_releases')->insertOrIgnore(['organization_id' => $s->organization_id, 'training_session_id' => $s->id, 'studio_member_id' => $r->studio_member_id, 'released_at' => now()]);
        }
        Audit::record(Organization::findOrFail($s->organization_id), $actor, 'reservation.cancelled', 'reservation', $r->public_id, ['session_id' => $s->public_id, 'occurrence_release' => $suppress]);
        self::promote($s, $actor);
    }
}
