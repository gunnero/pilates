<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReservationListRequest;
use App\Http\Requests\ReservationLookupRequest;
use App\Http\Requests\ReservationRequest;
use App\Models\StudioMember;
use App\Models\User;
use App\Support\Allocation;
use App\Support\Audit;
use App\Support\GroupDirectory;
use App\Support\Lifecycle;
use App\Support\Permission;
use App\Support\ReservationDirectory as D;
use App\Support\SessionDirectory;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ReservationController
{
    public function index(ReservationListRequest $r, TenantContext $c, string $organization, string $session): JsonResponse
    {
        $u = $this->actor($r);
        $s = SessionDirectory::query($c, $u)->where('public_id', $session)->firstOrFail();
        $waiting = $r->routeIs('waiting.index');
        $q = $waiting ? D::waitingEntries($c, $u)->where('status', 'waiting')->orderBy('joined_at')->orderBy('id') : D::reservations($c, $u)->orderBy('id');
        $page = $q->where('training_session_id', $s->id)->paginate($r->validated('per_page', 25));

        return response()->json(['data' => $page->getCollection()->map($waiting ? D::waiting(...) : D::reservation(...)), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()]]);
    }

    public function lookup(ReservationLookupRequest $r, TenantContext $c, string $organization, string $session): JsonResponse
    {
        if ($r->routeIs('spots.lookup')) {
            GroupDirectory::query($c, $this->actor($r))->where('public_id', $session)->firstOrFail();
        } else {
            SessionDirectory::query($c, $this->actor($r))->where('public_id', $session)->firstOrFail();
        }
        $term = addcslashes(mb_strtolower($r->validated('search')), '\\%_').'%';
        $rows = StudioMember::query()->where('organization_id', $c->organization()->id)->where('status', 'active')->where(fn ($q) => $q->whereRaw('lower(first_name) LIKE ?', [$term])->orWhereRaw('lower(last_name) LIKE ?', [$term])->orWhereRaw("lower(first_name || ' ' || last_name) LIKE ?", [$term]))->orderBy('id')->limit(20)->get();

        return response()->json(['data' => $rows->map(D::member(...))]);
    }

    public function store(ReservationRequest $r, TenantContext $c, string $organization, string $session): JsonResponse
    {
        $u = $this->actor($r);
        $waiting = $r->routeIs('waiting.store');
        $result = Lifecycle::run($c, $u, $waiting ? Permission::WaitingListManage : Permission::ReservationsManage, function () use ($r, $c, $u, $session, $waiting) {
            Gate::forUser($u)->authorize(Permission::ReservationsView->value);
            Gate::forUser($u)->authorize(Permission::ReservationsManage->value);
            $allow = $waiting || $r->boolean('allow_waiting');
            if ($allow) {
                Gate::forUser($u)->authorize(Permission::WaitingListManage->value);
                Gate::forUser($u)->authorize(Permission::WaitingListView->value);
            }
            $s = SessionDirectory::query($c, $u)->where('public_id', $session)->firstOrFail();
            $m = StudioMember::query()->where('organization_id', $c->organization()->id)->where('public_id', $r->validated('member_id'))->firstOrFail();

            return Allocation::reserve($s, $m, $u, $allow);
        });

        return response()->json(['data' => ['outcome' => $result['outcome'], 'reservation' => $result['reservation'] ? D::reservation($result['reservation']) : null, 'waiting_entry' => $result['waiting_entry'] ? D::waiting($result['waiting_entry']) : null]]);
    }

    public function show(Request $r, TenantContext $c, string $organization, string $reservation): JsonResponse
    {
        return response()->json(['data' => D::reservation(D::reservations($c, $this->actor($r))->where('public_id', $reservation)->firstOrFail())]);
    }

    public function cancel(Request $r, TenantContext $c, string $organization, string $reservation): JsonResponse
    {
        abort_unless($r->all() === [], 422);
        $u = $this->actor($r);
        $waiting = $r->routeIs('waiting.cancel');
        Lifecycle::run($c, $u, $waiting ? Permission::WaitingListManage : Permission::ReservationsCancel, function () use ($c, $u, $reservation, $waiting) {
            Gate::forUser($u)->authorize(($waiting ? Permission::WaitingListView : Permission::ReservationsView)->value);
            if ($waiting) {
                $w = D::waitingEntries($c, $u)->where('public_id', $reservation)->firstOrFail();
                Allocation::lock($w->session);
                if ($w->status === 'waiting') {
                    $w->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
                    Audit::record($c->organization(), $u, 'waiting_list.cancelled', 'waiting_list_entry', $w->public_id, []);
                }
            } else {
                $row = D::reservations($c, $u)->where('public_id', $reservation)->firstOrFail();
                Allocation::cancel(Allocation::lock($row->session), $row, $u);
            }
        });

        return response()->json(['data' => ['outcome' => 'cancelled']]);
    }

    private function actor(Request $r): User
    {
        $u = $r->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }
}
