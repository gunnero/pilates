<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceRequest;
use App\Http\Requests\ReservationListRequest;
use App\Http\Requests\ReservationLookupRequest;
use App\Http\Requests\ReservationRequest;
use App\Models\AttendanceRecord;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Allocation;
use App\Support\Attendance;
use App\Support\AttendanceQr;
use App\Support\Lifecycle;
use App\Support\Permission;
use App\Support\ReservationDirectory;
use App\Support\SessionDirectory;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AttendanceController
{
    private function actor(Request $r): User
    {
        $u = $r->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }

    public function roster(ReservationListRequest $r, TenantContext $c, string $organization, string $session): JsonResponse
    {
        $s = SessionDirectory::query($c, $this->actor($r))->where('public_id', $session)->firstOrFail();
        $page = Reservation::query()->where('organization_id', $s->organization_id)->where('training_session_id', $s->id)->where(function ($q) use ($s) {
            $q->where('status', 'active')->orWhereIn('id', AttendanceRecord::query()->where('training_session_id', $s->id)->select('reservation_id'));
        })->with('member')->orderBy('id')->paginate($r->validated('per_page', 25));
        $records = AttendanceRecord::query()->where('training_session_id', $s->id)->whereIn('reservation_id', $page->getCollection()->pluck('id'))->get()->keyBy('reservation_id');

        return response()->json(['data' => $page->getCollection()->map(fn ($reservation) => ['member' => ReservationDirectory::member($reservation->member), 'reservation_id' => $reservation->public_id, 'reservation_status' => $reservation->status, 'attendance' => isset($records[$reservation->id]) ? Attendance::represent($records[$reservation->id]) : null]), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function mark(AttendanceRequest $r, TenantContext $c, string $organization, string $session): JsonResponse
    {
        $a = Attendance::mark($c, $this->actor($r), $session, $r->validated('member_id'), $r->validated('status'), $r->routeIs('attendance.correct'), $r->validated('reason'));

        return response()->json(['data' => Attendance::represent($a)]);
    }

    public function walkIn(ReservationRequest $r, TenantContext $c, string $organization, string $session): JsonResponse
    {
        abort_if($r->has('allow_waiting'), 422);
        $a = Attendance::mark($c, $this->actor($r), $session, $r->validated('member_id'), 'present', false, null, true);

        return response()->json(['data' => Attendance::represent($a)]);
    }

    public function lookup(ReservationLookupRequest $r, TenantContext $c, string $organization, string $session): JsonResponse
    {
        return (new ReservationController)->lookup($r, $c, $organization, $session);
    }

    public function qr(Request $r, TenantContext $c, string $organization, string $session): JsonResponse
    {
        abort_unless($r->all() === [], 422);
        $u = $this->actor($r);
        $data = Lifecycle::run($c, $u, Permission::AttendanceManage, function () use ($c, $u, $session) {
            Gate::forUser($u)->authorize(Permission::AttendanceView->value);
            $s = Allocation::lock(SessionDirectory::query($c, $u)->where('public_id', $session)->firstOrFail());

            return AttendanceQr::issue($s);
        });

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store, private');
    }

    public function today(ReservationListRequest $r, TenantContext $c): JsonResponse
    {
        $page = SessionDirectory::query($c, $this->actor($r))->where('local_date', now($c->organization()->timezone)->toDateString())->orderBy('starts_at')->orderBy('id')->paginate($r->validated('per_page', 25));

        return response()->json(['data' => $page->getCollection()->map(SessionDirectory::represent(...)), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }
}
