<?php

namespace App\Http\Controllers;

use App\Http\Requests\PermanentSpotRequest;
use App\Http\Requests\ReservationListRequest;
use App\Models\PermanentSpot;
use App\Models\Reservation;
use App\Models\StudioMember;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\Audit;
use App\Support\GroupDirectory;
use App\Support\Lifecycle;
use App\Support\MemberStatus;
use App\Support\PermanentAllocation;
use App\Support\Permission;
use App\Support\ReservationConflict;
use App\Support\ReservationDirectory as D;
use App\Support\SessionDirectory;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class PermanentSpotController
{
    public function index(ReservationListRequest $r, TenantContext $c, string $organization, string $group): JsonResponse
    {
        $u = $r->user();
        abort_unless($u instanceof User, 401);
        $g = GroupDirectory::query($c, $u)->where('public_id', $group)->firstOrFail();
        $page = D::spots($c, $u)->where('training_group_id', $g->id)->orderBy('id')->paginate($r->validated('per_page', 25));

        return response()->json(['data' => $page->getCollection()->map(D::spot(...)), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function show(Request $r, TenantContext $c, string $organization, string $spot): JsonResponse
    {
        $u = $r->user();
        abort_unless($u instanceof User, 401);

        return response()->json(['data' => D::spot(D::spots($c, $u)->where('public_id', $spot)->firstOrFail())]);
    }

    public function store(PermanentSpotRequest $r, TenantContext $c, string $organization, string $group): JsonResponse
    {
        return $this->save($r, $c, $group, null);
    }

    public function update(PermanentSpotRequest $r, TenantContext $c, string $organization, string $spot): JsonResponse
    {
        return $this->save($r, $c, null, $spot);
    }

    private function save(PermanentSpotRequest $r, TenantContext $c, ?string $group, ?string $id): JsonResponse
    {
        $u = $r->user();
        abort_unless($u instanceof User, 401);

        return Lifecycle::run($c, $u, Permission::PermanentSpotsManage, function () use ($r, $c, $u, $group, $id) {
            Gate::forUser($u)->authorize(Permission::PermanentSpotsView->value);
            $p = $id === null ? new PermanentSpot : D::spots($c, $u)->where('public_id', $id)->firstOrFail();
            $g = $p->exists ? $p->group : GroupDirectory::query($c, $u)->where('public_id', $group)->firstOrFail();
            if ($g->status !== 'active' && $r->validated('status') === 'active') {
                throw new ReservationConflict('session_unavailable');
            }
            $m = $p->exists ? $p->member : StudioMember::query()->where('organization_id', $c->organization()->id)->where('public_id', $r->validated('member_id'))->firstOrFail();
            if ($r->validated('status') === 'active' && $m->status !== MemberStatus::Active) {
                throw new ReservationConflict('member_ineligible');
            }
            $p->forceFill(['organization_id' => $c->organization()->id, 'training_group_id' => $g->id, 'studio_member_id' => $m->id, 'effective_from' => $r->validated('effective_from'), 'effective_until' => $r->validated('effective_until'), 'status' => $r->validated('status')]);
            if (! $p->exists) {
                $p->forceFill(['created_by' => $u->id]);
            }
            $futureIds = TrainingSession::query()->where('training_group_id', $g->id)->where('starts_at', '>', now())->limit(801)->pluck('id');
            if ($futureIds->count() > 800) {
                throw new ReservationConflict('limit');
            }
            abort_unless(SessionDirectory::query($c, $u)->whereIn('id', $futureIds)->count() === $futureIds->count(), 404);
            PermanentAllocation::assertCapacity($g, $p);
            $overlap = PermanentSpot::query()->where('training_group_id', $g->id)->where('studio_member_id', $m->id)->where('status', 'active')->when($p->exists, fn ($q) => $q->whereKeyNot($p->id))->whereRaw("daterange(effective_from,effective_until,'[]') && daterange(?::date,?::date,'[]')", [$p->effective_from, $p->effective_until])->exists();
            if ($p->status === 'active' && $overlap) {
                throw new ReservationConflict('permanent_spot_conflict');
            }
            // All concrete sessions affected by reconciliation must remain visible to this actor.
            $affected = $p->exists ? Reservation::query()->where('organization_id', $c->organization()->id)->where('permanent_spot_id', $p->id)->where('status', 'active')->whereHas('session', fn ($q) => $q->where('starts_at', '>', now()))->pluck('training_session_id') : collect();
            abort_unless(SessionDirectory::query($c, $u)->whereIn('id', $affected)->count() === $affected->unique()->count(), 404);
            $new = ! $p->exists;
            $p->save();
            PermanentAllocation::reconcile($p, $u);
            $result = PermanentAllocation::group($g);
            Audit::record($c->organization(), $u, $new ? 'permanent_spot.created' : 'permanent_spot.changed', 'permanent_spot', $p->public_id, ['status' => $p->status, 'effective_from' => $p->effective_from, 'effective_until' => $p->effective_until, 'result' => $result]);

            return response()->json(['data' => D::spot($p), 'meta' => ['synchronization' => $result]], $new ? 201 : 200);
        });
    }
}
