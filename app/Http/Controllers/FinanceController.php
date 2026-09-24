<?php

namespace App\Http\Controllers;

use App\Http\Requests\CashPaymentRequest;
use App\Http\Requests\FinanceListRequest;
use App\Http\Requests\FinanceReasonRequest;
use App\Http\Requests\MemberMembershipRequest;
use App\Http\Requests\MembershipPlanRequest;
use App\Http\Requests\ReservationLookupRequest;
use App\Models\MembershipPlan;
use App\Models\StudioMember;
use App\Models\User;
use App\Support\FinanceDirectory as D;
use App\Support\FinanceLifecycle as L;
use App\Support\FinanceMetrics;
use App\Support\MembershipPeriod;
use App\Support\Permission;
use App\Support\ReservationDirectory;
use App\Support\StudioSettings;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class FinanceController
{
    private function actor(Request $r): User
    {
        $u = $r->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }

    public function plans(FinanceListRequest $r, TenantContext $c): JsonResponse
    {
        $u = $this->actor($r);
        D::read($u);
        $q = MembershipPlan::query()->where('organization_id', $c->organization()->id);
        if ($r->validated('status', 'all') !== 'all') {
            $q->where('status', $r->validated('status'));
        }
        $page = $q->orderBy('id')->paginate($r->validated('per_page', 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($p) => D::plan($p, $u)), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()]]);
    }

    public function plan(Request $r, TenantContext $c, string $organization, string $plan): JsonResponse
    {
        $u = $this->actor($r);
        D::read($u);

        return response()->json(['data' => D::plan(MembershipPlan::query()->where('organization_id', $c->organization()->id)->where('public_id', $plan)->firstOrFail(), $u)]);
    }

    public function savePlan(MembershipPlanRequest $r, TenantContext $c, string $organization, ?string $plan = null): JsonResponse
    {
        $u = $this->actor($r);
        $p = L::plan($c, $u, $r->validated(), $plan);

        return response()->json(['data' => D::plan($p, $u)], $plan === null ? 201 : 200);
    }

    public function preview(Request $r, TenantContext $c, string $organization, string $plan): JsonResponse
    {
        $u = $this->actor($r);
        D::read($u);
        abort_unless(array_diff(array_keys($r->all()), ['starts_on']) === [], 422);
        $v = $r->validate(['starts_on' => 'required|date_format:Y-m-d|after_or_equal:2000-01-01|before_or_equal:2100-12-31']);
        $p = MembershipPlan::query()->where('organization_id', $c->organization()->id)->where('public_id', $plan)->where('status', 'active')->firstOrFail();

        return response()->json(['data' => ['starts_on' => $v['starts_on'], 'ends_on' => MembershipPeriod::end($v['starts_on'], $p->duration_unit, $p->duration_count), 'plan' => D::plan($p, $u)]]);
    }

    public function memberships(FinanceListRequest $r, TenantContext $c, string $organization, ?string $member = null): JsonResponse
    {
        $u = $this->actor($r);
        $q = D::memberships($c, $u);
        $member ??= $r->validated('member_id');
        if ($member !== null) {
            $q->where('studio_member_id', StudioMember::query()->where('organization_id', $c->organization()->id)->where('public_id', $member)->firstOrFail()->id);
        }
        $today = now($c->organization()->timezone)->toDateString();
        $state = $r->validated('state', 'all');
        if ($state === 'cancelled') {
            $q->whereNotNull('cancelled_at');
        } elseif ($state !== 'all') {
            $q->whereNull('cancelled_at');
            if ($state === 'active' || $state === 'expiring') {
                $q->where('starts_on', '<=', $today)->where('ends_on', '>=', $today);
            }
            if ($state === 'expiring') {
                $q->where('ends_on', '<=', now($c->organization()->timezone)->addDays(StudioSettings::forOrganization($c->organization())->membership_expiring_days)->toDateString());
            }
            if ($state === 'expired') {
                $q->where('ends_on', '<', $today);
            }
            if ($state === 'upcoming') {
                $q->where('starts_on', '>', $today);
            }
        }
        $payment = $r->validated('payment_state', 'all');
        if ($payment !== 'all') {
            Gate::forUser($u)->authorize(Permission::PaymentsView->value);
            $net = D::NET_SQL;
            match ($payment) {
                'unpaid' => $q->whereRaw("$net=0 AND agreed_price_minor>0"),
                'partially_paid' => $q->whereRaw("$net>0 AND $net<agreed_price_minor"),
                'paid' => $q->whereRaw("$net=agreed_price_minor"),
                'overpaid' => $q->whereRaw("$net>agreed_price_minor"),
                default => abort(422),
            };
        }
        $page = $q->orderByDesc('starts_on')->orderByDesc('id')->paginate($r->validated('per_page', 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($m) => D::membership($m, $c, $u)), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()]]);
    }

    public function membership(Request $r, TenantContext $c, string $organization, string $membership): JsonResponse
    {
        $u = $this->actor($r);

        return response()->json(['data' => D::membership(D::memberships($c, $u)->where('public_id', $membership)->firstOrFail(), $c, $u)]);
    }

    public function assign(MemberMembershipRequest $r, TenantContext $c): JsonResponse
    {
        $u = $this->actor($r);
        $m = L::assign($c, $u, $r->validated('member_id'), $r->validated('plan_id'), $r->validated('starts_on'));

        return response()->json(['data' => D::membership(D::memberships($c, $u)->whereKey($m->id)->firstOrFail(), $c, $u)], 201);
    }

    public function cancel(FinanceReasonRequest $r, TenantContext $c, string $organization, string $membership): JsonResponse
    {
        $u = $this->actor($r);
        L::cancel($c, $u, $membership, $r->validated('reason'));

        return $this->membership($r, $c, $organization, $membership);
    }

    public function payments(FinanceListRequest $r, TenantContext $c): JsonResponse
    {
        $u = $this->actor($r);
        $q = D::payments($c, $u);
        if ($r->has('membership_id')) {
            $q->where('member_membership_id', D::memberships($c, $u)->where('public_id', $r->validated('membership_id'))->firstOrFail()->id);
        }
        if ($r->has('member_id')) {
            $q->where('studio_member_id', StudioMember::query()->where('organization_id', $c->organization()->id)->where('public_id', $r->validated('member_id'))->firstOrFail()->id);
        }
        $page = $q->orderByDesc('id')->paginate($r->validated('per_page', 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($p) => D::payment($p)), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()]]);
    }

    public function payment(Request $r, TenantContext $c, string $organization, string $payment): JsonResponse
    {
        return response()->json(['data' => D::payment(D::payments($c, $this->actor($r))->where('public_id', $payment)->firstOrFail(), true)]);
    }

    public function pay(CashPaymentRequest $r, TenantContext $c): JsonResponse
    {
        $u = $this->actor($r);
        $p = L::pay($c, $u, $r->validated());

        return response()->json(['data' => D::payment(D::payments($c, $u)->whereKey($p->id)->firstOrFail(), true)], 201);
    }

    public function reverse(FinanceReasonRequest $r, TenantContext $c, string $organization, string $payment): JsonResponse
    {
        L::reverse($c, $this->actor($r), $payment, $r->validated('reason'));

        return $this->payment($r, $c, $organization, $payment);
    }

    public function metrics(Request $r, TenantContext $c): JsonResponse
    {
        $u = $this->actor($r);
        Gate::forUser($u)->authorize(Permission::PaymentsView->value);
        Gate::forUser($u)->authorize(Permission::ReportsView->value);

        return response()->json(['data' => FinanceMetrics::forOrganization($c->organization())]);
    }

    public function lookup(ReservationLookupRequest $r, TenantContext $c): JsonResponse
    {
        D::read($this->actor($r));
        $term = addcslashes(mb_strtolower($r->validated('search')), '\\%_').'%';
        $rows = StudioMember::query()->where('organization_id', $c->organization()->id)->where('status', 'active')->where(fn ($q) => $q->whereRaw('lower(first_name) LIKE ?', [$term])->orWhereRaw('lower(last_name) LIKE ?', [$term])->orWhereRaw("lower(first_name || ' ' || last_name) LIKE ?", [$term]))->orderBy('id')->limit(20)->get();

        return response()->json(['data' => $rows->map(ReservationDirectory::member(...))]);
    }
}
