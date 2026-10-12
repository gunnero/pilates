<?php

namespace App\Support\Reporting;

use App\Models\MembershipPlan;
use App\Models\TrainerProfile;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Support\SessionDirectory;
use App\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** All relations are reduced to canonical grain before reporting aggregation. */
final class ReportQuery
{
    public function __construct(private TenantContext $context, private User $actor, private ReportFilters $filters) {}

    public function validateReferences(): void
    {
        $f = $this->filters->input;
        $org = $this->context->organization()->id;
        if (isset($f['plan_id'])) {
            MembershipPlan::where('organization_id', $org)->where('public_id', $f['plan_id'])->firstOrFail();
        }
        foreach (['group_id' => 'training_group_id', 'trainer_id' => 'trainer_profile_id'] as $key => $column) {
            if (! isset($f[$key])) {
                continue;
            }
            $model = $key === 'group_id' ? TrainingGroup::query() : TrainerProfile::query();
            $item = $model->where('organization_id', $org)->where('public_id', $f[$key])->firstOrFail();
            // No filter option may broaden the session scope; inaccessible identifiers are concealed.
            abort_unless(SessionDirectory::query($this->context, $this->actor)->where($column, $item->id)->exists(), 404);
        }
    }

    public function cash(): Builder
    {
        $q = DB::table('cash_payments as p')->join('member_memberships as m', function ($j) {
            $j->on('m.id', '=', 'p.member_membership_id')->on('m.organization_id', '=', 'p.organization_id');
        })
            ->leftJoin('payment_reversals as r', function ($j) {
                $j->on('r.cash_payment_id', '=', 'p.id')->on('r.organization_id', '=', 'p.organization_id');
            })
            ->where('p.organization_id', $this->context->organization()->id)->where('p.paid_at', '>=', $this->filters->start())->where('p.paid_at', '<', $this->filters->end());
        if (isset($this->filters->input['plan_id'])) {
            $q->whereIn('m.membership_plan_id', MembershipPlan::where('organization_id', $this->context->organization()->id)->where('public_id', $this->filters->input['plan_id'])->select('id'));
        }

        return $q->select(['p.id', 'p.public_id', 'p.paid_at', 'p.currency', 'm.plan_name'])->selectRaw('p.amount_minor as gross_minor, coalesce(r.amount_minor,0) as reversed_minor, p.amount_minor-coalesce(r.amount_minor,0) as net_minor');
    }

    public function periods(): Builder
    {
        $org = $this->context->organization()->id;
        $cash = DB::table('cash_payments as p')->leftJoin('payment_reversals as r', function ($j) {
            $j->on('r.cash_payment_id', '=', 'p.id')->on('r.organization_id', '=', 'p.organization_id');
        })->where('p.organization_id', $org)->groupBy('p.member_membership_id')->select('p.member_membership_id')->selectRaw('sum(p.amount_minor-coalesce(r.amount_minor,0)) as net_minor');
        $today = $this->filters->today();
        $base = DB::table('member_memberships as m')->join('studio_members as sm', function ($j) {
            $j->on('sm.id', '=', 'm.studio_member_id')->on('sm.organization_id', '=', 'm.organization_id');
        })->leftJoinSub($cash, 'cash', 'cash.member_membership_id', '=', 'm.id')->where('m.organization_id', $org);
        if (isset($this->filters->input['plan_id'])) {
            $base->whereIn('m.membership_plan_id', MembershipPlan::where('organization_id', $org)->where('public_id', $this->filters->input['plan_id'])->select('id'));
        }
        $base->select(['m.id', 'm.public_id', 'm.studio_member_id', 'm.plan_name', 'm.starts_on', 'm.ends_on', 'm.currency', 'm.agreed_price_minor', 'm.cancelled_at', 'sm.status as member_status'])->selectRaw("sm.public_id as member_id, sm.first_name || ' ' || sm.last_name as member_name, coalesce(cash.net_minor,0) as paid_minor, m.agreed_price_minor-coalesce(cash.net_minor,0) as balance_minor, CASE WHEN m.cancelled_at IS NOT NULL THEN 'cancelled' WHEN m.starts_on>? THEN 'upcoming' WHEN m.ends_on<? THEN 'expired' ELSE 'active' END as state", [$today, $today])->selectRaw("CASE WHEN m.ends_on<? THEN 'expired' WHEN m.ends_on<=? THEN '7' WHEN m.ends_on<=? THEN '14' WHEN m.ends_on<=? THEN '30' ELSE 'later' END as expiry", [$today, $this->filters->clock->addDays(7)->toDateString(), $this->filters->clock->addDays(14)->toDateString(), $this->filters->clock->addDays(30)->toDateString()]);
        $q = DB::query()->fromSub($base, 'periods')->select('*')->selectRaw("CASE WHEN balance_minor<0 THEN 'overpaid' WHEN balance_minor=0 THEN 'paid' WHEN paid_minor=0 THEN 'unpaid' ELSE 'partially_paid' END as payment_state");
        $q = DB::query()->fromSub($q, 'settlement')->select('*');
        foreach (['state', 'payment_state', 'expiry'] as $key) {
            if (isset($this->filters->input[$key])) {
                $q->where($key, $this->filters->input[$key]);
            }
        }
        if ($this->filters->input['report'] === 'expiry') {
            $q->whereNull('cancelled_at');
        }

        return $q;
    }

    public function sessions(): Builder
    {
        $org = $this->context->organization()->id;
        $scope = SessionDirectory::query($this->context, $this->actor)->withoutEagerLoads()->select('training_sessions.*')->where('starts_at', '>=', $this->filters->start())->where('starts_at', '<', $this->filters->end());
        foreach (['group_id' => 'group', 'trainer_id' => 'trainer'] as $key => $relation) {
            if (isset($this->filters->input[$key])) {
                $scope->whereHas($relation, fn ($q) => $q->where('public_id', $this->filters->input[$key]));
            }
        }
        $counts = DB::table('reservations as r')->leftJoin('attendance_records as a', function ($j) {
            $j->on('a.reservation_id', '=', 'r.id')->on('a.organization_id', '=', 'r.organization_id');
        })->where('r.organization_id', $org)->groupBy('r.training_session_id')->select('r.training_session_id')->selectRaw("count(*) FILTER (WHERE r.status='active') as reserved, count(*) FILTER (WHERE r.status='cancelled') as reservation_cancelled, count(*) FILTER (WHERE r.status='active' AND a.status='present') as present, count(*) FILTER (WHERE r.status='active' AND a.status='absent') as absent, count(*) FILTER (WHERE r.status='active' AND a.status='no_show') as no_show, count(*) FILTER (WHERE r.status='active' AND a.id IS NULL) as pending");
        $waiting = DB::table('waiting_list_entries')->where('organization_id', $org)->where('status', 'waiting')->select('training_session_id')->selectRaw('count(*) as waiting')->groupBy('training_session_id');
        $q = DB::query()->fromSub($scope->toBase(), 's')->leftJoinSub($counts, 'r', 'r.training_session_id', '=', 's.id')->leftJoinSub($waiting, 'w', 'w.training_session_id', '=', 's.id')->select('s.*');
        foreach (['reserved', 'reservation_cancelled', 'present', 'absent', 'no_show', 'pending'] as $key) {
            $q->selectRaw("coalesce(r.$key,0)::bigint as $key");
        }

        return $q->selectRaw("coalesce(w.waiting,0)::bigint as waiting, (s.status='scheduled' AND s.ends_at<=?) as held", [$this->filters->clock->utc()->format('Y-m-d H:i:sP')]);
    }

    public function members(): Builder
    {
        $org = $this->context->organization()->id;
        // Whole-studio participation intentionally needs all-location member authority.
        $active = DB::table('member_memberships')->where('organization_id', $org)->whereNull('cancelled_at')->where('starts_on', '<=', $this->filters->today())->where('ends_on', '>=', $this->filters->today())->select('studio_member_id')->distinct();
        $attended = DB::table('attendance_records as a')->join('training_sessions as s', function ($j) {
            $j->on('s.id', '=', 'a.training_session_id')->on('s.organization_id', '=', 'a.organization_id');
        })->where('a.organization_id', $org)->where('a.status', 'present')->where('s.status', 'scheduled')->where('s.ends_at', '<=', $this->filters->clock)->where('s.starts_at', '>=', $this->filters->start())->where('s.starts_at', '<', $this->filters->end())->select('a.studio_member_id')->distinct();

        return DB::table('studio_members as m')->leftJoinSub($active, 'eligible', 'eligible.studio_member_id', '=', 'm.id')->leftJoinSub($attended, 'attended', 'attended.studio_member_id', '=', 'm.id')->where('m.organization_id', $org)->select(['m.id', 'm.public_id', 'm.status'])->selectRaw("m.first_name || ' ' || m.last_name as member_name, (eligible.studio_member_id IS NOT NULL) as eligible, (attended.studio_member_id IS NOT NULL) as participated");
    }
}
