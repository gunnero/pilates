<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReservationListRequest;
use App\Models\User;
use App\Support\ReservationDirectory as D;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

final class MemberBookingsController
{
    public function __invoke(ReservationListRequest $r, TenantContext $c, string $organization, string $member): JsonResponse
    {
        $u = $r->user();
        abort_unless($u instanceof User, 401);
        $spots = $r->routeIs('member.spots');
        $q = $spots ? D::spots($c, $u) : D::reservations($c, $u)->where('status', 'active')->whereHas('session', fn ($q) => $q->where('status', 'scheduled')->where('starts_at', '>', now()));
        $q->whereHas('member', fn ($q) => $q->where('public_id', $member));
        $page = $q->orderBy('id')->paginate($r->validated('per_page', 25));

        return response()->json(['data' => $page->getCollection()->map($spots ? D::spot(...) : D::reservation(...)), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }
}
