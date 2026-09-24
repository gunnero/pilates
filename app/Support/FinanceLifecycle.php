<?php

namespace App\Support;

use App\Models\CashPayment;
use App\Models\MemberMembership;
use App\Models\MembershipPlan;
use App\Models\PaymentReversal;
use App\Models\StudioMember;
use App\Models\User;
use App\Support\Notifications\Events;
use App\Support\Notifications\NotificationType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

final class FinanceLifecycle
{
    /** @param array<string,mixed> $data */
    public static function plan(TenantContext $c, User $u, array $data, ?string $id = null): MembershipPlan
    {
        return Lifecycle::run($c, $u, Permission::MembershipsManage, function () use ($c, $u, $data, $id) {
            Gate::forUser($u)->authorize(Permission::MembershipsView->value);
            Gate::forUser($u)->authorize(Permission::PaymentsView->value);
            $p = $id === null ? new MembershipPlan : MembershipPlan::query()->where('organization_id', $c->organization()->id)->where('public_id', $id)->firstOrFail();
            if ($p->exists && $p->status === 'archived') {
                throw new FinanceConflict('archived');
            }
            $new = ! $p->exists;
            $before = $new ? null : ['price_minor' => $p->price_minor, 'duration_unit' => $p->duration_unit, 'duration_count' => $p->duration_count, 'status' => $p->status];
            $p->forceFill($data + ['organization_id' => $c->organization()->id, 'currency' => 'MKD'])->save();
            Audit::record($c->organization(), $u, $new ? 'membership_plan.created' : 'membership_plan.changed', 'membership_plan', $p->public_id, ['before' => $before, 'after' => ['price_minor' => $p->price_minor, 'duration_unit' => $p->duration_unit, 'duration_count' => $p->duration_count, 'status' => $p->status]]);

            return $p;
        });
    }

    public static function assign(TenantContext $c, User $u, string $member, string $plan, string $start): MemberMembership
    {
        return Lifecycle::run($c, $u, Permission::MembershipsManage, function () use ($c, $u, $member, $plan, $start) {
            Gate::forUser($u)->authorize(Permission::MembershipsView->value);
            $m = StudioMember::query()->where('organization_id', $c->organization()->id)->where('public_id', $member)->firstOrFail();
            $p = MembershipPlan::query()->where('organization_id', $c->organization()->id)->where('public_id', $plan)->firstOrFail();
            if ($m->status !== MemberStatus::Active || $p->status !== 'active') {
                throw new FinanceConflict('ineligible');
            }
            $end = MembershipPeriod::end($start, $p->duration_unit, $p->duration_count);
            if (MemberMembership::query()->where('organization_id', $c->organization()->id)->where('studio_member_id', $m->id)->whereNull('cancelled_at')->where('starts_on', '<=', $end)->where('ends_on', '>=', $start)->exists()) {
                throw new FinanceConflict('overlap');
            }
            $mm = new MemberMembership;
            $mm->forceFill(['organization_id' => $c->organization()->id, 'studio_member_id' => $m->id, 'membership_plan_id' => $p->id, 'plan_name' => $p->name, 'agreed_price_minor' => $p->price_minor, 'currency' => $p->currency, 'duration_unit' => $p->duration_unit, 'duration_count' => $p->duration_count, 'starts_on' => $start, 'ends_on' => $end, 'created_by' => $u->id])->save();
            Audit::record($c->organization(), $u, 'membership.created', 'member_membership', $mm->public_id, ['plan_id' => $p->public_id, 'starts_on' => $start, 'ends_on' => $end, 'agreed_price_minor' => $mm->agreed_price_minor, 'currency' => $mm->currency]);

            Events::membership($mm, NotificationType::MembershipCreated);

            return $mm;
        });
    }

    public static function cancel(TenantContext $c, User $u, string $id, string $reason): MemberMembership
    {
        return Lifecycle::run($c, $u, Permission::MembershipsManage, function () use ($c, $u, $id, $reason) {
            Gate::forUser($u)->authorize(Permission::MembershipsView->value);
            $m = MemberMembership::query()->where('organization_id', $c->organization()->id)->where('public_id', $id)->lockForUpdate()->firstOrFail();
            if ($m->cancelled_at !== null) {
                return $m;
            }
            $m->forceFill(['cancelled_at' => now(), 'cancelled_by' => $u->id, 'cancellation_reason' => $reason])->save();
            Audit::record($c->organization(), $u, 'membership.cancelled', 'member_membership', $m->public_id, ['reason' => $reason]);

            return $m;
        });
    }

    /** @param array<string,mixed> $data */
    public static function pay(TenantContext $c, User $u, array $data): CashPayment
    {
        return Lifecycle::run($c, $u, Permission::PaymentsRecord, function () use ($c, $u, $data) {
            Gate::forUser($u)->authorize(Permission::PaymentsView->value);
            $m = MemberMembership::query()->where('organization_id', $c->organization()->id)->where('public_id', $data['membership_id'])->lockForUpdate()->firstOrFail();
            $paid = CarbonImmutable::parse($data['paid_at'])->utc()->startOfSecond();
            $existing = CashPayment::query()->where('organization_id', $c->organization()->id)->where('request_id', $data['request_id'])->first();
            if ($existing !== null) {
                if ($existing->member_membership_id !== $m->id || $existing->recorded_by !== $u->id || $existing->amount_minor !== $data['amount_minor'] || ! $existing->paid_at->equalTo($paid) || $existing->note !== ($data['note'] ?? null)) {
                    throw new FinanceConflict('retry_conflict');
                }

                return $existing;
            }
            if ($m->cancelled_at !== null) {
                throw new FinanceConflict('cancelled');
            }
            if ($paid->isFuture() || $paid->lt(CarbonImmutable::parse('2000-01-01', 'UTC'))) {
                throw new FinanceConflict('paid_at');
            }
            if (CashPayment::query()->where('member_membership_id', $m->id)->count() >= 10000) {
                throw new FinanceConflict('limit');
            }
            $p = new CashPayment;
            $p->forceFill(['organization_id' => $c->organization()->id, 'studio_member_id' => $m->studio_member_id, 'member_membership_id' => $m->id, 'amount_minor' => $data['amount_minor'], 'currency' => $m->currency, 'paid_at' => $paid, 'recorded_by' => $u->id, 'note' => $data['note'] ?? null, 'request_id' => $data['request_id']])->save();
            Audit::record($c->organization(), $u, 'cash.recorded', 'cash_payment', $p->public_id, ['membership_id' => $m->public_id, 'amount_minor' => $p->amount_minor, 'currency' => $p->currency, 'paid_at' => $paid->toIso8601String()]);

            Events::payment($p);

            return $p;
        });
    }

    public static function reverse(TenantContext $c, User $u, string $id, string $reason): PaymentReversal
    {
        return Lifecycle::run($c, $u, Permission::PaymentsCorrect, function () use ($c, $u, $id, $reason) {
            Gate::forUser($u)->authorize(Permission::PaymentsView->value);
            $p = CashPayment::query()->where('organization_id', $c->organization()->id)->where('public_id', $id)->lockForUpdate()->firstOrFail();
            if (PaymentReversal::query()->where('cash_payment_id', $p->id)->exists()) {
                throw new FinanceConflict('reversed');
            }
            $r = new PaymentReversal;
            $r->forceFill(['organization_id' => $c->organization()->id, 'cash_payment_id' => $p->id, 'amount_minor' => $p->amount_minor, 'currency' => $p->currency, 'reason' => $reason, 'recorded_by' => $u->id])->save();
            Audit::record($c->organization(), $u, 'cash.reversed', 'payment_reversal', $r->public_id, ['payment_id' => $p->public_id, 'membership_id' => $p->membership->public_id, 'amount_minor' => $r->amount_minor, 'currency' => $r->currency, 'reason' => $reason]);

            Events::payment($p, $r);

            return $r;
        });
    }
}
