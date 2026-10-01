<?php

namespace App\Http\Controllers;

use App\Http\Requests\OperationsRequest;
use App\Http\Requests\OperationsRosterRequest;
use App\Models\AttendanceRecord;
use App\Models\Reservation;
use App\Models\StudioMember;
use App\Models\User;
use App\Models\WaitingListEntry;
use App\Support\Attendance;
use App\Support\FinanceDirectory;
use App\Support\LocationScope;
use App\Support\OperationsDirectory;
use App\Support\Permission;
use App\Support\ReservationDirectory;
use App\Support\StaffRole;
use App\Support\StudioSettings;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class OperationsController
{
    public function index(OperationsRequest $r, TenantContext $c): JsonResponse
    {
        $u = MemberAccountController::actor($r);

        return OperationsDirectory::snapshot(function () use ($r, $c, $u) {
            $today = now($c->organization()->timezone)->toDateString();
            $from = $r->validated('from', $today);
            $until = $r->validated('until', $today);
            $q = OperationsDirectory::query($c, $u)->whereBetween('local_date', [$from, $until]);
            foreach (['location_id' => 'location', 'trainer_id' => 'trainer', 'group_id' => 'group'] as $field => $relation) {
                if ($r->filled($field)) {
                    $q->whereHas($relation, fn ($b) => $b->where('public_id', $r->validated($field)));
                }
            }
            if ($r->validated('status', 'all') !== 'all') {
                $q->where('status', $r->validated('status'));
            }
            $aggregate = DB::query()->fromSub((clone $q)->withoutEagerLoads()->toBase(), 's')->selectRaw("count(*)::int as sessions, count(*) filter (where status='cancelled')::int as cancelled, coalesce(sum(capacity) filter (where status='scheduled'),0)::int as capacity, coalesce(sum(reserved_count) filter (where status='scheduled'),0)::int as reserved, coalesce(sum(waiting_count) filter (where status='scheduled'),0)::int as waiting, coalesce(sum(present_count),0)::int as present, coalesce(sum(pending_count) filter (where status='scheduled'),0)::int as pending")->first();
            $page = (clone $q)->orderBy('starts_at')->orderBy('id')->paginate($r->validated('per_page', 25));
            $alerts = (clone $q)->where(function ($b) {
                $b->where('status', 'cancelled')->orWhere('is_overridden', true)->orWhere(function (Builder $b) {
                    $b->where('ends_at', '<=', now())->whereHas('reservations', fn ($b) => $b->where('status', 'active')->whereNotIn('id', AttendanceRecord::whereColumn('attendance_records.organization_id', 'training_sessions.organization_id')->select('reservation_id')));
                })->orWhereHas('waitingEntries', fn ($b) => $b->where('status', 'waiting'));
            })->orderBy('starts_at')->orderBy('id')->limit(51)->get();
            $items = [];
            foreach ($alerts->take(50) as $s) {
                if ($s->status === 'scheduled' && now()->gte($s->ends_at) && (int) $s->getAttribute('pending_count') > 0) {
                    $items[] = ['kind' => 'attendance_pending', 'severity' => 'warning', 'session_id' => $s->public_id];
                }
                foreach (['cancelled' => $s->status === 'cancelled', 'changed' => $s->is_overridden, 'waiting' => $s->status === 'scheduled' && $s->waiting_count > 0] as $kind => $matches) {
                    if ($matches) {
                        $items[] = ['kind' => $kind, 'severity' => 'info', 'session_id' => $s->public_id];
                    }
                }
            }
            $financial = [];
            if (Gate::forUser($u)->allows(Permission::MembershipsView->value) || Gate::forUser($u)->allows(Permission::PaymentsView->value)) {
                $scope = (clone $q)->select('training_sessions.id')->withoutEagerLoads();
                $members = Reservation::where('organization_id', $c->organization()->id)->where('status', 'active')->whereIn('training_session_id', $scope)->select('studio_member_id');
                $periods = FinanceDirectory::memberships($c, $u)->whereIn('studio_member_id', $members)->whereNull('cancelled_at')->where('starts_on', '<=', $today);
                $threshold = now($c->organization()->timezone)->addDays(StudioSettings::forOrganization($c->organization()->id)->membership_expiring_days)->toDateString();
                $financial['expiring'] = (clone $periods)->whereBetween('ends_on', [$today, $threshold])->count();
                if (Gate::forUser($u)->allows(Permission::PaymentsView->value)) {
                    $financial['payment_due'] = (clone $periods)->whereRaw('agreed_price_minor > '.FinanceDirectory::NET_SQL)->count();
                }
            }
            $communication = null;
            if ($c->membership()->role !== StaffRole::Trainer && Gate::forUser($u)->allows(Permission::OrganizationsManage->value) && $c->membership()->location_scope === LocationScope::All) {
                $communication = DB::table('notification_deliveries as d')->join('platform_notifications as n', 'n.id', '=', 'd.platform_notification_id')->where('n.organization_id', $c->organization()->id)->where('n.created_at', '>=', now()->subDays(31))->selectRaw('d.channel, d.state, count(*)::int as total')->groupBy('d.channel', 'd.state')->get();
            }

            return $this->json(['data' => $page->getCollection()->map(fn ($s) => OperationsDirectory::session($s, $u)), 'summary' => $aggregate, 'alerts' => collect($items)->sortBy(fn ($item) => $item['severity'] === 'warning' ? 0 : 1)->values()->all(), 'alerts_truncated' => $alerts->count() > 50, 'communication' => $communication, 'financial' => $financial, 'today' => $today, 'from' => $from, 'until' => $until, 'timezone' => $c->organization()->timezone, 'as_of' => now()->toIso8601String(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
        });
    }

    public function roster(OperationsRosterRequest $r, TenantContext $c, string $organization, string $session): JsonResponse
    {
        $u = MemberAccountController::actor($r);

        return OperationsDirectory::snapshot(function () use ($r, $c, $u, $session) {
            $s = OperationsDirectory::query($c, $u)->where('public_id', $session)->firstOrFail();
            $waiting = $r->validated('kind', 'reservations') === 'waiting';
            if ($waiting) {
                Gate::forUser($u)->authorize(Permission::WaitingListView->value);
            }
            $q = ($waiting ? WaitingListEntry::query() : Reservation::query())->where('organization_id', $s->organization_id)->where('training_session_id', $s->id)->with('member');
            if ($waiting) {
                $q->where('status', 'waiting')->orderBy('joined_at');
            } elseif ($r->validated('status', 'all') !== 'all') {
                $q->where('status', $r->validated('status'));
            }
            // Search is JSON-body only: names must not enter URL/access logs.
            if (is_string($r->validated('search')) && trim($r->validated('search')) !== '') {
                $term = '%'.addcslashes(trim($r->validated('search')), '%_\\').'%';
                $q->whereHas('member', fn ($b) => $b->whereRaw("(first_name || ' ' || last_name) ilike ?", [$term]));
            }
            $page = $q->orderBy('id')->paginate($r->validated('per_page', 25));
            $records = AttendanceRecord::where('organization_id', $s->organization_id)->where('training_session_id', $s->id)->whereIn('studio_member_id', $page->getCollection()->pluck('studio_member_id'))->get()->keyBy('studio_member_id');
            $walkIns = DB::table('audit_events')->where('organization_id', $s->organization_id)->where('action', 'attendance.walk_in')->whereBetween('occurred_at', [$s->starts_at->subMinutes(30), $s->ends_at])->whereIn('subject_id', $records->pluck('public_id'))->pluck('subject_id')->flip();
            $positions = $waiting ? WaitingListEntry::where('organization_id', $s->organization_id)->where('training_session_id', $s->id)->where('status', 'waiting')->orderBy('joined_at')->orderBy('id')->pluck('id')->flip() : collect();
            $finance = $this->finance($c, $u, array_values($page->getCollection()->map(fn ($row): int => $row->studio_member_id)->all()));
            $rows = $page->getCollection()->map(function ($row) use ($waiting, $records, $positions, $finance, $u, $walkIns) {
                $record = $records->get($row->studio_member_id);

                return ['id' => $row->public_id, 'member' => ReservationDirectory::member($row->member), 'member_status' => $row->member->status->value, 'status' => $row->status, 'source' => $row->source, 'membership' => $finance[$row->studio_member_id] ?? null, 'finance_access' => Gate::forUser($u)->allows(Permission::MembershipsView->value) || Gate::forUser($u)->allows(Permission::PaymentsView->value), 'position' => $waiting ? (int) $positions->get($row->id) + 1 : null, 'walk_in' => $record !== null && $walkIns->has($record->public_id), 'attendance' => $record === null ? null : Attendance::represent($record)];
            });

            return $this->json(['data' => $rows, 'session' => OperationsDirectory::session($s, $u), 'as_of' => now()->toIso8601String(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
        });
    }

    public function profile(Request $r, TenantContext $c, string $organization, string $session, string $member): JsonResponse
    {
        abort_unless($r->all() === [], 422);
        $u = MemberAccountController::actor($r);

        return OperationsDirectory::snapshot(function () use ($c, $u, $session, $member) {
            $s = OperationsDirectory::query($c, $u)->where('public_id', $session)->firstOrFail();
            $m = StudioMember::where('organization_id', $s->organization_id)->where('public_id', $member)->where(function ($b) use ($s, $u) {
                $b->whereIn('id', Reservation::where('organization_id', $s->organization_id)->where('training_session_id', $s->id)->select('studio_member_id'));
                if (Gate::forUser($u)->allows(Permission::WaitingListView->value)) {
                    $b->orWhereIn('id', WaitingListEntry::where('organization_id', $s->organization_id)->where('training_session_id', $s->id)->where('status', 'waiting')->select('studio_member_id'));
                }
            })->firstOrFail();
            $data = ReservationDirectory::member($m) + ['status' => $m->status->value, 'entitlement' => 'not_applicable', 'finance_access' => false];
            if (Gate::forUser($u)->allows(Permission::MembersView->value) && $c->membership()->location_scope === LocationScope::All) {
                $data += ['email' => $m->email, 'phone' => $m->phone, 'profile_available' => true];
            }
            if (Gate::forUser($u)->allows(Permission::MembershipsView->value) || Gate::forUser($u)->allows(Permission::PaymentsView->value)) {
                $periods = FinanceDirectory::memberships($c, $u)->where('studio_member_id', $m->id)->orderByDesc('ends_on')->orderByDesc('id')->limit(11)->get();
                $policy = StudioSettings::forOrganization($s->organization_id);
                $today = now($c->organization()->timezone)->toDateString();
                $threshold = now($c->organization()->timezone)->addDays($policy->membership_expiring_days)->toDateString();
                $data['finance_access'] = true;
                $data['memberships'] = $periods->take(10)->map(function ($p) use ($c, $u, $today, $threshold) {
                    $view = FinanceDirectory::membership($p, $c, $u);
                    unset($view['member'], $view['cancellation_reason']);

                    return $view + ['expiring' => $view['status'] === 'active' && $p->ends_on >= $today && $p->ends_on <= $threshold];
                });
                $data['memberships_truncated'] = $periods->count() > 10;
            }

            return $this->json(['data' => $data]);
        });
    }

    /** @param list<int> $members
     * @return array<int,array<string,mixed>>
     */
    private function finance(TenantContext $c, User $u, array $members): array
    {
        if (! Gate::forUser($u)->allows(Permission::MembershipsView->value) && ! Gate::forUser($u)->allows(Permission::PaymentsView->value)) {
            return [];
        }
        $today = now($c->organization()->timezone)->toDateString();
        $threshold = now($c->organization()->timezone)->addDays(StudioSettings::forOrganization($c->organization()->id)->membership_expiring_days)->toDateString();
        $periods = FinanceDirectory::memberships($c, $u)->whereIn('studio_member_id', $members)->distinct('studio_member_id')->orderBy('studio_member_id')->orderByRaw('CASE WHEN cancelled_at IS NULL AND starts_on <= ? AND ends_on >= ? THEN 0 ELSE 1 END', [$today, $today])->orderByDesc('ends_on')->orderByDesc('id')->get();
        $result = [];
        foreach ($periods as $p) {
            $view = FinanceDirectory::membership($p, $c, $u);
            $result[$p->studio_member_id] = ['status' => $view['status'], 'plan_name' => $p->plan_name, 'ends_on' => $p->ends_on, 'expiring' => $view['status'] === 'active' && $p->ends_on <= $threshold];
            if (isset($view['payment_status'])) {
                $result[$p->studio_member_id]['payment_status'] = $view['payment_status'];
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $data */
    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }
}
