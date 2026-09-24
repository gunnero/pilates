<?php

namespace App\Support\SelfService;

use App\Models\AttendanceRecord;
use App\Models\CashPayment;
use App\Models\MemberMembership;
use App\Models\Reservation;
use App\Models\StudioMember;
use App\Models\TrainingSession;
use App\Support\MembershipPeriod;
use App\Support\SessionDirectory;
use App\Support\StudioSettings;
use Illuminate\Database\Eloquent\Builder;

final class Directory
{
    /** @return Builder<TrainingSession> */
    public static function sessions(MemberContext $c): Builder
    {
        return SessionDirectory::capacityQuery(TrainingSession::where('organization_id', $c->organization->id))->selectSub(AttendanceRecord::select('status')->whereColumn('training_session_id', 'training_sessions.id')->where('studio_member_id', $c->member->id)->limit(1), 'member_attendance')->with(['group', 'location', 'trainer.membership.locations', 'reservations' => fn ($q) => $q->where('studio_member_id', $c->member->id)->orderByDesc('id')->limit(1), 'waitingEntries' => fn ($q) => $q->where('studio_member_id', $c->member->id)->orderByDesc('id')->limit(1)]);
    }

    /** @return array<string,mixed> */
    public static function session(TrainingSession $s, ?StudioSettings $policy = null): array
    {
        $policy ??= StudioSettings::forOrganization($s->organization_id);
        $r = $s->reservations->first();
        $w = $s->waitingEntries->first();
        $ready = $s->status === 'scheduled' && $s->starts_at->isFuture() && SessionDirectory::issues($s) === [];

        return ['id' => $s->public_id, 'group' => ['id' => $s->group->public_id, 'name' => $s->group_name], 'location' => ['id' => $s->location->public_id, 'name' => $s->location_name], 'trainer' => ['id' => $s->trainer->public_id, 'name' => $s->trainer_name], 'local_date' => $s->local_date, 'starts_at' => $s->starts_at->toISOString(), 'ends_at' => $s->ends_at->toISOString(), 'timezone' => $s->timezone, 'status' => $s->status, 'capacity' => $s->capacity, 'available_spots' => SessionDirectory::available($s), 'can_reserve' => $ready && $policy->canReserve($s), 'can_join_waiting' => $ready && $policy->canReserve($s) && $policy->waiting_list_enabled && $s->waiting_count < 1000, 'attendance_status' => $s->getAttribute('member_attendance') ?? 'pending', 'reservation' => $r === null ? null : self::reservation($r, $s, $policy), 'waiting_entry' => $w === null ? null : ['id' => $w->public_id, 'status' => $w->status, 'can_cancel' => $w->status === 'waiting' && $policy->self_cancellation_enabled]];
    }

    /** @return array<string,mixed> */
    public static function reservation(Reservation $r, TrainingSession $s, ?StudioSettings $policy = null): array
    {
        $policy ??= StudioSettings::forOrganization($s->organization_id);

        return ['id' => $r->public_id, 'status' => $r->status, 'source' => $r->source, 'can_cancel' => $r->status === 'active' && $policy->canCancel($s) && $s->getAttribute('member_attendance') !== 'present'];
    }

    /** @return array<string,mixed> */
    public static function profile(StudioMember $m): array
    {
        return ['id' => $m->public_id, 'first_name' => $m->first_name, 'last_name' => $m->last_name, 'email' => $m->email, 'phone' => $m->phone, 'preferred_locale' => $m->preferred_locale];
    }

    /** @return Builder<MemberMembership> */
    public static function memberships(MemberContext $c): Builder
    {
        return MemberMembership::where('organization_id', $c->organization->id)->where('studio_member_id', $c->member->id)->select('member_memberships.*')->selectRaw('(SELECT COALESCE(SUM(p.amount_minor),0)::bigint FROM cash_payments p WHERE p.member_membership_id=member_memberships.id) AS gross_paid_minor')->selectRaw('(SELECT COALESCE(SUM(r.amount_minor),0)::bigint FROM payment_reversals r JOIN cash_payments p ON p.id=r.cash_payment_id WHERE p.member_membership_id=member_memberships.id) AS reversed_minor');
    }

    /** @return array<string,mixed> */
    public static function membership(MemberMembership $m, MemberContext $c): array
    {
        $net = (int) $m->getAttribute('gross_paid_minor') - (int) $m->getAttribute('reversed_minor');

        return ['id' => $m->public_id, 'plan_name' => $m->plan_name, 'starts_on' => $m->starts_on, 'ends_on' => $m->ends_on, 'status' => MembershipPeriod::state($m, now($c->organization->timezone)->toDateString()), 'agreed_price_minor' => $m->agreed_price_minor, 'currency' => $m->currency, 'net_paid_minor' => $net, 'balance_minor' => $m->agreed_price_minor - $net];
    }

    /** @return Builder<CashPayment> */
    public static function payments(MemberContext $c): Builder
    {
        return CashPayment::where('organization_id', $c->organization->id)->where('studio_member_id', $c->member->id)->with(['membership', 'reversal']);
    }

    /** @return array<string,mixed> */
    public static function payment(CashPayment $p): array
    {
        return ['id' => $p->public_id, 'membership_id' => $p->membership->public_id, 'amount_minor' => $p->amount_minor, 'currency' => $p->currency, 'paid_at' => $p->paid_at->toISOString(), 'status' => $p->reversal === null ? 'recorded' : 'reversed', 'reversal' => $p->reversal === null ? null : ['id' => $p->reversal->public_id, 'amount_minor' => $p->reversal->amount_minor, 'created_at' => $p->reversal->created_at?->toISOString()]];
    }
}
