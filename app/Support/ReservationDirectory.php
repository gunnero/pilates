<?php

namespace App\Support;

use App\Models\PermanentSpot;
use App\Models\Reservation;
use App\Models\StudioMember;
use App\Models\User;
use App\Models\WaitingListEntry;
use Illuminate\Database\Eloquent\Builder;

final class ReservationDirectory
{
    /** @return array<string,mixed> */
    public static function member(StudioMember $m): array
    {
        return ['id' => $m->public_id, 'display_name' => $m->first_name.' '.$m->last_name];
    }

    /** @return array<string,mixed> */
    public static function reservation(Reservation $r): array
    {
        return ['id' => $r->public_id, 'member' => self::member($r->member), 'status' => $r->status, 'source' => $r->source, 'created_at' => $r->created_at?->toISOString(), 'session_id' => $r->session->public_id, 'session_name' => $r->session->group_name, 'local_date' => $r->session->local_date, 'start_time' => $r->session->starts_at->setTimezone($r->session->timezone)->format('H:i'), 'permanent_spot_id' => $r->permanentSpot?->public_id];
    }

    /** @return array<string,mixed> */
    public static function waiting(WaitingListEntry $w): array
    {
        return ['id' => $w->public_id, 'member' => self::member($w->member), 'status' => $w->status, 'source' => $w->source, 'joined_at' => $w->joined_at->toISOString()];
    }

    /** @return array<string,mixed> */
    public static function spot(PermanentSpot $p): array
    {
        return ['id' => $p->public_id, 'member' => self::member($p->member), 'group_id' => $p->group->public_id, 'group_name' => $p->group->name, 'status' => $p->status, 'effective_from' => $p->effective_from, 'effective_until' => $p->effective_until];
    }

    /** @return Builder<Reservation> */
    public static function reservations(TenantContext $c, User $u): Builder
    {
        return Reservation::query()->where('organization_id', $c->organization()->id)->whereIn('training_session_id', SessionDirectory::query($c, $u)->select('training_sessions.id')->withoutEagerLoads())->with(['member', 'session', 'permanentSpot']);
    }

    /** @return Builder<WaitingListEntry> */
    public static function waitingEntries(TenantContext $c, User $u): Builder
    {
        return WaitingListEntry::query()->where('organization_id', $c->organization()->id)->whereIn('training_session_id', SessionDirectory::query($c, $u)->select('training_sessions.id')->withoutEagerLoads())->with('member');
    }

    /** @return Builder<PermanentSpot> */
    public static function spots(TenantContext $c, User $u): Builder
    {
        return PermanentSpot::query()->where('organization_id', $c->organization()->id)->whereIn('training_group_id', GroupDirectory::query($c, $u)->select('training_groups.id')->withoutEagerLoads())->with(['member', 'group']);
    }
}
