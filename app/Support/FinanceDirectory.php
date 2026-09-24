<?php

namespace App\Support;

use App\Models\CashPayment;
use App\Models\MemberMembership;
use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class FinanceDirectory
{
    public const NET_SQL = '(SELECT COALESCE(SUM(p.amount_minor),0) FROM cash_payments p WHERE p.member_membership_id=member_memberships.id AND NOT EXISTS (SELECT 1 FROM payment_reversals r WHERE r.cash_payment_id=p.id))';

    public static function read(User $u): void
    {
        abort_unless(Gate::forUser($u)->allows(Permission::MembershipsView->value) || Gate::forUser($u)->allows(Permission::PaymentsView->value), 403);
    }

    /** @return Builder<MemberMembership> */
    public static function memberships(TenantContext $c, User $u): Builder
    {
        self::read($u);
        $q = MemberMembership::query()->where('organization_id', $c->organization()->id)->with(['member', 'plan']);
        if (Gate::forUser($u)->allows(Permission::PaymentsView->value)) {
            $q->select('member_memberships.*')->selectRaw('(SELECT COALESCE(SUM(p.amount_minor),0)::bigint FROM cash_payments p WHERE p.member_membership_id=member_memberships.id) AS gross_paid_minor')->selectRaw('(SELECT COALESCE(SUM(r.amount_minor),0)::bigint FROM payment_reversals r JOIN cash_payments p ON p.id=r.cash_payment_id WHERE p.member_membership_id=member_memberships.id) AS reversed_minor');
        }

        return $q;
    }

    /** @return array<string,mixed> */
    public static function membership(MemberMembership $m, TenantContext $c, User $u): array
    {
        $result = ['id' => $m->public_id, 'member' => ReservationDirectory::member($m->member), 'plan_id' => $m->plan->public_id, 'plan_name' => $m->plan_name, 'starts_on' => $m->starts_on, 'ends_on' => $m->ends_on, 'status' => MembershipPeriod::state($m, now($c->organization()->timezone)->toDateString()), 'cancelled_at' => $m->cancelled_at, 'cancellation_reason' => $m->cancellation_reason];
        if (Gate::forUser($u)->allows(Permission::PaymentsView->value)) {
            $gross = (int) $m->getAttribute('gross_paid_minor');
            $reversed = (int) $m->getAttribute('reversed_minor');
            $net = $gross - $reversed;
            $due = $m->agreed_price_minor - $net;
            $result += ['agreed_price_minor' => $m->agreed_price_minor, 'currency' => $m->currency, 'gross_paid_minor' => $gross, 'reversed_minor' => $reversed, 'net_paid_minor' => $net, 'balance_minor' => $due, 'payment_status' => $due < 0 ? 'overpaid' : ($due === 0 ? 'paid' : ($net === 0 ? 'unpaid' : 'partially_paid'))];
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public static function plan(MembershipPlan $p, User $u): array
    {
        $data = ['id' => $p->public_id, 'name' => $p->name, 'description' => $p->description, 'status' => $p->status, 'duration_unit' => $p->duration_unit, 'duration_count' => $p->duration_count];
        if (Gate::forUser($u)->allows(Permission::PaymentsView->value)) {
            $data += ['price_minor' => $p->price_minor, 'currency' => $p->currency];
        }

        return $data;
    }

    /** @return Builder<CashPayment> */
    public static function payments(TenantContext $c, User $u): Builder
    {
        Gate::forUser($u)->authorize(Permission::PaymentsView->value);

        return CashPayment::query()->where('organization_id', $c->organization()->id)->with(['member', 'membership', 'actor', 'reversal.actor']);
    }

    /** @return array<string,mixed> */
    public static function payment(CashPayment $p, bool $detail = false): array
    {
        return ['id' => $p->public_id, 'member' => ReservationDirectory::member($p->member), 'membership_id' => $p->membership->public_id, 'plan_name' => $p->membership->plan_name, 'starts_on' => $p->membership->starts_on, 'ends_on' => $p->membership->ends_on, 'amount_minor' => $p->amount_minor, 'currency' => $p->currency, 'method' => 'cash', 'paid_at' => $p->paid_at->toIso8601String(), 'recorded_by' => ['id' => $p->actor->public_id, 'name' => $p->actor->name], 'created_at' => $p->created_at?->toISOString(), 'status' => $p->reversal === null ? 'recorded' : 'reversed', 'reversal' => $p->reversal === null ? null : ['id' => $p->reversal->public_id, 'amount_minor' => $p->reversal->amount_minor, 'reason' => $p->reversal->reason, 'created_at' => $p->reversal->created_at?->toISOString(), 'recorded_by' => ['id' => $p->reversal->actor->public_id, 'name' => $p->reversal->actor->name]]] + ($detail ? ['note' => $p->note] : []);
    }
}
