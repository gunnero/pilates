<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\PermanentSpot;
use App\Models\Reservation;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WaitingListEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PermanentAllocation
{
    /** Inclusive intervals; sweep boundaries, not total row count. Ignore expired demand. */
    public static function assertCapacity(TrainingGroup $g, ?PermanentSpot $candidate = null): void
    {
        $today = now(Organization::findOrFail($g->organization_id)->timezone)->toDateString();
        $spots = PermanentSpot::query()->where('training_group_id', $g->id)->where('status', 'active')->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $today))->when($candidate?->exists, fn ($q) => $q->whereKeyNot($candidate?->id))->limit(10001)->get();
        if ($spots->count() > 10000) {
            throw new ReservationConflict('limit');
        }
        if ($candidate !== null && $candidate->status === 'active' && ($candidate->effective_until === null || $candidate->effective_until >= $today)) {
            $spots->push($candidate);
        }
        $events = [];
        foreach ($spots as $spot) {
            $start = max($today, $spot->effective_from);
            $events[$start] = ($events[$start] ?? 0) + 1;
            if ($spot->effective_until !== null) {
                $end = CarbonImmutable::parse($spot->effective_until)->addDay()->toDateString();
                $events[$end] = ($events[$end] ?? 0) - 1;
            }
        }
        ksort($events);
        $demand = 0;
        foreach ($events as $delta) {
            $demand += $delta;
            if ($demand > $g->capacity) {
                throw new ReservationConflict('permanent_spot_conflict');
            }
        }
    }

    /** Caller holds organization then session lock.
     * @return array<string,int> */
    public static function session(TrainingSession $s): array
    {
        $result = ['created' => 0, 'existing' => 0, 'released' => 0, 'ineligible' => 0, 'conflicts' => 0];
        if ($s->source_type !== 'recurring' || $s->status !== 'scheduled' || ! $s->starts_at->isFuture() || SessionDirectory::issues($s) !== []) {
            return $result;
        }
        Allocation::promote($s);
        $spots = PermanentSpot::query()->where('organization_id', $s->organization_id)->where('training_group_id', $s->training_group_id)->where('status', 'active')->where('effective_from', '<=', $s->occurrence_date)->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $s->occurrence_date))->with('member')->orderBy('id')->get();
        foreach ($spots as $spot) {
            if ($spot->member->status !== MemberStatus::Active) {
                $result['ineligible']++;

                continue;
            }
            if (DB::table('permanent_spot_releases')->where('training_session_id', $s->id)->where('studio_member_id', $spot->studio_member_id)->exists()) {
                $result['released']++;

                continue;
            }
            if (Reservation::query()->where('training_session_id', $s->id)->where('studio_member_id', $spot->studio_member_id)->where('status', 'active')->exists()) {
                $result['existing']++;

                continue;
            }
            if (Allocation::count($s) >= $s->capacity || WaitingListEntry::query()->where('training_session_id', $s->id)->where('studio_member_id', $spot->studio_member_id)->where('status', 'waiting')->exists()) {
                $result['conflicts']++;

                continue;
            }
            Allocation::insert($s, $spot->member, 'permanent_spot', null, $spot->id);
            $result['created']++;
        }

        return $result;
    }

    /** Caller holds organization lock. Bounded rolling horizon.
     * @return array<string,int> */
    public static function group(TrainingGroup $g): array
    {
        $org = Organization::findOrFail($g->organization_id);
        $last = now($org->timezone)->addDays(55)->toDateString();
        $sessions = TrainingSession::query()->where('training_group_id', $g->id)->where('starts_at', '>', now())->where('local_date', '<=', $last)->orderBy('id')->limit(801)->get();
        if ($sessions->count() > 800) {
            throw new ReservationConflict('limit');
        }
        $result = ['created' => 0, 'existing' => 0, 'released' => 0, 'ineligible' => 0, 'conflicts' => 0];
        foreach ($sessions as $s) {
            foreach (self::session(Allocation::lock($s)) as $key => $value) {
                $result[$key] += $value;
            }
        }

        return $result;
    }

    /** Only attributable future allocations are reconciled. Caller holds organization lock. */
    public static function reconcile(PermanentSpot $spot, User $actor): void
    {
        $rows = Reservation::query()->where('permanent_spot_id', $spot->id)->where('status', 'active')->whereHas('session', fn ($q) => $q->where('starts_at', '>', now()))->with('session')->orderBy('training_session_id')->get();
        foreach ($rows as $r) {
            $s = Allocation::lock($r->session);
            if ($spot->status === 'inactive' || $s->occurrence_date < $spot->effective_from || ($spot->effective_until !== null && $s->occurrence_date > $spot->effective_until)) {
                Allocation::cancel($s, $r, $actor, false);
            }
        }
    }
}
